<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Database\Migrations\CreateNotificationQueue;
use Tests\Support\DatabaseTestCase;

/**
 * Migration additive M11 (file d'envoi des notifications et historique des tentatives).
 *
 * @internal
 */
final class NotificationMigrationTest extends DatabaseTestCase
{
    public function testQueueColumnsAndAttemptsTableExistAndMigrationIsIdempotent(): void
    {
        (new CreateNotificationQueue())->up();

        foreach (['recipient', 'attempts', 'max_attempts', 'next_attempt_at', 'locked_until', 'last_error'] as $column) {
            $this->assertContains($column, $this->db->getFieldNames('wf_notifications'));
        }

        $this->assertTrue($this->db->tableExists('wf_notification_attempts'));

        $index = $this->db->query(
            "SELECT column_name AS c FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'wf_notifications' AND index_name = 'idx_wf_notifications_queue' ORDER BY seq_in_index",
        )->getResultArray();
        $this->assertSame(['status', 'next_attempt_at'], array_column($index, 'c'));

        $unique = $this->db->query(
            "SELECT column_name AS c FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'wf_notification_attempts' AND index_name = 'uq_wf_notification_attempt' AND non_unique = 0 ORDER BY seq_in_index",
        )->getResultArray();
        $this->assertSame(['notification_id', 'attempt_number'], array_column($unique, 'c'));
    }
}
