<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Règles d'authentification WapiFood (surcharges `.env` : auth.<propriété>).
 */
class Auth extends BaseConfig
{
    /** Échecs de connexion consécutifs avant verrouillage temporaire du compte. */
    public int $maxFailedLogins = 5;

    /** Durée du verrouillage, en minutes. */
    public int $lockoutMinutes = 15;

    /** Longueur des codes OTP (chiffres). */
    public int $otpLength = 6;

    /** Validité d'un code OTP, en secondes. */
    public int $otpTtl = 600;

    /** Tentatives de saisie autorisées par code. */
    public int $otpMaxAttempts = 5;

    /** Délai minimal entre deux envois de code vers la même destination, en secondes. */
    public int $otpResendCooldown = 60;

    /** Nombre maximal de codes envoyés par destination et par heure. */
    public int $otpMaxPerHour = 5;

    /**
     * Mode d'envoi des codes OTP tant que les fournisseurs SMS / e-mail (Phase 8) ne sont pas branchés :
     * - 'log'  : le code est écrit dans writable/logs (développement et tests uniquement, refusé en production) ;
     * - 'channels' : envoi immédiat par les canaux de notification actifs (SMS, WhatsApp, e-mail ;
     *   voir Config\Notifications) — à utiliser en production une fois un fournisseur branché ;
     * - 'none' : aucun envoi possible → l'API répond 503 sur les actions nécessitant un code.
     */
    public string $otpDelivery = 'log';
}
