-- ============================================================
-- WapiFood — Schéma de référence v1 (31 tables)
-- Extrait À L'IDENTIQUE de wapifood_database.sql (section 1 à 10).
-- Exécuté par la migration CreateBaselineSchema.
-- NE PAS MODIFIER : toute évolution passe par une nouvelle migration.
-- ============================================================

-- ------------------------------------------------------------
-- 1. Utilisateurs, rôles et accès
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wf_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(30) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  avatar_url VARCHAR(500) NULL,
  status ENUM('pending','active','suspended','deleted') NOT NULL DEFAULT 'pending',
  email_verified_at DATETIME NULL,
  phone_verified_at DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wf_users_email (email),
  UNIQUE KEY uq_wf_users_phone (phone),
  KEY idx_wf_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wf_roles (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(50) NOT NULL,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wf_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wf_user_roles (
  user_id BIGINT UNSIGNED NOT NULL,
  role_id SMALLINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_wf_user_roles_user FOREIGN KEY (user_id) REFERENCES wf_users(id),
  CONSTRAINT fk_wf_user_roles_role FOREIGN KEY (role_id) REFERENCES wf_roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. Restaurants et équipes
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wf_restaurants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(180) NOT NULL,
  slug VARCHAR(200) NOT NULL,
  description TEXT NULL,
  logo_url VARCHAR(500) NULL,
  cover_url VARCHAR(500) NULL,
  phone VARCHAR(30) NOT NULL,
  email VARCHAR(190) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  commission_rate DECIMAL(5,2) NOT NULL DEFAULT 10.00,
  minimum_order_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  average_preparation_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  accepts_delivery TINYINT(1) NOT NULL DEFAULT 1,
  accepts_pickup TINYINT(1) NOT NULL DEFAULT 1,
  delivery_managed_by ENUM('restaurant','wapifood','both') NOT NULL DEFAULT 'restaurant',
  is_featured TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('pending','active','paused','rejected','suspended') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wf_restaurants_slug (slug),
  KEY idx_wf_restaurants_status (status),
  KEY idx_wf_restaurants_owner (owner_user_id),
  CONSTRAINT fk_wf_restaurants_owner FOREIGN KEY (owner_user_id) REFERENCES wf_users(id),
  CONSTRAINT chk_wf_restaurant_currency CHECK (currency IN ('USD','CDF')),
  CONSTRAINT chk_wf_restaurant_commission CHECK (commission_rate >= 0 AND commission_rate <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wf_restaurant_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  restaurant_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  restaurant_role ENUM('owner','manager','staff','cashier') NOT NULL DEFAULT 'staff',
  status ENUM('active','invited','disabled') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wf_restaurant_user (restaurant_id, user_id),
  CONSTRAINT fk_wf_restaurant_users_restaurant FOREIGN KEY (restaurant_id) REFERENCES wf_restaurants(id),
  CONSTRAINT fk_wf_restaurant_users_user FOREIGN KEY (user_id) REFERENCES wf_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wf_restaurant_addresses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  restaurant_id BIGINT UNSIGNED NOT NULL,
  label VARCHAR(100) NULL,
  address_line VARCHAR(255) NOT NULL,
  commune VARCHAR(100) NULL,
  city VARCHAR(100) NOT NULL DEFAULT 'Kinshasa',
  province VARCHAR(100) NULL,
  country VARCHAR(100) NOT NULL DEFAULT 'RDC',
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wf_restaurant_addresses_restaurant (restaurant_id),
  CONSTRAINT fk_wf_restaurant_addresses_restaurant FOREIGN KEY (restaurant_id) REFERENCES wf_restaurants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wf_restaurant_opening_hours (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  restaurant_id BIGINT UNSIGNED NOT NULL,
  day_of_week TINYINT UNSIGNED NOT NULL COMMENT '1=lundi ... 7=dimanche',
  opens_at TIME NULL,
  closes_at TIME NULL,
  is_closed TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wf_restaurant_day (restaurant_id, day_of_week),
  CONSTRAINT fk_wf_opening_hours_restaurant FOREIGN KEY (restaurant_id) REFERENCES wf_restaurants(id),
  CONSTRAINT chk_wf_opening_day CHECK (day_of_week BETWEEN 1 AND 7)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. Catalogue : catégories, produits, variantes et options
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wf_menu_categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  restaurant_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(500) NULL,
  image_url VARCHAR(500) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wf_menu_categories_restaurant (restaurant_id, is_active),
  CONSTRAINT fk_wf_menu_categories_restaurant FOREIGN KEY (restaurant_id) REFERENCES wf_restaurants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wf_products (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  restaurant_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NULL,
  name VARCHAR(180) NOT NULL,
  slug VARCHAR(200) NOT NULL,
  description TEXT NULL,
  sku VARCHAR(80) NULL,
  base_price DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  image_url VARCHAR(500) NULL,
  preparation_minutes SMALLINT UNSIGNED NULL,
  is_available TINYINT(1) NOT NULL DEFAULT 1,
  is_featured TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wf_product_slug (restaurant_id, slug),
  UNIQUE KEY uq_wf_product_sku (restaurant_id, sku),
  KEY idx_wf_products_listing (restaurant_id, category_id, is_available),
  CONSTRAINT fk_wf_products_restaurant FOREIGN KEY (restaurant_id) REFERENCES wf_restaurants(id),
  CONSTRAINT fk_wf_products_category FOREIGN KEY (category_id) REFERENCES wf_menu_categories(id),
  CONSTRAINT chk_wf_product_price CHECK (base_price >= 0),
  CONSTRAINT chk_wf_product_currency CHECK (currency IN ('USD','CDF'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wf_product_images (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  image_url VARCHAR(500) NOT NULL,
  alt_text VARCHAR(180) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  CONSTRAINT fk_wf_product_images_product FOREIGN KEY (product_id) REFERENCES wf_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wf_product_variants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  price_delta DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_wf_product_variants_product (product_id),
  CONSTRAINT fk_wf_product_variants_product FOREIGN KEY (product_id) REFERENCES wf_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wf_product_option_groups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  selection_type ENUM('single','multiple') NOT NULL DEFAULT 'single',
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  min_selections TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_selections TINYINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  CONSTRAINT fk_wf_option_groups_product FOREIGN KEY (product_id) REFERENCES wf_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wf_product_options (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  option_group_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  price_delta DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  CONSTRAINT fk_wf_product_options_group FOREIGN KEY (option_group_id) REFERENCES wf_product_option_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. Adresses et préférences clients
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wf_customer_addresses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  label VARCHAR(80) NULL,
  recipient_name VARCHAR(150) NULL,
  recipient_phone VARCHAR(30) NOT NULL,
  address_line VARCHAR(255) NOT NULL,
  commune VARCHAR(100) NULL,
  neighborhood VARCHAR(120) NULL,
  landmark VARCHAR(255) NULL,
  city VARCHAR(100) NOT NULL DEFAULT 'Kinshasa',
  country VARCHAR(100) NOT NULL DEFAULT 'RDC',
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  delivery_instructions TEXT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wf_customer_addresses_user (user_id),
  CONSTRAINT fk_wf_customer_addresses_user FOREIGN KEY (user_id) REFERENCES wf_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wf_customer_favorites (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  restaurant_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wf_customer_favorite (user_id, restaurant_id),
  CONSTRAINT fk_wf_favorites_user FOREIGN KEY (user_id) REFERENCES wf_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_wf_favorites_restaurant FOREIGN KEY (restaurant_id) REFERENCES wf_restaurants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. Commandes et lignes de commande
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wf_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_number VARCHAR(40) NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  restaurant_id BIGINT UNSIGNED NOT NULL,
  order_type ENUM('delivery','pickup') NOT NULL,
  delivery_address_id BIGINT UNSIGNED NULL,
  customer_name VARCHAR(150) NOT NULL,
  customer_phone VARCHAR(30) NOT NULL,
  delivery_address_snapshot TEXT NULL,
  delivery_instructions TEXT NULL,
  currency CHAR(3) NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL,
  discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_amount DECIMAL(12,2) NOT NULL,
  customer_note TEXT NULL,
  requested_for DATETIME NULL,
  status ENUM('pending','accepted','preparing','ready','out_for_delivery','completed','cancelled','rejected') NOT NULL DEFAULT 'pending',
  payment_status ENUM('unpaid','pending','paid','failed','refunded','partially_refunded') NOT NULL DEFAULT 'unpaid',
  accepted_at DATETIME NULL,
  completed_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  cancellation_reason VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wf_orders_number (order_number),
  KEY idx_wf_orders_customer_date (customer_id, created_at),
  KEY idx_wf_orders_restaurant_status (restaurant_id, status, created_at),
  KEY idx_wf_orders_payment_status (payment_status),
  CONSTRAINT fk_wf_orders_customer FOREIGN KEY (customer_id) REFERENCES wf_users(id),
  CONSTRAINT fk_wf_orders_restaurant FOREIGN KEY (restaurant_id) REFERENCES wf_restaurants(id),
  CONSTRAINT fk_wf_orders_address FOREIGN KEY (delivery_address_id) REFERENCES wf_customer_addresses(id),
  CONSTRAINT chk_wf_orders_currency CHECK (currency IN ('USD','CDF')),
  CONSTRAINT chk_wf_orders_amounts CHECK (subtotal >= 0 AND discount_amount >= 0 AND delivery_fee >= 0 AND total_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wf_order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NULL,
  product_name VARCHAR(180) NOT NULL COMMENT 'Snapshot du nom au moment de la commande',
  variant_name VARCHAR(120) NULL,
  quantity INT UNSIGNED NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL COMMENT 'Prix unitaire figé au moment de la commande',
  options_snapshot JSON NULL,
  line_total DECIMAL(12,2) NOT NULL,
  note VARCHAR(500) NULL,
  PRIMARY KEY (id),
  KEY idx_wf_order_items_order (order_id),
  CONSTRAINT fk_wf_order_items_order FOREIGN KEY (order_id) REFERENCES wf_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_wf_order_items_product FOREIGN KEY (product_id) REFERENCES wf_products(id) ON DELETE SET NULL,
  CONSTRAINT chk_wf_order_items_qty CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wf_order_status_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  old_status VARCHAR(40) NULL,
  new_status VARCHAR(40) NOT NULL,
  changed_by BIGINT UNSIGNED NULL,
  comment VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wf_order_history_order (order_id, created_at),
  CONSTRAINT fk_wf_order_history_order FOREIGN KEY (order_id) REFERENCES wf_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_wf_order_history_user FOREIGN KEY (changed_by) REFERENCES wf_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. Paiements et webhooks prestataires
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wf_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  provider ENUM('cash','mpesa','airtel_money','orange_money','other') NOT NULL,
  payment_method VARCHAR(80) NULL,
  provider_transaction_id VARCHAR(190) NULL,
  merchant_reference VARCHAR(120) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL,
  status ENUM('initiated','pending','successful','failed','cancelled','refunded') NOT NULL DEFAULT 'initiated',
  payer_phone VARCHAR(30) NULL,
  paid_at DATETIME NULL,
  raw_response JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wf_payment_reference (merchant_reference),
  UNIQUE KEY uq_wf_provider_transaction (provider, provider_transaction_id),
  KEY idx_wf_payments_order (order_id, status),
  CONSTRAINT fk_wf_payments_order FOREIGN KEY (order_id) REFERENCES wf_orders(id),
  CONSTRAINT chk_wf_payment_currency CHECK (currency IN ('USD','CDF')),
  CONSTRAINT chk_wf_payment_amount CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wf_payment_webhooks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider VARCHAR(50) NOT NULL,
  event_id VARCHAR(190) NULL,
  merchant_reference VARCHAR(120) NULL,
  provider_transaction_id VARCHAR(190) NULL,
  signature_valid TINYINT(1) NOT NULL DEFAULT 0,
  processing_status ENUM('received','verified','rejected','processed','error') NOT NULL DEFAULT 'received',
  payload JSON NOT NULL,
  processing_error TEXT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wf_webhook_provider_event (provider, event_id),
  KEY idx_wf_webhook_reference (merchant_reference),
  KEY idx_wf_webhook_status (processing_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7. Commissions, soldes et reversements
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wf_commissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  restaurant_id BIGINT UNSIGNED NOT NULL,
  commission_rate DECIMAL(5,2) NOT NULL,
  order_amount DECIMAL(12,2) NOT NULL,
  commission_amount DECIMAL(12,2) NOT NULL,
  restaurant_amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL,
  status ENUM('pending','earned','reversed') NOT NULL DEFAULT 'pending',
  earned_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wf_commission_order (order_id),
  KEY idx_wf_commissions_restaurant (restaurant_id, status),
  CONSTRAINT fk_wf_commissions_order FOREIGN KEY (order_id) REFERENCES wf_orders(id),
  CONSTRAINT fk_wf_commissions_restaurant FOREIGN KEY (restaurant_id) REFERENCES wf_restaurants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wf_restaurant_balances (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  restaurant_id BIGINT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL,
  available_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  pending_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  lifetime_earned DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  lifetime_paid DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wf_restaurant_balance (restaurant_id, currency),
  CONSTRAINT fk_wf_balances_restaurant FOREIGN KEY (restaurant_id) REFERENCES wf_restaurants(id),
  CONSTRAINT chk_wf_balance_currency CHECK (currency IN ('USD','CDF'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wf_restaurant_payouts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  restaurant_id BIGINT UNSIGNED NOT NULL,
  requested_by BIGINT UNSIGNED NULL,
  amount DECIMAL(14,2) NOT NULL,
  currency CHAR(3) NOT NULL,
  payout_method ENUM('bank_transfer','mpesa','airtel_money','orange_money','cash','other') NOT NULL,
  payout_account VARCHAR(190) NULL,
  reference VARCHAR(120) NULL,
  status ENUM('requested','processing','paid','rejected','cancelled') NOT NULL DEFAULT 'requested',
  admin_note TEXT NULL,
  processed_by BIGINT UNSIGNED NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_wf_payouts_restaurant_status (restaurant_id, status),
  CONSTRAINT fk_wf_payouts_restaurant FOREIGN KEY (restaurant_id) REFERENCES wf_restaurants(id),
  CONSTRAINT fk_wf_payouts_requester FOREIGN KEY (requested_by) REFERENCES wf_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_wf_payouts_processor FOREIGN KEY (processed_by) REFERENCES wf_users(id) ON DELETE SET NULL,
  CONSTRAINT chk_wf_payout_amount CHECK (amount > 0),
  CONSTRAINT chk_wf_payout_currency CHECK (currency IN ('USD','CDF'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 8. Livraison et livreurs (extension activable)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wf_couriers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  vehicle_type ENUM('motorbike','bicycle','car','other') NOT NULL DEFAULT 'motorbike',
  vehicle_plate VARCHAR(40) NULL,
  status ENUM('pending','active','busy','suspended') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wf_courier_user (user_id),
  CONSTRAINT fk_wf_couriers_user FOREIGN KEY (user_id) REFERENCES wf_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wf_deliveries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  courier_id BIGINT UNSIGNED NULL,
  managed_by ENUM('restaurant','wapifood') NOT NULL DEFAULT 'restaurant',
  delivery_status ENUM('pending','assigned','picked_up','in_transit','delivered','failed','cancelled') NOT NULL DEFAULT 'pending',
  delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  courier_phone VARCHAR(30) NULL,
  pickup_at DATETIME NULL,
  delivered_at DATETIME NULL,
  proof_url VARCHAR(500) NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wf_delivery_order (order_id),
  KEY idx_wf_deliveries_courier_status (courier_id, delivery_status),
  CONSTRAINT fk_wf_deliveries_order FOREIGN KEY (order_id) REFERENCES wf_orders(id),
  CONSTRAINT fk_wf_deliveries_courier FOREIGN KEY (courier_id) REFERENCES wf_couriers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 9. Promotions et codes promo
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wf_promotions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  restaurant_id BIGINT UNSIGNED NULL COMMENT 'NULL = promotion plateforme',
  code VARCHAR(50) NOT NULL,
  title VARCHAR(150) NOT NULL,
  discount_type ENUM('percentage','fixed') NOT NULL,
  discount_value DECIMAL(12,2) NOT NULL,
  minimum_order_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  maximum_discount_amount DECIMAL(12,2) NULL,
  currency CHAR(3) NULL,
  usage_limit INT UNSIGNED NULL,
  usage_per_customer INT UNSIGNED NULL,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wf_promotion_code (code),
  CONSTRAINT fk_wf_promotions_restaurant FOREIGN KEY (restaurant_id) REFERENCES wf_restaurants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wf_promotion_usages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  promotion_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  discount_amount DECIMAL(12,2) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wf_promotion_order (promotion_id, order_id),
  KEY idx_wf_promotion_user (promotion_id, user_id),
  CONSTRAINT fk_wf_promotion_usages_promotion FOREIGN KEY (promotion_id) REFERENCES wf_promotions(id),
  CONSTRAINT fk_wf_promotion_usages_order FOREIGN KEY (order_id) REFERENCES wf_orders(id),
  CONSTRAINT fk_wf_promotion_usages_user FOREIGN KEY (user_id) REFERENCES wf_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 10. Avis, notifications et journal d'audit
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wf_reviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  restaurant_id BIGINT UNSIGNED NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  comment TEXT NULL,
  status ENUM('published','hidden','pending') NOT NULL DEFAULT 'published',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wf_review_order (order_id),
  KEY idx_wf_reviews_restaurant (restaurant_id, status),
  CONSTRAINT fk_wf_reviews_order FOREIGN KEY (order_id) REFERENCES wf_orders(id),
  CONSTRAINT fk_wf_reviews_customer FOREIGN KEY (customer_id) REFERENCES wf_users(id),
  CONSTRAINT fk_wf_reviews_restaurant FOREIGN KEY (restaurant_id) REFERENCES wf_restaurants(id),
  CONSTRAINT chk_wf_review_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wf_notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  restaurant_id BIGINT UNSIGNED NULL,
  channel ENUM('in_app','email','sms','whatsapp','push') NOT NULL DEFAULT 'in_app',
  notification_type VARCHAR(80) NOT NULL,
  title VARCHAR(180) NOT NULL,
  message TEXT NOT NULL,
  data JSON NULL,
  status ENUM('queued','sent','failed','read') NOT NULL DEFAULT 'queued',
  sent_at DATETIME NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wf_notifications_user (user_id, status, created_at),
  KEY idx_wf_notifications_restaurant (restaurant_id, created_at),
  CONSTRAINT fk_wf_notifications_user FOREIGN KEY (user_id) REFERENCES wf_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_wf_notifications_restaurant FOREIGN KEY (restaurant_id) REFERENCES wf_restaurants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wf_audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(100) NULL,
  entity_id BIGINT UNSIGNED NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(500) NULL,
  details JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wf_audit_user_date (user_id, created_at),
  KEY idx_wf_audit_entity (entity_type, entity_id),
  CONSTRAINT fk_wf_audit_user FOREIGN KEY (user_id) REFERENCES wf_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wf_platform_settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  setting_key VARCHAR(120) NOT NULL,
  setting_value TEXT NULL,
  value_type ENUM('string','number','boolean','json') NOT NULL DEFAULT 'string',
  description VARCHAR(255) NULL,
  is_public TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wf_platform_setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
