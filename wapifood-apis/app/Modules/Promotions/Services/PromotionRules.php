<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Services;

use App\Common\Exceptions\ApiException;
use App\Common\Support\Money;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Règles d'application d'un code promo (règles pures, sans accès à la base).
 *
 * Ordre des contrôles : périmètre (restaurant) → actif → période → devise → minimum → limites.
 * Chaque refus est une erreur 422 avec un code dédié et le champ « promo_code ».
 *
 * Remise, en centimes entiers, calculée sur le sous-total (frais de livraison exclus) :
 *   percentage : sous-total × taux / 100, arrondi au centime le plus proche (0,5 → au-dessus),
 *                plafonnée à maximum_discount_amount s'il existe
 *   fixed      : discount_value
 *   dans tous les cas, jamais plus que le sous-total (le total ne devient jamais négatif).
 */
final class PromotionRules
{
    /**
     * @param array<string, mixed> $promotion Ligne wf_promotions
     * @param array{total: int, customer: int} $usages Utilisations comptées (commandes non refusées ni annulées)
     *
     * @return int Remise en centimes
     *
     * @throws ApiException 422 PROMO_CODE_*
     */
    public function discount(array $promotion, int $restaurantId, string $currency, int $subtotalCents, array $usages, ?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($promotion['restaurant_id'] !== null && (int) $promotion['restaurant_id'] !== $restaurantId) {
            throw self::rejected('Ce code promo n\'est pas valable dans ce restaurant.', 'PROMO_CODE_INVALID');
        }

        if (! filter_var($promotion['is_active'], FILTER_VALIDATE_BOOL)) {
            throw self::rejected('Ce code promo n\'est plus actif.', 'PROMO_CODE_INACTIVE');
        }

        if ($promotion['starts_at'] !== null && $now < self::utc((string) $promotion['starts_at'])) {
            throw self::rejected('Ce code promo n\'est pas encore valable.', 'PROMO_CODE_NOT_STARTED');
        }

        if ($promotion['ends_at'] !== null && $now > self::utc((string) $promotion['ends_at'])) {
            throw self::rejected('Ce code promo a expiré.', 'PROMO_CODE_EXPIRED');
        }

        if ($promotion['currency'] !== null && $promotion['currency'] !== $currency) {
            throw self::rejected('Ce code promo n\'est pas valable pour une commande en ' . $currency . '.', 'PROMO_CODE_CURRENCY_MISMATCH');
        }

        $minimum = Money::toCents((string) $promotion['minimum_order_amount']);

        if ($subtotalCents < $minimum) {
            throw new ApiException(
                'Le montant minimum pour utiliser ce code promo n\'est pas atteint.',
                422,
                ['promo_code' => 'Minimum : ' . Money::fromCents($minimum) . ' ' . $currency . ' (hors livraison).'],
                'PROMO_MINIMUM_NOT_REACHED',
            );
        }

        if ($promotion['usage_limit'] !== null && $usages['total'] >= (int) $promotion['usage_limit']) {
            throw self::rejected('Ce code promo a atteint son nombre maximal d\'utilisations.', 'PROMO_CODE_EXHAUSTED');
        }

        if ($promotion['usage_per_customer'] !== null && $usages['customer'] >= (int) $promotion['usage_per_customer']) {
            throw self::rejected('Vous avez déjà utilisé ce code promo le nombre de fois autorisé.', 'PROMO_CODE_ALREADY_USED');
        }

        return $this->amount($promotion, $subtotalCents);
    }

    /**
     * @param array<string, mixed> $promotion
     */
    public function amount(array $promotion, int $subtotalCents): int
    {
        $value = Money::toCents((string) $promotion['discount_value']);

        if ($promotion['discount_type'] === 'percentage') {
            // Taux en centièmes de point : 15.50 % → 1550.
            $discount = intdiv($subtotalCents * $value + 5_000, 10_000);
            $cap      = $promotion['maximum_discount_amount'] ?? null;

            if ($cap !== null && $cap !== '') {
                $discount = min($discount, Money::toCents((string) $cap));
            }
        } else {
            $discount = $value;
        }

        return max(0, min($discount, $subtotalCents));
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    private static function utc(string $datetime): DateTimeImmutable
    {
        return new DateTimeImmutable($datetime, new DateTimeZone('UTC'));
    }

    private static function rejected(string $message, string $code): ApiException
    {
        return new ApiException($message, 422, ['promo_code' => $message], $code);
    }
}
