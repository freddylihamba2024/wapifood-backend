<?php

declare(strict_types=1);

namespace App\Controllers\API\V1\Restaurant;

use App\Common\Http\Pagination;
use App\Controllers\API\BaseApiController;
use App\Modules\Notifications\Services\NotificationService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Notifications in-app du restaurant, partagées par l'équipe : /api/v1/restaurant/notifications
 */
final class NotificationController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        [$items, $meta, $unread] = (new NotificationService())->inbox(null, $this->restaurantId(), $this->queryBool('unread') ?? false, Pagination::fromRequest($this->request));

        return $this->ok($items, 'Opération effectuée avec succès.', ['pagination' => $meta, 'unread_count' => $unread]);
    }

    public function read(int $notificationId): ResponseInterface
    {
        return $this->ok((new NotificationService())->markRead(null, $this->restaurantId(), $notificationId), 'Notification lue.');
    }

    public function readAll(): ResponseInterface
    {
        return $this->ok(['updated' => (new NotificationService())->markAllRead(null, $this->restaurantId())], 'Notifications marquées comme lues.');
    }
}
