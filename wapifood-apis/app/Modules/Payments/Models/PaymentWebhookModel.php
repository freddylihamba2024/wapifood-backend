<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use CodeIgniter\Model;

/**
 * Table `wf_payment_webhooks` — Notifications reçues des prestataires de paiement.
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class PaymentWebhookModel extends Model
{
    protected $table            = 'wf_payment_webhooks';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'provider',
        'event_id',
        'merchant_reference',
        'provider_transaction_id',
        'signature_valid',
        'processing_status',
        'payload',
        'processing_error',
        'received_at',
        'processed_at',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'              => 'int',
        'signature_valid' => 'bool',
        'payload'         => 'json-array',
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = '';
    protected $updatedField  = '';
    protected $deletedField  = '';
}
