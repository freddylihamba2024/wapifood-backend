<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Matrice des permissions par rôle local de restaurant (wf_restaurant_users.restaurant_role).
 *
 * Contrôlée exclusivement côté serveur (RestaurantAccessFilter / RestaurantAccessService).
 * Le joker « * » accorde toutes les permissions du restaurant concerné, et seulement de celui-ci.
 */
class Permissions extends BaseConfig
{
    /** @var array<string, list<string>> */
    public array $restaurantRoles = [
        'owner' => ['*'],
        'manager' => [
            'restaurant.view',
            'restaurant.update',
            'staff.view',
            'catalog.view',
            'catalog.manage',
            'catalog.availability',
            'orders.view',
            'orders.manage',
            'deliveries.manage',
            'promotions.manage',
            'reports.view',
            'payments.record',
        ],
        'staff' => [
            'restaurant.view',
            'catalog.view',
            'catalog.availability',
            'orders.view',
            'orders.manage',
            'deliveries.manage',
            'payments.record',
        ],
        'cashier' => [
            'restaurant.view',
            'catalog.view',
            'orders.view',
            'payments.record',
        ],
    ];

    /**
     * Permissions réservées au propriétaire (non déléguables via « manager »),
     * listées ici pour documentation : finances et gestion de l'équipe.
     *
     * @var list<string>
     */
    public array $ownerOnly = [
        'staff.manage',
        'finance.view',
        'payouts.request',
        'restaurant.settings',
    ];
}
