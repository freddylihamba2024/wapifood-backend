<?php

declare(strict_types=1);

namespace Tests\Unit\Filters;

use App\Filters\RateLimitFilter;
use CodeIgniter\Config\Factories;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class RateLimitFilterTest extends CIUnitTestCase
{
    private string $bucket;

    protected function setUp(): void
    {
        parent::setUp();

        config('WapiFood')->rateLimitEnabled = true;

        // Compteur unique par test : aucune interférence avec les autres exécutions.
        $this->bucket = 'test-' . bin2hex(random_bytes(6));
    }

    public function testAllowsRequestsUpToCapacityThenReturns429(): void
    {
        $filter  = new RateLimitFilter();
        $request = service('request');
        $args    = ['2', '60', $this->bucket];

        $this->assertNull($filter->before($request, $args));
        $this->assertNull($filter->before($request, $args));

        $response = $filter->before($request, $args);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(429, $response->getStatusCode());
        $this->assertGreaterThanOrEqual(1, (int) $response->getHeaderLine('Retry-After'));
        $this->assertSame('2', $response->getHeaderLine('X-RateLimit-Limit'));

        $body = json_decode((string) $response->getBody(), true);
        $this->assertFalse($body['success']);
        $this->assertSame('RATE_LIMITED', $body['meta']['code']);
    }

    protected function tearDown(): void
    {
        Factories::reset('config');
        parent::tearDown();
    }

    public function testDisabledLimiterLetsEverythingThrough(): void
    {
        config('WapiFood')->rateLimitEnabled = false;
        $filter = new RateLimitFilter();

        for ($i = 0; $i < 3; $i++) {
            $this->assertNull($filter->before(service('request'), ['1', '60', $this->bucket]));
        }
    }

    public function testBucketsAreIndependent(): void
    {
        $filter  = new RateLimitFilter();
        $request = service('request');

        $this->assertNull($filter->before($request, ['1', '60', $this->bucket . '-a']));
        $this->assertNull($filter->before($request, ['1', '60', $this->bucket . '-b']));
        $this->assertInstanceOf(ResponseInterface::class, $filter->before($request, ['1', '60', $this->bucket . '-a']));
    }

    /** Derrière le NAT d'un opérateur mobile, deux clients partagent la même IP mais pas le même compteur. */
    public function testPerUserBucketsSeparateUsersSharingAnIp(): void
    {
        $filter  = new RateLimitFilter();
        $request = service('request');
        $context = service('authContext');
        $args    = ['1', '60', $this->bucket, 'per-user'];

        try {
            $context->authenticate(['id' => 101], ['customer'], 'sid-a');
            $this->assertNull($filter->before($request, $args));
            $this->assertInstanceOf(ResponseInterface::class, $filter->before($request, $args));

            $context->authenticate(['id' => 102], ['customer'], 'sid-b');
            $this->assertNull($filter->before($request, $args));
        } finally {
            $context->clear();
        }

        // Sans utilisateur authentifié : repli sur l'IP.
        $this->assertNull($filter->before($request, $args));
        $this->assertInstanceOf(ResponseInterface::class, $filter->before($request, $args));
    }
}
