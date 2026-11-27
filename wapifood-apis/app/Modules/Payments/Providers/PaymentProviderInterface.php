<?php

declare(strict_types=1);

namespace App\Modules\Payments\Providers;

/**
 * Contrat d'un moyen de paiement (espèces, agrégateur Mobile Money…).
 *
 * Pour brancher un agrégateur plus tard :
 *   1. implémenter cette interface (appels HTTP avec délais courts, secrets lus dans .env) ;
 *   2. l'enregistrer dans Config\Services::paymentProviders() ;
 *   3. ajouter son code à Config\WapiFood::$enabledPaymentMethods.
 * Tant que le code n'est pas activé, le moyen de paiement est refusé à la commande et
 * ses notifications (webhooks) reçoivent un 404.
 */
interface PaymentProviderInterface
{
    /** Code stocké dans wf_payments.provider (cash, mpesa, airtel_money, orange_money). */
    public function code(): string;

    /**
     * Démarre le paiement, APRÈS l'enregistrement de la commande (jamais dans la transaction).
     * Mobile Money : demande de confirmation envoyée sur le téléphone du client.
     *
     * @param array<string, mixed> $payment Ligne wf_payments
     * @param array<string, mixed> $order   Ligne wf_orders
     */
    public function initiate(array $payment, array $order): PaymentInitiation;

    /** Le fournisseur notifie-t-il le résultat par webhook ? */
    public function supportsWebhooks(): bool;

    /**
     * Vérifie l'authenticité d'une notification (signature) et en extrait l'événement.
     *
     * @param array<string, string> $headers En-têtes HTTP, noms en minuscules
     *
     * @throws WebhookRejected Signature absente ou invalide, contenu illisible
     */
    public function parseWebhook(string $rawBody, array $headers): PaymentWebhookEvent;
}
