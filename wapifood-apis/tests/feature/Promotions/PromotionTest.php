<?php

declare(strict_types=1);

namespace Tests\Feature\Promotions;

use App\Modules\Commissions\Services\FinanceService;
use CodeIgniter\Test\TestResponse;
use Tests\Support\OrderTestCase;

/**
 * Codes promo : gestion (restaurant, administration), application au panier, limites sous verrou,
 * effet sur la commission (D5) et crédit du restaurant pour un code plateforme (Ph7-1).
 *
 * @internal
 */
final class PromotionTest extends OrderTestCase
{
    /**
     * @param array<string, mixed> $restaurant
     * @param array<string, mixed> $body
     */
    private function restaurantPromo(array $restaurant, array $body = []): array
    {
        $response = $this->json('POST', 'api/v1/restaurant/promotions', $body + [
            'code'           => 'RESTO10',
            'title'          => '10 % chez nous',
            'discount_type'  => 'percentage',
            'discount_value' => '10',
        ], $restaurant['token']);
        $response->assertStatus(201);

        return $this->body($response)['data'];
    }

    /**
     * @param array<string, mixed> $restaurant
     * @param array<string, mixed> $customer
     */
    private function orderWith(array $restaurant, array $customer, ?string $code, int $fantas = 3): TestResponse
    {
        return $this->placeOrder($restaurant, $customer, [['product_id' => $restaurant['fanta']['id'], 'quantity' => $fantas]], $code === null ? [] : ['promo_code' => $code]);
    }

    /**
     * @param list<string> $statuses
     */
    private function advance(array $restaurant, int $orderId, array $statuses): void
    {
        foreach ($statuses as $status) {
            $this->json('PATCH', "api/v1/restaurant/orders/{$orderId}/status", ['status' => $status], $restaurant['token'])->assertStatus(200);
        }
    }

    private function errorCode(TestResponse $response): ?string
    {
        return $this->body($response)['meta']['code'] ?? null;
    }

    public function testRestaurantCodeReducesTotalAndCommissionBase(): void
    {
        $restaurant = $this->openRestaurant(['commission_rate' => '10.00']);
        $customer   = $this->customer();
        $promo      = $this->restaurantPromo($restaurant);

        $this->assertSame(['RESTO10', 'restaurant', 'USD', true, 0], [$promo['code'], $promo['scope'], $promo['currency'], $promo['is_active'], $promo['usage_count']]);

        // Devis : 3 × 1.20 = 3.60, remise 0.36, livraison 2.00 → 5.24. Saisie en minuscules acceptée.
        $quote = $this->body($this->json('POST', 'api/v1/customer/orders/quote', [
            'restaurant_id' => $restaurant['restaurant_id'], 'order_type' => 'delivery', 'delivery_address_id' => $customer['address_id'],
            'items' => [['product_id' => $restaurant['fanta']['id'], 'quantity' => 3]], 'promo_code' => 'resto10',
        ], $customer['token']))['data'];
        $this->assertSame(['3.60', '0.36', '2.00', '5.24'], [$quote['subtotal'], $quote['discount_amount'], $quote['delivery_fee'], $quote['total_amount']]);
        $this->assertSame(['code' => 'RESTO10', 'title' => '10 % chez nous', 'funded_by' => 'restaurant', 'discount_amount' => '0.36'], $quote['promotion']);

        $response = $this->orderWith($restaurant, $customer, 'RESTO10');
        $response->assertStatus(201);
        $order = $this->body($response)['data'];

        $this->assertSame(['0.36', '5.24'], [$order['discount_amount'], $order['total_amount']]);
        $this->assertSame('RESTO10', $order['promotion']['code']);
        $this->seeInDatabase('wf_promotion_usages', ['promotion_id' => $promo['id'], 'order_id' => $order['id'], 'user_id' => $customer['user_id'], 'discount_amount' => '0.36']);
        $this->seeInDatabase('wf_payments', ['order_id' => $order['id'], 'amount' => '5.24']);

        // Remise du restaurant : assiette 3.60 − 0.36 = 3.24 → commission 0.32.
        $this->seeInDatabase('wf_commissions', ['order_id' => $order['id'], 'order_amount' => '3.24', 'commission_amount' => '0.32']);

        $this->assertSame(1, $this->body($this->json('GET', "api/v1/restaurant/promotions/{$promo['id']}", [], $restaurant['token']))['data']['usage_count']);
    }

    public function testPlatformCodeIsFundedByWapiFood(): void
    {
        $admin      = $this->adminToken();
        $restaurant = $this->openRestaurant(['commission_rate' => '10.00']);
        $customer   = $this->customer();

        // Un montant sans devise n'a pas de sens (aucune conversion USD/CDF).
        $this->json('POST', 'api/v1/admin/promotions', ['code' => 'WAPI1', 'title' => 'Offert', 'discount_type' => 'fixed', 'discount_value' => '1.00'], $admin)->assertStatus(422);

        $promo = $this->body($this->json('POST', 'api/v1/admin/promotions', [
            'code' => 'WAPI1', 'title' => '1 $ offert', 'discount_type' => 'fixed', 'discount_value' => '1.00', 'currency' => 'USD',
        ], $admin))['data'];
        $this->assertSame(['platform', null], [$promo['scope'], $promo['restaurant_id']]);

        // Les codes plateforme ne sont pas visibles par les restaurants.
        $this->json('GET', "api/v1/restaurant/promotions/{$promo['id']}", [], $restaurant['token'])->assertStatus(404);

        $order = $this->body($this->orderWith($restaurant, $customer, 'WAPI1'))['data'];
        $this->assertSame(['1.00', '4.60', 'platform'], [$order['discount_amount'], $order['total_amount'], $order['promotion']['funded_by']]);

        // WapiFood absorbe la remise : la commission est calculée sur le sous-total entier.
        $this->seeInDatabase('wf_commissions', ['order_id' => $order['id'], 'order_amount' => '3.60', 'commission_amount' => '0.36']);

        $this->advance($restaurant, $order['id'], ['accepted', 'preparing', 'ready', 'out_for_delivery', 'completed']);

        // Commission due 0.36, crédit de la remise 1.00 → avoir de 0.64 en faveur du restaurant.
        $ledger = $this->body($this->json('GET', 'api/v1/restaurant/finance/ledger', [], $restaurant['token']))['data'];
        $this->assertSame([['platform_discount', '-1.00', '-0.64'], ['commission_accrued', '0.36', '0.36']], array_map(
            static fn (array $e): array => [$e['type'], $e['amount'], $e['balance_after']],
            $ledger,
        ));

        // Remboursement total : la commission et le crédit sont annulés, le solde revient à zéro.
        $this->json('POST', "api/v1/restaurant/orders/{$order['id']}/cash-payment", [], $restaurant['token'])->assertStatus(200);
        $this->json('POST', "api/v1/admin/orders/{$order['id']}/refunds", ['amount' => '4.60', 'reason' => 'Commande non conforme'], $admin)->assertStatus(201);

        $ledger = $this->body($this->json('GET', 'api/v1/restaurant/finance/ledger', [], $restaurant['token']))['data'];
        $this->assertSame(['platform_discount_reversed', '1.00', '0.00'], [$ledger[0]['type'], $ledger[0]['amount'], $ledger[0]['balance_after']]);
        $this->assertSame(['commission_reversed', '-0.36', '-1.00'], [$ledger[1]['type'], $ledger[1]['amount'], $ledger[1]['balance_after']]);
        $this->assertSame([], (new FinanceService())->inconsistencies());
    }

    public function testUsageLimitsAndReleaseOnCancellation(): void
    {
        $restaurant = $this->openRestaurant();
        $first      = $this->customer();
        $second     = $this->customer();
        $this->restaurantPromo($restaurant, ['code' => 'UNIQUE', 'usage_limit' => 1]);
        $this->restaurantPromo($restaurant, ['code' => 'UNEFOIS', 'usage_per_customer' => 1]);

        $order = $this->body($this->orderWith($restaurant, $first, 'UNIQUE'))['data'];

        $response = $this->orderWith($restaurant, $second, 'UNIQUE');
        $response->assertStatus(422);
        $this->assertSame('PROMO_CODE_EXHAUSTED', $this->errorCode($response));

        // Commande annulée : l'utilisation est rendue (Ph7-4), l'historique reste.
        $this->json('POST', "api/v1/customer/orders/{$order['id']}/cancel", [], $first['token'])->assertStatus(200);
        $this->orderWith($restaurant, $second, 'UNIQUE')->assertStatus(201);
        $this->seeNumRecords(2, 'wf_promotion_usages', []);

        // Limite par client.
        $this->orderWith($restaurant, $first, 'UNEFOIS')->assertStatus(201);
        $response = $this->orderWith($restaurant, $first, 'UNEFOIS');
        $this->assertSame('PROMO_CODE_ALREADY_USED', $this->errorCode($response));
        $this->orderWith($restaurant, $second, 'UNEFOIS')->assertStatus(201);
    }

    public function testInvalidCodesAreRejected(): void
    {
        $restaurant = $this->openRestaurant();
        $other      = $this->openRestaurant();
        $customer   = $this->customer();
        $this->restaurantPromo($other, ['code' => 'AILLEURS']);
        $inactive = $this->restaurantPromo($restaurant, ['code' => 'ENPAUSE']);
        $this->json('PATCH', "api/v1/restaurant/promotions/{$inactive['id']}/status", ['is_active' => false], $restaurant['token'])->assertStatus(200);
        $this->restaurantPromo($restaurant, ['code' => 'FINI', 'starts_at' => '2026-01-01T00:00:00Z', 'ends_at' => '2026-01-31T23:59:59Z']);
        $this->restaurantPromo($restaurant, ['code' => 'GROS', 'minimum_order_amount' => '50']);

        $expected = [
            'INCONNU'  => 'PROMO_CODE_INVALID',
            'AILLEURS' => 'PROMO_CODE_INVALID',
            'ENPAUSE'  => 'PROMO_CODE_INACTIVE',
            'FINI'     => 'PROMO_CODE_EXPIRED',
            'GROS'     => 'PROMO_MINIMUM_NOT_REACHED',
            'a b'      => 'PROMO_CODE_INVALID',
        ];

        foreach ($expected as $code => $error) {
            $response = $this->orderWith($restaurant, $customer, $code);
            $response->assertStatus(422);
            $this->assertSame($error, $this->errorCode($response), $code);
            $this->assertArrayHasKey('promo_code', $this->body($response)['errors']);
        }

        $this->seeNumRecords(0, 'wf_orders', ['customer_id' => $customer['user_id']]);
    }

    public function testPlatformCodeInAnotherCurrencyIsRejected(): void
    {
        $admin      = $this->adminToken();
        $restaurant = $this->openRestaurant();
        $customer   = $this->customer();
        $this->json('POST', 'api/v1/admin/promotions', ['code' => 'FRANCS', 'title' => 'CDF', 'discount_type' => 'fixed', 'discount_value' => '1000', 'currency' => 'CDF'], $admin)->assertStatus(201);
        $this->json('POST', 'api/v1/admin/promotions', ['code' => 'PARTOUT', 'title' => '5 %', 'discount_type' => 'percentage', 'discount_value' => '5'], $admin)->assertStatus(201);

        $this->assertSame('PROMO_CODE_CURRENCY_MISMATCH', $this->errorCode($this->orderWith($restaurant, $customer, 'FRANCS')));
        $this->orderWith($restaurant, $customer, 'PARTOUT')->assertStatus(201);
    }

    public function testManagementRules(): void
    {
        $restaurant = $this->openRestaurant();
        $customer   = $this->customer();
        $promo      = $this->restaurantPromo($restaurant);
        $body       = ['code' => 'RESTO10', 'title' => 'Titre', 'discount_type' => 'percentage', 'discount_value' => '10'];

        // Code unique sur la plateforme (insensible à la casse).
        $response = $this->json('POST', 'api/v1/restaurant/promotions', ['code' => 'resto10'] + $body, $restaurant['token']);
        $response->assertStatus(409);
        $this->assertSame('PROMO_CODE_TAKEN', $this->errorCode($response));

        $invalid = [
            ['discount_value' => '101'],
            ['discount_value' => '0'],
            ['code' => 'AB'],
            ['currency' => 'CDF'],
            ['discount_type' => 'fixed', 'discount_value' => '2', 'maximum_discount_amount' => '1'],
            ['starts_at' => '2026-12-10T00:00:00Z', 'ends_at' => '2026-12-01T00:00:00Z'],
            ['starts_at' => '10/12/2026'],
        ];

        foreach ($invalid as $index => $override) {
            $this->json('POST', 'api/v1/restaurant/promotions', array_merge($body, ['code' => 'NEW' . $index], $override), $restaurant['token'])->assertStatus(422);
        }

        // Avant toute utilisation : renommage et suppression possibles.
        $renamed = $this->body($this->json('PUT', "api/v1/restaurant/promotions/{$promo['id']}", ['code' => 'RESTO15', 'discount_value' => '15'] + $body, $restaurant['token']))['data'];
        $this->assertSame(['RESTO15', '15.00'], [$renamed['code'], $renamed['discount_value']]);

        $this->orderWith($restaurant, $customer, 'RESTO15')->assertStatus(201);

        // Après utilisation : ni renommage ni suppression ; désactivation possible.
        $this->assertSame('PROMOTION_IN_USE', $this->errorCode($this->json('PUT', "api/v1/restaurant/promotions/{$promo['id']}", ['code' => 'AUTRE'] + $body, $restaurant['token'])));
        $this->assertSame('PROMOTION_IN_USE', $this->errorCode($this->json('DELETE', "api/v1/restaurant/promotions/{$promo['id']}", [], $restaurant['token'])));
        $this->json('PUT', "api/v1/restaurant/promotions/{$promo['id']}", ['code' => 'RESTO15', 'title' => 'Nouveau titre'] + $body, $restaurant['token'])->assertStatus(200);

        $unused = $this->restaurantPromo($restaurant, ['code' => 'JETABLE']);
        $this->json('DELETE', "api/v1/restaurant/promotions/{$unused['id']}", [], $restaurant['token'])->assertStatus(204);

        $list = $this->body($this->json('GET', 'api/v1/restaurant/promotions?active=true', [], $restaurant['token']));
        $this->assertSame(['RESTO15'], array_column($list['data'], 'code'));
    }

    public function testPromotionPermissionsAndIsolation(): void
    {
        $restaurant = $this->openRestaurant();
        $other      = $this->openRestaurant();
        $promo      = $this->restaurantPromo($restaurant);

        // Le personnel (staff) ne gère pas les codes promo ; le manager oui.
        $staff   = $this->registerUser('customer');
        $manager = $this->registerUser('customer');
        $this->addMember($restaurant['restaurant_id'], (int) $staff['user']['id'], 'staff');
        $this->addMember($restaurant['restaurant_id'], (int) $manager['user']['id'], 'manager');

        $this->json('GET', 'api/v1/restaurant/promotions', [], $staff['tokens']['access_token'])->assertStatus(403);
        $this->json('GET', 'api/v1/restaurant/promotions', [], $manager['tokens']['access_token'])->assertStatus(200);

        // Un autre restaurant ne voit ni ne modifie le code.
        $this->json('GET', "api/v1/restaurant/promotions/{$promo['id']}", [], $other['token'])->assertStatus(404);
        $this->json('PATCH', "api/v1/restaurant/promotions/{$promo['id']}/status", ['is_active' => false], $other['token'])->assertStatus(404);

        // Codes plateforme : administration uniquement.
        $this->json('GET', 'api/v1/admin/promotions', [], $restaurant['token'])->assertStatus(403);
    }
}
