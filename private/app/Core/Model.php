<?php

namespace App\Core;

use App\Config\Database;
use PDO;

class Model
{
    protected string $table;
    protected string $primaryKey = 'id';
    protected PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getDb(): PDO
    {
        return $this->db;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE {$this->primaryKey} = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function findBy(string $column, mixed $value): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE {$column} = :value LIMIT 1");
        $stmt->execute(['value' => $value]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function where(string $column, mixed $value): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE {$column} = :value ORDER BY {$this->primaryKey} DESC");
        $stmt->execute(['value' => $value]);
        return $stmt->fetchAll();
    }

    public function all(int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} ORDER BY {$this->primaryKey} DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        $stmt = $this->db->prepare("INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})");
        $stmt->execute($data);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $set = [];
        foreach (array_keys($data) as $column) {
            $set[] = "{$column} = :{$column}";
        }
        $setStr = implode(', ', $set);
        $data['id'] = $id;
        $stmt = $this->db->prepare("UPDATE {$this->table} SET {$setStr} WHERE {$this->primaryKey} = :id");
        return $stmt->execute($data);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE {$this->primaryKey} = :id");
        return $stmt->execute(['id' => $id]);
    }

    public function count(string $where = '1=1', array $params = []): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM {$this->table} WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetch()['total'];
    }

    public function paginate(int $page, int $perPage, string $where = '1=1', array $params = [], string $orderBy = null): array
    {
        $orderBy = $orderBy ?? "{$this->primaryKey} DESC";
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->db->prepare("SELECT COUNT(*) as total FROM {$this->table} WHERE {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['total'];

        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE {$where} ORDER BY {$orderBy} LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data'  => $stmt->fetchAll(),
            'total' => $total,
        ];
    }
}
