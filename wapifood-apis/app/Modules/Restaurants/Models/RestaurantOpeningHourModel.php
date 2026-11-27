<?php

declare(strict_types=1);

namespace App\Modules\Restaurants\Models;

use CodeIgniter\Model;

/**
 * Table `wf_restaurant_opening_hours` — Horaires d'ouverture (1 = lundi … 7 = dimanche).
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class RestaurantOpeningHourModel extends Model
{
    protected $table            = 'wf_restaurant_opening_hours';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'restaurant_id',
        'day_of_week',
        'opens_at',
        'closes_at',
        'is_closed',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'            => 'int',
        'restaurant_id' => 'int',
        'day_of_week'   => 'int',
        'is_closed'     => 'bool',
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = '';
    protected $updatedField  = '';
    protected $deletedField  = '';
}
