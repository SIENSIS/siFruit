<?php

declare(strict_types=1);

namespace Modules\News\Search;

use DateTimeImmutable;
use DateTimeZone;
use Kpcrudy\Crud;
use Modules\Core\Content\ContentTranslations;
use Modules\Core\Search\SearchDocument;
use Modules\Core\Search\SearchIndex;
use Modules\Core\Search\SearchSource;

/**
 * News in the site search (search source `news.posts`, D-50-01, docs/search.md, docs/news.md
 * "Search"): a post that is published or scheduled and not in the trash has one document per site
 * language (fallback of `ContentTranslations::pick()`) with the audience of `NewsVisibility`:
 * `public` posts link to the public article (`news/<slug>`), `private` and `groups` posts (found
 * only by signed-in users, groups by their members) to the reader screen of the back office
 * (`backend/news/read/<id>`, D-00-42). The window is `published_at` → `expires_at`. Texts: title
 * ×5, the category's name as keywords ×3, summary ×2, body ×1.
 *
 * `sync()` runs after every change of a post, next to `NoticeCalendar::sync()`.
 */
final class NewsSearch implements SearchSource
{
    public const SOURCE = 'news.posts';

    /**
     * Indexes or removes one post. Returns whether the index changed.
     */
    public static function sync(int $id): bool
    {
        /** @var SearchIndex $index */
        $index = service('search');

        return $index->replace(self::SOURCE, self::key($id), (new self())->documentsOf($id));
    }

    /**
     * @param list<int|string> $ids
     */
    public static function syncMany(array $ids): void
    {
        foreach ($ids as $id) {
            self::sync((int) $id);
        }
    }

    public static function key(int $id): string
    {
        return 'post-' . $id;
    }

    /**
     * @return iterable<SearchDocument>
     */
    public function documents(): iterable
    {
        $ids = array_column(db_connect()->table('news')->select('id')->where('deleted_at', null)->whereIn('status', ['published', 'scheduled'])->get()->getResultArray(), 'id');

        foreach ($ids as $id) {
            yield from $this->documentsOf((int) $id);
        }
    }

    /**
     * The documents of one post (one per site language), or none when it must not be found.
     *
     * @return list<SearchDocument>
     */
    public function documentsOf(int $id): array
    {
        $row = db_connect()->table('news')->where('id', $id)->get(1)->getRowArray();

        if (! is_array($row) || $row['deleted_at'] !== null || ! in_array($row['status'], ['published', 'scheduled'], true)) {
            return [];
        }

        $texts = [];

        foreach (db_connect()->table('news_translations')->where('news_id', $id)->get()->getResultArray() as $text) {
            $texts[(string) $text['locale']] = $text;
        }

        $categories = [];

        if ($row['category_id'] !== null) {
            foreach (db_connect()->table('news_category_translations')->where('category_id', (int) $row['category_id'])->get()->getResultArray() as $text) {
                $categories[(string) $text['locale']] = $text;
            }
        }

        $storage    = new DateTimeZone(config('App')->appTimezone ?: 'UTC');
        $published  = is_string($row['published_at']) && $row['published_at'] !== '' ? new DateTimeImmutable($row['published_at'], $storage) : null;
        $expires    = is_string($row['expires_at']) && $row['expires_at'] !== '' ? new DateTimeImmutable($row['expires_at'], $storage) : null;
        $visibility = in_array($row['visibility'], ['public', 'private', 'groups'], true) ? (string) $row['visibility'] : 'private';
        $url        = $visibility === 'public' ? 'news/' . rawurlencode((string) $row['slug']) : backend_link('news/read/' . Crud::maskId($id, 'news'));
        $groups     = $visibility === 'groups' ? $this->groups($id) : [];
        $documents  = [];

        foreach (ContentTranslations::codes() as $locale) {
            $text = ContentTranslations::pick($texts, ['title', 'summary', 'body'], $locale);

            if ($text['title'] === '') {
                continue;
            }

            $documents[] = new SearchDocument(
                source: self::SOURCE,
                key: self::key($id),
                locale: $locale,
                title: $text['title'],
                url: $url,
                summary: $text['summary'],
                body: $text['body'],
                keywords: $categories === [] ? '' : ContentTranslations::pick($categories, ['name'], $locale)['name'],
                date: $published,
                visibility: $visibility,
                groups: $groups,
                publishFrom: $published,
                publishUntil: $expires,
            );
        }

        return $documents;
    }

    /**
     * @return list<string> names of the post's groups
     */
    private function groups(int $id): array
    {
        $rows = db_connect()->table('news_groups')->select('groups.name')
            ->join('groups', 'groups.id = news_groups.group_id')
            ->where('news_groups.news_id', $id)
            ->orderBy('groups.name')
            ->get()->getResultArray();

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }
}
