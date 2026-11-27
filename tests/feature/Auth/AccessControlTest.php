<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use CodeIgniter\HTTP\ResponseInterface;
use Tests\Support\ApiTestCase;

/**
 * Filtres d'accès (rôles globaux, isolation par restaurant), testés sur des routes de test
 * déclarées uniquement dans ce fichier (aucune route factice dans l'application).
 *
 * @internal
 */
final class AccessControlTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $echo = static function (): ResponseInterface {
            $context    = service('authContext');
            $restaurant = null;

            try {
                $restaurant = $context->restaurant();
            } catch (\Throwable) {
            }

            return service('response')->setJSON([
                'user_id'       => $context->userId(),
                'restaurant_id' => $restaurant?->restaurantId,
                'role'          => $restaurant?->restaurantRole,
            ]);
        };

        $this->withRoutes([
            ['get', 'test/active', $echo, ['filter' => 'jwt']],
            ['get', 'test/admin', $echo, ['filter' => ['jwt', 'role:super_admin']]],
            ['get', 'test/owners', $echo, ['filter' => ['jwt', 'role:restaurant_owner,super_admin']]],
            ['get', 'test/restaurant/orders', $echo, ['filter' => ['jwt', 'restaurant:orders.view']]],
            ['get', 'test/restaurant/catalog', $echo, ['filter' => ['jwt', 'restaurant:catalog.manage']]],
            ['get', 'test/restaurant/finance', $echo, ['filter' => ['jwt', 'restaurant:finance.view']]],
        ]);
    }

    public function testPendingAccountCannotUseProtectedFeatures(): void
    {
        $account  = $this->registerUser(verifyPhone: false);
        $response = $this->json('GET', 'test/active', [], $account['tokens']['access_token']);

        $response->assertStatus(403);
        $this->assertSame('ACCOUNT_NOT_VERIFIED', $this->body($response)['meta']['code']);
    }

    public function testRoleFilter(): void
    {
        $customer = $this->registerUser();
        $owner    = $this->registerUser('restaurant_owner');

        $this->json('GET', 'test/admin', [], $customer['tokens']['access_token'])->assertStatus(403);
        $this->json('GET', 'test/admin', [], $owner['tokens']['access_token'])->assertStatus(403);
        $this->json('GET', 'test/owners', [], $owner['tokens']['access_token'])->assertStatus(200);
        $this->json('GET', 'test/owners', [], $customer['tokens']['access_token'])->assertStatus(403);

        // Les rôles sont relus en base : en ajouter un prend effet sans nouveau jeton.
        $this->db->table('wf_user_roles')->insert(['user_id' => $customer['user']['id'], 'role_id' => 5]);
        $this->json('GET', 'test/admin', [], $customer['tokens']['access_token'])->assertStatus(200);
    }

    public function testOwnerResolvesOwnRestaurantAndCannotReachAnother(): void
    {
        $ownerA = $this->registerUser('restaurant_owner');
        $ownerB = $this->registerUser('restaurant_owner');
        $restoA = $this->createRestaurant($ownerA['user']['id']);
        $restoB = $this->createRestaurant($ownerB['user']['id']);

        $own = $this->json('GET', 'test/restaurant/finance', [], $ownerA['tokens']['access_token']);
        $own->assertStatus(200);
        $this->assertSame($restoA, $this->body($own)['restaurant_id']);
        $this->assertSame('owner', $this->body($own)['role']);

        // Tentative d'accès inter-restaurants via l'en-tête.
        $cross = $this->json('GET', 'test/restaurant/orders', [], $ownerA['tokens']['access_token'], ['X-Restaurant-Id' => (string) $restoB]);
        $cross->assertStatus(403);
        $this->assertSame('RESTAURANT_ACCESS_DENIED', $this->body($cross)['meta']['code']);

        $this->json('GET', 'test/restaurant/orders', [], $ownerA['tokens']['access_token'], ['X-Restaurant-Id' => 'abc'])->assertStatus(400);
    }

    public function testStaffPermissionsAreLimitedToTheirRole(): void
    {
        $owner = $this->registerUser('restaurant_owner');
        $staff = $this->registerUser();
        $resto = $this->createRestaurant($owner['user']['id']);
        $this->addMember($resto, $staff['user']['id'], 'staff');

        $token = $staff['tokens']['access_token'];

        $this->json('GET', 'test/restaurant/orders', [], $token)->assertStatus(200);

        $catalog = $this->json('GET', 'test/restaurant/catalog', [], $token);
        $catalog->assertStatus(403);
        $this->assertSame('PERMISSION_DENIED', $this->body($catalog)['meta']['code']);

        $this->json('GET', 'test/restaurant/finance', [], $token)->assertStatus(403);
    }

    public function testCashierAndManagerPermissions(): void
    {
        $owner   = $this->registerUser('restaurant_owner');
        $cashier = $this->registerUser();
        $manager = $this->registerUser();
        $resto   = $this->createRestaurant($owner['user']['id']);
        $this->addMember($resto, $cashier['user']['id'], 'cashier');
        $this->addMember($resto, $manager['user']['id'], 'manager');

        $this->json('GET', 'test/restaurant/catalog', [], $cashier['tokens']['access_token'])->assertStatus(403);
        $this->json('GET', 'test/restaurant/catalog', [], $manager['tokens']['access_token'])->assertStatus(200);
        // Finances : réservées au propriétaire.
        $this->json('GET', 'test/restaurant/finance', [], $manager['tokens']['access_token'])->assertStatus(403);
    }

    public function testDisabledMembershipLosesAccess(): void
    {
        $owner = $this->registerUser('restaurant_owner');
        $staff = $this->registerUser();
        $resto = $this->createRestaurant($owner['user']['id']);
        $this->addMember($resto, $staff['user']['id'], 'staff', 'disabled');

        $this->json('GET', 'test/restaurant/orders', [], $staff['tokens']['access_token'], ['X-Restaurant-Id' => (string) $resto])
            ->assertStatus(403);
    }

    public function testMemberOfSeveralRestaurantsMustChoose(): void
    {
        $owner = $this->registerUser('restaurant_owner');
        $first = $this->createRestaurant($owner['user']['id']);
        $this->createRestaurant($owner['user']['id']);

        $ambiguous = $this->json('GET', 'test/restaurant/orders', [], $owner['tokens']['access_token']);
        $ambiguous->assertStatus(403);
        $this->assertSame('RESTAURANT_REQUIRED', $this->body($ambiguous)['meta']['code']);

        $chosen = $this->json('GET', 'test/restaurant/orders', [], $owner['tokens']['access_token'], ['X-Restaurant-Id' => (string) $first]);
        $chosen->assertStatus(200);
        $this->assertSame($first, $this->body($chosen)['restaurant_id']);
    }

    public function testDeletedRestaurantIsNotAccessible(): void
    {
        $owner = $this->registerUser('restaurant_owner');
        $resto = $this->createRestaurant($owner['user']['id']);
        $this->db->table('wf_restaurants')->where('id', $resto)->update(['deleted_at' => gmdate('Y-m-d H:i:s')]);

        $this->json('GET', 'test/restaurant/orders', [], $owner['tokens']['access_token'], ['X-Restaurant-Id' => (string) $resto])
            ->assertStatus(403);
    }
}
