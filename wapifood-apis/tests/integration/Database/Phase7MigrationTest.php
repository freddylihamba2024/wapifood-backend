<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Database\Migrations\AddDeliveryReviewAndPromotionColumns;
use Tests\Support\DatabaseTestCase;

/**
 * Migration additive M10 (livraison, modération des avis, écritures de promotion plateforme).
 *
 * @internal
 */
final class Phase7MigrationTest extends DatabaseTestCase
{
    public function testColumnsAndLedgerTypesExistAndMigrationIsIdempotent(): void
    {
        (new AddDeliveryReviewAndPromotionColumns())->up();

        $this->assertContains('courier_name', $this->db->getFieldNames('wf_deliveries'));

        foreach (['moderated_at', 'moderated_by', 'moderation_reason'] as $column) {
            $this->assertContains($column, $this->db->getFieldNames('wf_reviews'));
        }

        $type = $this->db->query(
            "SELECT column_type AS t FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'wf_restaurant_ledger_entries' AND column_name = 'entry_type'",
        )->getRow()->t;
        $this->assertStringContainsString("'platform_discount','platform_discount_reversed'", $type);

        $fk = $this->db->query(
            "SELECT referenced_table_name AS t, delete_rule AS r FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND constraint_name = 'fk_wf_reviews_moderated_by'",
        )->getRowArray();
        $this->assertSame(['t' => 'wf_users', 'r' => 'SET NULL'], $fk);
    }
}
