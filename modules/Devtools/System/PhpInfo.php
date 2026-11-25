<?php

declare(strict_types=1);

namespace Modules\Devtools\System;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * The full `phpinfo()` for Devtools (`backend/devtools/phpinfo`, development only, session 53):
 * captured with output buffering and rebuilt as plain data — section titles and table rows of
 * text — so the page shows it inside the back-office layout with Bootstrap tables, escaped, with
 * no `<style>`, inline style, image or script of PHP's own page (the CSP stays strict).
 * Never in production: it shows environment variables and paths (docs/security.md).
 */
final class PhpInfo
{
    /**
     * @return list<array{title: string, rows: list<array{cells: list<string>, header: bool}>}>
     */
    public static function sections(?string $html = null): array
    {
        if ($html === null) {
            ob_start();
            phpinfo(INFO_ALL);
            $html = (string) ob_get_clean();
        }

        if (! str_contains($html, '<body')) {
            return self::fromText($html);
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        $found = $xpath->query('//body');
        $body  = $found !== false ? $found->item(0) : null;

        if (! $body instanceof DOMElement) {
            return [['title' => 'phpinfo()', 'rows' => [['cells' => [trim(strip_tags($html))], 'header' => false]]]];
        }

        $sections = [];
        $current  = ['title' => 'PHP', 'rows' => []];

        foreach ($xpath->query('.//h1|.//h2|.//table', $body) ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            if ($node->tagName === 'h1' || $node->tagName === 'h2') {
                if ($current['rows'] !== []) {
                    $sections[] = $current;
                }

                $current = ['title' => self::text($node->textContent), 'rows' => []];

                continue;
            }

            foreach ($xpath->query('.//tr', $node) ?: [] as $tr) {
                $cells  = [];
                $header = false;

                foreach ($tr->childNodes as $cell) {
                    if ($cell instanceof DOMElement && in_array($cell->tagName, ['td', 'th'], true)) {
                        $cells[] = self::text($cell->textContent);
                        $header  = $header || $cell->tagName === 'th';
                    }
                }

                if ($cells !== []) {
                    $current['rows'][] = ['cells' => $cells, 'header' => $header];
                }
            }
        }

        if ($current['rows'] !== []) {
            $sections[] = $current;
        }

        return $sections;
    }

    /**
     * The text format of `phpinfo()` (the CLI SAPI): titles alone on a line, rows `a => b => c`.
     *
     * @return list<array{title: string, rows: list<array{cells: list<string>, header: bool}>}>
     */
    private static function fromText(string $text): array
    {
        $sections = [];
        $current  = ['title' => 'PHP', 'rows' => []];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || $line === 'phpinfo()') {
                continue;
            }

            if (str_contains($line, ' => ')) {
                $current['rows'][] = ['cells' => array_map('trim', explode(' => ', $line)), 'header' => false];

                continue;
            }

            if ($current['rows'] !== []) {
                $sections[] = $current;
                $current    = ['title' => $line, 'rows' => []];
            } else {
                $current['title'] = $line;
            }
        }

        if ($current['rows'] !== []) {
            $sections[] = $current;
        }

        return $sections;
    }

    private static function text(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
