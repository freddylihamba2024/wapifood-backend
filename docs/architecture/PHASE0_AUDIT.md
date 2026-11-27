# WapiFood Backend — Phase 0 : Rapport d'audit

Date : 2026-11-25 — Aucun fichier applicatif ni aucune donnée n'ont été modifiés pendant cet audit.

---

## 1. État actuel du projet

### 1.1 Environnement

| Élément | Constat | Impact |
|---|---|---|
| PHP CLI | 8.2.31 (Homebrew) — extensions `intl`, `mbstring`, `mysqli`, `pdo_mysql`, `openssl`, `sodium`, `curl`, `gd`, `fileinfo` présentes | Conforme |
| PHP Apache (MAMP) | 8.2.0 disponible | Conforme, mais docroot à pointer sur `public/` |
| CodeIgniter | **4.7.6** | Conforme |
| Composer | 2.2.24 | Conforme (ancien mais fonctionnel) |
| MySQL (MAMP) | **5.7.39** | ⚠️ **Non conforme à l'exigence MySQL 8+** (voir 1.3) |
| Docker | 29.6.2 disponible | Permet un MySQL 8 de développement |
| Git | Installé, mais **le projet n'est pas un dépôt Git** et il n'existe **aucun `.gitignore`** | ⚠️ Un futur `.env` ne serait pas protégé |
| `.env` | Absent (seul le modèle `env` existe) | À créer |
| Tests | PHPUnit 11.5.58 — `5 tests, 6 assertions, OK` | Socle fonctionnel |

### 1.2 Structure du projet

- Le projet est la **distribution « framework »** de CI4 (`composer.json` → `codeigniter4/framework`, dossier `system/` à la racine), et non l'« appstarter ».
  - Conséquence : CI4 ne peut pas être mis à jour par Composer ; les dépendances applicatives (JWT…) seraient mélangées à celles du framework.
- `app/` est vierge : `Home` controller, route `/` uniquement, aucun filtre, aucune migration, aucun seed.
- Le namespace `App\` → `app/` est déjà déclaré (`app/Config/Autoload.php`). Un dossier `app/Modules/*` sera donc autoloadé sous `App\Modules\*` sans configuration supplémentaire.

### 1.3 Base de données

- La base `wapifood` **existe déjà** sur le MySQL MAMP, créée par `wapifood_database.sql` :
  - 31 tables présentes, **44 clés étrangères** (= 44 dans le script), moteur InnoDB, utf8mb4.
  - Données : uniquement les 5 rôles et les 4 paramètres plateforme. `wf_users` vide.
  - Pas de table `migrations` (la base n'a jamais été gérée par Spark).
- **MySQL 5.7 ignore silencieusement les contraintes `CHECK`** (montants ≥ 0, devises USD/CDF, note 1–5, jour 1–7, taux 0–100). Elles ne protègent donc rien actuellement → **toutes ces règles devront être appliquées dans le code** (ce qui est de toute façon prévu), et seront effectives en base sous MySQL 8.
- MySQL 5.7 ne supporte pas les CTE ni les fonctions de fenêtrage → les requêtes de rapports seront écrites sans elles (compatibles 5.7 et 8).
- `sql_mode` strict activé (bon point). `character_set_server = utf8` (utf8mb3) → forcer `utf8mb4` dans la config de connexion.

---

## 2. Analyse des 31 tables

Le schéma est **globalement solide** : clés étrangères nommées, index pertinents, montants en `DECIMAL`, snapshots sur les lignes de commande, unicité des webhooks par `(provider, event_id)`, une commission par commande. Les manques identifiés sont listés ci-dessous par domaine.

### 2.1 Authentification JWT / refresh tokens / OTP — **manquant**

| Besoin | Constat | Proposition (additive) |
|---|---|---|
| Refresh tokens hashés, révocables, rotation + détection de réutilisation | Aucune table | **`wf_refresh_tokens`** : `user_id`, `token_hash` (SHA-256, unique), `family_id`, `replaced_by_id`, `expires_at`, `revoked_at`, `revoked_reason`, `ip_address`, `user_agent`, `created_at` |
| OTP téléphone + réinitialisation mot de passe | Aucune table | **`wf_auth_codes`** : `user_id` (nullable), `destination` (téléphone/e-mail), `purpose` (`phone_verification`, `password_reset`, `email_verification`), `code_hash`, `attempts`, `max_attempts`, `expires_at`, `consumed_at`, `created_at` — une seule table couvre OTP et reset |
| Invalider les access tokens après changement de mot de passe / suspension | Aucun mécanisme | Colonnes `wf_users.token_version INT` et `wf_users.password_changed_at DATETIME` |
| Verrouillage après échecs de connexion | Aucun | Colonnes `wf_users.failed_login_attempts`, `wf_users.locked_until` (complète le rate-limit par IP) |

### 2.2 Utilisateurs et RBAC

- `wf_roles` / `wf_user_roles` (rôles globaux) + `wf_restaurant_users.restaurant_role` (`owner`, `manager`, `staff`, `cashier`) suffisent.
- **Proposition : matrice de permissions en configuration PHP** (`app/Config/Permissions.php`) plutôt que de nouvelles tables `permissions` — moins de complexité pour le MVP, versionnée avec le code.
- Point de vigilance : `wf_restaurants.owner_user_id` et la ligne `owner` dans `wf_restaurant_users` sont redondants → maintenus synchronisés dans une transaction par le service.
- Suppression logique d'un utilisateur : `phone` et `email` restent uniques → un numéro supprimé ne peut pas se réinscrire. Traitement proposé : anonymiser (`phone` → `deleted:<id>`) lors de la suppression. Pas de changement de schéma.

### 2.3 Restaurants

| Besoin | Constat | Proposition |
|---|---|---|
| Statuts attente / actif / suspendu / fermé temporairement | `pending`, `active`, `paused` (= fermé temporairement), `rejected`, `suspended` | Conforme |
| Motif de rejet/suspension, traçabilité de la validation | Absent | Colonnes `status_reason VARCHAR(500)`, `approved_at`, `approved_by` (+ entrée `wf_audit_logs`) |
| **Frais de livraison « selon les règles configurées »** | **Aucune colonne de frais de livraison** | Colonnes `delivery_fee DECIMAL(12,2)`, `free_delivery_threshold DECIMAL(12,2) NULL` (voir décision D6) |
| Fuseau horaire des horaires d'ouverture | Absent (Kinshasa UTC+1, Lubumbashi UTC+2) | Colonne `timezone VARCHAR(40) DEFAULT 'Africa/Kinshasa'` |
| Horaires | 1 plage par jour (`UNIQUE restaurant_id, day_of_week`) | Pas de service coupé midi/soir au MVP ; les plages passant minuit (`closes_at < opens_at`) seront gérées dans le code |

### 2.4 Catalogue

- Pas de `deleted_at` sur `wf_menu_categories` ; la FK `wf_products.category_id` bloque la suppression d'une catégorie non vide → règle : désactivation (`is_active = 0`), suppression physique uniquement si vide. Pas de changement de schéma.
- `wf_products.currency` peut différer de `wf_restaurants.currency` → règle métier : un produit doit avoir la devise de son restaurant (une commande = une devise).
- Slug produit unique par restaurant même après suppression logique → suffixe automatique côté service.
- Variantes / groupes d'options sans `sort_order` ni `is_active` (groupes) : acceptable pour le MVP, ajout éventuel plus tard.
- Images, variantes et options n'ont pas de `restaurant_id` direct → l'isolation passe par une jointure sur `wf_products` (systématique dans les repositories).

### 2.5 Clients

- `wf_customer_addresses` : la FK `wf_orders.delivery_address_id` empêche la suppression d'une adresse déjà utilisée. → Colonnes **`deleted_at`** et `updated_at` (suppression logique ; le snapshot d'adresse reste dans la commande).

### 2.6 Commandes

- Schéma conforme (snapshots, statuts, `payment_status`, historique).
- **Double soumission** (réseaux mobiles instables en RDC → double tap / renvoi) : aucune protection. → Colonne **`wf_orders.idempotency_key VARCHAR(64) NULL`** + `UNIQUE (customer_id, idempotency_key)`.
- `tax_amount` restera à 0 tant que les règles fiscales ne sont pas validées (note 10 du script).
- Transitions de statut : machine à états dans le code (pending → accepted|rejected|cancelled ; accepted → preparing|cancelled ; preparing → ready ; ready → out_for_delivery (delivery) | completed (pickup) ; out_for_delivery → completed). Aucun changement de schéma.

### 2.7 Paiements

- `uq_wf_webhook_provider_event (provider, event_id)` : si `event_id` est NULL, **MySQL autorise les doublons** → l'idempotence serait contournée. Règle : `event_id` = identifiant du fournisseur, sinon SHA-256 du corps brut. Pas de changement de schéma.
- `raw_response` / `payload` en JSON : à filtrer pour ne jamais y stocker de secrets.
- Remboursements partiels : `wf_orders.payment_status` prévoit `partially_refunded`, mais aucune table de remboursements. → Table **`wf_payment_refunds`** à créer en Phase 6 seulement (montant, statut, référence fournisseur).
- **Conflit de modèle à arbitrer (décision D4)** : voir section 4.

### 2.8 Commissions et traçabilité financière

- `wf_commissions` : conforme (taux figé, montant, part restaurant, statut `pending/earned/reversed`).
- **`wf_restaurant_balances` est conçu pour un modèle où WapiFood détient les fonds** (`available_balance`, `lifetime_paid`). Or au lancement, le restaurant encaisse directement et **doit** la commission à WapiFood. Le sens du solde est inversé et non représenté.
- Aucun journal financier immuable : un solde mis à jour par `UPDATE` n'est pas auditable seul.
- Propositions :
  - **`wf_restaurant_ledger_entries`** (écritures immuables, jamais modifiées) : `restaurant_id`, `currency`, `entry_type` (`commission_accrued`, `commission_reversed`, `commission_settlement`, `payout`, `adjustment`), `amount` (signé), `order_id`, `commission_id`, `payout_id`, `reference`, `created_by`, `note`, `created_at`.
  - Colonne **`wf_restaurant_balances.commission_due DECIMAL(14,2)`** (montant dû par le restaurant à WapiFood), recalculable depuis le journal. Une commande Spark de contrôle de cohérence comparera soldes et journal.
  - L'encaissement des commissions dues (le restaurant paie WapiFood) = écriture `commission_settlement` enregistrée par l'admin.

### 2.9 Livraisons, promotions, avis

- `wf_deliveries` : conforme et extensible (`courier_id` nullable, `managed_by`). Ajout optionnel `courier_name VARCHAR(150)` (le livreur du restaurant n'a pas de compte).
- `wf_promotions` : pas de compteur d'utilisation → calcul depuis `wf_promotion_usages` avec verrou `SELECT … FOR UPDATE` sur la promotion (pas de course possible). Qui finance une promo plateforme ? → décision D5.
- `wf_reviews` : `UNIQUE (order_id)` garantit un avis par commande. Conforme. Statut par défaut `published` = modération a posteriori.

### 2.10 Notifications — **incomplet pour la reprise sur échec**

`wf_notifications` n'a ni compteur de tentatives, ni erreur, ni date de nouvelle tentative, ni destinataire figé, ni verrou de traitement.

Proposition (additive) :
- Colonnes : `recipient VARCHAR(190)`, `attempts TINYINT`, `max_attempts TINYINT DEFAULT 5`, `next_attempt_at DATETIME`, `locked_until DATETIME`, `last_error TEXT` + index `(status, next_attempt_at)`.
- Table **`wf_notification_attempts`** : `notification_id`, `attempt_number`, `provider`, `status`, `error_message`, `provider_message_id`, `created_at` (historique demandé en Phase 8).

### 2.11 Sécurité multi-restaurants

Le schéma le permet correctement : chaque table « racine » porte `restaurant_id` ; les tables filles s'isolent par jointure. L'isolation sera garantie par :
1. un `RestaurantContext` résolu à partir du JWT + `wf_restaurant_users` (jamais à partir d'un ID envoyé seul par le client) ;
2. des repositories qui imposent `restaurant_id` dans chaque requête ;
3. des réponses **404** (et non 403) sur les ressources d'un autre restaurant, pour ne pas révéler leur existence ;
4. des tests d'accès croisés dédiés.

---

## 3. Récapitulatif des modifications de base proposées (toutes additives)

Aucune table ni colonne existante n'est supprimée ou renommée. Aucune donnée n'est touchée.

| # | Type | Objet | Phase |
|---|---|---|---|
| M1 | Migration « baseline » | Les 31 tables existantes, SQL identique au script (`CREATE TABLE IF NOT EXISTS`) | 2 |
| M2 | Nouvelle table | `wf_refresh_tokens` | 3 |
| M3 | Nouvelle table | `wf_auth_codes` (OTP + reset) | 3 |
| M4 | Colonnes | `wf_users` : `token_version`, `password_changed_at`, `failed_login_attempts`, `locked_until` | 3 |
| M5 | Colonnes | `wf_restaurants` : `status_reason`, `approved_at`, `approved_by`, `delivery_fee`, `free_delivery_threshold`, `timezone` | 4 |
| M6 | Colonnes | `wf_customer_addresses` : `updated_at`, `deleted_at` | 5 |
| M7 | Colonne + index | `wf_orders.idempotency_key` + `UNIQUE (customer_id, idempotency_key)` | 5 |
| M8 | Nouvelle table + colonne | `wf_restaurant_ledger_entries` ; `wf_restaurant_balances.commission_due` | 6 |
| M9 | Nouvelle table | `wf_payment_refunds` | 6 |
| M10 | Colonne | `wf_deliveries.courier_name` | 7 |
| M11 | Colonnes + nouvelle table | `wf_notifications` (tentatives/erreurs/verrou) ; `wf_notification_attempts` | 8 |

### Stratégie de déploiement (script SQL + migrations)

- La migration baseline M1 exécute le SQL du script (sans `CREATE DATABASE` / `USE`) en `CREATE TABLE IF NOT EXISTS` :
  - **base vide** → elle crée les 31 tables ;
  - **base existante** (votre cas actuel) → elle ne crée rien et s'enregistre simplement dans la table `migrations`.
- Le SQL brut est utilisé plutôt que le Forge de CI4, car le Forge ne reproduit pas fidèlement les `ENUM`, les `CHECK` et les noms de contraintes.
- Les migrations additives vérifient l'existence des colonnes avant `ALTER` (rejouables sans erreur).
- Les seeds (rôles, paramètres) utilisent `INSERT IGNORE` → aucun doublon avec les données déjà importées.
- À partir de la Phase 2, **Spark devient la seule source de vérité** ; `wapifood_database.sql` est conservé comme référence et n'est plus exécuté en production.
- Premier super administrateur : commande `php spark wapifood:create-admin` interactive (mot de passe saisi masqué, jamais en dur).

---

## 4. Décisions attendues

| # | Sujet | Options | Recommandation |
|---|---|---|---|
| **D1** | Version MySQL | (a) MySQL 8 via Docker pour dev et tests ; (b) rester sur MAMP 5.7 | **(a)** pour coller à la cible de production ; le code restera de toute façon compatible 5.7 (sans CTE ni fonctions de fenêtrage) |
| **D2** | Structure du projet | (a) passer à la structure appstarter : `composer require codeigniter4/framework:^4.7`, `Paths::$systemDirectory` pointé sur `vendor/`, le dossier `system/` racine conservé puis supprimé après votre accord ; (b) garder la distribution actuelle | **(a)** : mises à jour CI4 via Composer et dépendances séparées |
| **D3** | Modifications de schéma | Valider la liste M1–M11 (ou en retirer) | Toutes, livrées au fil des phases |
| **D4** | **Flux des paiements Mobile Money** | Les clés Mobile Money sont dans `.env` → un seul compte marchand = **celui de WapiFood**. Les fonds électroniques arriveraient donc chez WapiFood, ce qui contredit « le restaurant reçoit directement ». Options : (a) au MVP, cash uniquement opérationnel, Mobile Money préparé (contrat + adaptateurs) mais désactivé tant que le modèle n'est pas validé juridiquement ; (b) compte marchand WapiFood + reversements (`wf_restaurant_payouts`) ; (c) comptes marchands par restaurant (exige un coffre de secrets, hors `.env`) | **(a)**, en gardant l'architecture compatible avec (b) |
| **D5** | Assiette de la commission | (a) `sous-total − remise`, hors frais de livraison ; (b) total payé, livraison incluse. Et pour une promo plateforme : la commission est-elle calculée avant remise (WapiFood absorbe la remise) ? | **(a)** ; promo plateforme : commission calculée avant remise, la remise étant à la charge de WapiFood. À confirmer |
| **D6** | Frais de livraison | (a) forfait par restaurant + seuil de gratuité ; (b) zones tarifaires par commune (nouvelle table) | **(a)** au MVP, (b) ultérieurement |
| **D7** | Git | Initialiser un dépôt Git + `.gitignore` (`.env`, `vendor/`, `writable/*`, uploads) | **Oui**, avant la Phase 1 |

---

## 5. Architecture cible

Monolithe modulaire, conforme aux conventions CI4 (namespace `App\`).

```text
app/
├── Config/            Routes.php (groupes public/auth/restaurant/admin/webhook), Filters.php, Cors.php,
│                      + Jwt.php, Permissions.php, Uploads.php, Payments.php, Notifications.php, WapiFood.php
├── Controllers/API/V1/{Auth,Customer,Restaurant,Admin,Webhook,Public,System}/   ← minces, sans logique métier
├── Modules/{Auth,Users,Restaurants,Catalog,Customers,Orders,Payments,Commissions,
│           Deliveries,Promotions,Reviews,Notifications,Reports}/
│     ├── Models/        Modèles CI4 (allowedFields stricts, soft delete si la table le permet)
│     ├── Entities/
│     ├── Services/      Logique métier + transactions
│     ├── Repositories/  Uniquement là où les requêtes sont complexes (Orders, Reports, Catalog)
│     ├── DTO/           Entrées de commande, paiement… (pas de DTO pour des CRUD triviaux)
│     ├── Validators/    Règles de validation CI4 + règles métier
│     └── Exceptions/
├── Common/            ApiResponse (enveloppe JSON), Money (calculs en unités mineures entières, jamais en float),
│                      exceptions de domaine → codes HTTP, Pagination, RestaurantContext
├── Filters/           JwtAuthFilter, RoleFilter, RestaurantAccessFilter, RateLimitFilter (Throttler CI4 sur cache fichier)
│                      ; le CORS natif de CI4 4.7 est réutilisé (pas de filtre maison redondant)
├── Libraries/{Jwt,Upload}/
├── Commands/          wapifood:create-admin, notifications:process, notifications:retry, auth:purge-expired,
│                      orders:check-stuck, finance:check-consistency, reports:generate
└── Database/{Migrations,Seeds}/
public/docs/           Swagger UI (dev uniquement, désactivable par config)
docs/{api/openapi.yaml, architecture/, database/}
writable/uploads/      Fichiers uploadés hors du docroot, servis par un contrôleur avec en-têtes sûrs
tests/{unit,integration,feature}/  + base dédiée `wapifood_test` (jamais la base de dev)
```

Choix notables :
- **Montants** : objet `Money` sur entiers (centimes) → aucun float dans les calculs, aucune dépendance à `bcmath` (absent ici).
- **Notifications** : la transaction métier n'enregistre que des lignes `queued` ; l'envoi est fait après commit par `php spark notifications:process` (cron), avec reprise et backoff. Le canal in-app est immédiat ; les canaux non configurés sont ignorés sans bloquer les autres.
- **Webhooks** : corps brut conservé → vérification de signature (HMAC ou mécanisme du fournisseur) → insertion idempotente → traitement transactionnel (verrou sur le paiement) → contrôle du montant, de la devise et de la référence.
- **OpenAPI** : rédigé à la main (`docs/api/openapi.yaml`) en même temps que chaque route, plutôt que généré par annotations (moins de dépendances, contrat relu en revue).

---

## 6. Dépendances

| Paquet | Usage | Statut |
|---|---|---|
| `codeigniter4/framework` ^4.7 | Framework via Composer (si D2 = a) | À ajouter |
| `firebase/php-jwt` (dernière stable, compatibilité PHP 8.2 vérifiée à l'installation) | Signature/vérification JWT HS256 | À ajouter |
| `swagger-ui-dist` (assets statiques, version figée) | Interface `/docs` en dev | À ajouter (assets publiés, sans Node en production) |
| `phpunit/phpunit` 11, `fakerphp/faker` | Tests | Déjà présents |
| Email CI4, CURLRequest CI4, Throttler CI4, Cache fichier | Notifications, appels fournisseurs avec timeouts, rate limiting | Natifs |

Non retenus pour le MVP : Redis (le Throttler fonctionne sur cache fichier), file de messages, swagger-php, ORM tiers.

Identifiants externes manquants (à fournir plus tard, n'empêchent pas le développement) : fournisseur SMS/OTP, fournisseur WhatsApp, SMTP, environnements sandbox M-Pesa (Vodacom RDC), Airtel Money et Orange Money.

---

## 7. Plan de développement

| Phase | Contenu | Livrables vérifiables |
|---|---|---|
| **1 — Fondations** | Git + `.gitignore` (D7), conversion appstarter (D2), `.env` / `.env.example`, connexion utf8mb4, base `wapifood_test`, `ApiResponse`, gestion centralisée des exceptions (JSON, sans trace en production), CORS, Throttler, logs, Swagger initial | `GET /api/v1/health` (état de l'app et de la base), `/docs` en dev, tests verts |
| **2 — Base de données** | Migration baseline M1, seeds rôles/paramètres, modèles et entités des 31 tables, commande `wapifood:create-admin` | `spark migrate` sur base vide **et** sur la base existante sans erreur ; test de migration |
| **3 — Auth & RBAC** | M2–M4, register (client/propriétaire), login (téléphone ou e-mail), refresh avec rotation et détection de réutilisation, logout, révocation, mot de passe oublié/réinitialisé/changé, OTP (fournisseur configurable, « log » en dev), comptes suspendus, filtres JWT/Role/RestaurantAccess, matrice de permissions | Tests de sécurité (token expiré, réutilisé, compte suspendu, rôle insuffisant) |
| **4 — Restaurants & catalogue** | M5, inscription et soumission, validation/rejet admin, profil, adresses, horaires, équipe, catégories, produits, variantes, options, service d'upload (MIME réel, liste blanche, taille, nom généré) | Tests d'isolation inter-restaurants |
| **5 — Clients & commandes** | M6–M7, profil, adresses, favoris, création de commande (recalcul complet, disponibilités, appartenance, options, minimum, horaires, livraison, idempotence, transaction), machine à états, historique, annulation | Tests unitaires de calcul et de transitions ; tests d'isolation client/restaurant |
| **6 — Paiements & commissions** | M8–M9, contrat `PaymentProviderInterface`, cash opérationnel, adaptateurs Mobile Money derrière des flags désactivés (D4), webhooks idempotents, commission figée, journal, soldes, reversements | Tests webhook (signature invalide, rejeu, montant falsifié) ; tests financiers |
| **7 — Livraison, promotions, avis** | M10, livraison restaurant et retrait, codes promo (toutes les règles, verrou), avis (commande terminée, un seul par commande), modération | Tests métier |
| **8 — Notifications** | M11, dispatcher + canaux in-app/e-mail/SMS/WhatsApp, file d'attente, commandes Spark, reprise sur échec | Tests du dispatcher, canal désactivé non bloquant |
| **9 — Admin & rapports** | Dashboards admin, restaurant et client, par devise (pas de total USD+CDF sans conversion), gestion des commissions et reversements | Tests de périmètre des rapports |
| **10 — Finalisation** | Revue de sécurité, OpenAPI complet, README, guides d'installation, de déploiement Linux (Nginx/Apache, PHP-FPM, cron, permissions) et de maintenance | Suite complète verte |

Chaque phase se termine par l'exécution des tests et par le rapport au format demandé.
