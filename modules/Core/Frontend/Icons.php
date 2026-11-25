<?php

declare(strict_types=1);

namespace Modules\Core\Frontend;

/**
 * The names of the vendored Bootstrap Icons (`public/assets/vendor/bootstrap-icons`), read from
 * its stylesheet: the only source of icon names (D-49-02).
 *
 * - Icon fields (`data-icon-picker`, the section icon field) store `bi bi-<name>` or `bi-<name>`
 *   and are validated against this list on the server.
 * - `assets:publish` writes the list as `public/assets/core/icons.json` (LIST, read by the
 *   picker `core/forms/icon-picker.js`; `assets:publish --check` fails when it is stale).
 * - `HtmlSanitizer` keeps `<i class="bi bi-<name>">` in rich text only for a name of this list
 *   (D-49-01).
 */
final class Icons
{
    /** A stored icon class: `bi-<name>`. */
    public const PATTERN = '/^bi-[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** The generated list, relative to the Core's published assets (`core::icons.json`). */
    public const LIST = 'icons.json';

    /** @var array<string, array{stamp: string, names: list<string>, index: array<string, int>}> stylesheet => names */
    private static array $cache = [];

    /**
     * Every icon class (`bi-alarm`, `bi-house`…), sorted.
     *
     * @return list<string>
     */
    public static function all(?string $stylesheet = null): array
    {
        return array_map(static fn (string $name): string => 'bi-' . $name, self::names($stylesheet));
    }

    /**
     * Every icon name without the prefix (`alarm`, `house`…), sorted.
     *
     * @return list<string>
     */
    public static function names(?string $stylesheet = null): array
    {
        return self::read($stylesheet ?? self::stylesheet())['names'];
    }

    /** Whether `bi-<name>` is an icon of the list. */
    public static function exists(string $icon): bool
    {
        return preg_match(self::PATTERN, $icon) === 1 && self::isName(substr($icon, 3));
    }

    /** Whether `<name>` (without `bi-`) is an icon of the list. */
    public static function isName(string $name): bool
    {
        return isset(self::read(self::stylesheet())['index'][$name]);
    }

    /**
     * The JSON written to `public/assets/core/icons.json`: the library version and the sorted
     * names (`{"version": "1.13.1", "icons": ["0-circle", …]}`).
     */
    public static function json(?string $vendor = null): string
    {
        $vendor ??= FCPATH . 'assets/vendor';
        $version  = '';
        $manifest = $vendor . '/' . VendorChecksums::MANIFEST;

        if (is_file($manifest)) {
            $data    = json_decode((string) file_get_contents($manifest), true);
            $version = is_array($data) && is_array($data['bootstrap-icons'] ?? null) ? (string) ($data['bootstrap-icons']['version'] ?? '') : '';
        }

        return json_encode(
            ['version' => $version, 'icons' => self::names($vendor . '/bootstrap-icons/font/bootstrap-icons.min.css')],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /**
     * The names of a stylesheet, read again when the file changes (size or time).
     *
     * @return array{stamp: string, names: list<string>, index: array<string, int>}
     */
    private static function read(string $file): array
    {
        clearstatcache(true, $file);
        $stamp = is_file($file) ? filesize($file) . ':' . filemtime($file) : '';

        if (isset(self::$cache[$file]) && self::$cache[$file]['stamp'] === $stamp) {
            return self::$cache[$file];
        }

        $css = $stamp !== '' ? (string) file_get_contents($file) : '';
        preg_match_all('/\.bi-([a-z0-9]+(?:-[a-z0-9]+)*)::?before/', $css, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names, SORT_STRING);

        return self::$cache[$file] = ['stamp' => $stamp, 'names' => $names, 'index' => array_flip($names)];
    }

    private static function stylesheet(): string
    {
        return FCPATH . 'assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css';
    }
}
