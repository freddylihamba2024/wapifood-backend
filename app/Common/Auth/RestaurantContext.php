<?php

declare(strict_types=1);

namespace App\Common\Auth;

/**
 * Restaurant dans lequel l'utilisateur agit pour la requête en cours,
 * avec son rôle local et ses permissions (résolus côté serveur).
 */
final class RestaurantContext
{
    /**
     * @param list<string> $permissions
     */
    public function __construct(
        public readonly int $restaurantId,
        public readonly string $restaurantRole,
        public readonly string $restaurantStatus,
        private readonly array $permissions,
    ) {
    }

    public function can(string $permission): bool
    {
        return in_array('*', $this->permissions, true) || in_array($permission, $this->permissions, true);
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return $this->permissions;
    }
}
