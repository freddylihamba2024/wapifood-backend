<?php

declare(strict_types=1);

namespace App\Modules\Auth\Delivery;

use RuntimeException;

/**
 * DÉVELOPPEMENT UNIQUEMENT : écrit le code dans writable/logs au lieu de l'envoyer.
 * Refusé en production (voir Config\Services::authCodeSender()).
 */
final class LogAuthCodeSender implements AuthCodeSenderInterface
{
    public function __construct()
    {
        if (ENVIRONMENT === 'production') {
            throw new RuntimeException('LogAuthCodeSender est interdit en production.');
        }
    }

    public function send(string $channel, string $destination, string $purpose, string $code): void
    {
        log_message('notice', '[DEV OTP] {purpose} via {channel} → {destination} : {code}', [
            'purpose'     => $purpose,
            'channel'     => $channel,
            'destination' => $destination,
            'code'        => $code,
        ]);
    }

    public function supports(string $channel): bool
    {
        return true;
    }
}
