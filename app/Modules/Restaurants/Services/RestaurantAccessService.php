<?php

declare(strict_types=1);

namespace App\Modules\Restaurants\Services;

use App\Common\Auth\RestaurantContext;
use App\Common\Exceptions\ApiException;
use CodeIgniter\Database\BaseConnection;
use Config\Permissions;

/**
 * Isolation multi-restaurants : détermine si un utilisateur peut agir dans un restaurant,
 * avec quel rôle local et quelles permissions.
 *
 * Source de vérité : wf_restaurant_users (membre actif) ; wf_restaurants.owner_user_id
 * vaut rôle « owner ». Un identifiant transmis par le client n'autorise jamais rien seul.
 */
final class RestaurantAccessService
{
    private BaseConnection $db;

    public function __construct(private readonly Permissions $permissions = new Permissions(), ?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /**
     * @param int|null $restaurantId Restaurant demandé ; null = l'unique restaurant de l'utilisateur
     *
     * @throws ApiException 403 RESTAURANT_ACCESS_DENIED | RESTAURANT_REQUIRED
     */
    public function resolve(int $userId, ?int $restaurantId): RestaurantContext
    {
        $memberships = $this->memberships($userId, $restaurantId);

        if ($restaurantId === null) {
            if (count($memberships) !== 1) {
                throw ApiException::forbidden(
                    $memberships === []
                        ? 'Vous n\'êtes membre d\'aucun restaurant.'
                        : 'Précisez le restaurant concerné (en-tête X-Restaurant-Id).',
                    $memberships === [] ? 'RESTAURANT_ACCESS_DENIED' : 'RESTAURANT_REQUIRED',
                );
            }
        } elseif ($memberships === []) {
            throw ApiException::forbidden('Accès refusé à ce restaurant.', 'RESTAURANT_ACCESS_DENIED');
        }

        $membership = $memberships[0];
        $role       = $membership['role'];

        return new RestaurantContext(
            (int) $membership['restaurant_id'],
            $role,
            $membership['restaurant_status'],
            $this->permissionsFor($role),
        );
    }

    /**
     * @return list<string>
     */
    public function permissionsFor(string $restaurantRole): array
    {
        return $this->permissions->restaurantRoles[$restaurantRole] ?? [];
    }

    /**
     * @return list<array{restaurant_id: int|string, role: string, restaurant_status: string}>
     */
    private function memberships(int $userId, ?int $restaurantId): array
    {
        // Rôle local actif, ou propriétaire déclaré du restaurant. Un restaurant supprimé est exclu.
        $sql = "SELECT r.id AS restaurant_id,
                       CASE WHEN r.owner_user_id = ? THEN 'owner' ELSE ru.restaurant_role END AS role,
                       r.status AS restaurant_status
                FROM wf_restaurants r
                LEFT JOIN wf_restaurant_users ru
                       ON ru.restaurant_id = r.id AND ru.user_id = ? AND ru.status = 'active'
                WHERE r.deleted_at IS NULL
                  AND (r.owner_user_id = ? OR ru.id IS NOT NULL)";

        $bindings = [$userId, $userId, $userId];

        if ($restaurantId !== null) {
            $sql .= ' AND r.id = ?';
            $bindings[] = $restaurantId;
        }

        $sql .= ' ORDER BY r.id LIMIT 2';

        return $this->db->query($sql, $bindings)->getResultArray();
    }
}
