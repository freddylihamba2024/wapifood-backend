<?php

declare(strict_types=1);

namespace App\Modules\Payments\Providers;

/**
 * Moyens de paiement disponibles : implémentés ET activés (Config\WapiFood::$enabledPaymentMethods).
 * Service partagé : service('paymentProviders').
 */
final class PaymentProviderRegistry
{
    /** @var array<string, PaymentProviderInterface> */
    private array $providers = [];

    /**
     * @param list<PaymentProviderInterface> $providers
     * @param list<string>                   $enabled
     */
    public function __construct(array $providers, private readonly array $enabled)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->code()] = $provider;
        }
    }

    /** Moyen de paiement utilisable, ou null s'il n'est pas implémenté ou pas activé. */
    public function get(string $code): ?PaymentProviderInterface
    {
        return in_array($code, $this->enabled, true) ? ($this->providers[$code] ?? null) : null;
    }

    /**
     * @return list<string> Codes utilisables
     */
    public function available(): array
    {
        return array_values(array_filter($this->enabled, fn (string $code): bool => isset($this->providers[$code])));
    }
}
