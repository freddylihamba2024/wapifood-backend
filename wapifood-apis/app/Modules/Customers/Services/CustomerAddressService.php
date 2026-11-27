<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Common\Exceptions\ApiException;
use App\Common\Support\PhoneNumber;
use App\Common\Validation\InputValidator;
use App\Common\Validation\WapiRules;
use App\Modules\Customers\Models\CustomerAddressModel;
use App\Modules\Customers\Presenters\CustomerPresenter;
use App\Modules\Customers\Validators\CustomerRules;
use App\Modules\Users\Repositories\UserRepository;
use CodeIgniter\Database\BaseConnection;

/**
 * Carnet d'adresses de livraison d'un client.
 *
 * - Une adresse d'un autre client est traitée comme inexistante (404).
 * - Suppression logique (M6) : une adresse déjà utilisée reste référencée par ses commandes,
 *   qui conservent de toute façon leur propre snapshot.
 * - Le client garde au plus une adresse par défaut ; la première créée l'est automatiquement.
 * - Destinataire et téléphone : ceux du compte si non précisés.
 */
final class CustomerAddressService
{
    public const MAX_ADDRESSES = 20;

    private const TEXT_FIELDS = ['label', 'recipient_name', 'address_line', 'commune', 'neighborhood', 'landmark', 'city', 'country', 'delivery_instructions'];

    /** Champs qui ne peuvent pas être vidés (colonnes NOT NULL). */
    private const REQUIRED_FIELDS = ['address_line', 'city', 'country'];

    private BaseConnection $db;

    public function __construct(
        private readonly CustomerAddressModel $addresses = new CustomerAddressModel(),
        private readonly UserRepository $users = new UserRepository(),
        ?BaseConnection $db = null,
    ) {
        $this->db = $db ?? db_connect();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $userId): array
    {
        $rows = $this->addresses->where('user_id', $userId)->orderBy('is_default', 'DESC')->orderBy('id', 'DESC')->findAll();

        return array_map(CustomerPresenter::address(...), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function show(int $userId, int $addressId): array
    {
        return CustomerPresenter::address($this->owned($userId, $addressId));
    }

    /**
     * Adresse brute (non supprimée) du client, ou null.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $userId, int $addressId): ?array
    {
        return $this->addresses->where('user_id', $userId)->find($addressId);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function create(int $userId, array $input): array
    {
        $data = InputValidator::validate($input, CustomerRules::address());

        $count = $this->addresses->where('user_id', $userId)->countAllResults();

        if ($count >= self::MAX_ADDRESSES) {
            throw ApiException::conflict('Vous avez atteint le nombre maximum de ' . self::MAX_ADDRESSES . ' adresses.', [], 'ADDRESS_LIMIT_REACHED');
        }

        $user      = $this->users->find($userId) ?? throw ApiException::unauthorized();
        $isDefault = $count === 0 || (isset($data['is_default']) && WapiRules::toBool($data['is_default']));
        // À la création, un champ vide équivaut à un champ absent : les valeurs par défaut s'appliquent.
        $columns   = array_filter($this->columns($data), static fn ($value): bool => $value !== null) + [
            'recipient_name'  => trim($user['first_name'] . ' ' . ($user['last_name'] ?? '')),
            'recipient_phone' => $user['phone'],
            'city'            => 'Kinshasa',
            'country'         => 'RDC',
        ];

        $this->db->transException(true)->transStart();

        if ($isDefault) {
            $this->clearDefault($userId);
        }

        $id = (int) $this->addresses->insert($columns + ['user_id' => $userId, 'is_default' => $isDefault]);

        $this->db->transComplete();

        return CustomerPresenter::address($this->addresses->find($id));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function update(int $userId, int $addressId, array $input): array
    {
        $address = $this->owned($userId, $addressId);
        $data    = InputValidator::validate($input, CustomerRules::address(creating: false));
        $columns = $this->columns($data);

        foreach (self::REQUIRED_FIELDS as $field) {
            if (array_key_exists($field, $columns) && $columns[$field] === null) {
                unset($columns[$field]);
            }
        }

        $this->db->transException(true)->transStart();

        if (isset($data['is_default']) && WapiRules::toBool($data['is_default']) && ! $address['is_default']) {
            $this->clearDefault($userId);
            $columns['is_default'] = true;
        }

        if ($columns !== []) {
            $this->addresses->update($addressId, $columns);
        }

        $this->db->transComplete();

        return CustomerPresenter::address($this->addresses->find($addressId));
    }

    public function delete(int $userId, int $addressId): void
    {
        $address = $this->owned($userId, $addressId);

        $this->db->transException(true)->transStart();

        $this->addresses->update($addressId, ['is_default' => false]);
        $this->addresses->delete($addressId);

        if ($address['is_default']) {
            $next = $this->addresses->where('user_id', $userId)->orderBy('id', 'DESC')->first();

            if ($next !== null) {
                $this->addresses->update((int) $next['id'], ['is_default' => true]);
            }
        }

        $this->db->transComplete();
    }

    /**
     * @return array<string, mixed>
     */
    private function owned(int $userId, int $addressId): array
    {
        return $this->find($userId, $addressId) ?? throw ApiException::notFound('Adresse introuvable.');
    }

    private function clearDefault(int $userId): void
    {
        $this->addresses->builder()->where('user_id', $userId)->where('deleted_at', null)->update(['is_default' => 0]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        $columns = [];

        foreach (self::TEXT_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $value           = trim((string) $data[$field]);
                $columns[$field] = $value === '' ? null : $value;
            }
        }

        if (array_key_exists('recipient_phone', $data) && $data['recipient_phone'] !== null && $data['recipient_phone'] !== '') {
            $columns['recipient_phone'] = PhoneNumber::normalize((string) $data['recipient_phone']);
        }

        foreach (['latitude', 'longitude'] as $field) {
            if (array_key_exists($field, $data)) {
                $columns[$field] = ($data[$field] === null || $data[$field] === '') ? null : (string) $data[$field];
            }
        }

        return $columns;
    }
}
