<?php

declare(strict_types=1);

namespace Tests\Unit\Promotions;

use App\Common\Exceptions\ApiException;
use App\Modules\Promotions\Services\PromotionRules;
use CodeIgniter\Test\CIUnitTestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Règles d'application des codes promo et calcul de la remise (en centimes).
 *
 * @internal
 */
final class PromotionRulesTest extends CIUnitTestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function promo(array $overrides = []): array
    {
        return $overrides + [
            'restaurant_id'           => '7',
            'discount_type'           => 'percentage',
            'discount_value'          => '10.00',
            'minimum_order_amount'    => '0.00',
            'maximum_discount_amount' => null,
            'currency'                => 'USD',
            'usage_limit'             => null,
            'usage_per_customer'      => null,
            'starts_at'               => null,
            'ends_at'                 => null,
            'is_active'               => '1',
        ];
    }

    /**
     * @param array<string, mixed> $promo
     * @param array{total: int, customer: int} $usages
     */
    private static function discount(array $promo, int $subtotal = 2_000, array $usages = ['total' => 0, 'customer' => 0], int $restaurantId = 7, string $currency = 'USD'): int
    {
        return (new PromotionRules())->discount($promo, $restaurantId, $currency, $subtotal, $usages, new DateTimeImmutable('2026-12-01 12:00:00', new DateTimeZone('UTC')));
    }

    private function assertRejected(string $code, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Refus {$code} attendu.");
        } catch (ApiException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertSame($code, $exception->getErrorCode());
            $this->assertArrayHasKey('promo_code', $exception->getErrors());
        }
    }

    public function testPercentageIsRoundedHalfUpAndCapped(): void
    {
        $this->assertSame(200, self::discount(self::promo()));                                    // 10 % de 20.00
        $this->assertSame(16, self::discount(self::promo(['discount_value' => '15.00']), 105));    // 15 % de 1.05 = 0.1575 → 0.16
        $this->assertSame(15, self::discount(self::promo(['discount_value' => '15.00']), 99));     // 0.1485 → 0.15
        $this->assertSame(150, self::discount(self::promo(['discount_value' => '50.00', 'maximum_discount_amount' => '1.50'])));
        $this->assertSame(2_000, self::discount(self::promo(['discount_value' => '100.00'])));
    }

    public function testFixedDiscountNeverExceedsSubtotal(): void
    {
        $this->assertSame(300, self::discount(self::promo(['discount_type' => 'fixed', 'discount_value' => '3.00'])));
        $this->assertSame(250, self::discount(self::promo(['discount_type' => 'fixed', 'discount_value' => '5.00']), 250));
    }

    public function testPlatformCodeWithoutCurrencyAppliesEverywhere(): void
    {
        $platform = self::promo(['restaurant_id' => null, 'currency' => null]);

        $this->assertSame(200, self::discount($platform, restaurantId: 99));
        $this->assertSame(200, self::discount($platform, restaurantId: 99, currency: 'CDF'));
    }

    public function testEachRuleHasItsOwnRejection(): void
    {
        $this->assertRejected('PROMO_CODE_INVALID', static fn () => self::discount(self::promo(), restaurantId: 8));
        $this->assertRejected('PROMO_CODE_INACTIVE', static fn () => self::discount(self::promo(['is_active' => '0'])));
        $this->assertRejected('PROMO_CODE_NOT_STARTED', static fn () => self::discount(self::promo(['starts_at' => '2026-12-01 12:00:01'])));
        $this->assertRejected('PROMO_CODE_EXPIRED', static fn () => self::discount(self::promo(['ends_at' => '2026-12-01 11:59:59'])));
        $this->assertRejected('PROMO_CODE_CURRENCY_MISMATCH', static fn () => self::discount(self::promo(), currency: 'CDF'));
        $this->assertRejected('PROMO_MINIMUM_NOT_REACHED', static fn () => self::discount(self::promo(['minimum_order_amount' => '20.01'])));
        $this->assertRejected('PROMO_CODE_EXHAUSTED', static fn () => self::discount(self::promo(['usage_limit' => '5']), usages: ['total' => 5, 'customer' => 0]));
        $this->assertRejected('PROMO_CODE_ALREADY_USED', static fn () => self::discount(self::promo(['usage_per_customer' => '1']), usages: ['total' => 1, 'customer' => 1]));
    }

    public function testBoundariesAreInclusive(): void
    {
        $promo = self::promo([
            'starts_at'            => '2026-12-01 12:00:00',
            'ends_at'              => '2026-12-01 12:00:00',
            'minimum_order_amount' => '20.00',
            'usage_limit'          => '5',
            'usage_per_customer'   => '2',
        ]);

        $this->assertSame(200, self::discount($promo, usages: ['total' => 4, 'customer' => 1]));
    }

    public function testCodesAreNormalizedToUppercase(): void
    {
        $this->assertSame('BIENVENUE10', PromotionRules::normalizeCode('  bienvenue10 '));
    }
}
