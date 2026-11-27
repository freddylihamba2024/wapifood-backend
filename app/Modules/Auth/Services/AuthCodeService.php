<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Common\Exceptions\ApiException;
use App\Modules\Auth\Delivery\AuthCodeSenderInterface;
use App\Modules\Auth\Delivery\UnavailableAuthCodeSender;
use App\Modules\Auth\Models\AuthCodeModel;
use CodeIgniter\HTTP\IncomingRequest;
use Config\Auth as AuthConfig;
use Config\Jwt as JwtConfig;

/**
 * Codes à usage unique (OTP) : vérification de téléphone / e-mail, réinitialisation du mot de passe.
 *
 * - Code numérique aléatoire (random_int), stocké en HMAC-SHA256 avec un secret serveur.
 * - Expiration, nombre de tentatives limité, délai entre deux envois, quota horaire.
 * - Un nouveau code invalide les précédents pour la même destination et le même usage.
 * - L'envoi a lieu après l'enregistrement, hors de toute transaction métier.
 */
final class AuthCodeService
{
    public const PURPOSE_PHONE_VERIFICATION = 'phone_verification';
    public const PURPOSE_EMAIL_VERIFICATION = 'email_verification';
    public const PURPOSE_PASSWORD_RESET     = 'password_reset';

    private readonly AuthCodeSenderInterface $sender;

    public function __construct(
        private readonly AuthCodeModel $codes = new AuthCodeModel(),
        ?AuthCodeSenderInterface $sender = null,
        private readonly AuthConfig $config = new AuthConfig(),
        private readonly string $secret = '',
    ) {
        $this->sender = $sender ?? service('authCodeSender');
    }

    /**
     * Crée et envoie un code.
     *
     * @param 'email'|'sms'|'whatsapp' $channel
     *
     * @throws ApiException 429 (trop de demandes) | 503 (envoi indisponible)
     */
    public function issue(?int $userId, string $channel, string $destination, string $purpose): void
    {
        if (! $this->sender->supports($channel)) {
            throw UnavailableAuthCodeSender::unavailable();
        }

        $this->assertNotThrottled($destination, $purpose);

        $code = $this->generateCode();
        $now  = gmdate('Y-m-d H:i:s');

        // Les codes encore valides pour cet usage deviennent inutilisables.
        $this->codes->where('destination', $destination)
            ->where('purpose', $purpose)
            ->where('consumed_at', null)
            ->set(['consumed_at' => $now])
            ->update();

        $request = service('request');

        $this->codes->insert([
            'user_id'      => $userId,
            'channel'      => $channel,
            'destination'  => $destination,
            'purpose'      => $purpose,
            'code_hash'    => $this->hash($destination, $purpose, $code),
            'max_attempts' => $this->config->otpMaxAttempts,
            'expires_at'   => gmdate('Y-m-d H:i:s', time() + $this->config->otpTtl),
            'ip_address'   => $request instanceof IncomingRequest ? $request->getIPAddress() : null,
        ]);

        $this->sender->send($channel, $destination, $purpose, $code);
    }

    /**
     * Vérifie et consomme le code. Chaque échec est compté ; au-delà du maximum, le code est invalidé.
     *
     * @return array<string, mixed> Ligne wf_auth_codes consommée
     *
     * @throws ApiException 422 INVALID_CODE
     */
    public function consume(string $destination, string $purpose, string $code): array
    {
        $invalid = new ApiException('Code invalide ou expiré.', 422, ['code' => 'Code invalide ou expiré.'], 'INVALID_CODE');

        $row = $this->codes->where('destination', $destination)
            ->where('purpose', $purpose)
            ->where('consumed_at', null)
            ->where('expires_at >', gmdate('Y-m-d H:i:s'))
            ->orderBy('id', 'DESC')
            ->first();

        if ($row === null || $row['attempts'] >= $row['max_attempts']) {
            throw $invalid;
        }

        if (! hash_equals($row['code_hash'], $this->hash($destination, $purpose, trim($code)))) {
            $attempts = $row['attempts'] + 1;
            $update   = ['attempts' => $attempts];

            if ($attempts >= $row['max_attempts']) {
                $update['consumed_at'] = gmdate('Y-m-d H:i:s');
            }

            $this->codes->update($row['id'], $update);

            throw $invalid;
        }

        // Consommation atomique : un même code ne peut pas servir deux fois en parallèle.
        $consumed = $this->codes->builder()
            ->where('id', $row['id'])
            ->where('consumed_at', null)
            ->update(['consumed_at' => gmdate('Y-m-d H:i:s')]);

        if (! $consumed || db_connect()->affectedRows() !== 1) {
            throw $invalid;
        }

        return $row;
    }

    private function assertNotThrottled(string $destination, string $purpose): void
    {
        $last = $this->codes->where('destination', $destination)
            ->where('purpose', $purpose)
            ->orderBy('id', 'DESC')
            ->first();

        if ($last !== null && strtotime($last['created_at'] . ' UTC') > time() - $this->config->otpResendCooldown) {
            throw new ApiException('Veuillez patienter avant de demander un nouveau code.', 429, [], 'OTP_COOLDOWN');
        }

        $sentLastHour = $this->codes->where('destination', $destination)
            ->where('created_at >', gmdate('Y-m-d H:i:s', time() - 3600))
            ->countAllResults();

        if ($sentLastHour >= $this->config->otpMaxPerHour) {
            throw new ApiException('Trop de codes demandés. Réessayez plus tard.', 429, [], 'OTP_QUOTA_EXCEEDED');
        }
    }

    private function generateCode(): string
    {
        $length = max(4, $this->config->otpLength);

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    private function hash(string $destination, string $purpose, string $code): string
    {
        $secret = $this->secret !== '' ? $this->secret : config(JwtConfig::class)->secret;

        return hash_hmac('sha256', $purpose . '|' . $destination . '|' . $code, 'otp:' . $secret);
    }
}
