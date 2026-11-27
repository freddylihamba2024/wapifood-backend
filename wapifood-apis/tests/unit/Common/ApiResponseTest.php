<?php

declare(strict_types=1);

namespace Tests\Unit\Common;

use App\Common\Http\ApiResponse;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ApiResponseTest extends CIUnitTestCase
{
    public function testSuccessEnvelopeHasStandardShape(): void
    {
        $response = ApiResponse::success(service('response'), ['id' => 1], 'OK', 201, ['page' => 1]);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(
            ['success' => true, 'message' => 'OK', 'data' => ['id' => 1], 'meta' => ['page' => 1]],
            json_decode((string) $response->getBody(), true),
        );
    }

    public function testSuccessWithoutDataReturnsEmptyObjectNotNull(): void
    {
        $json = json_encode(ApiResponse::successBody());

        $this->assertStringContainsString('"data":{}', (string) $json);
        $this->assertStringContainsString('"meta":null', (string) $json);
    }

    public function testErrorEnvelopeHasStandardShape(): void
    {
        $response = ApiResponse::error(service('response'), 422, 'Invalide', ['phone' => 'Requis']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            ['success' => false, 'message' => 'Invalide', 'errors' => ['phone' => 'Requis'], 'meta' => null],
            json_decode((string) $response->getBody(), true),
        );
    }

    public function testErrorWithoutDetailsSerializesErrorsAsObject(): void
    {
        $json = json_encode(ApiResponse::errorBody('Erreur'));

        $this->assertStringContainsString('"errors":{}', (string) $json);
    }
}
