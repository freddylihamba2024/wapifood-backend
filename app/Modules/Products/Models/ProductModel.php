<?php

declare(strict_types=1);

namespace App\Modules\Products\Models;

use CodeIgniter\Model;

/**
 * Table `wf_products` — Produits du catalogue d'un restaurant.
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class ProductModel extends Model
{
    protected $table            = 'wf_products';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'restaurant_id',
        'category_id',
        'name',
        'slug',
        'description',
        'sku',
        'base_price',
        'currency',
        'image_url',
        'preparation_minutes',
        'is_available',
        'is_featured',
        'sort_order',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'                  => 'int',
        'restaurant_id'       => 'int',
        'category_id'         => '?int',
        'preparation_minutes' => '?int',
        'is_available'        => 'bool',
        'is_featured'         => 'bool',
        'sort_order'          => 'int',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';
}
