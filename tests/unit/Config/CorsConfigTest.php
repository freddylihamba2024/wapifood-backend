<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Cors;

/**
 * @internal
 */
final class CorsConfigTest extends CIUnitTestCase
{
    private string|false $previous;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = getenv('CORS_ALLOWED_ORIGINS');
    }

    protected function tearDown(): void
    {
        $this->setOrigins($this->previous === false ? null : $this->previous);
        parent::tearDown();
    }

    private function setOrigins(?string $value): void
    {
        if ($value === null) {
            putenv('CORS_ALLOWED_ORIGINS');
            unset($_ENV['CORS_ALLOWED_ORIGINS'], $_SERVER['CORS_ALLOWED_ORIGINS']);

            return;
        }

        putenv('CORS_ALLOWED_ORIGINS=' . $value);
        $_ENV['CORS_ALLOWED_ORIGINS'] = $_SERVER['CORS_ALLOWED_ORIGINS'] = $value;
    }

    public function testOriginsAreReadFromEnvironment(): void
    {
        $this->setOrigins('http://localhost:5173, https://app.wapifood.com');

        $this->assertSame(
            ['http://localhost:5173', 'https://app.wapifood.com'],
            (new Cors())->default['allowedOrigins'],
        );
    }

    public function testWildcardAndEmptyValuesAreIgnored(): void
    {
        $this->setOrigins('*,,https://app.wapifood.com');

        $this->assertSame(['https://app.wapifood.com'], (new Cors())->default['allowedOrigins']);
    }

    public function testCredentialsAreNotAllowed(): void
    {
        $this->assertFalse((new Cors())->default['supportsCredentials']);
    }
}
