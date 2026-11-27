<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels;

use Config\Email as EmailConfig;

/**
 * E-mail via la bibliothèque Email de CodeIgniter (SMTP configuré dans .env : `email.*`).
 */
final class EmailChannel implements NotificationChannelInterface
{
    public function __construct(private readonly EmailConfig $config = new EmailConfig())
    {
    }

    /** Actif seulement si l'expéditeur et (en SMTP) le serveur sont renseignés. */
    public static function isConfigured(EmailConfig $config): bool
    {
        return trim($config->fromEmail) !== '' && ($config->protocol !== 'smtp' || trim($config->SMTPHost) !== '');
    }

    public function channel(): string
    {
        return 'email';
    }

    public function provider(): string
    {
        return $this->config->protocol;
    }

    public function send(string $recipient, string $title, string $message, array $data = []): ?string
    {
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw ChannelException::permanent('Adresse e-mail invalide.');
        }

        $email = service('email', $this->config, false);
        $email->setFrom($this->config->fromEmail, $this->config->fromName === '' ? 'WapiFood' : $this->config->fromName);
        $email->setTo($recipient);
        $email->setSubject($title);
        $email->setMessage($message);

        if (! $email->send(false)) {
            // Erreur SMTP (serveur injoignable, refus temporaire…) : nouvelle tentative plus tard.
            throw new ChannelException('Envoi de l\'e-mail impossible : ' . mb_substr(strip_tags((string) $email->printDebugger(['headers'])), 0, 500));
        }

        return null;
    }
}
