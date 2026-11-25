<?php

declare(strict_types=1);

namespace Modules\Core\I18n;

/**
 * The language files check (docs/i18n.md): every language file of the modules and the themes
 * exists in `ca`, `es` and `en` with exactly the same keys, and no text is empty. Used by
 * `tests/unit/I18n/LanguageKeysTest.php` (CI) and Devtools › Checks.
 */
final class LanguageKeys
{
    public const LOCALES = ['ca', 'es', 'en'];

    public function __construct(private readonly string $root = ROOTPATH)
    {
    }

    /**
     * English files of the modules and the themes (the reference).
     *
     * @return list<string>
     */
    public function referenceFiles(): array
    {
        return array_merge(glob($this->root . 'modules/*/Language/en/*.php') ?: [], glob($this->root . 'themes/*/Language/en/*.php') ?: []);
    }

    /**
     * Problems, one line each (empty: the files are complete).
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        foreach ($this->referenceFiles() as $file) {
            $en = self::flatten(require $file);

            foreach (['ca', 'es'] as $locale) {
                $other = str_replace('/Language/en/', "/Language/{$locale}/", $file);

                if (! is_file($other)) {
                    $problems[] = $this->relative($other) . ': missing';

                    continue;
                }

                $keys = array_keys(self::flatten(require $other));

                foreach (array_diff(array_keys($en), $keys) as $key) {
                    $problems[] = $this->relative($other) . ": missing key {$key}";
                }

                foreach (array_diff($keys, array_keys($en)) as $key) {
                    $problems[] = $this->relative($other) . ": extra key {$key}";
                }
            }
        }

        foreach (['ca', 'es'] as $locale) {
            foreach (array_merge(glob($this->root . "modules/*/Language/{$locale}/*.php") ?: [], glob($this->root . "themes/*/Language/{$locale}/*.php") ?: []) as $file) {
                if (! is_file(str_replace("/Language/{$locale}/", '/Language/en/', $file))) {
                    $problems[] = $this->relative($file) . ': no English file';
                }
            }
        }

        foreach (array_merge(glob($this->root . 'modules/*/Language/*/*.php') ?: [], glob($this->root . 'themes/*/Language/*/*.php') ?: [], glob($this->root . 'app/Language/*/*.php') ?: []) as $file) {
            foreach (self::flatten(require $file) as $key => $text) {
                if (trim($text) === '') {
                    $problems[] = $this->relative($file) . ": empty text {$key}";
                }
            }
        }

        return $problems;
    }

    /**
     * @param array<array-key, mixed> $lines
     *
     * @return array<string, string> key (dotted) => text, sorted by key
     */
    public static function flatten(array $lines, string $prefix = ''): array
    {
        $keys = [];

        foreach ($lines as $key => $value) {
            if (is_array($value)) {
                $keys += self::flatten($value, $prefix . $key . '.');
            } else {
                $keys[$prefix . $key] = is_scalar($value) ? (string) $value : '';
            }
        }

        ksort($keys);

        return $keys;
    }

    private function relative(string $path): string
    {
        return str_replace($this->root, '', $path);
    }
}
