<?php

declare(strict_types=1);

namespace App\Modules\Restaurants\Models;

use CodeIgniter\Model;

/**
 * Table `wf_restaurant_users` — Membres d'un restaurant et leur rôle local (owner, manager, staff, cashier).
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class RestaurantUserModel extends Model
{
    protected $table            = 'wf_restaurant_users';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'restaurant_id',
        'user_id',
        'restaurant_role',
        'status',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'            => 'int',
        'restaurant_id' => 'int',
        'user_id'       => 'int',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
    protected $deletedField  = '';
}
