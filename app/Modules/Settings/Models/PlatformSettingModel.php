<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use CodeIgniter\Model;

/**
 * Table `wf_platform_settings` — Paramètres de la plateforme (aucun secret).
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class PlatformSettingModel extends Model
{
    protected $table            = 'wf_platform_settings';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'setting_key',
        'setting_value',
        'value_type',
        'description',
        'is_public',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'        => 'int',
        'is_public' => 'bool',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = '';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = '';
}
