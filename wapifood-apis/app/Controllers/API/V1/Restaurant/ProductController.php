<?php

declare(strict_types=1);

namespace App\Controllers\API\V1\Restaurant;

use App\Common\Http\Pagination;
use App\Controllers\API\BaseApiController;
use App\Modules\Products\Services\ProductService;
use App\Modules\Restaurants\Repositories\RestaurantRepository;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Produits du catalogue : /api/v1/restaurant/products
 */
final class ProductController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $category = $this->request->getGet('category_id');

        [$items, $meta] = (new ProductService())->list($this->restaurantId(), [
            'category_id' => is_string($category) && ctype_digit($category) ? (int) $category : null,
            'q'           => $this->queryString('q'),
            'featured'    => $this->queryBool('featured'),
            'available'   => $this->queryBool('available'),
        ], Pagination::fromRequest($this->request), publicOnly: false);

        return $this->paginated($items, $meta);
    }

    public function show(int $productId): ResponseInterface
    {
        return $this->ok((new ProductService())->show($this->restaurantId(), $productId, publicOnly: false));
    }

    public function create(): ResponseInterface
    {
        $restaurant = (new RestaurantRepository())->find($this->restaurantId());

        return $this->created((new ProductService())->create($restaurant, $this->auth()->userId(), $this->input()), 'Produit créé.');
    }

    public function update(int $productId): ResponseInterface
    {
        return $this->ok((new ProductService())->update($this->restaurantId(), $this->auth()->userId(), $productId, $this->input()), 'Produit mis à jour.');
    }

    public function delete(int $productId): ResponseInterface
    {
        (new ProductService())->delete($this->restaurantId(), $this->auth()->userId(), $productId);

        return $this->noContent();
    }

    public function availability(int $productId): ResponseInterface
    {
        $available = $this->input()['is_available'] ?? null;

        return $this->ok((new ProductService())->setAvailability($this->restaurantId(), $this->auth()->userId(), $productId, $available), 'Disponibilité mise à jour.');
    }

    public function uploadImage(int $productId): ResponseInterface
    {
        $alt = $this->request->getPost('alt_text');

        return $this->created((new ProductService())->addImage($this->restaurantId(), $productId, $this->request->getFile('file'), is_string($alt) ? $alt : null), 'Image ajoutée.');
    }

    public function deleteImage(int $productId, int $imageId): ResponseInterface
    {
        return $this->ok((new ProductService())->deleteImage($this->restaurantId(), $productId, $imageId), 'Image supprimée.');
    }
}
