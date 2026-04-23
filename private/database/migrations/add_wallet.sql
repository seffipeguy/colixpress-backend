-- ============================================
-- Migration: Wallet System
-- Date: 2025-04-19
-- ============================================

-- 1. Table Wallets (un portefeuille par utilisateur)
CREATE TABLE wallets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    balance INT NOT NULL DEFAULT 0 COMMENT 'Solde en centimes XAF',
    currency VARCHAR(3) DEFAULT 'XAF',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_wallet_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_wallet_user (user_id)
) ENGINE=InnoDB;

-- 2. Table Wallet Transactions (historique)
CREATE TABLE wallet_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wallet_id INT NOT NULL,
    type ENUM('credit','debit') NOT NULL,
    amount INT NOT NULL COMMENT 'Montant en XAF (toujours positif)',
    balance_before INT NOT NULL,
    balance_after INT NOT NULL,
    source ENUM('top_up','order_payment','refund','bonus','withdrawal') NOT NULL,
    order_reference VARCHAR(20) NULL COMMENT 'Référence commande liée si applicable',
    description VARCHAR(500) NULL,
    performed_by INT NULL COMMENT 'Admin qui a effectué l opération (top_up)',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wt_wallet FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE CASCADE,
    CONSTRAINT fk_wt_performer FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_wt_wallet (wallet_id, created_at),
    INDEX idx_wt_order (order_reference)
) ENGINE=InnoDB;

-- 3. Ajouter 'wallet' dans payment_method des orders
ALTER TABLE orders MODIFY COLUMN payment_method ENUM('cash','mobile_money','wallet') DEFAULT 'cash';
