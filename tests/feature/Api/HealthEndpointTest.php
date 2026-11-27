<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class HealthEndpointTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testHealthReturnsOkWithDatabaseCheck(): void
    {
        $result = $this->get('api/v1/health');

        $result->assertStatus(200);
        $result->assertHeader('Content-Type', 'application/json; charset=UTF-8');

        $body = json_decode($result->getJSON(), true);

        $this->assertTrue($body['success']);
        $this->assertSame('ok', $body['data']['status']);
        $this->assertSame('ok', $body['data']['checks']['database']);
        $this->assertSame('wapifood-api', $body['data']['service']);
        $this->assertArrayHasKey('meta', $body);
    }

    public function testHealthDoesNotExposeEnvironmentDetails(): void
    {
        $json = $this->get('api/v1/health')->getJSON();

        $this->assertStringNotContainsStringIgnoringCase('php', (string) $json);
        $this->assertStringNotContainsString(ENVIRONMENT, (string) $json);
    }

    public function testCorsPreflightFromAllowedOrigin(): void
    {
        $origin = explode(',', (string) env('CORS_ALLOWED_ORIGINS'))[0];

        $result = $this->withHeaders([
            'Origin'                        => $origin,
            'Access-Control-Request-Method' => 'POST',
        ])->options('api/v1/health');

        $result->assertStatus(204);
        $result->assertHeader('Access-Control-Allow-Origin', $origin);
    }

    public function testSecurityHeadersArePresent(): void
    {
        $result = $this->get('api/v1/health');

        $result->assertHeader('X-Content-Type-Options', 'nosniff');
        $result->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }
}
