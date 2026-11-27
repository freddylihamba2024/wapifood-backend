<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels;

use RuntimeException;

/**
 * Échec d'envoi. « permanent » : inutile de réessayer (destinataire invalide, canal désactivé…).
 */
final class ChannelException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $permanent = false)
    {
        parent::__construct($message);
    }

    public static function permanent(string $message): self
    {
        return new self($message, true);
    }
}
