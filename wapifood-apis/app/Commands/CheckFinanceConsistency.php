<?php

declare(strict_types=1);

namespace App\Commands;

use App\Modules\Commissions\Services\FinanceService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Vérifie que chaque solde (wf_restaurant_balances.commission_due) égale la somme de son journal
 * (wf_restaurant_ledger_entries) et le dernier « balance_after ».
 *
 *   php spark finance:check-consistency
 *
 * Code de sortie 0 si tout est cohérent, 1 sinon (utilisable par une supervision).
 * Lecture seule : aucune correction automatique. Un écart se corrige par un ajustement motivé.
 * À planifier une fois par jour (cron).
 */
class CheckFinanceConsistency extends BaseCommand
{
    protected $group       = 'WapiFood';
    protected $name        = 'finance:check-consistency';
    protected $description = 'Compare les soldes des restaurants à leur journal financier.';
    protected $usage       = 'finance:check-consistency';

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params)
    {
        $problems = (new FinanceService())->inconsistencies();

        if ($problems === []) {
            CLI::write('Soldes et journal cohérents.', 'green');

            return EXIT_SUCCESS;
        }

        CLI::error(count($problems) . ' incohérence(s) détectée(s) :');
        CLI::table(array_map(static fn (array $p): array => [
            $p['restaurant_id'], $p['currency'], $p['balance'], $p['ledger_sum'], $p['last_balance_after'] ?? '—',
        ], $problems), ['Restaurant', 'Devise', 'Solde', 'Somme du journal', 'Dernier solde inscrit']);

        log_message('critical', 'finance:check-consistency : {n} incohérence(s) entre soldes et journal.', ['n' => count($problems)]);

        return EXIT_ERROR;
    }
}
