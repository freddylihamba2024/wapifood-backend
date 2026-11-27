<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use CodeIgniter\Model;

/**
 * Table `wf_notifications` — Notifications (in-app, e-mail, SMS, WhatsApp, push).
 *
 * Les montants DECIMAL sont retournés en chaînes et ne sont jamais convertis en float.
 */
class NotificationModel extends Model
{
    protected $table            = 'wf_notifications';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /** @var list<string> */
    protected $allowedFields = [
        'user_id',
        'restaurant_id',
        'channel',
        'recipient',
        'notification_type',
        'title',
        'message',
        'data',
        'status',
        'attempts',
        'max_attempts',
        'next_attempt_at',
        'locked_until',
        'last_error',
        'sent_at',
        'read_at',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'            => 'int',
        'user_id'       => '?int',
        'restaurant_id' => '?int',
        'attempts'      => 'int',
        'max_attempts'  => 'int',
        'data'          => '?json-array',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
    protected $deletedField  = '';
}
