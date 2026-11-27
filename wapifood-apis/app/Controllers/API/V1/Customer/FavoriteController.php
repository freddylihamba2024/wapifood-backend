<?php

declare(strict_types=1);

namespace App\Controllers\API\V1\Customer;

use App\Common\Http\Pagination;
use App\Controllers\API\BaseApiController;
use App\Modules\Customers\Services\FavoriteService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Restaurants favoris : /api/v1/customer/favorites
 * PUT et DELETE sont idempotents (204 dans tous les cas, sauf restaurant introuvable à l'ajout).
 */
final class FavoriteController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        [$items, $meta] = (new FavoriteService())->list($this->auth()->userId(), Pagination::fromRequest($this->request));

        return $this->paginated($items, $meta);
    }

    public function add(int $restaurantId): ResponseInterface
    {
        (new FavoriteService())->add($this->auth()->userId(), $restaurantId);

        return $this->noContent();
    }

    public function remove(int $restaurantId): ResponseInterface
    {
        (new FavoriteService())->remove($this->auth()->userId(), $restaurantId);

        return $this->noContent();
    }
}
