<?php

declare(strict_types=1);

namespace App\Modules\Commissions\Models;

use CodeIgniter\Model;

/**
 * Table `wf_restaurant_balances` — Soldes par restaurant et par devise.
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class RestaurantBalanceModel extends Model
{
    protected $table            = 'wf_restaurant_balances';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'restaurant_id',
        'currency',
        'commission_due',
        'available_balance',
        'pending_balance',
        'lifetime_earned',
        'lifetime_paid',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'            => 'int',
        'restaurant_id' => 'int',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = '';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = '';
}
