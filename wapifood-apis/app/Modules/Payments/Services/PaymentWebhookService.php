<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Common\Exceptions\ApiException;
use App\Common\Support\Money;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Payments\Providers\PaymentWebhookEvent;
use App\Modules\Payments\Providers\WebhookRejected;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;
use Throwable;

/**
 * Notifications de paiement des fournisseurs (Mobile Money, plus tard).
 *
 * Aucun fournisseur à notifications n'est actif au MVP : la route répond 404 (décision D4).
 *
 * Traitement :
 *   1. fournisseur activé et à notifications, sinon 404 ;
 *   2. signature vérifiée par l'adaptateur ; échec → trace « rejected », 401 ;
 *   3. enregistrement idempotent : (fournisseur, event_id) unique, event_id = identifiant du fournisseur
 *      ou SHA-256 du corps brut (un event_id NULL contournerait l'index unique) ; doublon → 200 sans retraitement ;
 *   4. transaction, paiement verrouillé par sa référence marchande : contrôle du montant et de la devise,
 *      puis paiement « successful » (commande « paid ») ou « failed ».
 * Un écart (référence inconnue, montant ou devise différents) est tracé sans toucher au paiement,
 * et acquitté (200) : un nouvel envoi du fournisseur ne le corrigerait pas.
 */
final class PaymentWebhookService
{
    private BaseConnection $db;

    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger(),
        ?BaseConnection $db = null,
    ) {
        $this->db = $db ?? db_connect();
    }

    /**
     * @param array<string, string> $headers Noms en minuscules
     *
     * @return array{status: string} processed | duplicate | ignored
     */
    public function handle(string $providerCode, string $rawBody, array $headers): array
    {
        $provider = service('paymentProviders')->get($providerCode);

        if ($provider === null || ! $provider->supportsWebhooks()) {
            throw ApiException::notFound();
        }

        try {
            $event = $provider->parseWebhook($rawBody, $headers);
        } catch (WebhookRejected $exception) {
            $this->store($providerCode, hash('sha256', $rawBody), null, $rawBody, false, 'rejected', $exception->getMessage());

            throw ApiException::unauthorized('Notification refusée.', 'WEBHOOK_SIGNATURE_INVALID');
        }

        $eventId   = $event->eventId !== null && $event->eventId !== '' ? mb_substr($event->eventId, 0, 190) : hash('sha256', $rawBody);
        $webhookId = $this->store($providerCode, $eventId, $event, $rawBody, true, 'verified', null);

        if ($webhookId === null) {
            return ['status' => 'duplicate'];
        }

        try {
            $outcome = $this->apply($providerCode, $event);
            $this->finish($webhookId, $outcome['error'] === null ? 'processed' : 'error', $outcome['error']);

            if ($outcome['error'] !== null) {
                $this->audit->log('payment.webhook_mismatch', null, 'payment_webhook', $webhookId, [
                    'provider' => $providerCode,
                    'reference' => $event->merchantReference,
                    'error'    => $outcome['error'],
                ]);
            }

            return ['status' => $outcome['error'] === null ? 'processed' : 'ignored'];
        } catch (Throwable $exception) {
            $this->finish($webhookId, 'error', 'Erreur interne : ' . $exception::class);

            throw $exception;
        }
    }

    /**
     * @return array{error: string|null}
     */
    private function apply(string $providerCode, PaymentWebhookEvent $event): array
    {
        $this->db->transBegin();

        try {
            $payment = $this->db->query(
                'SELECT * FROM wf_payments WHERE provider = ? AND merchant_reference = ? FOR UPDATE',
                [$providerCode, $event->merchantReference],
            )->getRowArray();

            $error = match (true) {
                $payment === null                                                     => 'Référence marchande inconnue.',
                ! Money::isValid($event->amount)                                     => 'Montant illisible.',
                Money::toCents($event->amount) !== Money::toCents($payment['amount']) => 'Montant différent du paiement attendu.',
                $event->currency !== $payment['currency']                            => 'Devise différente du paiement attendu.',
                default                                                               => null,
            };

            if ($error === null && in_array($payment['status'], ['initiated', 'pending'], true)) {
                $success = $event->status === 'successful';

                $this->db->table('wf_payments')->where('id', $payment['id'])->update(array_filter([
                    'status'                  => $success ? 'successful' : 'failed',
                    'paid_at'                 => $success ? Time::now('UTC')->toDateTimeString() : null,
                    'provider_transaction_id' => $event->providerTransactionId,
                ], static fn ($value): bool => $value !== null));

                $this->db->table('wf_orders')->where('id', $payment['order_id'])->whereIn('payment_status', ['unpaid', 'pending', 'failed'])
                    ->update(['payment_status' => $success ? 'paid' : 'failed']);
            }

            // Paiement déjà finalisé : notification tardive ou répétée sous un autre identifiant, sans effet.
            $this->db->transCommit();

            return ['error' => $error];
        } catch (Throwable $exception) {
            $this->db->transRollback();

            throw $exception;
        }
    }

    /**
     * Enregistre la notification ; null si elle a déjà été reçue (même fournisseur et même event_id).
     */
    private function store(string $provider, string $eventId, ?PaymentWebhookEvent $event, string $rawBody, bool $signatureValid, string $status, ?string $error): ?int
    {
        $decoded = json_decode($rawBody, true);

        $this->db->table('wf_payment_webhooks')->ignore(true)->insert([
            'provider'                => $provider,
            'event_id'                => $eventId,
            'merchant_reference'      => $event?->merchantReference,
            'provider_transaction_id' => $event?->providerTransactionId,
            'signature_valid'         => $signatureValid ? 1 : 0,
            'processing_status'       => $status,
            // Corps conservé pour l'audit ; les adaptateurs ne doivent jamais y laisser de secret.
            'payload'                 => json_encode(is_array($decoded) ? $decoded : ['raw' => mb_substr($rawBody, 0, 10_000)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'processing_error'        => $error,
        ]);

        return $this->db->affectedRows() === 1 ? (int) $this->db->insertID() : null;
    }

    private function finish(int $webhookId, string $status, ?string $error): void
    {
        $this->db->table('wf_payment_webhooks')->where('id', $webhookId)->update([
            'processing_status' => $status,
            'processing_error'  => $error,
            'processed_at'      => Time::now('UTC')->toDateTimeString(),
        ]);
    }
}
