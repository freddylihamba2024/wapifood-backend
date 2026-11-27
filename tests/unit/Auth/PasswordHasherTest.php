<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Modules\Auth\Services\PasswordHasher;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class PasswordHasherTest extends CIUnitTestCase
{
    public function testHashIsVerifiableAndNeverPlainText(): void
    {
        $hasher = new PasswordHasher();
        $hash   = $hasher->hash('Kinshasa-2026');

        $this->assertNotSame('Kinshasa-2026', $hash);
        $this->assertTrue($hasher->verify('Kinshasa-2026', $hash));
        $this->assertFalse($hasher->verify('kinshasa-2026', $hash));
        $this->assertFalse($hasher->needsRehash($hash));
    }

    public function testLegacyBcryptHashStillVerifiesAndIsFlaggedForRehash(): void
    {
        $hasher = new PasswordHasher();
        $legacy = password_hash('Kinshasa-2026', PASSWORD_BCRYPT);

        $this->assertTrue($hasher->verify('Kinshasa-2026', $legacy));

        if (defined('PASSWORD_ARGON2ID')) {
            $this->assertTrue($hasher->needsRehash($legacy));
        }
    }

    public function testPolicyAcceptsStrongPassword(): void
    {
        $this->assertSame([], (new PasswordHasher())->policyErrors('Kinshasa-Secure-2026'));
    }

    public function testPolicyRejectsWeakPasswords(): void
    {
        $hasher = new PasswordHasher();

        $this->assertNotEmpty($hasher->policyErrors('Court1A'));
        $this->assertNotEmpty($hasher->policyErrors('toutenminuscule123'));
        $this->assertNotEmpty($hasher->policyErrors('SANSCHIFFREabcdef'));
        $this->assertNotEmpty($hasher->policyErrors(str_repeat('Aa1', 50)));
    }

    public function testPolicyRejectsPersonalData(): void
    {
        $errors = (new PasswordHasher())->policyErrors('Mbuyi-Secure-2026', 'Grace', 'Mbuyi');

        $this->assertContains('Le mot de passe ne doit pas contenir vos informations personnelles.', $errors);
    }
}
