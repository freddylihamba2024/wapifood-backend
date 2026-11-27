<?php

declare(strict_types=1);

namespace App\Modules\Restaurants\Services;

use App\Common\Exceptions\ApiException;
use App\Common\Support\Money;
use App\Common\Support\PhoneNumber;
use App\Common\Support\Slug;
use App\Common\Validation\InputValidator;
use App\Common\Validation\WapiRules;
use App\Libraries\Upload\UploadService;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Restaurants\Models\RestaurantModel;
use App\Modules\Restaurants\Presenters\RestaurantPresenter;
use App\Modules\Restaurants\Repositories\RestaurantRepository;
use App\Modules\Restaurants\Validators\RestaurantRules;
use App\Modules\Reviews\Services\ReviewService;
use App\Modules\Settings\Services\PlatformSettings;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use DateTimeImmutable;

/**
 * Gestion d'un restaurant par son équipe : inscription, profil, disponibilité, médias.
 *
 * Statuts : pending (en validation) → active | rejected ; active ↔ paused (fermeture temporaire,
 * décidée par le restaurant) ; suspended (décidé par l'administration). Le statut, le taux de
 * commission et la mise en avant ne sont jamais modifiables par le restaurant.
 */
final class RestaurantService
{
    private BaseConnection $db;

    public function __construct(
        private readonly RestaurantModel $restaurants = new RestaurantModel(),
        private readonly RestaurantRepository $repository = new RestaurantRepository(),
        private readonly OpeningHoursService $hours = new OpeningHoursService(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly UploadService $uploads = new UploadService(),
        ?BaseConnection $db = null,
    ) {
        $this->db = $db ?? db_connect();
    }

    /**
     * Inscription d'un restaurant par son propriétaire : statut « pending » (soumis à validation).
     *
     * @param array<string, mixed> $input Champs du profil + « address » (adresse principale)
     *
     * @return array<string, mixed>
     */
    public function create(int $ownerId, array $input): array
    {
        $profile = InputValidator::validate($input, RestaurantRules::profile(creating: true));
        $address = is_array($input['address'] ?? null) ? $input['address'] : null;

        if ($address === null) {
            throw ApiException::validation(['address' => 'L\'adresse du restaurant est obligatoire.']);
        }

        $address = InputValidator::validate($address, RestaurantRules::address(), 'address.');
        $data    = $this->profileData($profile, null);

        $data += [
            'currency'        => $profile['currency'] ?? 'USD',
            'owner_user_id'   => $ownerId,
            'slug'            => Slug::unique($data['name'], $this->repository->slugExists(...)),
            'commission_rate' => (new PlatformSettings())->defaultCommissionRate(),
            'status'          => 'pending',
        ];

        $this->assertServiceModes($data);

        $this->db->transException(true)->transStart();

        $restaurantId = (int) $this->restaurants->insert($data);

        $this->db->table('wf_restaurant_users')->insert([
            'restaurant_id'   => $restaurantId,
            'user_id'         => $ownerId,
            'restaurant_role' => 'owner',
            'status'          => 'active',
        ]);

        (new RestaurantAddressService())->insert($restaurantId, $address + ['is_primary' => true]);

        $this->audit->log('restaurant.created', $ownerId, 'restaurant', $restaurantId);

        $this->db->transComplete();

        return $this->profile($restaurantId);
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(int $restaurantId): array
    {
        $restaurant = $this->repository->find($restaurantId) ?? throw ApiException::notFound('Restaurant introuvable.');
        $addresses  = $this->repository->addressesFor([$restaurantId])[$restaurantId];
        $hours      = $this->repository->hoursFor([$restaurantId])[$restaurantId];

        return RestaurantPresenter::management(
            (new ReviewService())->withRatings([$restaurant])[0],
            $addresses,
            $hours,
            $this->hours->isOpenAt($hours, $restaurant['timezone'], new DateTimeImmutable()),
        );
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function update(int $restaurantId, int $userId, array $input): array
    {
        $restaurant = $this->repository->find($restaurantId) ?? throw ApiException::notFound('Restaurant introuvable.');
        $profile    = InputValidator::validate($input, RestaurantRules::profile(creating: false));
        $data       = $this->profileData($profile, $restaurant);

        if (isset($profile['currency']) && $profile['currency'] !== $restaurant['currency']) {
            $hasProducts = $this->db->table('wf_products')->where('restaurant_id', $restaurantId)->countAllResults() > 0;

            if ($hasProducts) {
                throw ApiException::conflict(
                    'La devise ne peut plus être modifiée : le restaurant a déjà des produits.',
                    ['currency' => 'Modification impossible.'],
                    'CURRENCY_LOCKED',
                );
            }

            $data['currency'] = $profile['currency'];
        }

        $this->assertServiceModes($data + $restaurant);

        if ($data !== []) {
            $this->restaurants->update($restaurantId, $data);
            $this->audit->log('restaurant.updated', $userId, 'restaurant', $restaurantId, ['fields' => array_keys($data)]);
        }

        return $this->profile($restaurantId);
    }

    /**
     * Fermeture temporaire (paused) ou réouverture (active), décidée par le restaurant.
     *
     * @return array<string, mixed>
     */
    public function setPaused(int $restaurantId, int $userId, bool $paused): array
    {
        $restaurant = $this->repository->find($restaurantId) ?? throw ApiException::notFound('Restaurant introuvable.');

        if (! in_array($restaurant['status'], ['active', 'paused'], true)) {
            throw ApiException::conflict(
                'Seul un restaurant validé peut être fermé temporairement ou rouvert.',
                [],
                'RESTAURANT_NOT_ACTIVE',
            );
        }

        $target = $paused ? 'paused' : 'active';

        if ($restaurant['status'] !== $target) {
            $this->restaurants->update($restaurantId, ['status' => $target]);
            $this->audit->log($paused ? 'restaurant.paused' : 'restaurant.resumed', $userId, 'restaurant', $restaurantId);
        }

        return $this->profile($restaurantId);
    }

    /**
     * Nouvelle soumission après un rejet : rejected → pending.
     *
     * @return array<string, mixed>
     */
    public function resubmit(int $restaurantId, int $userId): array
    {
        $restaurant = $this->repository->find($restaurantId) ?? throw ApiException::notFound('Restaurant introuvable.');

        if ($restaurant['status'] !== 'rejected') {
            throw ApiException::conflict('Seul un restaurant refusé peut être soumis à nouveau.', [], 'RESTAURANT_NOT_REJECTED');
        }

        $this->restaurants->update($restaurantId, ['status' => 'pending']);
        $this->audit->log('restaurant.resubmitted', $userId, 'restaurant', $restaurantId);

        return $this->profile($restaurantId);
    }

    /**
     * Remplace le logo ou l'image de couverture.
     *
     * @param 'logo'|'cover' $kind
     *
     * @return array<string, mixed>
     */
    public function replaceImage(int $restaurantId, int $userId, string $kind, ?UploadedFile $file): array
    {
        $restaurant = $this->repository->find($restaurantId) ?? throw ApiException::notFound('Restaurant introuvable.');
        $column     = $kind === 'logo' ? 'logo_url' : 'cover_url';
        $path       = $this->uploads->storeImage($file, $kind === 'logo' ? 'restaurant-logos' : 'restaurant-covers', $kind);

        $this->restaurants->update($restaurantId, [$column => $path]);
        $this->uploads->delete($restaurant[$column]);
        $this->audit->log("restaurant.{$kind}_updated", $userId, 'restaurant', $restaurantId);

        return $this->profile($restaurantId);
    }

    /**
     * Convertit les champs validés du profil en colonnes (normalisation incluse).
     *
     * @param array<string, mixed>      $profile
     * @param array<string, mixed>|null $current
     *
     * @return array<string, mixed>
     */
    private function profileData(array $profile, ?array $current): array
    {
        $data = [];

        foreach (['name', 'description', 'timezone'] as $field) {
            if (array_key_exists($field, $profile) && $profile[$field] !== null && $profile[$field] !== '') {
                $data[$field] = trim((string) $profile[$field]);
            }
        }

        if (array_key_exists('description', $profile) && ($profile['description'] === null || $profile['description'] === '')) {
            $data['description'] = null;
        }

        if (($profile['phone'] ?? '') !== '') {
            $data['phone'] = PhoneNumber::normalize((string) $profile['phone']);
        }

        if (array_key_exists('email', $profile)) {
            $data['email'] = ($profile['email'] ?? '') === '' ? null : mb_strtolower(trim((string) $profile['email']));
        }

        foreach (['minimum_order_amount', 'delivery_fee'] as $field) {
            if (isset($profile[$field]) && $profile[$field] !== '') {
                $data[$field] = Money::normalize(WapiRules::numericString($profile[$field]));
            }
        }

        if (array_key_exists('free_delivery_threshold', $profile)) {
            $value                           = $profile['free_delivery_threshold'];
            $data['free_delivery_threshold'] = ($value === null || $value === '') ? null : Money::normalize(WapiRules::numericString($value));
        }

        if (isset($profile['average_preparation_minutes']) && $profile['average_preparation_minutes'] !== '') {
            $data['average_preparation_minutes'] = (int) $profile['average_preparation_minutes'];
        }

        foreach (['accepts_delivery', 'accepts_pickup'] as $field) {
            if (isset($profile[$field]) && $profile[$field] !== '') {
                $data[$field] = WapiRules::toBool($profile[$field]) ? 1 : 0;
            }
        }

        if ($current === null) {
            $data += ['accepts_delivery' => 1, 'accepts_pickup' => 1];
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertServiceModes(array $data): void
    {
        if (! (bool) ($data['accepts_delivery'] ?? true) && ! (bool) ($data['accepts_pickup'] ?? true)) {
            throw ApiException::validation([
                'accepts_delivery' => 'Le restaurant doit proposer au moins la livraison ou le retrait sur place.',
            ]);
        }
    }
}
