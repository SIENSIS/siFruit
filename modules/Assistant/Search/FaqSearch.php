<?php

declare(strict_types=1);

namespace Modules\Assistant\Search;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Assistant\Services\Faqs;
use Modules\Core\Content\ContentTranslations;
use Modules\Core\Search\SearchDocument;
use Modules\Core\Search\SearchIndex;
use Modules\Core\Search\SearchSource;

/**
 * The FAQs in the site search (search source `assistant.faqs`, D-51-01; docs/assistant.md): an
 * active FAQ has one document per site language (fallback of `ContentTranslations::pick()`) with
 * its audience: the question ×5, the other phrasings and keywords ×3, the answer ×1. The result
 * links to the FAQ on the public page `faq` (D-51-03).
 *
 * The chat assistant looks for an FAQ in this source first (`Answerer`). `sync()` runs after every
 * write of the FAQ CRUD.
 */
final class FaqSearch implements SearchSource
{
    public const SOURCE = 'assistant.faqs';

    public static function sync(int $id): bool
    {
        /** @var SearchIndex $index */
        $index = service('search');

        return $index->replace(self::SOURCE, self::key($id), (new self())->documentsOf($id));
    }

    public static function key(int $id): string
    {
        return 'faq-' . $id;
    }

    /**
     * The FAQ id of a document key (`faq-12` → 12), or null.
     */
    public static function idOf(string $key): ?int
    {
        return preg_match('/^faq-(\d+)$/', $key, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * @return iterable<SearchDocument>
     */
    public function documents(): iterable
    {
        foreach (array_column(db_connect()->table('assistant_faqs')->select('id')->where('active', 1)->get()->getResultArray(), 'id') as $id) {
            yield from $this->documentsOf((int) $id);
        }
    }

    /**
     * @return list<SearchDocument>
     */
    public function documentsOf(int $id): array
    {
        $row = db_connect()->table('assistant_faqs')->where('id', $id)->get(1)->getRowArray();

        if (! is_array($row) || (int) $row['active'] !== 1) {
            return [];
        }

        $texts = [];

        foreach (db_connect()->table('assistant_faq_translations')->where('faq_id', $id)->get()->getResultArray() as $text) {
            $texts[(string) $text['locale']] = $text;
        }

        $visibility = in_array($row['visibility'], ['public', 'private', 'groups'], true) ? (string) $row['visibility'] : 'private';
        $groups     = $visibility === 'groups' ? $this->groups($id) : [];
        $storage    = new DateTimeZone(config('App')->appTimezone ?: 'UTC');
        $updated    = is_string($row['updated_at']) && $row['updated_at'] !== '' ? new DateTimeImmutable($row['updated_at'], $storage) : null;
        $documents  = [];

        foreach (ContentTranslations::codes() as $locale) {
            $text = ContentTranslations::pick($texts, Faqs::TEXTS, $locale);

            if ($text['question'] === '') {
                continue;
            }

            $documents[] = new SearchDocument(
                source: self::SOURCE,
                key: self::key($id),
                locale: $locale,
                title: $text['question'],
                url: 'faq#faq-' . $id,
                body: $text['answer'],
                keywords: str_replace(["\r\n", "\n", ','], ' ', $text['keywords']),
                date: $updated,
                visibility: $visibility,
                groups: $groups,
            );
        }

        return $documents;
    }

    /**
     * @return list<string> names of the FAQ's groups
     */
    private function groups(int $id): array
    {
        $rows = db_connect()->table('assistant_faq_groups')->select('groups.name')
            ->join('groups', 'groups.id = assistant_faq_groups.group_id')
            ->where('assistant_faq_groups.faq_id', $id)
            ->orderBy('groups.name')
            ->get()->getResultArray();

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }
}
