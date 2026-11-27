<?php

declare(strict_types=1);

namespace App\Controllers\API\V1\Admin;

use App\Common\Http\Pagination;
use App\Controllers\API\BaseApiController;
use App\Modules\Reviews\Services\ReviewService;
use App\Modules\Reviews\Validators\ReviewFilters;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Modération des avis : /api/v1/admin/reviews (super_admin).
 */
final class ReviewAdminController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        [$items, $meta] = (new ReviewService())->listForAdmin(ReviewFilters::fromQuery($this->request->getGet() ?? [], withRestaurant: true), Pagination::fromRequest($this->request));

        return $this->paginated($items, $meta);
    }

    public function moderate(int $reviewId): ResponseInterface
    {
        $review = (new ReviewService())->moderate($this->auth()->userId(), $reviewId, $this->input());

        return $this->ok($review, $review['status'] === 'hidden' ? 'Avis masqué.' : 'Avis publié.');
    }
}
