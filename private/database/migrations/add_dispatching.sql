-- ============================================================
-- Migration : Système d'affrètement / dispatching
-- ============================================================

-- 1. country_id existe déjà dans companies (skipped)

-- 2. Membres d'une entreprise (dispatchers, managers, livreurs rattachés)
CREATE TABLE company_users (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    company_id   INT NOT NULL,
    user_id      INT NOT NULL,
    role         ENUM('manager', 'dispatcher', 'livreur') NOT NULL DEFAULT 'dispatcher',
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_company_user (company_id, user_id),
    CONSTRAINT fk_cu_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_cu_user    FOREIGN KEY (user_id)    REFERENCES users(id)      ON DELETE CASCADE
);

-- 3. company_id existe déjà dans livreur_profiles (skipped)

-- 4. Colonnes dispatching sur orders
ALTER TABLE orders
    ADD COLUMN claimed_by        INT NULL AFTER status,
    ADD COLUMN claimed_at        DATETIME NULL AFTER claimed_by,
    ADD COLUMN dispatcher_notes  TEXT NULL AFTER claimed_at,
    ADD CONSTRAINT fk_orders_claimed_by FOREIGN KEY (claimed_by) REFERENCES users(id) ON DELETE SET NULL;

-- 5. Historique des claims (audit trail)
CREATE TABLE order_claims (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    order_id       INT NOT NULL,
    claimed_by     INT NOT NULL,
    claimed_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    released_by    INT NULL,
    released_at    DATETIME NULL,
    release_reason VARCHAR(255) NULL,
    CONSTRAINT fk_oc_order       FOREIGN KEY (order_id)    REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_oc_claimed_by  FOREIGN KEY (claimed_by)  REFERENCES users(id)  ON DELETE CASCADE,
    CONSTRAINT fk_oc_released_by FOREIGN KEY (released_by) REFERENCES users(id)  ON DELETE SET NULL
);

-- 6. Assignations dispatcher → livreur
CREATE TABLE dispatcher_assignments (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    order_id    INT NOT NULL,
    livreur_id  INT NOT NULL,
    assigned_by INT NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes       TEXT NULL,
    CONSTRAINT fk_da_order       FOREIGN KEY (order_id)    REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_da_livreur     FOREIGN KEY (livreur_id)  REFERENCES users(id)  ON DELETE CASCADE,
    CONSTRAINT fk_da_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id)  ON DELETE CASCADE
);
