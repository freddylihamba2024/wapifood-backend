<?php

declare(strict_types=1);

namespace App\Modules\Products\Models;

use CodeIgniter\Model;

/**
 * Table `wf_product_option_groups` — Groupes d'options d'un produit (suppléments, choix).
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class ProductOptionGroupModel extends Model
{
    protected $table            = 'wf_product_option_groups';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'product_id',
        'name',
        'selection_type',
        'is_required',
        'min_selections',
        'max_selections',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'             => 'int',
        'product_id'     => 'int',
        'is_required'    => 'bool',
        'min_selections' => 'int',
        'max_selections' => 'int',
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = '';
    protected $updatedField  = '';
    protected $deletedField  = '';
}
