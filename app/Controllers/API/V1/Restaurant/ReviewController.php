<?php

declare(strict_types=1);

namespace App\Controllers\API\V1\Restaurant;

use App\Common\Http\Pagination;
use App\Controllers\API\BaseApiController;
use App\Modules\Reviews\Services\ReviewService;
use App\Modules\Reviews\Validators\ReviewFilters;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Avis reçus par le restaurant (masqués compris) : /api/v1/restaurant/reviews
 */
final class ReviewController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        [$items, $meta, $rating] = (new ReviewService())->listForRestaurant(
            $this->restaurantId(),
            ReviewFilters::fromQuery($this->request->getGet() ?? [], withRestaurant: false),
            Pagination::fromRequest($this->request),
        );

        return $this->ok($items, 'Opération effectuée avec succès.', ['pagination' => $meta, 'rating' => $rating]);
    }
}
