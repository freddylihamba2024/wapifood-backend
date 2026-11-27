<?php

declare(strict_types=1);

namespace App\Modules\Products\Models;

use CodeIgniter\Model;

/**
 * Table `wf_product_images` — Images d'un produit (isolation via wf_products.restaurant_id).
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class ProductImageModel extends Model
{
    protected $table            = 'wf_product_images';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'product_id',
        'image_url',
        'alt_text',
        'sort_order',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'         => 'int',
        'product_id' => 'int',
        'sort_order' => 'int',
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = '';
    protected $updatedField  = '';
    protected $deletedField  = '';
}
