<?php

declare(strict_types=1);

namespace App\Modules\Payments\Providers;

/**
 * Résultat du démarrage d'un paiement.
 */
final class PaymentInitiation
{
    /**
     * @param 'pending'|'failed'        $status
     * @param array<string, mixed>|null $rawResponse Réponse du fournisseur, sans aucun secret
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $providerTransactionId = null,
        public readonly ?array $rawResponse = null,
    ) {
    }
}
