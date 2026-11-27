<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Presenters;

use App\Modules\Users\Presenters\UserPresenter;

/**
 * Représentation API d'un code promo (gestion par le restaurant ou l'administration).
 */
final class PromotionPresenter
{
    /**
     * @param array<string, mixed> $promotion Ligne wf_promotions
     *
     * @return array<string, mixed>
     */
    public static function present(array $promotion, int $usageCount): array
    {
        return [
            'id'                      => (int) $promotion['id'],
            'code'                    => $promotion['code'],
            'title'                   => $promotion['title'],
            'scope'                   => $promotion['restaurant_id'] === null ? 'platform' : 'restaurant',
            'restaurant_id'           => $promotion['restaurant_id'] === null ? null : (int) $promotion['restaurant_id'],
            'discount_type'           => $promotion['discount_type'],
            'discount_value'          => $promotion['discount_value'],
            'minimum_order_amount'    => $promotion['minimum_order_amount'],
            'maximum_discount_amount' => $promotion['maximum_discount_amount'],
            'currency'                => $promotion['currency'],
            'usage_limit'             => $promotion['usage_limit'] === null ? null : (int) $promotion['usage_limit'],
            'usage_per_customer'      => $promotion['usage_per_customer'] === null ? null : (int) $promotion['usage_per_customer'],
            'usage_count'             => $usageCount,
            'starts_at'               => UserPresenter::isoDate($promotion['starts_at']),
            'ends_at'                 => UserPresenter::isoDate($promotion['ends_at']),
            'is_active'               => filter_var($promotion['is_active'], FILTER_VALIDATE_BOOL),
            'created_at'              => UserPresenter::isoDate($promotion['created_at']),
        ];
    }
}
