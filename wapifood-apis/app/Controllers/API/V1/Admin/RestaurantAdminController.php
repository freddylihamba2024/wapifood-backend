<?php

declare(strict_types=1);

namespace App\Controllers\API\V1\Admin;

use App\Common\Http\Pagination;
use App\Controllers\API\BaseApiController;
use App\Modules\Restaurants\Services\RestaurantAdminService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Administration des restaurants : /api/v1/admin/restaurants (super_admin).
 */
final class RestaurantAdminController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        [$items, $meta] = (new RestaurantAdminService())->list([
            'status' => $this->queryString('status'),
            'q'      => $this->queryString('q'),
        ], Pagination::fromRequest($this->request));

        return $this->paginated($items, $meta);
    }

    public function show(int $restaurantId): ResponseInterface
    {
        return $this->ok((new RestaurantAdminService())->show($restaurantId));
    }

    public function approve(int $restaurantId): ResponseInterface
    {
        return $this->ok((new RestaurantAdminService())->approve($restaurantId, $this->auth()->userId()), 'Restaurant validé.');
    }

    public function reject(int $restaurantId): ResponseInterface
    {
        return $this->ok((new RestaurantAdminService())->reject($restaurantId, $this->auth()->userId(), $this->input()), 'Restaurant refusé.');
    }

    public function suspend(int $restaurantId): ResponseInterface
    {
        return $this->ok((new RestaurantAdminService())->suspend($restaurantId, $this->auth()->userId(), $this->input()), 'Restaurant suspendu.');
    }

    public function reactivate(int $restaurantId): ResponseInterface
    {
        return $this->ok((new RestaurantAdminService())->reactivate($restaurantId, $this->auth()->userId()), 'Restaurant réactivé.');
    }

    public function commission(int $restaurantId): ResponseInterface
    {
        return $this->ok((new RestaurantAdminService())->setCommissionRate($restaurantId, $this->auth()->userId(), $this->input()), 'Taux de commission mis à jour.');
    }
}
