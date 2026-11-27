<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use CodeIgniter\Model;

/**
 * Table `wf_customer_addresses` — Adresses de livraison d'un client (suppression logique, M6).
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class CustomerAddressModel extends Model
{
    protected $table            = 'wf_customer_addresses';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'user_id',
        'label',
        'recipient_name',
        'recipient_phone',
        'address_line',
        'commune',
        'neighborhood',
        'landmark',
        'city',
        'country',
        'latitude',
        'longitude',
        'delivery_instructions',
        'is_default',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'         => 'int',
        'user_id'    => 'int',
        'is_default' => 'bool',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';
}
