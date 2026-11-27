<?php

declare(strict_types=1);

namespace App\Controllers\API\V1\System;

use App\Controllers\API\BaseApiController;
use App\Modules\Payments\Services\PaymentWebhookService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Notifications des fournisseurs de paiement : POST /api/v1/webhooks/payments/{provider}
 * (sans authentification : l'authenticité est vérifiée par la signature du fournisseur).
 * Aucun fournisseur actif au MVP → 404.
 */
final class PaymentWebhookController extends BaseApiController
{
    public function receive(string $provider): ResponseInterface
    {
        $headers = [];

        foreach ($this->request->headers() as $name => $header) {
            $headers[strtolower($name)] = $this->request->getHeaderLine($name);
        }

        $result = (new PaymentWebhookService())->handle(mb_substr($provider, 0, 50), (string) $this->request->getBody(), $headers);

        return $this->ok($result, 'Notification reçue.');
    }
}
