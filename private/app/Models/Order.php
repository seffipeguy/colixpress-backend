<?php

namespace App\Models;

use App\Core\Model;
use PDO;

class Order extends Model
{
    protected string $table = 'orders';

    public function generateReference(): string
    {
        $prefix = 'COL';
        $year = date('Y');
        $random = strtoupper(bin2hex(random_bytes(3)));
        $ref = "{$prefix}-{$year}-{$random}";

        // Ensure uniqueness
        while ($this->findBy('reference', $ref)) {
            $random = strtoupper(bin2hex(random_bytes(3)));
            $ref = "{$prefix}-{$year}-{$random}";
        }

        return $ref;
    }

    public function findByReference(string $reference): ?array
    {
        return $this->findBy('reference', $reference);
    }

    public function getByClient(int $clientId, int $page, int $perPage, ?string $status = null): array
    {
        $where = 'client_id = :cid';
        $params = ['cid' => $clientId];

        if ($status) {
            $where .= ' AND status = :status';
            $params['status'] = $status;
        }

        return $this->paginate($page, $perPage, $where, $params);
    }

    public function getByLivreur(int $livreurId, int $page, int $perPage, ?string $status = null): array
    {
        $where = 'livreur_id = :lid';
        $params = ['lid' => $livreurId];

        if ($status) {
            $where .= ' AND status = :status';
            $params['status'] = $status;
        }

        return $this->paginate($page, $perPage, $where, $params);
    }

    public function getByShop(int $shopId, int $page, int $perPage): array
    {
        return $this->paginate($page, $perPage, 'shop_id = :sid', ['sid' => $shopId]);
    }

    public function getPending(int $page, int $perPage): array
    {
        return $this->paginate($page, $perPage, "status = 'pending'", []);
    }

    public function findWithDetails(int $id): ?array
    {
        return $this->findWithDetailsBy('o.id', $id);
    }

    public function findWithDetailsByReference(string $reference): ?array
    {
        return $this->findWithDetailsBy('o.reference', $reference);
    }

    private function findWithDetailsBy(string $field, mixed $value): ?array
    {
        $stmt = $this->db->prepare("
            SELECT o.*,
                   uc.first_name AS client_first_name, uc.last_name AS client_last_name, uc.phone AS client_phone,
                   ul.first_name AS livreur_first_name, ul.last_name AS livreur_last_name, ul.phone AS livreur_phone,
                   s.name AS shop_name
            FROM {$this->table} o
            JOIN users uc ON uc.id = o.client_id
            LEFT JOIN users ul ON ul.id = o.livreur_id
            LEFT JOIN shops s ON s.id = o.shop_id
            WHERE {$field} = :val
            LIMIT 1
        ");
        $stmt->execute(['val' => $value]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function updateStatus(int $id, string $status, ?int $changedBy = null, ?string $comment = null): bool
    {
        $data = ['status' => $status];

        switch ($status) {
            case 'accepted':
                $data['accepted_at'] = date('Y-m-d H:i:s');
                break;
            case 'picked_up':
                $data['picked_up_at'] = date('Y-m-d H:i:s');
                break;
            case 'delivered':
                $data['delivered_at'] = date('Y-m-d H:i:s');
                break;
            case 'cancelled':
                $data['cancelled_at'] = date('Y-m-d H:i:s');
                break;
        }

        $this->update($id, $data);

        // Log to history
        $historyModel = new OrderStatusHistory();
        $historyModel->create([
            'order_id'   => $id,
            'status'     => $status,
            'comment'    => $comment,
            'changed_by' => $changedBy,
        ]);

        return true;
    }

    public function calculatePrice(float $distanceKm, string $city = 'Douala', ?string $packageSize = null, ?float $weightKg = null, int $packageValue = 0): array
    {
        $pricing = new PricingRule();
        $rule = $pricing->getForCity($city);
        $settings = new Setting();

        if (!$rule) {
            return ['price' => 1000, 'value_surcharge' => 0];
        }

        // Base + distance
        $price = (int) $rule['base_price'] + (int) round($distanceKm * (int) $rule['price_per_km']);

        // Package size surcharge
        if ($packageSize === 'moyen' && isset($rule['surcharge_moyen'])) {
            $price += (int) $rule['surcharge_moyen'];
        } elseif ($packageSize === 'grand' && isset($rule['surcharge_grand'])) {
            $price += (int) $rule['surcharge_grand'];
        }

        // Weight surcharge
        if ($weightKg && isset($rule['weight_threshold_kg']) && isset($rule['price_per_extra_kg'])) {
            $threshold = (float) $rule['weight_threshold_kg'];
            if ($weightKg > $threshold) {
                $extraKg = $weightKg - $threshold;
                $price += (int) round($extraKg * (int) $rule['price_per_extra_kg']);
            }
        }

        // Surge multiplier (manual override)
        $price = (int) round($price * (float) $rule['surge_multiplier']);

        // Time-based multipliers
        $currentHour = (int) date('H');

        $nightStart = $settings->getInt('night_start_hour', 22);
        $nightEnd   = $settings->getInt('night_end_hour', 6);
        $isNight = ($nightStart > $nightEnd)
            ? ($currentHour >= $nightStart || $currentHour < $nightEnd)
            : ($currentHour >= $nightStart && $currentHour < $nightEnd);

        if ($isNight && isset($rule['night_multiplier']) && (float) $rule['night_multiplier'] > 1) {
            $price = (int) round($price * (float) $rule['night_multiplier']);
        } else {
            // Check peak hours
            $peakStart1 = $settings->getInt('peak_start_hour', 7);
            $peakEnd1   = $settings->getInt('peak_end_hour', 9);
            $peakStart2 = $settings->getInt('peak_start_hour_2', 17);
            $peakEnd2   = $settings->getInt('peak_end_hour_2', 19);

            $isPeak = ($currentHour >= $peakStart1 && $currentHour < $peakEnd1)
                   || ($currentHour >= $peakStart2 && $currentHour < $peakEnd2);

            if ($isPeak && isset($rule['peak_multiplier']) && (float) $rule['peak_multiplier'] > 1) {
                $price = (int) round($price * (float) $rule['peak_multiplier']);
            }
        }

        // Package value surcharge (insurance-style)
        $valueSurcharge = 0;
        if ($packageValue > 0) {
            $valueThreshold = $settings->getInt('package_value_threshold', 10000);
            if ($packageValue > $valueThreshold) {
                $surchargePercent = $settings->getFloat('package_value_surcharge_percent', 3);
                $valueSurcharge = (int) round($packageValue * $surchargePercent / 100);
                $maxSurcharge = $settings->getInt('package_value_max_surcharge', 5000);
                if ($maxSurcharge > 0 && $valueSurcharge > $maxSurcharge) {
                    $valueSurcharge = $maxSurcharge;
                }
                $price += $valueSurcharge;
            }
        }

        // Apply min price
        $price = max($price, (int) $rule['min_price']);

        // Apply max price cap
        if (isset($rule['max_price']) && (int) $rule['max_price'] > 0) {
            $price = min($price, (int) $rule['max_price']);
        }

        return ['price' => $price, 'value_surcharge' => $valueSurcharge];
    }

    public function getFrequentPlaces(int $userId, int $limit = 5): array
    {
        // Frequent pickup addresses
        $stmt = $this->db->prepare("
            SELECT pickup_address AS address,
                   pickup_lat AS latitude,
                   pickup_lng AS longitude,
                   pickup_contact_name AS contact_name,
                   pickup_contact_phone AS contact_phone,
                   COUNT(*) AS usage_count,
                   MAX(created_at) AS last_used
            FROM {$this->table}
            WHERE client_id = :uid AND pickup_address IS NOT NULL AND pickup_address != ''
            GROUP BY pickup_address, pickup_lat, pickup_lng, pickup_contact_name, pickup_contact_phone
            ORDER BY usage_count DESC, last_used DESC
            LIMIT :lim
        ");
        $stmt->bindValue('uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $pickups = $stmt->fetchAll();

        // Frequent dropoff addresses
        $stmt = $this->db->prepare("
            SELECT dropoff_address AS address,
                   dropoff_lat AS latitude,
                   dropoff_lng AS longitude,
                   dropoff_contact_name AS contact_name,
                   dropoff_contact_phone AS contact_phone,
                   COUNT(*) AS usage_count,
                   MAX(created_at) AS last_used
            FROM {$this->table}
            WHERE client_id = :uid AND dropoff_address IS NOT NULL AND dropoff_address != ''
            GROUP BY dropoff_address, dropoff_lat, dropoff_lng, dropoff_contact_name, dropoff_contact_phone
            ORDER BY usage_count DESC, last_used DESC
            LIMIT :lim
        ");
        $stmt->bindValue('uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $dropoffs = $stmt->fetchAll();

        return [
            'pickup'  => $pickups,
            'dropoff' => $dropoffs,
        ];
    }

    public function getFrequentShops(int $userId, int $limit = 5): array
    {
        $stmt = $this->db->prepare("
            SELECT s.id, s.name, s.address, s.latitude, s.longitude, s.phone,
                   s.logo, s.cover_photo,
                   sc.name AS category_name,
                   COUNT(o.id) AS order_count,
                   SUM(o.price) AS total_spent,
                   MAX(o.created_at) AS last_order_at
            FROM {$this->table} o
            JOIN shops s ON s.id = o.shop_id
            LEFT JOIN shop_category_map scm ON scm.shop_id = s.id
            LEFT JOIN shop_categories sc ON sc.id = scm.category_id
            WHERE o.client_id = :uid AND o.shop_id IS NOT NULL
            GROUP BY s.id, s.name, s.address, s.latitude, s.longitude, s.phone,
                     s.logo, s.cover_photo, sc.name
            ORDER BY order_count DESC, last_order_at DESC
            LIMIT :lim
        ");
        $stmt->bindValue('uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function calculateCommission(int $price, bool $isDeveloperOrder = false): int
    {
        $settings = new Setting();
        $percent = $isDeveloperOrder
            ? $settings->getFloat('developer_commission_percent', 5)
            : $settings->getFloat('commission_percent', 10);
        return (int) round($price * $percent / 100);
    }
}
