<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Notifications\Channels\ChannelException;
use App\Modules\Notifications\Channels\NotificationChannelInterface;

/**
 * Canal de test : enregistre les envois ; peut échouer (temporairement ou définitivement).
 *
 * @internal
 */
final class FakeNotificationChannel implements NotificationChannelInterface
{
    /** @var list<array{recipient: string, title: string, message: string}> */
    public array $sent = [];

    /** @var list<ChannelException> Échecs à produire, dans l'ordre, avant de réussir */
    public array $failures = [];

    public function __construct(private readonly string $channel)
    {
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function provider(): string
    {
        return 'fake';
    }

    public function send(string $recipient, string $title, string $message, array $data = []): ?string
    {
        if ($this->failures !== []) {
            throw array_shift($this->failures);
        }

        $this->sent[] = ['recipient' => $recipient, 'title' => $title, 'message' => $message];

        return 'fake-' . count($this->sent);
    }
}
