<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use CodeIgniter\Model;

/**
 * Table `wf_payment_refunds` — Remboursements d'un paiement (M9).
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class PaymentRefundModel extends Model
{
    protected $table            = 'wf_payment_refunds';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'payment_id',
        'order_id',
        'amount',
        'currency',
        'method',
        'status',
        'provider_refund_id',
        'reason',
        'created_by',
        'processed_at',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'         => 'int',
        'payment_id' => 'int',
        'order_id'   => 'int',
        'created_by' => '?int',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = '';
}
