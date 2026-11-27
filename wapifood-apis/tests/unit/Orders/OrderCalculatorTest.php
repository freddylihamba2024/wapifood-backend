<?php

declare(strict_types=1);

namespace Tests\Unit\Orders;

use App\Common\Exceptions\ApiException;
use App\Modules\Orders\Services\OrderCalculator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Calcul serveur des commandes : prix, variantes, options, minimum, livraison.
 *
 * @internal
 */
final class OrderCalculatorTest extends CIUnitTestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function restaurant(array $overrides = []): array
    {
        return $overrides + [
            'currency'                => 'USD',
            'minimum_order_amount'    => '0.00',
            'delivery_fee'            => '2.00',
            'free_delivery_threshold' => null,
        ];
    }

    /**
     * Burger 8.50 : variantes Simple (0) / Double (+3.00) ; Cuisson obligatoire (single) ;
     * Suppléments facultatifs, 2 au maximum : Fromage +1.00, Bacon +1.50, Oignons +0.10.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function products(): array
    {
        return [
            10 => [
                'id' => 10, 'name' => 'Burger', 'base_price' => '8.50', 'currency' => 'USD',
                'details' => [
                    'variants' => [
                        ['id' => 100, 'name' => 'Simple', 'price_delta' => '0.00'],
                        ['id' => 101, 'name' => 'Double', 'price_delta' => '3.00'],
                    ],
                    'option_groups' => [
                        [
                            'id' => 200, 'name' => 'Cuisson', 'selection_type' => 'single', 'is_required' => '1',
                            'min_selections' => '1', 'max_selections' => '1',
                            'options' => [
                                ['id' => 300, 'name' => 'Saignant', 'price_delta' => '0.00'],
                                ['id' => 301, 'name' => 'Bien cuit', 'price_delta' => '0.00'],
                            ],
                        ],
                        [
                            'id' => 201, 'name' => 'Suppléments', 'selection_type' => 'multiple', 'is_required' => '0',
                            'min_selections' => '0', 'max_selections' => '2',
                            'options' => [
                                ['id' => 310, 'name' => 'Fromage', 'price_delta' => '1.00'],
                                ['id' => 311, 'name' => 'Bacon', 'price_delta' => '1.50'],
                                ['id' => 312, 'name' => 'Oignons', 'price_delta' => '0.10'],
                            ],
                        ],
                    ],
                ],
            ],
            20 => [
                'id' => 20, 'name' => 'Fanta', 'base_price' => '1.20', 'currency' => 'USD',
                'details' => ['variants' => [], 'option_groups' => []],
            ],
        ];
    }

    /**
     * @param list<int> $options
     *
     * @return array{product_id: int, quantity: int, variant_id: int|null, option_ids: list<int>, note: string|null}
     */
    private static function item(int $productId, int $quantity, ?int $variantId = null, array $options = []): array
    {
        return ['product_id' => $productId, 'quantity' => $quantity, 'variant_id' => $variantId, 'option_ids' => $options, 'note' => null];
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, string>
     */
    private function errorsOf(array $items, ?array $restaurant = null, string $type = 'pickup', ?array $products = null): array
    {
        try {
            (new OrderCalculator())->calculate($restaurant ?? self::restaurant(), $products ?? self::products(), $items, $type);
        } catch (ApiException $exception) {
            return $exception->getErrors() + ['_code' => (string) $exception->getErrorCode()];
        }

        $this->fail('Une erreur était attendue.');
    }

    public function testPricesFromCatalogWithVariantAndOptions(): void
    {
        $result = (new OrderCalculator())->calculate(self::restaurant(), self::products(), [
            // Double (8.50 + 3.00) + Fromage 1.00 + Oignons 0.10 = 12.60 × 2 = 25.20
            self::item(10, 2, 101, [300, 310, 312]),
            // 1.20 × 3 = 3.60 (0.1 + 0.2 ne pose aucun problème : calcul en centimes)
            self::item(20, 3),
        ], 'delivery');

        $this->assertSame('12.60', $result['lines'][0]['unit_price']);
        $this->assertSame('25.20', $result['lines'][0]['line_total']);
        $this->assertSame('Double', $result['lines'][0]['variant_name']);
        $this->assertSame(['Saignant', 'Fromage', 'Oignons'], array_column($result['lines'][0]['options'], 'name'));
        $this->assertSame('Cuisson', $result['lines'][0]['options'][0]['group']);
        $this->assertSame('3.60', $result['lines'][1]['line_total']);
        $this->assertSame('28.80', $result['subtotal']);
        $this->assertSame('2.00', $result['delivery_fee']);
        $this->assertSame('0.00', $result['discount_amount']);
        $this->assertSame('30.80', $result['total_amount']);
        $this->assertArrayNotHasKey('line_total_cents', $result['lines'][0]);
    }

    public function testPickupHasNoDeliveryFee(): void
    {
        $result = (new OrderCalculator())->calculate(self::restaurant(), self::products(), [self::item(20, 1)], 'pickup');

        $this->assertSame('0.00', $result['delivery_fee']);
        $this->assertSame('1.20', $result['total_amount']);
    }

    public function testFreeDeliveryFromThreshold(): void
    {
        $restaurant = self::restaurant(['free_delivery_threshold' => '3.60']);
        $calculator = new OrderCalculator();

        $this->assertSame('0.00', $calculator->calculate($restaurant, self::products(), [self::item(20, 3)], 'delivery')['delivery_fee']);
        $this->assertSame('2.00', $calculator->calculate($restaurant, self::products(), [self::item(20, 2)], 'delivery')['delivery_fee']);
    }

    public function testMinimumOrderAmountOnSubtotal(): void
    {
        $errors = $this->errorsOf([self::item(20, 1)], self::restaurant(['minimum_order_amount' => '5.00']));

        $this->assertSame('MINIMUM_ORDER_NOT_REACHED', $errors['_code']);
        $this->assertSame('5.00', $errors['minimum_order_amount']);
        $this->assertSame('1.20', $errors['subtotal']);
    }

    public function testUnknownProductOrOtherCurrencyIsRejected(): void
    {
        $products        = self::products();
        $products[20]['currency'] = 'CDF';

        try {
            (new OrderCalculator())->calculate(self::restaurant(), $products, [self::item(20, 1), self::item(99, 1)], 'pickup');
            $this->fail('Erreur attendue.');
        } catch (ApiException $exception) {
            $this->assertSame('ORDER_ITEMS_INVALID', $exception->getErrorCode());
            $this->assertSame(['items.0.product_id', 'items.1.product_id'], array_keys($exception->getErrors()));
        }
    }

    public function testVariantRules(): void
    {
        $this->assertArrayHasKey('items.0.variant_id', $this->errorsOf([self::item(10, 1, null, [300])]));
        $this->assertArrayHasKey('items.0.variant_id', $this->errorsOf([self::item(10, 1, 999, [300])]));
        $this->assertArrayHasKey('items.0.variant_id', $this->errorsOf([self::item(20, 1, 100)]));
    }

    public function testOptionRules(): void
    {
        // Groupe obligatoire sans choix
        $this->assertStringContainsString('Cuisson', $this->errorsOf([self::item(10, 1, 100)])['items.0.option_ids']);
        // Deux choix dans un groupe « single »
        $this->assertArrayHasKey('items.0.option_ids', $this->errorsOf([self::item(10, 1, 100, [300, 301])]));
        // Maximum dépassé (3 suppléments pour 2 autorisés)
        $this->assertStringContainsString('au plus 2', $this->errorsOf([self::item(10, 1, 100, [300, 310, 311, 312])])['items.0.option_ids']);
        // Option d'un autre produit, et doublon
        $this->assertArrayHasKey('items.0.option_ids', $this->errorsOf([self::item(10, 1, 100, [300, 999])]));
        $this->assertArrayHasKey('items.0.option_ids', $this->errorsOf([self::item(10, 1, 100, [300, 310, 310])]));
    }

    public function testOptionalGroupWithMinimumOnlyAppliesOnceUsed(): void
    {
        $products = self::products();
        $products[10]['details']['option_groups'][1]['min_selections'] = '2';
        $calculator = new OrderCalculator();

        $this->assertSame('8.50', $calculator->calculate(self::restaurant(), $products, [self::item(10, 1, 100, [300])], 'pickup')['subtotal']);

        try {
            $calculator->calculate(self::restaurant(), $products, [self::item(10, 1, 100, [300, 310])], 'pickup');
            $this->fail('Erreur attendue.');
        } catch (ApiException $exception) {
            $this->assertStringContainsString('au moins 2', $exception->getErrors()['items.0.option_ids']);
        }
    }

    public function testNegativeUnitPriceIsRejected(): void
    {
        $products = self::products();
        $products[10]['details']['variants'][0]['price_delta'] = '-9.00';

        $this->assertArrayHasKey('items.0.product_id', $this->errorsOf([self::item(10, 1, 100, [300])], products: $products));
    }
}
