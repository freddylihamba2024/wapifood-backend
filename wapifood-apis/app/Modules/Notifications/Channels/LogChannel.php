<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels;

use RuntimeException;

/**
 * DÉVELOPPEMENT UNIQUEMENT : écrit le message dans writable/logs au lieu de l'envoyer
 * (SMS_PROVIDER=log ou WHATSAPP_PROVIDER=log). Refusé en production.
 */
final class LogChannel implements NotificationChannelInterface
{
    public function __construct(private readonly string $channel)
    {
        if (ENVIRONMENT === 'production') {
            throw new RuntimeException('LogChannel est interdit en production.');
        }
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function provider(): string
    {
        return 'log';
    }

    public function send(string $recipient, string $title, string $message, array $data = []): ?string
    {
        log_message('notice', '[DEV {channel}] → {recipient} : {message}', [
            'channel'   => strtoupper($this->channel),
            'recipient' => $recipient,
            'message'   => $message,
        ]);

        return 'log-' . bin2hex(random_bytes(8));
    }
}
