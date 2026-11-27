<?php

declare(strict_types=1);

namespace App\Common\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Exception métier convertie en réponse JSON standard.
 *
 * Le message est destiné au client : il ne doit jamais contenir de secret,
 * de requête SQL ni de détail interne.
 */
class ApiException extends RuntimeException
{
    /**
     * @param array<string, mixed> $errors Détails par champ ou par règle (affichables au client)
     */
    public function __construct(
        string $message = 'Une erreur est survenue.',
        private readonly int $statusCode = 400,
        private readonly array $errors = [],
        private readonly ?string $errorCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** Code d'erreur stable et lisible par machine (ex. RESTAURANT_SUSPENDED). */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * @param array<string, mixed> $errors
     */
    public static function badRequest(string $message = 'Requête invalide.', array $errors = [], ?string $errorCode = null): static
    {
        return new static($message, 400, $errors, $errorCode);
    }

    public static function unauthorized(string $message = 'Authentification requise.', ?string $errorCode = null): static
    {
        return new static($message, 401, [], $errorCode);
    }

    public static function forbidden(string $message = 'Accès interdit.', ?string $errorCode = null): static
    {
        return new static($message, 403, [], $errorCode);
    }

    public static function notFound(string $message = 'Ressource introuvable.', ?string $errorCode = null): static
    {
        return new static($message, 404, [], $errorCode);
    }

    /**
     * @param array<string, mixed> $errors
     */
    public static function conflict(string $message = 'Conflit avec l\'état actuel de la ressource.', array $errors = [], ?string $errorCode = null): static
    {
        return new static($message, 409, $errors, $errorCode);
    }

    /**
     * @param array<string, string> $errors Erreurs de validation par champ
     */
    public static function validation(array $errors, string $message = 'Les données envoyées sont invalides.'): static
    {
        return new static($message, 422, $errors, 'VALIDATION_FAILED');
    }

    public static function tooManyRequests(string $message = 'Trop de requêtes. Réessayez plus tard.'): static
    {
        return new static($message, 429, [], 'RATE_LIMITED');
    }
}
