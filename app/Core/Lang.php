<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Interface strings live in lang/<locale>/<file>.php and are addressed as
 * "file.key.subkey". Missing keys fall back to Hebrew, then to the key itself.
 */
final class Lang
{
    public const SUPPORTED = ['he' => 'עברית', 'ar' => 'العربية'];
    private const FALLBACK = 'he';

    private array $loaded = [];

    public function __construct(private string $dir, private string $locale)
    {
        $this->setLocale($locale);
    }

    public function setLocale(?string $locale): void
    {
        $this->locale = isset(self::SUPPORTED[$locale]) ? $locale : self::FALLBACK;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function direction(): string
    {
        return 'rtl'; // Both supported languages are right-to-left.
    }

    public function get(string $key, array $replace = []): string
    {
        $line = $this->lookup($this->locale, $key) ?? $this->lookup(self::FALLBACK, $key) ?? $key;
        foreach ($replace as $name => $value) {
            $line = str_replace(':' . $name, (string) $value, $line);
        }
        return $line;
    }

    public function has(string $locale, string $key): bool
    {
        return $this->lookup($locale, $key) !== null;
    }

    private function lookup(string $locale, string $key): ?string
    {
        $segments = explode('.', $key);
        $file = array_shift($segments);
        if (!preg_match('/^[a-z_]+$/', (string) $file)) {
            return null;
        }
        $this->loaded[$locale][$file] ??= is_file($path = "$this->dir/$locale/$file.php") ? require $path : [];
        $value = $this->loaded[$locale][$file];
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }
        return is_string($value) ? $value : null;
    }
}
