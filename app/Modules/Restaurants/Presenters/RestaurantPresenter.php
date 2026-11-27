<?php

declare(strict_types=1);

namespace App\Modules\Restaurants\Presenters;

use App\Libraries\Upload\UploadService;
use App\Modules\Users\Presenters\UserPresenter;

/**
 * Représentations d'un restaurant selon le public : client, restaurateur, administration.
 */
final class RestaurantPresenter
{
    /**
     * Fiche publique résumée (listes).
     *
     * @param array<string, mixed>      $restaurant
     * @param array<string, mixed>|null $primaryAddress
     *
     * @return array<string, mixed>
     */
    public static function summary(array $restaurant, ?array $primaryAddress, bool $isOpenNow): array
    {
        return [
            'id'                          => (int) $restaurant['id'],
            'name'                        => $restaurant['name'],
            'slug'                        => $restaurant['slug'],
            'description'                 => $restaurant['description'],
            'logo_url'                    => UploadService::url($restaurant['logo_url']),
            'cover_url'                   => UploadService::url($restaurant['cover_url']),
            'currency'                    => $restaurant['currency'],
            'minimum_order_amount'        => $restaurant['minimum_order_amount'],
            'average_preparation_minutes' => (int) $restaurant['average_preparation_minutes'],
            'accepts_delivery'            => (bool) $restaurant['accepts_delivery'],
            'accepts_pickup'              => (bool) $restaurant['accepts_pickup'],
            'delivery_fee'                => $restaurant['delivery_fee'],
            'free_delivery_threshold'     => $restaurant['free_delivery_threshold'],
            'is_featured'                 => (bool) $restaurant['is_featured'],
            'is_paused'                   => $restaurant['status'] === 'paused',
            'is_open_now'                 => $isOpenNow && $restaurant['status'] === 'active',
            'rating'                      => [
                'average' => $restaurant['rating_average'] ?? null,
                'count'   => (int) ($restaurant['rating_count'] ?? 0),
            ],
            'location'                    => $primaryAddress === null ? null : [
                'commune' => $primaryAddress['commune'],
                'city'    => $primaryAddress['city'],
            ],
        ];
    }

    /**
     * Fiche publique détaillée.
     *
     * @param array<string, mixed>       $restaurant
     * @param list<array<string, mixed>> $addresses
     * @param list<array<string, mixed>> $hours
     *
     * @return array<string, mixed>
     */
    public static function publicDetail(array $restaurant, array $addresses, array $hours, bool $isOpenNow): array
    {
        $primary = self::primary($addresses);

        return self::summary($restaurant, $primary, $isOpenNow) + [
            'phone'         => $restaurant['phone'],
            'address'       => $primary === null ? null : self::address($primary),
            'opening_hours' => array_map(self::hour(...), $hours),
            'timezone'      => $restaurant['timezone'],
        ];
    }

    /**
     * Vue complète pour l'équipe du restaurant.
     *
     * @param array<string, mixed>       $restaurant
     * @param list<array<string, mixed>> $addresses
     * @param list<array<string, mixed>> $hours
     *
     * @return array<string, mixed>
     */
    public static function management(array $restaurant, array $addresses, array $hours, bool $isOpenNow): array
    {
        return self::summary($restaurant, self::primary($addresses), $isOpenNow) + [
            'phone'               => $restaurant['phone'],
            'email'               => $restaurant['email'],
            'status'              => $restaurant['status'],
            'status_reason'       => $restaurant['status_reason'],
            'commission_rate'     => $restaurant['commission_rate'],
            'delivery_managed_by' => $restaurant['delivery_managed_by'],
            'timezone'            => $restaurant['timezone'],
            'addresses'           => array_map(self::address(...), $addresses),
            'opening_hours'       => array_map(self::hour(...), $hours),
            'approved_at'         => UserPresenter::isoDate($restaurant['approved_at']),
            'created_at'          => UserPresenter::isoDate($restaurant['created_at']),
            'updated_at'          => UserPresenter::isoDate($restaurant['updated_at']),
        ];
    }

    /**
     * @param array<string, mixed> $address
     *
     * @return array<string, mixed>
     */
    public static function address(array $address): array
    {
        return [
            'id'           => (int) $address['id'],
            'label'        => $address['label'],
            'address_line' => $address['address_line'],
            'commune'      => $address['commune'],
            'city'         => $address['city'],
            'province'     => $address['province'],
            'country'      => $address['country'],
            'latitude'     => $address['latitude'],
            'longitude'    => $address['longitude'],
            'is_primary'   => (bool) $address['is_primary'],
        ];
    }

    /**
     * @param array<string, mixed> $hour
     *
     * @return array<string, mixed>
     */
    public static function hour(array $hour): array
    {
        $closed = (bool) $hour['is_closed'];

        return [
            'day_of_week' => (int) $hour['day_of_week'],
            'is_closed'   => $closed,
            'opens_at'    => $closed || $hour['opens_at'] === null ? null : substr((string) $hour['opens_at'], 0, 5),
            'closes_at'   => $closed || $hour['closes_at'] === null ? null : substr((string) $hour['closes_at'], 0, 5),
        ];
    }

    /**
     * @param list<array<string, mixed>> $addresses
     *
     * @return array<string, mixed>|null
     */
    private static function primary(array $addresses): ?array
    {
        foreach ($addresses as $address) {
            if ((bool) $address['is_primary']) {
                return $address;
            }
        }

        return $addresses[0] ?? null;
    }
}
