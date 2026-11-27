<?php

declare(strict_types=1);

namespace App\Libraries\Jwt;

use App\Common\Exceptions\ApiException;
use Config\Jwt as JwtConfig;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use Throwable;

/**
 * Émission et vérification des jetons d'accès (JWT HS256, courte durée).
 *
 * Claims : iss, aud, sub (id utilisateur), iat, nbf, exp, jti, typ=access,
 *          sid (session = famille de refresh tokens), ver (token_version), roles (indicatif).
 * Les rôles du jeton servent uniquement à l'interface : les filtres relisent toujours la base.
 */
final class JwtService
{
    private const MIN_SECRET_LENGTH = 64;

    public function __construct(private readonly JwtConfig $config = new JwtConfig())
    {
        if (strlen($this->config->secret) < self::MIN_SECRET_LENGTH) {
            throw new RuntimeException('jwt.secret absent ou trop court (64 caractères minimum) : renseignez-le dans .env.');
        }
    }

    /**
     * @param list<string> $roles
     *
     * @return array{token: string, expires_in: int}
     */
    public function issueAccessToken(int $userId, string $sessionId, int $tokenVersion, array $roles): array
    {
        $now = time();

        $claims = [
            'iss'   => $this->config->issuer,
            'aud'   => $this->config->audience,
            'sub'   => (string) $userId,
            'iat'   => $now,
            'nbf'   => $now,
            'exp'   => $now + $this->config->accessTtl,
            'jti'   => bin2hex(random_bytes(16)),
            'typ'   => 'access',
            'sid'   => $sessionId,
            'ver'   => $tokenVersion,
            'roles' => array_values($roles),
        ];

        return [
            'token'      => JWT::encode($claims, $this->config->secret, $this->config->algorithm),
            'expires_in' => $this->config->accessTtl,
        ];
    }

    /**
     * @return array{sub: int, sid: string, ver: int, roles: list<string>}
     *
     * @throws ApiException 401 TOKEN_EXPIRED | TOKEN_INVALID
     */
    public function verifyAccessToken(string $token): array
    {
        JWT::$leeway = $this->config->leeway;

        try {
            $payload = (array) JWT::decode($token, new Key($this->config->secret, $this->config->algorithm));
        } catch (ExpiredException) {
            throw ApiException::unauthorized('Session expirée. Renouvelez votre jeton.', 'TOKEN_EXPIRED');
        } catch (Throwable) {
            throw ApiException::unauthorized('Jeton d\'authentification invalide.', 'TOKEN_INVALID');
        }

        $valid = ($payload['iss'] ?? null) === $this->config->issuer
            && ($payload['aud'] ?? null) === $this->config->audience
            && ($payload['typ'] ?? null) === 'access'
            && ctype_digit((string) ($payload['sub'] ?? ''))
            && is_string($payload['sid'] ?? null)
            && is_int($payload['ver'] ?? null);

        if (! $valid) {
            throw ApiException::unauthorized('Jeton d\'authentification invalide.', 'TOKEN_INVALID');
        }

        return [
            'sub'   => (int) $payload['sub'],
            'sid'   => $payload['sid'],
            'ver'   => $payload['ver'],
            'roles' => array_values(array_filter((array) ($payload['roles'] ?? []), 'is_string')),
        ];
    }
}
