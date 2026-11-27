<?php

declare(strict_types=1);

namespace App\Modules\Auth\Delivery;

use App\Modules\Notifications\Channels\ChannelException;
use App\Modules\Notifications\Channels\NotificationChannelRegistry;
use Config\Auth;

/**
 * Envoi des codes OTP par les canaux de notification actifs (auth.otpDelivery = channels).
 *
 * Envoi IMMÉDIAT (hors file d'attente) : un code doit arriver tout de suite et expire en quelques minutes.
 * Le code n'est jamais journalisé ni enregistré en clair.
 */
final class ChannelAuthCodeSender implements AuthCodeSenderInterface
{
    private const PURPOSES = [
        'phone_verification' => 'vérifier votre numéro',
        'password_reset'     => 'réinitialiser votre mot de passe',
        'email_verification' => 'vérifier votre adresse e-mail',
    ];

    public function __construct(private readonly NotificationChannelRegistry $channels, private readonly Auth $config = new Auth())
    {
    }

    public function supports(string $channel): bool
    {
        return $channel !== 'in_app' && $this->channels->get($channel) !== null;
    }

    public function send(string $channel, string $destination, string $purpose, string $code): void
    {
        $sender = $this->channels->get($channel) ?? throw UnavailableAuthCodeSender::unavailable();
        $minutes = max(1, intdiv($this->config->otpTtl, 60));
        $action  = self::PURPOSES[$purpose] ?? 'confirmer votre demande';

        try {
            $sender->send(
                $destination,
                'Votre code WapiFood',
                "WapiFood : votre code pour {$action} est {$code}. Il expire dans {$minutes} min. Ne le communiquez à personne.",
            );
        } catch (ChannelException $exception) {
            log_message('error', 'Envoi d\'un code OTP par {channel} impossible : {message}', ['channel' => $channel, 'message' => $exception->getMessage()]);

            throw UnavailableAuthCodeSender::unavailable();
        }
    }
}
