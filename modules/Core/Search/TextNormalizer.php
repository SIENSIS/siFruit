<?php

declare(strict_types=1);

namespace Modules\Core\Search;

use Normalizer;

/**
 * Turns text into search terms, the same way for the index and for the queries (docs/search.md,
 * "Terms"): HTML stripped, lower case, accents folded (`àéïçñ` → `aeicn`, Catalan `l·l` → `ll`),
 * split on anything that is not a letter or a digit, terms of 2 to 64 characters, the language's
 * stop words removed and a light suffix rule in Catalan, Spanish and English (a final `s`, then a
 * final `a`/`e`/`o`, dropped from words of 4+ characters: `cases`, `casa` → `cas`). Languages without a list keep every word.
 */
final class TextNormalizer
{
    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 64;

    /** Languages with the light suffix rule. */
    private const PLURAL_S = ['ca', 'es', 'en'];

    /** @var array<string, array<string, true>> */
    private static array $stopWords = [];

    /**
     * Plain text of HTML or text: tags removed (block ends become spaces), entities decoded,
     * whitespace collapsed.
     */
    public static function plain(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $text) ?? $text;
        $text = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/td|/th|/tr|/blockquote)\b[^>]*>#i', ' ', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Lower case without accents (the form terms are stored and compared in).
     */
    public static function fold(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace(['l·l', 'l.l', '·'], ['ll', 'll', ''], $text);
        $text = strtr($text, ['ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe', 'ø' => 'o', 'ł' => 'l', 'đ' => 'd', 'ð' => 'd', 'þ' => 'th']);

        if (class_exists(Normalizer::class)) {
            $decomposed = Normalizer::normalize($text, Normalizer::FORM_D);

            if (is_string($decomposed)) {
                $text = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $text;
            }
        }

        return $text;
    }

    /**
     * The terms of a text in a language, in order, repeated as they appear.
     *
     * @return list<string>
     */
    public static function terms(string $text, string $locale): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', self::fold(self::plain($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stop  = self::stopWords($locale);
        $terms = [];

        foreach ($words as $word) {
            $length = mb_strlen($word, 'UTF-8');

            if ($length < self::MIN_LENGTH || isset($stop[$word])) {
                continue;
            }

            if ($length > self::MAX_LENGTH) {
                $word = mb_substr($word, 0, self::MAX_LENGTH, 'UTF-8');
            }

            $terms[] = self::stem($word, $locale);
        }

        return $terms;
    }

    /**
     * The term of one word already folded (used to highlight results).
     */
    public static function stem(string $word, string $locale): string
    {
        if (! in_array(self::base($locale), self::PLURAL_S, true)) {
            return $word;
        }

        // A final `s` (not `ss`), then a final `a`, `e` or `o`, from words of 4+ characters:
        // `cases`/`casa` → `cas`, `notícies`/`notícia` → `notici`, `houses` → `hous`.
        if (strlen($word) >= 4 && str_ends_with($word, 's') && ! str_ends_with($word, 'ss')) {
            $word = substr($word, 0, -1);
        }

        if (strlen($word) >= 4 && in_array(substr($word, -1), ['a', 'e', 'o'], true)) {
            $word = substr($word, 0, -1);
        }

        return $word;
    }

    /**
     * The stop words of a language (folded), from the Core's lists (`StopWords`).
     *
     * @return array<string, true>
     */
    public static function stopWords(string $locale): array
    {
        $base = self::base($locale);

        if (! isset(self::$stopWords[$base])) {
            $words = [];

            foreach (StopWords::of($base) as $word) {
                $words[self::fold($word)] = true;
            }

            self::$stopWords[$base] = $words;
        }

        return self::$stopWords[$base];
    }

    private static function base(string $locale): string
    {
        return strtolower(explode('-', str_replace('_', '-', $locale), 2)[0]);
    }
}
