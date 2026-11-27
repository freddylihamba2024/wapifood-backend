<?php

declare(strict_types=1);

namespace App\Modules\Customers\Validators;

/**
 * Règles de format des données client.
 */
final class CustomerRules
{
    /**
     * @return array<string, array{label: string, rules: string}>
     */
    public static function address(bool $creating = true): array
    {
        $required = $creating ? 'required' : 'permit_empty';

        return [
            'label'                 => ['label' => 'libellé', 'rules' => 'permit_empty|string|max_length[80]'],
            'recipient_name'        => ['label' => 'destinataire', 'rules' => 'permit_empty|string|max_length[150]'],
            'recipient_phone'       => ['label' => 'téléphone du destinataire', 'rules' => 'permit_empty|wf_phone'],
            'address_line'          => ['label' => 'adresse', 'rules' => "{$required}|string|min_length[3]|max_length[255]"],
            'commune'               => ['label' => 'commune', 'rules' => 'permit_empty|string|max_length[100]'],
            'neighborhood'          => ['label' => 'quartier', 'rules' => 'permit_empty|string|max_length[120]'],
            'landmark'              => ['label' => 'point de repère', 'rules' => 'permit_empty|string|max_length[255]'],
            'city'                  => ['label' => 'ville', 'rules' => 'permit_empty|string|max_length[100]'],
            'country'               => ['label' => 'pays', 'rules' => 'permit_empty|string|max_length[100]'],
            'latitude'              => ['label' => 'latitude', 'rules' => 'permit_empty|decimal|greater_than_equal_to[-90]|less_than_equal_to[90]'],
            'longitude'             => ['label' => 'longitude', 'rules' => 'permit_empty|decimal|greater_than_equal_to[-180]|less_than_equal_to[180]'],
            'delivery_instructions' => ['label' => 'instructions de livraison', 'rules' => 'permit_empty|string|max_length[1000]'],
            'is_default'            => ['label' => 'adresse par défaut', 'rules' => 'permit_empty|wf_boolean'],
        ];
    }

    /**
     * Profil du compte (tous rôles). Le téléphone, identifiant de connexion vérifié par SMS,
     * n'est pas modifiable ici.
     *
     * @return array<string, array{label: string, rules: string}>
     */
    public static function profile(): array
    {
        return [
            'first_name' => ['label' => 'prénom', 'rules' => 'permit_empty|string|min_length[1]|max_length[100]'],
            'last_name'  => ['label' => 'nom', 'rules' => 'permit_empty|string|max_length[100]'],
            'email'      => ['label' => 'e-mail', 'rules' => 'permit_empty|valid_email|max_length[190]'],
        ];
    }
}
