<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * M8 — Journal financier des restaurants (additif).
 *
 * Au lancement (décision D4), le restaurant encaisse lui-même les espèces : c'est LUI qui doit
 * la commission à WapiFood. wf_restaurant_balances, pensé pour des fonds détenus par WapiFood,
 * ne représente pas cette dette.
 *
 * - wf_restaurant_ledger_entries : écritures immuables (jamais modifiées ni supprimées).
 *   Montant signé, du point de vue de la dette du restaurant envers WapiFood :
 *     commission_accrued (+)    commission acquise sur une commande terminée
 *     commission_reversed (−)   commission annulée (remboursement total)
 *     commission_settlement (−) le restaurant a réglé sa commission à WapiFood
 *     payout                    réservé aux reversements (Mobile Money, plus tard)
 *     adjustment (±)            correction manuelle de l'administration, motivée
 *   L'index unique (commission_id, entry_type) interdit d'acquérir ou d'annuler deux fois la même commission.
 * - wf_restaurant_balances.commission_due : dette courante, égale à la somme du journal
 *   (contrôlée par `php spark finance:check-consistency`).
 */
class CreateRestaurantLedger extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE IF NOT EXISTS wf_restaurant_ledger_entries (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              restaurant_id BIGINT UNSIGNED NOT NULL,
              currency CHAR(3) NOT NULL,
              entry_type ENUM('commission_accrued','commission_reversed','commission_settlement','payout','adjustment') NOT NULL,
              amount DECIMAL(14,2) NOT NULL COMMENT 'Signé : + augmente la dette du restaurant envers WapiFood',
              balance_after DECIMAL(14,2) NOT NULL COMMENT 'commission_due après cette écriture',
              order_id BIGINT UNSIGNED NULL,
              commission_id BIGINT UNSIGNED NULL,
              payout_id BIGINT UNSIGNED NULL,
              reference VARCHAR(120) NULL,
              note VARCHAR(500) NULL,
              created_by BIGINT UNSIGNED NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_wf_ledger_commission_entry (commission_id, entry_type),
              KEY idx_wf_ledger_restaurant (restaurant_id, currency, id),
              KEY idx_wf_ledger_order (order_id),
              CONSTRAINT fk_wf_ledger_restaurant FOREIGN KEY (restaurant_id) REFERENCES wf_restaurants(id),
              CONSTRAINT fk_wf_ledger_order FOREIGN KEY (order_id) REFERENCES wf_orders(id),
              CONSTRAINT fk_wf_ledger_commission FOREIGN KEY (commission_id) REFERENCES wf_commissions(id),
              CONSTRAINT fk_wf_ledger_payout FOREIGN KEY (payout_id) REFERENCES wf_restaurant_payouts(id),
              CONSTRAINT fk_wf_ledger_created_by FOREIGN KEY (created_by) REFERENCES wf_users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        if (! $this->columnExists('wf_restaurant_balances', 'commission_due')) {
            $this->db->query('ALTER TABLE wf_restaurant_balances ADD COLUMN commission_due DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER currency');
        }
    }

    public function down(): void
    {
        if ($this->columnExists('wf_restaurant_balances', 'commission_due')) {
            $this->db->query('ALTER TABLE wf_restaurant_balances DROP COLUMN commission_due');
        }

        $this->db->query('DROP TABLE IF EXISTS wf_restaurant_ledger_entries');
    }

    private function columnExists(string $table, string $column): bool
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column],
        )->getRow()->n > 0;
    }
}
