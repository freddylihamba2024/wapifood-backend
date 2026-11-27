<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Rôles globaux de la plateforme (identifiants figés, identiques à wapifood_database.sql).
 * Idempotent : INSERT IGNORE, aucune ligne existante n'est modifiée.
 */
class RoleSeeder extends Seeder
{
    /** @var list<array{id: int, code: string, name: string, description: string}> */
    public const ROLES = [
        ['id' => 1, 'code' => 'customer', 'name' => 'Client', 'description' => 'Utilisateur qui commande des repas'],
        ['id' => 2, 'code' => 'restaurant_owner', 'name' => 'Propriétaire restaurant', 'description' => 'Gère un ou plusieurs restaurants'],
        ['id' => 3, 'code' => 'restaurant_staff', 'name' => 'Personnel restaurant', 'description' => 'Accès opérationnel au restaurant'],
        ['id' => 4, 'code' => 'courier', 'name' => 'Livreur', 'description' => 'Livre les commandes qui lui sont attribuées'],
        ['id' => 5, 'code' => 'super_admin', 'name' => 'Super administrateur', 'description' => 'Administration complète de WapiFood'],
    ];

    public function run(): void
    {
        $this->db->table('wf_roles')->ignore(true)->insertBatch(self::ROLES);
    }
}
