<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * M5 — Colonnes opérationnelles sur wf_restaurants (additive, aucune colonne existante modifiée).
 *
 * - status_reason            : motif du rejet ou de la suspension (affiché au restaurateur).
 * - approved_at, approved_by : traçabilité de la validation par l'administration.
 * - delivery_fee             : forfait de livraison du restaurant (décision D6).
 * - free_delivery_threshold  : sous-total à partir duquel la livraison est offerte (NULL = jamais).
 * - timezone                 : fuseau des horaires d'ouverture (Kinshasa UTC+1, Lubumbashi UTC+2).
 */
class AddOperationalColumnsToRestaurants extends Migration
{
    /** @var array<string, string> */
    private const COLUMNS = [
        'status_reason'           => 'VARCHAR(500) NULL AFTER status',
        'approved_at'             => 'DATETIME NULL AFTER status_reason',
        'approved_by'             => 'BIGINT UNSIGNED NULL AFTER approved_at',
        'delivery_fee'            => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER delivery_managed_by',
        'free_delivery_threshold' => 'DECIMAL(12,2) NULL AFTER delivery_fee',
        'timezone'                => "VARCHAR(40) NOT NULL DEFAULT 'Africa/Kinshasa' AFTER free_delivery_threshold",
    ];

    private const FOREIGN_KEY = 'fk_wf_restaurants_approved_by';

    public function up(): void
    {
        foreach (self::COLUMNS as $column => $definition) {
            if (! $this->columnExists($column)) {
                $this->db->query("ALTER TABLE wf_restaurants ADD COLUMN {$column} {$definition}");
            }
        }

        if (! $this->foreignKeyExists()) {
            $this->db->query('ALTER TABLE wf_restaurants ADD CONSTRAINT ' . self::FOREIGN_KEY
                . ' FOREIGN KEY (approved_by) REFERENCES wf_users(id) ON DELETE SET NULL');
        }
    }

    public function down(): void
    {
        if ($this->foreignKeyExists()) {
            $this->db->query('ALTER TABLE wf_restaurants DROP FOREIGN KEY ' . self::FOREIGN_KEY);
            $this->db->query('ALTER TABLE wf_restaurants DROP INDEX ' . self::FOREIGN_KEY);
        }

        foreach (array_reverse(array_keys(self::COLUMNS)) as $column) {
            if ($this->columnExists($column)) {
                $this->db->query("ALTER TABLE wf_restaurants DROP COLUMN {$column}");
            }
        }
    }

    private function columnExists(string $column): bool
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'wf_restaurants' AND column_name = ?",
            [$column],
        )->getRow()->n > 0;
    }

    private function foreignKeyExists(): bool
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) AS n FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'wf_restaurants' AND constraint_name = ?",
            [self::FOREIGN_KEY],
        )->getRow()->n > 0;
    }
}
