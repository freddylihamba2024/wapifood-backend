<?php

declare(strict_types=1);

namespace App\Controllers\API\V1\Account;

use App\Common\Http\Pagination;
use App\Controllers\API\BaseApiController;
use App\Modules\Notifications\Services\NotificationService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Notifications in-app du compte connecté : /api/v1/notifications
 */
final class NotificationController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        [$items, $meta, $unread] = (new NotificationService())->inbox($this->auth()->userId(), null, $this->queryBool('unread') ?? false, Pagination::fromRequest($this->request));

        return $this->ok($items, 'Opération effectuée avec succès.', ['pagination' => $meta, 'unread_count' => $unread]);
    }

    public function read(int $notificationId): ResponseInterface
    {
        return $this->ok((new NotificationService())->markRead($this->auth()->userId(), null, $notificationId), 'Notification lue.');
    }

    public function readAll(): ResponseInterface
    {
        return $this->ok(['updated' => (new NotificationService())->markAllRead($this->auth()->userId(), null)], 'Notifications marquées comme lues.');
    }
}
