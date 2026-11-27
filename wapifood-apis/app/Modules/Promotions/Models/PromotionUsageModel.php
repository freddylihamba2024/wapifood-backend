<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Models;

use CodeIgniter\Model;

/**
 * Table `wf_promotion_usages` — Utilisations des codes promotionnels.
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class PromotionUsageModel extends Model
{
    protected $table            = 'wf_promotion_usages';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'promotion_id',
        'order_id',
        'user_id',
        'discount_amount',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'           => 'int',
        'promotion_id' => 'int',
        'order_id'     => 'int',
        'user_id'      => 'int',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
    protected $deletedField  = '';
}
