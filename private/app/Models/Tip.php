<?php

namespace App\Models;

use App\Core\Model;
use App\Core\Database;

class Tip extends Model
{
    protected string $table = 'tips';

    /**
     * Récupérer les tips actifs pour une page spécifique et un rôle
     * Exclut les tips déjà vus par l'utilisateur (selon la fréquence)
     */
    public function getActiveForPage(string $pageRoute, int $userId, ?string $userRole = null): array
    {
        $db = Database::getInstance();

        // Base query: tips actifs pour cette page
        // Note: on filtre par rôle dans PHP pour éviter les problèmes de collation avec JSON_CONTAINS
        $sql = "
            SELECT t.*
            FROM {$this->table} t
            WHERE t.page_route = :page_route
            AND t.is_active = 1
        ";
        $params = ['page_route' => $pageRoute];

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $allTips = $stmt->fetchAll();

        // Filtrer par rôle si spécifié
        $tips = [];
        foreach ($allTips as $tip) {
            // Si pas de restriction de rôle ou rôle correspondant
            if (empty($tip['target_roles'])) {
                $tips[] = $tip;
            } else {
                $targetRoles = json_decode($tip['target_roles'], true);
                if (is_array($targetRoles) && in_array($userRole, $targetRoles)) {
                    $tips[] = $tip;
                }
            }
        }

        // Filtrer selon la fréquence et l'historique de l'utilisateur
        $filteredTips = [];
        foreach ($tips as $tip) {
            if ($this->shouldShowToUser($tip, $userId)) {
                $filteredTips[] = $tip;
            }
        }

        // Trier par priorité
        usort($filteredTips, fn($a, $b) => $a['priority'] <=> $b['priority']);

        return $filteredTips;
    }

    /**
     * Déterminer si un tip doit être affiché à l'utilisateur
     */
    private function shouldShowToUser(array $tip, int $userId): bool
    {
        // Vérifier que les champs requis existent
        if (!isset($tip['id']) || !isset($tip['frequency'])) {
            return false;
        }

        $frequency = $tip['frequency'];

        // 'always' ou 'infinite' = toujours afficher à chaque visite
        if ($frequency === 'always' || $frequency === 'infinite') {
            return true;
        }

        // Vérifier si l'utilisateur a déjà vu ce tip
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT * FROM user_tips_seen
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

        // 'session' = afficher si pas vu dans la session actuelle
        // Pour l'instant, on considère qu'une session = 24h
        if ($frequency === 'session') {
            if (!$seen) {
                return true;
            }
            // Vérifier si vu il y a plus de 24h
            $lastSeen = strtotime($seen['seen_at']);
            return (time() - $lastSeen) > (24 * 60 * 60);
        }

        return true;
    }

    /**
     * Marquer un tip comme vu par un utilisateur
     */
    public function markAsSeen(int $tipId, int $userId, bool $dismissed = false, bool $completed = false): bool
    {
        $db = Database::getInstance();

        // Vérifier si déjà enregistré
        $stmt = $db->prepare("
            SELECT id FROM user_tips_seen
            WHERE user_id = :user_id AND tip_id = :tip_id
            LIMIT 1
        ");
        $stmt->execute([
            'user_id' => $userId,
            'tip_id' => $tipId
        ]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Mettre à jour
            $stmt = $db->prepare("
                UPDATE user_tips_seen
                SET seen_at = NOW(),
                    dismissed = :dismissed,
                    completed = :completed
                WHERE id = :id
            ");
            return $stmt->execute([
                'id' => $existing['id'],
                'dismissed' => $dismissed ? 1 : 0,
                'completed' => $completed ? 1 : 0
            ]);
        }

        // Créer nouvelle entrée
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
     * Réinitialiser les tips vus par un utilisateur (pour refaire le tour)
     */
    public function resetForUser(int $userId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM user_tips_seen WHERE user_id = :user_id");
        return $stmt->execute(['user_id' => $userId]);
    }
}
