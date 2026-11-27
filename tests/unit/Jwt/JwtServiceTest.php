<?php

declare(strict_types=1);

namespace Tests\Unit\Jwt;

use App\Common\Exceptions\ApiException;
use App\Libraries\Jwt\JwtService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Jwt as JwtConfig;
use Firebase\JWT\JWT;
use RuntimeException;

/**
 * @internal
 */
final class JwtServiceTest extends CIUnitTestCase
{
    private function config(int $ttl = 900, ?string $secret = null): JwtConfig
    {
        $config            = new JwtConfig();
        $config->secret    = $secret ?? str_repeat('k', 64);
        $config->accessTtl = $ttl;
        $config->leeway    = 0;

        return $config;
    }

    private function assertRejected(JwtService $service, string $token, string $code): void
    {
        try {
            $service->verifyAccessToken($token);
            $this->fail('Le jeton aurait dû être rejeté.');
        } catch (ApiException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
            $this->assertSame($code, $exception->getErrorCode());
        }
    }

    public function testIssueAndVerify(): void
    {
        $service = new JwtService($this->config());
        $issued  = $service->issueAccessToken(42, str_repeat('a', 32), 3, ['customer']);

        $this->assertSame(900, $issued['expires_in']);
        $this->assertSame(
            ['sub' => 42, 'sid' => str_repeat('a', 32), 'ver' => 3, 'roles' => ['customer']],
            $service->verifyAccessToken($issued['token']),
        );
    }

    public function testExpiredToken(): void
    {
        $service = new JwtService($this->config(ttl: -10));

        $this->assertRejected($service, $service->issueAccessToken(1, 'sid', 0, [])['token'], 'TOKEN_EXPIRED');
    }

    public function testTokenSignedWithAnotherSecretIsRejected(): void
    {
        $token = (new JwtService($this->config(secret: str_repeat('x', 64))))->issueAccessToken(1, 'sid', 0, [])['token'];

        $this->assertRejected(new JwtService($this->config()), $token, 'TOKEN_INVALID');
    }

    public function testUnsignedAndForeignTokensAreRejected(): void
    {
        $service = new JwtService($this->config());
        $secret  = str_repeat('k', 64);

        $this->assertRejected($service, 'eyJhbGciOiJub25lIiwidHlwIjoiSldUIn0.eyJzdWIiOiIxIn0.', 'TOKEN_INVALID');
        $this->assertRejected($service, 'pas-un-jwt', 'TOKEN_INVALID');

        // Bonne signature, mais pas un jeton d'accès WapiFood (refresh, autre audience…).
        $base = ['iss' => 'wapifood', 'aud' => 'wapifood-api', 'sub' => '1', 'sid' => 's', 'ver' => 0, 'exp' => time() + 60];
        $this->assertRejected($service, JWT::encode($base + ['typ' => 'refresh'], $secret, 'HS256'), 'TOKEN_INVALID');
        $this->assertRejected($service, JWT::encode(['aud' => 'autre'] + $base + ['typ' => 'access'], $secret, 'HS256'), 'TOKEN_INVALID');
    }

    public function testShortSecretIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        new JwtService($this->config(secret: 'trop-court'));
    }
}
