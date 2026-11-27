<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Common\Exceptions\ApiException;
use App\Common\Support\Money;
use App\Common\Validation\InputValidator;
use App\Common\Validation\WapiRules;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Notifications\Services\NotificationDispatcher;
use App\Modules\Orders\Repositories\OrderRepository;
use App\Modules\Payments\Models\PaymentRefundModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;
use Throwable;

/**
 * Encaissement des espèces et remboursements.
 *
 * - Espèces : le restaurant confirme avoir reçu le montant total, une fois la commande prête
 *   (retrait), en livraison ou terminée. La commande passe à « paid ».
 * - Remboursement : enregistré par l'administration (au MVP, effectué hors ligne par le restaurant
 *   ou WapiFood). Plusieurs remboursements partiels possibles, jamais plus que le montant payé.
 *   Un remboursement TOTAL annule la commission ; un remboursement partiel ne la modifie pas
 *   (une correction éventuelle passe par un ajustement du journal).
 *
 * Toute écriture se fait sous verrou du paiement (SELECT … FOR UPDATE).
 */
final class PaymentService
{
    /** Statuts de commande où l'argent a pu être remis au restaurant. */
    public const COLLECTABLE_STATUSES = ['ready', 'out_for_delivery', 'completed'];

    private BaseConnection $db;

    public function __construct(
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly PaymentRefundModel $refunds = new PaymentRefundModel(),
        private readonly CommissionService $commissions = new CommissionService(),
        private readonly AuditLogger $audit = new AuditLogger(),
        ?BaseConnection $db = null,
    ) {
        $this->db = $db ?? db_connect();
    }

    /**
     * Le restaurant confirme l'encaissement en espèces.
     */
    public function recordCash(int $restaurantId, int $userId, int $orderId): void
    {
        $order = $this->orders->findForRestaurant($restaurantId, $orderId) ?? throw ApiException::notFound('Commande introuvable.');

        if (! in_array($order['status'], self::COLLECTABLE_STATUSES, true)) {
            throw ApiException::conflict(
                'L\'encaissement ne peut être enregistré qu\'une fois la commande prête, en livraison ou terminée.',
                ['current_status' => $order['status']],
                'PAYMENT_NOT_COLLECTABLE',
            );
        }

        $this->transaction(function () use ($order, $orderId, $userId): void {
            $payment = $this->lockedPayment($orderId);

            if ($payment === null || $payment['provider'] !== 'cash') {
                throw ApiException::conflict('Cette commande n\'est pas payable en espèces.', [], 'PAYMENT_METHOD_MISMATCH');
            }

            if ($payment['status'] === 'successful') {
                throw ApiException::conflict('L\'encaissement de cette commande est déjà enregistré.', [], 'PAYMENT_ALREADY_RECORDED');
            }

            if (! in_array($payment['status'], ['initiated', 'pending'], true)) {
                throw ApiException::conflict('Ce paiement ne peut plus être encaissé.', ['payment_status' => $payment['status']], 'PAYMENT_NOT_COLLECTABLE');
            }

            $now = Time::now('UTC')->toDateTimeString();
            $this->db->table('wf_payments')->where('id', $payment['id'])->update(['status' => 'successful', 'paid_at' => $now]);
            $this->db->table('wf_orders')->where('id', $orderId)->update(['payment_status' => 'paid']);

            $this->audit->log('payment.cash_recorded', $userId, 'order', $orderId, [
                'order_number' => $order['order_number'],
                'amount'       => $payment['amount'],
                'currency'     => $payment['currency'],
            ]);
        });
    }

    /**
     * Remboursement enregistré par l'administration.
     *
     * @param array<string, mixed> $input { amount, reason }
     *
     * @return array<string, mixed> Remboursement créé et nouvel état du paiement
     */
    public function refund(int $adminId, int $orderId, array $input): array
    {
        $data  = InputValidator::validate($input, [
            'amount' => ['label' => 'montant', 'rules' => 'required|wf_money'],
            'reason' => ['label' => 'motif', 'rules' => 'required|string|min_length[3]|max_length[500]'],
        ]);
        $order  = $this->orders->find($orderId) ?? throw ApiException::notFound('Commande introuvable.');
        $amount = Money::toCents(WapiRules::numericString($data['amount']));

        if ($amount <= 0) {
            throw ApiException::validation(['amount' => 'Le montant doit être supérieur à zéro.']);
        }

        return $this->transaction(function () use ($order, $orderId, $adminId, $amount, $data): array {
            $payment = $this->lockedPayment($orderId);

            if ($payment === null || ! in_array($payment['status'], ['successful', 'refunded'], true)) {
                throw ApiException::conflict('Aucun paiement encaissé à rembourser pour cette commande.', [], 'PAYMENT_NOT_REFUNDABLE');
            }

            $paid     = Money::toCents($payment['amount']);
            $refunded = $this->refundedCents((int) $payment['id']);

            if ($refunded + $amount > $paid) {
                throw new ApiException(
                    'Le remboursement dépasse le montant encore remboursable.',
                    422,
                    ['amount' => 'Maximum remboursable : ' . Money::fromCents($paid - $refunded) . ' ' . $payment['currency'] . '.'],
                    'REFUND_EXCEEDS_PAID',
                );
            }

            $now      = Time::now('UTC')->toDateTimeString();
            $refundId = (int) $this->refunds->insert([
                'payment_id'   => (int) $payment['id'],
                'order_id'     => $orderId,
                'amount'       => Money::fromCents($amount),
                'currency'     => $payment['currency'],
                'method'       => in_array($payment['provider'], ['cash', 'mpesa', 'airtel_money', 'orange_money'], true) ? $payment['provider'] : 'other',
                'status'       => 'completed',
                'reason'       => trim((string) $data['reason']),
                'created_by'   => $adminId,
                'processed_at' => $now,
            ]);

            $full = $refunded + $amount === $paid;

            $this->db->table('wf_orders')->where('id', $orderId)->update(['payment_status' => $full ? 'refunded' : 'partially_refunded']);

            if ($full) {
                $this->db->table('wf_payments')->where('id', $payment['id'])->update(['status' => 'refunded']);
                $this->commissions->reverse($orderId, $adminId, 'Remboursement total');
            }

            $this->audit->log('payment.refunded', $adminId, 'order', $orderId, [
                'order_number' => $order['order_number'],
                'amount'       => Money::fromCents($amount),
                'currency'     => $payment['currency'],
                'full'         => $full,
            ]);

            (new NotificationDispatcher())->orderEvent('payment.refunded', $orderId, ['amount' => Money::fromCents($amount)]);

            return [
                'refund_id'       => $refundId,
                'amount'          => Money::fromCents($amount),
                'currency'        => $payment['currency'],
                'total_refunded'  => Money::fromCents($refunded + $amount),
                'payment_status'  => $full ? 'refunded' : 'partially_refunded',
            ];
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function refundsOf(int $orderId): array
    {
        return $this->refunds->where('order_id', $orderId)->orderBy('id', 'ASC')->findAll();
    }

    private function refundedCents(int $paymentId): int
    {
        $total = 0;

        foreach ($this->refunds->where('payment_id', $paymentId)->whereIn('status', ['pending', 'completed'])->findAll() as $refund) {
            $total += Money::toCents($refund['amount']);
        }

        return $total;
    }

    /**
     * @return array<string, mixed>|null Dernier paiement de la commande, verrouillé
     */
    private function lockedPayment(int $orderId): ?array
    {
        return $this->db->query('SELECT * FROM wf_payments WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE', [$orderId])->getRowArray();
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        $this->db->transBegin();

        try {
            $result = $callback();
            $this->db->transCommit();

            return $result;
        } catch (Throwable $exception) {
            $this->db->transRollback();

            throw $exception;
        }
    }
}
