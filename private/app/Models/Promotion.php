<?php

namespace App\Models;

use App\Core\Model;

class Promotion extends Model
{
    protected string $table = 'promotions';

    public function findByCode(string $code): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE code = :code LIMIT 1");
        $stmt->execute(['code' => strtoupper(trim($code))]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function validate(string $code, int $userId, int $orderAmount, ?string $city = null): array
    {
        $promo = $this->findByCode($code);

        if (!$promo) {
            return ['valid' => false, 'error' => 'Code promo invalide'];
        }

        if (!$promo['is_active']) {
            return ['valid' => false, 'error' => 'Code promo inactif'];
        }

        $now = date('Y-m-d H:i:s');
        if ($promo['valid_from'] && $now < $promo['valid_from']) {
            return ['valid' => false, 'error' => 'Code promo pas encore valide'];
        }
        if ($promo['valid_until'] && $now > $promo['valid_until']) {
            return ['valid' => false, 'error' => 'Code promo expiré'];
        }

        if ($promo['max_uses'] > 0 && $promo['used_count'] >= $promo['max_uses']) {
            return ['valid' => false, 'error' => 'Code promo épuisé'];
        }

        if ($promo['min_order_amount'] > 0 && $orderAmount < $promo['min_order_amount']) {
            return ['valid' => false, 'error' => 'Montant minimum requis: ' . $promo['min_order_amount'] . ' XAF'];
        }

        // Check per-user limit
        if ($promo['max_uses_per_user'] > 0) {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as cnt FROM promotion_uses 
                WHERE promotion_id = :promo_id AND user_id = :user_id
            ");
            $stmt->execute(['promo_id' => $promo['id'], 'user_id' => $userId]);
            $userUses = (int) $stmt->fetch()['cnt'];
            if ($userUses >= $promo['max_uses_per_user']) {
                return ['valid' => false, 'error' => 'Vous avez déjà utilisé ce code promo'];
            }
        }

        // Check city restriction
        if (!empty($promo['applicable_cities'])) {
            $cities = array_map('trim', explode(',', strtolower($promo['applicable_cities'])));
            if ($city && !in_array(strtolower($city), $cities)) {
                return ['valid' => false, 'error' => 'Code promo non valide pour cette ville'];
            }
        }

        // Calculate discount
        $discount = $this->calculateDiscount($promo, $orderAmount);

        return [
            'valid'       => true,
            'promotion'   => $promo,
            'discount'    => $discount,
        ];
    }

    public function calculateDiscount(array $promo, int $orderAmount): int
    {
        if ($promo['discount_type'] === 'percent') {
            $discount = (int) round($orderAmount * $promo['discount_value'] / 100);
        } else {
            $discount = (int) $promo['discount_value'];
        }

        // Apply max discount cap
        if ($promo['max_discount'] > 0 && $discount > $promo['max_discount']) {
            $discount = (int) $promo['max_discount'];
        }

        // Cannot exceed order amount
        if ($discount > $orderAmount) {
            $discount = $orderAmount;
        }

        return $discount;
    }

    public function recordUse(int $promoId, int $userId, int $orderId, int $discountApplied): void
    {
        $this->db->prepare("
            INSERT INTO promotion_uses (promotion_id, user_id, order_id, discount_applied)
            VALUES (:promo_id, :user_id, :order_id, :discount)
        ")->execute([
            'promo_id' => $promoId,
            'user_id'  => $userId,
            'order_id' => $orderId,
            'discount' => $discountApplied,
        ]);

        // Increment used_count
        $this->db->prepare("UPDATE {$this->table} SET used_count = used_count + 1 WHERE id = :id")
            ->execute(['id' => $promoId]);
    }

    public function getActive(): array
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare("
            SELECT * FROM {$this->table}
            WHERE is_active = 1
              AND (valid_from IS NULL OR valid_from <= :now1)
              AND (valid_until IS NULL OR valid_until >= :now2)
            ORDER BY created_at DESC
        ");
        $stmt->execute(['now1' => $now, 'now2' => $now]);
        return $stmt->fetchAll();
    }

    public function getStats(int $promoId): array
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as total_uses, SUM(discount_applied) as total_discount
            FROM promotion_uses WHERE promotion_id = :id
        ");
        $stmt->execute(['id' => $promoId]);
        return $stmt->fetch();
    }
}
