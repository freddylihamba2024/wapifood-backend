<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Database\Migrations\AddOperationalColumnsToRestaurants;
use Tests\Support\DatabaseTestCase;

/**
 * Migration additive M5.
 *
 * @internal
 */
final class RestaurantMigrationTest extends DatabaseTestCase
{
    public function testColumnsAndForeignKeyExistAndMigrationIsIdempotent(): void
    {
        (new AddOperationalColumnsToRestaurants())->up();

        $columns = array_column($this->db->query(
            "SELECT column_name AS c, column_default AS d FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'wf_restaurants'",
        )->getResultArray(), 'd', 'c');

        foreach (['status_reason', 'approved_at', 'approved_by', 'delivery_fee', 'free_delivery_threshold', 'timezone'] as $column) {
            $this->assertArrayHasKey($column, $columns);
        }

        $this->assertSame('0.00', $columns['delivery_fee']);
        $this->assertSame('Africa/Kinshasa', $columns['timezone']);

        $this->assertSame(1, (int) $this->db->query(
            "SELECT COUNT(*) AS n FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND constraint_name = 'fk_wf_restaurants_approved_by'",
        )->getRow()->n);
    }
}
