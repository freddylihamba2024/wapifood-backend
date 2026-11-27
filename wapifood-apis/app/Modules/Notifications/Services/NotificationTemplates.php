<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use InvalidArgumentException;

/**
 * Textes des notifications (français). Messages courts : ils servent aussi de SMS (≤ 160 caractères visés).
 *
 * Variables : {order}, {restaurant}, {reason}, {amount}, {currency}, {rating}.
 */
final class NotificationTemplates
{
    /** @var array<string, array{0: string, 1: string}> type → [titre, message] */
    private const TEMPLATES = [
        'order.placed'                => ['Nouvelle commande', 'Nouvelle commande {order} à traiter.'],
        'order.cancelled_by_customer' => ['Commande annulée', 'Le client a annulé la commande {order}.'],
        'order.accepted'              => ['Commande acceptée', '{restaurant} a accepté votre commande {order}.'],
        'order.rejected'              => ['Commande refusée', '{restaurant} a refusé votre commande {order} : {reason}'],
        'order.preparing'             => ['Commande en préparation', 'Votre commande {order} est en préparation.'],
        'order.ready'                 => ['Commande prête', 'Votre commande {order} est prête : vous pouvez la retirer chez {restaurant}.'],
        'order.out_for_delivery'      => ['Commande en route', 'Votre commande {order} est en route.'],
        'order.completed'             => ['Commande terminée', 'Commande {order} terminée. Merci ! Donnez votre avis sur {restaurant}.'],
        'order.cancelled'             => ['Commande annulée', '{restaurant} a annulé votre commande {order} : {reason}'],
        'order.delivery_failed'       => ['Livraison impossible', 'La livraison de votre commande {order} n\'a pas pu avoir lieu : {reason}'],
        'payment.refunded'            => ['Remboursement', 'Un remboursement de {amount} {currency} a été enregistré pour la commande {order}.'],
        'review.created'              => ['Nouvel avis', 'Nouvel avis {rating}/5 sur la commande {order}.'],
        'restaurant.approved'         => ['Restaurant validé', 'Bonne nouvelle : {restaurant} est validé et visible sur WapiFood.'],
        'restaurant.rejected'         => ['Inscription refusée', 'L\'inscription de {restaurant} a été refusée : {reason}'],
        'restaurant.suspended'        => ['Restaurant suspendu', '{restaurant} est suspendu : {reason}'],
        'restaurant.reactivated'      => ['Restaurant réactivé', '{restaurant} est de nouveau actif sur WapiFood.'],
    ];

    /**
     * @param array<string, scalar|null> $vars
     *
     * @return array{title: string, message: string}
     */
    public static function render(string $type, array $vars): array
    {
        [$title, $message] = self::TEMPLATES[$type] ?? throw new InvalidArgumentException("Modèle de notification inconnu : {$type}");

        $replace = [];

        foreach ($vars as $key => $value) {
            if (trim((string) $value) !== '') {
                $replace['{' . $key . '}'] = trim((string) $value);
            }
        }

        $message = strtr($message, $replace);

        // Variable absente (motif facultatif…) : on retire le reste du gabarit proprement.
        $message = (string) preg_replace(['/\s*:\s*\{[a-z]+\}/', '/\{[a-z]+\}/'], ['.', ''], $message);

        return ['title' => $title, 'message' => trim($message)];
    }

    public static function exists(string $type): bool
    {
        return isset(self::TEMPLATES[$type]);
    }
}
