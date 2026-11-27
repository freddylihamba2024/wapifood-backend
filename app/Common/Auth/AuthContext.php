<?php

declare(strict_types=1);

namespace App\Common\Auth;

use App\Common\Exceptions\ApiException;

/**
 * Utilisateur authentifié de la requête en cours, alimenté par JwtAuthFilter
 * (à partir de la base de données, jamais des seules données du jeton).
 *
 * Service partagé : service('authContext'). Réinitialisé à chaque requête par le filtre.
 */
final class AuthContext
{
    /** @var array<string, mixed>|null */
    private ?array $user = null;

    /** @var list<string> */
    private array $roles = [];

    private ?string $sessionId = null;

    private ?RestaurantContext $restaurant = null;

    /**
     * @param array<string, mixed> $user
     * @param list<string>         $roles
     */
    public function authenticate(array $user, array $roles, string $sessionId): void
    {
        $this->user       = $user;
        $this->roles      = $roles;
        $this->sessionId  = $sessionId;
        $this->restaurant = null;
    }

    public function clear(): void
    {
        $this->user       = null;
        $this->roles      = [];
        $this->sessionId  = null;
        $this->restaurant = null;
    }

    public function isAuthenticated(): bool
    {
        return $this->user !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function user(): array
    {
        return $this->user ?? throw ApiException::unauthorized();
    }

    public function userId(): int
    {
        return (int) $this->user()['id'];
    }

    public function sessionId(): string
    {
        return $this->sessionId ?? throw ApiException::unauthorized();
    }

    /**
     * @return list<string>
     */
    public function roles(): array
    {
        return $this->roles;
    }

    public function hasRole(string ...$roles): bool
    {
        return array_intersect($roles, $this->roles) !== [];
    }

    public function setRestaurant(RestaurantContext $restaurant): void
    {
        $this->restaurant = $restaurant;
    }

    /**
     * Restaurant résolu et autorisé par RestaurantAccessFilter.
     */
    public function restaurant(): RestaurantContext
    {
        return $this->restaurant ?? throw ApiException::forbidden('Aucun restaurant sélectionné.', 'RESTAURANT_REQUIRED');
    }
}
