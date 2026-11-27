<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use CodeIgniter\Model;

/**
 * Table `wf_customer_favorites` — Restaurants favoris d'un client.
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class CustomerFavoriteModel extends Model
{
    protected $table            = 'wf_customer_favorites';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'user_id',
        'restaurant_id',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'            => 'int',
        'user_id'       => 'int',
        'restaurant_id' => 'int',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
    protected $deletedField  = '';
}
