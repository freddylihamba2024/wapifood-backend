<?php

declare(strict_types=1);

namespace App\Modules\Orders\Presenters;

use App\Libraries\Upload\UploadService;
use App\Modules\Users\Presenters\UserPresenter;

/**
 * Représentation API d'une commande.
 *
 * Deux audiences : le client (voit le restaurant) et le restaurant (voit le client).
 * Les montants restent des chaînes décimales ; les dates sont en UTC (ISO 8601).
 */
final class OrderPresenter
{
    public const AUDIENCE_CUSTOMER   = 'customer';
    public const AUDIENCE_RESTAURANT = 'restaurant';

    /**
     * @param array<string, mixed> $order Ligne wf_orders jointe au restaurant (OrderRepository)
     *
     * @return array<string, mixed>
     */
    public static function summary(array $order, int $itemCount, string $audience): array
    {
        $data = [
            'id'              => (int) $order['id'],
            'order_number'    => $order['order_number'],
            'status'          => $order['status'],
            'payment_status'  => $order['payment_status'],
            'order_type'      => $order['order_type'],
            'currency'        => $order['currency'],
            'subtotal'        => $order['subtotal'],
            'discount_amount' => $order['discount_amount'],
            'delivery_fee'    => $order['delivery_fee'],
            'tax_amount'      => $order['tax_amount'],
            'total_amount'    => $order['total_amount'],
            'item_count'      => $itemCount,
            'requested_for'   => UserPresenter::isoDate($order['requested_for']),
            'created_at'      => UserPresenter::isoDate($order['created_at']),
            'updated_at'      => UserPresenter::isoDate($order['updated_at']),
        ];

        if ($audience === self::AUDIENCE_CUSTOMER) {
            $data['restaurant'] = [
                'id'       => (int) $order['restaurant_id'],
                'name'     => $order['restaurant_name'],
                'slug'     => $order['restaurant_slug'],
                'logo_url' => UploadService::url($order['restaurant_logo_url']),
            ];
        } else {
            $data['customer'] = [
                'name'  => $order['customer_name'],
                'phone' => $order['customer_phone'],
            ];
        }

        return $data;
    }

    /**
     * @param array<string, mixed>       $order
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $history
     * @param array<string, mixed>|null  $payment
     * @param list<string>               $nextStatuses Statuts que l'audience peut appliquer
     *
     * @return array<string, mixed>
     */
    public static function detail(array $order, array $items, array $history, ?array $payment, array $nextStatuses, string $audience): array
    {
        $snapshot = $order['delivery_address_snapshot'] === null ? null : json_decode((string) $order['delivery_address_snapshot'], true);

        $data = self::summary($order, array_sum(array_map(static fn (array $i): int => (int) $i['quantity'], $items)), $audience) + [
            'delivery_address'      => is_array($snapshot) ? $snapshot : null,
            'delivery_instructions' => $order['delivery_instructions'],
            'customer_note'         => $order['customer_note'],
            'cancellation_reason'   => $order['cancellation_reason'],
            'accepted_at'           => UserPresenter::isoDate($order['accepted_at']),
            'completed_at'          => UserPresenter::isoDate($order['completed_at']),
            'cancelled_at'          => UserPresenter::isoDate($order['cancelled_at']),
            'items'                 => array_map(self::item(...), $items),
            'payment'               => $payment === null ? null : [
                'method'    => $payment['provider'],
                'status'    => $payment['status'],
                'amount'    => $payment['amount'],
                'currency'  => $payment['currency'],
                'reference' => $payment['merchant_reference'],
                'paid_at'   => UserPresenter::isoDate($payment['paid_at']),
            ],
            'status_history'        => array_map(static fn (array $h): array => [
                'from'       => $h['old_status'],
                'to'         => $h['new_status'],
                'comment'    => $h['comment'],
                'created_at' => UserPresenter::isoDate($h['created_at']),
            ], $history),
            'next_statuses'         => $nextStatuses,
        ];

        if ($audience === self::AUDIENCE_CUSTOMER) {
            $data['restaurant']['phone'] = $order['restaurant_phone'];
        }

        return $data;
    }

    /**
     * Ligne de commande (snapshot) ou ligne calculée par OrderCalculator (devis).
     *
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    public static function item(array $item): array
    {
        $options = $item['options'] ?? $item['options_snapshot'] ?? [];

        if (is_string($options)) {
            $options = json_decode($options, true) ?? [];
        }

        return [
            'product_id'   => $item['product_id'] === null ? null : (int) $item['product_id'],
            'product_name' => $item['product_name'],
            'variant_name' => $item['variant_name'],
            'quantity'     => (int) $item['quantity'],
            'unit_price'   => $item['unit_price'],
            'options'      => $options,
            'line_total'   => $item['line_total'],
            'note'         => $item['note'],
        ];
    }
}
