<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Modules\Payments\Providers\CashPaymentProvider;
use App\Modules\Payments\Providers\PaymentProviderRegistry;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Tests\Support\FakeMobileMoneyProvider;
use Tests\Support\OrderTestCase;

/**
 * Mobile Money : désactivé dans la configuration réelle (D4, aucun agrégateur choisi) ;
 * chaîne de traitement vérifiée avec un agrégateur simulé.
 *
 * @internal
 */
final class MobileMoneyTest extends OrderTestCase
{
    protected function tearDown(): void
    {
        Services::resetSingle('paymentProviders');
        parent::tearDown();
    }

    private function enableFakeAggregator(bool $failInitiation = false): void
    {
        Services::injectMock('paymentProviders', new PaymentProviderRegistry(
            [new CashPaymentProvider(), new FakeMobileMoneyProvider($failInitiation)],
            ['cash', 'mpesa'],
        ));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function webhook(array $payload, ?string $signature = null, string $provider = 'mpesa'): TestResponse
    {
        $body = (string) json_encode($payload);

        return $this->withHeaders(['Content-Type' => 'application/json', 'X-Signature' => $signature ?? FakeMobileMoneyProvider::sign($body)])
            ->withBodyFormat('')
            ->withBody($body)
            ->call('POST', "api/v1/webhooks/payments/{$provider}");
    }

    public function testMobileMoneyIsDisabledInRealConfiguration(): void
    {
        $restaurant = $this->openRestaurant();
        $customer   = $this->customer();

        $this->assertSame(['cash'], service('paymentProviders')->available());

        $order = $this->placeOrder($restaurant, $customer, [['product_id' => $restaurant['fanta']['id'], 'quantity' => 1]], ['payment_method' => 'mpesa']);
        $order->assertStatus(422);
        $this->assertSame('PAYMENT_METHOD_UNAVAILABLE', $this->body($order)['meta']['code']);

        // Aucun point de réception de notifications n'est actif, pas même pour les espèces.
        foreach (['mpesa', 'airtel_money', 'orange_money', 'cash', 'inconnu'] as $provider) {
            $this->webhook(['reference' => 'x'], 'x', $provider)->assertStatus(404);
        }

        $this->assertSame(0, $this->db->table('wf_payment_webhooks')->countAllResults());
    }

    public function testPaymentFlowWithSimulatedAggregator(): void
    {
        $this->enableFakeAggregator();
        $restaurant = $this->openRestaurant();
        $customer   = $this->customer();

        $order = $this->body($this->placeOrder($restaurant, $customer, [['product_id' => $restaurant['fanta']['id'], 'quantity' => 2]], ['payment_method' => 'mpesa']))['data'];
        $this->assertSame(['pending', 'pending', 'mpesa'], [$order['payment_status'], $order['payment']['status'], $order['payment']['method']]);
        $reference = $order['payment']['reference'];

        $event = ['event_id' => 'evt-001', 'reference' => $reference, 'transaction_id' => 'MP123', 'status' => 'successful', 'amount' => '4.40', 'currency' => 'USD'];

        // Signature invalide : refusée et tracée, paiement inchangé.
        $forged = $this->webhook($event, 'signature-falsifiee');
        $forged->assertStatus(401);
        $this->assertSame('WEBHOOK_SIGNATURE_INVALID', $this->body($forged)['meta']['code']);
        $this->seeInDatabase('wf_payment_webhooks', ['provider' => 'mpesa', 'signature_valid' => 0, 'processing_status' => 'rejected']);

        // Montant falsifié (signature valide) : ignoré, paiement inchangé.
        $tampered = $this->webhook(['event_id' => 'evt-000', 'amount' => '0.01'] + $event);
        $tampered->assertStatus(200);
        $this->assertSame('ignored', $this->body($tampered)['data']['status']);
        $this->seeInDatabase('wf_payments', ['id' => $this->paymentId($order['id']), 'status' => 'pending']);
        $this->seeInDatabase('wf_audit_logs', ['action' => 'payment.webhook_mismatch']);

        $this->assertSame('ignored', $this->body($this->webhook(['event_id' => 'evt-002', 'currency' => 'CDF'] + $event))['data']['status']);
        $this->assertSame('ignored', $this->body($this->webhook(['event_id' => 'evt-003', 'reference' => 'PAY-INCONNU'] + $event))['data']['status']);

        // Notification valide : paiement confirmé.
        $ok = $this->webhook($event);
        $ok->assertStatus(200);
        $this->assertSame('processed', $this->body($ok)['data']['status']);
        $this->seeInDatabase('wf_payments', ['id' => $this->paymentId($order['id']), 'status' => 'successful', 'provider_transaction_id' => 'MP123']);
        $this->seeInDatabase('wf_orders', ['id' => $order['id'], 'payment_status' => 'paid']);

        // Rejeu : aucun retraitement.
        $this->assertSame('duplicate', $this->body($this->webhook($event))['data']['status']);
        $this->assertSame(1, $this->db->table('wf_payment_webhooks')->where('event_id', 'evt-001')->countAllResults());

        // Un « échec » tardif ne revient pas sur un paiement confirmé.
        $this->webhook(['event_id' => 'evt-004', 'status' => 'failed'] + $event)->assertStatus(200);
        $this->seeInDatabase('wf_orders', ['id' => $order['id'], 'payment_status' => 'paid']);

        // Le restaurant ne peut pas « encaisser en espèces » une commande payée par Mobile Money.
        foreach (['accepted', 'preparing', 'ready'] as $status) {
            $this->json('PATCH', "api/v1/restaurant/orders/{$order['id']}/status", ['status' => $status], $restaurant['token'])->assertStatus(200);
        }

        $cash = $this->json('POST', "api/v1/restaurant/orders/{$order['id']}/cash-payment", [], $restaurant['token']);
        $cash->assertStatus(409);
        $this->assertSame('PAYMENT_METHOD_MISMATCH', $this->body($cash)['meta']['code']);
    }

    public function testEventWithoutIdIsDeduplicatedByBodyHash(): void
    {
        $this->enableFakeAggregator();
        $restaurant = $this->openRestaurant();
        $order      = $this->body($this->placeOrder($restaurant, $this->customer(), [['product_id' => $restaurant['fanta']['id'], 'quantity' => 1]], ['payment_method' => 'mpesa']))['data'];
        $event      = ['reference' => $order['payment']['reference'], 'status' => 'failed', 'amount' => '3.20', 'currency' => 'USD'];

        $this->assertSame('processed', $this->body($this->webhook($event))['data']['status']);
        $this->assertSame('duplicate', $this->body($this->webhook($event))['data']['status']);
        $this->seeInDatabase('wf_orders', ['id' => $order['id'], 'payment_status' => 'failed']);
        $this->seeInDatabase('wf_payment_webhooks', ['event_id' => hash('sha256', (string) json_encode($event))]);
    }

    public function testInitiationFailureKeepsOrderButMarksPaymentFailed(): void
    {
        $this->enableFakeAggregator(failInitiation: true);
        $restaurant = $this->openRestaurant();

        $response = $this->placeOrder($restaurant, $this->customer(), [['product_id' => $restaurant['fanta']['id'], 'quantity' => 1]], ['payment_method' => 'mpesa']);
        $response->assertStatus(201);
        $this->assertSame(['failed', 'failed'], [$this->body($response)['data']['payment_status'], $this->body($response)['data']['payment']['status']]);
    }

    private function paymentId(int $orderId): int
    {
        return (int) $this->db->table('wf_payments')->where('order_id', $orderId)->get()->getRow()->id;
    }
}
