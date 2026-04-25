-- Migration: Système de paiement multi-providers
-- Date: 2026-04-25
-- Description: Tables pour gérer plusieurs providers de paiement (CamPay, MTN, Orange, etc.)

USE colixpress_db;

-- =============================================
-- Table: payment_providers
-- =============================================
CREATE TABLE IF NOT EXISTS payment_providers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE COMMENT 'campay, mtn_cm, orange_cm, etc.',
    name VARCHAR(100) NOT NULL COMMENT 'CamPay, MTN Mobile Money',
    provider_type ENUM('mobile_money','bank_card','bank_transfer','cash','wallet') DEFAULT 'mobile_money',
    logo_url VARCHAR(255) NULL,
    
    -- Configuration API
    api_base_url VARCHAR(255) NULL,
    api_version VARCHAR(20) NULL,
    requires_api_key BOOLEAN DEFAULT 1,
    
    -- Credentials (à chiffrer en production)
    api_username TEXT NULL COMMENT 'Username ou App ID',
    api_password TEXT NULL COMMENT 'Password ou App Secret',
    api_token TEXT NULL COMMENT 'Access Token permanent',
    webhook_secret TEXT NULL COMMENT 'Clé de signature webhook',
    extra_config JSON NULL COMMENT 'Config spécifique au provider',
    
    -- Statut
    is_active BOOLEAN DEFAULT 1,
    is_sandbox BOOLEAN DEFAULT 0,
    
    -- Limites
    min_amount INT DEFAULT 100 COMMENT 'Montant minimum en XAF',
    max_amount INT DEFAULT 5000000 COMMENT 'Montant maximum',
    transaction_fee_percent DECIMAL(5,2) DEFAULT 0 COMMENT 'Frais en %',
    transaction_fee_fixed INT DEFAULT 0 COMMENT 'Frais fixes en XAF',
    
    -- Métadonnées
    description TEXT NULL,
    instructions TEXT NULL COMMENT 'Instructions pour le client',
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_code (code),
    INDEX idx_active (is_active),
    INDEX idx_type (provider_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- Table: payment_provider_countries
-- =============================================
CREATE TABLE IF NOT EXISTS payment_provider_countries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider_id INT NOT NULL,
    country_id INT NOT NULL,
    is_default BOOLEAN DEFAULT 0 COMMENT 'Provider par défaut pour ce pays',
    display_order INT DEFAULT 0 COMMENT 'Ordre d\'affichage dans l\'app',
    is_active BOOLEAN DEFAULT 1,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    CONSTRAINT fk_ppc_provider FOREIGN KEY (provider_id) REFERENCES payment_providers(id) ON DELETE CASCADE,
    CONSTRAINT fk_ppc_country FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE CASCADE,
    UNIQUE KEY unique_provider_country (provider_id, country_id),
    INDEX idx_country_default (country_id, is_default),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- Table: payment_transactions
-- =============================================
CREATE TABLE IF NOT EXISTS payment_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(50) NOT NULL UNIQUE COMMENT 'TX-YYYYMMDD-XXXXX',
    order_id INT NULL,
    
    -- Provider
    provider_id INT NOT NULL,
    provider_transaction_id VARCHAR(255) NULL COMMENT 'ID de la transaction chez le provider',
    provider_reference VARCHAR(255) NULL COMMENT 'Référence externe du provider',
    
    -- Montant
    amount INT NOT NULL COMMENT 'Montant en XAF',
    currency VARCHAR(3) DEFAULT 'XAF',
    fee_amount INT DEFAULT 0 COMMENT 'Frais de transaction',
    net_amount INT GENERATED ALWAYS AS (amount - fee_amount) STORED,
    
    -- Client
    customer_phone VARCHAR(20) NOT NULL,
    customer_name VARCHAR(100) NULL,
    customer_email VARCHAR(100) NULL,
    
    -- Statut
    status ENUM('pending','processing','completed','failed','cancelled','refunded') DEFAULT 'pending',
    
    -- Métadonnées
    payment_method VARCHAR(50) NULL COMMENT 'campay, mtn_momo, orange_money, etc.',
    payment_details JSON NULL COMMENT 'Détails spécifiques du provider',
    
    -- Callback & Webhook
    callback_url VARCHAR(255) NULL,
    return_url VARCHAR(255) NULL,
    webhook_received_at DATETIME NULL,
    webhook_data JSON NULL,
    
    -- Erreurs
    error_code VARCHAR(50) NULL,
    error_message TEXT NULL,
    retry_count INT DEFAULT 0,
    
    -- Dates
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

-- =============================================
-- Données initiales
-- =============================================

-- CamPay (Cameroun) - Provider principal
INSERT INTO payment_providers (
    code, name, provider_type, 
    api_base_url, api_version,
    api_username, api_password, api_token, webhook_secret,
    is_active, is_sandbox,
    min_amount, max_amount,
    description, instructions
) VALUES (
    'campay',
    'CamPay',
    'mobile_money',
    'https://demo.campay.net/api',
    'v1',
    'n1nmmcgPKwtEAL2o9YZuC1HI3Ujh5ZGkJEDCP_p-9E7Leux-mxHATTChoBnmC-Ua4Q2DtUSoKJCTtPvyT4I84Q',
    'K5zvc7OSvTfgp30eEJJe1ykuiGCVxiGkpV8UJz_pPpm9uJeYNXGMuAnaXY00PiaKDftLc0ehACvr16PX6h83tA',
    '2722fa4e60d1c3a10a3b7e833c306bd9ba13eb0a',
    'Gc5qBn0H-yKV3CAa3BKKlnC8MO5yu9JqTG7V8YoYwk-8Kc_UF7-7DabMBYyzlA7CtXL4gefr66rmHqYHUHstKw',
    1,
    1,
    100,
    2000000,
    'Paiement mobile money via CamPay (MTN, Orange, Express Union)',
    'Vous recevrez une notification sur votre téléphone pour valider le paiement. Si la notification ne vient pas : Orange Money #150*50# ou MTN Mobile Money *126# et suivez les instructions'
);

-- Cash (disponible partout)
INSERT INTO payment_providers (
    code, name, provider_type,
    is_active, requires_api_key,
    min_amount, max_amount,
    description, instructions
) VALUES (
    'cash',
    'Paiement en espèces',
    'cash',
    1,
    0,
    0,
    999999999,
    'Paiement en espèces à la livraison',
    'Préparez le montant exact pour faciliter la transaction'
);

-- Lier CamPay au Cameroun (par défaut)
INSERT INTO payment_provider_countries (provider_id, country_id, is_default, display_order, is_active)
SELECT id, 1, 1, 1, 1 FROM payment_providers WHERE code = 'campay';

-- Lier Cash à tous les pays
INSERT INTO payment_provider_countries (provider_id, country_id, is_default, display_order, is_active)
SELECT p.id, c.id, 0, 99, 1 
FROM payment_providers p
CROSS JOIN countries c
WHERE p.code = 'cash';

-- =============================================
-- Settings pour le système de paiement
-- =============================================
INSERT INTO settings (setting_key, setting_value, category, description) VALUES
('payment_enabled', '1', 'finance', 'Activer le système de paiement en ligne'),
('payment_auto_confirm_orders', '1', 'finance', 'Confirmer automatiquement les commandes après paiement réussi'),
('payment_webhook_timeout_minutes', '30', 'finance', 'Timeout pour les webhooks de paiement (minutes)'),
('payment_platform_fee_percent', '0', 'finance', 'Frais plateforme en pourcentage'),
('payment_platform_fee_fixed', '0', 'finance', 'Frais plateforme fixes (XAF)'),
('payment_retry_max_attempts', '3', 'finance', 'Nombre maximum de tentatives de paiement')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
