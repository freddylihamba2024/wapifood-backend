<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Database\Migrations\CreatePaymentRefunds;
use App\Database\Migrations\CreateRestaurantLedger;
use Tests\Support\DatabaseTestCase;

/**
 * Migrations additives M8 (journal financier) et M9 (remboursements).
 *
 * @internal
 */
final class FinanceMigrationsTest extends DatabaseTestCase
{
    public function testLedgerAndRefundTablesExistAndMigrationsAreIdempotent(): void
    {
        (new CreateRestaurantLedger())->up();
        (new CreatePaymentRefunds())->up();

        $this->assertTrue($this->db->tableExists('wf_restaurant_ledger_entries'));
        $this->assertTrue($this->db->tableExists('wf_payment_refunds'));
        $this->assertContains('commission_due', $this->db->getFieldNames('wf_restaurant_balances'));

        // Une commission ne peut être acquise (ou annulée) qu'une fois.
        $index = $this->db->query(
            "SELECT column_name AS c FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'wf_restaurant_ledger_entries' AND index_name = 'uq_wf_ledger_commission_entry' AND non_unique = 0 ORDER BY seq_in_index",
        )->getResultArray();
        $this->assertSame(['commission_id', 'entry_type'], array_column($index, 'c'));
    }
}
