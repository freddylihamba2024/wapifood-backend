<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use CodeIgniter\Model;

/**
 * Table `wf_auth_codes` — codes à usage unique (HMAC-SHA256, jamais en clair).
 */
class AuthCodeModel extends Model
{
    protected $table            = 'wf_auth_codes';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'user_id',
        'channel',
        'destination',
        'purpose',
        'code_hash',
        'attempts',
        'max_attempts',
        'expires_at',
        'consumed_at',
        'ip_address',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'           => 'int',
        'user_id'      => '?int',
        'attempts'     => 'int',
        'max_attempts' => 'int',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
    protected $deletedField  = '';
}
