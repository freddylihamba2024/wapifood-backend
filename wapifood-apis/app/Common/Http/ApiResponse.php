<?php

declare(strict_types=1);

namespace App\Common\Http;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * Construit l'enveloppe JSON standard de l'API WapiFood.
 *
 * Succès : { success: true,  message, data,   meta }
 * Erreur : { success: false, message, errors, meta }
 */
final class ApiResponse
{
    /**
     * @param array<string, mixed>|list<mixed>|object|null $data
     * @param array<string, mixed>|null                    $meta
     *
     * @return array{success: true, message: string, data: mixed, meta: array<string, mixed>|null}
     */
    public static function successBody(mixed $data = null, string $message = 'Opération effectuée avec succès.', ?array $meta = null): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data'    => $data ?? (object) [],
            'meta'    => $meta,
        ];
    }

    /**
     * @param array<string, mixed>      $errors
     * @param array<string, mixed>|null $meta
     *
     * @return array{success: false, message: string, errors: object|array<string, mixed>, meta: array<string, mixed>|null}
     */
    public static function errorBody(string $message = 'Une erreur est survenue.', array $errors = [], ?array $meta = null): array
    {
        return [
            'success' => false,
            'message' => $message,
            // Objet vide plutôt que tableau vide : le format JSON reste stable pour le client TypeScript.
            'errors' => $errors === [] ? (object) [] : $errors,
            'meta'   => $meta,
        ];
    }

    /**
     * @param array<string, mixed>|list<mixed>|object|null $data
     * @param array<string, mixed>|null                    $meta
     */
    public static function success(
        ResponseInterface $response,
        mixed $data = null,
        string $message = 'Opération effectuée avec succès.',
        int $status = 200,
        ?array $meta = null,
    ): ResponseInterface {
        return $response
            ->setStatusCode($status)
            ->setJSON(self::successBody($data, $message, $meta));
    }

    /**
     * @param array<string, mixed>      $errors
     * @param array<string, mixed>|null $meta
     */
    public static function error(
        ResponseInterface $response,
        int $status,
        string $message = 'Une erreur est survenue.',
        array $errors = [],
        ?array $meta = null,
    ): ResponseInterface {
        return $response
            ->setStatusCode($status)
            ->setJSON(self::errorBody($message, $errors, $meta));
    }
}
