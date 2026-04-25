<?php

namespace App\Models;

use App\Core\Model;
use PDO;

class PaymentProvider extends Model
{
    protected string $table = 'payment_providers';

    /**
     * Récupérer tous les providers actifs
     */
    public function getActive(): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM {$this->table}
            WHERE is_active = 1
            ORDER BY name ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Récupérer les providers disponibles pour un pays
     */
    public function getByCountry(int $countryId, bool $activeOnly = true): array
    {
        $activeFilter = $activeOnly ? 'AND pp.is_active = 1 AND ppc.is_active = 1' : '';
        
        $stmt = $this->db->prepare("
            SELECT pp.*, ppc.is_default, ppc.display_order
            FROM {$this->table} pp
            JOIN payment_provider_countries ppc ON ppc.provider_id = pp.id
            WHERE ppc.country_id = :country_id
            {$activeFilter}
            ORDER BY ppc.display_order ASC, pp.name ASC
        ");
        $stmt->execute(['country_id' => $countryId]);
        return $stmt->fetchAll();
    }

    /**
     * Récupérer le provider par défaut d'un pays
     */
    public function getDefaultByCountry(int $countryId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT pp.*
            FROM {$this->table} pp
            JOIN payment_provider_countries ppc ON ppc.provider_id = pp.id
            WHERE ppc.country_id = :country_id
              AND ppc.is_default = 1
              AND pp.is_active = 1
              AND ppc.is_active = 1
            LIMIT 1
        ");
        $stmt->execute(['country_id' => $countryId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Récupérer un provider par son code
     */
    public function findByCode(string $code): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM {$this->table}
            WHERE code = :code
            LIMIT 1
        ");
        $stmt->execute(['code' => $code]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Lier un provider à un pays
     */
    public function linkToCountry(int $providerId, int $countryId, bool $isDefault = false, int $displayOrder = 0): int
    {
        // Si c'est le provider par défaut, retirer le flag des autres
        if ($isDefault) {
            $this->db->prepare("
                UPDATE payment_provider_countries
                SET is_default = 0
                WHERE country_id = :country_id
            ")->execute(['country_id' => $countryId]);
        }

        $stmt = $this->db->prepare("
            INSERT INTO payment_provider_countries (provider_id, country_id, is_default, display_order)
            VALUES (:provider_id, :country_id, :is_default, :display_order)
            ON DUPLICATE KEY UPDATE
                is_default = VALUES(is_default),
                display_order = VALUES(display_order),
                is_active = 1
        ");
        $stmt->execute([
            'provider_id' => $providerId,
            'country_id' => $countryId,
            'is_default' => (int) $isDefault,
            'display_order' => $displayOrder,
        ]);
        
        return (int) $this->db->lastInsertId();
    }

    /**
     * Délier un provider d'un pays
     */
    public function unlinkFromCountry(int $providerId, int $countryId): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM payment_provider_countries
            WHERE provider_id = :provider_id AND country_id = :country_id
        ");
        return $stmt->execute([
            'provider_id' => $providerId,
            'country_id' => $countryId,
        ]);
    }

    /**
     * Définir un provider comme défaut pour un pays
     */
    public function setAsDefault(int $providerId, int $countryId): bool
    {
        // Retirer le flag des autres
        $this->db->prepare("
            UPDATE payment_provider_countries
            SET is_default = 0
            WHERE country_id = :country_id
        ")->execute(['country_id' => $countryId]);

        // Définir le nouveau par défaut
        $stmt = $this->db->prepare("
            UPDATE payment_provider_countries
            SET is_default = 1
            WHERE provider_id = :provider_id AND country_id = :country_id
        ");
        return $stmt->execute([
            'provider_id' => $providerId,
            'country_id' => $countryId,
        ]);
    }

    /**
     * Récupérer les pays liés à un provider
     */
    public function getLinkedCountries(int $providerId): array
    {
        $stmt = $this->db->prepare("
            SELECT c.*, ppc.is_default, ppc.display_order, ppc.is_active
            FROM countries c
            JOIN payment_provider_countries ppc ON ppc.country_id = c.id
            WHERE ppc.provider_id = :provider_id
            ORDER BY c.name ASC
        ");
        $stmt->execute(['provider_id' => $providerId]);
        return $stmt->fetchAll();
    }

    /**
     * Décoder la config JSON
     */
    public function decodeExtraConfig(?string $extraConfig): ?array
    {
        if (!$extraConfig) {
            return null;
        }
        return json_decode($extraConfig, true);
    }

    /**
     * Encoder la config JSON
     */
    public function encodeExtraConfig(?array $config): ?string
    {
        if (!$config) {
            return null;
        }
        return json_encode($config, JSON_UNESCAPED_UNICODE);
    }
}
