<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use CodeIgniter\Model;

/**
 * Table `wf_order_status_history` — Historique des statuts (journal : insertion uniquement).
 *
 * Table de journal : les services n'y font que des insertions.
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class OrderStatusHistoryModel extends Model
{
    protected $table            = 'wf_order_status_history';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'order_id',
        'old_status',
        'new_status',
        'changed_by',
        'comment',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'         => 'int',
        'order_id'   => 'int',
        'changed_by' => '?int',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
    protected $deletedField  = '';
}
