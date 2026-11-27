# WapiFood — Backend API

Backend REST de **WapiFood**, plateforme SaaS multi-restaurants (RDC) : clients, restaurants, administration.

- CodeIgniter 4.7 · PHP 8.2+ · MySQL 5.7+ / 8+ (InnoDB, utf8mb4) · JWT · OpenAPI
- Architecture : monolithe modulaire (`app/Modules/*`), API versionnée sous `/api/v1`

> État : **Phase 8 (notifications)** terminée. Les modules métier arrivent dans les phases suivantes
> (voir [docs/architecture/PHASE0_AUDIT.md](docs/architecture/PHASE0_AUDIT.md)).

## Installation (développement)

```bash
composer install
cp .env.example .env          # puis renseigner la base de données et jwt.secret
php -r "echo bin2hex(random_bytes(64)), PHP_EOL;"   # valeur pour jwt.secret
```

Bases de données :

- `wapifood` : base applicative (schéma de référence : `wapifood_database.sql`).
- `wapifood_test` : base **dédiée aux tests**, jamais la base de développement :

  ```sql
  CREATE DATABASE wapifood_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  ```

Sous MAMP, utilisez `127.0.0.1` comme hôte (TCP). Avec `localhost`, mysqli cherche `/tmp/mysql.sock`, qui n'existe pas sous MAMP.

Schéma, données de référence et premier administrateur :

```bash
php spark migrate                  # crée les 31 tables (ou contrôle une base déjà importée)
php spark db:seed DatabaseSeeder   # rôles + paramètres plateforme (rejouable)
php spark wapifood:create-admin    # super administrateur, mot de passe saisi masqué
```

Le détail du schéma et la stratégie de déploiement sont dans [docs/database/SCHEMA.md](docs/database/SCHEMA.md).

> **MAMP + MySQL 5.7 sous macOS** : au-delà de 1024 fichiers ouverts, MySQL refuse toute
> connexion (`File Descriptor … exceeded FD_SETSIZE=1024` dans `mysql_error.log`). Limitez
> le cache de tables dans `/Applications/MAMP/conf/my.cnf` (`table_open_cache = 400`,
> `table_definition_cache = 400`, `innodb_open_files = 300`), puis redémarrez MySQL.

## Lancer

```bash
php spark serve               # http://localhost:8080
curl http://localhost:8080/api/v1/health
```

Le serveur web doit pointer sur le dossier `public/`, jamais sur la racine du projet.

## Authentification

- **Jeton d'accès** JWT HS256 (15 min), à envoyer dans `Authorization: Bearer <jeton>`.
- **Refresh token** opaque (30 jours) à usage unique. `POST /api/v1/auth/refresh` en délivre un nouveau ; réutiliser un ancien jeton révoque toute la session.
- **Inscription** : le compte reste `pending` jusqu'à la vérification du téléphone par code SMS (`POST /api/v1/auth/verify-phone`).
- **Codes OTP en développement** : en l'absence de fournisseur SMS, le code est écrit dans `writable/logs` (ligne `[DEV OTP]`). Ce mode est refusé en production ; en production, utilisez `auth.otpDelivery = channels` (voir Notifications).
- **Rôles** : globaux dans `wf_user_roles` ; locaux à un restaurant dans `wf_restaurant_users`, avec la matrice de permissions de `app/Config/Permissions.php`. Ils sont relus en base à chaque requête.
- **Maintenance** : `php spark auth:purge-expired` (une fois par jour, `--dry-run` pour simuler).

## Restaurants et catalogue

- **Cycle de vie** : inscription par un propriétaire (`pending`) → validation (`active`) ou refus (`rejected`, puis nouvelle soumission) par l'administration. Le restaurant peut se mettre en pause (`paused`) ; l'administration peut le suspendre (`suspended` : invisible et en lecture seule).
- **Espace restaurant** `/api/v1/restaurant/*` : le restaurant est résolu côté serveur, avec l'en-tête `X-Restaurant-Id` si l'utilisateur en a plusieurs, et chaque route exige une permission (`app/Config/Permissions.php`).
- **Montants** : chaînes décimales (`"8.50"`), calculés en centimes entiers (`App\Common\Support\Money`).
- **Images** : JPEG, PNG ou WebP, 2 Mo maximum. Elles sont ré-encodées côté serveur, stockées dans `writable/uploads` et servies par `GET /media/...` (en-tête `nosniff`, cache long). Le serveur web ne doit jamais exposer `writable/`.

## Clients et commandes

- **Espace client** `/api/v1/customer/*` (rôle `customer`, téléphone vérifié) : adresses de livraison, favoris, commandes. Profil : `PATCH /api/v1/auth/me`.
- **Création de commande** : le serveur recalcule tout à partir du catalogue (prix, variantes, options, minimum, frais de livraison) et vérifie que le restaurant est ouvert, maintenant ou à l'heure programmée (`requested_for`). `POST /customer/orders/quote` calcule le panier sans rien enregistrer.
- **Idempotence** : l'application envoie un en-tête `Idempotency-Key` par panier ; un nouvel envoi renvoie la commande déjà créée (`200`, `Idempotent-Replayed: true`).
- **Paiement** : espèces uniquement (décision D4). Le restaurant confirme l'encaissement (`POST /restaurant/orders/{id}/cash-payment`).
- **Statuts** : `pending → accepted → preparing → ready → out_for_delivery → completed` (retrait : `ready → completed`). Le restaurant refuse (`rejected`) ou annule une commande acceptée avec un motif ; le client n'annule qu'une commande en attente. Règles : `App\Modules\Orders\Services\OrderStateMachine`.

## Paiements et commissions

- **Commission** (D5) : figée à la commande (`sous-total − remise du restaurant`, hors livraison, au taux du restaurant), due à la fin de la commande, annulée si la commande est refusée, annulée ou remboursée en totalité.
- **Journal financier** : chaque mouvement est une écriture immuable (`wf_restaurant_ledger_entries`) ; le solde dû (`commission_due`) est mis à jour dans la même transaction. L'administration enregistre les règlements et les ajustements motivés.
- **Contrôle quotidien** (cron) : `php spark finance:check-consistency` (code de sortie 1 en cas d'écart).
- **Mobile Money** : désactivé (aucun agrégateur choisi). Pour en brancher un : implémenter `App\Modules\Payments\Providers\PaymentProviderInterface`, l'enregistrer dans `Config\Services::paymentProviders()`, puis ajouter son code à `Config\WapiFood::$enabledPaymentMethods`. Les notifications arrivent sur `POST /api/v1/webhooks/payments/{fournisseur}` (404 tant que rien n'est activé).

## Livraison, codes promo et avis

- **Livraison** : assurée par le restaurant. Chaque commande en livraison a un suivi (`wf_deliveries`) qui suit les statuts de la commande. Le livreur (nom, téléphone) est facultatif : `PUT /restaurant/orders/{id}/delivery`, ou au départ avec `out_for_delivery`. Une livraison impossible annule la commande, avec un motif : `POST …/delivery/failure`. La photo de preuve est privée : `POST`/`GET …/delivery/proof`, jamais servie par `/media`.
- **Codes promo** : codes du restaurant (`/restaurant/promotions`, remise à sa charge) et codes plateforme (`/admin/promotions`, remise à la charge de WapiFood). Le client envoie `promo_code` au devis et à la commande. La remise porte sur le sous-total, hors livraison. Les limites d'utilisation sont vérifiées sous verrou. Une commande refusée ou annulée rend son utilisation.
- **Code plateforme et commission** : la commission est calculée avant remise. À la fin de la commande, le restaurant est crédité de la remise (écriture `platform_discount`). Son solde dû peut alors devenir négatif : c'est un avoir.
- **Avis** : `POST /customer/orders/{id}/review`, uniquement pour une commande terminée, dans les 30 jours (`wapifood.reviewWindowDays`), un seul par commande. L'avis est publié aussitôt ; l'administration peut le masquer (`PATCH /admin/reviews/{id}/moderation`). La note moyenne (`rating`) figure sur les fiches restaurant, et la liste publique est sur `GET /restaurants/{id}/reviews`.

## Notifications

- **Événements** : nouvelle commande et annulation par le client (restaurant), changements de statut, échec de livraison et remboursement (client), nouvel avis (restaurant), validation, refus et suspension (propriétaire). La correspondance événement → canaux est dans `app/Config/Notifications.php`.
- **In-app** : `GET /notifications` (compte connecté) et `GET /restaurant/notifications` (boîte partagée par l'équipe), lecture une par une ou en bloc (`read-all`), compteur `meta.unread_count`.
- **E-mail, SMS, WhatsApp** : la transaction métier ne fait qu'enregistrer les messages en file (`queued`), avec le destinataire figé. L'envoi est fait par `php spark notifications:process`, hors transaction. En cas d'échec, il retente après 1, 5, 15 puis 60 min, et passe en `failed` après 5 tentatives. Chaque tentative est historisée.
- **Canaux** : un canal non configuré est ignoré sans rien bloquer. E-mail : SMTP de `.env`. SMS et WhatsApp : aucun fournisseur choisi, seul `log` existe (développement). Pour brancher un fournisseur : implémenter `App\Modules\Notifications\Channels\NotificationChannelInterface` et l'enregistrer dans `Config\Services::notificationChannels()`.
- **Codes OTP en production** : `auth.otpDelivery = channels`. L'envoi est immédiat par ces mêmes canaux, sans passer par la file.
- **Supervision** : `GET /admin/notifications?status=failed`, `POST /admin/notifications/{id}/retry`, ou `php spark notifications:retry --hours 24`.

Tâches planifiées (cron) :

```cron
* * * * *  php /chemin/wapifood/spark notifications:process
30 3 * * * php /chemin/wapifood/spark auth:purge-expired
0 4 * * *  php /chemin/wapifood/spark finance:check-consistency
```

## Documentation de l'API

- Swagger UI : `GET /docs`
- Spécification : `GET /docs/openapi.yaml` (source : [docs/api/openapi.yaml](docs/api/openapi.yaml))

Elle est désactivée automatiquement en production (`404`), ou via `wapifood.docsEnabled = false`.

## Tests

```bash
vendor/bin/phpunit --no-coverage
```

`tests/unit` (logique isolée) · `tests/integration` (services et base) · `tests/feature` (HTTP de bout en bout).

## Conventions de l'API

Toutes les réponses JSON suivent la même enveloppe :

```json
{ "success": true,  "message": "…", "data": {},   "meta": null }
{ "success": false, "message": "…", "errors": {}, "meta": { "code": "VALIDATION_FAILED" } }
```

- Les erreurs sur `api/*` (route inconnue, exception, erreur fatale) sont toujours renvoyées en JSON.
- En production : aucun détail technique. En développement : `meta.debug`.
- Limitation des requêtes : 120 requêtes / 60 s / IP par défaut (`429` + `Retry-After`).
- CORS : origines listées dans `CORS_ALLOWED_ORIGINS`, sans joker ni cookies.

## Sécurité

- Les secrets sont uniquement dans `.env` (ignoré par Git), jamais dans la base ni dans le dépôt.
- En production : `CI_ENVIRONMENT = production`, HTTPS (`app.forceGlobalSecureRequests = true`), `expose_php = Off`.
