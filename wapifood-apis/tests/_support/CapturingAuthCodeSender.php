<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Auth\Delivery\AuthCodeSenderInterface;

/**
 * Double de test : capture les codes au lieu de les envoyer.
 */
final class CapturingAuthCodeSender implements AuthCodeSenderInterface
{
    /** @var list<array{channel: string, destination: string, purpose: string, code: string}> */
    public array $sent = [];

    public function send(string $channel, string $destination, string $purpose, string $code): void
    {
        $this->sent[] = compact('channel', 'destination', 'purpose', 'code');
    }

    public function supports(string $channel): bool
    {
        return true;
    }

    public function lastCode(?string $purpose = null): ?string
    {
        foreach (array_reverse($this->sent) as $message) {
            if ($purpose === null || $message['purpose'] === $purpose) {
                return $message['code'];
            }
        }

        return null;
    }
}
