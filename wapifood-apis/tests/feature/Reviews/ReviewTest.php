<?php

declare(strict_types=1);

namespace Tests\Feature\Reviews;

use CodeIgniter\Test\TestResponse;
use Tests\Support\OrderTestCase;

/**
 * Avis clients : commande terminée, un seul par commande, délai, affichage public, modération a posteriori.
 *
 * @internal
 */
final class ReviewTest extends OrderTestCase
{
    /**
     * Commande en retrait, terminée.
     *
     * @param array<string, mixed> $restaurant
     * @param array<string, mixed> $customer
     *
     * @return array<string, mixed>
     */
    private function completedOrder(array $restaurant, array $customer, bool $complete = true): array
    {
        $order = $this->body($this->placeOrder($restaurant, $customer, [['product_id' => $restaurant['fanta']['id'], 'quantity' => 1]], ['order_type' => 'pickup']))['data'];

        if ($complete) {
            foreach (['accepted', 'preparing', 'ready', 'completed'] as $status) {
                $this->json('PATCH', "api/v1/restaurant/orders/{$order['id']}/status", ['status' => $status], $restaurant['token'])->assertStatus(200);
            }
        }

        return $order;
    }

    /**
     * @param array<string, mixed> $customer
     * @param array<string, mixed> $body
     */
    private function review(array $customer, int $orderId, array $body = ['rating' => 5, 'comment' => 'Très bon']): TestResponse
    {
        return $this->json('POST', "api/v1/customer/orders/{$orderId}/review", $body, $customer['token']);
    }

    private function errorCode(TestResponse $response): ?string
    {
        return $this->body($response)['meta']['code'] ?? null;
    }

    public function testCustomerReviewsACompletedOrderOnce(): void
    {
        $restaurant = $this->openRestaurant();
        $customer   = $this->customer();
        $order      = $this->completedOrder($restaurant, $customer, complete: false);

        $this->assertFalse($this->body($this->json('GET', "api/v1/customer/orders/{$order['id']}", [], $customer['token']))['data']['can_review']);
        $this->assertSame('ORDER_NOT_COMPLETED', $this->errorCode($this->review($customer, $order['id'])));

        $order = $this->completedOrder($restaurant, $customer);
        $this->assertTrue($this->body($this->json('GET', "api/v1/customer/orders/{$order['id']}", [], $customer['token']))['data']['can_review']);

        foreach ([['rating' => 0], ['rating' => 6], ['rating' => 'cinq'], ['comment' => 'Sans note'], ['rating' => 4, 'comment' => str_repeat('a', 1001)]] as $invalid) {
            $this->review($customer, $order['id'], $invalid)->assertStatus(422);
        }

        $response = $this->review($customer, $order['id'], ['rating' => 4, 'comment' => '  Rapide et chaud  ']);
        $response->assertStatus(201);
        $review = $this->body($response)['data'];
        $this->assertSame([4, 'Rapide et chaud', 'Test U.', 'published'], [$review['rating'], $review['comment'], $review['author'], $review['status']]);

        $this->assertSame('REVIEW_ALREADY_EXISTS', $this->errorCode($this->review($customer, $order['id'])));

        $detail = $this->body($this->json('GET', "api/v1/customer/orders/{$order['id']}", [], $customer['token']))['data'];
        $this->assertSame([false, $review['id']], [$detail['can_review'], $detail['review']['id']]);

        $mine = $this->body($this->json('GET', 'api/v1/customer/reviews', [], $customer['token']))['data'];
        $this->assertSame([$order['order_number']], array_column($mine, 'order_number'));

        // La commande d'un autre client est introuvable.
        $this->review($this->customer(), $order['id'])->assertStatus(404);
    }

    public function testReviewWindowCloses(): void
    {
        $restaurant = $this->openRestaurant();
        $customer   = $this->customer();
        $order      = $this->completedOrder($restaurant, $customer);

        $this->db->table('wf_orders')->where('id', $order['id'])->update(['completed_at' => gmdate('Y-m-d H:i:s', time() - 31 * 86400)]);

        $this->assertFalse($this->body($this->json('GET', "api/v1/customer/orders/{$order['id']}", [], $customer['token']))['data']['can_review']);
        $this->assertSame('REVIEW_WINDOW_CLOSED', $this->errorCode($this->review($customer, $order['id'])));
    }

    public function testPublicReviewsRatingAndModeration(): void
    {
        $admin      = $this->adminToken();
        $restaurant = $this->openRestaurant();
        $customer   = $this->customer();

        $ids = [];

        foreach ([5, 4, 2] as $rating) {
            $order = $this->completedOrder($restaurant, $customer);
            $ids[] = $this->body($this->review($customer, $order['id'], ['rating' => $rating]))['data']['id'];
        }

        $public = $this->body($this->json('GET', "api/v1/restaurants/{$restaurant['restaurant_id']}/reviews"));
        $this->assertSame([2, 4, 5], array_column($public['data'], 'rating'));
        $this->assertSame(['average' => '3.7', 'count' => 3], $public['meta']['rating']);
        $this->assertSame(['id', 'rating', 'comment', 'author', 'created_at'], array_keys($public['data'][0]));

        $this->assertSame(['average' => '3.7', 'count' => 3], $this->body($this->json('GET', "api/v1/restaurants/{$restaurant['restaurant_id']}"))['data']['rating']);

        // Masquage : motif obligatoire, réservé à l'administration.
        $moderation = "api/v1/admin/reviews/{$ids[2]}/moderation";
        $this->json('PATCH', $moderation, ['status' => 'hidden'], $restaurant['token'])->assertStatus(403);
        $this->json('PATCH', $moderation, ['status' => 'hidden'], $admin)->assertStatus(422);
        $this->json('PATCH', $moderation, ['status' => 'deleted', 'reason' => 'Insulte'], $admin)->assertStatus(422);

        $hidden = $this->body($this->json('PATCH', $moderation, ['status' => 'hidden', 'reason' => 'Propos injurieux'], $admin))['data'];
        $this->assertSame(['hidden', 'Propos injurieux'], [$hidden['status'], $hidden['moderation_reason']]);
        $this->seeInDatabase('wf_audit_logs', ['action' => 'review.moderated', 'entity_id' => $ids[2]]);

        // L'avis masqué disparaît de la fiche publique et de la note, mais reste visible par le restaurant.
        $public = $this->body($this->json('GET', "api/v1/restaurants/{$restaurant['restaurant_id']}/reviews"));
        $this->assertSame([4, 5], array_column($public['data'], 'rating'));
        $this->assertSame(['average' => '4.5', 'count' => 2], $public['meta']['rating']);

        $list = $this->body($this->json('GET', 'api/v1/restaurants'))['data'];
        $this->assertSame(['average' => '4.5', 'count' => 2], $list[0]['rating']);

        $own = $this->body($this->json('GET', 'api/v1/restaurant/reviews?status=hidden', [], $restaurant['token']));
        $this->assertSame([[2, 'hidden']], array_map(static fn (array $r): array => [$r['rating'], $r['status']], $own['data']));

        $adminList = $this->body($this->json('GET', "api/v1/admin/reviews?rating=2&restaurant_id={$restaurant['restaurant_id']}", [], $admin))['data'];
        $this->assertSame([$ids[2]], array_column($adminList, 'id'));
        $this->json('GET', 'api/v1/admin/reviews?rating=9', [], $admin)->assertStatus(422);

        // Republication.
        $this->json('PATCH', $moderation, ['status' => 'published'], $admin)->assertStatus(200);
        $this->assertSame(3, $this->body($this->json('GET', "api/v1/restaurants/{$restaurant['restaurant_id']}/reviews"))['meta']['rating']['count']);
    }

    public function testRestaurantWithoutReviewsAndHiddenRestaurant(): void
    {
        $restaurant = $this->openRestaurant();

        $public = $this->body($this->json('GET', "api/v1/restaurants/{$restaurant['restaurant_id']}/reviews"));
        $this->assertSame([[], ['average' => null, 'count' => 0]], [$public['data'], $public['meta']['rating']]);

        $this->db->table('wf_restaurants')->where('id', $restaurant['restaurant_id'])->update(['status' => 'suspended']);
        $this->json('GET', "api/v1/restaurants/{$restaurant['restaurant_id']}/reviews")->assertStatus(404);
    }
}
