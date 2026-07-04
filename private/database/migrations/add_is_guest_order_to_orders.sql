-- Migration: Ajouter la colonne is_guest_order à la table orders
-- Date: 2026-07-04
-- Description: Permet de distinguer les commandes passées par des invités

ALTER TABLE orders
ADD COLUMN is_guest_order TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 si la commande a été passée par un invité';

-- Index pour optimiser les requêtes sur les commandes invité
ALTER TABLE orders
ADD INDEX idx_orders_guest (is_guest_order);
