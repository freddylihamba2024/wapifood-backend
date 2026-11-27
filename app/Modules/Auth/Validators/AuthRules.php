<?php

declare(strict_types=1);

namespace App\Modules\Auth\Validators;

/**
 * Règles de validation des requêtes d'authentification (format et tailles).
 * Les règles métier (politique de mot de passe, unicité, normalisation du téléphone)
 * sont appliquées dans les services.
 */
final class AuthRules
{
    private const PASSWORD = 'required|string|max_length[128]';
    private const IDENTIFIER = 'required|string|max_length[190]';

    /**
     * @return array<string, array{label: string, rules: string}>
     */
    public static function register(): array
    {
        return [
            'first_name'   => ['label' => 'prénom', 'rules' => 'required|string|max_length[100]'],
            'last_name'    => ['label' => 'nom', 'rules' => 'permit_empty|string|max_length[100]'],
            'email'        => ['label' => 'e-mail', 'rules' => 'permit_empty|valid_email|max_length[190]'],
            'phone'        => ['label' => 'téléphone', 'rules' => 'required|string|max_length[30]'],
            'password'     => ['label' => 'mot de passe', 'rules' => self::PASSWORD],
            'account_type' => ['label' => 'type de compte', 'rules' => 'required|in_list[customer,restaurant_owner]'],
        ];
    }

    /**
     * @return array<string, array{label: string, rules: string}>
     */
    public static function login(): array
    {
        return [
            'identifier' => ['label' => 'identifiant', 'rules' => self::IDENTIFIER],
            'password'   => ['label' => 'mot de passe', 'rules' => self::PASSWORD],
        ];
    }

    /**
     * @return array<string, array{label: string, rules: string}>
     */
    public static function refresh(): array
    {
        return [
            'refresh_token' => ['label' => 'refresh token', 'rules' => 'required|string|max_length[255]'],
        ];
    }

    /**
     * @return array<string, array{label: string, rules: string}>
     */
    public static function logout(): array
    {
        return [
            // Booléen JSON ou "true"/"false"/"1"/"0" : vérifié par AuthController::logout().
            'all_devices' => ['label' => 'tous les appareils', 'rules' => 'permit_empty'],
        ];
    }

    /**
     * @return array<string, array{label: string, rules: string}>
     */
    public static function forgotPassword(): array
    {
        return [
            'identifier' => ['label' => 'identifiant', 'rules' => self::IDENTIFIER],
        ];
    }

    /**
     * @return array<string, array{label: string, rules: string}>
     */
    public static function resetPassword(): array
    {
        return [
            'identifier' => ['label' => 'identifiant', 'rules' => self::IDENTIFIER],
            'code'       => ['label' => 'code', 'rules' => 'required|string|regex_match[/\A\d{4,8}\z/]'],
            'password'   => ['label' => 'mot de passe', 'rules' => self::PASSWORD],
        ];
    }

    /**
     * @return array<string, array{label: string, rules: string}>
     */
    public static function verifyCode(): array
    {
        return [
            'code' => ['label' => 'code', 'rules' => 'required|string|regex_match[/\A\d{4,8}\z/]'],
        ];
    }

    /**
     * @return array<string, array{label: string, rules: string}>
     */
    public static function changePassword(): array
    {
        return [
            'current_password' => ['label' => 'mot de passe actuel', 'rules' => self::PASSWORD],
            'new_password'     => ['label' => 'nouveau mot de passe', 'rules' => self::PASSWORD],
        ];
    }
}
