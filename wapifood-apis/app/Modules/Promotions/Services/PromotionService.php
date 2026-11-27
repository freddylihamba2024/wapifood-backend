<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Services;

use App\Common\Exceptions\ApiException;
use App\Common\Http\Pagination;
use App\Common\Support\Money;
use App\Common\Validation\InputValidator;
use App\Common\Validation\WapiRules;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Promotions\Models\PromotionModel;
use App\Modules\Promotions\Presenters\PromotionPresenter;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\WapiFood;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Codes promo : gestion (restaurant ou administration) et application à une commande.
 *
 * Deux périmètres :
 * - code RESTAURANT (restaurant_id renseigné) : créé par l'équipe du restaurant, remise à sa charge,
 *   valable uniquement chez lui, dans la devise du restaurant ;
 * - code PLATEFORME (restaurant_id NULL) : créé par l'administration, remise à la charge de WapiFood
 *   (D5 : la commission est calculée avant remise ; le restaurant est crédité de la remise, Ph7-1).
 *
 * Les codes sont uniques sur toute la plateforme et enregistrés en majuscules.
 * Les utilisations des commandes refusées ou annulées ne comptent pas dans les limites (Ph7-4).
 */
final class PromotionService
{
    public const SCOPE_RESTAURANT = 'restaurant';
    public const SCOPE_PLATFORM   = 'platform';

    private const CODE_PATTERN = '/\A[A-Za-z0-9_\-]{3,30}\z/';
    private const DATE_PATTERN = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:\d{2})\z/';
    private const DUPLICATE_KEY_ERROR = 1062;

    private BaseConnection $db;

    public function __construct(
        private readonly PromotionModel $promotions = new PromotionModel(),
        private readonly PromotionRules $rules = new PromotionRules(),
        private readonly AuditLogger $audit = new AuditLogger(),
        ?BaseConnection $db = null,
    ) {
        $this->db = $db ?? db_connect();
    }

    // ------------------------------------------------------------------
    // Gestion
    // ------------------------------------------------------------------

    /**
     * @param int|null $restaurantId null = codes plateforme
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>}
     */
    public function list(?int $restaurantId, ?bool $active, Pagination $pagination): array
    {
        $builder = $this->scoped($restaurantId)->orderBy('id', 'DESC');

        if ($active !== null) {
            $builder->where('is_active', $active ? 1 : 0);
        }

        [$rows, $meta] = $pagination->run($builder);
        $counts        = $this->usageCountsFor(array_map(static fn (array $r): int => (int) $r['id'], $rows));

        return [array_map(static fn (array $r): array => PromotionPresenter::present($r, $counts[(int) $r['id']] ?? 0), $rows), $meta];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(?int $restaurantId, int $promotionId): array
    {
        $row = $this->findScoped($restaurantId, $promotionId);

        return PromotionPresenter::present($row, $this->usageCountsFor([$promotionId])[$promotionId] ?? 0);
    }

    /**
     * @param array<string, mixed>      $input
     * @param array<string, mixed>|null $restaurant Ligne wf_restaurants (code restaurant) ou null (code plateforme)
     *
     * @return array<string, mixed>
     */
    public function create(?array $restaurant, int $userId, array $input): array
    {
        $values = $this->validated($input, $restaurant);

        if ($this->codeExists($values['code'])) {
            throw self::codeTaken();
        }

        try {
            $id = (int) $this->promotions->insert($values + ['restaurant_id' => $restaurant === null ? null : (int) $restaurant['id']]);
        } catch (DatabaseException $exception) {
            throw (int) $exception->getCode() === self::DUPLICATE_KEY_ERROR ? self::codeTaken() : $exception;
        }

        $this->audit->log('promotion.created', $userId, 'promotion', $id, [
            'code'          => $values['code'],
            'restaurant_id' => $restaurant === null ? null : (int) $restaurant['id'],
        ]);

        return $this->show($restaurant === null ? null : (int) $restaurant['id'], $id);
    }

    /**
     * Remplacement complet des paramètres. Le code ne peut plus changer une fois utilisé.
     *
     * @param array<string, mixed>      $input
     * @param array<string, mixed>|null $restaurant
     *
     * @return array<string, mixed>
     */
    public function update(?array $restaurant, int $userId, int $promotionId, array $input): array
    {
        $restaurantId = $restaurant === null ? null : (int) $restaurant['id'];
        $current      = $this->findScoped($restaurantId, $promotionId);
        $values       = $this->validated($input, $restaurant);

        if ($values['code'] !== $current['code']) {
            if ($this->hasUsages($promotionId)) {
                throw ApiException::conflict('Ce code a déjà été utilisé : il ne peut plus être renommé. Créez un nouveau code.', [], 'PROMOTION_IN_USE');
            }

            if ($this->codeExists($values['code'])) {
                throw self::codeTaken();
            }
        }

        try {
            $this->promotions->update($promotionId, $values);
        } catch (DatabaseException $exception) {
            throw (int) $exception->getCode() === self::DUPLICATE_KEY_ERROR ? self::codeTaken() : $exception;
        }

        $this->audit->log('promotion.updated', $userId, 'promotion', $promotionId, ['code' => $values['code']]);

        return $this->show($restaurantId, $promotionId);
    }

    /**
     * @return array<string, mixed>
     */
    public function setActive(?int $restaurantId, int $userId, int $promotionId, mixed $isActive): array
    {
        if (! (new WapiRules())->wf_boolean($isActive)) {
            throw ApiException::validation(['is_active' => 'Le champ is_active doit être un booléen (true ou false).']);
        }

        $this->findScoped($restaurantId, $promotionId);
        $active = WapiRules::toBool($isActive);
        $this->promotions->update($promotionId, ['is_active' => $active ? 1 : 0]);
        $this->audit->log($active ? 'promotion.activated' : 'promotion.deactivated', $userId, 'promotion', $promotionId);

        return $this->show($restaurantId, $promotionId);
    }

    /**
     * Suppression possible seulement si le code n'a jamais servi (sinon : désactivation).
     */
    public function delete(?int $restaurantId, int $userId, int $promotionId): void
    {
        $promotion = $this->findScoped($restaurantId, $promotionId);

        if ($this->hasUsages($promotionId)) {
            throw ApiException::conflict('Ce code a déjà été utilisé dans des commandes : désactivez-le au lieu de le supprimer.', [], 'PROMOTION_IN_USE');
        }

        $this->promotions->delete($promotionId);
        $this->audit->log('promotion.deleted', $userId, 'promotion', $promotionId, ['code' => $promotion['code']]);
    }

    // ------------------------------------------------------------------
    // Application à une commande
    // ------------------------------------------------------------------

    /**
     * Vérifie un code pour un panier et calcule la remise.
     *
     * Avec $lock = true (création de commande, dans la transaction de l'appelant), la ligne de la
     * promotion est verrouillée (SELECT … FOR UPDATE) jusqu'à la fin de la transaction : deux commandes
     * simultanées ne peuvent pas dépasser une limite d'utilisation.
     *
     * @param array<string, mixed> $restaurant
     *
     * @return array{promotion: array<string, mixed>, discount_cents: int, funded_by: string}
     *
     * @throws ApiException 422 PROMO_CODE_*
     */
    public function apply(string $code, array $restaurant, int $customerId, int $subtotalCents, bool $lock = false): array
    {
        $code = PromotionRules::normalizeCode($code);

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new ApiException('Code promo invalide.', 422, ['promo_code' => 'Code promo invalide.'], 'PROMO_CODE_INVALID');
        }

        $sql       = 'SELECT * FROM wf_promotions WHERE code = ?' . ($lock ? ' FOR UPDATE' : '');
        $promotion = $this->db->query($sql, [$code])->getRowArray()
            ?? throw new ApiException('Ce code promo n\'existe pas.', 422, ['promo_code' => 'Ce code promo n\'existe pas.'], 'PROMO_CODE_INVALID');

        $discount = $this->rules->discount(
            $promotion,
            (int) $restaurant['id'],
            (string) $restaurant['currency'],
            $subtotalCents,
            $this->usages((int) $promotion['id'], $customerId),
        );

        return [
            'promotion'      => $promotion,
            'discount_cents' => $discount,
            'funded_by'      => $promotion['restaurant_id'] === null ? self::SCOPE_PLATFORM : self::SCOPE_RESTAURANT,
        ];
    }

    /**
     * Promotion appliquée à une commande (au plus une), avec son financeur.
     *
     * @return array{id: int, code: string, title: string, funded_by: string, discount_amount: string}|null
     */
    public function forOrder(int $orderId): ?array
    {
        $row = $this->db->table('wf_promotion_usages u')
            ->select('p.id, p.code, p.title, p.restaurant_id, u.discount_amount')
            ->join('wf_promotions p', 'p.id = u.promotion_id')
            ->where('u.order_id', $orderId)
            ->get()
            ->getRowArray();

        if ($row === null) {
            return null;
        }

        return [
            'id'              => (int) $row['id'],
            'code'            => $row['code'],
            'title'           => $row['title'],
            'funded_by'       => $row['restaurant_id'] === null ? self::SCOPE_PLATFORM : self::SCOPE_RESTAURANT,
            'discount_amount' => $row['discount_amount'],
        ];
    }

    /**
     * Utilisations prises en compte dans les limites : commandes ni refusées ni annulées.
     *
     * @return array{total: int, customer: int}
     */
    public function usages(int $promotionId, int $customerId): array
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS total, COALESCE(SUM(u.user_id = ?), 0) AS customer
               FROM wf_promotion_usages u
               JOIN wf_orders o ON o.id = u.order_id
              WHERE u.promotion_id = ? AND o.status NOT IN ('cancelled', 'rejected')",
            [$customerId, $promotionId],
        )->getRowArray();

        return ['total' => (int) $row['total'], 'customer' => (int) $row['customer']];
    }

    // ------------------------------------------------------------------
    // Interne
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed>      $input
     * @param array<string, mixed>|null $restaurant
     *
     * @return array<string, mixed> Colonnes wf_promotions
     */
    private function validated(array $input, ?array $restaurant): array
    {
        $currencies = implode(',', config(WapiFood::class)->supportedCurrencies);

        $data = InputValidator::validate($input, [
            'code'                    => ['label' => 'code', 'rules' => 'required|string|max_length[30]'],
            'title'                   => ['label' => 'titre', 'rules' => 'required|string|min_length[2]|max_length[150]'],
            'discount_type'           => ['label' => 'type de remise', 'rules' => 'required|in_list[percentage,fixed]'],
            'discount_value'          => ['label' => 'valeur de la remise', 'rules' => 'required|wf_money'],
            'minimum_order_amount'    => ['label' => 'montant minimum', 'rules' => 'permit_empty|wf_money'],
            'maximum_discount_amount' => ['label' => 'remise maximale', 'rules' => 'permit_empty|wf_money'],
            'currency'                => ['label' => 'devise', 'rules' => 'permit_empty|in_list[' . $currencies . ']'],
            'usage_limit'             => ['label' => 'limite d\'utilisations', 'rules' => 'permit_empty|wf_int|greater_than[0]|less_than_equal_to[1000000]'],
            'usage_per_customer'      => ['label' => 'limite par client', 'rules' => 'permit_empty|wf_int|greater_than[0]|less_than_equal_to[1000]'],
            'starts_at'               => ['label' => 'début', 'rules' => 'permit_empty|string|max_length[40]'],
            'ends_at'                 => ['label' => 'fin', 'rules' => 'permit_empty|string|max_length[40]'],
            'is_active'               => ['label' => 'actif', 'rules' => 'permit_empty|wf_boolean'],
        ]);

        $errors = [];
        $code   = PromotionRules::normalizeCode((string) $data['code']);

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            $errors['code'] = 'Le code doit contenir de 3 à 30 caractères : lettres, chiffres, « - » ou « _ ».';
        }

        $type    = (string) $data['discount_type'];
        $value   = Money::toCents(WapiRules::numericString($data['discount_value']));
        $minimum = self::optionalCents($data['minimum_order_amount'] ?? null) ?? 0;
        $maximum = self::optionalCents($data['maximum_discount_amount'] ?? null);

        if ($value <= 0) {
            $errors['discount_value'] = 'La remise doit être supérieure à zéro.';
        } elseif ($type === 'percentage' && $value > 10_000) {
            $errors['discount_value'] = 'Un pourcentage de remise ne peut pas dépasser 100.';
        }

        if ($maximum !== null && $maximum <= 0) {
            $errors['maximum_discount_amount'] = 'La remise maximale doit être supérieure à zéro.';
        } elseif ($maximum !== null && $type === 'fixed') {
            $errors['maximum_discount_amount'] = 'La remise maximale ne s\'applique qu\'à une remise en pourcentage.';
        }

        $currency = ($data['currency'] ?? '') === '' ? null : (string) $data['currency'];

        if ($restaurant !== null) {
            // Code restaurant : toujours dans la devise du restaurant.
            if ($currency !== null && $currency !== $restaurant['currency']) {
                $errors['currency'] = 'Un code de votre restaurant est forcément en ' . $restaurant['currency'] . '.';
            }

            $currency = (string) $restaurant['currency'];
        } elseif ($currency === null && ($type === 'fixed' || $minimum > 0 || $maximum !== null)) {
            // Code plateforme : un montant n'a de sens que dans une devise (aucune conversion USD/CDF).
            $errors['currency'] = 'Indiquez la devise : le code contient un montant (remise fixe, minimum ou plafond).';
        }

        $startsAt = $this->moment($data['starts_at'] ?? null, 'starts_at', $errors);
        $endsAt   = $this->moment($data['ends_at'] ?? null, 'ends_at', $errors);

        if ($startsAt !== null && $endsAt !== null && $endsAt <= $startsAt) {
            $errors['ends_at'] = 'La fin doit être postérieure au début.';
        }

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return [
            'code'                    => $code,
            'title'                   => trim((string) $data['title']),
            'discount_type'           => $type,
            'discount_value'          => Money::fromCents($value),
            'minimum_order_amount'    => Money::fromCents($minimum),
            'maximum_discount_amount' => $maximum === null ? null : Money::fromCents($maximum),
            'currency'                => $currency,
            'usage_limit'             => ($data['usage_limit'] ?? '') === '' ? null : (int) $data['usage_limit'],
            'usage_per_customer'      => ($data['usage_per_customer'] ?? '') === '' ? null : (int) $data['usage_per_customer'],
            'starts_at'               => $startsAt?->format('Y-m-d H:i:s'),
            'ends_at'                 => $endsAt?->format('Y-m-d H:i:s'),
            'is_active'               => ($data['is_active'] ?? '') === '' ? 1 : (WapiRules::toBool($data['is_active']) ? 1 : 0),
        ];
    }

    /**
     * @param array<string, string> $errors
     */
    private function moment(mixed $value, string $field, array &$errors): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $moment = preg_match(self::DATE_PATTERN, (string) $value) === 1 ? new DateTimeImmutable((string) $value) : null;
        } catch (Throwable) {
            $moment = null;
        }

        if ($moment === null) {
            $errors[$field] = 'Date attendue au format ISO 8601 avec fuseau, par exemple 2026-12-01T00:00:00+01:00.';

            return null;
        }

        return $moment->setTimezone(new DateTimeZone('UTC'));
    }

    private static function optionalCents(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : Money::toCents(WapiRules::numericString($value));
    }

    private function scoped(?int $restaurantId): BaseBuilder
    {
        $builder = $this->db->table('wf_promotions');

        return $restaurantId === null ? $builder->where('restaurant_id', null) : $builder->where('restaurant_id', $restaurantId);
    }

    /**
     * @return array<string, mixed>
     */
    private function findScoped(?int $restaurantId, int $promotionId): array
    {
        return $this->scoped($restaurantId)->where('id', $promotionId)->get()->getRowArray()
            ?? throw ApiException::notFound('Code promo introuvable.');
    }

    private function codeExists(string $code): bool
    {
        return $this->db->table('wf_promotions')->where('code', $code)->countAllResults() > 0;
    }

    private function hasUsages(int $promotionId): bool
    {
        return $this->db->table('wf_promotion_usages')->where('promotion_id', $promotionId)->countAllResults() > 0;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, int>
     */
    private function usageCountsFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->db->table('wf_promotion_usages u')
            ->select('u.promotion_id, COUNT(*) AS n')
            ->join('wf_orders o', 'o.id = u.order_id')
            ->whereIn('u.promotion_id', $ids)
            ->whereNotIn('o.status', ['cancelled', 'rejected'])
            ->groupBy('u.promotion_id')
            ->get()
            ->getResultArray();

        return array_map('intval', array_column($rows, 'n', 'promotion_id'));
    }

    private static function codeTaken(): ApiException
    {
        return ApiException::conflict('Ce code promo existe déjà. Choisissez-en un autre.', ['code' => 'Code déjà utilisé.'], 'PROMO_CODE_TAKEN');
    }
}
