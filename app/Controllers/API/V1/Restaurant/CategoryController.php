<?php

declare(strict_types=1);

namespace App\Controllers\API\V1\Restaurant;

use App\Controllers\API\BaseApiController;
use App\Modules\Menus\Services\CategoryService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Catégories du menu : /api/v1/restaurant/categories
 */
final class CategoryController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        return $this->ok((new CategoryService())->list($this->restaurantId(), activeOnly: false));
    }

    public function create(): ResponseInterface
    {
        return $this->created((new CategoryService())->create($this->restaurantId(), $this->input()), 'Catégorie créée.');
    }

    public function update(int $categoryId): ResponseInterface
    {
        return $this->ok((new CategoryService())->update($this->restaurantId(), $categoryId, $this->input()), 'Catégorie mise à jour.');
    }

    public function delete(int $categoryId): ResponseInterface
    {
        (new CategoryService())->delete($this->restaurantId(), $categoryId);

        return $this->noContent();
    }

    public function reorder(): ResponseInterface
    {
        return $this->ok((new CategoryService())->reorder($this->restaurantId(), $this->input()['category_ids'] ?? null), 'Ordre des catégories mis à jour.');
    }

    public function uploadImage(int $categoryId): ResponseInterface
    {
        return $this->ok((new CategoryService())->replaceImage($this->restaurantId(), $categoryId, $this->request->getFile('file')), 'Image mise à jour.');
    }
}
