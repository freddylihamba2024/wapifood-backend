<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use CodeIgniter\Model;

/**
 * Table `wf_refresh_tokens` — sessions (seul le hash SHA-256 du jeton est stocké).
 */
class RefreshTokenModel extends Model
{
    protected $table            = 'wf_refresh_tokens';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'user_id',
        'family_id',
        'token_hash',
        'replaced_by_id',
        'expires_at',
        'revoked_at',
        'revoked_reason',
        'ip_address',
        'user_agent',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'             => 'int',
        'user_id'        => 'int',
        'replaced_by_id' => '?int',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
    protected $deletedField  = '';
}
