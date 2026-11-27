<?php

declare(strict_types=1);

namespace App\Modules\Commissions\Models;

use CodeIgniter\Model;

/**
 * Table `wf_commissions` — Commission WapiFood par commande (taux figé).
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class CommissionModel extends Model
{
    protected $table            = 'wf_commissions';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'order_id',
        'restaurant_id',
        'commission_rate',
        'order_amount',
        'commission_amount',
        'restaurant_amount',
        'currency',
        'status',
        'earned_at',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'            => 'int',
        'order_id'      => 'int',
        'restaurant_id' => 'int',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
    protected $deletedField  = '';
}
