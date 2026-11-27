<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use CodeIgniter\Model;

/**
 * Table `wf_order_items` — Lignes de commande (snapshot nom, prix, options).
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class OrderItemModel extends Model
{
    protected $table            = 'wf_order_items';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'order_id',
        'product_id',
        'product_name',
        'variant_name',
        'quantity',
        'unit_price',
        'options_snapshot',
        'line_total',
        'note',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'               => 'int',
        'order_id'         => 'int',
        'product_id'       => '?int',
        'quantity'         => 'int',
        'options_snapshot' => '?json-array',
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = '';
    protected $updatedField  = '';
    protected $deletedField  = '';
}
