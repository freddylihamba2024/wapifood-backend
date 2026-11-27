<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Channels\ChannelException;
use App\Modules\Notifications\Channels\NotificationChannelRegistry;
use App\Modules\Notifications\Models\NotificationAttemptModel;
use CodeIgniter\Database\BaseConnection;
use Config\Notifications;
use Throwable;

/**
 * Envoi des notifications en file (`php spark notifications:process`, cron chaque minute).
 *
 * Pour chaque notification « queued » dont l'heure est venue :
 *   1. réservation atomique (UPDATE … SET locked_until … WHERE non verrouillée) : plusieurs processus
 *      peuvent tourner en même temps sans jamais envoyer deux fois la même notification ;
 *   2. envoi par le canal, hors transaction ;
 *   3. succès → « sent » ; échec temporaire → nouvelle tentative après un délai croissant ;
 *      échec définitif (canal désactivé, destinataire invalide) ou tentatives épuisées → « failed ».
 * Chaque tentative est historisée dans wf_notification_attempts.
 *
 * Un processus arrêté en plein envoi laisse un verrou qui expire après lockSeconds : la notification
 * est alors reprise (un envoi en double reste possible dans ce cas rare, jamais une perte).
 */
final class NotificationQueueProcessor
{
    private BaseConnection $db;

    public function __construct(
        private readonly ?NotificationChannelRegistry $channels = null,
        private readonly ?Notifications $config = null,
        private readonly NotificationAttemptModel $attempts = new NotificationAttemptModel(),
        ?BaseConnection $db = null,
    ) {
        $this->db = $db ?? db_connect();
    }

    /**
     * @return array{processed: int, sent: int, retry: int, failed: int}
     */
    public function process(?int $limit = null, ?int $now = null): array
    {
        $config = $this->config ?? config(Notifications::class);
        $now ??= time();
        $stats  = ['processed' => 0, 'sent' => 0, 'retry' => 0, 'failed' => 0];
        $nowSql = gmdate('Y-m-d H:i:s', $now);

        $ids = array_column($this->db->table('wf_notifications')
            ->select('id')
            ->where('status', 'queued')
            ->where('channel !=', 'in_app')
            ->groupStart()->where('next_attempt_at', null)->orWhere('next_attempt_at <=', $nowSql)->groupEnd()
            ->groupStart()->where('locked_until', null)->orWhere('locked_until <=', $nowSql)->groupEnd()
            ->orderBy('id', 'ASC')
            ->limit(max(1, $limit ?? $config->batchSize))
            ->get()
            ->getResultArray(), 'id');

        foreach ($ids as $id) {
            $outcome = $this->processOne((int) $id, $now, $config);

            if ($outcome !== null) {
                $stats['processed']++;
                $stats[$outcome]++;
            }
        }

        return $stats;
    }

    /**
     * @return 'sent'|'retry'|'failed'|null null = déjà prise par un autre processus
     */
    private function processOne(int $id, int $now, Notifications $config): ?string
    {
        if (! $this->claim($id, $now, $config)) {
            return null;
        }

        $notification = $this->db->table('wf_notifications')->where('id', $id)->get()->getRowArray();
        $attempt      = (int) $notification['attempts'] + 1;
        $channel      = ($this->channels ?? service('notificationChannels'))->get((string) $notification['channel']);
        $provider     = $channel?->provider() ?? 'none';

        try {
            if ($channel === null) {
                throw ChannelException::permanent("Canal « {$notification['channel']} » désactivé.");
            }

            if (trim((string) $notification['recipient']) === '') {
                throw ChannelException::permanent('Aucun destinataire.');
            }

            $data      = $notification['data'] === null ? [] : (json_decode((string) $notification['data'], true) ?? []);
            $messageId = $channel->send((string) $notification['recipient'], (string) $notification['title'], (string) $notification['message'], $data);
        } catch (Throwable $exception) {
            $permanent = $exception instanceof ChannelException && $exception->permanent;
            $error     = mb_substr($exception instanceof ChannelException ? $exception->getMessage() : 'Erreur inattendue : ' . $exception->getMessage(), 0, 1000);

            return $this->fail($notification, $attempt, $provider, $error, $permanent, $now, $config);
        }

        $this->attempts->insert([
            'notification_id'     => $id,
            'attempt_number'      => $attempt,
            'provider'            => $provider,
            'status'              => 'sent',
            'provider_message_id' => $messageId === null ? null : mb_substr($messageId, 0, 190),
        ]);

        $this->db->table('wf_notifications')->where('id', $id)->update([
            'status'          => 'sent',
            'attempts'        => $attempt,
            'sent_at'         => gmdate('Y-m-d H:i:s', $now),
            'next_attempt_at' => null,
            'locked_until'    => null,
            'last_error'      => null,
        ]);

        return 'sent';
    }

    /**
     * @param array<string, mixed> $notification
     *
     * @return 'retry'|'failed'
     */
    private function fail(array $notification, int $attempt, string $provider, string $error, bool $permanent, int $now, Notifications $config): string
    {
        $this->attempts->insert([
            'notification_id' => (int) $notification['id'],
            'attempt_number'  => $attempt,
            'provider'        => $provider,
            'status'          => 'failed',
            'error_message'   => $error,
        ]);

        $final = $permanent || $attempt >= (int) $notification['max_attempts'];

        $this->db->table('wf_notifications')->where('id', (int) $notification['id'])->update([
            'status'          => $final ? 'failed' : 'queued',
            'attempts'        => $attempt,
            'next_attempt_at' => $final ? null : gmdate('Y-m-d H:i:s', $now + $config->retryDelaySeconds($attempt)),
            'locked_until'    => null,
            'last_error'      => $error,
        ]);

        if ($final) {
            log_message('warning', 'Notification {id} ({channel}) abandonnée après {attempt} tentative(s) : {error}', [
                'id' => $notification['id'], 'channel' => $notification['channel'], 'attempt' => $attempt, 'error' => $error,
            ]);
        }

        return $final ? 'failed' : 'retry';
    }

    /** Réservation atomique : vrai si ce processus a obtenu la notification. */
    private function claim(int $id, int $now, Notifications $config): bool
    {
        $nowSql = gmdate('Y-m-d H:i:s', $now);

        $this->db->table('wf_notifications')
            ->where('id', $id)
            ->where('status', 'queued')
            ->groupStart()->where('locked_until', null)->orWhere('locked_until <=', $nowSql)->groupEnd()
            ->update(['locked_until' => gmdate('Y-m-d H:i:s', $now + max(30, $config->lockSeconds))]);

        return $this->db->affectedRows() === 1;
    }
}
