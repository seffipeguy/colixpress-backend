<?php

namespace App\Models;

use App\Core\Model;
use App\Core\Database;

/**
 * Version simplifiée du système de tips
 * Évite les problèmes de collation JSON
 */
class TipSimple extends Model
{
    protected string $table = 'tips';

    /**
     * Récupérer les tips pour une page - approche simple
     * 1. Récupère tous les tips actifs de la page
     * 2. Filtre par rôle en PHP (pas en SQL)
     * 3. Filtre par historique utilisateur
     */
    public function getForPage(string $pageRoute, int $userId, ?string $userRole = null): array
    {
        $db = Database::getInstance();

        // Étape 1: Récupérer tous les tips actifs pour cette page (requête simple)
        $stmt = $db->prepare("
            SELECT id, tip_key, title, html_content, frequency, priority, target_roles
            FROM {$this->table}
            WHERE page_route = :page_route
            AND is_active = 1
            ORDER BY priority ASC
        ");
        $stmt->execute(['page_route' => $pageRoute]);
        $allTips = $stmt->fetchAll();

        $result = [];

        foreach ($allTips as $tip) {
            // Étape 2: Vérifier le rôle (en PHP, pas SQL)
            if (!$this->roleMatches($tip['target_roles'], $userRole)) {
                continue;
            }

            // Étape 3: Vérifier si déjà vu selon fréquence
            if (!$this->shouldShowToUser($tip, $userId)) {
                continue;
            }

            $result[] = $tip;
        }

        return $result;
    }

    /**
     * Vérifier si le rôle correspond (PHP pur, pas de JSON SQL)
     */
    private function roleMatches(?string $targetRolesJson, ?string $userRole): bool
    {
        // Pas de restriction = tout le monde peut voir
        if (empty($targetRolesJson)) {
            return true;
        }

        // Si pas de rôle utilisateur spécifié mais restriction existe = ne pas montrer
        if ($userRole === null) {
            return false;
        }

        $targetRoles = json_decode($targetRolesJson, true);
        
        // Si JSON invalide = ne pas montrer
        if (!is_array($targetRoles)) {
            return false;
        }

        return in_array($userRole, $targetRoles);
    }

    /**
     * Vérifier si le tip doit être affiché (fréquence + historique)
     */
    private function shouldShowToUser(array $tip, int $userId): bool
    {
        $frequency = $tip['frequency'] ?? 'once';

        // 'always' ou 'infinite' = toujours afficher
        if ($frequency === 'always' || $frequency === 'infinite') {
            return true;
        }

        // Vérifier l'historique
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT seen_at FROM user_tips_seen
            WHERE user_id = :user_id AND tip_id = :tip_id
            LIMIT 1
        ");
        $stmt->execute([
            'user_id' => $userId,
            'tip_id' => $tip['id']
        ]);
        $seen = $stmt->fetch();

        // 'once' = afficher si jamais vu
        if ($frequency === 'once') {
            return !$seen;
        }

        // 'session' = afficher si pas vu dans les dernières 24h
        if ($frequency === 'session') {
            if (!$seen) {
                return true;
            }
            $lastSeen = strtotime($seen['seen_at']);
            return (time() - $lastSeen) > (24 * 60 * 60);
        }

        return true;
    }

    /**
     * Marquer un tip comme vu
     */
    public function markAsSeen(int $tipId, int $userId, bool $dismissed = false, bool $completed = false): bool
    {
        $db = Database::getInstance();

        // Vérifier si déjà existant
        $stmt = $db->prepare("
            SELECT id FROM user_tips_seen
            WHERE user_id = :user_id AND tip_id = :tip_id
            LIMIT 1
        ");
        $stmt->execute(['user_id' => $userId, 'tip_id' => $tipId]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Mettre à jour
            $stmt = $db->prepare("
                UPDATE user_tips_seen
                SET seen_at = NOW(), dismissed = :dismissed, completed = :completed
                WHERE id = :id
            ");
            return $stmt->execute([
                'id' => $existing['id'],
                'dismissed' => $dismissed ? 1 : 0,
                'completed' => $completed ? 1 : 0
            ]);
        }

        // Insérer nouveau
        $stmt = $db->prepare("
            INSERT INTO user_tips_seen (user_id, tip_id, dismissed, completed)
            VALUES (:user_id, :tip_id, :dismissed, :completed)
        ");
        return $stmt->execute([
            'user_id' => $userId,
            'tip_id' => $tipId,
            'dismissed' => $dismissed ? 1 : 0,
            'completed' => $completed ? 1 : 0
        ]);
    }

    /**
     * Récupérer l'historique des tips vus par un utilisateur
     */
    public function getSeenByUser(int $userId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT uts.*, t.tip_key, t.title, t.page_route
            FROM user_tips_seen uts
            JOIN tips t ON t.id = uts.tip_id
            WHERE uts.user_id = :user_id
            ORDER BY uts.seen_at DESC
        ");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Réinitialiser les tips vus par un utilisateur
     */
    public function resetForUser(int $userId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM user_tips_seen WHERE user_id = :user_id");
        return $stmt->execute(['user_id' => $userId]);
    }
}
