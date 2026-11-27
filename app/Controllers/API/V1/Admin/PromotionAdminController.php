<?php

declare(strict_types=1);

namespace App\Controllers\API\V1\Admin;

use App\Common\Http\Pagination;
use App\Controllers\API\BaseApiController;
use App\Modules\Promotions\Services\PromotionService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Codes promo plateforme (remise à la charge de WapiFood) : /api/v1/admin/promotions (super_admin).
 */
final class PromotionAdminController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        [$items, $meta] = (new PromotionService())->list(null, $this->queryBool('active'), Pagination::fromRequest($this->request));

        return $this->paginated($items, $meta);
    }

    public function show(int $promotionId): ResponseInterface
    {
        return $this->ok((new PromotionService())->show(null, $promotionId));
    }

    public function create(): ResponseInterface
    {
        return $this->created((new PromotionService())->create(null, $this->auth()->userId(), $this->input()), 'Code promo plateforme créé.');
    }

    public function update(int $promotionId): ResponseInterface
    {
        return $this->ok((new PromotionService())->update(null, $this->auth()->userId(), $promotionId, $this->input()), 'Code promo mis à jour.');
    }

    public function status(int $promotionId): ResponseInterface
    {
        $promotion = (new PromotionService())->setActive(null, $this->auth()->userId(), $promotionId, $this->input()['is_active'] ?? null);

        return $this->ok($promotion, $promotion['is_active'] ? 'Code promo activé.' : 'Code promo désactivé.');
    }

    public function delete(int $promotionId): ResponseInterface
    {
        (new PromotionService())->delete(null, $this->auth()->userId(), $promotionId);

        return $this->noContent();
    }
}
