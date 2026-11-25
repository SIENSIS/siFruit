<?php

declare(strict_types=1);

namespace Modules\Core\Storage;

/**
 * The stored files a text refers to (D-55-01, docs/storage.md "Unused files"), for the
 * `FileReferenceProvider` of every module: rich text (`<img src>`, links), JSON (section data,
 * revision snapshots, settings) or plain values.
 *
 * It finds, without parsing the markup (so nothing hidden in an attribute, a comment or an
 * escaped JSON string is missed):
 *
 * - every 32-hex token: file keys (`files/private/<key>`, picker values) and the random names
 *   of stored files and their variants (`…/<hex>.png`, `…/<hex>-w800.webp`);
 * - every path under a public disk's URL prefix (`/uploads/modules/news/2026/11/x.png`, also in
 *   absolute URLs and JSON-escaped `\/`), returned as a site path (`/uploads/…`).
 *
 * Finding more than a file really uses only keeps a file; it never deletes one.
 */
final class RichTextFiles
{
    private const TOKEN = '/(?<![A-Fa-f0-9])[a-f0-9]{32}(?![A-Fa-f0-9])/';

    /** @var array<string, string>|null public disk => URL path prefix without slashes (`uploads`) */
    private static ?array $prefixes = null;

    /**
     * @return list<string> 32-hex tokens and site paths of public files, without duplicates
     */
    public static function references(?string $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }

        $text  = str_replace('\\/', '/', $text);
        $found = [];

        if (preg_match_all(self::TOKEN, $text, $matches) > 0) {
            foreach ($matches[0] as $token) {
                $found[$token] = true;
            }
        }

        foreach (self::prefixes() as $prefix) {
            $pattern = '#(?<![A-Za-z0-9_.-])' . preg_quote($prefix, '#') . '/([^\s"\'<>()\\\\?\#,]+)#';

            if (preg_match_all($pattern, $text, $matches) > 0) {
                foreach ($matches[1] as $path) {
                    $found['/' . $prefix . '/' . rawurldecode($path)] = true;
                }
            }
        }

        return array_map('strval', array_keys($found));
    }

    /**
     * The public disks and their URL path prefixes (`['public' => 'uploads']`).
     *
     * @return array<string, string>
     */
    public static function prefixes(): array
    {
        if (self::$prefixes !== null) {
            return self::$prefixes;
        }

        $prefixes = [];

        /** @var Storage $storage */
        $storage = service('storage');

        foreach ($storage->config()->disks as $name => $disk) {
            if (($disk['url'] ?? null) === null) {
                continue;
            }

            $path = trim((string) parse_url(base_url((string) $disk['url']), PHP_URL_PATH), '/');

            if ($path !== '') {
                $prefixes[(string) $name] = $path;
            }
        }

        return self::$prefixes = $prefixes;
    }

    /**
     * Forgets the cached prefixes (tests that change the disks).
     */
    public static function reset(): void
    {
        self::$prefixes = null;
    }
}
