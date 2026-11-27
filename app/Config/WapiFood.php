<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Paramètres métier et techniques propres à WapiFood.
 *
 * Chaque propriété peut être surchargée dans `.env` via `wapifood.<propriété>`.
 */
class WapiFood extends BaseConfig
{
    /** Version publique de l'API, exposée par /api/v1/health. */
    public string $apiVersion = '1.0.0';

    /** Devise par défaut de la plateforme (USD ou CDF). */
    public string $defaultCurrency = 'USD';

    /** Devises acceptées par la plateforme. Aucune conversion implicite entre elles. */
    public array $supportedCurrencies = ['USD', 'CDF'];

    /** Taux de commission par défaut, en pourcentage (chaîne décimale, jamais un float). */
    public string $defaultCommissionRate = '10.00';

    /**
     * Moyens de paiement acceptés à la commande (décision D4 : espèces uniquement au MVP).
     * Les moyens Mobile Money (mpesa, airtel_money, orange_money) seront activés ici une fois
     * le modèle validé juridiquement et les adaptateurs livrés (Phase 6).
     *
     * @var list<string>
     */
    public array $enabledPaymentMethods = ['cash'];

    /** Commande programmée : délai maximal, en jours, entre la commande et l'heure souhaitée. */
    public int $orderScheduleMaxDays = 7;

    /** Délai, en jours après la fin de la commande, pendant lequel le client peut donner son avis. */
    public int $reviewWindowDays = 30;

    /**
     * Swagger UI et spécification OpenAPI accessibles sur /docs.
     * Forcé à false en production, quelle que soit la valeur de `.env`.
     */
    public bool $docsEnabled = true;

    /**
     * Active la limitation des requêtes. Désactivée uniquement dans les tests HTTP automatisés,
     * où toutes les requêtes proviennent de la même adresse IP.
     */
    public bool $rateLimitEnabled = true;

    /** Limite globale de l'API : nombre de requêtes autorisées par fenêtre et par IP. */
    public int $rateLimitRequests = 120;

    /** Durée de la fenêtre de limitation, en secondes. */
    public int $rateLimitWindow = 60;

    public function __construct()
    {
        parent::__construct();

        if (ENVIRONMENT === 'production') {
            $this->docsEnabled = false;
        }
    }
}
