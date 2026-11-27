<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * M11 — File d'envoi des notifications et reprise sur échec (Phase 8, additif).
 *
 * wf_notifications :
 *   recipient        destinataire figé à la création (téléphone, e-mail) : un changement ultérieur
 *                    du profil ne modifie pas une notification déjà en file
 *   attempts         tentatives d'envoi effectuées ; max_attempts : plafond avant « failed »
 *   next_attempt_at  prochaine tentative (backoff) ; NULL = dès que possible
 *   locked_until     verrou de traitement : deux processus `notifications:process` ne traitent
 *                    jamais la même notification
 *   last_error       dernier message d'erreur du canal
 *   index (status, next_attempt_at) : sélection de la file
 *
 * wf_notification_attempts : historique de chaque tentative (fournisseur, résultat, identifiant
 *   du message chez le fournisseur, erreur).
 */
class CreateNotificationQueue extends Migration
{
    public function up(): void
    {
        if (! $this->columnExists('wf_notifications', 'recipient')) {
            $this->db->query(<<<'SQL'
                ALTER TABLE wf_notifications
                  ADD COLUMN recipient VARCHAR(190) NULL AFTER channel,
                  ADD COLUMN attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER status,
                  ADD COLUMN max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5 AFTER attempts,
                  ADD COLUMN next_attempt_at DATETIME NULL AFTER max_attempts,
                  ADD COLUMN locked_until DATETIME NULL AFTER next_attempt_at,
                  ADD COLUMN last_error TEXT NULL AFTER locked_until,
                  ADD KEY idx_wf_notifications_queue (status, next_attempt_at)
                SQL);
        }

        $this->db->query(<<<'SQL'
            CREATE TABLE IF NOT EXISTS wf_notification_attempts (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              notification_id BIGINT UNSIGNED NOT NULL,
              attempt_number TINYINT UNSIGNED NOT NULL,
              provider VARCHAR(50) NOT NULL,
              status ENUM('sent','failed') NOT NULL,
              provider_message_id VARCHAR(190) NULL,
              error_message VARCHAR(1000) NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_wf_notification_attempt (notification_id, attempt_number),
              CONSTRAINT fk_wf_notification_attempts_notification FOREIGN KEY (notification_id) REFERENCES wf_notifications(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS wf_notification_attempts');

        if ($this->columnExists('wf_notifications', 'recipient')) {
            $this->db->query(<<<'SQL'
                ALTER TABLE wf_notifications
                  DROP KEY idx_wf_notifications_queue,
                  DROP COLUMN last_error,
                  DROP COLUMN locked_until,
                  DROP COLUMN next_attempt_at,
                  DROP COLUMN max_attempts,
                  DROP COLUMN attempts,
                  DROP COLUMN recipient
                SQL);
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
