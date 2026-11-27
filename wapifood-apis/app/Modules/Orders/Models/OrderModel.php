<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use CodeIgniter\Model;

/**
 * Table `wf_orders` — Commandes (une commande = un restaurant, une devise).
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class OrderModel extends Model
{
    protected $table            = 'wf_orders';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'order_number',
        'idempotency_key',
        'customer_id',
        'restaurant_id',
        'order_type',
        'delivery_address_id',
        'customer_name',
        'customer_phone',
        'delivery_address_snapshot',
        'delivery_instructions',
        'currency',
        'subtotal',
        'discount_amount',
        'delivery_fee',
        'tax_amount',
        'total_amount',
        'customer_note',
        'requested_for',
        'status',
        'payment_status',
        'accepted_at',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'                  => 'int',
        'customer_id'         => 'int',
        'restaurant_id'       => 'int',
        'delivery_address_id' => '?int',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = '';
}
