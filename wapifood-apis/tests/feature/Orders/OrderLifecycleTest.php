<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use CodeIgniter\Test\TestResponse;
use Tests\Support\OrderTestCase;

/**
 * Cycle de vie des commandes, annulation, isolation client/restaurant et permissions.
 *
 * @internal
 */
final class OrderLifecycleTest extends OrderTestCase
{
    /**
     * @param array<string, mixed> $restaurant
     * @param array<string, mixed> $customer
     *
     * @return array<string, mixed>
     */
    private function order(array $restaurant, array $customer, string $type = 'delivery'): array
    {
        $response = $this->placeOrder($restaurant, $customer, [['product_id' => $restaurant['fanta']['id'], 'quantity' => 1]], ['order_type' => $type]);
        $response->assertStatus(201);

        return $this->body($response)['data'];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setStatus(string $token, int $orderId, array $body): TestResponse
    {
        return $this->json('PATCH', "api/v1/restaurant/orders/{$orderId}/status", $body, $token);
    }

    public function testDeliveryOrderFullLifecycle(): void
    {
        $restaurant = $this->openRestaurant();
        $customer   = $this->customer();
        $order      = $this->order($restaurant, $customer);

        $detail = $this->body($this->json('GET', "api/v1/restaurant/orders/{$order['id']}", [], $restaurant['token']))['data'];
        $this->assertSame(['accepted', 'rejected'], $detail['next_statuses']);
        $this->assertSame(['name' => 'Test Utilisateur', 'phone' => $customer['phone']], $detail['customer']);

        foreach (['accepted', 'preparing', 'ready', 'out_for_delivery', 'completed'] as $status) {
            $response = $this->setStatus($restaurant['token'], $order['id'], ['status' => $status]);
            $response->assertStatus(200);
            $this->assertSame($status, $this->body($response)['data']['status']);
        }

        $final = $this->body($this->json('GET', "api/v1/customer/orders/{$order['id']}", [], $customer['token']))['data'];
        $this->assertSame('completed', $final['status']);
        $this->assertNotNull($final['accepted_at']);
        $this->assertNotNull($final['completed_at']);
        $this->assertNull($final['cancelled_at']);
        $this->assertSame([], $final['next_statuses']);
        $this->assertSame(['pending', 'accepted', 'preparing', 'ready', 'out_for_delivery', 'completed'], array_column($final['status_history'], 'to'));
        $this->assertSame(5, $this->db->table('wf_audit_logs')->where('action', 'order.status_changed')->where('entity_id', $order['id'])->countAllResults());

        // Le paiement en espèces reste à confirmer (Phase 6) : la livraison ne le marque pas payé.
        $this->assertSame(['unpaid', 'pending'], [$final['payment_status'], $final['payment']['status']]);

        $this->assertError($this->setStatus($restaurant['token'], $order['id'], ['status' => 'cancelled', 'reason' => 'Trop tard']), 409, 'INVALID_STATUS_TRANSITION');
    }

    public function testPickupOrderIsHandedOverWithoutDelivery(): void
    {
        $restaurant = $this->openRestaurant();
        $order      = $this->order($restaurant, $this->customer(), 'pickup');

        foreach (['accepted', 'preparing', 'ready'] as $status) {
            $this->setStatus($restaurant['token'], $order['id'], ['status' => $status])->assertStatus(200);
        }

        $this->assertError($this->setStatus($restaurant['token'], $order['id'], ['status' => 'out_for_delivery']), 409, 'INVALID_STATUS_TRANSITION');
        $this->setStatus($restaurant['token'], $order['id'], ['status' => 'completed'])->assertStatus(200);
    }

    public function testInvalidTransitionsAreRejected(): void
    {
        $restaurant = $this->openRestaurant();
        $order      = $this->order($restaurant, $this->customer());

        $skip = $this->setStatus($restaurant['token'], $order['id'], ['status' => 'ready']);
        $this->assertError($skip, 409, 'INVALID_STATUS_TRANSITION');
        $this->assertSame(['accepted', 'rejected'], $this->body($skip)['errors']['allowed']);

        $this->setStatus($restaurant['token'], $order['id'], ['status' => 'unknown'])->assertStatus(422);
        $this->setStatus($restaurant['token'], $order['id'], [])->assertStatus(422);
        $this->seeInDatabase('wf_orders', ['id' => $order['id'], 'status' => 'pending']);
    }

    public function testRejectionRequiresReasonAndCancelsPayment(): void
    {
        $restaurant = $this->openRestaurant();
        $customer   = $this->customer();
        $order      = $this->order($restaurant, $customer);

        $noReason = $this->setStatus($restaurant['token'], $order['id'], ['status' => 'rejected', 'reason' => '  ']);
        $this->assertError($noReason, 422, 'VALIDATION_FAILED');
        $this->assertArrayHasKey('reason', $this->body($noReason)['errors']);

        $this->setStatus($restaurant['token'], $order['id'], ['status' => 'rejected', 'reason' => 'Rupture de stock'])->assertStatus(200);

        $seen = $this->body($this->json('GET', "api/v1/customer/orders/{$order['id']}", [], $customer['token']))['data'];
        $this->assertSame(['rejected', 'Rupture de stock'], [$seen['status'], $seen['cancellation_reason']]);
        $this->assertNotNull($seen['cancelled_at']);
        $this->assertSame('cancelled', $seen['payment']['status']);
        $this->assertSame('Rupture de stock', end($seen['status_history'])['comment']);
    }

    public function testRestaurantCanCancelAcceptedOrderWithReason(): void
    {
        $restaurant = $this->openRestaurant();
        $order      = $this->order($restaurant, $this->customer());

        $this->setStatus($restaurant['token'], $order['id'], ['status' => 'accepted'])->assertStatus(200);
        $this->setStatus($restaurant['token'], $order['id'], ['status' => 'cancelled'])->assertStatus(422);
        $this->setStatus($restaurant['token'], $order['id'], ['status' => 'cancelled', 'reason' => 'Coupure de courant'])->assertStatus(200);
        $this->seeInDatabase('wf_orders', ['id' => $order['id'], 'status' => 'cancelled', 'cancellation_reason' => 'Coupure de courant']);
    }

    public function testCustomerCanCancelOnlyPendingOrders(): void
    {
        $restaurant = $this->openRestaurant();
        $customer   = $this->customer();

        $pending   = $this->order($restaurant, $customer);
        $cancelled = $this->json('POST', "api/v1/customer/orders/{$pending['id']}/cancel", ['reason' => 'Erreur de commande'], $customer['token']);
        $cancelled->assertStatus(200);
        $this->assertSame(['cancelled', 'Erreur de commande', 'cancelled'], [
            $this->body($cancelled)['data']['status'],
            $this->body($cancelled)['data']['cancellation_reason'],
            $this->body($cancelled)['data']['payment']['status'],
        ]);

        // Deuxième annulation, puis action du restaurant sur une commande annulée.
        $this->assertError($this->json('POST', "api/v1/customer/orders/{$pending['id']}/cancel", [], $customer['token']), 409, 'ORDER_NOT_CANCELLABLE');
        $this->assertError($this->setStatus($restaurant['token'], $pending['id'], ['status' => 'accepted']), 409, 'INVALID_STATUS_TRANSITION');

        $accepted = $this->order($restaurant, $customer);
        $this->setStatus($restaurant['token'], $accepted['id'], ['status' => 'accepted'])->assertStatus(200);
        $this->assertError($this->json('POST', "api/v1/customer/orders/{$accepted['id']}/cancel", [], $customer['token']), 409, 'ORDER_NOT_CANCELLABLE');
    }

    public function testOrdersAreIsolatedBetweenCustomersAndRestaurants(): void
    {
        $mama   = $this->openRestaurant();
        $chez   = $this->openRestaurant();
        $alice  = $this->customer();
        $bob    = $this->customer();
        $order  = $this->order($mama, $alice);
        $this->order($chez, $bob);

        // Un autre client ne voit ni n'annule la commande.
        $this->json('GET', "api/v1/customer/orders/{$order['id']}", [], $bob['token'])->assertStatus(404);
        $this->json('POST', "api/v1/customer/orders/{$order['id']}/cancel", [], $bob['token'])->assertStatus(404);
        $this->assertSame(1, $this->body($this->json('GET', 'api/v1/customer/orders', [], $bob['token']))['meta']['pagination']['total']);

        // Un autre restaurant ne la voit ni ne la modifie.
        $this->json('GET', "api/v1/restaurant/orders/{$order['id']}", [], $chez['token'])->assertStatus(404);
        $this->setStatus($chez['token'], $order['id'], ['status' => 'accepted'])->assertStatus(404);
        $this->assertSame(1, $this->body($this->json('GET', 'api/v1/restaurant/orders', [], $chez['token']))['meta']['pagination']['total']);

        // Un client n'accède pas à l'espace restaurant.
        $this->json('GET', 'api/v1/restaurant/orders', [], $alice['token'])->assertStatus(403);

        $this->seeInDatabase('wf_orders', ['id' => $order['id'], 'status' => 'pending']);
    }

    public function testStaffPermissionsAndSuspendedRestaurant(): void
    {
        $restaurant = $this->openRestaurant();
        $order      = $this->order($restaurant, $this->customer());

        $cashier = $this->registerUser('customer');
        $this->addMember($restaurant['restaurant_id'], (int) $cashier['user']['id'], 'cashier');
        $this->json('GET', "api/v1/restaurant/orders/{$order['id']}", [], $cashier['tokens']['access_token'])->assertStatus(200);
        $this->setStatus($cashier['tokens']['access_token'], $order['id'], ['status' => 'accepted'])->assertStatus(403);

        $staff = $this->registerUser('customer');
        $this->addMember($restaurant['restaurant_id'], (int) $staff['user']['id'], 'staff');
        $this->setStatus($staff['tokens']['access_token'], $order['id'], ['status' => 'accepted'])->assertStatus(200);
        $this->seeInDatabase('wf_order_status_history', ['order_id' => $order['id'], 'new_status' => 'accepted', 'changed_by' => $staff['user']['id']]);

        // Restaurant suspendu : consultation possible, modification bloquée.
        $this->db->table('wf_restaurants')->where('id', $restaurant['restaurant_id'])->update(['status' => 'suspended']);
        $this->json('GET', 'api/v1/restaurant/orders', [], $restaurant['token'])->assertStatus(200);
        $this->assertError($this->setStatus($restaurant['token'], $order['id'], ['status' => 'preparing']), 403, 'RESTAURANT_SUSPENDED');
    }

    /**
     * Course client/restaurant : la commande a été lue « pending », puis modifiée par l'autre acteur
     * avant l'écriture. L'écriture conditionnelle refuse d'écraser ce changement.
     */
    public function testStaleStatusIsNotOverwritten(): void
    {
        $restaurant = $this->openRestaurant();
        $customer   = $this->customer();
        $order      = $this->order($restaurant, $customer);
        $stale      = $this->db->table('wf_orders')->where('id', $order['id'])->get()->getRowArray();

        $this->json('POST', "api/v1/customer/orders/{$order['id']}/cancel", [], $customer['token'])->assertStatus(200);

        $transition = new \ReflectionMethod(\App\Modules\Orders\Services\OrderService::class, 'transition');

        try {
            $transition->invoke(new \App\Modules\Orders\Services\OrderService(), $stale, 'accepted', 'restaurant', $restaurant['user_id'], null);
            $this->fail('Le changement concurrent aurait dû être détecté.');
        } catch (\App\Common\Exceptions\ApiException $exception) {
            $this->assertSame('ORDER_STATUS_CHANGED', $exception->getErrorCode());
        }

        $this->seeInDatabase('wf_orders', ['id' => $order['id'], 'status' => 'cancelled']);
        $this->assertSame(2, $this->db->table('wf_order_status_history')->where('order_id', $order['id'])->countAllResults());
        $this->assertSame(0, db_connect()->transDepth, 'La transaction doit être annulée proprement.');
    }

    public function testRestaurantOrderFilters(): void
    {
        $restaurant = $this->openRestaurant();
        $customer   = $this->customer();
        $first      = $this->order($restaurant, $customer);
        $second     = $this->order($restaurant, $customer);
        $done       = $this->order($restaurant, $customer);
        $this->json('POST', "api/v1/customer/orders/{$done['id']}/cancel", [], $customer['token'])->assertStatus(200);
        $this->db->table('wf_orders')->where('id', $first['id'])->update(['created_at' => '2026-01-10 08:00:00']);

        $list = fn (string $query): array => $this->body($this->json('GET', 'api/v1/restaurant/orders' . $query, [], $restaurant['token']));

        // File de travail : commandes en cours, la plus ancienne d'abord.
        $this->assertSame([$first['id'], $second['id']], array_column($list('?status=active')['data'], 'id'));
        // Historique : plus récentes d'abord.
        $this->assertSame($done['id'], $list('')['data'][0]['id']);
        $this->assertSame([$done['id']], array_column($list('?status=cancelled,rejected')['data'], 'id'));
        $this->assertSame([$first['id']], array_column($list('?date_from=2026-01-10&date_to=2026-01-10')['data'], 'id'));
        $this->assertSame([$second['id']], array_column($list('?q=' . $second['order_number'])['data'], 'id'));
        $this->assertSame(1, $list('?status=active&per_page=1')['meta']['pagination']['per_page']);
        $this->assertSame(1, $list('?status=active')['data'][0]['item_count']);

        $this->json('GET', 'api/v1/restaurant/orders?status=lost', [], $restaurant['token'])->assertStatus(422);
        $this->json('GET', 'api/v1/restaurant/orders?date_from=10/01/2026', [], $restaurant['token'])->assertStatus(422);

        $mine = $this->body($this->json('GET', 'api/v1/customer/orders?status=pending', [], $customer['token']))['data'];
        $this->assertSame([$second['id'], $first['id']], array_column($mine, 'id'));
        $this->assertSame($restaurant['restaurant_id'], $mine[0]['restaurant']['id']);
    }

    private function assertError(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status);
        $this->assertSame($code, $this->body($response)['meta']['code'] ?? null, (string) $response->getJSON());
    }
}
