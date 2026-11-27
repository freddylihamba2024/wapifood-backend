<?php

declare(strict_types=1);

namespace App\Modules\Users\Models;

use CodeIgniter\Model;

/**
 * Table `wf_users` — Comptes utilisateurs (clients, restaurateurs, personnel, livreurs, admins).
 *
 * password_hash ne doit jamais sortir de l'API : passer par UserPresenter.
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class UserModel extends Model
{
    protected $table            = 'wf_users';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'password_hash',
        'avatar_url',
        'status',
        'email_verified_at',
        'phone_verified_at',
        'last_login_at',
        'token_version',
        'password_changed_at',
        'failed_login_attempts',
        'locked_until',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'                    => 'int',
        'token_version'         => 'int',
        'failed_login_attempts' => 'int',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';
}
