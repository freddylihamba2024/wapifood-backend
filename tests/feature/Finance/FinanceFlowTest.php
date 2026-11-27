<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Modules\Commissions\Services\FinanceService;
use CodeIgniter\Test\TestResponse;
use Tests\Support\OrderTestCase;

/**
 * Commission figée, encaissement des espèces, journal, soldes, règlements, remboursements.
 *
 * @internal
 */
final class FinanceFlowTest extends OrderTestCase
{
    /**
     * @param array<string, mixed> $restaurant
     * @param array<string, mixed> $customer
     *
     * @return array<string, mixed>
     */
    private function order(array $restaurant, array $customer, int $fantas = 3, string $type = 'delivery'): array
    {
        $response = $this->placeOrder($restaurant, $customer, [['product_id' => $restaurant['fanta']['id'], 'quantity' => $fantas]], ['order_type' => $type]);
        $response->assertStatus(201);

        return $this->body($response)['data'];
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

    private function cash(array $restaurant, int $orderId, ?string $token = null): TestResponse
    {
        return $this->json('POST', "api/v1/restaurant/orders/{$orderId}/cash-payment", [], $token ?? $restaurant['token']);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function balances(array $restaurant): array
    {
        $rows = $this->body($this->json('GET', 'api/v1/restaurant/finance', [], $restaurant['token']))['data'];

        return array_column($rows, null, 'currency');
    }

    public function testCommissionIsFrozenAtOrderAndEarnedOnCompletion(): void
    {
        $restaurant = $this->openRestaurant(['commission_rate' => '10.00']);
        $customer   = $this->customer();
        $order      = $this->order($restaurant, $customer); // sous-total 3.60, livraison 2.00

        $this->seeInDatabase('wf_commissions', [
            'order_id' => $order['id'], 'commission_rate' => '10.00', 'order_amount' => '3.60',
            'commission_amount' => '0.36', 'restaurant_amount' => '3.24', 'status' => 'pending',
        ]);
        $this->assertSame(['commission_due' => '0.00', 'commission_pending' => '0.36'], array_intersect_key($this->balances($restaurant)['USD'], ['commission_due' => 1, 'commission_pending' => 1]));

        // Un changement de taux ne modifie pas une commande déjà passée.
        $this->db->table('wf_restaurants')->where('id', $restaurant['restaurant_id'])->update(['commission_rate' => '25.00']);
        $this->advance($restaurant, $order['id'], ['accepted', 'preparing', 'ready', 'out_for_delivery', 'completed']);

        $this->seeInDatabase('wf_commissions', ['order_id' => $order['id'], 'commission_amount' => '0.36', 'status' => 'earned']);
        $this->assertSame('0.36', $this->balances($restaurant)['USD']['commission_due']);
        $this->assertSame('0.00', $this->balances($restaurant)['USD']['commission_pending']);

        $ledger = $this->body($this->json('GET', 'api/v1/restaurant/finance/ledger', [], $restaurant['token']))['data'];
        $this->assertSame([['commission_accrued', '0.36', '0.36', $order['order_number']]], array_map(
            static fn (array $e): array => [$e['type'], $e['amount'], $e['balance_after'], $e['order_number']],
            $ledger,
        ));
    }

    public function testRejectedOrCancelledOrdersOweNothing(): void
    {
        $restaurant = $this->openRestaurant();
        $customer   = $this->customer();

        $rejected = $this->order($restaurant, $customer);
        $this->json('PATCH', "api/v1/restaurant/orders/{$rejected['id']}/status", ['status' => 'rejected', 'reason' => 'Fermeture'], $restaurant['token'])->assertStatus(200);

        $cancelled = $this->order($restaurant, $customer);
        $this->json('POST', "api/v1/customer/orders/{$cancelled['id']}/cancel", [], $customer['token'])->assertStatus(200);

        $this->assertSame(2, $this->db->table('wf_commissions')->where('status', 'reversed')->countAllResults());
        $this->assertSame(0, $this->db->table('wf_restaurant_ledger_entries')->countAllResults());
        $this->assertSame([], $this->balances($restaurant), 'Aucune commission due ni en attente.');
    }

    public function testCashCollection(): void
    {
        $restaurant = $this->openRestaurant();
        $customer   = $this->customer();
        $order      = $this->order($restaurant, $customer);

        $this->assertSame(['unpaid', 'pending'], [$order['payment_status'], $order['payment']['status']]);

        $early = $this->cash($restaurant, $order['id']);
        $early->assertStatus(409);
        $this->assertSame('PAYMENT_NOT_COLLECTABLE', $this->body($early)['meta']['code']);

        $this->advance($restaurant, $order['id'], ['accepted', 'preparing', 'ready', 'out_for_delivery']);

        // Le caissier peut enregistrer l'encaissement ; un client ou un autre restaurant non.
        $cashier = $this->registerUser('customer');
        $this->addMember($restaurant['restaurant_id'], (int) $cashier['user']['id'], 'cashier');
        $this->cash($restaurant, $order['id'], $customer['token'])->assertStatus(403);
        $this->cash($restaurant, $order['id'], $this->openRestaurant()['token'])->assertStatus(404);

        $paid = $this->cash($restaurant, $order['id'], $cashier['tokens']['access_token']);
        $paid->assertStatus(200);
        $data = $this->body($paid)['data'];
        $this->assertSame(['paid', 'successful'], [$data['payment_status'], $data['payment']['status']]);
        $this->assertNotNull($data['payment']['paid_at']);
        $this->seeInDatabase('wf_audit_logs', ['action' => 'payment.cash_recorded', 'entity_id' => $order['id'], 'user_id' => $cashier['user']['id']]);

        $twice = $this->cash($restaurant, $order['id']);
        $twice->assertStatus(409);
        $this->assertSame('PAYMENT_ALREADY_RECORDED', $this->body($twice)['meta']['code']);

        // Le client voit sa commande payée.
        $this->assertSame('paid', $this->body($this->json('GET', "api/v1/customer/orders/{$order['id']}", [], $customer['token']))['data']['payment_status']);
    }

    public function testSettlementsAdjustmentsAndConsistency(): void
    {
        $restaurant = $this->openRestaurant(['commission_rate' => '10.00']);
        $customer   = $this->customer();
        $admin      = $this->adminToken();

        foreach ([10, 20] as $quantity) { // 12.00 → 1.20 ; 24.00 → 2.40
            $order = $this->order($restaurant, $customer, $quantity, 'pickup');
            $this->advance($restaurant, $order['id'], ['accepted', 'preparing', 'ready', 'completed']);
        }

        $this->assertSame('3.60', $this->balances($restaurant)['USD']['commission_due']);

        $path = "api/v1/admin/restaurants/{$restaurant['restaurant_id']}";

        $tooMuch = $this->json('POST', "{$path}/settlements", ['amount' => '3.61', 'currency' => 'USD'], $admin);
        $tooMuch->assertStatus(409);
        $this->assertSame('SETTLEMENT_EXCEEDS_DUE', $this->body($tooMuch)['meta']['code']);
        $this->json('POST', "{$path}/settlements", ['amount' => '1', 'currency' => 'CDF'], $admin)->assertStatus(409);
        $this->json('POST', "{$path}/settlements", ['amount' => '0', 'currency' => 'USD'], $admin)->assertStatus(422);

        $settled = $this->json('POST', "{$path}/settlements", ['amount' => '3.00', 'currency' => 'USD', 'reference' => 'Reçu 0042'], $admin);
        $settled->assertStatus(201);
        $this->assertSame(['commission_settlement', '-3.00', '0.60'], [$this->body($settled)['data']['type'], $this->body($settled)['data']['amount'], $this->body($settled)['data']['balance_after']]);

        $this->json('POST', "{$path}/adjustments", ['amount' => '-0.10', 'currency' => 'USD'], $admin)->assertStatus(422);
        $this->json('POST', "{$path}/adjustments", ['amount' => '-0.10', 'currency' => 'USD', 'note' => 'Geste commercial'], $admin)->assertStatus(201);
        $this->assertSame('0.50', $this->balances($restaurant)['USD']['commission_due']);

        // Le restaurant ne peut pas enregistrer de règlement lui-même.
        $this->json('POST', "{$path}/settlements", ['amount' => '0.50', 'currency' => 'USD'], $restaurant['token'])->assertStatus(403);

        $balances = $this->body($this->json('GET', 'api/v1/admin/finance/balances?only_due=true', [], $admin))['data'];
        $this->assertSame([[$restaurant['restaurant_id'], 'USD', '0.50']], array_map(static fn (array $b): array => [$b['restaurant']['id'], $b['currency'], $b['commission_due']], $balances));
        $this->assertSame(4, $this->body($this->json('GET', "{$path}/ledger", [], $admin))['meta']['pagination']['total']);
        $this->assertSame(0, $this->body($this->json('GET', "{$path}/ledger?currency=CDF", [], $admin))['meta']['pagination']['total']);

        // Cohérence : solde = somme du journal ; une modification directe du solde est détectée.
        $this->assertSame([], (new FinanceService())->inconsistencies());
        $this->db->table('wf_restaurant_balances')->where('restaurant_id', $restaurant['restaurant_id'])->update(['commission_due' => '9.99']);
        $problems = (new FinanceService())->inconsistencies();
        $this->assertCount(1, $problems);
        $this->assertSame(['9.99', '0.50'], [$problems[0]['balance'], $problems[0]['ledger_sum']]);
    }

    public function testFinanceIsOwnerOnlyAndIsolated(): void
    {
        $restaurant = $this->openRestaurant();
        $other      = $this->openRestaurant();
        $order      = $this->order($restaurant, $this->customer(), 10, 'pickup');
        $this->advance($restaurant, $order['id'], ['accepted', 'preparing', 'ready', 'completed']);

        $manager = $this->registerUser('customer');
        $this->addMember($restaurant['restaurant_id'], (int) $manager['user']['id'], 'manager');
        $this->json('GET', 'api/v1/restaurant/finance', [], $manager['tokens']['access_token'])->assertStatus(403);

        $this->assertSame([], $this->body($this->json('GET', 'api/v1/restaurant/finance', [], $other['token']))['data']);
        $this->assertSame([], $this->body($this->json('GET', 'api/v1/restaurant/finance/ledger', [], $other['token']))['data']);
        $this->json('GET', 'api/v1/restaurant/finance/ledger?currency=EUR', [], $restaurant['token'])->assertStatus(422);
    }

    public function testRefunds(): void
    {
        $restaurant = $this->openRestaurant(['commission_rate' => '10.00']);
        $customer   = $this->customer();
        $admin      = $this->adminToken();
        $order      = $this->order($restaurant, $customer, 10, 'pickup'); // total 12.00, commission 1.20
        $refund     = static fn (array $body): array => [$body, "api/v1/admin/orders/{$order['id']}/refunds"];

        [$body, $path] = $refund(['amount' => '5.00', 'reason' => 'Plat froid']);
        $notPaid       = $this->json('POST', $path, $body, $admin);
        $notPaid->assertStatus(409);
        $this->assertSame('PAYMENT_NOT_REFUNDABLE', $this->body($notPaid)['meta']['code']);

        $this->advance($restaurant, $order['id'], ['accepted', 'preparing', 'ready']);
        $this->cash($restaurant, $order['id'])->assertStatus(200);
        $this->advance($restaurant, $order['id'], ['completed']);
        $this->assertSame('1.20', $this->balances($restaurant)['USD']['commission_due']);

        $this->json('POST', $path, ['amount' => '5.00'], $admin)->assertStatus(422);
        $this->json('POST', $path, $body, $restaurant['token'])->assertStatus(403);

        $partial = $this->json('POST', $path, $body, $admin);
        $partial->assertStatus(201);
        $this->assertSame(['partially_refunded', '5.00'], [$this->body($partial)['data']['payment_status'], $this->body($partial)['data']['total_refunded']]);
        $this->assertSame('1.20', $this->balances($restaurant)['USD']['commission_due'], 'Un remboursement partiel ne modifie pas la commission.');

        $excess = $this->json('POST', $path, ['amount' => '7.01', 'reason' => 'Erreur'], $admin);
        $excess->assertStatus(422);
        $this->assertSame('REFUND_EXCEEDS_PAID', $this->body($excess)['meta']['code']);

        $full = $this->json('POST', $path, ['amount' => '7.00', 'reason' => 'Annulation commerciale'], $admin);
        $full->assertStatus(201);
        $this->assertSame('refunded', $this->body($full)['data']['payment_status']);

        // Remboursement total : la commission acquise est annulée par une écriture inverse.
        $this->assertSame('0.00', $this->balances($restaurant)['USD']['commission_due']);
        $this->seeInDatabase('wf_commissions', ['order_id' => $order['id'], 'status' => 'reversed']);
        $this->seeInDatabase('wf_restaurant_ledger_entries', ['order_id' => $order['id'], 'entry_type' => 'commission_reversed', 'amount' => '-1.20']);

        $detail = $this->body($this->json('GET', "api/v1/admin/orders/{$order['id']}", [], $admin))['data'];
        $this->assertSame(['refunded', 'refunded'], [$detail['payment_status'], $detail['payment']['status']]);
        $this->assertSame(['5.00', '7.00'], array_column($detail['refunds'], 'amount'));
        $this->assertSame([], $detail['next_statuses']);

        $this->json('POST', $path, ['amount' => '0.01', 'reason' => 'Encore'], $admin)->assertStatus(422);
        $this->assertSame([], (new FinanceService())->inconsistencies());
    }
}
