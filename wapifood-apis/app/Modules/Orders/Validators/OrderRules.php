<?php

declare(strict_types=1);

namespace App\Modules\Orders\Validators;

use App\Common\Exceptions\ApiException;
use App\Common\Validation\InputValidator;
use App\Modules\Orders\Services\OrderStateMachine;

/**
 * Règles de format des commandes. Les règles métier (disponibilité, prix, horaires…) sont dans les services.
 */
final class OrderRules
{
    public const MAX_ITEMS        = 50;
    public const MAX_QUANTITY     = 99;
    public const MAX_OPTIONS      = 50;
    public const PAYMENT_METHODS  = ['cash', 'mpesa', 'airtel_money', 'orange_money'];
    public const IDEMPOTENCY_KEY  = '/\A[A-Za-z0-9_\-]{8,64}\z/';

    /**
     * @return array<string, array{label: string, rules: string}>
     */
    public static function order(): array
    {
        return [
            'restaurant_id'         => ['label' => 'restaurant', 'rules' => 'required|wf_int|greater_than[0]'],
            'order_type'            => ['label' => 'type de commande', 'rules' => 'required|in_list[delivery,pickup]'],
            'delivery_address_id'   => ['label' => 'adresse de livraison', 'rules' => 'permit_empty|wf_int|greater_than[0]'],
            'delivery_instructions' => ['label' => 'instructions de livraison', 'rules' => 'permit_empty|string|max_length[1000]'],
            'customer_note'         => ['label' => 'note pour le restaurant', 'rules' => 'permit_empty|string|max_length[1000]'],
            'payment_method'        => ['label' => 'moyen de paiement', 'rules' => 'permit_empty|in_list[' . implode(',', self::PAYMENT_METHODS) . ']'],
            'requested_for'         => ['label' => 'heure souhaitée', 'rules' => 'permit_empty|string|max_length[40]'],
            'expected_total'        => ['label' => 'total attendu', 'rules' => 'permit_empty|wf_money'],
            'promo_code'            => ['label' => 'code promo', 'rules' => 'permit_empty|string|max_length[30]'],
            'items'                 => ['label' => 'articles', 'rules' => 'required|wf_list'],
        ];
    }

    /**
     * Valide et normalise la liste des articles : seuls les identifiants et quantités sont retenus.
     *
     * @return list<array{product_id: int, quantity: int, variant_id: int|null, option_ids: list<int>, note: string|null}>
     *
     * @throws ApiException 422
     */
    public static function items(mixed $items): array
    {
        if (! is_array($items) || ! array_is_list($items) || $items === []) {
            throw ApiException::validation(['items' => 'Le panier doit contenir au moins un article.']);
        }

        if (count($items) > self::MAX_ITEMS) {
            throw ApiException::validation(['items' => 'Le panier ne peut pas contenir plus de ' . self::MAX_ITEMS . ' lignes.']);
        }

        $errors     = [];
        $normalized = [];

        foreach ($items as $index => $item) {
            $prefix = "items.{$index}.";

            if (! is_array($item)) {
                $errors[$prefix . 'product_id'] = 'Article invalide.';

                continue;
            }

            $errors += InputValidator::errors($item, [
                'product_id' => ['label' => 'produit', 'rules' => 'required|wf_int|greater_than[0]'],
                'quantity'   => ['label' => 'quantité', 'rules' => 'required|wf_int|greater_than[0]|less_than_equal_to[' . self::MAX_QUANTITY . ']'],
                'variant_id' => ['label' => 'variante', 'rules' => 'permit_empty|wf_int|greater_than[0]'],
                'note'       => ['label' => 'note', 'rules' => 'permit_empty|string|max_length[500]'],
            ], $prefix);

            $optionIds = $item['option_ids'] ?? [];

            if (! is_array($optionIds) || ! array_is_list($optionIds) || count($optionIds) > self::MAX_OPTIONS) {
                $errors[$prefix . 'option_ids'] = 'Les options doivent être une liste d\'identifiants.';

                continue;
            }

            foreach ($optionIds as $optionId) {
                if (! self::isId($optionId)) {
                    $errors[$prefix . 'option_ids'] = 'Les options doivent être une liste d\'identifiants.';

                    continue 2;
                }
            }

            if (array_filter($errors, static fn (string $key): bool => str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY) !== []) {
                continue;
            }

            $note = isset($item['note']) ? trim((string) $item['note']) : '';

            $normalized[] = [
                'product_id' => (int) $item['product_id'],
                'quantity'   => (int) $item['quantity'],
                'variant_id' => isset($item['variant_id']) && $item['variant_id'] !== '' ? (int) $item['variant_id'] : null,
                'option_ids' => array_map('intval', $optionIds),
                'note'       => $note === '' ? null : $note,
            ];
        }

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return $normalized;
    }

    /**
     * @return array<string, array{label: string, rules: string}>
     */
    public static function statusChange(): array
    {
        return [
            'status' => ['label' => 'statut', 'rules' => 'required|in_list[' . implode(',', OrderStateMachine::STATUSES) . ']'],
            'reason' => ['label' => 'motif', 'rules' => 'permit_empty|string|max_length[500]'],
        ];
    }

    /**
     * @return array<string, array{label: string, rules: string}>
     */
    public static function cancellation(): array
    {
        return [
            'reason' => ['label' => 'motif', 'rules' => 'permit_empty|string|max_length[500]'],
        ];
    }

    /**
     * Filtres de liste : status (liste séparée par des virgules, ou « active » = commandes en cours),
     * date_from / date_to (AAAA-MM-JJ, UTC) et q (numéro de commande) pour le restaurant.
     *
     * @param array<string, mixed> $query Paramètres GET
     *
     * @return array{status: list<string>, date_from?: string, date_to?: string, q?: string, oldest_first?: bool}
     *
     * @throws ApiException 422
     */
    public static function listFilters(array $query, bool $forRestaurant): array
    {
        $filters = ['status' => []];
        $status  = $query['status'] ?? '';

        if (is_string($status) && trim($status) !== '') {
            foreach (array_map('trim', explode(',', $status)) as $value) {
                if ($value === 'active') {
                    $filters['status'] = [...$filters['status'], ...OrderStateMachine::ACTIVE_STATUSES];
                } elseif (in_array($value, OrderStateMachine::STATUSES, true)) {
                    $filters['status'][] = $value;
                } else {
                    throw ApiException::validation(['status' => 'Statut inconnu : « ' . mb_substr($value, 0, 40) . ' ».']);
                }
            }

            $filters['status'] = array_values(array_unique($filters['status']));
        } elseif ($status !== '') {
            throw ApiException::validation(['status' => 'Le filtre status doit être une liste de statuts séparés par des virgules.']);
        }

        if (! $forRestaurant) {
            return $filters;
        }

        foreach (['date_from', 'date_to'] as $field) {
            $value = $query[$field] ?? '';

            if ($value === '') {
                continue;
            }

            $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

            if ($date === false || $date->format('Y-m-d') !== $value) {
                throw ApiException::validation([$field => 'Date attendue au format AAAA-MM-JJ.']);
            }

            $filters[$field] = $value;
        }

        if (is_string($query['q'] ?? null) && trim($query['q']) !== '') {
            $filters['q'] = mb_substr(trim($query['q']), 0, 40);
        }

        // File de travail : quand seules des commandes en cours sont demandées, la plus ancienne d'abord.
        $filters['oldest_first'] = $filters['status'] !== [] && array_diff($filters['status'], OrderStateMachine::ACTIVE_STATUSES) === [];

        return $filters;
    }

    private static function isId(mixed $value): bool
    {
        return (is_int($value) && $value > 0) || (is_string($value) && preg_match('/\A[1-9]\d{0,17}\z/', $value) === 1);
    }
}
