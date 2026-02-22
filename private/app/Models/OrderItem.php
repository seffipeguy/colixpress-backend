<?php

namespace App\Models;

use App\Core\Model;

class OrderItem extends Model
{
    protected string $table = 'order_items';

    public function getByOrder(int $orderId): array
    {
        return $this->where('order_id', $orderId);
    }

    public function createFromCart(int $orderId, array $items): void
    {
        $shopItemModel = new ShopItem();

        foreach ($items as $item) {
            $shopItem = $shopItemModel->find((int) $item['shop_item_id']);
            if (!$shopItem) {
                continue;
            }

            $quantity = max(1, (int) ($item['quantity'] ?? 1));

            $this->create([
                'order_id'     => $orderId,
                'shop_item_id' => (int) $shopItem['id'],
                'item_name'    => $shopItem['name'],
                'quantity'     => $quantity,
                'unit_price'   => (int) $shopItem['price'],
                'total_price'  => (int) $shopItem['price'] * $quantity,
            ]);
        }
    }
}
