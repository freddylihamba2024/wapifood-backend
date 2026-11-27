<?php

declare(strict_types=1);

namespace App\Controllers\API\V1\Auth;

use App\Common\Exceptions\ApiException;
use App\Controllers\API\BaseApiController;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\TokenService;
use App\Modules\Auth\Validators\AuthRules;
use App\Modules\Users\Services\ProfileService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Authentification : /api/v1/auth/*
 */
final class AuthController extends BaseApiController
{
    private const RESET_MESSAGE = 'Si un compte correspond à cet identifiant, un code de réinitialisation vient d\'être envoyé.';

    public function register(): ResponseInterface
    {
        $result = (new AuthService())->register($this->validatedInput(AuthRules::register()));

        return $this->created($result, 'Compte créé. Un code de vérification a été envoyé par SMS.');
    }

    public function login(): ResponseInterface
    {
        $input = $this->validatedInput(AuthRules::login());

        return $this->ok((new AuthService())->login($input['identifier'], $input['password']), 'Connexion réussie.');
    }

    public function refresh(): ResponseInterface
    {
        $input = $this->validatedInput(AuthRules::refresh());

        return $this->ok(['tokens' => (new TokenService())->refresh($input['refresh_token'])], 'Session renouvelée.');
    }

    public function logout(): ResponseInterface
    {
        $input = $this->validatedInput(AuthRules::logout());
        $all   = filter_var($input['all_devices'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($all === null) {
            throw ApiException::validation(['all_devices' => 'Le champ tous les appareils doit être un booléen.']);
        }

        (new AuthService())->logout($this->auth()->userId(), $this->auth()->sessionId(), $all);

        return $this->ok(null, $all ? 'Déconnexion de tous les appareils effectuée.' : 'Déconnexion effectuée.');
    }

    public function me(): ResponseInterface
    {
        return $this->ok((new AuthService())->me($this->auth()->userId()));
    }

    /** Modification du profil : prénom, nom, e-mail (le téléphone n'est pas modifiable ici). */
    public function updateProfile(): ResponseInterface
    {
        return $this->ok((new ProfileService())->update($this->auth()->userId(), $this->input()), 'Profil mis à jour.');
    }

    public function verifyPhone(): ResponseInterface
    {
        $input = $this->validatedInput(AuthRules::verifyCode());

        return $this->ok((new AuthService())->verifyPhone($this->auth()->userId(), $input['code']), 'Numéro de téléphone vérifié.');
    }

    public function resendPhoneCode(): ResponseInterface
    {
        (new AuthService())->resendPhoneVerification($this->auth()->userId());

        return $this->ok(null, 'Un nouveau code de vérification a été envoyé.');
    }

    public function forgotPassword(): ResponseInterface
    {
        $input = $this->validatedInput(AuthRules::forgotPassword());

        (new AuthService())->forgotPassword($input['identifier']);

        // Réponse identique que le compte existe ou non.
        return $this->ok(null, self::RESET_MESSAGE);
    }

    public function resetPassword(): ResponseInterface
    {
        $input = $this->validatedInput(AuthRules::resetPassword());

        (new AuthService())->resetPassword($input['identifier'], $input['code'], $input['password']);

        return $this->ok(null, 'Mot de passe réinitialisé. Connectez-vous avec votre nouveau mot de passe.');
    }

    public function changePassword(): ResponseInterface
    {
        $input  = $this->validatedInput(AuthRules::changePassword());
        $tokens = (new AuthService())->changePassword($this->auth()->userId(), $input['current_password'], $input['new_password']);

        return $this->ok(['tokens' => $tokens], 'Mot de passe modifié. Les autres sessions ont été fermées.');
    }

    public function sessions(): ResponseInterface
    {
        return $this->ok((new TokenService())->activeSessions($this->auth()->userId(), $this->auth()->sessionId()));
    }

    public function revokeSession(string $sessionId): ResponseInterface
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $sessionId) !== 1
            || ! (new TokenService())->revokeSession($this->auth()->userId(), $sessionId)) {
            throw ApiException::notFound('Session introuvable.');
        }

        return $this->noContent();
    }
}
