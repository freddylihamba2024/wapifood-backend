<?php

declare(strict_types=1);

namespace App\Modules\Audit\Services;

use App\Modules\Audit\Models\AuditLogModel;
use CodeIgniter\HTTP\IncomingRequest;

/**
 * Journalisation des actions sensibles dans wf_audit_logs.
 *
 * Ne jamais passer de secret dans $details (mot de passe, jeton, code OTP, clé API) :
 * les clés sensibles connues sont masquées par précaution.
 */
final class AuditLogger
{
    private const SENSITIVE_KEYS = ['password', 'password_hash', 'token', 'access_token', 'refresh_token', 'otp', 'code', 'secret', 'api_key'];

    public function __construct(private readonly AuditLogModel $model = new AuditLogModel())
    {
    }

    /**
     * @param array<string, mixed> $details
     */
    public function log(string $action, ?int $userId = null, ?string $entityType = null, ?int $entityId = null, array $details = []): void
    {
        $request = service('request');
        $isHttp  = $request instanceof IncomingRequest;

        $this->model->insert([
            'user_id'     => $userId,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'ip_address'  => $isHttp ? $request->getIPAddress() : null,
            'user_agent'  => $isHttp ? mb_substr((string) $request->getUserAgent(), 0, 500) : 'cli',
            'details'     => $details === [] ? null : $this->mask($details),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function mask(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->mask($value);
            } elseif (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                $data[$key] = '***';
            }
        }

        return $data;
    }
}
