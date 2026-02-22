<?php

namespace App\Models;

use App\Core\Model;

class OtpCode extends Model
{
    protected string $table = 'otp_codes';

    public function generate(int $countryId, string $phone): string
    {
        // Invalidate previous OTPs
        $this->db->prepare("UPDATE {$this->table} SET is_used = 1 WHERE country_id = :cid AND phone = :phone AND is_used = 0")
            ->execute(['cid' => $countryId, 'phone' => $phone]);

        // Generate new OTP
        $code = str_pad((string) random_int(0, (int) str_repeat('9', OTP_LENGTH)), OTP_LENGTH, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . OTP_EXPIRY_MINUTES . ' minutes'));

        $this->create([
            'country_id' => $countryId,
            'phone'      => $phone,
            'code'       => $code,
            'expires_at' => $expiresAt,
        ]);

        return $code;
    }

    public function verify(int $countryId, string $phone, string $code): bool
    {
        $stmt = $this->db->prepare("
            SELECT id FROM {$this->table}
            WHERE country_id = :cid AND phone = :phone AND code = :code
              AND is_used = 0 AND expires_at > NOW()
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute(['cid' => $countryId, 'phone' => $phone, 'code' => $code]);
        $otp = $stmt->fetch();

        if ($otp) {
            $this->update((int) $otp['id'], ['is_used' => 1]);
            return true;
        }

        return false;
    }

    public function cleanup(): void
    {
        $this->db->exec("DELETE FROM {$this->table} WHERE expires_at < NOW() OR is_used = 1");
    }
}
