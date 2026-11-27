<?php

declare(strict_types=1);

namespace App\Modules\Deliveries\Models;

use CodeIgniter\Model;

/**
 * Table `wf_couriers` — Livreurs WapiFood (extension, hors périmètre MVP).
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class CourierModel extends Model
{
    protected $table            = 'wf_couriers';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'user_id',
        'vehicle_type',
        'vehicle_plate',
        'status',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'      => 'int',
        'user_id' => 'int',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
    protected $deletedField  = '';
}
