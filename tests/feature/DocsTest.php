<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Config\Factories;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\WapiFood;

/**
 * @internal
 */
final class DocsTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function tearDown(): void
    {
        Factories::reset('config');
        parent::tearDown();
    }

    public function testSwaggerUiIsServedWhenEnabled(): void
    {
        $result = $this->get('docs');

        $result->assertStatus(200);
        $result->assertSee('swagger-ui');
        $result->assertSee('docs/openapi.yaml');
    }

    public function testOpenApiSpecIsServedAsYaml(): void
    {
        $result = $this->get('docs/openapi.yaml');

        $result->assertStatus(200);
        $result->assertHeader('Content-Type', 'application/yaml; charset=UTF-8');
        $result->assertSee('openapi: 3.0.3');
        $result->assertSee('/health:');
    }

    public function testDocsAreHiddenWhenDisabled(): void
    {
        $config              = config(WapiFood::class);
        $config->docsEnabled = false;
        Factories::injectMock('config', WapiFood::class, $config);

        $this->expectException(PageNotFoundException::class);

        $this->get('docs/openapi.yaml');
    }

    public function testEveryImplementedApiRouteIsDocumented(): void
    {
        $spec      = (string) file_get_contents(ROOTPATH . 'docs/api/openapi.yaml');
        $collector = service('routes')->loadRoutes();
        $checked   = 0;

        foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
            foreach (array_keys($collector->getRoutes(strtoupper($method))) as $route) {
                if (! str_starts_with($route, 'api/v1/')) {
                    continue;
                }

                // api/v1/auth/sessions/([^/]+) → /auth/sessions/{paramètre}
                $path    = '/' . substr($route, strlen('api/v1/'));
                $pattern = preg_quote($path, '/');
                $pattern = str_replace(preg_quote('([^/]+)', '/'), '\\{[a-zA-Z_]+\\}', $pattern);

                $this->assertMatchesRegularExpression(
                    '/^  ' . $pattern . ':\n(?:(?!^  \/).*\n)*?^    ' . $method . ':/m',
                    $spec,
                    strtoupper($method) . " {$route} absente de docs/api/openapi.yaml",
                );
                $checked++;
            }
        }

        $this->assertGreaterThan(1, $checked);
    }

    public function testSpecDoesNotDocumentMissingRoutes(): void
    {
        $spec      = (string) file_get_contents(ROOTPATH . 'docs/api/openapi.yaml');
        $collector = service('routes')->loadRoutes();
        $known     = [];

        foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
            foreach (array_keys($collector->getRoutes(strtoupper($method))) as $route) {
                $known[] = $method . ' /' . preg_replace('#\(\[\^/\]\+\)#', '{}', substr($route, strlen('api/v1/')));
            }
        }

        preg_match_all('/^  (\/[^:\n]+):\n((?:(?!^  \/).*\n?)*)/m', $spec, $paths, PREG_SET_ORDER);

        foreach ($paths as [, $path, $block]) {
            preg_match_all('/^    (get|post|put|patch|delete):/m', $block, $methods);

            foreach ($methods[1] as $method) {
                $normalized = $method . ' ' . preg_replace('/\{[a-zA-Z_]+\}/', '{}', $path);
                $this->assertContains($normalized, $known, "openapi.yaml documente une route inexistante : {$normalized}");
            }
        }
    }
}
