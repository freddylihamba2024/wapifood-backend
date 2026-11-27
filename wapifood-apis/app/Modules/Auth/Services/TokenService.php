<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Common\Exceptions\ApiException;
use App\Libraries\Jwt\JwtService;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Auth\Models\RefreshTokenModel;
use App\Modules\Users\Presenters\UserPresenter;
use App\Modules\Users\Repositories\UserRepository;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\IncomingRequest;
use Config\Jwt as JwtConfig;

/**
 * Sessions : paires access token (JWT) + refresh token (opaque).
 *
 * - Refresh token : 48 octets aléatoires (base64url) ; seul son SHA-256 est stocké.
 * - Rotation à chaque renouvellement : l'ancien jeton est révoqué et chaîné au nouveau.
 * - Réutilisation d'un jeton déjà renouvelé = vol probable : toute la session est révoquée
 *   et token_version est incrémenté (tous les jetons d'accès de l'utilisateur tombent).
 * - Le jeton d'accès porte l'identifiant de session (sid) : une session révoquée rend
 *   immédiatement ses jetons d'accès inutilisables (contrôle dans JwtAuthFilter).
 */
final class TokenService
{
    private BaseConnection $db;

    public function __construct(
        private readonly RefreshTokenModel $tokens = new RefreshTokenModel(),
        private readonly UserRepository $users = new UserRepository(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ?JwtService $jwt = null,
        private readonly JwtConfig $config = new JwtConfig(),
        ?BaseConnection $db = null,
    ) {
        $this->db = $db ?? db_connect();
    }

    /**
     * Ouvre une nouvelle session pour l'utilisateur.
     *
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed> Réponse « tokens » de l'API
     */
    public function startSession(array $user): array
    {
        $familyId = bin2hex(random_bytes(16));
        [$refreshToken] = $this->storeRefreshToken((int) $user['id'], $familyId);

        return $this->buildPair($user, $familyId, $refreshToken);
    }

    /**
     * Renouvelle une session à partir d'un refresh token (rotation).
     *
     * @return array<string, mixed>
     *
     * @throws ApiException 401 REFRESH_TOKEN_INVALID | REFRESH_TOKEN_REUSED | 403 ACCOUNT_SUSPENDED
     */
    public function refresh(string $refreshToken): array
    {
        $invalid = ApiException::unauthorized('Session invalide ou expirée. Reconnectez-vous.', 'REFRESH_TOKEN_INVALID');

        if (preg_match('/\A[A-Za-z0-9_-]{40,128}\z/', $refreshToken) !== 1) {
            throw $invalid;
        }

        $this->db->transException(true)->transStart();

        $current = $this->db->query(
            'SELECT * FROM wf_refresh_tokens WHERE token_hash = ? FOR UPDATE',
            [hash('sha256', $refreshToken)],
        )->getRowArray();

        if ($current === null) {
            $this->db->transComplete();

            throw $invalid;
        }

        if ($current['revoked_at'] !== null) {
            $reused = $current['revoked_reason'] === 'rotated';

            if ($reused) {
                $this->revokeFamilyInTransaction($current['family_id'], 'reuse_detected');
                $this->users->bumpTokenVersion((int) $current['user_id']);
            }

            $this->db->transComplete();

            if ($reused) {
                $this->audit->log('auth.refresh_token_reuse_detected', (int) $current['user_id'], 'session', null, ['session' => $current['family_id']]);

                throw ApiException::unauthorized('Session révoquée pour raison de sécurité. Reconnectez-vous.', 'REFRESH_TOKEN_REUSED');
            }

            throw $invalid;
        }

        if (strtotime($current['expires_at'] . ' UTC') <= time()) {
            $this->db->transComplete();

            throw $invalid;
        }

        $user = $this->users->find((int) $current['user_id']);

        if ($user === null || in_array($user['status'], ['suspended', 'deleted'], true)) {
            $this->revokeFamilyInTransaction($current['family_id'], 'account_disabled');
            $this->db->transComplete();

            throw ApiException::forbidden('Ce compte est suspendu.', 'ACCOUNT_SUSPENDED');
        }

        [$newToken, $newId] = $this->storeRefreshToken((int) $user['id'], $current['family_id']);

        $this->tokens->update((int) $current['id'], [
            'revoked_at'     => gmdate('Y-m-d H:i:s'),
            'revoked_reason' => 'rotated',
            'replaced_by_id' => $newId,
        ]);

        $this->db->transComplete();

        return $this->buildPair($user, $current['family_id'], $newToken);
    }

    /** Ferme une session (déconnexion de l'appareil courant). */
    public function revokeSession(int $userId, string $familyId, string $reason = 'logout'): bool
    {
        $affected = $this->tokens->builder()
            ->where('user_id', $userId)
            ->where('family_id', $familyId)
            ->where('revoked_at', null)
            ->update(['revoked_at' => gmdate('Y-m-d H:i:s'), 'revoked_reason' => $reason]);

        return $affected && $this->db->affectedRows() > 0;
    }

    /**
     * Ferme toutes les sessions de l'utilisateur et invalide tous ses jetons d'accès.
     */
    public function revokeAllSessions(int $userId, string $reason, ?string $exceptFamilyId = null): void
    {
        $builder = $this->tokens->builder()
            ->where('user_id', $userId)
            ->where('revoked_at', null);

        if ($exceptFamilyId !== null) {
            $builder->where('family_id !=', $exceptFamilyId);
        }

        $builder->update(['revoked_at' => gmdate('Y-m-d H:i:s'), 'revoked_reason' => $reason]);
    }

    /** Une session est active si elle possède un refresh token non révoqué et non expiré. */
    public function isSessionActive(int $userId, string $familyId): bool
    {
        return $this->tokens->where('user_id', $userId)
            ->where('family_id', $familyId)
            ->where('revoked_at', null)
            ->where('expires_at >', gmdate('Y-m-d H:i:s'))
            ->countAllResults() > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeSessions(int $userId, string $currentFamilyId): array
    {
        $rows = $this->tokens->select('family_id, ip_address, user_agent, created_at, expires_at')
            ->where('user_id', $userId)
            ->where('revoked_at', null)
            ->where('expires_at >', gmdate('Y-m-d H:i:s'))
            ->orderBy('created_at', 'DESC')
            ->findAll(50);

        $firstSeen = $this->firstSeenByFamily($userId, array_column($rows, 'family_id'));

        return array_map(static fn (array $row): array => [
            'id'             => $row['family_id'],
            'current'        => $row['family_id'] === $currentFamilyId,
            'ip_address'     => $row['ip_address'],
            'user_agent'     => $row['user_agent'],
            'started_at'     => UserPresenter::isoDate($firstSeen[$row['family_id']] ?? $row['created_at']),
            'last_refreshed' => UserPresenter::isoDate($row['created_at']),
            'expires_at'     => UserPresenter::isoDate($row['expires_at']),
        ], $rows);
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    private function buildPair(array $user, string $familyId, string $refreshToken): array
    {
        $roles  = $this->users->rolesOf((int) $user['id']);
        $access = ($this->jwt ?? service('jwt'))->issueAccessToken((int) $user['id'], $familyId, (int) $user['token_version'], $roles);

        return [
            'token_type'         => 'Bearer',
            'access_token'       => $access['token'],
            'expires_in'         => $access['expires_in'],
            'refresh_token'      => $refreshToken,
            'refresh_expires_in' => $this->config->refreshTtl,
        ];
    }

    /**
     * @return array{0: string, 1: int} [jeton brut, identifiant de la ligne]
     */
    private function storeRefreshToken(int $userId, string $familyId): array
    {
        $token   = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $request = service('request');
        $isHttp  = $request instanceof IncomingRequest;

        $id = (int) $this->tokens->insert([
            'user_id'    => $userId,
            'family_id'  => $familyId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $this->config->refreshTtl),
            'ip_address' => $isHttp ? $request->getIPAddress() : null,
            'user_agent' => $isHttp ? mb_substr((string) $request->getUserAgent(), 0, 500) : null,
        ]);

        return [$token, $id];
    }

    private function revokeFamilyInTransaction(string $familyId, string $reason): void
    {
        $this->tokens->builder()
            ->where('family_id', $familyId)
            ->where('revoked_at', null)
            ->update(['revoked_at' => gmdate('Y-m-d H:i:s'), 'revoked_reason' => $reason]);
    }

    /**
     * @param list<string> $familyIds
     *
     * @return array<string, string>
     */
    private function firstSeenByFamily(int $userId, array $familyIds): array
    {
        if ($familyIds === []) {
            return [];
        }

        $rows = $this->tokens->builder()
            ->select('family_id, MIN(created_at) AS started_at')
            ->where('user_id', $userId)
            ->whereIn('family_id', $familyIds)
            ->groupBy('family_id')
            ->get()
            ->getResultArray();

        return array_column($rows, 'started_at', 'family_id');
    }
}
