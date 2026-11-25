<?php

declare(strict_types=1);

namespace Modules\Core\Search;

/**
 * Words too common to search for, per language (articles, prepositions, conjunctions, pronouns and
 * the most common auxiliary verbs). Written with accents; the normaliser folds them. A language
 * without a list keeps every word (docs/search.md, "Adding a language").
 */
final class StopWords
{
    private const WORDS = [
        'ca' => 'a al als amb an ans aquell aquella aquelles aquells aquest aquesta aquestes aquests així com d de dels des del dins el ell ella elles ells els em en entre era eren es és està estan et fa fer fins ha han hi i ja jo l la les li lo los me mes més meu meva molt ne ni no nosaltres o on per però perquè pel pels qual quan que què qui s sa se seu seus seva seves si sense ser sobre som són sota també te tu un una unes uns us va van vosaltres',
        'es' => 'a al algo algunos ante antes como con contra cual cuando de del desde donde durante e el ella ellas ellos en entre era eran es esa esas ese eso esos esta está estas este esto estos fue fueron ha han hasta hay la las le les lo los más me mi muy nada ni no nos nosotros o os otra otro para pero por porque que qué quien se sea ser si sí sin sobre son su sus también te tiene tu un una unas uno unos y ya yo',
        'en' => 'a about after all also an and any are as at be been before but by can could did do does for from had has have he her his how i if in into is it its just me more my no not of on or our out over she so some such than that the their them then there these they this those to too under up us was we were what when where which while who will with would you your',
    ];

    /**
     * @return list<string>
     */
    public static function of(string $language): array
    {
        $words = self::WORDS[$language] ?? '';

        return $words === '' ? [] : explode(' ', $words);
    }
}
