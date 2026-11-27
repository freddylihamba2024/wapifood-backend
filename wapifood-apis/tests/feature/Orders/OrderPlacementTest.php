<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\OrderTestCase;

/**
 * Création de commande : recalcul serveur, snapshots, idempotence, contrôles métier.
 *
 * @internal
 */
final class OrderPlacementTest extends OrderTestCase
{
    public function testDeliveryOrderIsPricedServerSideAndSnapshotted(): void
    {
        $restaurant = $this->openRestaurant();
        $customer   = $this->customer();

        $line                 = self::burgerLine($restaurant['burger'], 2, 'Double', 'Bien cuit', ['Fromage', 'Bacon']);
        $line['unit_price']   = '0.01'; // ignoré : seul le catalogue fait foi
        $line['product_name'] = 'Gratuit';

        $response = $this->placeOrder($restaurant, $customer, [
            $line + ['note' => 'Sans oignon'],
            ['product_id' => $restaurant['fanta']['id'], 'quantity' => 3],
        ], ['total_amount' => '1.00', 'customer_note' => 'Sonnez deux fois', 'delivery_instructions' => 'Portail bleu']);

        $response->assertStatus(201);
        $order = $this->body($response)['data'];

        // Burger Double 11.50 + Fromage 1.00 + Bacon 1.50 = 14.00 × 2 = 28.00 ; Fanta 1.20 × 3 = 3.60
        $this->assertSame('31.60', $order['subtotal']);
        $this->assertSame('2.00', $order['delivery_fee']);
        $this->assertSame('33.60', $order['total_amount']);
        $this->assertSame('USD', $order['currency']);
        $this->assertSame('pending', $order['status']);
        $this->assertSame('unpaid', $order['payment_status']);
        $this->assertSame(5, $order['item_count']);
        $this->assertMatchesRegularExpression('/\AWF\d{6}-[A-HJ-KM-NP-Z2-9]{6}\z/', $order['order_number']);

        $this->assertSame('Burger', $order['items'][0]['product_name']);
        $this->assertSame('Double', $order['items'][0]['variant_name']);
        $this->assertSame('14.00', $order['items'][0]['unit_price']);
        $this->assertSame('Sans oignon', $order['items'][0]['note']);
        $this->assertSame(['Bien cuit', 'Fromage', 'Bacon'], array_column($order['items'][0]['options'], 'name'));

        $this->assertSame('45 avenue Kasa-Vubu', $order['delivery_address']['address_line']);
        $this->assertSame($customer['phone'], $order['delivery_address']['recipient_phone']);
        $this->assertSame('Portail bleu', $order['delivery_instructions']);
        $this->assertSame('Sonnez deux fois', $order['customer_note']);
        $this->assertSame('Test Utilisateur', $this->db->table('wf_orders')->where('id', $order['id'])->get()->getRow()->customer_name);

        $this->assertSame(['cash', 'pending', '33.60'], [$order['payment']['method'], $order['payment']['status'], $order['payment']['amount']]);
        $this->assertSame([['from' => null, 'to' => 'pending']], array_map(static fn (array $h): array => ['from' => $h['from'], 'to' => $h['to']], $order['status_history']));
        $this->assertSame(['cancelled'], $order['next_statuses']);
        $this->seeInDatabase('wf_audit_logs', ['action' => 'order.placed', 'entity_id' => $order['id']]);

        // Le snapshot ne bouge pas si le catalogue ou l'adresse changent ensuite.
        $this->db->table('wf_products')->where('id', $restaurant['burger']['id'])->update(['base_price' => '99.00', 'name' => 'Burger XXL']);
        $this->json('PUT', "api/v1/customer/addresses/{$customer['address_id']}", ['address_line' => 'Nouvelle adresse'], $customer['token'])->assertStatus(200);

        $again = $this->body($this->json('GET', "api/v1/customer/orders/{$order['id']}", [], $customer['token']))['data'];
        $this->assertSame(['Burger', '14.00'], [$again['items'][0]['product_name'], $again['items'][0]['unit_price']]);
        $this->assertSame('45 avenue Kasa-Vubu', $again['delivery_address']['address_line']);
        $this->assertSame('33.60', $again['total_amount']);
    }

    public function testQuoteComputesWithoutPersisting(): void
    {
        $restaurant = $this->openRestaurant(['free_delivery_threshold' => '10.00']);
        $customer   = $this->customer();

        $quote = $this->json('POST', 'api/v1/customer/orders/quote', [
            'restaurant_id' => $restaurant['restaurant_id'],
            'order_type'    => 'delivery',
            'delivery_address_id' => $customer['address_id'],
            'items'         => [self::burgerLine($restaurant['burger'], 1, 'Double')],
        ], $customer['token']);

        $quote->assertStatus(200);
        $data = $this->body($quote)['data'];
        $this->assertSame(['11.50', '0.00', '11.50'], [$data['subtotal'], $data['delivery_fee'], $data['total_amount']]);
        $this->assertSame('cash', $data['payment_method']);
        $this->assertSame(0, $this->db->table('wf_orders')->countAllResults());
    }

    public function testPickupOrderIgnoresAddressAndFee(): void
    {
        $restaurant = $this->openRestaurant();
        $customer   = $this->customer();

        $order = $this->body($this->placeOrder($restaurant, $customer, [['product_id' => $restaurant['fanta']['id'], 'quantity' => 1]], [
            'order_type' => 'pickup', 'delivery_instructions' => 'Ignorées',
        ]))['data'];

        $this->assertSame(['pickup', '0.00', '1.20'], [$order['order_type'], $order['delivery_fee'], $order['total_amount']]);
        $this->assertNull($order['delivery_address']);
        $this->assertNull($order['delivery_instructions']);
        $this->seeInDatabase('wf_orders', ['id' => $order['id'], 'delivery_address_id' => null]);
    }

    public function testIdempotencyKeyPreventsDuplicateOrders(): void
    {
        $restaurant = $this->openRestaurant();
        $customer   = $this->customer();
        $items      = [['product_id' => $restaurant['fanta']['id'], 'quantity' => 2]];

        $first = $this->placeOrder($restaurant, $customer, $items, [], 'panier-7f3a9c21');
        $first->assertStatus(201);

        $retry = $this->placeOrder($restaurant, $customer, $items, [], 'panier-7f3a9c21');
        $retry->assertStatus(200);
        $retry->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($this->body($first)['data']['id'], $this->body($retry)['data']['id']);
        $this->assertSame(1, $this->db->table('wf_orders')->countAllResults());
        $this->assertSame(1, $this->db->table('wf_payments')->countAllResults());

        // Même clé pour un autre restaurant : refus explicite.
        $other = $this->openRestaurant();
        $reuse = $this->placeOrder($other, $customer, [['product_id' => $other['fanta']['id'], 'quantity' => 1]], [], 'panier-7f3a9c21');
        $reuse->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_KEY_REUSED', $this->body($reuse)['meta']['code']);

        // La clé est propre au client : un autre client peut utiliser la même valeur.
        $bob = $this->customer();
        $this->placeOrder($restaurant, $bob, $items, [], 'panier-7f3a9c21')->assertStatus(201);

        // Sans clé, chaque envoi crée une commande ; clé mal formée refusée.
        $this->placeOrder($restaurant, $customer, $items)->assertStatus(201);
        $this->placeOrder($restaurant, $customer, $items, [], 'court')->assertStatus(422);
        $this->assertSame(3, $this->db->table('wf_orders')->countAllResults());
    }

    public function testRestaurantAvailabilityRules(): void
    {
        $customer = $this->customer();
        $items    = static fn (array $r): array => [['product_id' => $r['fanta']['id'], 'quantity' => 1]];

        $paused = $this->openRestaurant(['status' => 'paused']);
        $this->assertError($this->placeOrder($paused, $customer, $items($paused)), 409, 'RESTAURANT_CLOSED');

        foreach (['pending', 'suspended', 'rejected'] as $status) {
            $hidden = $this->openRestaurant(['status' => $status]);
            $this->assertError($this->placeOrder($hidden, $customer, $items($hidden)), 404, 'RESTAURANT_NOT_FOUND');
        }

        $closed = $this->openRestaurant();
        $this->db->table('wf_restaurant_opening_hours')->where('restaurant_id', $closed['restaurant_id'])->update(['is_closed' => 1]);
        $this->assertError($this->placeOrder($closed, $customer, $items($closed)), 409, 'RESTAURANT_CLOSED');

        $noHours = $this->openRestaurant();
        $this->db->table('wf_restaurant_opening_hours')->where('restaurant_id', $noHours['restaurant_id'])->delete();
        $this->assertError($this->placeOrder($noHours, $customer, $items($noHours)), 409, 'RESTAURANT_CLOSED');

        $deliveryOnly = $this->openRestaurant(['accepts_pickup' => 0]);
        $this->assertError($this->placeOrder($deliveryOnly, $customer, $items($deliveryOnly), ['order_type' => 'pickup']), 422, 'ORDER_TYPE_UNAVAILABLE');

        $pickupOnly = $this->openRestaurant(['accepts_delivery' => 0]);
        $this->assertError($this->placeOrder($pickupOnly, $customer, $items($pickupOnly)), 422, 'ORDER_TYPE_UNAVAILABLE');

        $minimum = $this->openRestaurant(['minimum_order_amount' => '5.00']);
        $this->assertError($this->placeOrder($minimum, $customer, $items($minimum)), 422, 'MINIMUM_ORDER_NOT_REACHED');

        $this->assertSame(0, $this->db->table('wf_orders')->countAllResults());
    }

    public function testOnlyCashIsAcceptedForNow(): void
    {
        $restaurant = $this->openRestaurant();
        $customer   = $this->customer();
        $items      = [['product_id' => $restaurant['fanta']['id'], 'quantity' => 1]];

        foreach (['mpesa', 'airtel_money', 'orange_money'] as $method) {
            $this->assertError($this->placeOrder($restaurant, $customer, $items, ['payment_method' => $method]), 422, 'PAYMENT_METHOD_UNAVAILABLE');
        }

        $this->placeOrder($restaurant, $customer, $items, ['payment_method' => 'paypal'])->assertStatus(422);
        $this->placeOrder($restaurant, $customer, $items, ['payment_method' => 'cash'])->assertStatus(201);
    }

    public function testItemsMustBelongToRestaurantAndBeOrderable(): void
    {
        $restaurant = $this->openRestaurant();
        $other      = $this->openRestaurant();
        $customer   = $this->customer();

        $foreign = $this->placeOrder($restaurant, $customer, [['product_id' => $other['fanta']['id'], 'quantity' => 1]]);
        $this->assertError($foreign, 422, 'ORDER_ITEMS_INVALID');
        $this->assertArrayHasKey('items.0.product_id', $this->body($foreign)['errors']);

        $this->db->table('wf_products')->where('id', $restaurant['fanta']['id'])->update(['is_available' => 0]);
        $this->assertError($this->placeOrder($restaurant, $customer, [['product_id' => $restaurant['fanta']['id'], 'quantity' => 1]]), 422, 'ORDER_ITEMS_INVALID');

        $this->db->table('wf_products')->where('id', $restaurant['fanta']['id'])->update(['is_available' => 1, 'deleted_at' => '2026-01-01 00:00:00']);
        $this->assertError($this->placeOrder($restaurant, $customer, [['product_id' => $restaurant['fanta']['id'], 'quantity' => 1]]), 422, 'ORDER_ITEMS_INVALID');

        // Variante désactivée, option désactivée, option obligatoire manquante
        $burger = $restaurant['burger'];
        $this->db->table('wf_product_variants')->where('id', $burger['variants'][1]['id'])->update(['is_active' => 0]);
        $this->assertError($this->placeOrder($restaurant, $customer, [self::burgerLine($burger, 1, 'Double')]), 422, 'ORDER_ITEMS_INVALID');

        $this->db->table('wf_product_options')->where('id', $burger['option_groups'][1]['options'][0]['id'])->update(['is_active' => 0]);
        $this->assertError($this->placeOrder($restaurant, $customer, [self::burgerLine($burger, 1, 'Simple', 'Saignant', ['Fromage'])]), 422, 'ORDER_ITEMS_INVALID');

        $noCooking = self::burgerLine($burger, 1, 'Simple', 'aucune');
        $this->assertError($this->placeOrder($restaurant, $customer, [$noCooking]), 422, 'ORDER_ITEMS_INVALID');

        // Format du panier
        foreach ([[], [['product_id' => $burger['id'], 'quantity' => 0]], [['product_id' => $burger['id'], 'quantity' => 100]], [['quantity' => 1]], [['product_id' => $burger['id'], 'quantity' => 1, 'option_ids' => 'x']]] as $items) {
            $this->assertError($this->placeOrder($restaurant, $customer, $items), 422, 'VALIDATION_FAILED');
        }

        $this->placeOrder($restaurant, $customer, [self::burgerLine($burger, 1, 'Simple', 'Saignant', ['Bacon'])])->assertStatus(201);
    }

    public function testDeliveryAddressMustBelongToCustomer(): void
    {
        $restaurant = $this->openRestaurant();
        $alice      = $this->customer();
        $bob        = $this->customer();
        $items      = [['product_id' => $restaurant['fanta']['id'], 'quantity' => 1]];

        $stolen = $this->placeOrder($restaurant, $alice, $items, ['delivery_address_id' => $bob['address_id']]);
        $this->assertError($stolen, 422, 'VALIDATION_FAILED');
        $this->assertArrayHasKey('delivery_address_id', $this->body($stolen)['errors']);

        $this->assertError($this->placeOrder($restaurant, $alice, $items, ['delivery_address_id' => null]), 422, 'VALIDATION_FAILED');

        $this->json('DELETE', "api/v1/customer/addresses/{$alice['address_id']}", [], $alice['token'])->assertStatus(204);
        $this->assertError($this->placeOrder($restaurant, $alice, $items), 422, 'VALIDATION_FAILED');
    }

    public function testExpectedTotalDetectsPriceChanges(): void
    {
        $restaurant = $this->openRestaurant();
        $customer   = $this->customer();
        $items      = [['product_id' => $restaurant['fanta']['id'], 'quantity' => 2]];

        // Le panier affiché indiquait 4.40 (ancien prix) ; le total actuel est 1.20 × 2 + 2.00 = 4.40 → on change le prix.
        $this->db->table('wf_products')->where('id', $restaurant['fanta']['id'])->update(['base_price' => '1.50']);

        $changed = $this->placeOrder($restaurant, $customer, $items, ['expected_total' => '4.40']);
        $this->assertError($changed, 409, 'PRICE_CHANGED');
        $this->assertSame(['expected_total' => '4.40', 'total_amount' => '5.00'], $this->body($changed)['errors']);
        $this->assertSame(0, $this->db->table('wf_orders')->countAllResults());

        // Nombre JSON accepté : 5 = 5.00
        $this->placeOrder($restaurant, $customer, $items, ['expected_total' => 5])->assertStatus(201);
    }

    public function testScheduledOrders(): void
    {
        $customer = $this->customer();
        $kinshasa = new DateTimeZone('Africa/Kinshasa');
        $tomorrow = (new DateTimeImmutable('now', $kinshasa))->modify('+1 day')->setTime(12, 30);

        // Fermé aujourd'hui, ouvert seulement demain de 11:00 à 15:00 (heure de Kinshasa).
        $restaurant = $this->openRestaurant();
        $this->db->table('wf_restaurant_opening_hours')->where('restaurant_id', $restaurant['restaurant_id'])->update(['is_closed' => 1]);
        $this->db->table('wf_restaurant_opening_hours')->where('restaurant_id', $restaurant['restaurant_id'])->where('day_of_week', (int) $tomorrow->format('N'))
            ->update(['is_closed' => 0, 'opens_at' => '11:00:00', 'closes_at' => '15:00:00']);
        $items = [['product_id' => $restaurant['fanta']['id'], 'quantity' => 1]];

        $this->assertError($this->placeOrder($restaurant, $customer, $items), 409, 'RESTAURANT_CLOSED');
        $this->assertError($this->placeOrder($restaurant, $customer, $items, ['requested_for' => $tomorrow->setTime(16, 0)->format(DATE_ATOM)]), 409, 'RESTAURANT_CLOSED');

        $order = $this->placeOrder($restaurant, $customer, $items, ['requested_for' => $tomorrow->format(DATE_ATOM)]);
        $order->assertStatus(201);
        $this->assertSame($tomorrow->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), $this->body($order)['data']['requested_for']);

        // Trop tôt (moins que le temps de préparation), trop tard, ou sans fuseau.
        $open = $this->openRestaurant();
        $soon = (new DateTimeImmutable('now', $kinshasa))->modify('+10 minutes');
        $far  = (new DateTimeImmutable('now', $kinshasa))->modify('+8 days');

        foreach ([$soon->format(DATE_ATOM), $far->format(DATE_ATOM), $tomorrow->format('Y-m-d\TH:i:s'), 'demain midi'] as $value) {
            $this->assertError($this->placeOrder($open, $customer, [['product_id' => $open['fanta']['id'], 'quantity' => 1]], ['requested_for' => $value]), 422, 'VALIDATION_FAILED');
        }
    }

    private function assertError(\CodeIgniter\Test\TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status);
        $this->assertSame($code, $this->body($response)['meta']['code'] ?? null, (string) $response->getJSON());
    }
}
