-- Migration: Ajouter le créneau horaire de ramassage
-- Date: 2026-02-17
-- Description: Ajoute le champ pickup_scheduled_at pour permettre au client de spécifier l'heure souhaitée pour le ramassage

USE colixpress_db;

-- Ajouter le champ pickup_scheduled_at dans orders
ALTER TABLE orders 
ADD COLUMN pickup_scheduled_at DATETIME NULL COMMENT 'Créneau horaire souhaité pour le ramassage' 
AFTER notes;

-- Mettre à jour le commentaire de scheduled_at pour clarifier
ALTER TABLE orders 
MODIFY COLUMN scheduled_at DATETIME NULL COMMENT 'Créneau horaire souhaité pour la livraison';
