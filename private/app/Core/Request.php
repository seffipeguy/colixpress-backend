<?php

namespace App\Core;

class Request
{
    private array $body;
    private array $query;
    private array $params;

    public function __construct()
    {
        $this->query = $_GET;
        $this->params = [];

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            $this->body = json_decode($raw, true) ?? [];
        } else {
            $this->body = $_POST;
        }
    }

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD']);
    }

    public function uri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $uri = parse_url($uri, PHP_URL_PATH);
        return '/' . trim($uri, '/');
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function body(): array
    {
        return $this->body;
    }

    public function query(string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }
        return $this->query[$key] ?? $default;
    }

    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->body[$key]) || isset($this->query[$key]);
    }

    public function validate(array $required): array
    {
        $missing = [];
        foreach ($required as $field) {
            $value = $this->input($field);
            if ($value === null || $value === '') {
                $missing[] = $field;
            }
        }
        if (!empty($missing)) {
            Response::error('Missing required fields', 422, ['missing' => $missing]);
        }
        return $this->all();
    }

    public function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
            return $matches[1];
        }
        return null;
    }

    public function page(): int
    {
        return max(1, (int) $this->query('page', 1));
    }

    public function perPage(): int
    {
        $perPage = (int) $this->query('per_page', DEFAULT_PER_PAGE);
        return min(max(1, $perPage), MAX_PER_PAGE);
    }

    public function file(string $key): ?array
    {
        return $_FILES[$key] ?? null;
    }
}
