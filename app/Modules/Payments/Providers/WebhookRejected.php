<?php

declare(strict_types=1);

namespace App\Modules\Payments\Providers;

use RuntimeException;

/** Notification refusée : signature invalide ou contenu illisible. */
final class WebhookRejected extends RuntimeException
{
}
