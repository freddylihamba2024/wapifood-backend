<?php

declare(strict_types=1);

namespace App\Common\Exceptions;

use CodeIgniter\Debug\ExceptionHandler;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Exceptions as ExceptionsConfig;
use Throwable;

/**
 * Gestionnaire global des exceptions non interceptées.
 *
 * Pour toute requête sous `api/`, renvoie l'enveloppe JSON standard
 * (y compris pour les routes inexistantes et les erreurs fatales).
 * Les autres requêtes (pages HTML, CLI) gardent le comportement natif de CI4.
 */
final class ApiExceptionHandler implements ExceptionHandlerInterface
{
    public function __construct(
        private readonly ExceptionsConfig $config,
        private readonly ApiExceptionRenderer $renderer = new ApiExceptionRenderer(),
    ) {
    }

    public function handle(
        Throwable $exception,
        RequestInterface $request,
        ResponseInterface $response,
        int $statusCode,
        int $exitCode,
    ): void {
        if (! self::isApiRequest($request)) {
            (new ExceptionHandler($this->config))->handle($exception, $request, $response, $statusCode, $exitCode);

            return;
        }

        $this->renderer->toResponse($exception, $response)->send();

        if (ENVIRONMENT !== 'testing') {
            // @codeCoverageIgnoreStart
            exit($exitCode);
            // @codeCoverageIgnoreEnd
        }
    }

    public static function isApiRequest(RequestInterface $request): bool
    {
        if (! $request instanceof IncomingRequest) {
            return false;
        }

        $path = ltrim($request->getPath(), '/');

        return $path === 'api' || str_starts_with($path, 'api/');
    }
}
