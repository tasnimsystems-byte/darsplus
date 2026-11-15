<?php

declare(strict_types=1);

namespace App\Core;

final class Session
{
    private array $data = [];
    private bool $native = false;

    private function __construct()
    {
    }

    public static function start(array $config): self
    {
        $session = new self();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            session_name($config['name']);
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => (bool) $config['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
        $session->native = true;
        $session->data = &$_SESSION;
        return $session;
    }

    /** In-memory session for tests and the console. */
    public static function fake(): self
    {
        return new self();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->data[$key] ?? $default;
        unset($this->data[$key]);
        return $value;
    }

    public function flash(string $key, mixed $value): void
    {
        $this->data['_flash'][$key] = $value;
    }

    public function pullFlash(string $key, mixed $default = null): mixed
    {
        $value = $this->data['_flash'][$key] ?? $default;
        unset($this->data['_flash'][$key]);
        return $value;
    }

    /** New session id, same data — call on every privilege change (login, school switch). */
    public function regenerate(): void
    {
        if ($this->native) {
            session_regenerate_id(true);
        }
    }

    public function clear(): void
    {
        foreach (array_keys($this->data) as $key) {
            unset($this->data[$key]);
        }
        $this->regenerate();
    }
}
