<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Channels\NotificationChannelRegistry;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;
use Config\Notifications;
use Throwable;

/**
 * Crée les notifications d'un événement métier, DANS la transaction de l'appelant :
 * si l'action est annulée, aucune notification n'existe ; si elle est validée, elles existent toutes.
 *
 * - in_app : enregistrée directement comme envoyée (statut « sent », non lue) ;
 * - canaux externes actifs : ligne « queued » avec le destinataire figé, envoyée plus tard par
 *   `php spark notifications:process` (jamais d'appel réseau pendant une transaction) ;
 * - canal inactif ou destinataire inconnu (pas d'e-mail…) : ignoré, sans bloquer les autres canaux.
 *
 * Non bloquant : une erreur ici est journalisée et n'empêche jamais l'action métier.
 */
final class NotificationDispatcher
{
    private BaseConnection $db;

    public function __construct(
        private readonly ?NotificationChannelRegistry $channels = null,
        private readonly ?Notifications $config = null,
        ?BaseConnection $db = null,
    ) {
        $this->db = $db ?? db_connect();
    }

    /**
     * Événement lié à une commande (client et/ou restaurant selon la configuration).
     *
     * @param array<string, scalar|null> $vars Variables supplémentaires du message (reason, amount…)
     */
    public function orderEvent(string $event, int $orderId, array $vars = []): void
    {
        $this->safely($event, function () use ($event, $orderId, $vars): void {
            $order = $this->db->table('wf_orders o')
                ->select('o.id, o.order_number, o.customer_id, o.customer_phone, o.restaurant_id, o.currency, r.name AS restaurant_name, r.phone AS restaurant_phone, r.email AS restaurant_email, u.email AS customer_email')
                ->join('wf_restaurants r', 'r.id = o.restaurant_id')
                ->join('wf_users u', 'u.id = o.customer_id')
                ->where('o.id', $orderId)
                ->get()
                ->getRowArray();

            if ($order === null) {
                return;
            }

            $this->dispatch($event, [
                'customer'   => ['user_id' => (int) $order['customer_id'], 'restaurant_id' => (int) $order['restaurant_id'], 'phone' => $order['customer_phone'], 'email' => $order['customer_email']],
                'restaurant' => ['user_id' => null, 'restaurant_id' => (int) $order['restaurant_id'], 'phone' => $order['restaurant_phone'], 'email' => $order['restaurant_email']],
            ], $vars + [
                'order'      => $order['order_number'],
                'restaurant' => $order['restaurant_name'],
                'currency'   => $order['currency'],
            ], ['order_id' => $orderId, 'order_number' => $order['order_number'], 'restaurant_id' => (int) $order['restaurant_id']]);
        });
    }

    /**
     * Événement adressé au propriétaire d'un restaurant (validation, refus, suspension…).
     *
     * @param array<string, scalar|null> $vars
     */
    public function restaurantEvent(string $event, int $restaurantId, array $vars = []): void
    {
        $this->safely($event, function () use ($event, $restaurantId, $vars): void {
            $restaurant = $this->db->table('wf_restaurants r')
                ->select('r.id, r.name, r.owner_user_id, u.phone AS owner_phone, u.email AS owner_email')
                ->join('wf_users u', 'u.id = r.owner_user_id')
                ->where('r.id', $restaurantId)
                ->get()
                ->getRowArray();

            if ($restaurant === null) {
                return;
            }

            $this->dispatch($event, [
                'owner' => ['user_id' => (int) $restaurant['owner_user_id'], 'restaurant_id' => $restaurantId, 'phone' => $restaurant['owner_phone'], 'email' => $restaurant['owner_email']],
            ], $vars + ['restaurant' => $restaurant['name']], ['restaurant_id' => $restaurantId]);
        });
    }

    /**
     * @param array<string, array{user_id: int|null, restaurant_id: int|null, phone: string|null, email: string|null}> $targets
     * @param array<string, scalar|null> $vars
     * @param array<string, mixed>       $data Données structurées pour l'application (liens profonds)
     *
     * @return int Nombre de notifications créées
     */
    public function dispatch(string $event, array $targets, array $vars, array $data = []): int
    {
        $config   = $this->config ?? config(Notifications::class);
        $channels = $this->channels ?? service('notificationChannels');
        $text     = NotificationTemplates::render($event, $vars);
        $now      = Time::now('UTC')->toDateTimeString();
        $rows     = [];

        foreach ($config->events[$event] ?? [] as $audience => $audienceChannels) {
            $target = $targets[$audience] ?? null;

            if ($target === null) {
                continue;
            }

            foreach ($audienceChannels as $channel) {
                if (! $channels->isEnabled($channel)) {
                    continue;
                }

                $recipient = match ($channel) {
                    'in_app'          => null,
                    'email'           => $target['email'] ?? null,
                    'sms', 'whatsapp' => $target['phone'] ?? null,
                    default           => null,
                };

                // In-app : un compte (user_id) ou l'équipe du restaurant (restaurant_id seul).
                if (($channel === 'in_app' && $target['user_id'] === null && $target['restaurant_id'] === null)
                    || ($channel !== 'in_app' && ($recipient === null || trim($recipient) === ''))) {
                    continue;
                }

                $rows[] = [
                    'user_id'           => $target['user_id'],
                    'restaurant_id'     => $target['restaurant_id'],
                    'channel'           => $channel,
                    'recipient'         => $recipient,
                    'notification_type' => $event,
                    'title'             => $text['title'],
                    'message'           => $text['message'],
                    'data'              => $data === [] ? null : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'status'            => $channel === 'in_app' ? 'sent' : 'queued',
                    'max_attempts'      => max(1, min(255, $config->maxAttempts)),
                    'sent_at'           => $channel === 'in_app' ? $now : null,
                    'created_at'        => $now,
                ];
            }
        }

        if ($rows !== []) {
            $this->db->table('wf_notifications')->insertBatch($rows);
        }

        return count($rows);
    }

    private function safely(string $event, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            log_message('error', 'Notification « {event} » non créée : {message}', ['event' => $event, 'message' => $exception->getMessage()]);
        }
    }
}
