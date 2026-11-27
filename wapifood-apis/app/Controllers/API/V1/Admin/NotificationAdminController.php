<?php

declare(strict_types=1);

namespace App\Controllers\API\V1\Admin;

use App\Common\Exceptions\ApiException;
use App\Common\Http\Pagination;
use App\Controllers\API\BaseApiController;
use App\Modules\Notifications\Services\NotificationService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Supervision de la file de notifications : /api/v1/admin/notifications (super_admin).
 */
final class NotificationAdminController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $filters = [
            'status'  => $this->enumQuery('status', NotificationService::STATUSES),
            'channel' => $this->enumQuery('channel', NotificationService::CHANNELS),
            'type'    => $this->queryString('type') === '' ? null : $this->queryString('type'),
        ];

        [$items, $meta] = (new NotificationService())->adminList($filters, Pagination::fromRequest($this->request));

        return $this->paginated($items, $meta);
    }

    public function show(int $notificationId): ResponseInterface
    {
        return $this->ok((new NotificationService())->adminShow($notificationId));
    }

    public function retry(int $notificationId): ResponseInterface
    {
        return $this->ok((new NotificationService())->retry($this->auth()->userId(), $notificationId), 'Notification remise en file.');
    }

    /**
     * @param list<string> $allowed
     */
    private function enumQuery(string $name, array $allowed): ?string
    {
        $value = $this->queryString($name);

        if ($value === '') {
            return null;
        }

        return in_array($value, $allowed, true) ? $value : throw ApiException::validation([$name => 'Valeur inconnue. Valeurs possibles : ' . implode(', ', $allowed) . '.']);
    }
}
