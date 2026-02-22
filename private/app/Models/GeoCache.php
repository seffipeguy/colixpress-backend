<?php

namespace App\Models;

use App\Core\Model;
use PDO;

class GeoCache extends Model
{
    protected string $table = 'geocache';

    // Durées de cache par type (en jours)
    private const CACHE_DURATIONS = [
        'autocomplete'    => 30,
        'geocode'         => 90,
        'reverse_geocode' => 90,
        'directions'      => 3,
        'distance'        => 3,
    ];

    /**
     * Générer une clé de cache unique
     */
    public static function buildKey(string $type, string $input, array $extra = []): string
    {
        $raw = $type . ':' . mb_strtolower(trim($input));
        if (!empty($extra)) {
            ksort($extra);
            $raw .= ':' . json_encode($extra);
        }
        return hash('sha256', $raw);
    }

    /**
     * Chercher dans le cache (non expiré)
     */
    public function lookup(string $cacheKey): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM {$this->table}
            WHERE cache_key = :key AND expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute(['key' => $cacheKey]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        // Incrémenter le compteur de hits
        $this->db->prepare("
            UPDATE {$this->table} SET hit_count = hit_count + 1 WHERE id = :id
        ")->execute(['id' => $row['id']]);

        return json_decode($row['response_data'], true);
    }

    /**
     * Stocker un résultat en cache
     */
    public function store(string $type, string $cacheKey, string $queryInput, array $responseData): void
    {
        $days = self::CACHE_DURATIONS[$type] ?? 7;
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$days} days"));

        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (cache_type, cache_key, query_input, response_data, expires_at)
            VALUES (:type, :key, :input, :data, :expires)
            ON DUPLICATE KEY UPDATE
                response_data = VALUES(response_data),
                expires_at = VALUES(expires_at),
                hit_count = 0,
                updated_at = NOW()
        ");
        $stmt->execute([
            'type'    => $type,
            'key'     => $cacheKey,
            'input'   => $queryInput,
            'data'    => json_encode($responseData, JSON_UNESCAPED_UNICODE),
            'expires' => $expiresAt,
        ]);
    }

    /**
     * Purger les entrées expirées
     */
    public function purgeExpired(): int
    {
        $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE expires_at <= NOW()");
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Statistiques du cache
     */
    public function stats(): array
    {
        $stmt = $this->db->query("
            SELECT 
                cache_type,
                COUNT(*) as total_entries,
                SUM(hit_count) as total_hits,
                SUM(CASE WHEN expires_at > NOW() THEN 1 ELSE 0 END) as active_entries,
                SUM(CASE WHEN expires_at <= NOW() THEN 1 ELSE 0 END) as expired_entries
            FROM {$this->table}
            GROUP BY cache_type
        ");
        return $stmt->fetchAll();
    }
}
