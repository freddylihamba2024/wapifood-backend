<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Models;

use CodeIgniter\Model;

/**
 * Table `wf_reviews` — Avis clients (un par commande), modération a posteriori (M10).
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class ReviewModel extends Model
{
    protected $table            = 'wf_reviews';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'order_id',
        'customer_id',
        'restaurant_id',
        'rating',
        'comment',
        'status',
        'moderated_at',
        'moderated_by',
        'moderation_reason',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'            => 'int',
        'order_id'      => 'int',
        'customer_id'   => 'int',
        'restaurant_id' => 'int',
        'rating'        => 'int',
        'moderated_by'  => '?int',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
    protected $deletedField  = '';
}
