-- ============================================
-- TIPS - Système d'aide contextuelle
-- ============================================

-- Table des tips
CREATE TABLE IF NOT EXISTS tips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page_route VARCHAR(100) NOT NULL COMMENT 'ex: /home, /orders/create, /tracking',
    tip_key VARCHAR(50) NOT NULL UNIQUE COMMENT 'identifiant unique ex: welcome_home',
    title VARCHAR(200) NULL,
    html_content TEXT NOT NULL COMMENT 'HTML complet avec CSS/JS inline',
    frequency ENUM('once','session','always','infinite') DEFAULT 'once' COMMENT 'once: 1 fois, session: chaque session, always/infinite: à chaque visite',
    priority INT DEFAULT 0 COMMENT 'ordre d affichage si plusieurs tips sur même page',
    target_roles JSON NULL COMMENT '["client","livreur","admin"] - null = tous les rôles',
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_page_route (page_route, is_active),
    INDEX idx_tip_key (tip_key),
    INDEX idx_priority (priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table de tracking: quels tips ont été vus par quels utilisateurs
CREATE TABLE IF NOT EXISTS user_tips_seen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    tip_id INT NOT NULL,
    seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    dismissed TINYINT(1) DEFAULT 0 COMMENT 'user a cliqué sur X pour fermer',
    completed TINYINT(1) DEFAULT 0 COMMENT 'user a terminé le tour complet',
    
    UNIQUE KEY unique_user_tip (user_id, tip_id),
    INDEX idx_user_id (user_id),
    INDEX idx_tip_id (tip_id),
    
    CONSTRAINT fk_user_tips_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_tips_tip FOREIGN KEY (tip_id) REFERENCES tips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- EXEMPLES DE TIPS
-- ============================================

-- Tip pour la page d'accueil client
INSERT INTO tips (page_route, tip_key, title, html_content, frequency, priority, target_roles) VALUES
('/home', 'welcome_client', 'Bienvenue', 
'<div style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:10000;display:flex;align-items:center;justify-content:center;">
  <div style="background:white;padding:24px;border-radius:16px;max-width:320px;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,0.3);">
    <h3 style="margin:0 0 12px 0;color:#333;">Bienvenue sur ColiXpress ! 👋</h3>
    <p style="margin:0 0 20px 0;color:#666;line-height:1.5;">Créez votre première livraison en un clic</p>
    <button onclick="this.closest(&#39;div&#39;).parentElement.remove();localStorage.setItem(&#39;tip_welcome_client&#39;,&#39;seen&#39;)" 
            style="background:#4F46E5;color:white;border:none;padding:12px 24px;border-radius:8px;cursor:pointer;font-weight:600;">
      J&#39;ai compris
    </button>
  </div>
</div>', 
'once', 1, '["client"]');

-- Tip pour la création de commande
INSERT INTO tips (page_route, tip_key, title, html_content, frequency, priority, target_roles) VALUES
('/orders/create', 'new_order_help', 'Nouvelle commande',
'<div style="position:fixed;top:20px;right:20px;background:#10B981;color:white;padding:16px 20px;border-radius:12px;z-index:10000;max-width:280px;box-shadow:0 4px 12px rgba(0,0,0,0.15);">
  <div style="font-weight:600;margin-bottom:8px;">💡 Astuce</div>
  <div style="font-size:14px;line-height:1.4;">Remplissez l&#39;adresse de retrait et l&#39;adresse de livraison pour voir le prix estimé.</div>
  <button onclick="this.parentElement.remove()" style="position:absolute;top:8px;right:12px;background:none;border:none;color:white;font-size:18px;cursor:pointer;">×</button>
</div>',
'session', 1, '["client"]');

-- Tip pour les livreurs sur la page des commandes disponibles
INSERT INTO tips (page_route, tip_key, title, html_content, frequency, priority, target_roles) VALUES
('/livreur/orders', 'accept_order_tip', 'Accepter une commande',
'<div style="position:fixed;bottom:100px;left:50%;transform:translateX(-50%);background:#F59E0B;color:white;padding:16px 24px;border-radius:50px;z-index:10000;white-space:nowrap;box-shadow:0 4px 12px rgba(0,0,0,0.2);">
  <span style="margin-right:12px;">👆 Swipez vers la droite pour accepter une commande</span>
  <button onclick="this.parentElement.remove()" style="background:rgba(255,255,255,0.2);border:none;color:white;width:28px;height:28px;border-radius:50%;cursor:pointer;margin-left:8px;">×</button>
</div>',
'once', 1, '["livreur"]');

-- Tip qui s'affiche TOUJOURS (à chaque visite) - ex: promotion temporaire
INSERT INTO tips (page_route, tip_key, title, html_content, frequency, priority, target_roles) VALUES
('/home', 'promo_flash', 'Promo Flash',
'<div style="position:fixed;top:80px;left:20px;right:20px;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:white;padding:20px;border-radius:16px;z-index:10000;text-align:center;box-shadow:0 8px 32px rgba(0,0,0,0.3);">
  <div style="font-size:20px;font-weight:700;margin-bottom:8px;">🔥 Promo Flash -50%</div>
  <div style="font-size:14px;margin-bottom:16px;">Sur toutes vos livraisons aujourd&#39;hui uniquement !</div>
  <button onclick="this.parentElement.remove()" style="background:white;color:#764ba2;border:none;padding:10px 24px;border-radius:8px;font-weight:600;cursor:pointer;">Profiter</button>
</div>',
'infinite', 2, '["client"]');
