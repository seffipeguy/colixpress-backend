-- =====================================================
-- MIGRATION: Mise à jour Système de Compagnies (Dispatch Network)
-- ATTENTION: Production - Migration sécurisée
-- La table companies existe déjà avec structure différente
-- =====================================================

-- =====================================================
-- 1. AJOUTER COLONNES MANQUANTES à companies
-- =====================================================

-- Type d'entreprise (commissionnaire, transporteur, hybrid)
SET @exist_type := (SELECT COUNT(*) FROM information_schema.columns 
                    WHERE table_schema = DATABASE() 
                    AND table_name = 'companies' 
                    AND column_name = 'type');
SET @sql_type := IF(@exist_type = 0, 
    'ALTER TABLE companies ADD COLUMN type ENUM("commissionnaire", "transporteur", "hybrid") DEFAULT "hybrid" AFTER status',
    'SELECT "Column type already exists" as message');
PREPARE stmt FROM @sql_type;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Zone de couverture (JSON)
SET @exist_zone := (SELECT COUNT(*) FROM information_schema.columns 
                    WHERE table_schema = DATABASE() 
                    AND table_name = 'companies' 
                    AND column_name = 'zone_coverage');
SET @sql_zone := IF(@exist_zone = 0, 
    'ALTER TABLE companies ADD COLUMN zone_coverage VARCHAR(200) NULL AFTER type',
    'SELECT "Column zone_coverage already exists" as message');
PREPARE stmt FROM @sql_zone;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Commission par défaut
SET @exist_comm := (SELECT COUNT(*) FROM information_schema.columns 
                    WHERE table_schema = DATABASE() 
                    AND table_name = 'companies' 
                    AND column_name = 'default_commission_rate');
SET @sql_comm := IF(@exist_comm = 0, 
    'ALTER TABLE companies ADD COLUMN default_commission_rate DECIMAL(5,2) DEFAULT 10.00 AFTER zone_coverage',
    'SELECT "Column default_commission_rate already exists" as message');
PREPARE stmt FROM @sql_comm;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Slug pour URL
SET @exist_slug := (SELECT COUNT(*) FROM information_schema.columns 
                    WHERE table_schema = DATABASE() 
                    AND table_name = 'companies' 
                    AND column_name = 'slug');
SET @sql_slug := IF(@exist_slug = 0, 
    'ALTER TABLE companies ADD COLUMN slug VARCHAR(100) UNIQUE AFTER name',
    'SELECT "Column slug already exists" as message');
PREPARE stmt FROM @sql_slug;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Description
SET @exist_desc := (SELECT COUNT(*) FROM information_schema.columns 
                    WHERE table_schema = DATABASE() 
                    AND table_name = 'companies' 
                    AND column_name = 'description');
SET @sql_desc := IF(@exist_desc = 0, 
    'ALTER TABLE companies ADD COLUMN description TEXT NULL AFTER slug',
    'SELECT "Column description already exists" as message');
PREPARE stmt FROM @sql_desc;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- IFU (tax_id)
SET @exist_tax := (SELECT COUNT(*) FROM information_schema.columns 
                   WHERE table_schema = DATABASE() 
                   AND table_name = 'companies' 
                   AND column_name = 'tax_id');
SET @sql_tax := IF(@exist_tax = 0, 
    'ALTER TABLE companies ADD COLUMN tax_id VARCHAR(50) NULL AFTER registre_commerce',
    'SELECT "Column tax_id already exists" as message');
PREPARE stmt FROM @sql_tax;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Ville
SET @exist_city := (SELECT COUNT(*) FROM information_schema.columns 
                    WHERE table_schema = DATABASE() 
                    AND table_name = 'companies' 
                    AND column_name = 'city');
SET @sql_city := IF(@exist_city = 0, 
    'ALTER TABLE companies ADD COLUMN city VARCHAR(50) NULL AFTER address',
    'SELECT "Column city already exists" as message');
PREPARE stmt FROM @sql_city;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================
-- 2. AJOUTER company_id à users
-- =====================================================
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

-- Foreign key users → companies
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
    order_id INT NOT NULL,
    from_company_id INT,
    to_company_id INT,
    assigned_by INT,
    assigned_livreur_id INT,
    client_price INT,
    subcontract_price INT,
    commission_rate DECIMAL(5,2) DEFAULT 10.00,
    commission_amount INT GENERATED ALWAYS AS (ROUND(client_price * commission_rate / 100)) STORED,
    status ENUM('pending', 'accepted', 'rejected', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
    dispatcher_notes TEXT,
    rejection_reason TEXT,
    assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    accepted_at DATETIME NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (from_company_id) REFERENCES companies(id) ON DELETE SET NULL,
    FOREIGN KEY (to_company_id) REFERENCES companies(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_livreur_id) REFERENCES users(id) ON DELETE SET NULL,
    
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
-- 5. METTRE À JOUR LES ENTREPRISES EXISTANTES
-- =====================================================
UPDATE companies SET type = 'hybrid' WHERE type IS NULL;
UPDATE companies SET default_commission_rate = 10.00 WHERE default_commission_rate IS NULL;

-- Générer des slugs pour les entreprises existantes
UPDATE companies SET slug = LOWER(REPLACE(REPLACE(name, ' ', '-'), '\'', '')) WHERE slug IS NULL;

-- =====================================================
-- VÉRIFICATION
-- =====================================================
SELECT 'Migration update_companies_system completed successfully' as status;
