<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use CodeIgniter\Test\StreamFilterTrait;
use Tests\Support\DatabaseTestCase;

/**
 * @internal
 */
final class PurgeExpiredAuthDataTest extends DatabaseTestCase
{
    use StreamFilterTrait;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emptyTables(['wf_refresh_tokens', 'wf_auth_codes']);
        $this->db->table('wf_users')->insert(['first_name' => 'P', 'phone' => $this->uniquePhone(), 'password_hash' => 'x']);
        $this->userId = (int) $this->db->insertID();

        $old    = gmdate('Y-m-d H:i:s', time() - 40 * 86400);
        $recent = gmdate('Y-m-d H:i:s', time() - 2 * 86400);
        $future = gmdate('Y-m-d H:i:s', time() + 86400);

        foreach ([['old-expired', $old, null], ['old-revoked', $future, $old], ['recent-revoked', $future, $recent], ['active', $future, null]] as [$name, $expires, $revoked]) {
            $this->db->table('wf_refresh_tokens')->insert([
                'user_id' => $this->userId, 'family_id' => str_pad($name, 32, '0'), 'token_hash' => hash('sha256', $name . $this->userId),
                'expires_at' => $expires, 'revoked_at' => $revoked,
            ]);
        }

        foreach ([$old, $future] as $expires) {
            $this->db->table('wf_auth_codes')->insert([
                'user_id' => $this->userId, 'channel' => 'sms', 'destination' => '+243800000000', 'purpose' => 'phone_verification',
                'code_hash' => str_repeat('0', 64), 'expires_at' => $expires,
            ]);
        }
    }

    public function testDryRunDeletesNothing(): void
    {
        command('auth:purge-expired --dry-run');

        $this->assertStringContainsString('Refresh tokens concernés : 2', $this->getStreamFilterBuffer());
        $this->assertSame(4, $this->db->table('wf_refresh_tokens')->countAllResults());
        $this->assertSame(2, $this->db->table('wf_auth_codes')->countAllResults());
    }

    public function testPurgeKeepsActiveAndRecentRows(): void
    {
        command('auth:purge-expired');

        $remaining = array_column($this->db->table('wf_refresh_tokens')->get()->getResultArray(), 'family_id');

        $this->assertEqualsCanonicalizing([str_pad('recent-revoked', 32, '0'), str_pad('active', 32, '0')], $remaining);
        $this->assertSame(1, $this->db->table('wf_auth_codes')->countAllResults());
    }
}
