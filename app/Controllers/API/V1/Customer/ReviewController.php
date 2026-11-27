<?php

declare(strict_types=1);

namespace App\Controllers\API\V1\Customer;

use App\Common\Http\Pagination;
use App\Controllers\API\BaseApiController;
use App\Modules\Reviews\Services\ReviewService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Avis du client : POST /api/v1/customer/orders/{id}/review, GET /api/v1/customer/reviews
 */
final class ReviewController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        [$items, $meta] = (new ReviewService())->listForCustomer($this->auth()->userId(), Pagination::fromRequest($this->request));

        return $this->paginated($items, $meta);
    }

    public function create(int $orderId): ResponseInterface
    {
        return $this->created((new ReviewService())->create($this->auth()->userId(), $orderId, $this->input()), 'Merci pour votre avis.');
    }
}
