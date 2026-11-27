<?php

declare(strict_types=1);

namespace App\Modules\Commissions\Services;

use App\Common\Support\Money;
use App\Modules\Commissions\Models\CommissionModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;

/**
 * Cycle de vie de la commission d'une commande (une seule par commande, index unique).
 *
 *   création de la commande  → pending   : taux et montants FIGÉS (une baisse ou hausse du taux
 *                                           du restaurant ne change pas les commandes passées)
 *   commande terminée         → earned    : écriture « commission_accrued » (+) au journal
 *   refus / annulation        → reversed  : aucune écriture (rien n'avait été dû)
 *   remboursement total       → reversed  : écriture « commission_reversed » (−) si elle était acquise
 *
 * Code promo PLATEFORME (Ph7-1) : le client a payé au restaurant le total diminué de la remise,
 * financée par WapiFood. À la commande terminée, une écriture « platform_discount » (−) crédite le
 * restaurant de cette remise (sa dette baisse, voire devient un avoir) ; un remboursement total
 * l'annule par « platform_discount_reversed » (+).
 *
 * Toutes les méthodes s'exécutent dans la transaction de l'appelant.
 */
final class CommissionService
{
    private BaseConnection $db;

    public function __construct(
        private readonly CommissionModel $commissions = new CommissionModel(),
        private readonly CommissionCalculator $calculator = new CommissionCalculator(),
        private readonly LedgerService $ledger = new LedgerService(),
        ?BaseConnection $db = null,
    ) {
        $this->db = $db ?? db_connect();
    }

    /**
     * Fige la commission au moment de la commande.
     *
     * @param array<string, mixed> $order Ligne wf_orders (subtotal, discount_amount, currency, restaurant_id)
     */
    public function freeze(int $orderId, array $order, string $rate): int
    {
        $amounts = $this->calculator->calculate((string) $order['subtotal'], (string) $order['discount_amount'], $rate);

        return (int) $this->commissions->insert($amounts + [
            'order_id'      => $orderId,
            'restaurant_id' => (int) $order['restaurant_id'],
            'currency'      => $order['currency'],
            'status'        => 'pending',
        ]);
    }

    /**
     * Commande terminée : la commission devient due par le restaurant.
     */
    public function earn(int $orderId, ?int $userId): void
    {
        $commission = $this->locked($orderId);

        if ($commission === null || $commission['status'] !== 'pending') {
            return;
        }

        $this->commissions->update((int) $commission['id'], ['status' => 'earned', 'earned_at' => Time::now('UTC')->toDateTimeString()]);

        $this->ledger->post((int) $commission['restaurant_id'], $commission['currency'], 'commission_accrued', Money::toCents($commission['commission_amount']), [
            'order_id'      => $orderId,
            'commission_id' => (int) $commission['id'],
            'created_by'    => $userId,
        ]);

        $platformDiscount = $this->platformDiscountCents($orderId);

        if ($platformDiscount > 0) {
            $this->ledger->post((int) $commission['restaurant_id'], $commission['currency'], 'platform_discount', -$platformDiscount, [
                'order_id'      => $orderId,
                'commission_id' => (int) $commission['id'],
                'created_by'    => $userId,
                'note'          => 'Remise d\'un code promo WapiFood',
            ]);
        }
    }

    /**
     * Annule la commission (commande refusée ou annulée, remboursement total).
     * Si elle était déjà acquise, une écriture inverse rétablit le solde.
     */
    public function reverse(int $orderId, ?int $userId, ?string $note = null): void
    {
        $commission = $this->locked($orderId);

        if ($commission === null || $commission['status'] === 'reversed') {
            return;
        }

        $this->commissions->update((int) $commission['id'], ['status' => 'reversed']);

        if ($commission['status'] === 'earned') {
            $this->ledger->post((int) $commission['restaurant_id'], $commission['currency'], 'commission_reversed', -Money::toCents($commission['commission_amount']), [
                'order_id'      => $orderId,
                'commission_id' => (int) $commission['id'],
                'created_by'    => $userId,
                'note'          => $note,
            ]);

            $credited = $this->db->table('wf_restaurant_ledger_entries')
                ->where('commission_id', (int) $commission['id'])
                ->where('entry_type', 'platform_discount')
                ->get()
                ->getRowArray();

            if ($credited !== null) {
                $this->ledger->post((int) $commission['restaurant_id'], $commission['currency'], 'platform_discount_reversed', -Money::toCents((string) $credited['amount']), [
                    'order_id'      => $orderId,
                    'commission_id' => (int) $commission['id'],
                    'created_by'    => $userId,
                    'note'          => $note,
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForOrder(int $orderId): ?array
    {
        return $this->commissions->where('order_id', $orderId)->first();
    }

    /**
     * Remise d'un code promo plateforme appliquée à la commande (0 si aucun).
     */
    private function platformDiscountCents(int $orderId): int
    {
        $row = $this->db->table('wf_promotion_usages u')
            ->select('u.discount_amount')
            ->join('wf_promotions p', 'p.id = u.promotion_id')
            ->where('u.order_id', $orderId)
            ->where('p.restaurant_id', null)
            ->get()
            ->getRowArray();

        return $row === null ? 0 : Money::toCents((string) $row['discount_amount']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function locked(int $orderId): ?array
    {
        return $this->db->query('SELECT * FROM wf_commissions WHERE order_id = ? FOR UPDATE', [$orderId])->getRowArray();
    }
}
