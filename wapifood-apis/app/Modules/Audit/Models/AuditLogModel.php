<?php

declare(strict_types=1);

namespace App\Modules\Audit\Models;

use CodeIgniter\Model;

/**
 * Table `wf_audit_logs` — Journal d'audit des actions sensibles (insertion uniquement).
 *
 * Table de journal : les services n'y font que des insertions.
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class AuditLogModel extends Model
{
    protected $table            = 'wf_audit_logs';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'ip_address',
        'user_agent',
        'details',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'        => 'int',
        'user_id'   => '?int',
        'entity_id' => '?int',
        'details'   => '?json-array',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
    protected $deletedField  = '';
}
