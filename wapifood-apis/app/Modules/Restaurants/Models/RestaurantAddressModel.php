<?php

declare(strict_types=1);

namespace App\Modules\Restaurants\Models;

use CodeIgniter\Model;

/**
 * Table `wf_restaurant_addresses` — Adresses d'un restaurant.
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class RestaurantAddressModel extends Model
{
    protected $table            = 'wf_restaurant_addresses';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'restaurant_id',
        'label',
        'address_line',
        'commune',
        'city',
        'province',
        'country',
        'latitude',
        'longitude',
        'is_primary',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'            => 'int',
        'restaurant_id' => 'int',
        'is_primary'    => 'bool',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
    protected $deletedField  = '';
}
