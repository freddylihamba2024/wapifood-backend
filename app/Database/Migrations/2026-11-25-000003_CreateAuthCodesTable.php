<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * M3 — Codes à usage unique : vérification du téléphone / e-mail et réinitialisation du mot de passe (additive).
 *
 * Le code n'est jamais stocké en clair : HMAC-SHA256 avec un secret serveur (un vol de la base
 * ne permet pas de retrouver les codes par force brute hors ligne).
 */
class CreateAuthCodesTable extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE IF NOT EXISTS wf_auth_codes (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              user_id BIGINT UNSIGNED NULL,
              channel ENUM('sms','email','whatsapp') NOT NULL,
              destination VARCHAR(190) NOT NULL COMMENT 'Téléphone E.164 ou e-mail normalisé',
              purpose ENUM('phone_verification','email_verification','password_reset') NOT NULL,
              code_hash CHAR(64) NOT NULL,
              attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
              max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
              expires_at DATETIME NOT NULL,
              consumed_at DATETIME NULL,
              ip_address VARCHAR(45) NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_wf_auth_codes_lookup (destination, purpose, created_at),
              KEY idx_wf_auth_codes_user (user_id, purpose),
              KEY idx_wf_auth_codes_expires (expires_at),
              CONSTRAINT fk_wf_auth_codes_user FOREIGN KEY (user_id) REFERENCES wf_users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(): void
    {
        // Ne contient que des codes temporaires (aucune donnée métier).
        $this->db->query('DROP TABLE IF EXISTS wf_auth_codes');
    }
}
