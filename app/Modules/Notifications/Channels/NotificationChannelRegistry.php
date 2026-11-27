<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels;

/**
 * Canaux externes ACTIFS, par nom. Un canal absent est désactivé : la notification n'est pas créée
 * (dispatcher) ou échoue définitivement si elle était déjà en file (canal désactivé entre-temps).
 */
final class NotificationChannelRegistry
{
    /** @var array<string, NotificationChannelInterface> */
    private array $channels = [];

    /**
     * @param list<NotificationChannelInterface> $channels
     */
    public function __construct(array $channels)
    {
        foreach ($channels as $channel) {
            $this->channels[$channel->channel()] = $channel;
        }
    }

    public function get(string $channel): ?NotificationChannelInterface
    {
        return $this->channels[$channel] ?? null;
    }

    /** in_app est toujours disponible. */
    public function isEnabled(string $channel): bool
    {
        return $channel === 'in_app' || isset($this->channels[$channel]);
    }

    /**
     * @return list<string>
     */
    public function enabled(): array
    {
        return ['in_app', ...array_keys($this->channels)];
    }
}
