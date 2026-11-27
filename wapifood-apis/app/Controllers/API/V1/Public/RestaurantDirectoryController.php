<?php

declare(strict_types=1);

namespace App\Controllers\API\V1\Public;

use App\Common\Http\Pagination;
use App\Controllers\API\BaseApiController;
use App\Modules\Restaurants\Services\RestaurantDirectoryService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Annuaire public : /api/v1/restaurants (sans authentification).
 */
final class RestaurantDirectoryController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        [$items, $meta] = (new RestaurantDirectoryService())->list([
            'q'        => $this->queryString('q'),
            'city'     => $this->queryString('city'),
            'commune'  => $this->queryString('commune'),
            'featured' => $this->queryBool('featured'),
            'delivery' => $this->queryBool('delivery'),
            'pickup'   => $this->queryBool('pickup'),
        ], Pagination::fromRequest($this->request));

        return $this->paginated($items, $meta);
    }

    public function show(int $restaurantId): ResponseInterface
    {
        return $this->ok((new RestaurantDirectoryService())->show($restaurantId));
    }

    public function categories(int $restaurantId): ResponseInterface
    {
        return $this->ok((new RestaurantDirectoryService())->categories($restaurantId));
    }

    public function products(int $restaurantId): ResponseInterface
    {
        $category = $this->request->getGet('category_id');

        [$items, $meta] = (new RestaurantDirectoryService())->products($restaurantId, [
            'category_id' => is_string($category) && ctype_digit($category) ? (int) $category : null,
            'q'           => $this->queryString('q'),
            'featured'    => $this->queryBool('featured'),
        ], Pagination::fromRequest($this->request));

        return $this->paginated($items, $meta);
    }

    public function product(int $restaurantId, int $productId): ResponseInterface
    {
        return $this->ok((new RestaurantDirectoryService())->product($restaurantId, $productId));
    }

    public function reviews(int $restaurantId): ResponseInterface
    {
        [$items, $meta, $rating] = (new RestaurantDirectoryService())->reviews($restaurantId, Pagination::fromRequest($this->request));

        return $this->ok($items, 'Opération effectuée avec succès.', ['pagination' => $meta, 'rating' => $rating]);
    }
}
