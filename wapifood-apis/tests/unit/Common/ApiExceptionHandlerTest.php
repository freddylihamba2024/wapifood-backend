<?php

declare(strict_types=1);

namespace Tests\Unit\Common;

use App\Common\Exceptions\ApiExceptionHandler;
use App\Common\Exceptions\ApiExceptionRenderer;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Exceptions;

/**
 * @internal
 */
final class ApiExceptionHandlerTest extends CIUnitTestCase
{
    private function request(string $path): IncomingRequest
    {
        return new IncomingRequest(new App(), new URI('http://example.com/' . $path), null, new UserAgent());
    }

    public function testDetectsApiRequests(): void
    {
        $this->assertTrue(ApiExceptionHandler::isApiRequest($this->request('api/v1/health')));
        $this->assertTrue(ApiExceptionHandler::isApiRequest($this->request('api')));
        $this->assertFalse(ApiExceptionHandler::isApiRequest($this->request('apifoo')));
        $this->assertFalse(ApiExceptionHandler::isApiRequest($this->request('docs')));
        $this->assertFalse(ApiExceptionHandler::isApiRequest(new CLIRequest(new App())));
    }

    public function testUnknownApiRouteRendersJsonEnvelope(): void
    {
        $handler  = new ApiExceptionHandler(new Exceptions(), new ApiExceptionRenderer(false));
        $response = service('response');

        ob_start();
        $handler->handle(PageNotFoundException::forPageNotFound(), $this->request('api/v1/unknown'), $response, 404, 4);
        $output = (string) ob_get_clean();

        $this->assertSame(
            ['success' => false, 'message' => 'Ressource introuvable.', 'errors' => [], 'meta' => null],
            json_decode($output, true),
        );
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
    }
}
