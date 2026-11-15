<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    private static array $shared = ['errors' => [], 'old' => []];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function shared(string $key): mixed
    {
        return self::$shared[$key] ?? null;
    }

    public static function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::partial($template, $data);
        return $layout === null ? $content : self::partial($layout, $data + ['content' => $content]);
    }

    public static function partial(string $template, array $data = []): string
    {
        $file = App::root('app/Views/' . $template . '.php');
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: $template");
        }
        $level = ob_get_level();
        ob_start();
        try {
            (static function (string $__file, array $__data): void {
                extract($__data, EXTR_SKIP);
                require $__file;
            })($file, $data);
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
    }
}
