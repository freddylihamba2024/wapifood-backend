<?php

declare(strict_types=1);

namespace Tests\Unit\Common;

use App\Common\Exceptions\ApiException;
use App\Common\Exceptions\ApiExceptionRenderer;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * @internal
 */
final class ApiExceptionRendererTest extends CIUnitTestCase
{
    public function testApiExceptionKeepsStatusMessageErrorsAndCode(): void
    {
        [$status, $body] = (new ApiExceptionRenderer(false))->render(
            ApiException::validation(['phone' => 'Le téléphone est requis.']),
        );

        $this->assertSame(422, $status);
        $this->assertFalse($body['success']);
        $this->assertSame('Les données envoyées sont invalides.', $body['message']);
        $this->assertSame(['phone' => 'Le téléphone est requis.'], $body['errors']);
        $this->assertSame(['code' => 'VALIDATION_FAILED'], $body['meta']);
    }

    /**
     * @return iterable<string, array{ApiException, int}>
     */
    public static function provideFactoryStatuses(): iterable
    {
        yield '400' => [ApiException::badRequest(), 400];
        yield '401' => [ApiException::unauthorized(), 401];
        yield '403' => [ApiException::forbidden(), 403];
        yield '404' => [ApiException::notFound(), 404];
        yield '409' => [ApiException::conflict(), 409];
        yield '429' => [ApiException::tooManyRequests(), 429];
    }

    #[DataProvider('provideFactoryStatuses')]
    public function testFactoriesMapToExpectedStatus(ApiException $exception, int $expected): void
    {
        [$status] = (new ApiExceptionRenderer(false))->render($exception);

        $this->assertSame($expected, $status);
    }

    public function testRouteNotFoundBecomes404(): void
    {
        [$status, $body] = (new ApiExceptionRenderer(false))->render(PageNotFoundException::forPageNotFound());

        $this->assertSame(404, $status);
        $this->assertSame('Ressource introuvable.', $body['message']);
    }

    public function testFrameworkHttpExceptionKeepsItsStatus(): void
    {
        [$status] = (new ApiExceptionRenderer(false))->render(SecurityException::forInvalidUTF8Chars('get', "\xFF"));

        $this->assertSame(400, $status);
    }

    public function testUnexpectedErrorIsGeneric500WithoutLeakInProduction(): void
    {
        $exception = new DatabaseException("SQLSTATE: Table 'wf_users' secret=abc");

        [$status, $body] = (new ApiExceptionRenderer(false))->render($exception);

        $this->assertSame(500, $status);
        $this->assertSame('Une erreur interne est survenue.', $body['message']);
        $this->assertNull($body['meta']);

        $json = (string) json_encode($body);
        $this->assertStringNotContainsString('SQLSTATE', $json);
        $this->assertStringNotContainsString('secret', $json);
        $this->assertStringNotContainsString('DatabaseException', $json);
    }

    public function testUnexpectedErrorIsLogged(): void
    {
        (new ApiExceptionRenderer(false))->render(new RuntimeException('boom'));

        $this->assertLogged('error', '[API] RuntimeException: boom in ' . clean_path(__FILE__) . ':' . (__LINE__ - 2));
    }

    public function testDebugDetailsOnlyWhenEnabled(): void
    {
        [, $body] = (new ApiExceptionRenderer(true))->render(new RuntimeException('boom'));

        $this->assertSame(RuntimeException::class, $body['meta']['debug']['exception']);
        $this->assertSame('boom', $body['meta']['debug']['message']);
    }

    public function testApiExceptionNeverCarriesDebugDetails(): void
    {
        [, $body] = (new ApiExceptionRenderer(true))->render(ApiException::notFound());

        $this->assertNull($body['meta']);
    }
}
