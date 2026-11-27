<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * M2 — Refresh tokens (additive).
 *
 * - Seul le hash SHA-256 du jeton est stocké (le jeton brut n'existe que chez le client).
 * - family_id regroupe les jetons successifs d'une même session : la réutilisation d'un
 *   jeton déjà renouvelé révoque toute la famille (détection de vol).
 */
class CreateRefreshTokensTable extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE IF NOT EXISTS wf_refresh_tokens (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              user_id BIGINT UNSIGNED NOT NULL,
              family_id CHAR(32) NOT NULL COMMENT 'Identifiant de session (chaîne de rotation)',
              token_hash CHAR(64) NOT NULL COMMENT 'SHA-256 du jeton, jamais le jeton brut',
              replaced_by_id BIGINT UNSIGNED NULL,
              expires_at DATETIME NOT NULL,
              revoked_at DATETIME NULL,
              revoked_reason VARCHAR(40) NULL,
              ip_address VARCHAR(45) NULL,
              user_agent VARCHAR(500) NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_wf_refresh_token_hash (token_hash),
              KEY idx_wf_refresh_tokens_user (user_id, revoked_at),
              KEY idx_wf_refresh_tokens_family (family_id, revoked_at),
              KEY idx_wf_refresh_tokens_expires (expires_at),
              CONSTRAINT fk_wf_refresh_tokens_user FOREIGN KEY (user_id) REFERENCES wf_users(id) ON DELETE CASCADE,
              CONSTRAINT fk_wf_refresh_tokens_replaced FOREIGN KEY (replaced_by_id) REFERENCES wf_refresh_tokens(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(): void
    {
        // Ne contient que des jetons de session (aucune donnée métier).
        $this->db->query('DROP TABLE IF EXISTS wf_refresh_tokens');
    }
}
