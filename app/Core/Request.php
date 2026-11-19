<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $files = [],
        public readonly array $server = [],
    ) {
    }

    public static function fromGlobals(string $basePath = ''): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $basePath = rtrim($basePath, '/');
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        $path = '/' . trim($path, '/');

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            $_POST,
            $_FILES,
            $_SERVER,
        );
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    /** Trimmed scalar string; arrays and missing values become ''. */
    public function str(string $key): string
    {
        $value = $this->input($key, '');
        return is_scalar($value) ? trim((string) $value) : '';
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key);
        return is_scalar($value) && preg_match('/^-?\d+$/', (string) $value) ? (int) $value : $default;
    }

    /** List of positive integers from a checkbox group / multi-select. */
    public function ids(string $key): array
    {
        $value = $this->input($key, []);
        if (!is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $item) {
            if (is_scalar($item) && preg_match('/^\d+$/', (string) $item) && (int) $item > 0) {
                $ids[] = (int) $item;
            }
        }
        return array_values(array_unique($ids));
    }

    public function has(string $key): bool
    {
        return isset($this->post[$key]) || isset($this->query[$key]);
    }

    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        return is_array($file) && isset($file['tmp_name']) && !is_array($file['tmp_name']) ? $file : null;
    }

    /**
     * The files of a multiple upload field (`name="key[]"`), one array per file in the shape of a single upload.
     *
     * @return list<array{name: string, type: string, tmp_name: string, error: int, size: int}>
     */
    public function fileList(string $key): array
    {
        $field = $this->files[$key] ?? null;
        if (!is_array($field) || !isset($field['tmp_name'])) {
            return [];
        }
        if (!is_array($field['tmp_name'])) {
            return [$field];
        }
        $files = [];
        foreach (array_keys($field['tmp_name']) as $i) {
            if (is_array($field['tmp_name'][$i])) {
                continue;   // deeper nesting is never a field of ours
            }
            $files[] = [
                'name' => (string) ($field['name'][$i] ?? ''), 'type' => (string) ($field['type'][$i] ?? ''),
                'tmp_name' => (string) $field['tmp_name'][$i], 'error' => (int) ($field['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($field['size'][$i] ?? 0),
            ];
        }
        return $files;
    }

    public function ip(): string
    {
        // REMOTE_ADDR only: forwarded headers are client-controlled unless a trusted proxy is configured.
        return substr((string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($this->server[$key]) ? (string) $this->server[$key] : null;
    }
}
