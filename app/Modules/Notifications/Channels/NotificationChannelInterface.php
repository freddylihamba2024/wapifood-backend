<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels;

/**
 * Canal d'envoi externe (e-mail, SMS, WhatsApp). L'in-app n'en est pas un : il est enregistré directement.
 *
 * Un canal est appelé HORS transaction, par la file (`notifications:process`) ou pour un code OTP.
 */
interface NotificationChannelInterface
{
    /** in_app | email | sms | whatsapp */
    public function channel(): string;

    /** Nom du fournisseur, enregistré dans l'historique des tentatives (ex. smtp, log). */
    public function provider(): string;

    /**
     * @param array<string, mixed> $data
     *
     * @return string|null Identifiant du message chez le fournisseur
     *
     * @throws ChannelException Échec ; temporaire (nouvelle tentative) ou définitif
     */
    public function send(string $recipient, string $title, string $message, array $data = []): ?string;
}
