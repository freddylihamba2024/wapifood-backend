<?php

declare(strict_types=1);

namespace App\Controllers\API\V1\Customer;

use App\Controllers\API\BaseApiController;
use App\Modules\Customers\Services\CustomerAddressService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Carnet d'adresses du client : /api/v1/customer/addresses
 */
final class AddressController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        return $this->ok((new CustomerAddressService())->list($this->auth()->userId()));
    }

    public function show(int $addressId): ResponseInterface
    {
        return $this->ok((new CustomerAddressService())->show($this->auth()->userId(), $addressId));
    }

    public function create(): ResponseInterface
    {
        return $this->created((new CustomerAddressService())->create($this->auth()->userId(), $this->input()), 'Adresse ajoutée.');
    }

    public function update(int $addressId): ResponseInterface
    {
        return $this->ok((new CustomerAddressService())->update($this->auth()->userId(), $addressId, $this->input()), 'Adresse mise à jour.');
    }

    public function delete(int $addressId): ResponseInterface
    {
        (new CustomerAddressService())->delete($this->auth()->userId(), $addressId);

        return $this->noContent();
    }
}
