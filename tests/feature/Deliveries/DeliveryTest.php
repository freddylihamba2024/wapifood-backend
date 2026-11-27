<?php

declare(strict_types=1);

namespace Tests\Feature\Deliveries;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\TestResponse;
use Tests\Support\OrderTestCase;

/**
 * Livraison par le restaurant : suivi synchronisé avec la commande, livreur, échec, preuve privée.
 *
 * @internal
 */
final class DeliveryTest extends OrderTestCase
{
    /**
     * @param array<string, mixed> $restaurant
     * @param array<string, mixed> $customer
     *
     * @return array<string, mixed>
     */
    private function order(array $restaurant, array $customer, string $type = 'delivery'): array
    {
        $response = $this->placeOrder($restaurant, $customer, [['product_id' => $restaurant['fanta']['id'], 'quantity' => 2]], ['order_type' => $type]);
        $response->assertStatus(201);

        return $this->body($response)['data'];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setStatus(array $restaurant, int $orderId, string $status, array $body = []): TestResponse
    {
        return $this->json('PATCH', "api/v1/restaurant/orders/{$orderId}/status", ['status' => $status] + $body, $restaurant['token']);
    }

    private function errorCode(TestResponse $response): ?string
    {
        return $this->body($response)['meta']['code'] ?? null;
    }

    public function testDeliveryFollowsOrderLifecycle(): void
    {
        $restaurant = $this->openRestaurant();
        $customer   = $this->customer();
        $order      = $this->order($restaurant, $customer);

        $this->assertSame('pending', $order['delivery']['status']);
        $this->seeInDatabase('wf_deliveries', ['order_id' => $order['id'], 'managed_by' => 'restaurant', 'delivery_status' => 'pending', 'delivery_fee' => '2.00']);

        // Livreur indiqué avant acceptation : refusé.
        $assign = fn (array $body): TestResponse => $this->json('PUT', "api/v1/restaurant/orders/{$order['id']}/delivery", $body, $restaurant['token']);
        $this->assertSame('DELIVERY_NOT_ASSIGNABLE', $this->errorCode($assign(['courier_name' => 'Patrick'])));

        $this->setStatus($restaurant, $order['id'], 'accepted')->assertStatus(200);
        $assign(['courier_name' => ''])->assertStatus(422);
        $assign(['courier_name' => 'Patrick', 'courier_phone' => 'pas un numéro'])->assertStatus(422);

        $detail = $this->body($assign(['courier_name' => 'Patrick', 'courier_phone' => '0991234567', 'notes' => 'Moto rouge']))['data'];
        $this->assertSame(['assigned', 'Patrick', '+243991234567', 'Moto rouge', false], [
            $detail['delivery']['status'], $detail['delivery']['courier_name'], $detail['delivery']['courier_phone'], $detail['delivery']['notes'], $detail['delivery']['has_proof'],
        ]);

        // Changement de livreur au départ (dans la même requête que le statut).
        $this->setStatus($restaurant, $order['id'], 'preparing')->assertStatus(200);
        $this->setStatus($restaurant, $order['id'], 'ready')->assertStatus(200);
        $detail = $this->body($this->setStatus($restaurant, $order['id'], 'out_for_delivery', ['courier_name' => 'Joël', 'courier_phone' => '0811111111']))['data'];
        $this->assertSame(['in_transit', 'Joël'], [$detail['delivery']['status'], $detail['delivery']['courier_name']]);
        $this->assertNotNull($detail['delivery']['picked_up_at']);

        // Le client voit le livreur et son téléphone, pas les notes internes.
        $seen = $this->body($this->json('GET', "api/v1/customer/orders/{$order['id']}", [], $customer['token']))['data']['delivery'];
        $this->assertSame(['in_transit', 'Joël', '+243811111111'], [$seen['status'], $seen['courier_name'], $seen['courier_phone']]);
        $this->assertArrayNotHasKey('notes', $seen);
        $this->assertArrayNotHasKey('has_proof', $seen);

        $detail = $this->body($this->setStatus($restaurant, $order['id'], 'completed'))['data'];
        $this->assertSame('delivered', $detail['delivery']['status']);
        $this->assertNotNull($detail['delivery']['delivered_at']);
    }

    public function testCourierIsOptionalAndInvalidCourierRollsBackDeparture(): void
    {
        $restaurant = $this->openRestaurant();
        $order      = $this->order($restaurant, $this->customer());

        foreach (['accepted', 'preparing', 'ready'] as $status) {
            $this->setStatus($restaurant, $order['id'], $status)->assertStatus(200);
        }

        // Téléphone du livreur invalide : ni le statut ni la livraison ne changent.
        $this->setStatus($restaurant, $order['id'], 'out_for_delivery', ['courier_name' => 'Joël', 'courier_phone' => '123'])->assertStatus(422);
        $this->seeInDatabase('wf_orders', ['id' => $order['id'], 'status' => 'ready']);
        $this->seeInDatabase('wf_deliveries', ['order_id' => $order['id'], 'delivery_status' => 'pending']);

        // Sans livreur renseigné (le patron livre lui-même) : accepté (Ph7-5).
        $detail = $this->body($this->setStatus($restaurant, $order['id'], 'out_for_delivery'))['data'];
        $this->assertSame(['in_transit', null], [$detail['delivery']['status'], $detail['delivery']['courier_name']]);
    }

    public function testFailedDeliveryCancelsTheOrder(): void
    {
        $restaurant = $this->openRestaurant();
        $customer   = $this->customer();
        $order      = $this->order($restaurant, $customer);
        $failure    = fn (array $body): TestResponse => $this->json('POST', "api/v1/restaurant/orders/{$order['id']}/delivery/failure", $body, $restaurant['token']);

        $this->setStatus($restaurant, $order['id'], 'accepted')->assertStatus(200);
        $this->assertSame('DELIVERY_NOT_IN_PROGRESS', $this->errorCode($failure(['reason' => 'Client absent'])));

        $this->setStatus($restaurant, $order['id'], 'preparing')->assertStatus(200);
        $this->setStatus($restaurant, $order['id'], 'ready')->assertStatus(200);
        $this->setStatus($restaurant, $order['id'], 'out_for_delivery')->assertStatus(200);

        // Annuler par le changement de statut reste interdit : il faut déclarer l'échec.
        $this->assertSame('INVALID_STATUS_TRANSITION', $this->errorCode($this->setStatus($restaurant, $order['id'], 'cancelled', ['reason' => 'Client absent'])));
        $failure([])->assertStatus(422);

        $detail = $this->body($failure(['reason' => 'Client injoignable à l\'adresse indiquée']))['data'];
        $this->assertSame(['cancelled', 'failed', 'Client injoignable à l\'adresse indiquée', []], [
            $detail['status'], $detail['delivery']['status'], $detail['cancellation_reason'], $detail['next_statuses'],
        ]);
        $this->seeInDatabase('wf_commissions', ['order_id' => $order['id'], 'status' => 'reversed']);
        $this->seeInDatabase('wf_payments', ['order_id' => $order['id'], 'status' => 'cancelled']);
        $this->seeInDatabase('wf_audit_logs', ['action' => 'order.delivery_failed', 'entity_id' => $order['id']]);

        $seen = $this->body($this->json('GET', "api/v1/customer/orders/{$order['id']}", [], $customer['token']))['data'];
        $this->assertSame(['cancelled', 'failed'], [$seen['status'], $seen['delivery']['status']]);
    }

    public function testRejectedOrderCancelsDeliveryAndPickupHasNone(): void
    {
        $restaurant = $this->openRestaurant();
        $customer   = $this->customer();
        $order      = $this->order($restaurant, $customer);

        $this->setStatus($restaurant, $order['id'], 'rejected', ['reason' => 'Rupture de stock'])->assertStatus(200);
        $this->seeInDatabase('wf_deliveries', ['order_id' => $order['id'], 'delivery_status' => 'cancelled']);

        $pickup = $this->order($restaurant, $customer, 'pickup');
        $this->assertNull($pickup['delivery']);
        $this->seeNumRecords(0, 'wf_deliveries', ['order_id' => $pickup['id']]);

        $this->setStatus($restaurant, $pickup['id'], 'accepted')->assertStatus(200);
        $response = $this->json('PUT', "api/v1/restaurant/orders/{$pickup['id']}/delivery", ['courier_name' => 'Joël'], $restaurant['token']);
        $this->assertSame('ORDER_NOT_DELIVERY', $this->errorCode($response));
    }

    public function testProofOfDeliveryIsPrivate(): void
    {
        $restaurant = $this->openRestaurant();
        $other      = $this->openRestaurant();
        $admin      = $this->adminToken();
        $order      = $this->order($restaurant, $this->customer());
        $proofUrl   = "api/v1/restaurant/orders/{$order['id']}/delivery/proof";

        $this->setStatus($restaurant, $order['id'], 'accepted')->assertStatus(200);
        $this->fakeUpload('file', self::pngBytes());
        $this->assertSame('DELIVERY_PROOF_NOT_ALLOWED', $this->errorCode($this->upload($proofUrl, $restaurant['token'])));

        foreach (['preparing', 'ready', 'out_for_delivery'] as $status) {
            $this->setStatus($restaurant, $order['id'], $status)->assertStatus(200);
        }

        $this->json('GET', $proofUrl, [], $restaurant['token'])->assertStatus(404);

        $this->fakeUpload('file', self::pngBytes());
        $response = $this->upload($proofUrl, $restaurant['token']);
        $response->assertStatus(200);
        $this->assertTrue($this->body($response)['data']['delivery']['has_proof']);

        $stored = $this->db->table('wf_deliveries')->where('order_id', $order['id'])->get()->getRowArray()['proof_url'];
        $this->assertMatchesRegularExpression('#\Adelivery-proofs/\d{4}/\d{2}/[a-f0-9]{32}\.png\z#', $stored);

        // Restaurant de la commande et administration : oui. Autre restaurant : non. Jamais via /media.
        $image = $this->json('GET', $proofUrl, [], $restaurant['token']);
        $image->assertStatus(200);
        $image->assertHeader('Content-Type', 'image/png');
        $image->assertHeader('Cache-Control', 'private, no-store');

        $this->json('GET', "api/v1/admin/orders/{$order['id']}/delivery/proof", [], $admin)->assertStatus(200);
        $this->json('GET', $proofUrl, [], $other['token'])->assertStatus(404);

        try {
            $this->get('media/' . $stored);
            $this->fail('Une preuve de livraison ne doit jamais être servie par /media.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }
}
