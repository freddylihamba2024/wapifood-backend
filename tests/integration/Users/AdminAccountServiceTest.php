<?php

declare(strict_types=1);

namespace Tests\Integration\Users;

use App\Common\Exceptions\ApiException;
use App\Modules\Users\Services\AdminAccountService;
use CodeIgniter\Test\StreamFilterTrait;
use Tests\Support\DatabaseTestCase;

/**
 * @internal
 */
final class AdminAccountServiceTest extends DatabaseTestCase
{
    use StreamFilterTrait;

    private const PASSWORD = 'Kinshasa-Secure-2026';

    protected function setUp(): void
    {
        parent::setUp();

        // Chaque test part sans aucun compte (les rôles et paramètres sont conservés).
        $this->emptyTables(['wf_audit_logs', 'wf_user_roles', 'wf_restaurant_users', 'wf_restaurants', 'wf_users']);
    }

    public function testCreatesActiveSuperAdminWithHashedPasswordAndAuditTrail(): void
    {
        $service = new AdminAccountService();

        $userId = $service->createSuperAdmin('Grace', 'Mbuyi', 'Admin@WapiFood.CD', '081 234 5678', self::PASSWORD);

        $user = $this->db->table('wf_users')->where('id', $userId)->get()->getRowArray();

        $this->assertSame('+243812345678', $user['phone']);
        $this->assertSame('admin@wapifood.cd', $user['email']);
        $this->assertSame('active', $user['status']);
        $this->assertNotSame(self::PASSWORD, $user['password_hash']);
        $this->assertTrue(password_verify(self::PASSWORD, $user['password_hash']));

        $this->assertSame(1, $service->superAdminCount());
        $this->seeInDatabase('wf_audit_logs', ['action' => 'admin.super_admin_created', 'entity_id' => $userId]);

        $audit = $this->db->table('wf_audit_logs')->get()->getRowArray();
        $this->assertStringNotContainsString(self::PASSWORD, (string) json_encode($audit));
    }

    public function testRefusesSecondSuperAdminUnlessAdditional(): void
    {
        $service = new AdminAccountService();
        $service->createSuperAdmin('Admin', null, null, '0812345678', self::PASSWORD);

        try {
            $service->createSuperAdmin('Autre', null, null, '0822222222', self::PASSWORD);
            $this->fail('Un second super administrateur ne doit pas être créé sans --additional.');
        } catch (ApiException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame('SUPER_ADMIN_EXISTS', $exception->getErrorCode());
        }

        $service->createSuperAdmin('Autre', null, null, '0822222222', self::PASSWORD, allowAdditional: true);
        $this->assertSame(2, $service->superAdminCount());
    }

    public function testRejectsDuplicatePhoneEvenWhenSoftDeleted(): void
    {
        $service = new AdminAccountService();
        $userId  = $service->createSuperAdmin('Admin', null, null, '0812345678', self::PASSWORD);
        $this->db->table('wf_users')->where('id', $userId)->update(['deleted_at' => gmdate('Y-m-d H:i:s')]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Ce numéro de téléphone est déjà utilisé.');

        $service->createSuperAdmin('Admin', null, null, '+243 81 234 5678', self::PASSWORD, allowAdditional: true);
    }

    public function testRejectsWeakPasswordAndInvalidData(): void
    {
        try {
            (new AdminAccountService())->createSuperAdmin('', null, 'pas-un-email', '123', 'faible');
            $this->fail('Des données invalides doivent être rejetées.');
        } catch (ApiException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertEqualsCanonicalizing(['first_name', 'email', 'phone', 'password'], array_keys($exception->getErrors()));
        }

        $this->assertSame(0, $this->db->table('wf_users')->countAllResults());
    }

    public function testCommandCreatesAdminNonInteractively(): void
    {
        putenv('WAPIFOOD_ADMIN_PASSWORD=' . self::PASSWORD);
        $_ENV['WAPIFOOD_ADMIN_PASSWORD'] = $_SERVER['WAPIFOOD_ADMIN_PASSWORD'] = self::PASSWORD;

        try {
            command('wapifood:create-admin --first-name Grace --last-name Mbuyi --email ops@wapifood.cd --phone 0899999999');
        } finally {
            putenv('WAPIFOOD_ADMIN_PASSWORD');
            unset($_ENV['WAPIFOOD_ADMIN_PASSWORD'], $_SERVER['WAPIFOOD_ADMIN_PASSWORD']);
        }

        $this->assertStringContainsString('Super administrateur créé', $this->getStreamFilterBuffer());
        $this->assertStringNotContainsString(self::PASSWORD, $this->getStreamFilterBuffer());
        $this->seeInDatabase('wf_users', ['phone' => '+243899999999', 'email' => 'ops@wapifood.cd', 'status' => 'active']);
        $this->assertSame(1, (new AdminAccountService())->superAdminCount());
    }
}
