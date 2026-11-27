<?php

declare(strict_types=1);

namespace App\Modules\Restaurants\Validators;

/**
 * Règles de format des données restaurant. Les règles métier sont dans les services.
 */
final class RestaurantRules
{
    public const TIMEZONES = ['Africa/Kinshasa', 'Africa/Lubumbashi'];

    /**
     * @return array<string, array{label: string, rules: string}>
     */
    public static function profile(bool $creating): array
    {
        $required = $creating ? 'required' : 'permit_empty';

        return [
            'name'                        => ['label' => 'nom', 'rules' => "{$required}|string|min_length[2]|max_length[180]"],
            'description'                 => ['label' => 'description', 'rules' => 'permit_empty|string|max_length[2000]'],
            'phone'                       => ['label' => 'téléphone', 'rules' => "{$required}|wf_phone"],
            'email'                       => ['label' => 'e-mail', 'rules' => 'permit_empty|valid_email|max_length[190]'],
            'currency'                    => ['label' => 'devise', 'rules' => 'permit_empty|in_list[USD,CDF]'],
            'minimum_order_amount'        => ['label' => 'minimum de commande', 'rules' => 'permit_empty|wf_money'],
            'average_preparation_minutes' => ['label' => 'temps de préparation', 'rules' => 'permit_empty|wf_int|greater_than_equal_to[1]|less_than_equal_to[600]'],
            'accepts_delivery'            => ['label' => 'livraison', 'rules' => 'permit_empty|wf_boolean'],
            'accepts_pickup'              => ['label' => 'retrait sur place', 'rules' => 'permit_empty|wf_boolean'],
            'delivery_fee'                => ['label' => 'frais de livraison', 'rules' => 'permit_empty|wf_money'],
            'free_delivery_threshold'     => ['label' => 'seuil de livraison gratuite', 'rules' => 'permit_empty|wf_money'],
            'timezone'                    => ['label' => 'fuseau horaire', 'rules' => 'permit_empty|in_list[' . implode(',', self::TIMEZONES) . ']'],
        ];
    }

    /**
     * @return array<string, array{label: string, rules: string}>
     */
    public static function address(bool $creating = true): array
    {
        $required = $creating ? 'required' : 'permit_empty';

        return [
            'label'        => ['label' => 'libellé', 'rules' => 'permit_empty|string|max_length[100]'],
            'address_line' => ['label' => 'adresse', 'rules' => "{$required}|string|min_length[3]|max_length[255]"],
            'commune'      => ['label' => 'commune', 'rules' => 'permit_empty|string|max_length[100]'],
            'city'         => ['label' => 'ville', 'rules' => 'permit_empty|string|max_length[100]'],
            'province'     => ['label' => 'province', 'rules' => 'permit_empty|string|max_length[100]'],
            'latitude'     => ['label' => 'latitude', 'rules' => 'permit_empty|decimal|greater_than_equal_to[-90]|less_than_equal_to[90]'],
            'longitude'    => ['label' => 'longitude', 'rules' => 'permit_empty|decimal|greater_than_equal_to[-180]|less_than_equal_to[180]'],
            'is_primary'   => ['label' => 'adresse principale', 'rules' => 'permit_empty|wf_boolean'],
        ];
    }
}
