<?php

declare(strict_types=1);

namespace App\Modules\Deliveries\Models;

use CodeIgniter\Model;

/**
 * Table `wf_deliveries` — Livraison associée à une commande.
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class DeliveryModel extends Model
{
    protected $table            = 'wf_deliveries';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'order_id',
        'courier_id',
        'courier_name',
        'managed_by',
        'delivery_status',
        'delivery_fee',
        'courier_phone',
        'pickup_at',
        'delivered_at',
        'proof_url',
        'notes',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'         => 'int',
        'order_id'   => 'int',
        'courier_id' => '?int',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = '';
}
