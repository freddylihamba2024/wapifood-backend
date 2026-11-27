<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Cross-Origin Resource Sharing (CORS) Configuration
 *
 * Les origines autorisées sont lues depuis la variable `CORS_ALLOWED_ORIGINS`
 * du fichier `.env` (liste séparée par des virgules). Aucune origine joker.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
 */
class Cors extends BaseConfig
{
    /**
     * Profil utilisé par l'API (filtre `cors` appliqué au groupe api/*).
     *
     * L'authentification se fait par en-tête `Authorization: Bearer`, sans cookie :
     * `supportsCredentials` reste donc à false.
     *
     * @var array{
     *      allowedOrigins: list<string>,
     *      allowedOriginsPatterns: list<string>,
     *      supportsCredentials: bool,
     *      allowedHeaders: list<string>,
     *      exposedHeaders: list<string>,
     *      allowedMethods: list<string>,
     *      maxAge: int,
     *  }
     */
    public array $default = [
        'allowedOrigins'         => [],
        'allowedOriginsPatterns' => [],
        'supportsCredentials'    => false,
        'allowedHeaders'         => ['Authorization', 'Content-Type', 'Accept', 'X-Requested-With', 'Idempotency-Key', 'X-Restaurant-Id'],
        'exposedHeaders'         => ['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Idempotent-Replayed'],
        'allowedMethods'         => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'maxAge'                 => 7200,
    ];

    public function __construct()
    {
        parent::__construct();

        $origins = (string) env('CORS_ALLOWED_ORIGINS', '');

        $this->default['allowedOrigins'] = array_values(array_filter(
            array_map('trim', explode(',', $origins)),
            static fn (string $origin): bool => $origin !== '' && $origin !== '*',
        ));
    }
}
