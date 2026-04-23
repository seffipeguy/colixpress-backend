<?php

namespace App\Models;

use App\Core\Model;

class OrderCart extends Model
{
    protected string $table = 'order_carts';

    public function generateReference(): string
    {
        do {
            $ref = 'CART-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        } while ($this->findByReference($ref));
        return $ref;
    }

    public function findByReference(string $reference): ?array
    {
        return $this->findBy('reference', $reference);
    }

    public function getByClient(int $clientId, int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare("
            SELECT * FROM {$this->table}
            WHERE client_id = :cid
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':cid',    $clientId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit',  $perPage,  \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,   \PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll();

        $total = $this->count('client_id = :cid', ['cid' => $clientId]);

        return ['data' => $data, 'total' => $total];
    }

    public function getOrdersWithStats(int $cartId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, reference, status, price, currency, pickup_address, dropoff_address,
                   package_description, package_size, created_at
            FROM orders
            WHERE cart_id = :cart_id
            ORDER BY created_at ASC
        ");
        $stmt->execute(['cart_id' => $cartId]);
        $orders = $stmt->fetchAll();

        $totalPrice  = 0;
        $statusCount = [];
        foreach ($orders as $o) {
            $totalPrice += (float) $o['price'];
            $statusCount[$o['status']] = ($statusCount[$o['status']] ?? 0) + 1;
        }

        return [
            'orders'       => $orders,
            'total_orders' => count($orders),
            'total_price'  => $totalPrice,
            'status_count' => $statusCount,
        ];
    }
}
