<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use CodeIgniter\Model;

/**
 * Table `wf_payments` — Paiements d'une commande.
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class PaymentModel extends Model
{
    protected $table            = 'wf_payments';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'order_id',
        'provider',
        'payment_method',
        'provider_transaction_id',
        'merchant_reference',
        'amount',
        'currency',
        'status',
        'payer_phone',
        'paid_at',
        'raw_response',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'           => 'int',
        'order_id'     => 'int',
        'raw_response' => '?json-array',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = '';
}
