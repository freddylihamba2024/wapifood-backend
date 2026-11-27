<?php

declare(strict_types=1);

namespace App\Modules\Restaurants\Services;

use App\Common\Exceptions\ApiException;
use App\Common\Validation\InputValidator;
use App\Common\Validation\WapiRules;
use App\Modules\Restaurants\Models\RestaurantAddressModel;
use App\Modules\Restaurants\Presenters\RestaurantPresenter;
use App\Modules\Restaurants\Validators\RestaurantRules;
use CodeIgniter\Database\BaseConnection;

/**
 * Adresses d'un restaurant. Toute opération est limitée au restaurant du contexte :
 * une adresse d'un autre restaurant est traitée comme inexistante (404).
 * Un restaurant garde toujours exactement une adresse principale.
 */
final class RestaurantAddressService
{
    private BaseConnection $db;

    public function __construct(private readonly RestaurantAddressModel $addresses = new RestaurantAddressModel(), ?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $restaurantId): array
    {
        $rows = $this->addresses->where('restaurant_id', $restaurantId)->orderBy('is_primary', 'DESC')->orderBy('id', 'ASC')->findAll();

        return array_map(RestaurantPresenter::address(...), $rows);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function create(int $restaurantId, array $input): array
    {
        $data = InputValidator::validate($input, RestaurantRules::address());

        $this->db->transException(true)->transStart();
        $id = $this->insert($restaurantId, $data);
        $this->db->transComplete();

        return RestaurantPresenter::address($this->addresses->find($id));
    }

    /**
     * Insertion sans validation (données déjà validées). À appeler dans une transaction.
     *
     * @param array<string, mixed> $data
     */
    public function insert(int $restaurantId, array $data): int
    {
        $isFirst   = $this->addresses->where('restaurant_id', $restaurantId)->countAllResults() === 0;
        $isPrimary = $isFirst || (isset($data['is_primary']) && WapiRules::toBool($data['is_primary']));

        if ($isPrimary) {
            $this->clearPrimary($restaurantId);
        }

        return (int) $this->addresses->insert($this->columns($data, true) + [
            'restaurant_id' => $restaurantId,
            'is_primary'    => $isPrimary,
        ]);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function update(int $restaurantId, int $addressId, array $input): array
    {
        $address = $this->owned($restaurantId, $addressId);
        $data    = InputValidator::validate($input, RestaurantRules::address(creating: false));
        $columns = $this->columns($data, false);

        $this->db->transException(true)->transStart();

        if (isset($data['is_primary']) && WapiRules::toBool($data['is_primary']) && ! $address['is_primary']) {
            $this->clearPrimary($restaurantId);
            $columns['is_primary'] = true;
        }

        if ($columns !== []) {
            $this->addresses->update($addressId, $columns);
        }

        $this->db->transComplete();

        return RestaurantPresenter::address($this->addresses->find($addressId));
    }

    public function delete(int $restaurantId, int $addressId): void
    {
        $address = $this->owned($restaurantId, $addressId);

        if ($this->addresses->where('restaurant_id', $restaurantId)->countAllResults() <= 1) {
            throw ApiException::conflict('Le restaurant doit conserver au moins une adresse.', [], 'LAST_ADDRESS');
        }

        $this->db->transException(true)->transStart();

        $this->addresses->delete($addressId);

        if ($address['is_primary']) {
            $next = $this->addresses->where('restaurant_id', $restaurantId)->orderBy('id', 'ASC')->first();
            $this->addresses->update((int) $next['id'], ['is_primary' => true]);
        }

        $this->db->transComplete();
    }

    /**
     * @return array<string, mixed>
     */
    private function owned(int $restaurantId, int $addressId): array
    {
        return $this->addresses->where('restaurant_id', $restaurantId)->find($addressId)
            ?? throw ApiException::notFound('Adresse introuvable.');
    }

    private function clearPrimary(int $restaurantId): void
    {
        $this->addresses->builder()->where('restaurant_id', $restaurantId)->update(['is_primary' => 0]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function columns(array $data, bool $creating): array
    {
        $columns = [];

        foreach (['label', 'address_line', 'commune', 'city', 'province'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = trim((string) $data[$field]);

                if ($value !== '') {
                    $columns[$field] = $value;
                } elseif ($field !== 'address_line' && $field !== 'city') {
                    $columns[$field] = null;
                }
            }
        }

        foreach (['latitude', 'longitude'] as $field) {
            if (array_key_exists($field, $data)) {
                $columns[$field] = ($data[$field] === null || $data[$field] === '') ? null : (string) $data[$field];
            }
        }

        if ($creating) {
            $columns += ['city' => 'Kinshasa'];
        }

        return $columns;
    }
}
