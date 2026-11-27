# Schéma de base de données WapiFood

Référence : `wapifood_database.sql` → migration `CreateBaselineSchema` (schéma v1, **31 tables, 44 clés étrangères**).
Généré depuis `information_schema` le 2026-11-25. InnoDB, utf8mb4_unicode_ci.

## Règles transverses

- **Dates** : stockées en UTC. Chaque session MySQL est forcée à `+00:00` par le pilote `App\Database\MySQLi`.
- **Montants** : `DECIMAL(12,2)` (`14,2` pour les soldes), lus en chaînes et jamais convertis en float.
- **Devises** : `USD` ou `CDF`. La contrainte `CHECK` est ignorée par MySQL 5.7, la règle est donc appliquée dans le code.
- **Suppression logique** (`deleted_at`) : `wf_users`, `wf_restaurants`, `wf_products`.
- **Isolation par restaurant** : les tables racines portent `restaurant_id`. Les tables filles (`wf_product_*`, `wf_order_items`, `wf_payments`, `wf_deliveries`…) s'isolent par jointure sur leur parent.
- **Tables de journal** (insertion uniquement) : `wf_order_status_history`, `wf_audit_logs`, `wf_payment_webhooks`.

## Tables et relations

### Utilisateurs et accès · module `Users`

| Table | Colonnes | Clés étrangères (colonne → cible, ON DELETE) |
|---|---|---|
| `wf_users` | 14 | — |
| `wf_roles` | 4 | — |
| `wf_user_roles` | 3 | `role_id` → `wf_roles.id` (RESTRICT)<br>`user_id` → `wf_users.id` (RESTRICT) |

### Restaurants et équipes · module `Restaurants`

| Table | Colonnes | Clés étrangères (colonne → cible, ON DELETE) |
|---|---|---|
| `wf_restaurants` | 21 | `owner_user_id` → `wf_users.id` (RESTRICT) |
| `wf_restaurant_users` | 6 | `restaurant_id` → `wf_restaurants.id` (RESTRICT)<br>`user_id` → `wf_users.id` (RESTRICT) |
| `wf_restaurant_addresses` | 12 | `restaurant_id` → `wf_restaurants.id` (RESTRICT) |
| `wf_restaurant_opening_hours` | 6 | `restaurant_id` → `wf_restaurants.id` (RESTRICT) |

### Catalogue · module `Menus / Products`

| Table | Colonnes | Clés étrangères (colonne → cible, ON DELETE) |
|---|---|---|
| `wf_menu_categories` | 9 | `restaurant_id` → `wf_restaurants.id` (RESTRICT) |
| `wf_products` | 17 | `category_id` → `wf_menu_categories.id` (RESTRICT)<br>`restaurant_id` → `wf_restaurants.id` (RESTRICT) |
| `wf_product_images` | 5 | `product_id` → `wf_products.id` (CASCADE) |
| `wf_product_variants` | 5 | `product_id` → `wf_products.id` (CASCADE) |
| `wf_product_option_groups` | 7 | `product_id` → `wf_products.id` (CASCADE) |
| `wf_product_options` | 5 | `option_group_id` → `wf_product_option_groups.id` (CASCADE) |

### Clients · module `Customers`

| Table | Colonnes | Clés étrangères (colonne → cible, ON DELETE) |
|---|---|---|
| `wf_customer_addresses` | 18 | `user_id` → `wf_users.id` (RESTRICT) |
| `wf_customer_favorites` | 4 | `restaurant_id` → `wf_restaurants.id` (CASCADE)<br>`user_id` → `wf_users.id` (CASCADE) |

### Commandes · module `Orders`

| Table | Colonnes | Clés étrangères (colonne → cible, ON DELETE) |
|---|---|---|
| `wf_orders` | 27 | `customer_id` → `wf_users.id` (RESTRICT)<br>`delivery_address_id` → `wf_customer_addresses.id` (RESTRICT)<br>`restaurant_id` → `wf_restaurants.id` (RESTRICT) |
| `wf_order_items` | 10 | `order_id` → `wf_orders.id` (CASCADE)<br>`product_id` → `wf_products.id` (SET NULL) |
| `wf_order_status_history` | 7 | `changed_by` → `wf_users.id` (SET NULL)<br>`order_id` → `wf_orders.id` (CASCADE) |

### Paiements · module `Payments`

| Table | Colonnes | Clés étrangères (colonne → cible, ON DELETE) |
|---|---|---|
| `wf_payments` | 14 | `order_id` → `wf_orders.id` (RESTRICT) |
| `wf_payment_webhooks` | 11 | — |
| `wf_payment_refunds` (M9) | 13 | `created_by` → `wf_users.id` (SET NULL)<br>`order_id` → `wf_orders.id` (RESTRICT)<br>`payment_id` → `wf_payments.id` (RESTRICT) |

### Commissions et soldes · module `Commissions`

| Table | Colonnes | Clés étrangères (colonne → cible, ON DELETE) |
|---|---|---|
| `wf_commissions` | 11 | `order_id` → `wf_orders.id` (RESTRICT)<br>`restaurant_id` → `wf_restaurants.id` (RESTRICT) |
| `wf_restaurant_balances` | 9 | `restaurant_id` → `wf_restaurants.id` (RESTRICT) |
| `wf_restaurant_ledger_entries` (M8) | 13 | `commission_id` → `wf_commissions.id` (RESTRICT)<br>`created_by` → `wf_users.id` (SET NULL)<br>`order_id` → `wf_orders.id` (RESTRICT)<br>`payout_id` → `wf_restaurant_payouts.id` (RESTRICT)<br>`restaurant_id` → `wf_restaurants.id` (RESTRICT) |
| `wf_restaurant_payouts` | 13 | `processed_by` → `wf_users.id` (SET NULL)<br>`requested_by` → `wf_users.id` (SET NULL)<br>`restaurant_id` → `wf_restaurants.id` (RESTRICT) |

### Livraison · module `Deliveries`

| Table | Colonnes | Clés étrangères (colonne → cible, ON DELETE) |
|---|---|---|
| `wf_couriers` | 6 | `user_id` → `wf_users.id` (RESTRICT) |
| `wf_deliveries` | 13 | `courier_id` → `wf_couriers.id` (SET NULL)<br>`order_id` → `wf_orders.id` (RESTRICT) |

### Promotions · module `Promotions`

| Table | Colonnes | Clés étrangères (colonne → cible, ON DELETE) |
|---|---|---|
| `wf_promotions` | 15 | `restaurant_id` → `wf_restaurants.id` (CASCADE) |
| `wf_promotion_usages` | 6 | `order_id` → `wf_orders.id` (RESTRICT)<br>`promotion_id` → `wf_promotions.id` (RESTRICT)<br>`user_id` → `wf_users.id` (RESTRICT) |

### Avis, notifications, audit, paramètres · module `Reviews / Notifications / Audit / Settings`

| Table | Colonnes | Clés étrangères (colonne → cible, ON DELETE) |
|---|---|---|
| `wf_reviews` | 8 | `customer_id` → `wf_users.id` (RESTRICT)<br>`order_id` → `wf_orders.id` (RESTRICT)<br>`restaurant_id` → `wf_restaurants.id` (RESTRICT) |
| `wf_notifications` | 12 | `restaurant_id` → `wf_restaurants.id` (SET NULL)<br>`user_id` → `wf_users.id` (SET NULL) |
| `wf_audit_logs` | 9 | `user_id` → `wf_users.id` (SET NULL) |
| `wf_platform_settings` | 7 | — |

## Migrations additives (après le schéma v1)

| Migration | Contenu |
|---|---|
| M2 `CreateRefreshTokensTable` | `wf_refresh_tokens` : sessions, hash SHA-256 du jeton, chaîne de rotation (`family_id`, `replaced_by_id`) |
| M3 `CreateAuthCodesTable` | `wf_auth_codes` : codes OTP (HMAC-SHA256), tentatives, expiration |
| M4 `AddSecurityColumnsToUsers` | `wf_users` : `token_version`, `password_changed_at`, `failed_login_attempts`, `locked_until` |
| M5 `AddOperationalColumnsToRestaurants` | `wf_restaurants` : `status_reason`, `approved_at`, `approved_by` (FK `wf_users`, SET NULL), `delivery_fee`, `free_delivery_threshold`, `timezone` |
| M6 `AddSoftDeleteToCustomerAddresses` | `wf_customer_addresses` : `updated_at`, `deleted_at` (suppression logique : une adresse utilisée par une commande ne peut pas être supprimée physiquement) |
| M7 `AddIdempotencyKeyToOrders` | `wf_orders` : `idempotency_key VARCHAR(64) NULL` + index unique `uq_wf_orders_idempotency (customer_id, idempotency_key)` |
| M8 `CreateRestaurantLedger` | Table `wf_restaurant_ledger_entries` (journal immuable, montant signé, `balance_after`, index unique `(commission_id, entry_type)`) ; `wf_restaurant_balances.commission_due` (dette du restaurant envers WapiFood) |
| M9 `CreatePaymentRefunds` | Table `wf_payment_refunds` (remboursements partiels ou totaux d'un paiement) |
| M10 `AddDeliveryReviewAndPromotionColumns` | `wf_deliveries.courier_name` (livreur du restaurant, sans compte) ; `wf_reviews` : `moderated_at`, `moderated_by` (FK `wf_users`, SET NULL), `moderation_reason` ; `wf_restaurant_ledger_entries.entry_type` : ajout de `platform_discount` (−) et `platform_discount_reversed` (+). Rollback refusé si des écritures de ce type existent |
| M11 `CreateNotificationQueue` | `wf_notifications` : `recipient` (figé), `attempts`, `max_attempts`, `next_attempt_at`, `locked_until`, `last_error` + index `(status, next_attempt_at)` ; table `wf_notification_attempts` (historique des tentatives, index unique `(notification_id, attempt_number)`, CASCADE) |

## Procédure de déploiement

```bash
php spark migrate                  # base vide : crée les 31 tables ; base importée du script : contrôle + enregistrement
php spark db:seed DatabaseSeeder   # rôles et paramètres (INSERT IGNORE, rejouable)
php spark wapifood:create-admin    # premier super administrateur (mot de passe masqué)
```

- Ne **plus** exécuter `wapifood_database.sql` sur une base gérée par Spark : les évolutions passent uniquement par des migrations.
- Si une base existante diverge du schéma (table ou colonne manquante), la migration échoue et liste les écarts, sans rien modifier.
- `migrate:rollback` de la baseline est **refusé** hors environnement `testing`, car il supprimerait toutes les données.
