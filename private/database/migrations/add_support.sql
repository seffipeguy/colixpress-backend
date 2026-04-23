-- ============================================
-- Migration: Support Tickets System
-- Date: 2025-04-19
-- ============================================

-- 1. Table support_tickets
CREATE TABLE support_tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(20) NOT NULL UNIQUE,
    created_by INT NOT NULL,
    assigned_to INT NULL COMMENT 'Agent admin assigné',
    subject VARCHAR(255) NOT NULL,
    order_reference VARCHAR(20) NULL COMMENT 'Commande liée si applicable',
    category ENUM('livraison','paiement','compte','autre') DEFAULT 'autre',
    status ENUM('open','in_progress','closed') DEFAULT 'open',
    priority ENUM('low','normal','high') DEFAULT 'normal',
    closed_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ticket_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_agent FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_ticket_user (created_by, status),
    INDEX idx_ticket_status (status, created_at),
    INDEX idx_ticket_order (order_reference)
) ENGINE=InnoDB;

-- 2. Table support_messages
CREATE TABLE support_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    sender_id INT NOT NULL,
    message TEXT NOT NULL,
    is_from_agent TINYINT(1) DEFAULT 0 COMMENT '1 si envoyé par un admin/agent',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_msg_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_msg_ticket (ticket_id, created_at)
) ENGINE=InnoDB;
