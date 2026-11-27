<?php

declare(strict_types=1);

namespace App\Controllers\API\V1\Restaurant;

use App\Controllers\API\BaseApiController;
use App\Modules\Restaurants\Services\OpeningHoursService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Horaires d'ouverture : /api/v1/restaurant/opening-hours
 */
final class OpeningHoursController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        return $this->ok((new OpeningHoursService())->list($this->restaurantId()));
    }

    public function replace(): ResponseInterface
    {
        return $this->ok((new OpeningHoursService())->replace($this->restaurantId(), $this->input()['days'] ?? null), 'Horaires mis à jour.');
    }
}
