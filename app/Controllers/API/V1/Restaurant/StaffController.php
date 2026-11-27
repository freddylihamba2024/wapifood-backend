<?php

declare(strict_types=1);

namespace App\Controllers\API\V1\Restaurant;

use App\Controllers\API\BaseApiController;
use App\Modules\Restaurants\Services\StaffService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Équipe du restaurant : /api/v1/restaurant/staff
 */
final class StaffController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        return $this->ok((new StaffService())->list($this->restaurantId()));
    }

    public function create(): ResponseInterface
    {
        return $this->created((new StaffService())->add($this->restaurantId(), $this->auth()->userId(), $this->input()), 'Membre ajouté à l\'équipe.');
    }

    public function update(int $memberId): ResponseInterface
    {
        return $this->ok((new StaffService())->update($this->restaurantId(), $this->auth()->userId(), $memberId, $this->input()), 'Membre mis à jour.');
    }

    public function delete(int $memberId): ResponseInterface
    {
        (new StaffService())->remove($this->restaurantId(), $this->auth()->userId(), $memberId);

        return $this->noContent();
    }
}
