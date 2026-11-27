<?php

declare(strict_types=1);

namespace App\Modules\Users\Models;

use CodeIgniter\Model;

/**
 * Table `wf_roles` — Rôles globaux de la plateforme.
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class RoleModel extends Model
{
    protected $table            = 'wf_roles';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'code',
        'name',
        'description',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id' => 'int',
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = '';
    protected $updatedField  = '';
    protected $deletedField  = '';
}
