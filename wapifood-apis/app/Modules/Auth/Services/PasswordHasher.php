<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

/**
 * Hachage et politique des mots de passe.
 *
 * Argon2id si PHP le fournit, sinon bcrypt (PASSWORD_DEFAULT).
 * Les deux sont vérifiés par password_verify() : changer d'algorithme ne casse
 * aucun compte, needsRehash() permet la migration au fil des connexions.
 */
final class PasswordHasher
{
    public const MIN_LENGTH = 10;
    public const MAX_LENGTH = 128;

    /** @var array<string, int> */
    private const ARGON_OPTIONS = [
        'memory_cost' => 65536, // 64 Mo
        'time_cost'   => 4,
        'threads'     => 1,
    ];

    public function hash(string $password): string
    {
        return password_hash($password, $this->algorithm(), $this->options());
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm(), $this->options());
    }

    /**
     * Vérifie la robustesse d'un mot de passe.
     *
     * @return list<string> Messages d'erreur (liste vide = mot de passe accepté)
     */
    public function policyErrors(string $password, string ...$personalData): array
    {
        $errors = [];
        $length = mb_strlen($password);

        if ($length < self::MIN_LENGTH) {
            $errors[] = 'Le mot de passe doit contenir au moins ' . self::MIN_LENGTH . ' caractères.';
        }

        if ($length > self::MAX_LENGTH) {
            $errors[] = 'Le mot de passe ne doit pas dépasser ' . self::MAX_LENGTH . ' caractères.';
        }

        if (preg_match('/\p{Ll}/u', $password) !== 1 || preg_match('/\p{Lu}/u', $password) !== 1 || preg_match('/\d/', $password) !== 1) {
            $errors[] = 'Le mot de passe doit contenir au moins une minuscule, une majuscule et un chiffre.';
        }

        foreach ($personalData as $value) {
            $value = mb_strtolower(trim($value));

            if (mb_strlen($value) >= 4 && str_contains(mb_strtolower($password), $value)) {
                $errors[] = 'Le mot de passe ne doit pas contenir vos informations personnelles.';
                break;
            }
        }

        return $errors;
    }

    private function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    /**
     * @return array<string, int>
     */
    private function options(): array
    {
        return defined('PASSWORD_ARGON2ID') ? self::ARGON_OPTIONS : [];
    }
}
