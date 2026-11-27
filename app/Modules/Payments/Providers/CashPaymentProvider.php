<?php

declare(strict_types=1);

namespace App\Modules\Payments\Providers;

use LogicException;

/**
 * Paiement en espèces à la remise de la commande (seul moyen actif au MVP, décision D4).
 * Aucun appel externe : l'encaissement est confirmé par le restaurant (PaymentService::recordCash).
 */
final class CashPaymentProvider implements PaymentProviderInterface
{
    public function code(): string
    {
        return 'cash';
    }

    public function initiate(array $payment, array $order): PaymentInitiation
    {
        return new PaymentInitiation('pending');
    }

    public function supportsWebhooks(): bool
    {
        return false;
    }

    public function parseWebhook(string $rawBody, array $headers): PaymentWebhookEvent
    {
        throw new LogicException('Le paiement en espèces ne reçoit pas de notification.');
    }
}
