-- =====================================================
-- MIGRATION: Système de Compagnies (Dispatch Network)
-- ATTENTION: Production - Migration sécurisée
-- =====================================================

-- =====================================================
-- 1. TABLE: companies (Entreprises du réseau)
-- =====================================================
CREATE TABLE IF NOT EXISTS companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) UNIQUE,
    description TEXT,
    
    -- Type d'entreprise
    type ENUM('commissionnaire', 'transporteur', 'hybrid') DEFAULT 'hybrid',
    -- commissionnaire: prend commandes, pas de livreurs
    -- transporteur: a des livreurs, exécute
    -- hybrid: les deux
    
    -- Contact
    email VARCHAR(100),
    phone VARCHAR(20),
    address TEXT,
    
    -- Localisation (pour le dispatch géographique)
    city VARCHAR(50),
    zone_coverage VARCHAR(100), -- JSON array des zones desservies
    
    -- Tarifs et commission
    default_commission_rate DECIMAL(5,2) DEFAULT 10.00, -- % commission ColiXpress
    
    -- Informations légales
    registration_number VARCHAR(50), -- RCCM
    tax_id VARCHAR(50), -- IFU
    
    -- Logo et branding
    logo_url VARCHAR(255),
    
    -- Statut
    is_active TINYINT DEFAULT 1,
    is_verified TINYINT DEFAULT 0, -- Entreprise vérifiée par ColiXpress
    
    -- Dates
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_type (type),
    INDEX idx_city (city),
    INDEX idx_active (is_active),
    INDEX idx_verified (is_verified)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2. AJOUTER company_id à la table users
-- =====================================================
-- Vérifier si la colonne existe déjà
SET @exist := (SELECT COUNT(*) FROM information_schema.columns 
               WHERE table_schema = DATABASE() 
               AND table_name = 'users' 
               AND column_name = 'company_id');

SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE users ADD COLUMN company_id INT NULL AFTER role', 
    'SELECT "Column company_id already exists" as message');

PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Ajouter la clé étrangère (si la colonne vient d'être créée)
-- Note: On ne met pas ON DELETE CASCADE pour éviter la suppression accidentelle
SET @exist_fk := (SELECT COUNT(*) FROM information_schema.table_constraints 
                  WHERE table_schema = DATABASE() 
                  AND table_name = 'users' 
                  AND constraint_name = 'fk_users_company');

SET @sqlfk := IF(@exist_fk = 0,
    'ALTER TABLE users ADD CONSTRAINT fk_users_company 
     FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL',
    'SELECT "Foreign key already exists" as message');

PREPARE stmt FROM @sqlfk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index sur company_id
SET @exist_idx := (SELECT COUNT(*) FROM information_schema.statistics 
                   WHERE table_schema = DATABASE() 
                   AND table_name = 'users' 
                   AND index_name = 'idx_users_company');

SET @sqlidx := IF(@exist_idx = 0,
    'CREATE INDEX idx_users_company ON users(company_id)',
    'SELECT "Index already exists" as message');

PREPARE stmt FROM @sqlidx;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================
-- 3. TABLE: company_assignments (Sous-traitances)
-- =====================================================
CREATE TABLE IF NOT EXISTS company_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Commande concernée
    order_id INT NOT NULL,
    
    -- Qui détient la commande (commissionnaire)
    from_company_id INT, -- NULL si client direct sans commissionnaire
    
    -- Qui exécute (transporteur avec livreur)
    to_company_id INT,
    
    -- Qui a fait l'assignation (dispatcher)
    assigned_by INT,
    
    -- Qui est le livreur assigné
    assigned_livreur_id INT,
    
    -- Informations financières
    client_price INT, -- Prix facturé au client (copie de orders.price)
    subcontract_price INT, -- Prix négocié entre entreprises
    commission_rate DECIMAL(5,2) DEFAULT 10.00, -- % commission ColiXpress
    commission_amount INT GENERATED ALWAYS AS (ROUND(client_price * commission_rate / 100)) STORED,
    
    -- Statut de l'assignation
    status ENUM('pending', 'accepted', 'rejected', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
    
    -- Notes
    dispatcher_notes TEXT,
    rejection_reason TEXT,
    
    -- Dates
    assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    accepted_at DATETIME NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    
    -- Métadonnées
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Contraintes
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (from_company_id) REFERENCES companies(id) ON DELETE SET NULL,
    FOREIGN KEY (to_company_id) REFERENCES companies(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_livreur_id) REFERENCES users(id) ON DELETE SET NULL,
    
    -- Index
    INDEX idx_order (order_id),
    INDEX idx_from_company (from_company_id),
    INDEX idx_to_company (to_company_id),
    INDEX idx_status (status),
    INDEX idx_livreur (assigned_livreur_id),
    INDEX idx_assigned_at (assigned_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 4. AJOUTER assigned_company_id à orders
-- =====================================================
-- Pour savoir rapidement qui exécute la commande
SET @exist_ac := (SELECT COUNT(*) FROM information_schema.columns 
                  WHERE table_schema = DATABASE() 
                  AND table_name = 'orders' 
                  AND column_name = 'assigned_company_id');

SET @sqlac := IF(@exist_ac = 0, 
    'ALTER TABLE orders ADD COLUMN assigned_company_id INT NULL AFTER livreur_id', 
    'SELECT "Column assigned_company_id already exists" as message');

PREPARE stmt FROM @sqlac;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Foreign key pour assigned_company_id
SET @exist_fk_ac := (SELECT COUNT(*) FROM information_schema.table_constraints 
                       WHERE table_schema = DATABASE() 
                       AND table_name = 'orders' 
                       AND constraint_name = 'fk_orders_assigned_company');

SET @sqlfk_ac := IF(@exist_fk_ac = 0,
    'ALTER TABLE orders ADD CONSTRAINT fk_orders_assigned_company 
     FOREIGN KEY (assigned_company_id) REFERENCES companies(id) ON DELETE SET NULL',
    'SELECT "Foreign key already exists" as message');

PREPARE stmt FROM @sqlfk_ac;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================
-- 5. DONNÉES DE TEST (Optionnel - à supprimer en prod)
-- =====================================================
-- Entreprises exemple
INSERT INTO companies (name, slug, type, email, phone, city, is_verified, is_active) VALUES
('Akwa Express', 'akwa-express', 'commissionnaire', 'contact@akwa.com', '+237 6XX XXX XXX', 'Douala', 1, 1),
('Bali Delivery', 'bali-delivery', 'transporteur', 'info@bali.com', '+237 6XX XXX XXX', 'Douala', 1, 1),
('Logista Pro', 'logista-pro', 'hybrid', 'hello@logista.com', '+237 6XX XXX XXX', 'Yaoundé', 1, 1)
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

-- =====================================================
-- VÉRIFICATION
-- =====================================================
SELECT 'Migration companies_system completed successfully' as status;
SHOW TABLES LIKE '%company%';
