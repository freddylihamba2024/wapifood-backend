<?php

declare(strict_types=1);

namespace App\Modules\Users\Presenters;

/**
 * Représentation publique d'un utilisateur : liste blanche de champs.
 * password_hash, token_version, compteurs d'échecs… ne sortent jamais de l'API.
 */
final class UserPresenter
{
    /**
     * @param array<string, mixed> $user
     * @param list<string>         $roles
     *
     * @return array<string, mixed>
     */
    public static function present(array $user, array $roles): array
    {
        return [
            'id'                => (int) $user['id'],
            'first_name'        => $user['first_name'],
            'last_name'         => $user['last_name'],
            'email'             => $user['email'],
            'phone'             => $user['phone'],
            'avatar_url'        => $user['avatar_url'],
            'status'            => $user['status'],
            'phone_verified'    => $user['phone_verified_at'] !== null,
            'email_verified'    => $user['email_verified_at'] !== null,
            'roles'             => $roles,
            'created_at'        => self::isoDate($user['created_at']),
            'last_login_at'     => self::isoDate($user['last_login_at']),
        ];
    }

    /** Dates stockées en UTC → ISO 8601 avec « Z ». */
    public static function isoDate(?string $datetime): ?string
    {
        return $datetime === null ? null : str_replace(' ', 'T', $datetime) . 'Z';
    }
}
