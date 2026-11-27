<?php

declare(strict_types=1);

namespace App\Modules\Users\Models;

use CodeIgniter\Model;

/**
 * Table `wf_user_roles` — Association utilisateur ↔ rôle global (clé primaire composite user_id + role_id).
 *
 * Clé primaire composite : utiliser where(['user_id' => …, 'role_id' => …]) pour les suppressions ;
 * find()/update() par identifiant ne sont pas pertinents sur cette table.
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class UserRoleModel extends Model
{
    protected $table            = 'wf_user_roles';
    protected $primaryKey       = 'user_id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'user_id',
        'role_id',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'user_id' => 'int',
        'role_id' => 'int',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
    protected $deletedField  = '';
}
