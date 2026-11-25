<?php

declare(strict_types=1);

namespace Modules\Core\Search;

/**
 * Escaped HTML of a text with the words that match a query wrapped in `<mark>` (results page,
 * docs/search.md). Every piece of the text is escaped; only the `<mark>` tags are added.
 */
final class Highlighter
{
    /**
     * @param list<string> $terms the query's terms (`SearchResults::$terms`)
     */
    public static function highlight(string $text, array $terms, string $locale): string
    {
        if ($text === '' || $terms === []) {
            return esc($text);
        }

        $parts = preg_split('/([\p{L}\p{N}·]+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$text];
        $html  = '';

        foreach ($parts as $part) {
            $html .= self::matches($part, $terms, $locale) ? '<mark>' . esc($part) . '</mark>' : esc($part);
        }

        return $html;
    }

    /**
     * @param list<string> $terms
     */
    private static function matches(string $word, array $terms, string $locale): bool
    {
        if (preg_match('/^[\p{L}\p{N}·]+$/u', $word) !== 1) {
            return false;
        }

        $folded = str_replace('·', '', TextNormalizer::fold($word));
        $stem   = TextNormalizer::stem($folded, $locale);

        foreach ($terms as $term) {
            if ($stem === $term || $folded === $term || (mb_strlen($term, 'UTF-8') >= 3 && str_starts_with($folded, $term))) {
                return true;
            }
        }

        return false;
    }
}
