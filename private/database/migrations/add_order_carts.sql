-- Migration: order_carts
CREATE TABLE IF NOT EXISTS order_carts (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    reference   VARCHAR(20)  NOT NULL UNIQUE,
    client_id   INT          NOT NULL,
    status      ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    notes       TEXT         NULL,
    created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Ajouter cart_id dans orders
ALTER TABLE orders
    ADD COLUMN cart_id INT NULL AFTER client_id,
    ADD CONSTRAINT fk_orders_cart FOREIGN KEY (cart_id) REFERENCES order_carts(id) ON DELETE SET NULL;
