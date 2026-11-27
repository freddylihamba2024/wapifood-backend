<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Models;

use CodeIgniter\Model;

/**
 * Table `wf_promotions` — Codes promotionnels (restaurant_id NULL = promotion plateforme).
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class PromotionModel extends Model
{
    protected $table            = 'wf_promotions';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'restaurant_id',
        'code',
        'title',
        'discount_type',
        'discount_value',
        'minimum_order_amount',
        'maximum_discount_amount',
        'currency',
        'usage_limit',
        'usage_per_customer',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'                 => 'int',
        'restaurant_id'      => '?int',
        'usage_limit'        => '?int',
        'usage_per_customer' => '?int',
        'is_active'          => 'bool',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
    protected $deletedField  = '';
}
