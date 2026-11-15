<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\App;
use App\Core\Lang;
use PHPUnit\Framework\TestCase;

final class LangTest extends TestCase
{
    /** @return array<string, string> flattened "file.key.sub" => text */
    private function load(string $locale): array
    {
        $flat = [];
        foreach (glob(App::root("lang/$locale/*.php")) as $file) {
            $walk = function (array $items, string $prefix) use (&$walk, &$flat): void {
                foreach ($items as $key => $value) {
                    is_array($value) ? $walk($value, "$prefix.$key") : $flat["$prefix.$key"] = $value;
                }
            };
            $walk(require $file, basename($file, '.php'));
        }
        return $flat;
    }

    public function testHebrewAndArabicHaveExactlyTheSameKeys(): void
    {
        $he = $this->load('he');
        $ar = $this->load('ar');
        self::assertNotEmpty($he);
        self::assertSame([], array_values(array_diff(array_keys($he), array_keys($ar))), 'Keys missing in Arabic');
        self::assertSame([], array_values(array_diff(array_keys($ar), array_keys($he))), 'Keys missing in Hebrew');
    }

    public function testPlaceholdersMatchBetweenLanguages(): void
    {
        $he = $this->load('he');
        $ar = $this->load('ar');
        foreach ($he as $key => $text) {
            preg_match_all('/:[a-z_]+/', $text, $a);
            preg_match_all('/:[a-z_]+/', $ar[$key], $b);
            sort($a[0]);
            sort($b[0]);
            self::assertSame($a[0], $b[0], "Placeholders differ for $key");
        }
    }

    public function testNoTranslationIsEmpty(): void
    {
        foreach (['he', 'ar'] as $locale) {
            foreach ($this->load($locale) as $key => $text) {
                self::assertNotSame('', trim((string) $text), "$locale: $key is empty");
            }
        }
    }

    public function testEveryModuleActionAndRoleHasALabel(): void
    {
        $config = App::config('permissions');
        foreach (array_keys(Lang::SUPPORTED) as $locale) {
            foreach ($config['modules'] as $module) {
                self::assertTrue(App::lang()->has($locale, "permissions.modules.$module"), "$locale: module $module");
            }
            foreach ($config['actions'] as $action) {
                self::assertTrue(App::lang()->has($locale, "permissions.actions.$action"), "$locale: action $action");
            }
            foreach (array_keys($config['roles']) as $role) {
                self::assertTrue(App::lang()->has($locale, "permissions.roles.$role"), "$locale: role $role");
            }
            foreach (App::config('upload.categories') as $category) {
                self::assertTrue(App::lang()->has($locale, "documents.categories.$category"), "$locale: category $category");
            }
        }
    }

    public function testEveryKeyUsedInViewsAndControllersExists(): void
    {
        $he = $this->load('he');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(App::root('app')));
        $missing = [];
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            // Literal keys only: __('file.key') with no concatenation.
            preg_match_all("/__\\('([a-z_]+\\.[a-zA-Z0-9_.]+)'[,)]/", file_get_contents($file->getPathname()), $m);
            foreach ($m[1] as $key) {
                if (!isset($he[$key])) {
                    $missing[] = $key . ' (' . $file->getFilename() . ')';
                }
            }
        }
        foreach (App::config('navigation') as $group) {
            foreach ([$group['group'], ...array_column($group['items'], 'label')] as $key) {
                if (!isset($he[$key])) {
                    $missing[] = $key . ' (navigation)';
                }
            }
        }
        self::assertSame([], $missing, 'Translation keys used in code but not defined');
    }

    public function testUnknownLocaleFallsBackToHebrew(): void
    {
        $lang = new Lang(App::root('lang'), 'xx');
        self::assertSame('he', $lang->locale());
        self::assertSame('rtl', $lang->direction());
    }
}
