<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * M9 — Remboursements (additif).
 *
 * wf_orders.payment_status prévoit `refunded` et `partially_refunded`, mais aucune table ne
 * conservait le détail. Un paiement peut faire l'objet de plusieurs remboursements partiels ;
 * leur somme ne dépasse jamais le montant payé (contrôlé par le service, sous verrou).
 *
 * Statut : `completed` pour un remboursement effectué hors ligne (espèces, enregistré par l'administration) ;
 * `pending` / `failed` serviront aux remboursements Mobile Money traités par un agrégateur.
 */
class CreatePaymentRefunds extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE IF NOT EXISTS wf_payment_refunds (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              payment_id BIGINT UNSIGNED NOT NULL,
              order_id BIGINT UNSIGNED NOT NULL,
              amount DECIMAL(12,2) NOT NULL,
              currency CHAR(3) NOT NULL,
              method ENUM('cash','mpesa','airtel_money','orange_money','other') NOT NULL,
              status ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
              provider_refund_id VARCHAR(190) NULL,
              reason VARCHAR(500) NOT NULL,
              created_by BIGINT UNSIGNED NULL,
              processed_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_wf_refunds_order (order_id),
              KEY idx_wf_refunds_payment (payment_id, status),
              CONSTRAINT fk_wf_refunds_payment FOREIGN KEY (payment_id) REFERENCES wf_payments(id),
              CONSTRAINT fk_wf_refunds_order FOREIGN KEY (order_id) REFERENCES wf_orders(id),
              CONSTRAINT fk_wf_refunds_created_by FOREIGN KEY (created_by) REFERENCES wf_users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS wf_payment_refunds');
    }
}
