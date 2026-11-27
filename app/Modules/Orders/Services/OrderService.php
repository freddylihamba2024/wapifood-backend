<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Common\Exceptions\ApiException;
use App\Common\Http\Pagination;
use App\Common\Validation\InputValidator;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Deliveries\Services\DeliveryService;
use App\Modules\Notifications\Services\NotificationDispatcher;
use App\Modules\Orders\Models\OrderStatusHistoryModel;
use App\Modules\Orders\Presenters\OrderPresenter;
use App\Modules\Orders\Repositories\OrderRepository;
use App\Modules\Orders\Validators\OrderRules;
use App\Modules\Promotions\Services\PromotionService;
use App\Modules\Reviews\Services\ReviewService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;
use Throwable;

/**
 * Consultation des commandes et changements de statut, côté client et côté restaurant.
 *
 * Isolation : une commande d'un autre client ou d'un autre restaurant est introuvable (404).
 * Concurrence : le changement de statut est conditionné au statut lu (UPDATE … WHERE status = ancien).
 * Si un autre acteur l'a modifié entre-temps (client qui annule pendant que le restaurant accepte),
 * la seconde requête reçoit 409 ORDER_STATUS_CHANGED au lieu d'écraser la première.
 *
 * Livraison (Phase 7) : la ligne wf_deliveries suit chaque changement de statut dans la même transaction.
 * Une livraison échouée annule la commande (out_for_delivery → cancelled, motif obligatoire, Ph7-2) :
 * cette transition n'est possible que par POST …/delivery/failure, jamais par PATCH …/status.
 */
final class OrderService
{
    private BaseConnection $db;

    public function __construct(
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly OrderStateMachine $machine = new OrderStateMachine(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly CommissionService $commissions = new CommissionService(),
        private readonly DeliveryService $deliveries = new DeliveryService(),
        private readonly PromotionService $promotions = new PromotionService(),
        private readonly ReviewService $reviews = new ReviewService(),
        private readonly NotificationDispatcher $notifications = new NotificationDispatcher(),
        ?BaseConnection $db = null,
    ) {
        $this->db = $db ?? db_connect();
    }

    // ------------------------------------------------------------------
    // Client
    // ------------------------------------------------------------------

    /**
     * @param array{status?: list<string>} $filters
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>}
     */
    public function listForCustomer(int $customerId, array $filters, Pagination $pagination): array
    {
        [$rows, $meta] = $this->orders->searchForCustomer($customerId, $filters, $pagination);

        return [$this->summaries($rows, OrderPresenter::AUDIENCE_CUSTOMER), $meta];
    }

    /**
     * @return array<string, mixed>
     */
    public function showForCustomer(int $customerId, int $orderId): array
    {
        $order = $this->orders->findForCustomer($customerId, $orderId) ?? throw ApiException::notFound('Commande introuvable.');

        return $this->detail($order, OrderStateMachine::ACTOR_CUSTOMER, OrderPresenter::AUDIENCE_CUSTOMER);
    }

    /**
     * Annulation par le client : uniquement tant que le restaurant n'a pas accepté la commande.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function cancelByCustomer(int $customerId, int $orderId, array $input): array
    {
        $data  = InputValidator::validate($input, OrderRules::cancellation());
        $order = $this->orders->findForCustomer($customerId, $orderId) ?? throw ApiException::notFound('Commande introuvable.');

        if (! $this->machine->canTransition($order['status'], 'cancelled', $order['order_type'], OrderStateMachine::ACTOR_CUSTOMER)) {
            throw ApiException::conflict(
                'Cette commande ne peut plus être annulée : le restaurant l\'a déjà prise en charge. Contactez le restaurant.',
                ['current_status' => $order['status']],
                'ORDER_NOT_CANCELLABLE',
            );
        }

        $this->transition($order, 'cancelled', OrderStateMachine::ACTOR_CUSTOMER, $customerId, $this->reason($data));

        return $this->showForCustomer($customerId, $orderId);
    }

    // ------------------------------------------------------------------
    // Restaurant
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>}
     */
    public function listForRestaurant(int $restaurantId, array $filters, Pagination $pagination): array
    {
        [$rows, $meta] = $this->orders->searchForRestaurant($restaurantId, $filters, $pagination);

        return [$this->summaries($rows, OrderPresenter::AUDIENCE_RESTAURANT), $meta];
    }

    /**
     * @return array<string, mixed>
     */
    public function showForRestaurant(int $restaurantId, int $orderId): array
    {
        $order = $this->orders->findForRestaurant($restaurantId, $orderId) ?? throw ApiException::notFound('Commande introuvable.');

        return $this->detail($order, OrderStateMachine::ACTOR_RESTAURANT, OrderPresenter::AUDIENCE_RESTAURANT);
    }

    /**
     * @param array<string, mixed> $input { status, reason?, courier_name?, courier_phone? }
     *                                    (livreur facultatif, pris en compte au départ en livraison)
     *
     * @return array<string, mixed>
     */
    public function changeStatusByRestaurant(int $restaurantId, int $userId, int $orderId, array $input): array
    {
        $data   = InputValidator::validate($input, OrderRules::statusChange());
        $order  = $this->orders->findForRestaurant($restaurantId, $orderId) ?? throw ApiException::notFound('Commande introuvable.');
        $to     = (string) $data['status'];
        $reason = $this->reason($data);

        $this->machine->assertTransition($order['status'], $to, $order['order_type'], OrderStateMachine::ACTOR_RESTAURANT);

        if ($reason === null && $this->machine->requiresReason($to, OrderStateMachine::ACTOR_RESTAURANT)) {
            throw ApiException::validation(['reason' => 'Indiquez le motif : il sera communiqué au client.']);
        }

        $beforeCommit = $to === 'out_for_delivery'
            ? fn () => $this->deliveries->assignOnDeparture($orderId, $input)
            : null;

        $this->transition($order, $to, OrderStateMachine::ACTOR_RESTAURANT, $userId, $reason, beforeCommit: $beforeCommit);

        return $this->showForRestaurant($restaurantId, $orderId);
    }

    /**
     * Livraison impossible (client absent ou injoignable, adresse introuvable…) : la livraison passe
     * à « failed » et la commande est annulée avec le motif, communiqué au client (Ph7-2).
     *
     * @param array<string, mixed> $input { reason }
     *
     * @return array<string, mixed>
     */
    public function failDeliveryByRestaurant(int $restaurantId, int $userId, int $orderId, array $input): array
    {
        $data  = InputValidator::validate($input, ['reason' => ['label' => 'motif', 'rules' => 'required|string|min_length[3]|max_length[500]']]);
        $order = $this->orders->findForRestaurant($restaurantId, $orderId) ?? throw ApiException::notFound('Commande introuvable.');

        if ($order['order_type'] !== 'delivery' || $order['status'] !== 'out_for_delivery') {
            throw ApiException::conflict(
                'Un échec de livraison ne peut être déclaré que pour une commande partie en livraison.',
                ['current_status' => $order['status']],
                'DELIVERY_NOT_IN_PROGRESS',
            );
        }

        $this->transition($order, 'cancelled', OrderStateMachine::ACTOR_RESTAURANT, $userId, $this->reason($data), deliveryFailed: true);

        return $this->showForRestaurant($restaurantId, $orderId);
    }

    // ------------------------------------------------------------------
    // Administration
    // ------------------------------------------------------------------

    /**
     * Détail d'une commande pour le support WapiFood (aucune transition proposée).
     *
     * @return array<string, mixed>
     */
    public function showForAdmin(int $orderId): array
    {
        $order = $this->orders->find($orderId) ?? throw ApiException::notFound('Commande introuvable.');

        return $this->detail($order, 'admin', OrderPresenter::AUDIENCE_RESTAURANT) + [
            'restaurant' => ['id' => (int) $order['restaurant_id'], 'name' => $order['restaurant_name']],
        ];
    }

    // ------------------------------------------------------------------
    // Interne
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $order
     */
    private function transition(array $order, string $to, string $actor, int $userId, ?string $reason, bool $deliveryFailed = false, ?callable $beforeCommit = null): void
    {
        $from    = (string) $order['status'];
        $orderId = (int) $order['id'];
        $now     = Time::now('UTC')->toDateTimeString();
        $changes = ['status' => $to];

        if ($to === 'accepted') {
            $changes['accepted_at'] = $now;
        } elseif ($to === 'completed') {
            $changes['completed_at'] = $now;
        } elseif ($to === 'cancelled' || $to === 'rejected') {
            $changes['cancelled_at']        = $now;
            $changes['cancellation_reason'] = $reason;
        }

        $this->db->transBegin();

        try {
            $this->db->table('wf_orders')->where('id', $orderId)->where('status', $from)->update($changes);

            if ($this->db->affectedRows() !== 1) {
                throw ApiException::conflict(
                    'Le statut de la commande vient d\'être modifié. Rechargez la commande.',
                    [],
                    'ORDER_STATUS_CHANGED',
                );
            }

            (new OrderStatusHistoryModel())->insert([
                'order_id'   => $orderId,
                'old_status' => $from,
                'new_status' => $to,
                'changed_by' => $userId,
                'comment'    => $reason,
            ]);

            if ($order['order_type'] === 'delivery') {
                if ($beforeCommit !== null) {
                    $beforeCommit();
                }

                $this->deliveries->syncWithOrder($orderId, $to, $deliveryFailed);
            }

            if ($to === 'completed') {
                // La vente a eu lieu : la commission devient due par le restaurant (journal).
                $this->commissions->earn($orderId, $userId);
            } elseif ($this->machine->isFinal($to)) {
                // Refus ou annulation : rien n'est dû, et le paiement non encaissé est annulé.
                $this->commissions->reverse($orderId, $userId);
                $this->db->table('wf_payments')
                    ->where('order_id', $orderId)
                    ->whereIn('status', ['initiated', 'pending'])
                    ->update(['status' => 'cancelled']);
            }

            $this->audit->log($deliveryFailed ? 'order.delivery_failed' : 'order.status_changed', $userId, 'order', $orderId, [
                'from'  => $from,
                'to'    => $to,
                'actor' => $actor,
            ]);

            $event = self::notificationEvent($to, $order['order_type'], $actor, $deliveryFailed);

            if ($event !== null) {
                $this->notifications->orderEvent($event, $orderId, ['reason' => $reason]);
            }

            $this->db->transCommit();
        } catch (Throwable $exception) {
            $this->db->transRollback();

            throw $exception;
        }
    }

    /**
     * Événement de notification d'un changement de statut (Config\Notifications::$events).
     * Livraison : « prête » est une étape interne au restaurant, le client est prévenu au départ.
     */
    private static function notificationEvent(string $to, string $orderType, string $actor, bool $deliveryFailed): ?string
    {
        return match (true) {
            $deliveryFailed                                   => 'order.delivery_failed',
            $to === 'cancelled' && $actor === OrderStateMachine::ACTOR_CUSTOMER => 'order.cancelled_by_customer',
            $to === 'ready' && $orderType === 'delivery'      => null,
            in_array($to, ['accepted', 'rejected', 'preparing', 'ready', 'out_for_delivery', 'completed', 'cancelled'], true) => 'order.' . $to,
            default                                           => null,
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function reason(array $data): ?string
    {
        $reason = trim((string) ($data['reason'] ?? ''));

        return $reason === '' ? null : $reason;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function summaries(array $rows, string $audience): array
    {
        $counts = $this->orders->itemCountsFor(array_map(static fn (array $r): int => (int) $r['id'], $rows));

        return array_map(static fn (array $r): array => OrderPresenter::summary($r, $counts[(int) $r['id']] ?? 0, $audience), $rows);
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    private function detail(array $order, string $actor, string $audience): array
    {
        $orderId = (int) $order['id'];

        $detail = OrderPresenter::detail(
            $order,
            $this->orders->itemsFor([$orderId])[$orderId],
            $this->orders->historyOf($orderId),
            $this->orders->latestPaymentOf($orderId),
            $this->machine->nextStatuses($order['status'], $order['order_type'], $actor),
            $audience,
        ) + [
            'promotion' => $this->promotions->forOrder($orderId),
            'delivery'  => DeliveryService::present($this->deliveries->forOrder($orderId), $audience),
        ];

        if ($audience === OrderPresenter::AUDIENCE_CUSTOMER) {
            $detail += $this->reviews->reviewStateForOrder($order);
        }

        return $detail;
    }
}
