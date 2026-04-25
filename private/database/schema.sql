-- ============================================
-- ColiXpress Database Schema
-- Version: 1.0
-- Date: 2025-02-08
-- Charset: utf8mb4
-- ============================================

USE colixpress_db;

-- ============================================
-- 1. COUNTRIES - Codes pays et indicatifs
-- ============================================
CREATE TABLE countries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    iso_code CHAR(2) NOT NULL UNIQUE,
    dial_code VARCHAR(5) NOT NULL,
    phone_length TINYINT NOT NULL DEFAULT 9,
    currency VARCHAR(3) DEFAULT 'XAF',
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================
-- 2. USERS - Tous les utilisateurs
-- ============================================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    country_id INT NOT NULL,
    phone VARCHAR(15) NOT NULL,
    role ENUM('client','livreur','shop_owner','admin','developer') DEFAULT 'client',
    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    email VARCHAR(255) NULL,
    password VARCHAR(255) NULL,
    profile_photo VARCHAR(500) NULL,
    is_verified TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_country_phone (country_id, phone),
    CONSTRAINT fk_users_country FOREIGN KEY (country_id) REFERENCES countries(id)
) ENGINE=InnoDB;

-- ============================================
-- 3. OTP_CODES - Codes OTP pour authentification
-- ============================================
CREATE TABLE otp_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    country_id INT NOT NULL,
    phone VARCHAR(15) NOT NULL,
    code VARCHAR(6) NOT NULL,
    expires_at DATETIME NOT NULL,
    is_used TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_otp_country FOREIGN KEY (country_id) REFERENCES countries(id),
    INDEX idx_otp_phone (country_id, phone),
    INDEX idx_otp_expires (expires_at)
) ENGINE=InnoDB;

-- ============================================
-- 4. AUTH_TOKENS - Tokens de session
-- ============================================
CREATE TABLE auth_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(500) NOT NULL UNIQUE,
    device_info VARCHAR(255) NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token_expires (expires_at)
) ENGINE=InnoDB;

-- ============================================
-- 5. ADDRESSES - Adresses sauvegardees
-- ============================================
CREATE TABLE addresses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    label VARCHAR(100) NOT NULL,
    full_address VARCHAR(500) NOT NULL,
    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,
    city VARCHAR(100) DEFAULT 'Douala',
    quarter VARCHAR(100) NULL,
    is_default TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_addresses_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_addresses_user (user_id)
) ENGINE=InnoDB;

-- ============================================
-- 6. SHOP_CATEGORIES - Categories de boutiques
-- ============================================
CREATE TABLE shop_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    icon VARCHAR(500) NULL,
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================
-- 7. SHOPS - Boutiques partenaires
-- ============================================
CREATE TABLE shops (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT NULL,
    category_id INT NULL,
    logo VARCHAR(500) NULL,
    cover_photo VARCHAR(500) NULL,
    address VARCHAR(500) NOT NULL,
    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,
    city VARCHAR(100) DEFAULT 'Douala',
    quarter VARCHAR(100) NULL,
    country_id INT NOT NULL,
    phone VARCHAR(15) NOT NULL,
    opening_time TIME NULL,
    closing_time TIME NULL,
    is_active TINYINT(1) DEFAULT 1,
    is_approved TINYINT(1) DEFAULT 0,
    rating_avg DECIMAL(3,2) DEFAULT 0.00,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_shops_owner FOREIGN KEY (owner_id) REFERENCES users(id),
    CONSTRAINT fk_shops_category FOREIGN KEY (category_id) REFERENCES shop_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_shops_country FOREIGN KEY (country_id) REFERENCES countries(id),
    INDEX idx_shops_city (city),
    INDEX idx_shops_category (category_id),
    INDEX idx_shops_active (is_active, is_approved)
) ENGINE=InnoDB;

-- ============================================
-- 8. SHOP_ITEMS - Catalogue produits des boutiques
-- ============================================
CREATE TABLE shop_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT NULL,
    price INT NOT NULL,
    photo VARCHAR(500) NULL,
    category VARCHAR(100) NULL,
    is_available TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_items_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    INDEX idx_items_shop (shop_id),
    INDEX idx_items_available (shop_id, is_available)
) ENGINE=InnoDB;

-- ============================================
-- 9. LIVREUR_PROFILES - Profil etendu des livreurs
-- ============================================
CREATE TABLE livreur_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    vehicle_type ENUM('moto','voiture','velo','pied') NOT NULL,
    plate_number VARCHAR(20) NULL,
    id_card_number VARCHAR(50) NULL,
    id_card_photo VARCHAR(500) NULL,
    is_available TINYINT(1) DEFAULT 0,
    is_approved TINYINT(1) DEFAULT 0,
    current_lat DECIMAL(10,8) NULL,
    current_lng DECIMAL(11,8) NULL,
    last_location_at DATETIME NULL,
    rating_avg DECIMAL(3,2) DEFAULT 0.00,
    total_deliveries INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_livreur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================
-- 10. API_KEYS - Clés API pour développeurs tiers
-- ============================================
CREATE TABLE api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    api_key VARCHAR(64) NOT NULL UNIQUE,
    api_secret VARCHAR(128) NOT NULL,
    webhook_url VARCHAR(500) NULL,
    allowed_ips TEXT NULL,
    rate_limit_per_hour INT DEFAULT 1000,
    is_active TINYINT(1) DEFAULT 1,
    is_test_mode TINYINT(1) DEFAULT 1,
    total_requests INT DEFAULT 0,
    last_request_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_apikeys_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ============================================
-- 11. ORDERS - Commandes de livraison
-- ============================================
CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(20) NOT NULL UNIQUE,
    external_reference VARCHAR(100) NULL,
    order_type ENUM('direct','shop') DEFAULT 'direct',
    client_id INT NOT NULL,
    api_key_id INT NULL,
    livreur_id INT NULL,
    shop_id INT NULL,

    -- Point de ramassage
    pickup_address VARCHAR(500) NULL,
    pickup_lat DECIMAL(10,8) NULL,
    pickup_lng DECIMAL(11,8) NULL,
    pickup_contact_name VARCHAR(100) NULL,
    pickup_contact_phone VARCHAR(20) NULL,

    -- Point de livraison
    dropoff_address VARCHAR(500) NULL,
    dropoff_lat DECIMAL(10,8) NULL,
    dropoff_lng DECIMAL(11,8) NULL,
    dropoff_contact_name VARCHAR(100) NULL,
    dropoff_contact_phone VARCHAR(20) NULL,

    -- Colis
    package_description VARCHAR(500) NULL,
    package_size ENUM('petit','moyen','grand') NULL,
    package_weight_kg DECIMAL(5,2) NULL,
    package_value INT DEFAULT 0 COMMENT 'Valeur estimée du colis en XAF',

    -- Tarification
    distance_km DECIMAL(8,2) NULL,
    price INT NOT NULL DEFAULT 0,
    maps_api_cost INT DEFAULT 0 COMMENT 'Coût utilisation API Maps en XAF',
    currency VARCHAR(3) DEFAULT 'XAF',

    -- Statut
    status ENUM('pending','accepted','picking_up','picked_up','in_transit','delivered','cancelled') DEFAULT 'pending',

    -- Paiement
    payment_method ENUM('cash','mobile_money') DEFAULT 'cash',
    payment_status ENUM('pending','paid','refunded') DEFAULT 'pending',

    -- Divers
    notes TEXT NULL,
    pickup_scheduled_at DATETIME NULL COMMENT 'Créneau horaire souhaité pour le ramassage',
    scheduled_at DATETIME NULL COMMENT 'Créneau horaire souhaité pour la livraison',
    accepted_at DATETIME NULL,
    picked_up_at DATETIME NULL,
    delivered_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    cancellation_reason VARCHAR(500) NULL,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_orders_client FOREIGN KEY (client_id) REFERENCES users(id),
    CONSTRAINT fk_orders_apikey FOREIGN KEY (api_key_id) REFERENCES api_keys(id),
    CONSTRAINT fk_orders_livreur FOREIGN KEY (livreur_id) REFERENCES users(id),
    CONSTRAINT fk_orders_shop FOREIGN KEY (shop_id) REFERENCES shops(id),
    INDEX idx_orders_client (client_id),
    INDEX idx_orders_livreur (livreur_id),
    INDEX idx_orders_status (status),
    INDEX idx_orders_shop (shop_id),
    INDEX idx_orders_created (created_at),
    INDEX idx_external_ref (external_reference)
) ENGINE=InnoDB;

-- ============================================
-- 11. ORDER_ITEMS - Articles commandes (commandes boutique)
-- ============================================
CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    shop_item_id INT NULL,
    item_name VARCHAR(200) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price INT NOT NULL,
    total_price INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_items_shopitem FOREIGN KEY (shop_item_id) REFERENCES shop_items(id) ON DELETE SET NULL,
    INDEX idx_order_items_order (order_id)
) ENGINE=InnoDB;

-- ============================================
-- 12. ORDER_STATUS_HISTORY - Historique des statuts
-- ============================================
CREATE TABLE order_status_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    status VARCHAR(50) NOT NULL,
    comment VARCHAR(500) NULL,
    changed_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_status_history_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_status_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status_history_order (order_id)
) ENGINE=InnoDB;

-- ============================================
-- 13. LIVREUR_LOCATIONS - Historique GPS (tracking)
-- ============================================
CREATE TABLE livreur_locations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    livreur_id INT NOT NULL,
    order_id INT NULL,
    latitude DECIMAL(10,8) NOT NULL,
    longitude DECIMAL(11,8) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_locations_livreur FOREIGN KEY (livreur_id) REFERENCES users(id),
    CONSTRAINT fk_locations_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    INDEX idx_locations_order (order_id, created_at),
    INDEX idx_locations_livreur (livreur_id, created_at)
) ENGINE=InnoDB;

-- ============================================
-- 14. PRICING_RULES - Configuration tarifaire
-- ============================================
CREATE TABLE pricing_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    city VARCHAR(100) NOT NULL,
    base_price INT NOT NULL,
    price_per_km INT NOT NULL,
    min_price INT NOT NULL,
    surge_multiplier DECIMAL(3,2) DEFAULT 1.00,
    surcharge_moyen INT DEFAULT 0,
    surcharge_grand INT DEFAULT 0,
    weight_threshold_kg DECIMAL(5,2) DEFAULT 5.00,
    price_per_extra_kg INT DEFAULT 100,
    night_multiplier DECIMAL(3,2) DEFAULT 1.50,
    peak_multiplier DECIMAL(3,2) DEFAULT 1.25,
    max_price INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pricing_city (city, is_active)
) ENGINE=InnoDB;

-- ============================================
-- 15. NOTIFICATIONS - Systeme de notifications
-- ============================================
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('order_update','promotion','system') DEFAULT 'system',
    data JSON NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notifications_user (user_id, is_read),
    INDEX idx_notifications_created (created_at)
) ENGINE=InnoDB;

-- ============================================
-- 16. RATINGS - Evaluations post-livraison
-- ============================================
CREATE TABLE ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    rated_by INT NOT NULL,
    rated_user INT NOT NULL,
    score TINYINT NOT NULL CHECK (score BETWEEN 1 AND 5),
    comment VARCHAR(500) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ratings_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_ratings_ratedby FOREIGN KEY (rated_by) REFERENCES users(id),
    CONSTRAINT fk_ratings_rateduser FOREIGN KEY (rated_user) REFERENCES users(id),
    UNIQUE KEY uk_rating_order_user (order_id, rated_by)
) ENGINE=InnoDB;

-- ============================================
-- 17. SETTINGS - Paramètres globaux clé/valeur
-- ============================================
CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    description VARCHAR(500) NULL,
    category VARCHAR(50) DEFAULT 'general',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_settings_category (category)
) ENGINE=InnoDB;

-- ============================================
-- 18. PROMOTIONS - Codes promotionnels
-- ============================================
CREATE TABLE promotions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(500) NULL,
    discount_type ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
    discount_value INT NOT NULL,
    min_order_amount INT DEFAULT 0,
    max_discount INT DEFAULT 0,
    max_uses INT DEFAULT 0,
    used_count INT DEFAULT 0,
    max_uses_per_user INT DEFAULT 1,
    valid_from DATETIME NULL,
    valid_until DATETIME NULL,
    applicable_cities TEXT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_promo_code (code),
    INDEX idx_promo_active (is_active, valid_from, valid_until)
) ENGINE=InnoDB;

-- ============================================
-- 19. PROMOTION_USES - Historique utilisation codes promo
-- ============================================
CREATE TABLE promotion_uses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    promotion_id INT NOT NULL,
    user_id INT NOT NULL,
    order_id INT NOT NULL,
    discount_applied INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_promo_uses_promo FOREIGN KEY (promotion_id) REFERENCES promotions(id),
    CONSTRAINT fk_promo_uses_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_promo_uses_order FOREIGN KEY (order_id) REFERENCES orders(id),
    INDEX idx_promo_user (promotion_id, user_id)
) ENGINE=InnoDB;

-- ============================================
-- 20. BANNERS - Actualités / Slider app
-- ============================================
CREATE TABLE banners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    image_url VARCHAR(500) NULL,
    link_url VARCHAR(500) NULL,
    link_type ENUM('internal','external','none') DEFAULT 'none',
    link_data JSON NULL,
    target_roles VARCHAR(255) NULL,
    target_cities VARCHAR(255) NULL,
    position INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    valid_from DATETIME NULL,
    valid_until DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_banners_active (is_active, position),
    INDEX idx_banners_dates (valid_from, valid_until)
) ENGINE=InnoDB;

-- ============================================
-- 21. GEOCACHE - Cache proxy Google Maps
-- ============================================
CREATE TABLE geocache (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    cache_type ENUM('autocomplete','geocode','reverse_geocode','directions','distance') NOT NULL,
    cache_key VARCHAR(255) NOT NULL UNIQUE,
    query_input VARCHAR(500) NOT NULL COMMENT 'Requête originale (texte ou coordonnées)',
    response_data JSON NOT NULL COMMENT 'Réponse Google Maps mise en cache',
    hit_count INT DEFAULT 0 COMMENT 'Nombre de fois servie depuis le cache',
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_geocache_type (cache_type),
    INDEX idx_geocache_expires (expires_at),
    INDEX idx_geocache_key (cache_key)
) ENGINE=InnoDB;

-- ============================================
-- DONNEES INITIALES
-- ============================================

-- Pays actifs
INSERT INTO countries (name, iso_code, dial_code, phone_length, currency) VALUES
('Cameroun', 'CM', '+237', 9, 'XAF'),
('Gabon', 'GA', '+241', 8, 'XAF'),
('Congo', 'CG', '+242', 9, 'XAF'),
('Cote d\'Ivoire', 'CI', '+225', 10, 'XOF'),
('Senegal', 'SN', '+221', 9, 'XOF');

-- Tarification Douala (valeurs initiales)
INSERT INTO pricing_rules (city, base_price, price_per_km, min_price, surge_multiplier) VALUES
('Douala', 500, 200, 1000, 1.00);

-- Categories de boutiques
INSERT INTO shop_categories (name, sort_order) VALUES
('Restaurant', 1),
('Pharmacie', 2),
('Supermarche', 3),
('Boutique Mode', 4),
('Electronique', 5),
('Boulangerie / Patisserie', 6),
('Librairie', 7),
('Autre', 99);

-- =============================================
-- PAYMENT SYSTEM (Ajouté 2026-04-25)
-- =============================================

-- Table: payment_providers
CREATE TABLE IF NOT EXISTS payment_providers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE COMMENT 'campay, mtn_cm, orange_cm, etc.',
    name VARCHAR(100) NOT NULL COMMENT 'CamPay, MTN Mobile Money',
    provider_type ENUM('mobile_money','bank_card','bank_transfer','cash','wallet') DEFAULT 'mobile_money',
    logo_url VARCHAR(255) NULL,
    api_base_url VARCHAR(255) NULL,
    api_version VARCHAR(20) NULL,
    requires_api_key BOOLEAN DEFAULT 1,
    api_username TEXT NULL,
    api_password TEXT NULL,
    api_token TEXT NULL,
    webhook_secret TEXT NULL,
    extra_config JSON NULL,
    is_active BOOLEAN DEFAULT 1,
    is_sandbox BOOLEAN DEFAULT 0,
    min_amount INT DEFAULT 100,
    max_amount INT DEFAULT 5000000,
    transaction_fee_percent DECIMAL(5,2) DEFAULT 0,
    transaction_fee_fixed INT DEFAULT 0,
    description TEXT NULL,
    instructions TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_active (is_active),
    INDEX idx_type (provider_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: payment_provider_countries
CREATE TABLE IF NOT EXISTS payment_provider_countries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider_id INT NOT NULL,
    country_id INT NOT NULL,
    is_default BOOLEAN DEFAULT 0,
    display_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ppc_provider FOREIGN KEY (provider_id) REFERENCES payment_providers(id) ON DELETE CASCADE,
    CONSTRAINT fk_ppc_country FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE CASCADE,
    UNIQUE KEY unique_provider_country (provider_id, country_id),
    INDEX idx_country_default (country_id, is_default),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: payment_transactions
CREATE TABLE IF NOT EXISTS payment_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(50) NOT NULL UNIQUE,
    order_id INT NULL,
    provider_id INT NOT NULL,
    provider_transaction_id VARCHAR(255) NULL,
    provider_reference VARCHAR(255) NULL,
    amount INT NOT NULL,
    currency VARCHAR(3) DEFAULT 'XAF',
    fee_amount INT DEFAULT 0,
    net_amount INT GENERATED ALWAYS AS (amount - fee_amount) STORED,
    customer_phone VARCHAR(20) NOT NULL,
    customer_name VARCHAR(100) NULL,
    customer_email VARCHAR(100) NULL,
    status ENUM('pending','processing','completed','failed','cancelled','refunded') DEFAULT 'pending',
    payment_method VARCHAR(50) NULL,
    payment_details JSON NULL,
    callback_url VARCHAR(255) NULL,
    return_url VARCHAR(255) NULL,
    webhook_received_at DATETIME NULL,
    webhook_data JSON NULL,
    error_code VARCHAR(50) NULL,
    error_message TEXT NULL,
    retry_count INT DEFAULT 0,
    initiated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    failed_at DATETIME NULL,
    refunded_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pt_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    CONSTRAINT fk_pt_provider FOREIGN KEY (provider_id) REFERENCES payment_providers(id),
    INDEX idx_reference (reference),
    INDEX idx_order (order_id),
    INDEX idx_status (status),
    INDEX idx_provider_tx (provider_transaction_id),
    INDEX idx_customer_phone (customer_phone),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
