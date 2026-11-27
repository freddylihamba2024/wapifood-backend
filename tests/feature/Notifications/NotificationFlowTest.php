<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Notifications\Channels\ChannelException;
use App\Modules\Notifications\Channels\NotificationChannelRegistry;
use App\Modules\Notifications\Services\NotificationDispatcher;
use App\Modules\Notifications\Services\NotificationQueueProcessor;
use Config\Notifications;
use Config\Services;
use Tests\Support\FakeNotificationChannel;
use Tests\Support\OrderTestCase;

/**
 * Notifications : création dans la transaction métier, canaux inactifs ignorés, boîtes de réception,
 * file d'envoi avec reprise sur échec, supervision par l'administration.
 *
 * @internal
 */
final class NotificationFlowTest extends OrderTestCase
{
    private FakeNotificationChannel $sms;
    private FakeNotificationChannel $email;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms   = new FakeNotificationChannel('sms');
        $this->email = new FakeNotificationChannel('email');
    }

    protected function tearDown(): void
    {
        Services::resetSingle('notificationChannels');
        parent::tearDown();
    }

    private function enableChannels(FakeNotificationChannel ...$channels): NotificationChannelRegistry
    {
        $registry = new NotificationChannelRegistry($channels);
        Services::injectMock('notificationChannels', $registry);

        return $registry;
    }

    private function processor(NotificationChannelRegistry $registry): NotificationQueueProcessor
    {
        return new NotificationQueueProcessor($registry, config(Notifications::class));
    }

    /**
     * @param array<string, mixed> $restaurant
     * @param array<string, mixed> $customer
     *
     * @return array<string, mixed>
     */
    private function order(array $restaurant, array $customer, string $type = 'delivery'): array
    {
        return $this->body($this->placeOrder($restaurant, $customer, [['product_id' => $restaurant['fanta']['id'], 'quantity' => 1]], ['order_type' => $type]))['data'];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string|null, 3: string}>
     */
    private function rows(): array
    {
        return array_map(
            static fn (array $r): array => [$r['notification_type'], $r['channel'], $r['recipient'], $r['status']],
            $this->db->table('wf_notifications')->orderBy('id', 'ASC')->get()->getResultArray(),
        );
    }

    public function testDisabledChannelsAreSkippedWithoutBlocking(): void
    {
        // Aucun canal externe configuré en test : seules les notifications in-app sont créées.
        $restaurant = $this->openRestaurant();
        $customer   = $this->customer();
        $order      = $this->order($restaurant, $customer);

        $this->assertSame([['order.placed', 'in_app', null, 'sent']], $this->rows());
        $this->seeInDatabase('wf_notifications', ['user_id' => null, 'restaurant_id' => $restaurant['restaurant_id'], 'notification_type' => 'order.placed']);

        $this->json('PATCH', "api/v1/restaurant/orders/{$order['id']}/status", ['status' => 'accepted'], $restaurant['token'])->assertStatus(200);
        $this->seeInDatabase('wf_notifications', ['user_id' => $customer['user_id'], 'notification_type' => 'order.accepted', 'channel' => 'in_app']);
        $this->seeNumRecords(0, 'wf_notifications', ['channel' => 'sms']);
    }

    public function testEventsQueueExternalNotificationsWithFrozenRecipients(): void
    {
        $this->enableChannels($this->sms);
        $restaurant = $this->openRestaurant();
        $customer   = $this->customer();
        $order      = $this->order($restaurant, $customer);
        $restaurantPhone = $this->db->table('wf_restaurants')->where('id', $restaurant['restaurant_id'])->get()->getRow()->phone;

        foreach (['accepted', 'preparing', 'ready', 'out_for_delivery', 'completed'] as $status) {
            $this->json('PATCH', "api/v1/restaurant/orders/{$order['id']}/status", ['status' => $status], $restaurant['token'])->assertStatus(200);
        }

        // « ready » en livraison : étape interne, pas de notification au client.
        $this->assertSame([
            ['order.placed', 'in_app', null, 'sent'],
            ['order.placed', 'sms', $restaurantPhone, 'queued'],
            ['order.accepted', 'in_app', null, 'sent'],
            ['order.accepted', 'sms', $customer['phone'], 'queued'],
            ['order.preparing', 'in_app', null, 'sent'],
            ['order.out_for_delivery', 'in_app', null, 'sent'],
            ['order.out_for_delivery', 'sms', $customer['phone'], 'queued'],
            ['order.completed', 'in_app', null, 'sent'],
        ], $this->rows());

        $accepted = $this->db->table('wf_notifications')->where('notification_type', 'order.accepted')->where('channel', 'in_app')->get()->getRowArray();
        $this->assertSame('Chez Mama Fifi a accepté votre commande ' . $order['order_number'] . '.', $accepted['message']);
        $this->assertSame(['order_id' => $order['id'], 'order_number' => $order['order_number'], 'restaurant_id' => $restaurant['restaurant_id']], json_decode($accepted['data'], true));

        // Un événement rejeté avec l'action (transition invalide) ne crée aucune notification.
        $before = count($this->rows());
        $this->json('PATCH', "api/v1/restaurant/orders/{$order['id']}/status", ['status' => 'accepted'], $restaurant['token'])->assertStatus(409);
        $this->assertCount($before, $this->rows());
    }

    public function testCancellationAndRejectionNotifyTheOtherParty(): void
    {
        $restaurant = $this->openRestaurant();
        $customer   = $this->customer();

        $first = $this->order($restaurant, $customer);
        $this->json('POST', "api/v1/customer/orders/{$first['id']}/cancel", [], $customer['token'])->assertStatus(200);
        $this->seeInDatabase('wf_notifications', ['notification_type' => 'order.cancelled_by_customer', 'user_id' => null, 'restaurant_id' => $restaurant['restaurant_id']]);

        $second = $this->order($restaurant, $customer, 'pickup');
        $this->json('PATCH', "api/v1/restaurant/orders/{$second['id']}/status", ['status' => 'rejected', 'reason' => 'Rupture de stock'], $restaurant['token'])->assertStatus(200);

        $rejected = $this->db->table('wf_notifications')->where('notification_type', 'order.rejected')->get()->getRowArray();
        $this->assertSame((int) $customer['user_id'], (int) $rejected['user_id']);
        $this->assertStringEndsWith(': Rupture de stock', $rejected['message']);
    }

    public function testInboxesAreIsolatedAndCanBeMarkedRead(): void
    {
        $restaurant = $this->openRestaurant();
        $other      = $this->openRestaurant();
        $customer   = $this->customer();
        $order      = $this->order($restaurant, $customer);
        $this->order($restaurant, $customer, 'pickup');
        $this->json('PATCH', "api/v1/restaurant/orders/{$order['id']}/status", ['status' => 'accepted'], $restaurant['token'])->assertStatus(200);

        $inbox = $this->body($this->json('GET', 'api/v1/restaurant/notifications', [], $restaurant['token']));
        $this->assertSame([2, 2], [count($inbox['data']), $inbox['meta']['unread_count']]);
        $this->assertSame(['order.placed', 'order.placed'], array_column($inbox['data'], 'type'));
        $this->assertSame(0, $this->body($this->json('GET', 'api/v1/restaurant/notifications', [], $other['token']))['meta']['unread_count']);

        $mine = $this->body($this->json('GET', 'api/v1/notifications', [], $customer['token']));
        $this->assertSame([['order.accepted'], 1], [array_column($mine['data'], 'type'), $mine['meta']['unread_count']]);

        // Chacun ne lit que sa boîte.
        $restaurantNotificationId = $inbox['data'][0]['id'];
        $this->json('PATCH', "api/v1/notifications/{$restaurantNotificationId}/read", [], $customer['token'])->assertStatus(404);
        $this->json('PATCH', "api/v1/restaurant/notifications/{$restaurantNotificationId}/read", [], $other['token'])->assertStatus(404);
        $this->json('PATCH', "api/v1/restaurant/notifications/{$mine['data'][0]['id']}/read", [], $restaurant['token'])->assertStatus(404);

        $read = $this->body($this->json('PATCH', "api/v1/restaurant/notifications/{$restaurantNotificationId}/read", [], $restaurant['token']))['data'];
        $this->assertTrue($read['is_read']);

        $unread = $this->body($this->json('GET', 'api/v1/restaurant/notifications?unread=true', [], $restaurant['token']));
        $this->assertSame([1, 1], [count($unread['data']), $unread['meta']['unread_count']]);

        $this->assertSame(1, $this->body($this->json('POST', 'api/v1/restaurant/notifications/read-all', [], $restaurant['token']))['data']['updated']);
        $this->assertSame(1, $this->body($this->json('POST', 'api/v1/notifications/read-all', [], $customer['token']))['data']['updated']);
        $this->assertSame(0, $this->body($this->json('GET', 'api/v1/notifications', [], $customer['token']))['meta']['unread_count']);
    }

    public function testQueueSendsRetriesWithBackoffAndGivesUp(): void
    {
        $registry   = $this->enableChannels($this->sms);
        $restaurant = $this->openRestaurant();
        $customer   = $this->customer();
        $this->order($restaurant, $customer);
        $this->order($restaurant, $customer);

        [$first, $second] = array_map('intval', array_column($this->db->table('wf_notifications')->where('channel', 'sms')->orderBy('id')->get()->getResultArray(), 'id'));
        $this->db->table('wf_notifications')->where('id', $first)->update(['max_attempts' => 2]);

        $now = time();
        $this->sms->failures = [new ChannelException('Délai dépassé'), new ChannelException('Délai dépassé')];

        // 1re passe : la première est en échec temporaire, la seconde aussi.
        $this->assertSame(['processed' => 2, 'sent' => 0, 'retry' => 2, 'failed' => 0], $this->processor($registry)->process(null, $now));
        $row = $this->db->table('wf_notifications')->where('id', $first)->get()->getRowArray();
        $this->assertSame(['queued', '1', gmdate('Y-m-d H:i:s', $now + 60), 'Délai dépassé'], [$row['status'], $row['attempts'], $row['next_attempt_at'], $row['last_error']]);

        // Avant le délai : rien n'est retenté.
        $this->assertSame(0, $this->processor($registry)->process(null, $now + 30)['processed']);

        // Après le délai : la première échoue de nouveau et atteint son plafond (2) ; la seconde part.
        $this->sms->failures = [new ChannelException('Délai dépassé')];
        $stats = $this->processor($registry)->process(null, $now + 61);

        $this->assertSame(['processed' => 2, 'sent' => 1, 'retry' => 0, 'failed' => 1], $stats);
        $this->seeInDatabase('wf_notifications', ['id' => $first, 'status' => 'failed', 'attempts' => 2, 'next_attempt_at' => null]);
        $this->seeInDatabase('wf_notifications', ['id' => $second, 'status' => 'sent', 'attempts' => 2, 'last_error' => null]);
        $this->seeInDatabase('wf_notification_attempts', ['notification_id' => $second, 'attempt_number' => 2, 'status' => 'sent', 'provider' => 'fake', 'provider_message_id' => 'fake-1']);
        $this->seeNumRecords(4, 'wf_notification_attempts', []);
        $this->assertSame(1, count($this->sms->sent));
    }

    public function testPermanentFailuresLocksAndDisabledChannels(): void
    {
        $registry   = $this->enableChannels($this->sms);
        $restaurant = $this->openRestaurant();
        $customer   = $this->customer();
        $this->order($restaurant, $customer);
        $this->order($restaurant, $customer);
        $this->order($restaurant, $customer);
        [$invalid, $locked, $orphan] = array_map('intval', array_column($this->db->table('wf_notifications')->where('channel', 'sms')->orderBy('id')->get()->getResultArray(), 'id'));

        // Verrouillée par un autre processus : ignorée.
        $this->db->table('wf_notifications')->where('id', $locked)->update(['locked_until' => gmdate('Y-m-d H:i:s', time() + 120)]);

        $this->sms->failures = [ChannelException::permanent('Numéro invalide')];
        $stats = $this->processor($registry)->process();

        $this->assertSame(['processed' => 2, 'sent' => 1, 'retry' => 0, 'failed' => 1], $stats);
        $this->seeInDatabase('wf_notifications', ['id' => $invalid, 'status' => 'failed', 'attempts' => 1, 'last_error' => 'Numéro invalide']);
        $this->seeInDatabase('wf_notifications', ['id' => $locked, 'status' => 'queued', 'attempts' => 0]);
        $this->seeInDatabase('wf_notifications', ['id' => $orphan, 'status' => 'sent']);

        // Canal désactivé entre la mise en file et l'envoi : échec définitif, sans exception.
        $this->db->table('wf_notifications')->where('id', $locked)->update(['locked_until' => null]);
        $stats = $this->processor(new NotificationChannelRegistry([]))->process();
        $this->assertSame(['processed' => 1, 'sent' => 0, 'retry' => 0, 'failed' => 1], $stats);
        $this->seeInDatabase('wf_notifications', ['id' => $locked, 'status' => 'failed', 'last_error' => 'Canal « sms » désactivé.']);
        $this->seeInDatabase('wf_notification_attempts', ['notification_id' => $locked, 'provider' => 'none', 'status' => 'failed']);
    }

    public function testAdminSupervisesAndRequeuesFailedNotifications(): void
    {
        $admin      = $this->adminToken();
        $registry   = $this->enableChannels($this->sms);
        $restaurant = $this->openRestaurant();
        $this->order($restaurant, $this->customer());
        $id = (int) $this->db->table('wf_notifications')->where('channel', 'sms')->get()->getRow()->id;

        $this->json('POST', "api/v1/admin/notifications/{$id}/retry", [], $admin)->assertStatus(409);

        $this->sms->failures = [ChannelException::permanent('Fournisseur hors service')];
        $this->processor($registry)->process();

        $failed = $this->body($this->json('GET', 'api/v1/admin/notifications?status=failed&channel=sms', [], $admin))['data'];
        $this->assertSame([[$id, 'failed', 'Fournisseur hors service']], array_map(static fn (array $n): array => [$n['id'], $n['status'], $n['last_error']], $failed));
        $this->json('GET', 'api/v1/admin/notifications?status=lost', [], $admin)->assertStatus(422);
        $this->json('GET', 'api/v1/admin/notifications', [], $restaurant['token'])->assertStatus(403);

        $requeued = $this->body($this->json('POST', "api/v1/admin/notifications/{$id}/retry", [], $admin))['data'];
        $this->assertSame(['queued', 1, 6], [$requeued['status'], $requeued['attempts'], $requeued['max_attempts']]);
        $this->assertSame([[1, 'failed', 'Fournisseur hors service']], array_map(static fn (array $a): array => [$a['attempt'], $a['status'], $a['error']], $requeued['attempt_history']));

        $this->processor($registry)->process();
        $detail = $this->body($this->json('GET', "api/v1/admin/notifications/{$id}", [], $admin))['data'];
        $this->assertSame(['sent', 2], [$detail['status'], count($detail['attempt_history'])]);
        $this->seeInDatabase('wf_audit_logs', ['action' => 'notification.requeued', 'entity_id' => $id]);
    }

    public function testRestaurantStatusChangesNotifyTheOwner(): void
    {
        $this->enableChannels($this->sms, $this->email);
        $admin = $this->adminToken();
        $owner = $this->ownerWithRestaurant('pending');
        $this->db->table('wf_users')->where('id', $owner['user_id'])->update(['email' => 'fifi@example.com']);

        $this->json('PATCH', "api/v1/admin/restaurants/{$owner['restaurant_id']}/reject", ['reason' => 'Documents illisibles'], $admin)->assertStatus(200);

        $this->assertSame([
            ['restaurant.rejected', 'in_app', null, 'sent'],
            ['restaurant.rejected', 'email', 'fifi@example.com', 'queued'],
            ['restaurant.rejected', 'sms', $owner['phone'], 'queued'],
        ], $this->rows());

        $inbox = $this->body($this->json('GET', 'api/v1/notifications', [], $owner['token']))['data'];
        $this->assertSame('L\'inscription de Chez Mama Fifi a été refusée : Documents illisibles', $inbox[0]['message']);
    }

    public function testDispatcherNeverBreaksTheBusinessAction(): void
    {
        $restaurant = $this->openRestaurant();
        $order      = $this->order($restaurant, $this->customer());
        $before     = $this->rows();

        // Événement sans modèle de message : journalisé, aucune exception remontée.
        (new NotificationDispatcher())->orderEvent('order.unknown', $order['id']);
        (new NotificationDispatcher())->restaurantEvent('restaurant.unknown', $restaurant['restaurant_id']);
        (new NotificationDispatcher())->orderEvent('order.accepted', 999_999);

        $this->assertSame($before, $this->rows());
    }
}
