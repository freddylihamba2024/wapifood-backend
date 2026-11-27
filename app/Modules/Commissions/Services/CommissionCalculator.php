<?php

declare(strict_types=1);

namespace App\Modules\Commissions\Services;

use App\Common\Support\Money;
use InvalidArgumentException;

/**
 * Calcul de la commission WapiFood (décision D5), en centimes entiers.
 *
 *   assiette    = sous-total − remise financée par le restaurant (frais de livraison exclus)
 *   commission  = assiette × taux / 100, arrondie au centime le plus proche (0,5 → au-dessus)
 *   part resto  = assiette − commission
 *
 * Promotion financée par WapiFood (Phase 7) : elle ne réduit pas l'assiette (WapiFood absorbe la remise).
 */
final class CommissionCalculator
{
    /**
     * @return array{order_amount: string, commission_amount: string, restaurant_amount: string, commission_rate: string}
     */
    public function calculate(string $subtotal, string $restaurantDiscount, string $rate): array
    {
        if (preg_match('/\A\d{1,3}(\.\d{1,2})?\z/', $rate) !== 1 || Money::toCents($rate) > 10_000) {
            throw new InvalidArgumentException("Taux de commission invalide : « {$rate} ».");
        }

        $base       = max(0, Money::toCents($subtotal) - Money::toCents($restaurantDiscount));
        $rateBasis  = Money::toCents($rate); // 10.00 % → 1000 (centièmes de point)
        $commission = intdiv($base * $rateBasis + 5_000, 10_000);

        return [
            'order_amount'      => Money::fromCents($base),
            'commission_amount' => Money::fromCents($commission),
            'restaurant_amount' => Money::fromCents($base - $commission),
            'commission_rate'   => Money::normalize($rate),
        ];
    }
}
