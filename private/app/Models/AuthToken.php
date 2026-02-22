<?php

namespace App\Models;

use App\Core\Model;

class AuthToken extends Model
{
    protected string $table = 'auth_tokens';

    public function findByToken(string $token): ?array
    {
        return $this->findBy('token', $token);
    }

    public function deleteExpired(): void
    {
        $this->db->exec("DELETE FROM {$this->table} WHERE expires_at < NOW()");
    }
}
