<?php

declare(strict_types=1);

namespace App\Modules\Payments\Providers;

/**
 * Événement de paiement authentifié, extrait d'une notification du fournisseur.
 */
final class PaymentWebhookEvent
{
    /**
     * @param string|null $eventId Identifiant unique de l'événement chez le fournisseur ;
     *                             à défaut, l'empreinte SHA-256 du corps brut sert de clé d'idempotence
     * @param 'successful'|'failed' $status
     * @param string $amount   Montant décimal (« 12.50 »)
     * @param array<string, mixed> $payload Corps décodé (stocké, sans secret)
     */
    public function __construct(
        public readonly ?string $eventId,
        public readonly string $merchantReference,
        public readonly ?string $providerTransactionId,
        public readonly string $status,
        public readonly string $amount,
        public readonly string $currency,
        public readonly array $payload = [],
    ) {
    }
}
