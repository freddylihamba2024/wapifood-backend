<?php

declare(strict_types=1);

namespace App\Modules\Commissions\Services;

use App\Common\Exceptions\ApiException;
use App\Common\Support\Money;
use App\Modules\Commissions\Models\RestaurantLedgerEntryModel;
use CodeIgniter\Database\BaseConnection;
use Config\WapiFood;
use LogicException;

/**
 * Écritures du journal financier et solde dû par restaurant et par devise.
 *
 * Chaque écriture met à jour wf_restaurant_balances.commission_due dans la même transaction,
 * sous verrou de la ligne de solde (SELECT … FOR UPDATE) : deux écritures simultanées
 * ne peuvent pas perdre de mise à jour. Le journal n'est jamais modifié : une erreur se corrige
 * par une écriture inverse (ajustement).
 *
 * À appeler à l'intérieur d'une transaction ouverte par l'appelant.
 */
final class LedgerService
{
    public const TYPES = ['commission_accrued', 'commission_reversed', 'commission_settlement', 'payout', 'adjustment', 'platform_discount', 'platform_discount_reversed'];

    private BaseConnection $db;

    public function __construct(
        private readonly RestaurantLedgerEntryModel $entries = new RestaurantLedgerEntryModel(),
        ?BaseConnection $db = null,
    ) {
        $this->db = $db ?? db_connect();
    }

    /**
     * @param array{order_id?: int|null, commission_id?: int|null, payout_id?: int|null, reference?: string|null, note?: string|null, created_by?: int|null} $links
     *
     * @return array<string, mixed> Écriture créée
     */
    public function post(int $restaurantId, string $currency, string $type, int $amountCents, array $links = []): array
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new LogicException("Type d'écriture inconnu : {$type}");
        }

        if ($this->db->transDepth === 0) {
            throw new LogicException('LedgerService::post doit être appelé dans une transaction.');
        }

        $due    = $this->lockedDue($restaurantId, $currency);
        $newDue = $due + $amountCents;

        $id = (int) $this->entries->insert([
            'restaurant_id' => $restaurantId,
            'currency'      => $currency,
            'entry_type'    => $type,
            'amount'        => Money::fromCents($amountCents),
            'balance_after' => Money::fromCents($newDue),
            'order_id'      => $links['order_id'] ?? null,
            'commission_id' => $links['commission_id'] ?? null,
            'payout_id'     => $links['payout_id'] ?? null,
            'reference'     => $links['reference'] ?? null,
            'note'          => $links['note'] ?? null,
            'created_by'    => $links['created_by'] ?? null,
        ]);

        $this->db->table('wf_restaurant_balances')
            ->where('restaurant_id', $restaurantId)
            ->where('currency', $currency)
            ->update(['commission_due' => Money::fromCents($newDue)]);

        return $this->entries->find($id);
    }

    /**
     * Solde dû actuel, verrouillé jusqu'à la fin de la transaction (ligne créée au besoin).
     */
    public function lockedDue(int $restaurantId, string $currency): int
    {
        if (! in_array($currency, config(WapiFood::class)->supportedCurrencies, true)) {
            throw ApiException::validation(['currency' => 'Devise non prise en charge.']);
        }

        $this->db->table('wf_restaurant_balances')->ignore(true)->insert([
            'restaurant_id' => $restaurantId,
            'currency'      => $currency,
        ]);

        $row = $this->db->query(
            'SELECT commission_due FROM wf_restaurant_balances WHERE restaurant_id = ? AND currency = ? FOR UPDATE',
            [$restaurantId, $currency],
        )->getRowArray();

        return Money::toCents((string) $row['commission_due']);
    }
}
