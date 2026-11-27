<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Notifications : événements, canaux et reprise sur échec.
 *
 * Canaux :
 * - in_app   : toujours actif, enregistré immédiatement (aucun envoi) ;
 * - email    : actif quand l'expéditeur et le serveur SMTP sont renseignés (`email.*` dans .env) ;
 * - sms      : actif quand SMS_PROVIDER désigne un fournisseur implémenté ;
 * - whatsapp : actif quand WHATSAPP_PROVIDER désigne un fournisseur implémenté.
 *
 * Aucun fournisseur SMS ni WhatsApp n'est choisi : seul « log » existe (développement, refusé en
 * production), qui écrit le message dans writable/logs. Un canal inactif est ignoré sans bloquer
 * les autres ni l'action métier.
 */
class Notifications extends BaseConfig
{
    /** Fournisseur SMS (vide = canal désactivé). Valeurs implémentées : log. */
    public string $smsProvider = '';

    /** Fournisseur WhatsApp (vide = canal désactivé). Valeurs implémentées : log. */
    public string $whatsappProvider = '';

    /** Tentatives d'envoi avant l'abandon (statut « failed »). */
    public int $maxAttempts = 5;

    /**
     * Délai avant chaque nouvelle tentative, en minutes (la dernière valeur sert au-delà).
     *
     * @var list<int>
     */
    public array $retryDelaysMinutes = [1, 5, 15, 60];

    /** Durée du verrou de traitement d'une notification, en secondes. */
    public int $lockSeconds = 300;

    /** Nombre maximal de notifications traitées par exécution de `notifications:process`. */
    public int $batchSize = 100;

    /**
     * Événement → destinataire → canaux. Destinataires :
     *   customer   le client de la commande
     *   restaurant l'équipe du restaurant (in-app partagé) et le téléphone / e-mail du restaurant
     *   owner      le propriétaire du restaurant (compte)
     *
     * Les SMS sont réservés aux moments où le client doit agir ou être prévenu (coût par message).
     *
     * @var array<string, array<string, list<string>>>
     */
    public array $events = [
        'order.placed'               => ['restaurant' => ['in_app', 'sms']],
        'order.cancelled_by_customer' => ['restaurant' => ['in_app']],
        'order.accepted'             => ['customer' => ['in_app', 'sms']],
        'order.rejected'             => ['customer' => ['in_app', 'sms']],
        'order.preparing'            => ['customer' => ['in_app']],
        'order.ready'                => ['customer' => ['in_app', 'sms']],
        'order.out_for_delivery'     => ['customer' => ['in_app', 'sms']],
        'order.completed'            => ['customer' => ['in_app']],
        'order.cancelled'            => ['customer' => ['in_app', 'sms']],
        'order.delivery_failed'      => ['customer' => ['in_app', 'sms']],
        'payment.refunded'           => ['customer' => ['in_app', 'sms']],
        'review.created'             => ['restaurant' => ['in_app']],
        'restaurant.approved'        => ['owner' => ['in_app', 'email', 'sms']],
        'restaurant.rejected'        => ['owner' => ['in_app', 'email', 'sms']],
        'restaurant.suspended'       => ['owner' => ['in_app', 'email', 'sms']],
        'restaurant.reactivated'     => ['owner' => ['in_app', 'email']],
    ];

    public function __construct()
    {
        parent::__construct();

        $this->smsProvider      = strtolower(trim((string) (env('SMS_PROVIDER') ?? $this->smsProvider)));
        $this->whatsappProvider = strtolower(trim((string) (env('WHATSAPP_PROVIDER') ?? $this->whatsappProvider)));
    }

    /** Délai avant la tentative suivant la n-ième (1, 2, …), en secondes. */
    public function retryDelaySeconds(int $attempt): int
    {
        $delays = $this->retryDelaysMinutes === [] ? [5] : $this->retryDelaysMinutes;

        return 60 * $delays[min(max(1, $attempt), count($delays)) - 1];
    }
}
