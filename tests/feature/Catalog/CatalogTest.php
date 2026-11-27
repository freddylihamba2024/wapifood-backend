<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Tests\Support\ApiTestCase;

/**
 * Catégories et produits : gestion, permissions, isolation et menu public.
 *
 * @internal
 */
final class CatalogTest extends ApiTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function burgerPayload(?int $categoryId = null): array
    {
        return array_filter([
            'category_id'         => $categoryId,
            'name'                => 'Burger maison',
            'description'         => 'Pain brioché, bœuf local',
            'sku'                 => 'BRG-01',
            'base_price'          => '8.50',
            'preparation_minutes' => 15,
            'variants'            => [
                ['name' => 'Simple', 'price_delta' => '0'],
                ['name' => 'Double', 'price_delta' => '3.00'],
                ['name' => 'Mini', 'price_delta' => '-2.50'],
            ],
            'option_groups' => [
                [
                    'name' => 'Cuisson', 'selection_type' => 'single', 'is_required' => true,
                    'options' => [['name' => 'Saignant'], ['name' => 'Bien cuit']],
                ],
                [
                    'name' => 'Suppléments', 'selection_type' => 'multiple', 'max_selections' => 3,
                    'options' => [['name' => 'Fromage', 'price_delta' => '1.00'], ['name' => 'Bacon', 'price_delta' => 1.5]],
                ],
            ],
        ], static fn ($v): bool => $v !== null);
    }

    public function testCategoryLifecycle(): void
    {
        $owner = $this->ownerWithRestaurant();

        $plats   = $this->body($this->json('POST', 'api/v1/restaurant/categories', ['name' => 'Plats'], $owner['token']))['data'];
        $boisson = $this->body($this->json('POST', 'api/v1/restaurant/categories', ['name' => 'Boissons'], $owner['token']))['data'];
        $this->assertSame([0, 1], [$plats['sort_order'], $boisson['sort_order']]);

        $reordered = $this->body($this->json('PATCH', 'api/v1/restaurant/categories/reorder', ['category_ids' => [$boisson['id'], $plats['id']]], $owner['token']))['data'];
        $this->assertSame(['Boissons', 'Plats'], array_column($reordered, 'name'));

        $this->json('PUT', "api/v1/restaurant/categories/{$plats['id']}", ['name' => 'Plats du jour', 'is_active' => false], $owner['token'])->assertStatus(200);
        $this->json('POST', 'api/v1/restaurant/categories', [], $owner['token'])->assertStatus(422);

        $this->json('POST', 'api/v1/restaurant/products', ['name' => 'Fanta', 'base_price' => '1', 'category_id' => $boisson['id']], $owner['token'])->assertStatus(201);

        $notEmpty = $this->json('DELETE', "api/v1/restaurant/categories/{$boisson['id']}", [], $owner['token']);
        $notEmpty->assertStatus(409);
        $this->assertSame('CATEGORY_NOT_EMPTY', $this->body($notEmpty)['meta']['code']);

        $this->json('DELETE', "api/v1/restaurant/categories/{$plats['id']}", [], $owner['token'])->assertStatus(204);
    }

    public function testProductWithVariantsAndOptions(): void
    {
        $owner    = $this->ownerWithRestaurant();
        $category = $this->body($this->json('POST', 'api/v1/restaurant/categories', ['name' => 'Burgers'], $owner['token']))['data'];

        $response = $this->json('POST', 'api/v1/restaurant/products', $this->burgerPayload($category['id']), $owner['token']);
        $response->assertStatus(201);
        $product = $this->body($response)['data'];

        $this->assertSame('8.50', $product['base_price']);
        $this->assertSame('USD', $product['currency']);
        $this->assertSame('burger-maison', $product['slug']);
        $this->assertSame(['0.00', '3.00', '-2.50'], array_column($product['variants'], 'price_delta'));
        $this->assertSame(1, $product['option_groups'][0]['min_selections']);
        $this->assertSame(1, $product['option_groups'][0]['max_selections']);
        $this->assertSame(['1.00', '1.50'], array_column($product['option_groups'][1]['options'], 'price_delta'));

        // Mise à jour : on garde « Simple », on renomme « Double », on supprime « Mini », on ajoute « Triple ».
        $simple = $product['variants'][0];
        $double = $product['variants'][1];
        $update = $this->json('PUT', "api/v1/restaurant/products/{$product['id']}", [
            'base_price' => '9',
            'variants'   => [
                ['id' => $simple['id'], 'name' => 'Simple'],
                ['id' => $double['id'], 'name' => 'Double steak', 'price_delta' => '3.50'],
                ['name' => 'Triple', 'price_delta' => '6'],
            ],
        ], $owner['token']);

        $this->assertSame(200, $update->response()->getStatusCode(), (string) $update->getJSON());
        $updated = $this->body($update)['data'];
        $this->assertSame('9.00', $updated['base_price']);
        $this->assertSame(['Simple', 'Double steak', 'Triple'], array_column($updated['variants'], 'name'));
        $this->assertSame($double['id'], $updated['variants'][1]['id']);
        $this->assertCount(2, $updated['option_groups'], 'Les options non envoyées sont conservées.');
    }

    public function testProductValidationRules(): void
    {
        $owner   = $this->ownerWithRestaurant();
        $token   = $owner['token'];
        $created = $this->body($this->json('POST', 'api/v1/restaurant/products', $this->burgerPayload(), $token))['data'];

        $this->json('POST', 'api/v1/restaurant/products', ['name' => 'X', 'base_price' => '-1'], $token)->assertStatus(422);
        $this->json('POST', 'api/v1/restaurant/products', ['name' => 'X', 'base_price' => '1.999'], $token)->assertStatus(422);
        $this->json('POST', 'api/v1/restaurant/products', ['name' => 'X', 'base_price' => 'gratuit'], $token)->assertStatus(422);

        $negative = $this->json('POST', 'api/v1/restaurant/products', ['name' => 'X', 'base_price' => '2', 'variants' => [['name' => 'Mini', 'price_delta' => '-3']]], $token);
        $negative->assertStatus(422);
        $this->assertArrayHasKey('variants.0.price_delta', $this->body($negative)['errors']);

        $badGroup = $this->json('POST', 'api/v1/restaurant/products', ['name' => 'X', 'base_price' => '2', 'option_groups' => [
            ['name' => 'Choix', 'selection_type' => 'multiple', 'min_selections' => 3, 'max_selections' => 2, 'options' => [['name' => 'A']]],
        ]], $token);
        $badGroup->assertStatus(422);

        $duplicateSku = $this->json('POST', 'api/v1/restaurant/products', ['name' => 'Autre', 'base_price' => '2', 'sku' => 'BRG-01'], $token);
        $duplicateSku->assertStatus(409);

        // Baisser le prix de base ne doit pas rendre la variante « Mini » (-2.50) négative.
        $this->json('PUT', "api/v1/restaurant/products/{$created['id']}", ['base_price' => '2'], $token)->assertStatus(422);
    }

    public function testCrossRestaurantIsolation(): void
    {
        $alice = $this->ownerWithRestaurant();
        $bob   = $this->ownerWithRestaurant();

        $bobCategory = $this->body($this->json('POST', 'api/v1/restaurant/categories', ['name' => 'Bob'], $bob['token']))['data'];
        $bobProduct  = $this->body($this->json('POST', 'api/v1/restaurant/products', $this->burgerPayload(), $bob['token']))['data'];

        // Alice ne peut ni lire, ni modifier, ni supprimer les éléments de Bob.
        $this->json('GET', "api/v1/restaurant/products/{$bobProduct['id']}", [], $alice['token'])->assertStatus(404);
        $this->json('PUT', "api/v1/restaurant/products/{$bobProduct['id']}", ['name' => 'Volé'], $alice['token'])->assertStatus(404);
        $this->json('DELETE', "api/v1/restaurant/products/{$bobProduct['id']}", [], $alice['token'])->assertStatus(404);
        $this->json('PATCH', "api/v1/restaurant/products/{$bobProduct['id']}/availability", ['is_available' => false], $alice['token'])->assertStatus(404);
        $this->json('PUT', "api/v1/restaurant/categories/{$bobCategory['id']}", ['name' => 'Volé'], $alice['token'])->assertStatus(404);
        $this->json('PATCH', 'api/v1/restaurant/categories/reorder', ['category_ids' => [$bobCategory['id']]], $alice['token'])->assertStatus(422);

        // Ni rattacher son produit à une catégorie de Bob, ni reprendre ses variantes.
        $this->json('POST', 'api/v1/restaurant/products', ['name' => 'X', 'base_price' => '1', 'category_id' => $bobCategory['id']], $alice['token'])->assertStatus(422);

        $aliceProduct = $this->body($this->json('POST', 'api/v1/restaurant/products', ['name' => 'Alice', 'base_price' => '1'], $alice['token']))['data'];
        $steal        = $this->json('PUT', "api/v1/restaurant/products/{$aliceProduct['id']}", ['variants' => [['id' => $bobProduct['variants'][0]['id'], 'name' => 'Volée']]], $alice['token']);
        $steal->assertStatus(422);

        $this->seeInDatabase('wf_products', ['id' => $bobProduct['id'], 'name' => 'Burger maison', 'is_available' => 1]);
        $this->seeInDatabase('wf_product_variants', ['id' => $bobProduct['variants'][0]['id'], 'name' => 'Simple']);
    }

    public function testStaffCanToggleAvailabilityButNotEditCatalog(): void
    {
        $owner   = $this->ownerWithRestaurant();
        $staff   = $this->registerUser();
        $this->addMember($owner['restaurant_id'], $staff['user']['id'], 'staff');
        $product = $this->body($this->json('POST', 'api/v1/restaurant/products', $this->burgerPayload(), $owner['token']))['data'];
        $token   = $staff['tokens']['access_token'];

        $this->json('GET', 'api/v1/restaurant/products', [], $token)->assertStatus(200);
        $this->json('PATCH', "api/v1/restaurant/products/{$product['id']}/availability", ['is_available' => false], $token)->assertStatus(200);
        $this->json('PUT', "api/v1/restaurant/products/{$product['id']}", ['base_price' => '1'], $token)->assertStatus(403);
        $this->json('POST', 'api/v1/restaurant/products', ['name' => 'X', 'base_price' => '1'], $token)->assertStatus(403);
        $this->json('DELETE', "api/v1/restaurant/products/{$product['id']}", [], $token)->assertStatus(403);
    }

    public function testPublicMenuShowsOnlyOrderableItems(): void
    {
        $owner  = $this->ownerWithRestaurant();
        $token  = $owner['token'];
        $active = $this->body($this->json('POST', 'api/v1/restaurant/categories', ['name' => 'Plats'], $token))['data'];
        $hidden = $this->body($this->json('POST', 'api/v1/restaurant/categories', ['name' => 'Cachée'], $token))['data'];
        $this->json('PUT', "api/v1/restaurant/categories/{$hidden['id']}", ['is_active' => false], $token);

        $visible     = $this->body($this->json('POST', 'api/v1/restaurant/products', $this->burgerPayload($active['id']), $token))['data'];
        $unavailable = $this->body($this->json('POST', 'api/v1/restaurant/products', ['name' => 'Épuisé', 'base_price' => '3', 'is_available' => false, 'sku' => 'EP'], $token))['data'];
        $inHidden    = $this->body($this->json('POST', 'api/v1/restaurant/products', ['name' => 'Caché', 'base_price' => '3', 'category_id' => $hidden['id']], $token))['data'];
        $deleted     = $this->body($this->json('POST', 'api/v1/restaurant/products', ['name' => 'Supprimé', 'base_price' => '3'], $token))['data'];
        $this->json('DELETE', "api/v1/restaurant/products/{$deleted['id']}", [], $token)->assertStatus(204);

        // Une variante désactivée ne doit pas apparaître publiquement.
        $this->json('PUT', "api/v1/restaurant/products/{$visible['id']}", ['variants' => [
            ['id' => $visible['variants'][0]['id'], 'name' => 'Simple'],
            ['id' => $visible['variants'][1]['id'], 'name' => 'Double', 'price_delta' => '3', 'is_active' => false],
        ]], $token)->assertStatus(200);

        $id   = $owner['restaurant_id'];
        $menu = $this->body($this->json('GET', "api/v1/restaurants/{$id}/products"));

        $this->assertSame([$visible['id']], array_column($menu['data'], 'id'));
        $this->assertSame(['Simple'], array_column($menu['data'][0]['variants'], 'name'));
        $this->assertArrayNotHasKey('sku', $menu['data'][0]);
        $this->assertSame(1, $menu['meta']['pagination']['total']);

        $categories = $this->body($this->json('GET', "api/v1/restaurants/{$id}/categories"))['data'];
        $this->assertSame(['Plats'], array_column($categories, 'name'));

        foreach ([$unavailable, $inHidden, $deleted] as $product) {
            $this->json('GET', "api/v1/restaurants/{$id}/products/{$product['id']}")->assertStatus(404);
        }

        $this->json('GET', "api/v1/restaurants/{$id}/products/{$visible['id']}")->assertStatus(200);

        // Le restaurateur, lui, voit tout sauf le produit supprimé.
        $this->assertSame(3, $this->body($this->json('GET', 'api/v1/restaurant/products', [], $token))['meta']['pagination']['total']);
    }

    public function testPublicDirectoryFiltersAndPagination(): void
    {
        $a = $this->ownerWithRestaurant();
        $b = $this->ownerWithRestaurant('active', ['name' => 'Le Grill', 'address' => ['address_line' => 'Av. Kasa-Vubu', 'commune' => 'Bandal']]);
        $this->ownerWithRestaurant('pending', ['name' => 'Pas encore validé']);
        $this->db->table('wf_restaurants')->where('id', $b['restaurant_id'])->update(['is_featured' => 1]);

        $all = $this->body($this->json('GET', 'api/v1/restaurants'));
        $this->assertSame(2, $all['meta']['pagination']['total']);
        $this->assertSame($b['restaurant_id'], $all['data'][0]['id'], 'Les restaurants mis en avant passent en premier.');

        $this->assertSame([$b['restaurant_id']], array_column($this->body($this->json('GET', 'api/v1/restaurants?commune=Bandal'))['data'], 'id'));
        $this->assertSame([$a['restaurant_id']], array_column($this->body($this->json('GET', 'api/v1/restaurants?q=Fifi'))['data'], 'id'));
        $this->assertSame([$b['restaurant_id']], array_column($this->body($this->json('GET', 'api/v1/restaurants?featured=true'))['data'], 'id'));

        $page = $this->body($this->json('GET', 'api/v1/restaurants?per_page=1&page=2'));
        $this->assertCount(1, $page['data']);
        $this->assertSame(['page' => 2, 'per_page' => 1, 'total' => 2, 'total_pages' => 2], $page['meta']['pagination']);

        $this->json('GET', 'api/v1/restaurants?page=0')->assertStatus(422);
        $this->json('GET', 'api/v1/restaurants?featured=peut-etre')->assertStatus(422);
        $this->json('GET', 'api/v1/restaurants/999999')->assertStatus(404);
        $this->json('GET', 'api/v1/restaurants/abc')->assertStatus(404);
    }
}
