<?php

declare(strict_types=1);

namespace App\Modules\Auth\Delivery;

/**
 * Transmission d'un code à usage unique à son destinataire.
 *
 * Appelée APRÈS la validation de la transaction SQL. Une implémentation ne doit jamais
 * journaliser le code, sauf LogAuthCodeSender (développement uniquement).
 */
interface AuthCodeSenderInterface
{
    /**
     * @param 'email'|'sms'|'whatsapp' $channel
     *
     * @throws \App\Common\Exceptions\ApiException 503 si le canal est indisponible
     */
    public function send(string $channel, string $destination, string $purpose, string $code): void;

    /** Indique si un envoi est possible sur ce canal (contrôle avant de créer le code). */
    public function supports(string $channel): bool;
}
