<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use CodeIgniter\Model;

/**
 * Table `wf_notification_attempts` (M11) — Historique des tentatives d'envoi (insertion uniquement).
 */
class NotificationAttemptModel extends Model
{
    protected $table            = 'wf_notification_attempts';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'notification_id',
        'attempt_number',
        'provider',
        'status',
        'provider_message_id',
        'error_message',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'              => 'int',
        'notification_id' => 'int',
        'attempt_number'  => 'int',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
    protected $deletedField  = '';
}
