<?php

declare(strict_types=1);

namespace App\Modules\Menus\Models;

use CodeIgniter\Model;

/**
 * Table `wf_menu_categories` — Catégories du menu d'un restaurant.
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class MenuCategoryModel extends Model
{
    protected $table            = 'wf_menu_categories';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'restaurant_id',
        'name',
        'description',
        'image_url',
        'sort_order',
        'is_active',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'            => 'int',
        'restaurant_id' => 'int',
        'sort_order'    => 'int',
        'is_active'     => 'bool',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = '';
}
