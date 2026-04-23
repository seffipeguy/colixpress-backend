<?php

namespace App\Models;

use App\Core\Model;

class Wallet extends Model
{
    protected string $table = 'wallets';

    public function findByUserId(int $userId): ?array
    {
        return $this->findBy('user_id', $userId);
    }

    public function getOrCreate(int $userId): array
    {
        $wallet = $this->findByUserId($userId);
        if (!$wallet) {
            $id = $this->create([
                'user_id'  => $userId,
                'balance'  => 0,
                'currency' => 'XAF',
            ]);
            $wallet = $this->find($id);
        }
        return $wallet;
    }
}
