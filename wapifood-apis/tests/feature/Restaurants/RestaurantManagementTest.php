<?php

declare(strict_types=1);

namespace Tests\Feature\Restaurants;

use Tests\Support\ApiTestCase;

/**
 * Profil, adresses, horaires et équipe d'un restaurant — isolation entre restaurants.
 *
 * @internal
 */
final class RestaurantManagementTest extends ApiTestCase
{
    public function testProfileUpdate(): void
    {
        $owner    = $this->ownerWithRestaurant();
        $response = $this->json('PUT', 'api/v1/restaurant/profile', [
            'description'             => 'Cuisine congolaise',
            'delivery_fee'            => 2,
            'free_delivery_threshold' => '30',
            'accepts_pickup'          => false,
            'timezone'                => 'Africa/Lubumbashi',
        ], $owner['token']);

        $response->assertStatus(200);
        $data = $this->body($response)['data'];
        $this->assertSame('Cuisine congolaise', $data['description']);
        $this->assertSame('2.00', $data['delivery_fee']);
        $this->assertSame('30.00', $data['free_delivery_threshold']);
        $this->assertFalse($data['accepts_pickup']);
        $this->assertSame('Africa/Lubumbashi', $data['timezone']);

        $this->json('PUT', 'api/v1/restaurant/profile', ['accepts_delivery' => false], $owner['token'])->assertStatus(422);
        $this->json('PUT', 'api/v1/restaurant/profile', ['delivery_fee' => '1.234'], $owner['token'])->assertStatus(422);
        $this->json('PUT', 'api/v1/restaurant/profile', ['timezone' => 'Europe/Paris'], $owner['token'])->assertStatus(422);
    }

    public function testCurrencyIsLockedOnceProductsExist(): void
    {
        $owner = $this->ownerWithRestaurant();

        $this->json('PUT', 'api/v1/restaurant/profile', ['currency' => 'CDF'], $owner['token'])->assertStatus(200);
        $this->json('POST', 'api/v1/restaurant/products', ['name' => 'Pondu', 'base_price' => '5000'], $owner['token'])->assertStatus(201);

        $locked = $this->json('PUT', 'api/v1/restaurant/profile', ['currency' => 'USD'], $owner['token']);
        $locked->assertStatus(409);
        $this->assertSame('CURRENCY_LOCKED', $this->body($locked)['meta']['code']);
    }

    public function testAddressesKeepExactlyOnePrimary(): void
    {
        $owner = $this->ownerWithRestaurant();

        $second = $this->body($this->json('POST', 'api/v1/restaurant/addresses', [
            'address_line' => '45 boulevard du 30 Juin', 'commune' => 'Gombe', 'is_primary' => true, 'latitude' => -4.3105, 'longitude' => 15.3136,
        ], $owner['token']))['data'];

        $addresses = $this->body($this->json('GET', 'api/v1/restaurant/addresses', [], $owner['token']))['data'];
        $this->assertCount(2, $addresses);
        $this->assertCount(1, array_filter($addresses, static fn (array $a): bool => $a['is_primary']));
        $this->assertSame($second['id'], $addresses[0]['id']);

        $this->json('DELETE', "api/v1/restaurant/addresses/{$second['id']}", [], $owner['token'])->assertStatus(204);

        $remaining = $this->body($this->json('GET', 'api/v1/restaurant/addresses', [], $owner['token']))['data'];
        $this->assertTrue($remaining[0]['is_primary']);

        $last = $this->json('DELETE', "api/v1/restaurant/addresses/{$remaining[0]['id']}", [], $owner['token']);
        $last->assertStatus(409);
    }

    public function testCannotTouchAnotherRestaurantsAddress(): void
    {
        $alice = $this->ownerWithRestaurant();
        $bob   = $this->ownerWithRestaurant();

        $bobAddress = $this->body($this->json('GET', 'api/v1/restaurant/addresses', [], $bob['token']))['data'][0]['id'];

        $this->json('PUT', "api/v1/restaurant/addresses/{$bobAddress}", ['address_line' => 'Piratée'], $alice['token'])->assertStatus(404);
        $this->json('DELETE', "api/v1/restaurant/addresses/{$bobAddress}", [], $alice['token'])->assertStatus(404);
        $this->seeInDatabase('wf_restaurant_addresses', ['id' => $bobAddress, 'address_line' => '12 avenue du Commerce']);
    }

    public function testOpeningHoursReplaceAndValidation(): void
    {
        $owner = $this->ownerWithRestaurant();

        $response = $this->json('PUT', 'api/v1/restaurant/opening-hours', ['days' => [
            ['day_of_week' => 1, 'opens_at' => '09:00', 'closes_at' => '22:00'],
            ['day_of_week' => 5, 'opens_at' => '18:00', 'closes_at' => '02:00'],
            ['day_of_week' => 7, 'is_closed' => true],
        ]], $owner['token']);

        $response->assertStatus(200);
        $hours = $this->body($response)['data'];
        $this->assertCount(3, $hours);
        $this->assertSame(['day_of_week' => 5, 'is_closed' => false, 'opens_at' => '18:00', 'closes_at' => '02:00'], $hours[1]);

        $invalid = $this->json('PUT', 'api/v1/restaurant/opening-hours', ['days' => [
            ['day_of_week' => 8, 'opens_at' => '09:00', 'closes_at' => '22:00'],
            ['day_of_week' => 2, 'opens_at' => '25:00', 'closes_at' => '22:00'],
        ]], $owner['token']);
        $invalid->assertStatus(422);
        $this->assertArrayHasKey('days.0.day_of_week', $this->body($invalid)['errors']);
        $this->assertArrayHasKey('days.1.opens_at', $this->body($invalid)['errors']);

        $duplicate = $this->json('PUT', 'api/v1/restaurant/opening-hours', ['days' => [
            ['day_of_week' => 1, 'is_closed' => true],
            ['day_of_week' => 1, 'is_closed' => true],
        ]], $owner['token']);
        $duplicate->assertStatus(422);

        // Le planning précédent n'a pas été modifié par les requêtes invalides.
        $this->assertCount(3, $this->body($this->json('GET', 'api/v1/restaurant/opening-hours', [], $owner['token']))['data']);
    }

    public function testStaffManagementByOwnerOnly(): void
    {
        $owner   = $this->ownerWithRestaurant();
        $manager = $this->registerUser();
        $staff   = $this->registerUser();

        $added = $this->json('POST', 'api/v1/restaurant/staff', ['phone' => $manager['phone'], 'role' => 'manager'], $owner['token']);
        $added->assertStatus(201);
        $managerMember = $this->body($added)['data'];
        $this->assertSame('manager', $managerMember['role']);
        $this->assertContains('restaurant_staff', $this->body($this->json('GET', 'api/v1/auth/me', [], $manager['tokens']['access_token']))['data']['roles']);

        $this->json('POST', 'api/v1/restaurant/staff', ['phone' => $manager['phone'], 'role' => 'staff'], $owner['token'])->assertStatus(409);
        $this->json('POST', 'api/v1/restaurant/staff', ['phone' => '0899111222', 'role' => 'staff'], $owner['token'])->assertStatus(422);
        $this->json('POST', 'api/v1/restaurant/staff', ['phone' => $staff['phone'], 'role' => 'owner'], $owner['token'])->assertStatus(422);

        // Un gérant voit l'équipe mais ne la gère pas (permission réservée au propriétaire).
        $this->json('GET', 'api/v1/restaurant/staff', [], $manager['tokens']['access_token'])->assertStatus(200);
        $this->json('POST', 'api/v1/restaurant/staff', ['phone' => $staff['phone'], 'role' => 'staff'], $manager['tokens']['access_token'])->assertStatus(403);

        $staffMember = $this->body($this->json('POST', 'api/v1/restaurant/staff', ['phone' => $staff['phone'], 'role' => 'staff'], $owner['token']))['data'];

        $this->json('PATCH', "api/v1/restaurant/staff/{$staffMember['id']}", ['status' => 'disabled'], $owner['token'])->assertStatus(200);
        $this->json('GET', 'api/v1/restaurant/categories', [], $staff['tokens']['access_token'])->assertStatus(403);

        $ownerMember = current(array_filter(
            $this->body($this->json('GET', 'api/v1/restaurant/staff', [], $owner['token']))['data'],
            static fn (array $m): bool => $m['role'] === 'owner',
        ));
        $this->json('DELETE', "api/v1/restaurant/staff/{$ownerMember['id']}", [], $owner['token'])->assertStatus(403);

        $this->json('DELETE', "api/v1/restaurant/staff/{$managerMember['id']}", [], $owner['token'])->assertStatus(204);
        $this->assertNotContains('restaurant_staff', $this->body($this->json('GET', 'api/v1/auth/me', [], $manager['tokens']['access_token']))['data']['roles']);
    }

    public function testCannotManageAnotherRestaurantsStaff(): void
    {
        $alice  = $this->ownerWithRestaurant();
        $bob    = $this->ownerWithRestaurant();
        $worker = $this->registerUser();

        $member = $this->body($this->json('POST', 'api/v1/restaurant/staff', ['phone' => $worker['phone'], 'role' => 'staff'], $bob['token']))['data'];

        $this->json('PATCH', "api/v1/restaurant/staff/{$member['id']}", ['role' => 'manager'], $alice['token'])->assertStatus(404);
        $this->json('DELETE', "api/v1/restaurant/staff/{$member['id']}", [], $alice['token'])->assertStatus(404);
    }
}
