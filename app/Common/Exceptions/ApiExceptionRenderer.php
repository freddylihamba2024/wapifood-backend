<?php

declare(strict_types=1);

namespace App\Common\Exceptions;

use App\Common\Http\ApiResponse;
use CodeIgniter\Exceptions\HTTPExceptionInterface;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Convertit n'importe quelle exception en réponse JSON standard.
 *
 * - ApiException       : statut, message et erreurs de l'exception.
 * - Exceptions HTTP CI4 : statut conservé, message générique.
 * - Toute autre erreur  : 500, message générique, journalisée.
 *
 * Les détails techniques (classe, message, fichier, ligne) ne sont ajoutés
 * dans `meta.debug` qu'hors production.
 */
final class ApiExceptionRenderer
{
    /** @var array<int, string> */
    private const GENERIC_MESSAGES = [
        400 => 'Requête invalide.',
        401 => 'Authentification requise.',
        403 => 'Accès interdit.',
        404 => 'Ressource introuvable.',
        405 => 'Méthode HTTP non autorisée.',
        409 => 'Conflit avec l\'état actuel de la ressource.',
        413 => 'Contenu trop volumineux.',
        415 => 'Type de contenu non supporté.',
        422 => 'Les données envoyées sont invalides.',
        429 => 'Trop de requêtes. Réessayez plus tard.',
        503 => 'Service temporairement indisponible.',
    ];

    public function __construct(private readonly bool $exposeDebug = ENVIRONMENT !== 'production')
    {
    }

    /**
     * @return array{0: int, 1: array<string, mixed>} [statut HTTP, corps JSON]
     */
    public function render(Throwable $exception): array
    {
        $status  = $this->resolveStatus($exception);
        $message = self::GENERIC_MESSAGES[$status] ?? 'Une erreur interne est survenue.';
        $errors  = [];
        $meta    = null;

        if ($exception instanceof ApiException) {
            $message = $exception->getMessage();
            $errors  = $exception->getErrors();

            if ($exception->getErrorCode() !== null) {
                $meta = ['code' => $exception->getErrorCode()];
            }
        }

        if ($status >= 500) {
            // Note : {file} et {line} sont réservés par le Logger CI4 (emplacement de l'appel).
            log_message('error', '[API] {class}: {message} in {location}', [
                'class'    => $exception::class,
                'message'  => $exception->getMessage(),
                'location' => clean_path($exception->getFile()) . ':' . $exception->getLine(),
            ]);
        }

        if ($this->exposeDebug && ! $exception instanceof ApiException) {
            $meta ??= [];
            $meta['debug'] = [
                'exception' => $exception::class,
                'message'   => $exception->getMessage(),
                'file'      => clean_path($exception->getFile()),
                'line'      => $exception->getLine(),
            ];
        }

        return [$status, ApiResponse::errorBody($message, $errors, $meta)];
    }

    public function toResponse(Throwable $exception, ResponseInterface $response): ResponseInterface
    {
        [$status, $body] = $this->render($exception);

        return $response->setStatusCode($status)->setJSON($body);
    }

    private function resolveStatus(Throwable $exception): int
    {
        if ($exception instanceof ApiException) {
            return $exception->getStatusCode();
        }

        if ($exception instanceof PageNotFoundException) {
            return 404;
        }

        if ($exception instanceof HTTPExceptionInterface) {
            $code = (int) $exception->getCode();

            return ($code >= 400 && $code < 600) ? $code : 500;
        }

        return 500;
    }
}
