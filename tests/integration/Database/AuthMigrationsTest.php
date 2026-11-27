<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Database\Migrations\AddSecurityColumnsToUsers;
use App\Database\Migrations\CreateAuthCodesTable;
use App\Database\Migrations\CreateRefreshTokensTable;
use Tests\Support\DatabaseTestCase;

/**
 * Migrations additives M2 à M4.
 *
 * @internal
 */
final class AuthMigrationsTest extends DatabaseTestCase
{
    public function testAuthTablesAndUserColumnsExist(): void
    {
        $this->assertTrue($this->db->tableExists('wf_refresh_tokens', false));
        $this->assertTrue($this->db->tableExists('wf_auth_codes', false));

        $columns = array_column($this->db->query(
            "SELECT column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'wf_users'",
        )->getResultArray(), 'c');

        foreach (['token_version', 'password_changed_at', 'failed_login_attempts', 'locked_until'] as $column) {
            $this->assertContains($column, $columns);
        }

        $this->assertCount(18, $columns, '14 colonnes d\'origine + 4 colonnes de sécurité');
    }

    public function testMigrationsAreIdempotent(): void
    {
        (new CreateRefreshTokensTable())->up();
        (new CreateAuthCodesTable())->up();
        (new AddSecurityColumnsToUsers())->up();

        $this->assertSame(18, count($this->db->query(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'wf_users'",
        )->getResultArray()));
    }

    public function testRefreshTokensAreDeletedWithTheirUser(): void
    {
        $this->db->table('wf_users')->insert(['first_name' => 'X', 'phone' => $this->uniquePhone(), 'password_hash' => 'x']);
        $userId = (int) $this->db->insertID();

        $this->db->table('wf_refresh_tokens')->insert([
            'user_id' => $userId, 'family_id' => str_repeat('a', 32), 'token_hash' => hash('sha256', 'x' . $userId),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 60),
        ]);

        $this->db->query('DELETE FROM wf_users WHERE id = ?', [$userId]);

        $this->dontSeeInDatabase('wf_refresh_tokens', ['user_id' => $userId]);
    }
}
