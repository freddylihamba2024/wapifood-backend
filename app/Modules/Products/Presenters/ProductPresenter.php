<?php

declare(strict_types=1);

namespace App\Modules\Products\Presenters;

use App\Libraries\Upload\UploadService;
use App\Modules\Users\Presenters\UserPresenter;

/**
 * Représentation d'un produit (vue publique ou vue de gestion).
 */
final class ProductPresenter
{
    /**
     * @param array<string, mixed>                                                                       $product
     * @param array{variants: list<array<string, mixed>>, option_groups: list<array<string, mixed>>, images: list<array<string, mixed>>} $details
     *
     * @return array<string, mixed>
     */
    public static function present(array $product, array $details, bool $management): array
    {
        $data = [
            'id'                  => (int) $product['id'],
            'category'            => $product['category_id'] === null ? null : ['id' => (int) $product['category_id'], 'name' => $product['category_name']],
            'name'                => $product['name'],
            'slug'                => $product['slug'],
            'description'         => $product['description'],
            'base_price'          => $product['base_price'],
            'currency'            => $product['currency'],
            'image_url'           => UploadService::url($product['image_url']),
            'images'              => array_map(static fn (array $i): array => [
                'id'       => (int) $i['id'],
                'url'      => UploadService::url($i['image_url']),
                'alt_text' => $i['alt_text'],
            ], $details['images']),
            'preparation_minutes' => $product['preparation_minutes'] === null ? null : (int) $product['preparation_minutes'],
            'is_available'        => (bool) $product['is_available'],
            'is_featured'         => (bool) $product['is_featured'],
            'variants'            => array_map(static fn (array $v): array => [
                'id'          => (int) $v['id'],
                'name'        => $v['name'],
                'price_delta' => $v['price_delta'],
            ] + ($management ? ['is_active' => (bool) $v['is_active']] : []), $details['variants']),
            'option_groups'       => array_map(static fn (array $g): array => [
                'id'             => (int) $g['id'],
                'name'           => $g['name'],
                'selection_type' => $g['selection_type'],
                'is_required'    => (bool) $g['is_required'],
                'min_selections' => (int) $g['min_selections'],
                'max_selections' => (int) $g['max_selections'],
                'options'        => array_map(static fn (array $o): array => [
                    'id'          => (int) $o['id'],
                    'name'        => $o['name'],
                    'price_delta' => $o['price_delta'],
                ] + ($management ? ['is_active' => (bool) $o['is_active']] : []), $g['options']),
            ], $details['option_groups']),
        ];

        if ($management) {
            $data += [
                'sku'        => $product['sku'],
                'sort_order' => (int) $product['sort_order'],
                'created_at' => UserPresenter::isoDate($product['created_at']),
                'updated_at' => UserPresenter::isoDate($product['updated_at']),
            ];
        }

        return $data;
    }
}
