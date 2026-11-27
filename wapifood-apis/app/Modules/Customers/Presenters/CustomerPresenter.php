<?php

declare(strict_types=1);

namespace App\Modules\Customers\Presenters;

use App\Modules\Users\Presenters\UserPresenter;

/**
 * Représentation API des données client.
 */
final class CustomerPresenter
{
    /**
     * @param array<string, mixed> $address
     *
     * @return array<string, mixed>
     */
    public static function address(array $address): array
    {
        return [
            'id'                    => (int) $address['id'],
            'label'                 => $address['label'],
            'recipient_name'        => $address['recipient_name'],
            'recipient_phone'       => $address['recipient_phone'],
            'address_line'          => $address['address_line'],
            'commune'               => $address['commune'],
            'neighborhood'          => $address['neighborhood'],
            'landmark'              => $address['landmark'],
            'city'                  => $address['city'],
            'country'               => $address['country'],
            'latitude'              => $address['latitude'],
            'longitude'             => $address['longitude'],
            'delivery_instructions' => $address['delivery_instructions'],
            'is_default'            => (bool) $address['is_default'],
            'created_at'            => UserPresenter::isoDate($address['created_at']),
            'updated_at'            => UserPresenter::isoDate($address['updated_at'] ?? null),
        ];
    }

    /**
     * Snapshot d'adresse figé dans la commande (indépendant des modifications ultérieures).
     *
     * @param array<string, mixed> $address
     *
     * @return array<string, mixed>
     */
    public static function addressSnapshot(array $address): array
    {
        return array_intersect_key($address, array_flip([
            'label', 'recipient_name', 'recipient_phone', 'address_line', 'commune', 'neighborhood',
            'landmark', 'city', 'country', 'latitude', 'longitude',
        ]));
    }
}
