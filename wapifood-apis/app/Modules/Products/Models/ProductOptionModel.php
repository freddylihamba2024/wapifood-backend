<?php

declare(strict_types=1);

namespace App\Modules\Products\Models;

use CodeIgniter\Model;

/**
 * Table `wf_product_options` — Options d'un groupe.
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class ProductOptionModel extends Model
{
    protected $table            = 'wf_product_options';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'option_group_id',
        'name',
        'price_delta',
        'is_active',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'              => 'int',
        'option_group_id' => 'int',
        'is_active'       => 'bool',
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = '';
    protected $updatedField  = '';
    protected $deletedField  = '';
}
