SET FOREIGN_KEY_CHECKS = 0;

-- 1. Nettoyage
DROP TABLE IF EXISTS order_items; -- Les commandes historiques ne pourront plus référencer les items
DROP TABLE IF EXISTS shop_items;

-- 2. Mise à jour de la table shops
ALTER TABLE shops 
    ADD COLUMN website_url VARCHAR(500) AFTER description,
    ADD COLUMN short_description VARCHAR(255) AFTER name,
    ADD COLUMN permissions LONGTEXT AFTER website_url, -- JSON
    DROP COLUMN category_id; -- On passe en Many-to-Many

-- 3. Mise à jour des catégories (Hiérarchie)
ALTER TABLE shop_categories 
    ADD COLUMN parent_id INT DEFAULT NULL AFTER id,
    ADD COLUMN slug VARCHAR(100) AFTER name,
    ADD COLUMN description TEXT AFTER name;

-- 4. Table Pivot Shops <-> Catégories
CREATE TABLE shop_category_map (
    shop_id INT NOT NULL,
    category_id INT NOT NULL,
    PRIMARY KEY (shop_id, category_id),
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES shop_categories(id) ON DELETE CASCADE
);

-- 5. Système de Tags (Services/Produits offerts)
CREATE TABLE shop_tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    icon VARCHAR(255) DEFAULT NULL
);

CREATE TABLE shop_tag_map (
    shop_id INT NOT NULL,
    tag_id INT NOT NULL,
    PRIMARY KEY (shop_id, tag_id),
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES shop_tags(id) ON DELETE CASCADE
);

SET FOREIGN_KEY_CHECKS = 1;
