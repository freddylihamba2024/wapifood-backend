<?php

declare(strict_types=1);

namespace App\Modules\Commissions\Services;

use App\Common\Exceptions\ApiException;
use App\Common\Http\Pagination;
use App\Common\Support\Money;
use App\Common\Validation\InputValidator;
use App\Common\Validation\WapiRules;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Restaurants\Repositories\RestaurantRepository;
use App\Modules\Users\Presenters\UserPresenter;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Consultation des finances (restaurant, administration) et écritures manuelles de l'administration.
 *
 * Les montants sont toujours présentés PAR DEVISE : aucun total USD + CDF sans conversion.
 */
final class FinanceService
{
    private BaseConnection $db;

    public function __construct(
        private readonly LedgerService $ledger = new LedgerService(),
        private readonly RestaurantRepository $restaurants = new RestaurantRepository(),
        private readonly AuditLogger $audit = new AuditLogger(),
        ?BaseConnection $db = null,
    ) {
        $this->db = $db ?? db_connect();
    }

    /**
     * Soldes d'un restaurant, une ligne par devise utilisée, avec les commissions en attente
     * (commandes en cours, pas encore dues).
     *
     * @return list<array<string, mixed>>
     */
    public function balances(int $restaurantId): array
    {
        $due = array_column($this->db->table('wf_restaurant_balances')->where('restaurant_id', $restaurantId)->get()->getResultArray(), 'commission_due', 'currency');

        $pending = [];

        foreach ($this->db->table('wf_commissions')->select('currency, commission_amount')->where('restaurant_id', $restaurantId)->where('status', 'pending')->get()->getResultArray() as $row) {
            $pending[$row['currency']] = ($pending[$row['currency']] ?? 0) + Money::toCents($row['commission_amount']);
        }

        $currencies = array_unique([...array_keys($due), ...array_keys($pending)]);
        sort($currencies);

        return array_map(static fn (string $currency): array => [
            'currency'           => $currency,
            'commission_due'     => Money::normalize((string) ($due[$currency] ?? '0')),
            'commission_pending' => Money::fromCents($pending[$currency] ?? 0),
        ], $currencies);
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>}
     */
    public function ledger(int $restaurantId, ?string $currency, Pagination $pagination): array
    {
        $builder = $this->db->table('wf_restaurant_ledger_entries e')
            ->select('e.*, o.order_number')
            ->join('wf_orders o', 'o.id = e.order_id', 'left')
            ->where('e.restaurant_id', $restaurantId)
            ->orderBy('e.id', 'DESC');

        if ($currency !== null) {
            $builder->where('e.currency', $currency);
        }

        [$rows, $meta] = $pagination->run($builder);

        return [array_map(self::entry(...), $rows), $meta];
    }

    /**
     * Administration : restaurants ayant un solde, par devise (les plus endettés d'abord).
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>}
     */
    public function adminBalances(?string $currency, bool $onlyDue, Pagination $pagination): array
    {
        $builder = $this->db->table('wf_restaurant_balances b')
            ->select('b.restaurant_id, b.currency, b.commission_due, b.updated_at, r.name AS restaurant_name, r.status AS restaurant_status')
            ->join('wf_restaurants r', 'r.id = b.restaurant_id')
            ->orderBy('b.commission_due', 'DESC')
            ->orderBy('b.restaurant_id', 'ASC');

        if ($currency !== null) {
            $builder->where('b.currency', $currency);
        }

        if ($onlyDue) {
            $builder->where('b.commission_due >', 0);
        }

        [$rows, $meta] = $pagination->run($builder);

        return [array_map(static fn (array $r): array => [
            'restaurant'     => ['id' => (int) $r['restaurant_id'], 'name' => $r['restaurant_name'], 'status' => $r['restaurant_status']],
            'currency'       => $r['currency'],
            'commission_due' => $r['commission_due'],
            'updated_at'     => UserPresenter::isoDate($r['updated_at']),
        ], $rows), $meta];
    }

    /**
     * Le restaurant a réglé (tout ou partie de) sa commission à WapiFood.
     *
     * @param array<string, mixed> $input { amount, currency, reference?, note? }
     *
     * @return array<string, mixed>
     */
    public function recordSettlement(int $adminId, int $restaurantId, array $input): array
    {
        $data   = InputValidator::validate($input, [
            'amount'    => ['label' => 'montant', 'rules' => 'required|wf_money'],
            'currency'  => ['label' => 'devise', 'rules' => 'required|in_list[USD,CDF]'],
            'reference' => ['label' => 'référence', 'rules' => 'permit_empty|string|max_length[120]'],
            'note'      => ['label' => 'note', 'rules' => 'permit_empty|string|max_length[500]'],
        ]);
        $amount = Money::toCents(WapiRules::numericString($data['amount']));
        $this->assertRestaurant($restaurantId);

        if ($amount <= 0) {
            throw ApiException::validation(['amount' => 'Le montant doit être supérieur à zéro.']);
        }

        return $this->transaction(function () use ($adminId, $restaurantId, $data, $amount): array {
            $due = $this->ledger->lockedDue($restaurantId, $data['currency']);

            if ($amount > $due) {
                throw ApiException::conflict(
                    'Le règlement dépasse la commission due.',
                    ['commission_due' => Money::fromCents($due)],
                    'SETTLEMENT_EXCEEDS_DUE',
                );
            }

            $entry = $this->ledger->post($restaurantId, $data['currency'], 'commission_settlement', -$amount, [
                'reference'  => self::text($data['reference'] ?? null),
                'note'       => self::text($data['note'] ?? null),
                'created_by' => $adminId,
            ]);

            $this->audit->log('finance.settlement_recorded', $adminId, 'restaurant', $restaurantId, ['amount' => Money::fromCents($amount), 'currency' => $data['currency']]);

            return self::entry($entry);
        });
    }

    /**
     * Correction manuelle (signée), toujours motivée. Positive : augmente la dette du restaurant.
     *
     * @param array<string, mixed> $input { amount (signé), currency, note }
     *
     * @return array<string, mixed>
     */
    public function recordAdjustment(int $adminId, int $restaurantId, array $input): array
    {
        $data   = InputValidator::validate($input, [
            'amount'   => ['label' => 'montant', 'rules' => 'required|wf_money_signed'],
            'currency' => ['label' => 'devise', 'rules' => 'required|in_list[USD,CDF]'],
            'note'     => ['label' => 'motif', 'rules' => 'required|string|min_length[3]|max_length[500]'],
        ]);
        $amount = Money::toCents(WapiRules::numericString($data['amount']));
        $this->assertRestaurant($restaurantId);

        if ($amount === 0) {
            throw ApiException::validation(['amount' => 'Le montant ne peut pas être nul.']);
        }

        return $this->transaction(function () use ($adminId, $restaurantId, $data, $amount): array {
            $entry = $this->ledger->post($restaurantId, $data['currency'], 'adjustment', $amount, [
                'note'       => trim((string) $data['note']),
                'created_by' => $adminId,
            ]);

            $this->audit->log('finance.adjustment_recorded', $adminId, 'restaurant', $restaurantId, ['amount' => Money::fromCents($amount), 'currency' => $data['currency']]);

            return self::entry($entry);
        });
    }

    /**
     * Contrôle de cohérence : chaque solde doit égaler la somme de son journal,
     * et le dernier balance_after doit égaler le solde.
     *
     * @return list<array{restaurant_id: int, currency: string, balance: string, ledger_sum: string, last_balance_after: string|null}>
     */
    public function inconsistencies(): array
    {
        $balances = $this->db->table('wf_restaurant_balances')->get()->getResultArray();
        $sums     = [];

        foreach ($this->db->table('wf_restaurant_ledger_entries')->select('id, restaurant_id, currency, amount, balance_after')->orderBy('id', 'ASC')->get()->getResultArray() as $row) {
            $key        = $row['restaurant_id'] . '|' . $row['currency'];
            $sums[$key] = ['sum' => ($sums[$key]['sum'] ?? 0) + Money::toCents($row['amount']), 'last' => $row['balance_after']];
        }

        $problems = [];

        foreach ($balances as $balance) {
            $key   = $balance['restaurant_id'] . '|' . $balance['currency'];
            $due   = Money::toCents($balance['commission_due']);
            $sum   = $sums[$key]['sum'] ?? 0;
            $last  = $sums[$key]['last'] ?? null;
            unset($sums[$key]);

            if ($due !== $sum || ($last !== null && Money::toCents($last) !== $due)) {
                $problems[] = [
                    'restaurant_id'      => (int) $balance['restaurant_id'],
                    'currency'           => $balance['currency'],
                    'balance'            => Money::fromCents($due),
                    'ledger_sum'         => Money::fromCents($sum),
                    'last_balance_after' => $last,
                ];
            }
        }

        // Écritures sans ligne de solde
        foreach ($sums as $key => $value) {
            [$restaurantId, $currency] = explode('|', $key);
            $problems[]                = ['restaurant_id' => (int) $restaurantId, 'currency' => $currency, 'balance' => '0.00', 'ledger_sum' => Money::fromCents($value['sum']), 'last_balance_after' => $value['last']];
        }

        return $problems;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function entry(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'type'          => $row['entry_type'],
            'currency'      => $row['currency'],
            'amount'        => $row['amount'],
            'balance_after' => $row['balance_after'],
            'order_id'      => $row['order_id'] === null ? null : (int) $row['order_id'],
            'order_number'  => $row['order_number'] ?? null,
            'reference'     => $row['reference'],
            'note'          => $row['note'],
            'created_at'    => UserPresenter::isoDate($row['created_at']),
        ];
    }

    private function assertRestaurant(int $restaurantId): void
    {
        $this->restaurants->find($restaurantId) ?? throw ApiException::notFound('Restaurant introuvable.');
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
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
