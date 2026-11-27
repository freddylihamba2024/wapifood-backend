<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Paramètres initiaux de la plateforme (identiques à wapifood_database.sql).
 * Idempotent : une valeur déjà présente (éventuellement modifiée par l'admin) n'est jamais écrasée.
 * Aucun secret ici : les clés des prestataires restent dans .env.
 */
class PlatformSettingSeeder extends Seeder
{
    /** @var list<array{setting_key: string, setting_value: string, value_type: string, description: string, is_public: int}> */
    public const SETTINGS = [
        ['setting_key' => 'default_currency', 'setting_value' => 'USD', 'value_type' => 'string', 'description' => 'Devise par défaut de la plateforme', 'is_public' => 1],
        ['setting_key' => 'supported_currencies', 'setting_value' => '["USD","CDF"]', 'value_type' => 'json', 'description' => 'Devises acceptées', 'is_public' => 1],
        ['setting_key' => 'default_commission_rate', 'setting_value' => '10.00', 'value_type' => 'number', 'description' => 'Taux de commission par défaut en pourcentage', 'is_public' => 0],
        ['setting_key' => 'platform_name', 'setting_value' => 'WapiFood', 'value_type' => 'string', 'description' => 'Nom de la plateforme', 'is_public' => 1],
    ];

    public function run(): void
    {
        $this->db->table('wf_platform_settings')->ignore(true)->insertBatch(self::SETTINGS);
    }
}
