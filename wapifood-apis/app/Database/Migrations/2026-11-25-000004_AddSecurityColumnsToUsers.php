<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * M4 — Colonnes de sécurité sur wf_users (additive, aucune colonne existante modifiée).
 *
 * - token_version         : incrémenté pour invalider immédiatement tous les jetons d'accès
 *                           (changement / réinitialisation du mot de passe, suspension, vol détecté).
 * - password_changed_at   : date du dernier changement de mot de passe.
 * - failed_login_attempts : échecs de connexion consécutifs.
 * - locked_until          : verrouillage temporaire après trop d'échecs.
 */
class AddSecurityColumnsToUsers extends Migration
{
    /** @var array<string, string> */
    private const COLUMNS = [
        'token_version'         => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_login_at',
        'password_changed_at'   => 'DATETIME NULL AFTER token_version',
        'failed_login_attempts' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER password_changed_at',
        'locked_until'          => 'DATETIME NULL AFTER failed_login_attempts',
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $column => $definition) {
            if (! $this->columnExists($column)) {
                $this->db->query("ALTER TABLE wf_users ADD COLUMN {$column} {$definition}");
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(array_keys(self::COLUMNS)) as $column) {
            if ($this->columnExists($column)) {
                $this->db->query("ALTER TABLE wf_users DROP COLUMN {$column}");
            }
        }
    }

    private function columnExists(string $column): bool
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'wf_users' AND column_name = ?",
            [$column],
        )->getRow()->n > 0;
    }
}
