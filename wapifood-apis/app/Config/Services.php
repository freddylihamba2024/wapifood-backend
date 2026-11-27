<?php

namespace Config;

use App\Common\Auth\AuthContext;
use App\Libraries\Jwt\JwtService;
use App\Modules\Auth\Delivery\AuthCodeSenderInterface;
use App\Modules\Auth\Delivery\ChannelAuthCodeSender;
use App\Modules\Auth\Delivery\LogAuthCodeSender;
use App\Modules\Auth\Delivery\UnavailableAuthCodeSender;
use App\Modules\Notifications\Channels\EmailChannel;
use App\Modules\Notifications\Channels\LogChannel;
use App\Modules\Notifications\Channels\NotificationChannelRegistry;
use App\Modules\Payments\Providers\CashPaymentProvider;
use App\Modules\Payments\Providers\PaymentProviderRegistry;
use CodeIgniter\Config\BaseService;

/**
 * Services WapiFood. Accès : service('<nom>'). Remplaçables en test via Services::injectMock().
 */
class Services extends BaseService
{
    /**
     * Utilisateur authentifié de la requête en cours.
     */
    public static function authContext(bool $getShared = true): AuthContext
    {
        if ($getShared) {
            return static::getSharedInstance('authContext');
        }

        return new AuthContext();
    }

    public static function jwt(bool $getShared = true): JwtService
    {
        if ($getShared) {
            return static::getSharedInstance('jwt');
        }

        return new JwtService(config(Jwt::class));
    }

    /**
     * Envoi des codes OTP : « log » (jamais en production), « channels » (canaux de notification actifs :
     * SMS, WhatsApp, e-mail) ou « none ».
     */
    public static function authCodeSender(bool $getShared = true): AuthCodeSenderInterface
    {
        if ($getShared) {
            return static::getSharedInstance('authCodeSender');
        }

        $delivery = config(Auth::class)->otpDelivery;

        if ($delivery === 'log' && ENVIRONMENT !== 'production') {
            return new LogAuthCodeSender();
        }

        if ($delivery === 'channels') {
            return new ChannelAuthCodeSender(static::notificationChannels(), config(Auth::class));
        }

        return new UnavailableAuthCodeSender();
    }

    /**
     * Moyens de paiement implémentés. Seuls ceux listés dans Config\WapiFood::$enabledPaymentMethods
     * sont utilisables. Aucun adaptateur Mobile Money tant qu'aucun agrégateur n'est choisi (D4).
     */
    public static function paymentProviders(bool $getShared = true): PaymentProviderRegistry
    {
        if ($getShared) {
            return static::getSharedInstance('paymentProviders');
        }

        return new PaymentProviderRegistry(
            [new CashPaymentProvider()],
            config(WapiFood::class)->enabledPaymentMethods,
        );
    }

    /**
     * Canaux de notification externes actifs (Config\Notifications). Aucun adaptateur SMS ni WhatsApp
     * réel n'existe tant qu'aucun fournisseur n'est choisi : seul « log » (hors production) est reconnu.
     */
    public static function notificationChannels(bool $getShared = true): NotificationChannelRegistry
    {
        if ($getShared) {
            return static::getSharedInstance('notificationChannels');
        }

        $config   = config(Notifications::class);
        $channels = [];

        if (EmailChannel::isConfigured(config(Email::class))) {
            $channels[] = new EmailChannel(config(Email::class));
        }

        foreach (['sms' => $config->smsProvider, 'whatsapp' => $config->whatsappProvider] as $channel => $provider) {
            if ($provider === 'log' && ENVIRONMENT !== 'production') {
                $channels[] = new LogChannel($channel);
            } elseif ($provider !== '' && $provider !== 'log') {
                log_message('warning', 'Fournisseur {channel} « {provider} » non implémenté : canal désactivé.', ['channel' => $channel, 'provider' => $provider]);
            }
        }

        return new NotificationChannelRegistry($channels);
    }
}
