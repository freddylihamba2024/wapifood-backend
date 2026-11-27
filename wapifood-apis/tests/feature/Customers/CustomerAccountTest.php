<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use Tests\Support\OrderTestCase;

/**
 * Espace client : profil, carnet d'adresses, favoris, isolation entre clients.
 *
 * @internal
 */
final class CustomerAccountTest extends OrderTestCase
{
    public function testProfileUpdate(): void
    {
        $user  = $this->registerUser('customer', email: 'ancien@example.com');
        $token = $user['tokens']['access_token'];
        $this->db->table('wf_users')->where('id', $user['user']['id'])->update(['email_verified_at' => '2026-01-01 00:00:00']);

        $data = $this->body($this->json('PATCH', 'api/v1/auth/me', ['first_name' => ' Grâce ', 'last_name' => '', 'email' => 'Nouveau@Example.com'], $token))['data'];

        $this->assertSame('Grâce', $data['first_name']);
        $this->assertNull($data['last_name']);
        $this->assertSame('nouveau@example.com', $data['email']);
        $this->assertFalse($data['email_verified'], 'Un nouvel e-mail doit être vérifié à nouveau.');
        $this->assertSame($user['phone'], $data['phone']);

        // Le téléphone n'est pas modifiable par cette route (champ ignoré).
        $this->json('PATCH', 'api/v1/auth/me', ['phone' => '+243899999999'], $token)->assertStatus(200);
        $this->seeInDatabase('wf_users', ['id' => $user['user']['id'], 'phone' => $user['phone']]);

        $this->json('PATCH', 'api/v1/auth/me', ['first_name' => '   '], $token)->assertStatus(422);
        $this->json('PATCH', 'api/v1/auth/me', ['email' => 'pas-un-email'], $token)->assertStatus(422);

        $other = $this->registerUser('customer', email: 'pris@example.com');
        $this->assertSame('EMAIL_TAKEN', $this->body($this->json('PATCH', 'api/v1/auth/me', ['email' => 'pris@example.com'], $token))['meta']['code']);
        $this->assertNotSame($other['user']['id'], $user['user']['id']);

        $this->seeInDatabase('wf_audit_logs', ['action' => 'user.profile_updated', 'user_id' => $user['user']['id']]);
    }

    public function testAddressBookLifecycle(): void
    {
        $user  = $this->registerUser('customer');
        $token = $user['tokens']['access_token'];

        $home = $this->json('POST', 'api/v1/customer/addresses', ['address_line' => '12 avenue Lumumba', 'commune' => 'Limete', 'city' => ''], $token);
        $home->assertStatus(201);
        $home = $this->body($home)['data'];

        // Première adresse : par défaut ; destinataire, téléphone, ville et pays par défaut.
        $this->assertTrue($home['is_default']);
        $this->assertSame('Test Utilisateur', $home['recipient_name']);
        $this->assertSame($user['phone'], $home['recipient_phone']);
        $this->assertSame('Kinshasa', $home['city']);
        $this->assertSame('RDC', $home['country']);

        $office = $this->body($this->json('POST', 'api/v1/customer/addresses', [
            'label' => 'Bureau', 'address_line' => '3 boulevard du 30 Juin', 'recipient_phone' => '0997001122', 'is_default' => true,
        ], $token))['data'];
        $this->assertTrue($office['is_default']);
        $this->assertSame('+243997001122', $office['recipient_phone']);

        $list = $this->body($this->json('GET', 'api/v1/customer/addresses', [], $token))['data'];
        $this->assertSame([$office['id'], $home['id']], array_column($list, 'id'));
        $this->assertSame([true, false], array_column($list, 'is_default'));

        $updated = $this->body($this->json('PUT', "api/v1/customer/addresses/{$home['id']}", ['landmark' => 'Près du marché', 'address_line' => '', 'is_default' => true], $token))['data'];
        $this->assertSame('Près du marché', $updated['landmark']);
        $this->assertSame('12 avenue Lumumba', $updated['address_line'], 'Une adresse ne peut pas être vidée.');
        $this->assertTrue($updated['is_default']);

        $this->json('POST', 'api/v1/customer/addresses', ['address_line' => 'x'], $token)->assertStatus(422);
        $this->json('POST', 'api/v1/customer/addresses', ['address_line' => 'Rue 1', 'recipient_phone' => '123'], $token)->assertStatus(422);

        // Suppression logique de l'adresse par défaut : l'autre devient l'adresse par défaut.
        $this->json('DELETE', "api/v1/customer/addresses/{$home['id']}", [], $token)->assertStatus(204);
        $this->json('GET', "api/v1/customer/addresses/{$home['id']}", [], $token)->assertStatus(404);
        $this->assertNotNull($this->db->table('wf_customer_addresses')->where('id', $home['id'])->get()->getRow()->deleted_at);

        $list = $this->body($this->json('GET', 'api/v1/customer/addresses', [], $token))['data'];
        $this->assertSame([$office['id']], array_column($list, 'id'));
        $this->assertTrue($list[0]['is_default']);
    }

    public function testAddressLimit(): void
    {
        $user = $this->registerUser('customer');

        $this->db->table('wf_customer_addresses')->insertBatch(array_map(static fn (int $i): array => [
            'user_id' => $user['user']['id'], 'recipient_phone' => $user['phone'], 'address_line' => "Rue {$i}",
        ], range(1, 20)));

        $response = $this->json('POST', 'api/v1/customer/addresses', ['address_line' => 'Rue 21'], $user['tokens']['access_token']);
        $response->assertStatus(409);
        $this->assertSame('ADDRESS_LIMIT_REACHED', $this->body($response)['meta']['code']);
    }

    public function testAddressesAreIsolatedBetweenCustomers(): void
    {
        $alice = $this->customer();
        $bob   = $this->customer();

        $this->json('GET', "api/v1/customer/addresses/{$alice['address_id']}", [], $bob['token'])->assertStatus(404);
        $this->json('PUT', "api/v1/customer/addresses/{$alice['address_id']}", ['label' => 'Piratée'], $bob['token'])->assertStatus(404);
        $this->json('DELETE', "api/v1/customer/addresses/{$alice['address_id']}", [], $bob['token'])->assertStatus(404);
        $this->assertCount(1, $this->body($this->json('GET', 'api/v1/customer/addresses', [], $bob['token']))['data']);

        $this->seeInDatabase('wf_customer_addresses', ['id' => $alice['address_id'], 'label' => 'Maison', 'deleted_at' => null]);
    }

    public function testCustomerAreaRequiresVerifiedCustomer(): void
    {
        $this->json('GET', 'api/v1/customer/addresses')->assertStatus(401);

        $pending = $this->registerUser('customer', verifyPhone: false);
        $this->json('GET', 'api/v1/customer/addresses', [], $pending['tokens']['access_token'])->assertStatus(403);

        $owner = $this->registerUser('restaurant_owner');
        $response = $this->json('GET', 'api/v1/customer/orders', [], $owner['tokens']['access_token']);
        $response->assertStatus(403);
        $this->assertSame('FORBIDDEN_ROLE', $this->body($response)['meta']['code']);
    }

    public function testFavorites(): void
    {
        $customer = $this->customer();
        $open     = $this->openRestaurant();
        $other    = $this->ownerWithRestaurant('active');
        $pending  = $this->ownerWithRestaurant('pending');

        $this->json('PUT', "api/v1/customer/favorites/{$open['restaurant_id']}", [], $customer['token'])->assertStatus(204);
        $this->json('PUT', "api/v1/customer/favorites/{$open['restaurant_id']}", [], $customer['token'])->assertStatus(204);
        $this->json('PUT', "api/v1/customer/favorites/{$other['restaurant_id']}", [], $customer['token'])->assertStatus(204);
        $this->json('PUT', "api/v1/customer/favorites/{$pending['restaurant_id']}", [], $customer['token'])->assertStatus(404);
        $this->json('PUT', 'api/v1/customer/favorites/999999', [], $customer['token'])->assertStatus(404);

        $this->assertSame(2, $this->db->table('wf_customer_favorites')->where('user_id', $customer['user_id'])->countAllResults());

        $list = $this->body($this->json('GET', 'api/v1/customer/favorites', [], $customer['token']));
        $this->assertSame(2, $list['meta']['pagination']['total']);
        $this->assertTrue($list['data'][array_search($open['restaurant_id'], array_column($list['data'], 'id'), true)]['is_open_now']);

        // Restaurant suspendu : masqué de la liste, sans être supprimé.
        $this->db->table('wf_restaurants')->where('id', $other['restaurant_id'])->update(['status' => 'suspended']);
        $list = $this->body($this->json('GET', 'api/v1/customer/favorites', [], $customer['token']))['data'];
        $this->assertSame([$open['restaurant_id']], array_column($list, 'id'));

        $this->json('DELETE', "api/v1/customer/favorites/{$open['restaurant_id']}", [], $customer['token'])->assertStatus(204);
        $this->json('DELETE', "api/v1/customer/favorites/{$open['restaurant_id']}", [], $customer['token'])->assertStatus(204);
        $this->assertSame([], $this->body($this->json('GET', 'api/v1/customer/favorites', [], $customer['token']))['data']);

        // Les favoris d'un autre client sont indépendants.
        $bob = $this->customer();
        $this->assertSame([], $this->body($this->json('GET', 'api/v1/customer/favorites', [], $bob['token']))['data']);
    }
}
