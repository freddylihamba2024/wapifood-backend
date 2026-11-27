<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * M6 — Suppression logique des adresses client (additive, aucune colonne existante modifiée).
 *
 * La clé étrangère wf_orders.delivery_address_id empêche de supprimer physiquement une adresse
 * déjà utilisée par une commande : l'adresse est donc masquée (deleted_at), la commande gardant
 * son propre snapshot d'adresse.
 *
 * - updated_at : date de dernière modification.
 * - deleted_at : suppression logique.
 */
class AddSoftDeleteToCustomerAddresses extends Migration
{
    private const TABLE = 'wf_customer_addresses';

    /** @var array<string, string> */
    private const COLUMNS = [
        'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
        'deleted_at' => 'DATETIME NULL AFTER updated_at',
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $column => $definition) {
            if (! $this->columnExists($column)) {
                $this->db->query('ALTER TABLE ' . self::TABLE . " ADD COLUMN {$column} {$definition}");
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(array_keys(self::COLUMNS)) as $column) {
            if ($this->columnExists($column)) {
                $this->db->query('ALTER TABLE ' . self::TABLE . " DROP COLUMN {$column}");
            }
        }
    }

    private function columnExists(string $column): bool
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [self::TABLE, $column],
        )->getRow()->n > 0;
    }
}
