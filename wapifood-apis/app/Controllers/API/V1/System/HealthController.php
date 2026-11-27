<?php

declare(strict_types=1);

namespace App\Controllers\API\V1\System;

use App\Common\Http\ApiResponse;
use App\Controllers\API\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;
use Config\WapiFood;
use Throwable;

/**
 * Endpoint de santé : utilisé par la supervision et le load balancer.
 *
 * Ne révèle aucune information sensible (ni version de PHP, ni environnement,
 * ni message d'erreur de la base de données).
 */
final class HealthController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $databaseOk = $this->checkDatabase();

        $data = [
            'status'      => $databaseOk ? 'ok' : 'degraded',
            'service'     => 'wapifood-api',
            'api_version' => config(WapiFood::class)->apiVersion,
            'time'        => gmdate('Y-m-d\TH:i:s\Z'),
            'checks'      => [
                'database' => $databaseOk ? 'ok' : 'unavailable',
            ],
        ];

        if (! $databaseOk) {
            return ApiResponse::error(
                $this->response,
                503,
                'Service temporairement indisponible.',
                ['database' => 'unavailable'],
                ['health' => $data],
            );
        }

        return $this->ok($data, 'Service opérationnel.');
    }

    private function checkDatabase(): bool
    {
        try {
            $row = db_connect()->query('SELECT 1 AS ok')->getRowArray();

            return (int) ($row['ok'] ?? 0) === 1;
        } catch (Throwable $exception) {
            log_message('critical', '[Health] Base de données injoignable : {class}', ['class' => $exception::class]);

            return false;
        }
    }
}
