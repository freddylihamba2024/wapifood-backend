<?php

declare(strict_types=1);

namespace App\Modules\Restaurants\Models;

use CodeIgniter\Model;

/**
 * Table `wf_restaurants` — Restaurants (tenants).
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class RestaurantModel extends Model
{
    protected $table            = 'wf_restaurants';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'owner_user_id',
        'name',
        'slug',
        'description',
        'logo_url',
        'cover_url',
        'phone',
        'email',
        'currency',
        'commission_rate',
        'minimum_order_amount',
        'average_preparation_minutes',
        'accepts_delivery',
        'accepts_pickup',
        'delivery_managed_by',
        'is_featured',
        'status',
        'status_reason',
        'approved_at',
        'approved_by',
        'delivery_fee',
        'free_delivery_threshold',
        'timezone',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'                          => 'int',
        'owner_user_id'               => 'int',
        'approved_by'                 => '?int',
        'average_preparation_minutes' => 'int',
        'accepts_delivery'            => 'bool',
        'accepts_pickup'              => 'bool',
        'is_featured'                 => 'bool',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';
}
