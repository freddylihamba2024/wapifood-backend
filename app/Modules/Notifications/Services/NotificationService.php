<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Common\Exceptions\ApiException;
use App\Common\Http\Pagination;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Users\Presenters\UserPresenter;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;
use Config\Notifications;

/**
 * Consultation des notifications in-app et supervision de la file.
 *
 * Deux boîtes de réception :
 * - celle d'un COMPTE (user_id) : client, propriétaire… ;
 * - celle d'un RESTAURANT (user_id NULL, restaurant_id) : partagée par l'équipe ; « lue » par l'un,
 *   elle l'est pour tous (Ph8-4).
 */
final class NotificationService
{
    public const STATUSES = ['queued', 'sent', 'failed', 'read'];
    public const CHANNELS = ['in_app', 'email', 'sms', 'whatsapp', 'push'];

    private BaseConnection $db;

    public function __construct(private readonly AuditLogger $audit = new AuditLogger(), ?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    // ------------------------------------------------------------------
    // Boîtes de réception
    // ------------------------------------------------------------------

    /**
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>, 2: int} [notifications, pagination, non lues]
     */
    public function inbox(?int $userId, ?int $restaurantId, bool $unreadOnly, Pagination $pagination): array
    {
        $builder = $this->inboxQuery($userId, $restaurantId)->orderBy('id', 'DESC');

        if ($unreadOnly) {
            $builder->where('read_at', null);
        }

        [$rows, $meta] = $pagination->run($builder);

        return [array_map(self::present(...), $rows), $meta, $this->inboxQuery($userId, $restaurantId)->where('read_at', null)->countAllResults()];
    }

    /**
     * @return array<string, mixed>
     */
    public function markRead(?int $userId, ?int $restaurantId, int $notificationId): array
    {
        $row = $this->inboxQuery($userId, $restaurantId)->where('id', $notificationId)->get()->getRowArray()
            ?? throw ApiException::notFound('Notification introuvable.');

        if ($row['read_at'] === null) {
            $this->db->table('wf_notifications')->where('id', $notificationId)->where('read_at', null)
                ->update(['status' => 'read', 'read_at' => Time::now('UTC')->toDateTimeString()]);
            $row = $this->db->table('wf_notifications')->where('id', $notificationId)->get()->getRowArray();
        }

        return self::present($row);
    }

    /** @return int Nombre de notifications marquées comme lues */
    public function markAllRead(?int $userId, ?int $restaurantId): int
    {
        $this->inboxQuery($userId, $restaurantId)->where('read_at', null)
            ->update(['status' => 'read', 'read_at' => Time::now('UTC')->toDateTimeString()]);

        return $this->db->affectedRows();
    }

    // ------------------------------------------------------------------
    // Administration
    // ------------------------------------------------------------------

    /**
     * @param array{status?: string|null, channel?: string|null, type?: string|null} $filters
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>}
     */
    public function adminList(array $filters, Pagination $pagination): array
    {
        $builder = $this->db->table('wf_notifications')->orderBy('id', 'DESC');

        foreach (['status' => 'status', 'channel' => 'channel', 'type' => 'notification_type'] as $filter => $column) {
            if (($filters[$filter] ?? null) !== null) {
                $builder->where($column, $filters[$filter]);
            }
        }

        [$rows, $meta] = $pagination->run($builder);

        return [array_map(self::presentAdmin(...), $rows), $meta];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminShow(int $notificationId): array
    {
        $row = $this->db->table('wf_notifications')->where('id', $notificationId)->get()->getRowArray()
            ?? throw ApiException::notFound('Notification introuvable.');

        $attempts = $this->db->table('wf_notification_attempts')->where('notification_id', $notificationId)->orderBy('attempt_number', 'ASC')->get()->getResultArray();

        return self::presentAdmin($row) + ['attempt_history' => array_map(static fn (array $a): array => [
            'attempt'             => (int) $a['attempt_number'],
            'provider'            => $a['provider'],
            'status'              => $a['status'],
            'provider_message_id' => $a['provider_message_id'],
            'error'               => $a['error_message'],
            'created_at'          => UserPresenter::isoDate($a['created_at']),
        ], $attempts)];
    }

    /**
     * Remet en file une notification échouée : de nouvelles tentatives lui sont accordées
     * (l'historique des précédentes est conservé).
     *
     * @return array<string, mixed>
     */
    public function retry(int $adminId, int $notificationId): array
    {
        $row = $this->db->table('wf_notifications')->where('id', $notificationId)->get()->getRowArray()
            ?? throw ApiException::notFound('Notification introuvable.');

        if ($row['status'] !== 'failed') {
            throw ApiException::conflict('Seule une notification en échec peut être renvoyée.', ['status' => $row['status']], 'NOTIFICATION_NOT_FAILED');
        }

        $this->requeue([$notificationId]);
        $this->audit->log('notification.requeued', $adminId, 'notification', $notificationId);

        return $this->adminShow($notificationId);
    }

    /**
     * Remet en file des notifications échouées (commande `notifications:retry`).
     *
     * @param list<int>|null $ids null = toutes les notifications en échec depuis $sinceHours heures
     *
     * @return int Nombre de notifications remises en file
     */
    public function requeue(?array $ids, int $sinceHours = 24): int
    {
        $config  = config(Notifications::class);
        $builder = $this->db->table('wf_notifications')->where('status', 'failed')->where('channel !=', 'in_app');

        if ($ids !== null) {
            if ($ids === []) {
                return 0;
            }

            $builder->whereIn('id', $ids);
        } else {
            $builder->where('created_at >=', gmdate('Y-m-d H:i:s', time() - 3600 * max(1, $sinceHours)));
        }

        // Les numéros de tentative restent uniques : on relève le plafond au lieu de remettre à zéro.
        $builder->set('max_attempts', 'LEAST(255, attempts + ' . max(1, $config->maxAttempts) . ')', false)
            ->set('status', 'queued')
            ->set('next_attempt_at', null)
            ->set('locked_until', null)
            ->update();

        return $this->db->affectedRows();
    }

    // ------------------------------------------------------------------
    // Interne
    // ------------------------------------------------------------------

    private function inboxQuery(?int $userId, ?int $restaurantId): BaseBuilder
    {
        $builder = $this->db->table('wf_notifications')->where('channel', 'in_app');

        return $userId !== null
            ? $builder->where('user_id', $userId)
            : $builder->where('user_id', null)->where('restaurant_id', $restaurantId);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'type'       => $row['notification_type'],
            'title'      => $row['title'],
            'message'    => $row['message'],
            'data'       => $row['data'] === null ? null : json_decode((string) $row['data'], true),
            'is_read'    => $row['read_at'] !== null,
            'read_at'    => UserPresenter::isoDate($row['read_at']),
            'created_at' => UserPresenter::isoDate($row['created_at']),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function presentAdmin(array $row): array
    {
        return self::present($row) + [
            'channel'         => $row['channel'],
            'recipient'       => $row['recipient'],
            'status'          => $row['status'],
            'user_id'         => $row['user_id'] === null ? null : (int) $row['user_id'],
            'restaurant_id'   => $row['restaurant_id'] === null ? null : (int) $row['restaurant_id'],
            'attempts'        => (int) $row['attempts'],
            'max_attempts'    => (int) $row['max_attempts'],
            'next_attempt_at' => UserPresenter::isoDate($row['next_attempt_at']),
            'last_error'      => $row['last_error'],
            'sent_at'         => UserPresenter::isoDate($row['sent_at']),
        ];
    }
}
