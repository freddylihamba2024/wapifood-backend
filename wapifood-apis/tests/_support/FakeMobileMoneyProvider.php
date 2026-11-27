<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Payments\Providers\PaymentInitiation;
use App\Modules\Payments\Providers\PaymentProviderInterface;
use App\Modules\Payments\Providers\PaymentWebhookEvent;
use App\Modules\Payments\Providers\WebhookRejected;

/**
 * Agrégateur Mobile Money simulé (tests uniquement) : signature HMAC-SHA256 du corps brut
 * dans l'en-tête X-Signature. Aucun adaptateur réel n'existe tant qu'aucun agrégateur n'est choisi.
 *
 * @internal
 */
final class FakeMobileMoneyProvider implements PaymentProviderInterface
{
    public const SECRET = 'secret-de-test';

    public function __construct(private readonly bool $failInitiation = false)
    {
    }

    public function code(): string
    {
        return 'mpesa';
    }

    public function initiate(array $payment, array $order): PaymentInitiation
    {
        if ($this->failInitiation) {
            throw new \RuntimeException('Agrégateur injoignable');
        }

        return new PaymentInitiation('pending', 'TX-' . $payment['merchant_reference'], ['accepted' => true]);
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function parseWebhook(string $rawBody, array $headers): PaymentWebhookEvent
    {
        if (! hash_equals(self::sign($rawBody), $headers['x-signature'] ?? '')) {
            throw new WebhookRejected('Signature invalide');
        }

        $data = json_decode($rawBody, true);

        if (! is_array($data) || ! isset($data['reference'], $data['status'], $data['amount'], $data['currency'])) {
            throw new WebhookRejected('Contenu illisible');
        }

        return new PaymentWebhookEvent($data['event_id'] ?? null, $data['reference'], $data['transaction_id'] ?? null, $data['status'], (string) $data['amount'], $data['currency'], $data);
    }

    public static function sign(string $body): string
    {
        return hash_hmac('sha256', $body, self::SECRET);
    }
}
