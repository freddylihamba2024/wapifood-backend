<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Common\Exceptions\ApiException;

/**
 * Cycle de vie d'une commande (règles pures, sans accès à la base).
 *
 *   pending ──► accepted ──► preparing ──► ready ──► out_for_delivery ──► completed   (livraison)
 *      │            │                        └──────────────────────────► completed   (retrait)
 *      ├──► rejected (restaurant)
 *      └──► cancelled (client ou restaurant)  ◄── accepted (restaurant uniquement)
 *
 * - Le client ne peut qu'annuler une commande encore en attente.
 * - Le restaurant fait avancer la commande ; il refuse (pending) ou annule (accepted) avec un motif.
 * - Une commande en préparation ne peut plus être annulée : le litige relève du support (Phase 9).
 * - Exception : une LIVRAISON ÉCHOUÉE annule la commande partie (out_for_delivery → cancelled). Cette
 *   transition n'est pas proposée ici ; elle passe uniquement par OrderService::failDeliveryByRestaurant.
 * - completed, cancelled et rejected sont définitifs.
 */
final class OrderStateMachine
{
    public const ACTOR_CUSTOMER   = 'customer';
    public const ACTOR_RESTAURANT = 'restaurant';

    public const STATUSES = ['pending', 'accepted', 'preparing', 'ready', 'out_for_delivery', 'completed', 'cancelled', 'rejected'];

    public const FINAL_STATUSES = ['completed', 'cancelled', 'rejected'];

    /** Statuts « en cours » côté restaurant (file de travail). */
    public const ACTIVE_STATUSES = ['pending', 'accepted', 'preparing', 'ready', 'out_for_delivery'];

    /** @var array<string, array<string, list<string>>> acteur → statut actuel → statuts suivants */
    private const TRANSITIONS = [
        self::ACTOR_CUSTOMER => [
            'pending' => ['cancelled'],
        ],
        self::ACTOR_RESTAURANT => [
            'pending'          => ['accepted', 'rejected'],
            'accepted'         => ['preparing', 'cancelled'],
            'preparing'        => ['ready'],
            'ready'            => ['out_for_delivery', 'completed'],
            'out_for_delivery' => ['completed'],
        ],
    ];

    /**
     * Statuts accessibles depuis $status pour cet acteur, selon le type de commande.
     *
     * @return list<string>
     */
    public function nextStatuses(string $status, string $orderType, string $actor): array
    {
        $next = self::TRANSITIONS[$actor][$status] ?? [];

        if ($status === 'ready') {
            // Livraison : la commande part en livraison. Retrait : le client la récupère au comptoir.
            $next = array_values(array_intersect($next, [$orderType === 'delivery' ? 'out_for_delivery' : 'completed']));
        }

        return $next;
    }

    public function canTransition(string $from, string $to, string $orderType, string $actor): bool
    {
        return in_array($to, $this->nextStatuses($from, $orderType, $actor), true);
    }

    /**
     * @throws ApiException 409 INVALID_STATUS_TRANSITION
     */
    public function assertTransition(string $from, string $to, string $orderType, string $actor): void
    {
        if (! $this->canTransition($from, $to, $orderType, $actor)) {
            throw ApiException::conflict(
                "La commande ne peut pas passer du statut « {$from} » au statut « {$to} ».",
                ['current_status' => $from, 'allowed' => $this->nextStatuses($from, $orderType, $actor)],
                'INVALID_STATUS_TRANSITION',
            );
        }
    }

    /** Le restaurant doit motiver un refus ou une annulation (le motif est affiché au client). */
    public function requiresReason(string $to, string $actor): bool
    {
        return $actor === self::ACTOR_RESTAURANT && in_array($to, ['rejected', 'cancelled'], true);
    }

    public function isFinal(string $status): bool
    {
        return in_array($status, self::FINAL_STATUSES, true);
    }
}
