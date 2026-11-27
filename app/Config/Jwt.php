<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Jetons d'accès JWT (HS256).
 *
 * Surcharges `.env` : jwt.secret, jwt.issuer, jwt.audience, jwt.accessTtl, jwt.refreshTtl.
 * Le secret n'est jamais versionné ; générer : php -r "echo bin2hex(random_bytes(64));"
 */
class Jwt extends BaseConfig
{
    /** Clé HMAC : 64 caractères minimum (vérifié au démarrage du service JWT). */
    public string $secret = '';

    public string $algorithm = 'HS256';

    public string $issuer = 'wapifood';

    public string $audience = 'wapifood-api';

    /** Durée de vie du jeton d'accès, en secondes (15 min). */
    public int $accessTtl = 900;

    /** Durée de vie d'un refresh token, en secondes (30 jours). */
    public int $refreshTtl = 2_592_000;

    /** Tolérance d'horloge entre serveurs, en secondes. */
    public int $leeway = 30;
}
