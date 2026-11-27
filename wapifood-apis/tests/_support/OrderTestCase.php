<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Base des tests de commandes : restaurant actif ouvert 24 h/24, catalogue type, client vérifié.
 *
 * @internal
 */
abstract class OrderTestCase extends ApiTestCase
{
    /**
     * Restaurant actif, ouvert tous les jours 24 h/24, avec :
     * - burger 8.50 (variantes Simple 0 / Double +3.00 ; Cuisson obligatoire ; Suppléments facultatifs ≤ 2) ;
     * - fanta 1.20 (sans variante ni option).
     *
     * @param array<string, mixed> $columns Colonnes wf_restaurants à forcer (delivery_fee, minimum…)
     *
     * @return array{token: string, user_id: int, restaurant_id: int, burger: array<string, mixed>, fanta: array<string, mixed>}
     */
    protected function openRestaurant(array $columns = []): array
    {
        $owner  = $this->ownerWithRestaurant('active');
        $status = $columns['status'] ?? null;
        unset($columns['status']);

        $this->db->table('wf_restaurants')->where('id', $owner['restaurant_id'])->update($columns + [
            'delivery_fee'         => '2.00',
            'minimum_order_amount' => '0.00',
        ]);

        // opens_at = closes_at : ouvert 24 h/24 ce jour-là.
        $this->db->table('wf_restaurant_opening_hours')->insertBatch(array_map(static fn (int $day): array => [
            'restaurant_id' => $owner['restaurant_id'],
            'day_of_week'   => $day,
            'opens_at'      => '00:00:00',
            'closes_at'     => '00:00:00',
            'is_closed'     => 0,
        ], range(1, 7)));

        $burger = $this->body($this->json('POST', 'api/v1/restaurant/products', [
            'name'          => 'Burger',
            'base_price'    => '8.50',
            'variants'      => [['name' => 'Simple', 'price_delta' => '0'], ['name' => 'Double', 'price_delta' => '3.00']],
            'option_groups' => [
                ['name' => 'Cuisson', 'selection_type' => 'single', 'is_required' => true, 'options' => [['name' => 'Saignant'], ['name' => 'Bien cuit']]],
                ['name' => 'Suppléments', 'selection_type' => 'multiple', 'max_selections' => 2, 'options' => [
                    ['name' => 'Fromage', 'price_delta' => '1.00'], ['name' => 'Bacon', 'price_delta' => '1.50'],
                ]],
            ],
        ], $owner['token']))['data'];

        $fanta = $this->body($this->json('POST', 'api/v1/restaurant/products', ['name' => 'Fanta', 'base_price' => '1.20'], $owner['token']))['data'];

        // Statut appliqué après la création du catalogue (un restaurant suspendu ne peut plus rien modifier).
        if ($status !== null) {
            $this->db->table('wf_restaurants')->where('id', $owner['restaurant_id'])->update(['status' => $status]);
        }

        return $owner + ['burger' => $burger, 'fanta' => $fanta];
    }

    /**
     * Client vérifié avec une adresse par défaut.
     *
     * @return array{token: string, user_id: int, address_id: int, phone: string}
     */
    protected function customer(): array
    {
        $user    = $this->registerUser('customer');
        $address = $this->body($this->json('POST', 'api/v1/customer/addresses', [
            'label'        => 'Maison',
            'address_line' => '45 avenue Kasa-Vubu',
            'commune'      => 'Kalamu',
            'landmark'     => 'En face de l\'église',
        ], $user['tokens']['access_token']))['data'];

        return [
            'token'      => $user['tokens']['access_token'],
            'user_id'    => (int) $user['user']['id'],
            'address_id' => (int) $address['id'],
            'phone'      => $user['phone'],
        ];
    }

    /**
     * Ligne « burger » : variante, cuisson et suppléments désignés par leur nom.
     *
     * @param array<string, mixed> $burger
     * @param list<string>         $extras
     *
     * @return array<string, mixed>
     */
    protected static function burgerLine(array $burger, int $quantity = 1, string $variant = 'Simple', string $cooking = 'Saignant', array $extras = []): array
    {
        $variantId = null;

        foreach ($burger['variants'] as $v) {
            if ($v['name'] === $variant) {
                $variantId = $v['id'];
            }
        }

        $optionIds = [];

        foreach ($burger['option_groups'] as $group) {
            foreach ($group['options'] as $option) {
                if ($option['name'] === $cooking || in_array($option['name'], $extras, true)) {
                    $optionIds[] = $option['id'];
                }
            }
        }

        return ['product_id' => $burger['id'], 'quantity' => $quantity, 'variant_id' => $variantId, 'option_ids' => $optionIds];
    }

    /**
     * Passe une commande et renvoie la réponse brute.
     *
     * @param array<string, mixed>       $restaurant Résultat de openRestaurant()
     * @param array<string, mixed>       $customer   Résultat de customer()
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>       $extra
     */
    protected function placeOrder(array $restaurant, array $customer, array $items, array $extra = [], ?string $idempotencyKey = null): \CodeIgniter\Test\TestResponse
    {
        $headers = $idempotencyKey === null ? [] : ['Idempotency-Key' => $idempotencyKey];

        return $this->json('POST', 'api/v1/customer/orders', $extra + [
            'restaurant_id'       => $restaurant['restaurant_id'],
            'order_type'          => 'delivery',
            'delivery_address_id' => $customer['address_id'],
            'items'               => $items,
        ], $customer['token'], $headers);
    }
}
