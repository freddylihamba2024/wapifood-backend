<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Données de référence obligatoires. Rejouable sans effet de bord.
 *
 *   php spark db:seed DatabaseSeeder
 *
 * Aucun compte utilisateur n'est créé ici : le premier super administrateur
 * se crée avec `php spark wapifood:create-admin`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $this->call(PlatformSettingSeeder::class);
    }
}
