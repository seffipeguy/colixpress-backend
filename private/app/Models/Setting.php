<?php

namespace App\Models;

use App\Core\Model;

class Setting extends Model
{
    protected string $table = 'settings';

    private static array $cache = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $stmt = $this->db->prepare("SELECT setting_value FROM {$this->table} WHERE setting_key = :key LIMIT 1");
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch();

        $value = $row ? $row['setting_value'] : $default;
        self::$cache[$key] = $value;
        return $value;
    }

    public function getInt(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    public function getFloat(string $key, float $default = 0.0): float
    {
        return (float) $this->get($key, $default);
    }

    public function getBool(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default);
    }

    public function set(string $key, string $value, ?string $description = null, string $category = 'general'): void
    {
        $existing = $this->findBy('setting_key', $key);

        if ($existing) {
            $this->update((int) $existing['id'], ['setting_value' => $value]);
        } else {
            $this->create([
                'setting_key'   => $key,
                'setting_value' => $value,
                'description'   => $description,
                'category'      => $category,
            ]);
        }

        self::$cache[$key] = $value;
    }

    public function getByCategory(string $category): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE category = :category ORDER BY setting_key ASC");
        $stmt->execute(['category' => $category]);
        return $stmt->fetchAll();
    }

    public function getAll(): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} ORDER BY category ASC, setting_key ASC");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getCategories(): array
    {
        $stmt = $this->db->prepare("SELECT DISTINCT category FROM {$this->table} ORDER BY category ASC");
        $stmt->execute();
        return array_column($stmt->fetchAll(), 'category');
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
