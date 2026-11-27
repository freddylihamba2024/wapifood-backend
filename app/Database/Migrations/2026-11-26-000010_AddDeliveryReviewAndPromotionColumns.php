<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * M10 — Livraison, avis et promotions (Phase 7, additif).
 *
 * - wf_deliveries.courier_name : nom du livreur du restaurant (il n'a pas de compte WapiFood).
 * - wf_reviews : moderated_at, moderated_by (FK wf_users, SET NULL), moderation_reason.
 *   Modération a posteriori : un avis est publié dès sa création, l'administration peut le masquer.
 * - wf_restaurant_ledger_entries.entry_type : deux nouveaux types pour les promotions financées par WapiFood
 *     platform_discount (−)          WapiFood rembourse au restaurant la remise accordée au client
 *     platform_discount_reversed (+) annulation de ce crédit (remboursement total de la commande)
 */
class AddDeliveryReviewAndPromotionColumns extends Migration
{
    private const LEDGER_TYPES_V1 = "'commission_accrued','commission_reversed','commission_settlement','payout','adjustment'";
    private const LEDGER_TYPES_V2 = self::LEDGER_TYPES_V1 . ",'platform_discount','platform_discount_reversed'";

    public function up(): void
    {
        if (! $this->columnExists('wf_deliveries', 'courier_name')) {
            $this->db->query('ALTER TABLE wf_deliveries ADD COLUMN courier_name VARCHAR(150) NULL AFTER courier_id');
        }

        if (! $this->columnExists('wf_reviews', 'moderated_at')) {
            $this->db->query(<<<'SQL'
                ALTER TABLE wf_reviews
                  ADD COLUMN moderated_at DATETIME NULL AFTER status,
                  ADD COLUMN moderated_by BIGINT UNSIGNED NULL AFTER moderated_at,
                  ADD COLUMN moderation_reason VARCHAR(500) NULL AFTER moderated_by,
                  ADD CONSTRAINT fk_wf_reviews_moderated_by FOREIGN KEY (moderated_by) REFERENCES wf_users(id) ON DELETE SET NULL
                SQL);
        }

        $this->db->query('ALTER TABLE wf_restaurant_ledger_entries MODIFY entry_type ENUM(' . self::LEDGER_TYPES_V2 . ') NOT NULL');
    }

    public function down(): void
    {
        $used = (int) $this->db->query(
            "SELECT COUNT(*) AS n FROM wf_restaurant_ledger_entries WHERE entry_type IN ('platform_discount','platform_discount_reversed')",
        )->getRow()->n;

        if ($used > 0) {
            // Le journal est immuable : on ne supprime pas des écritures pour revenir en arrière.
            throw new RuntimeException("Rollback M10 refusé : {$used} écriture(s) de promotion plateforme existent dans le journal.");
        }

        $this->db->query('ALTER TABLE wf_restaurant_ledger_entries MODIFY entry_type ENUM(' . self::LEDGER_TYPES_V1 . ') NOT NULL');

        if ($this->columnExists('wf_reviews', 'moderated_at')) {
            $this->db->query(<<<'SQL'
                ALTER TABLE wf_reviews
                  DROP FOREIGN KEY fk_wf_reviews_moderated_by,
                  DROP COLUMN moderation_reason,
                  DROP COLUMN moderated_by,
                  DROP COLUMN moderated_at
                SQL);
        }

        if ($this->columnExists('wf_deliveries', 'courier_name')) {
            $this->db->query('ALTER TABLE wf_deliveries DROP COLUMN courier_name');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column],
        )->getRow()->n > 0;
    }
}
