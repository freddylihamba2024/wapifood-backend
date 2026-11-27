<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Database\Migrations\AddIdempotencyKeyToOrders;
use App\Database\Migrations\AddSoftDeleteToCustomerAddresses;
use Tests\Support\DatabaseTestCase;

/**
 * Migrations additives M6 (adresses client) et M7 (idempotence des commandes).
 *
 * @internal
 */
final class OrderMigrationsTest extends DatabaseTestCase
{
    public function testCustomerAddressColumnsExistAndMigrationIsIdempotent(): void
    {
        (new AddSoftDeleteToCustomerAddresses())->up();

        $columns = array_column($this->db->query(
            "SELECT column_name AS c, is_nullable AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'wf_customer_addresses'",
        )->getResultArray(), 'n', 'c');

        $this->assertSame('NO', $columns['updated_at']);
        $this->assertSame('YES', $columns['deleted_at']);
    }

    public function testIdempotencyKeyIsUniquePerCustomerAndMigrationIsIdempotent(): void
    {
        (new AddIdempotencyKeyToOrders())->up();

        $index = $this->db->query(
            "SELECT column_name AS c, non_unique AS u FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'wf_orders' AND index_name = 'uq_wf_orders_idempotency' ORDER BY seq_in_index",
        )->getResultArray();

        $this->assertSame(['customer_id', 'idempotency_key'], array_column($index, 'c'));
        $this->assertSame('0', (string) $index[0]['u']);
    }
}
