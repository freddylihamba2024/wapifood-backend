<?php

declare(strict_types=1);

namespace App\Modules\Auth\Delivery;

use App\Common\Exceptions\ApiException;

/**
 * Aucun fournisseur d'envoi configuré : toute action nécessitant un code répond 503.
 */
final class UnavailableAuthCodeSender implements AuthCodeSenderInterface
{
    public function send(string $channel, string $destination, string $purpose, string $code): void
    {
        throw self::unavailable();
    }

    public function supports(string $channel): bool
    {
        return false;
    }

    public static function unavailable(): ApiException
    {
        return new ApiException('L\'envoi de codes de vérification est momentanément indisponible.', 503, [], 'OTP_DELIVERY_UNAVAILABLE');
    }
}
