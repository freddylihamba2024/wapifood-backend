<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Common\Exceptions\ApiException;
use App\Common\Support\Money;

/**
 * Calcul serveur d'une commande à partir du catalogue actuel (règles pures, sans accès à la base).
 *
 * Aucun prix envoyé par le client n'est utilisé : seuls les identifiants (produit, variante,
 * options) et les quantités le sont. Tous les calculs se font en centimes entiers.
 *
 *   prix unitaire = prix de base + écart de la variante + Σ écarts des options
 *   sous-total    = Σ (prix unitaire × quantité)
 *   livraison     = forfait du restaurant, offert si un seuil existe et que sous-total ≥ seuil (D6)
 *   remise        = code promo éventuel, calculée sur le sous-total (PromotionRules), jamais > sous-total
 *   total         = sous-total − remise + livraison + taxes
 *
 * Le minimum de commande et le seuil de livraison offerte s'appliquent au sous-total AVANT remise.
 * Taxes : 0 tant que les règles fiscales ne sont pas validées.
 */
final class OrderCalculator
{
    /** Plafond d'une colonne DECIMAL(12,2), en centimes. */
    private const MAX_AMOUNT_CENTS = 999_999_999_999;

    /**
     * @param array<string, mixed>                                                   $restaurant Ligne wf_restaurants
     * @param array<int, array<string, mixed>>                                       $products   Produits commandables du restaurant, par id,
     *                                                                                           avec la clé « details » (variantes et groupes d'options actifs)
     * @param list<array{product_id: int, quantity: int, variant_id: int|null, option_ids: list<int>, note: string|null}> $items
     *
     * @return array{
     *     currency: string,
     *     lines: list<array<string, mixed>>,
     *     subtotal: string,
     *     discount_amount: string,
     *     delivery_fee: string,
     *     tax_amount: string,
     *     total_amount: string
     * }
     *
     * @param (callable(int): int)|null                                             $discountFor Remise en centimes pour un sous-total
     *                                                                                            donné (code promo), appelée après les contrôles
     *
     * @throws ApiException 422 ORDER_ITEMS_INVALID | MINIMUM_ORDER_NOT_REACHED | ORDER_TOO_LARGE (et celles de $discountFor)
     */
    public function calculate(array $restaurant, array $products, array $items, string $orderType, ?callable $discountFor = null): array
    {
        $currency = (string) $restaurant['currency'];
        $errors   = [];
        $lines    = [];
        $subtotal = 0;

        foreach ($items as $index => $item) {
            $line = $this->priceLine($products[$item['product_id']] ?? null, $item, $currency, "items.{$index}.", $errors);

            if ($line !== null) {
                $lines[]   = $line;
                $subtotal += $line['line_total_cents'];
            }
        }

        if ($errors !== []) {
            throw new ApiException('Certains articles du panier ne peuvent pas être commandés.', 422, $errors, 'ORDER_ITEMS_INVALID');
        }

        $minimum = Money::toCents((string) $restaurant['minimum_order_amount']);

        if ($subtotal < $minimum) {
            throw new ApiException(
                'Le montant minimum de commande de ce restaurant n\'est pas atteint.',
                422,
                ['minimum_order_amount' => Money::fromCents($minimum), 'subtotal' => Money::fromCents($subtotal)],
                'MINIMUM_ORDER_NOT_REACHED',
            );
        }

        $deliveryFee = $orderType === 'delivery' ? $this->deliveryFee($restaurant, $subtotal) : 0;
        $discount    = $discountFor === null ? 0 : max(0, min($subtotal, $discountFor($subtotal)));
        $tax         = 0;
        $total       = $subtotal - $discount + $deliveryFee + $tax;

        if ($total > self::MAX_AMOUNT_CENTS) {
            throw new ApiException('Le montant de la commande dépasse la limite autorisée.', 422, [], 'ORDER_TOO_LARGE');
        }

        return [
            'currency'        => $currency,
            'lines'           => array_map(static function (array $line): array {
                unset($line['line_total_cents']);

                return $line;
            }, $lines),
            'subtotal'        => Money::fromCents($subtotal),
            'discount_amount' => Money::fromCents($discount),
            'delivery_fee'    => Money::fromCents($deliveryFee),
            'tax_amount'      => Money::fromCents($tax),
            'total_amount'    => Money::fromCents($total),
        ];
    }

    /**
     * @param array<string, mixed> $restaurant
     */
    public function deliveryFee(array $restaurant, int $subtotalCents): int
    {
        $threshold = $restaurant['free_delivery_threshold'] ?? null;

        if ($threshold !== null && $threshold !== '' && $subtotalCents >= Money::toCents((string) $threshold)) {
            return 0;
        }

        return Money::toCents((string) ($restaurant['delivery_fee'] ?? '0'));
    }

    /**
     * @param array<string, mixed>|null                                                                             $product
     * @param array{product_id: int, quantity: int, variant_id: int|null, option_ids: list<int>, note: string|null} $item
     * @param array<string, string>                                                                                 $errors
     *
     * @return array<string, mixed>|null
     */
    private function priceLine(?array $product, array $item, string $currency, string $prefix, array &$errors): ?array
    {
        if ($product === null || $product['currency'] !== $currency) {
            $errors[$prefix . 'product_id'] = 'Ce produit est introuvable ou n\'est plus disponible.';

            return null;
        }

        $unit    = Money::toCents((string) $product['base_price']);
        $variant = $this->variant($product, $item['variant_id'], $prefix, $errors);

        if ($variant !== null) {
            $unit += Money::toCents((string) $variant['price_delta']);
        }

        $options = $this->options($product, $item['option_ids'], $prefix, $errors);

        foreach ($options as $option) {
            $unit += Money::toCents((string) $option['price_delta']);
        }

        if (isset($errors[$prefix . 'variant_id']) || isset($errors[$prefix . 'option_ids'])) {
            return null;
        }

        if ($unit < 0) {
            $errors[$prefix . 'product_id'] = 'Le prix de cet article est invalide. Contactez le restaurant.';

            return null;
        }

        $lineTotal = $unit * $item['quantity'];

        return [
            'product_id'       => (int) $product['id'],
            'product_name'     => (string) $product['name'],
            'variant_id'       => $variant === null ? null : (int) $variant['id'],
            'variant_name'     => $variant['name'] ?? null,
            'quantity'         => $item['quantity'],
            'unit_price'       => Money::fromCents($unit),
            'options'          => array_map(static fn (array $o): array => [
                'group'       => $o['group_name'],
                'name'        => $o['name'],
                'price_delta' => Money::normalize((string) $o['price_delta']),
            ], $options),
            'line_total'       => Money::fromCents($lineTotal),
            'line_total_cents' => $lineTotal,
            'note'             => $item['note'],
        ];
    }

    /**
     * Un produit avec des variantes actives exige le choix de l'une d'elles.
     *
     * @param array<string, mixed>  $product
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>|null
     */
    private function variant(array $product, ?int $variantId, string $prefix, array &$errors): ?array
    {
        $variants = $product['details']['variants'];

        if ($variants === []) {
            if ($variantId !== null) {
                $errors[$prefix . 'variant_id'] = 'Ce produit n\'a pas de variante.';
            }

            return null;
        }

        if ($variantId === null) {
            $errors[$prefix . 'variant_id'] = 'Choisissez une variante pour « ' . $product['name'] . ' ».';

            return null;
        }

        foreach ($variants as $variant) {
            if ((int) $variant['id'] === $variantId) {
                return $variant;
            }
        }

        $errors[$prefix . 'variant_id'] = 'Cette variante n\'est plus disponible.';

        return null;
    }

    /**
     * Vérifie l'appartenance des options au produit et les règles de chaque groupe
     * (obligatoire, minimum, maximum ; « single » = un seul choix).
     *
     * @param array<string, mixed>  $product
     * @param list<int>             $optionIds
     * @param array<string, string> $errors
     *
     * @return list<array<string, mixed>> Options choisies, avec « group_name »
     */
    private function options(array $product, array $optionIds, string $prefix, array &$errors): array
    {
        if (count($optionIds) !== count(array_unique($optionIds))) {
            $errors[$prefix . 'option_ids'] = 'Une même option ne peut être choisie qu\'une fois.';

            return [];
        }

        $available = [];

        foreach ($product['details']['option_groups'] as $group) {
            foreach ($group['options'] as $option) {
                $available[(int) $option['id']] = $option + ['group_id' => (int) $group['id'], 'group_name' => $group['name']];
            }
        }

        $chosen  = [];
        $byGroup = [];

        foreach ($optionIds as $optionId) {
            if (! isset($available[$optionId])) {
                $errors[$prefix . 'option_ids'] = 'Une des options choisies n\'est plus disponible pour ce produit.';

                return [];
            }

            $chosen[]                                   = $available[$optionId];
            $byGroup[$available[$optionId]['group_id']] = ($byGroup[$available[$optionId]['group_id']] ?? 0) + 1;
        }

        foreach ($product['details']['option_groups'] as $group) {
            // Groupe facultatif : aucun choix accepté ; dès qu'un choix est fait, le minimum s'applique.
            $count    = $byGroup[(int) $group['id']] ?? 0;
            $required = filter_var($group['is_required'], FILTER_VALIDATE_BOOL);
            $min      = max(1, (int) $group['min_selections']);
            $max      = $group['selection_type'] === 'single' ? 1 : max(1, (int) $group['max_selections']);

            if ($count === 0 && $required) {
                $errors[$prefix . 'option_ids'] = "Le choix « {$group['name']} » est obligatoire.";
            } elseif ($count > 0 && $count < $min) {
                $errors[$prefix . 'option_ids'] = "Choisissez au moins {$min} option(s) pour « {$group['name']} ».";
            } elseif ($count > $max) {
                $errors[$prefix . 'option_ids'] = "Choisissez au plus {$max} option(s) pour « {$group['name']} ».";
            }
        }

        return $chosen;
    }
}
