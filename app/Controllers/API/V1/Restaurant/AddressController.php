<?php

declare(strict_types=1);

namespace App\Controllers\API\V1\Restaurant;

use App\Controllers\API\BaseApiController;
use App\Modules\Restaurants\Services\RestaurantAddressService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Adresses du restaurant : /api/v1/restaurant/addresses
 */
final class AddressController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        return $this->ok((new RestaurantAddressService())->list($this->restaurantId()));
    }

    public function create(): ResponseInterface
    {
        return $this->created((new RestaurantAddressService())->create($this->restaurantId(), $this->input()), 'Adresse ajoutée.');
    }

    public function update(int $addressId): ResponseInterface
    {
        return $this->ok((new RestaurantAddressService())->update($this->restaurantId(), $addressId, $this->input()), 'Adresse mise à jour.');
    }

    public function delete(int $addressId): ResponseInterface
    {
        (new RestaurantAddressService())->delete($this->restaurantId(), $addressId);

        return $this->noContent();
    }
}
