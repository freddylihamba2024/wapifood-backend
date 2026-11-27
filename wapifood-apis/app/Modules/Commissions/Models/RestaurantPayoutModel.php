<?php

declare(strict_types=1);

namespace App\Modules\Commissions\Models;

use CodeIgniter\Model;

/**
 * Table `wf_restaurant_payouts` — Reversements aux restaurants.
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class RestaurantPayoutModel extends Model
{
    protected $table            = 'wf_restaurant_payouts';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'restaurant_id',
        'requested_by',
        'amount',
        'currency',
        'payout_method',
        'payout_account',
        'reference',
        'status',
        'admin_note',
        'processed_by',
        'requested_at',
        'processed_at',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'            => 'int',
        'restaurant_id' => 'int',
        'requested_by'  => '?int',
        'processed_by'  => '?int',
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = '';
    protected $updatedField  = '';
    protected $deletedField  = '';
}
