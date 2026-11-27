<?php

declare(strict_types=1);

namespace App\Modules\Commissions\Models;

use CodeIgniter\Model;

/**
 * Table `wf_restaurant_ledger_entries` — Journal financier des restaurants (M8).
 *
 * Table de journal : les services n'y font que des insertions (via LedgerService, qui met à jour
 * le solde dans la même transaction). Les montants restent des chaînes.
 */
class RestaurantLedgerEntryModel extends Model
{
    protected $table            = 'wf_restaurant_ledger_entries';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'restaurant_id',
        'currency',
        'entry_type',
        'amount',
        'balance_after',
        'order_id',
        'commission_id',
        'payout_id',
        'reference',
        'note',
        'created_by',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'            => 'int',
        'restaurant_id' => 'int',
        'order_id'      => '?int',
        'commission_id' => '?int',
        'payout_id'     => '?int',
        'created_by'    => '?int',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
    protected $deletedField  = '';
}
