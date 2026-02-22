-- Migration: Ajouter le tracking des coûts Maps API
-- Date: 2026-02-16
-- Description: Ajoute le champ maps_api_cost à la table orders et les paramètres de tarification Maps

USE colixpress_db;

-- Ajouter le champ maps_api_cost dans orders
ALTER TABLE orders ADD COLUMN IF NOT EXISTS maps_api_cost INT DEFAULT 0 COMMENT 'Coût utilisation API Maps en XAF' AFTER price;

-- Ajouter les paramètres de tarification Maps dans settings
INSERT INTO settings (setting_key, setting_value, category, description, is_public) VALUES
('maps_cost_autocomplete', '10', 'maps', 'Coût par recherche autocomplete (XAF)', 1),
('maps_cost_geocode', '15', 'Coût par géocodage d''adresse (XAF)', 'maps', 1),
('maps_cost_directions', '20', 'Coût par calcul d''itinéraire (XAF)', 'maps', 1),
('maps_cost_place_details', '15', 'Coût par détail de lieu (XAF)', 'maps', 1)
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
