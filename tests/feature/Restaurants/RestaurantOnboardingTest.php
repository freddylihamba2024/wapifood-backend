<?php

declare(strict_types=1);

namespace Tests\Feature\Restaurants;

use Tests\Support\ApiTestCase;

/**
 * Inscription, validation, rejet, suspension d'un restaurant.
 *
 * @internal
 */
final class RestaurantOnboardingTest extends ApiTestCase
{
    public function testOwnerRegistersPendingRestaurantWithPlatformCommission(): void
    {
        $this->db->table('wf_platform_settings')->where('setting_key', 'default_commission_rate')->update(['setting_value' => '12.50']);

        $owner    = $this->registerUser('restaurant_owner');
        $response = $this->json('POST', 'api/v1/restaurants', [
            'name'                 => 'Chez Mama Fifi',
            'phone'                => '081 200 0111',
            'currency'             => 'CDF',
            'minimum_order_amount' => 5000,
            'delivery_fee'         => '2500',
            'commission_rate'      => '0.00', // ignoré : fixé par la plateforme
            'status'               => 'active', // ignoré
            'is_featured'          => true, // ignoré
            'address'              => ['address_line' => '12 avenue du Commerce', 'commune' => 'Gombe'],
        ], $owner['tokens']['access_token']);

        $response->assertStatus(201);
        $data = $this->body($response)['data'];

        $this->assertSame('pending', $data['status']);
        $this->assertSame('chez-mama-fifi', $data['slug']);
        $this->assertSame('12.50', $data['commission_rate']);
        $this->assertSame('CDF', $data['currency']);
        $this->assertSame('5000.00', $data['minimum_order_amount']);
        $this->assertSame('2500.00', $data['delivery_fee']);
        $this->assertSame('+243812000111', $data['phone']);
        $this->assertFalse($data['is_featured']);
        $this->assertSame('Kinshasa', $data['addresses'][0]['city']);
        $this->assertTrue($data['addresses'][0]['is_primary']);

        $this->seeInDatabase('wf_restaurant_users', ['restaurant_id' => $data['id'], 'user_id' => $owner['user']['id'], 'restaurant_role' => 'owner']);
    }

    public function testSlugIsUniqueAndAddressRequired(): void
    {
        $first  = $this->ownerWithRestaurant('pending');
        $second = $this->ownerWithRestaurant('pending');

        $this->assertNotSame(
            $this->body($this->json('GET', 'api/v1/restaurant/profile', [], $first['token']))['data']['slug'],
            $this->body($this->json('GET', 'api/v1/restaurant/profile', [], $second['token']))['data']['slug'],
        );

        $owner   = $this->registerUser('restaurant_owner');
        $missing = $this->json('POST', 'api/v1/restaurants', ['name' => 'Sans adresse', 'phone' => '0812000111'], $owner['tokens']['access_token']);
        $missing->assertStatus(422);
        $this->assertArrayHasKey('address', $this->body($missing)['errors']);

        $noService = $this->json('POST', 'api/v1/restaurants', [
            'name' => 'Ni livraison ni retrait', 'phone' => '0812000111', 'accepts_delivery' => false, 'accepts_pickup' => false,
            'address' => ['address_line' => 'Avenue X'],
        ], $owner['tokens']['access_token']);
        $noService->assertStatus(422);
    }

    public function testOnlyVerifiedOwnersCanRegisterRestaurants(): void
    {
        $customer = $this->registerUser();
        $pending  = $this->registerUser('restaurant_owner', verifyPhone: false);
        $body     = ['name' => 'X', 'phone' => '0812000111', 'address' => ['address_line' => 'Avenue X']];

        $this->json('POST', 'api/v1/restaurants', $body, $customer['tokens']['access_token'])->assertStatus(403);
        $this->json('POST', 'api/v1/restaurants', $body, $pending['tokens']['access_token'])->assertStatus(403);
        $this->json('POST', 'api/v1/restaurants', $body)->assertStatus(401);
    }

    public function testPendingRestaurantIsHiddenUntilApproved(): void
    {
        $owner = $this->ownerWithRestaurant('pending');
        $admin = $this->adminToken();
        $id    = $owner['restaurant_id'];

        $this->json('GET', "api/v1/restaurants/{$id}")->assertStatus(404);
        $this->assertSame(0, $this->body($this->json('GET', 'api/v1/restaurants'))['meta']['pagination']['total']);

        $queue = $this->body($this->json('GET', 'api/v1/admin/restaurants?status=pending', [], $admin));
        $this->assertSame($id, $queue['data'][0]['id']);
        $this->assertSame($owner['phone'], $queue['data'][0]['owner']['phone']);

        $approved = $this->json('PATCH', "api/v1/admin/restaurants/{$id}/approve", [], $admin);
        $approved->assertStatus(200);
        $this->assertSame('active', $this->body($approved)['data']['status']);
        $this->assertNotNull($this->body($approved)['data']['approved_at']);

        $this->json('GET', "api/v1/restaurants/{$id}")->assertStatus(200);
        $this->seeInDatabase('wf_audit_logs', ['action' => 'restaurant.approved', 'entity_id' => $id]);

        // On ne valide pas deux fois.
        $this->json('PATCH', "api/v1/admin/restaurants/{$id}/approve", [], $admin)->assertStatus(409);
    }

    public function testRejectionAndResubmission(): void
    {
        $owner = $this->ownerWithRestaurant('pending');
        $admin = $this->adminToken();
        $id    = $owner['restaurant_id'];

        $this->json('PATCH', "api/v1/admin/restaurants/{$id}/reject", [], $admin)->assertStatus(422);

        $rejected = $this->json('PATCH', "api/v1/admin/restaurants/{$id}/reject", ['reason' => 'Photos du menu manquantes.'], $admin);
        $rejected->assertStatus(200);

        $profile = $this->body($this->json('GET', 'api/v1/restaurant/profile', [], $owner['token']))['data'];
        $this->assertSame('rejected', $profile['status']);
        $this->assertSame('Photos du menu manquantes.', $profile['status_reason']);

        $this->json('POST', 'api/v1/restaurant/submit', [], $owner['token'])->assertStatus(200);
        $this->seeInDatabase('wf_restaurants', ['id' => $id, 'status' => 'pending']);
        $this->json('POST', 'api/v1/restaurant/submit', [], $owner['token'])->assertStatus(409);
    }

    public function testSuspendedRestaurantIsHiddenAndReadOnly(): void
    {
        $owner = $this->ownerWithRestaurant('active');
        $admin = $this->adminToken();
        $id    = $owner['restaurant_id'];

        $this->json('PATCH', "api/v1/admin/restaurants/{$id}/suspend", ['reason' => 'Plaintes répétées des clients.'], $admin)->assertStatus(200);

        $this->json('GET', "api/v1/restaurants/{$id}")->assertStatus(404);
        $this->json('GET', 'api/v1/restaurant/profile', [], $owner['token'])->assertStatus(200);

        $blocked = $this->json('PUT', 'api/v1/restaurant/profile', ['name' => 'Nouveau nom'], $owner['token']);
        $blocked->assertStatus(403);
        $this->assertSame('RESTAURANT_SUSPENDED', $this->body($blocked)['meta']['code']);
        $this->json('POST', 'api/v1/restaurant/categories', ['name' => 'Plats'], $owner['token'])->assertStatus(403);

        // Le restaurant ne peut pas se réactiver lui-même.
        $this->json('PATCH', 'api/v1/restaurant/availability', ['is_paused' => false], $owner['token'])->assertStatus(403);

        $this->json('PATCH', "api/v1/admin/restaurants/{$id}/reactivate", [], $admin)->assertStatus(200);
        $this->json('GET', "api/v1/restaurants/{$id}")->assertStatus(200);
    }

    public function testOwnerCanPauseAndResume(): void
    {
        $owner = $this->ownerWithRestaurant('active');

        $paused = $this->json('PATCH', 'api/v1/restaurant/availability', ['is_paused' => true], $owner['token']);
        $paused->assertStatus(200);
        $this->assertSame('paused', $this->body($paused)['data']['status']);

        // Visible publiquement, mais signalé comme fermé.
        $public = $this->body($this->json('GET', "api/v1/restaurants/{$owner['restaurant_id']}"))['data'];
        $this->assertTrue($public['is_paused']);
        $this->assertFalse($public['is_open_now']);

        $this->json('PATCH', 'api/v1/restaurant/availability', ['is_paused' => false], $owner['token'])->assertStatus(200);
        $this->json('PATCH', 'api/v1/restaurant/availability', ['is_paused' => 'peut-être'], $owner['token'])->assertStatus(422);

        $pending = $this->ownerWithRestaurant('pending');
        $this->json('PATCH', 'api/v1/restaurant/availability', ['is_paused' => true], $pending['token'])->assertStatus(409);
    }

    public function testCommissionRateIsAdminOnlyAndBounded(): void
    {
        $owner = $this->ownerWithRestaurant('active');
        $admin = $this->adminToken();
        $id    = $owner['restaurant_id'];

        $this->json('PATCH', "api/v1/admin/restaurants/{$id}/commission", ['commission_rate' => '8.5'], $owner['token'])->assertStatus(403);
        $this->json('PATCH', "api/v1/admin/restaurants/{$id}/commission", ['commission_rate' => '100.01'], $admin)->assertStatus(422);
        $this->json('PATCH', "api/v1/admin/restaurants/{$id}/commission", ['commission_rate' => '-1'], $admin)->assertStatus(422);

        $ok = $this->json('PATCH', "api/v1/admin/restaurants/{$id}/commission", ['commission_rate' => 8.5], $admin);
        $ok->assertStatus(200);
        $this->assertSame('8.50', $this->body($ok)['data']['commission_rate']);

        // Le restaurant ne peut pas modifier son taux via son profil.
        $this->json('PUT', 'api/v1/restaurant/profile', ['commission_rate' => '0'], $owner['token'])->assertStatus(200);
        $this->seeInDatabase('wf_restaurants', ['id' => $id, 'commission_rate' => '8.50']);
    }

    public function testAdminRoutesRequireSuperAdmin(): void
    {
        $owner = $this->ownerWithRestaurant('pending');

        $this->json('GET', 'api/v1/admin/restaurants', [], $owner['token'])->assertStatus(403);
        $this->json('PATCH', "api/v1/admin/restaurants/{$owner['restaurant_id']}/approve", [], $owner['token'])->assertStatus(403);
        $this->json('GET', 'api/v1/admin/restaurants')->assertStatus(401);
        $this->json('GET', 'api/v1/admin/restaurants/abc', [], $this->adminToken())->assertStatus(404);
    }
}
