<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Common\Exceptions\ApiException;
use App\Common\Support\PhoneNumber;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Users\Presenters\UserPresenter;
use App\Modules\Users\Repositories\UserRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Auth as AuthConfig;
use Throwable;

/**
 * Cas d'usage de l'authentification : inscription, connexion, mots de passe, vérification du téléphone.
 *
 * Statuts du compte :
 * - pending   : inscrit, téléphone non vérifié → accès limité aux routes du compte ;
 * - active    : téléphone vérifié → accès complet selon les rôles ;
 * - suspended : connexion et jetons refusés ;
 * - deleted   : idem.
 */
final class AuthService
{
    /** Rôles qu'un visiteur peut choisir à l'inscription (jamais super_admin, staff ou courier). */
    public const SELF_SERVICE_ROLES = ['customer', 'restaurant_owner'];

    /** Hash factice (calculé une fois par processus) pour égaliser le temps de réponse. */
    private static ?string $dummyHash = null;

    private BaseConnection $db;

    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly TokenService $tokens = new TokenService(),
        private readonly AuthCodeService $codes = new AuthCodeService(),
        private readonly PasswordHasher $hasher = new PasswordHasher(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly AuthConfig $config = new AuthConfig(),
        ?BaseConnection $db = null,
    ) {
        $this->db = $db ?? db_connect();
    }

    /**
     * @param array{first_name: string, last_name?: string|null, email?: string|null, phone: string, password: string, account_type: string} $input
     *
     * @return array{user: array<string, mixed>, tokens: array<string, mixed>, phone_verification: array<string, mixed>}
     */
    public function register(array $input): array
    {
        $phone = PhoneNumber::normalize($input['phone']);
        $email = isset($input['email']) && trim((string) $input['email']) !== '' ? mb_strtolower(trim((string) $input['email'])) : null;
        $role  = $input['account_type'];

        if ($phone === null) {
            throw ApiException::validation(['phone' => 'Le numéro de téléphone est invalide.']);
        }

        if (! in_array($role, self::SELF_SERVICE_ROLES, true)) {
            throw ApiException::validation(['account_type' => 'Type de compte invalide.']);
        }

        $passwordErrors = $this->hasher->policyErrors($input['password'], $input['first_name'], (string) ($input['last_name'] ?? ''), (string) $email);

        if ($passwordErrors !== []) {
            throw ApiException::validation(['password' => implode(' ', $passwordErrors)]);
        }

        if ($this->users->phoneExists($phone)) {
            throw ApiException::conflict('Ce numéro de téléphone est déjà associé à un compte.', ['phone' => 'Déjà utilisé.'], 'PHONE_TAKEN');
        }

        if ($email !== null && $this->users->emailExists($email)) {
            throw ApiException::conflict('Cette adresse e-mail est déjà associée à un compte.', ['email' => 'Déjà utilisée.'], 'EMAIL_TAKEN');
        }

        $this->db->transException(true)->transStart();

        $userId = $this->users->create([
            'first_name'    => trim($input['first_name']),
            'last_name'     => isset($input['last_name']) && trim((string) $input['last_name']) !== '' ? trim((string) $input['last_name']) : null,
            'email'         => $email,
            'phone'         => $phone,
            'password_hash' => $this->hasher->hash($input['password']),
            'status'        => 'pending',
        ]);
        $this->users->assignRole($userId, $role);
        $this->audit->log('auth.registered', $userId, 'user', $userId, ['account_type' => $role]);

        $this->db->transComplete();

        $user = $this->users->find($userId);

        return [
            'user'               => UserPresenter::present($user, $this->users->rolesOf($userId)),
            'tokens'             => $this->tokens->startSession($user),
            'phone_verification' => $this->sendPhoneCodeSafely($user),
        ];
    }

    /**
     * @return array{user: array<string, mixed>, tokens: array<string, mixed>}
     *
     * @throws ApiException 401 INVALID_CREDENTIALS | 403 ACCOUNT_SUSPENDED | 429 ACCOUNT_LOCKED
     */
    public function login(string $identifier, string $password): array
    {
        $user = $this->users->findByIdentifier($identifier);

        if ($user === null) {
            // Temps de réponse comparable à un compte existant (pas d'énumération des comptes).
            self::$dummyHash ??= $this->hasher->hash(bin2hex(random_bytes(16)));
            $this->hasher->verify($password, self::$dummyHash);

            throw self::invalidCredentials();
        }

        if ($user['locked_until'] !== null && strtotime($user['locked_until'] . ' UTC') > time()) {
            $retryAfter = strtotime($user['locked_until'] . ' UTC') - time();

            throw new ApiException(
                'Trop de tentatives de connexion. Compte verrouillé temporairement.',
                429,
                ['retry_after' => $retryAfter],
                'ACCOUNT_LOCKED',
            );
        }

        if (! $this->hasher->verify($password, $user['password_hash'])) {
            $this->recordFailedLogin($user);

            throw self::invalidCredentials();
        }

        if (in_array($user['status'], ['suspended', 'deleted'], true)) {
            $this->audit->log('auth.login_refused_suspended', (int) $user['id'], 'user', (int) $user['id']);

            throw ApiException::forbidden('Ce compte est suspendu. Contactez le support WapiFood.', 'ACCOUNT_SUSPENDED');
        }

        $update = ['failed_login_attempts' => 0, 'locked_until' => null, 'last_login_at' => gmdate('Y-m-d H:i:s')];

        if ($this->hasher->needsRehash($user['password_hash'])) {
            $update['password_hash'] = $this->hasher->hash($password);
        }

        $this->users->update((int) $user['id'], $update);
        $this->audit->log('auth.login', (int) $user['id'], 'user', (int) $user['id']);

        $user = $this->users->find((int) $user['id']);

        return [
            'user'   => UserPresenter::present($user, $this->users->rolesOf((int) $user['id'])),
            'tokens' => $this->tokens->startSession($user),
        ];
    }

    public function logout(int $userId, string $sessionId, bool $allDevices): void
    {
        if ($allDevices) {
            $this->tokens->revokeAllSessions($userId, 'logout_all');
            $this->users->bumpTokenVersion($userId);
        } else {
            $this->tokens->revokeSession($userId, $sessionId);
        }

        $this->audit->log($allDevices ? 'auth.logout_all' : 'auth.logout', $userId, 'user', $userId);
    }

    /**
     * @return array<string, mixed>
     */
    public function me(int $userId): array
    {
        $user = $this->users->find($userId) ?? throw ApiException::unauthorized();

        return UserPresenter::present($user, $this->users->rolesOf($userId));
    }

    /**
     * @return array<string, mixed> Utilisateur mis à jour (statut active)
     */
    public function verifyPhone(int $userId, string $code): array
    {
        $user = $this->users->find($userId) ?? throw ApiException::unauthorized();

        if ($user['phone_verified_at'] !== null) {
            throw ApiException::conflict('Ce numéro est déjà vérifié.', [], 'PHONE_ALREADY_VERIFIED');
        }

        $this->codes->consume($user['phone'], AuthCodeService::PURPOSE_PHONE_VERIFICATION, $code);

        $update = ['phone_verified_at' => gmdate('Y-m-d H:i:s')];

        if ($user['status'] === 'pending') {
            $update['status'] = 'active';
        }

        $this->users->update($userId, $update);
        $this->audit->log('auth.phone_verified', $userId, 'user', $userId);

        return $this->me($userId);
    }

    public function resendPhoneVerification(int $userId): void
    {
        $user = $this->users->find($userId) ?? throw ApiException::unauthorized();

        if ($user['phone_verified_at'] !== null) {
            throw ApiException::conflict('Ce numéro est déjà vérifié.', [], 'PHONE_ALREADY_VERIFIED');
        }

        $this->codes->issue($userId, 'sms', $user['phone'], AuthCodeService::PURPOSE_PHONE_VERIFICATION);
    }

    /**
     * Toujours silencieux sur l'existence du compte (réponse identique côté API).
     * Les erreurs de quota (429) et d'indisponibilité (503) sont en revanche remontées.
     */
    public function forgotPassword(string $identifier): void
    {
        $user = $this->users->findByIdentifier($identifier);

        if ($user === null || in_array($user['status'], ['suspended', 'deleted'], true)) {
            return;
        }

        [$channel, $destination] = $this->resetDestination($user, $identifier);

        $this->codes->issue((int) $user['id'], $channel, $destination, AuthCodeService::PURPOSE_PASSWORD_RESET);
        $this->audit->log('auth.password_reset_requested', (int) $user['id'], 'user', (int) $user['id'], ['channel' => $channel]);
    }

    /**
     * Réinitialise le mot de passe, ferme toutes les sessions et invalide tous les jetons d'accès.
     */
    public function resetPassword(string $identifier, string $code, string $newPassword): void
    {
        $invalid = new ApiException('Code invalide ou expiré.', 422, ['code' => 'Code invalide ou expiré.'], 'INVALID_CODE');
        $user    = $this->users->findByIdentifier($identifier);

        if ($user === null || in_array($user['status'], ['suspended', 'deleted'], true)) {
            throw $invalid;
        }

        $this->assertPasswordPolicy($newPassword, $user);

        [, $destination] = $this->resetDestination($user, $identifier);
        $this->codes->consume($destination, AuthCodeService::PURPOSE_PASSWORD_RESET, $code);

        $this->replacePassword($user, $newPassword, 'password_reset');

        // Le code a été reçu sur le téléphone : il prouve aussi la possession du numéro.
        if (! str_contains($identifier, '@') && $user['phone_verified_at'] === null) {
            $this->users->update((int) $user['id'], array_filter([
                'phone_verified_at' => gmdate('Y-m-d H:i:s'),
                'status'            => $user['status'] === 'pending' ? 'active' : null,
            ]));
        }
    }

    /**
     * Change le mot de passe de l'utilisateur connecté et ouvre une nouvelle session
     * (les autres sessions et tous les anciens jetons d'accès sont invalidés).
     *
     * @return array<string, mixed> Nouveaux jetons
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): array
    {
        $user = $this->users->find($userId) ?? throw ApiException::unauthorized();

        if (! $this->hasher->verify($currentPassword, $user['password_hash'])) {
            throw ApiException::validation(['current_password' => 'Le mot de passe actuel est incorrect.']);
        }

        if ($this->hasher->verify($newPassword, $user['password_hash'])) {
            throw ApiException::validation(['new_password' => 'Le nouveau mot de passe doit être différent de l\'actuel.']);
        }

        $this->assertPasswordPolicy($newPassword, $user, 'new_password');
        $this->replacePassword($user, $newPassword, 'password_changed');

        return $this->tokens->startSession($this->users->find($userId));
    }

    /**
     * @param array<string, mixed> $user
     */
    private function replacePassword(array $user, string $newPassword, string $reason): void
    {
        $userId = (int) $user['id'];

        $this->db->transException(true)->transStart();

        $this->users->update($userId, [
            'password_hash'         => $this->hasher->hash($newPassword),
            'password_changed_at'   => gmdate('Y-m-d H:i:s'),
            'failed_login_attempts' => 0,
            'locked_until'          => null,
        ]);
        $this->users->bumpTokenVersion($userId);
        $this->tokens->revokeAllSessions($userId, $reason);
        $this->audit->log('auth.' . $reason, $userId, 'user', $userId);

        $this->db->transComplete();
    }

    /**
     * @param array<string, mixed> $user
     */
    private function assertPasswordPolicy(string $password, array $user, string $field = 'password'): void
    {
        $errors = $this->hasher->policyErrors($password, (string) $user['first_name'], (string) $user['last_name'], (string) $user['email']);

        if ($errors !== []) {
            throw ApiException::validation([$field => implode(' ', $errors)]);
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    private function recordFailedLogin(array $user): void
    {
        $attempts = (int) $user['failed_login_attempts'] + 1;
        $update   = ['failed_login_attempts' => $attempts];

        if ($attempts >= $this->config->maxFailedLogins) {
            $update['locked_until']          = gmdate('Y-m-d H:i:s', time() + $this->config->lockoutMinutes * 60);
            $update['failed_login_attempts'] = 0;
            $this->audit->log('auth.account_locked', (int) $user['id'], 'user', (int) $user['id']);
        }

        $this->users->update((int) $user['id'], $update);
    }

    /**
     * Le code de réinitialisation part vers l'identifiant saisi : e-mail ou téléphone.
     *
     * @param array<string, mixed> $user
     *
     * @return array{0: 'email'|'sms', 1: string}
     */
    private function resetDestination(array $user, string $identifier): array
    {
        return str_contains($identifier, '@')
            ? ['email', (string) $user['email']]
            : ['sms', (string) $user['phone']];
    }

    /**
     * L'inscription ne doit pas échouer si le SMS ne part pas : le client pourra redemander un code.
     *
     * @param array<string, mixed> $user
     *
     * @return array{sent: bool, retry_after?: int}
     */
    private function sendPhoneCodeSafely(array $user): array
    {
        try {
            $this->codes->issue((int) $user['id'], 'sms', $user['phone'], AuthCodeService::PURPOSE_PHONE_VERIFICATION);

            return ['sent' => true];
        } catch (ApiException $exception) {
            log_message('warning', '[Auth] Code de vérification non envoyé (utilisateur {id}) : {code}', [
                'id'   => $user['id'],
                'code' => $exception->getErrorCode(),
            ]);

            return ['sent' => false];
        } catch (Throwable $exception) {
            log_message('error', '[Auth] Échec d\'envoi du code de vérification : {class}', ['class' => $exception::class]);

            return ['sent' => false];
        }
    }

    private static function invalidCredentials(): ApiException
    {
        return ApiException::unauthorized('Identifiant ou mot de passe incorrect.', 'INVALID_CREDENTIALS');
    }
}
