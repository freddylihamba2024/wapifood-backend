<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * M7 — Protection contre la double soumission d'une commande (additive).
 *
 * Sur un réseau mobile instable, l'application peut renvoyer la même commande (double appui,
 * nouvel essai après expiration du délai). Elle envoie alors la même clé d'idempotence, et
 * l'index unique garantit qu'une seule commande est créée, même avec deux requêtes simultanées.
 *
 * NULL autorisé (clé facultative) : MySQL accepte plusieurs NULL dans un index unique.
 */
class AddIdempotencyKeyToOrders extends Migration
{
    private const TABLE  = 'wf_orders';
    private const COLUMN = 'idempotency_key';
    private const INDEX  = 'uq_wf_orders_idempotency';

    public function up(): void
    {
        if (! $this->columnExists()) {
            $this->db->query('ALTER TABLE ' . self::TABLE . ' ADD COLUMN ' . self::COLUMN . ' VARCHAR(64) NULL AFTER order_number');
        }

        if (! $this->indexExists()) {
            $this->db->query('ALTER TABLE ' . self::TABLE . ' ADD UNIQUE KEY ' . self::INDEX . ' (customer_id, ' . self::COLUMN . ')');
        }
    }

    public function down(): void
    {
        if ($this->indexExists()) {
            $this->db->query('ALTER TABLE ' . self::TABLE . ' DROP INDEX ' . self::INDEX);
        }

        if ($this->columnExists()) {
            $this->db->query('ALTER TABLE ' . self::TABLE . ' DROP COLUMN ' . self::COLUMN);
        }
    }

    private function columnExists(): bool
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [self::TABLE, self::COLUMN],
        )->getRow()->n > 0;
    }

    private function indexExists(): bool
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) AS n FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [self::TABLE, self::INDEX],
        )->getRow()->n > 0;
    }
}
