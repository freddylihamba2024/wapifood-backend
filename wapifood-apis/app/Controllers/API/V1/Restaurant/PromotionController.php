<?php

declare(strict_types=1);

namespace App\Controllers\API\V1\Restaurant;

use App\Common\Exceptions\ApiException;
use App\Common\Http\Pagination;
use App\Controllers\API\BaseApiController;
use App\Modules\Promotions\Services\PromotionService;
use App\Modules\Restaurants\Repositories\RestaurantRepository;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Codes promo du restaurant (remise à sa charge) : /api/v1/restaurant/promotions
 */
final class PromotionController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        [$items, $meta] = (new PromotionService())->list($this->restaurantId(), $this->queryBool('active'), Pagination::fromRequest($this->request));

        return $this->paginated($items, $meta);
    }

    public function show(int $promotionId): ResponseInterface
    {
        return $this->ok((new PromotionService())->show($this->restaurantId(), $promotionId));
    }

    public function create(): ResponseInterface
    {
        return $this->created((new PromotionService())->create($this->restaurant(), $this->auth()->userId(), $this->input()), 'Code promo créé.');
    }

    public function update(int $promotionId): ResponseInterface
    {
        return $this->ok((new PromotionService())->update($this->restaurant(), $this->auth()->userId(), $promotionId, $this->input()), 'Code promo mis à jour.');
    }

    public function status(int $promotionId): ResponseInterface
    {
        $promotion = (new PromotionService())->setActive($this->restaurantId(), $this->auth()->userId(), $promotionId, $this->input()['is_active'] ?? null);

        return $this->ok($promotion, $promotion['is_active'] ? 'Code promo activé.' : 'Code promo désactivé.');
    }

    public function delete(int $promotionId): ResponseInterface
    {
        (new PromotionService())->delete($this->restaurantId(), $this->auth()->userId(), $promotionId);

        return $this->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function restaurant(): array
    {
        return (new RestaurantRepository())->find($this->restaurantId()) ?? throw ApiException::notFound('Restaurant introuvable.');
    }
}
