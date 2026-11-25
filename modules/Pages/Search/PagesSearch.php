<?php

declare(strict_types=1);

namespace Modules\Pages\Search;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Core\Content\ContentTranslations;
use Modules\Core\Search\SearchDocument;
use Modules\Core\Search\SearchIndex;
use Modules\Core\Search\SearchSource;
use Modules\Pages\Services\HomePageResolver;
use Modules\Pages\Services\PageSections;
use Modules\Pages\Services\PageTree;

/**
 * Pages in the site search (search source `pages.pages`, D-50-01, docs/search.md, docs/pages.md
 * "Search"): a page that is published or scheduled, not in the trash, reachable in the tree and
 * not marked `noindex` has one document per site language (its text in that language, else the
 * fallback of `ContentTranslations::pick()`), with its audience (`public` / `private` / `groups`
 * and their group names) and `publish_at` as the start of its window. Texts: title ×5, meta title
 * ×3, summary and meta description ×2, body and the texts of its visible sections ×1.
 *
 * `sync()` runs on every change of a page (`Revisions::record()` and the CRUD's writes,
 * publish/unpublish); it also refreshes the page's subpages, whose paths start with its slug.
 */
final class PagesSearch implements SearchSource
{
    public const SOURCE = 'pages.pages';

    /** Keys of section fields that never hold text to search (links, images, styles). */
    private const SKIPPED_FIELDS = ['url', 'href', 'link', 'image', 'images', 'icon', 'style', 'variant', 'color', 'colour', 'background', 'align', 'alignment', 'target', 'embed', 'code', 'file', 'logo'];

    /**
     * Indexes or removes one page and its subpages. Returns whether the index changed.
     */
    public static function sync(int $id): bool
    {
        $tree    = new PageTree();
        $self    = new self();
        $changed = false;

        foreach ([$id, ...$tree->descendants($id)] as $pageId) {
            $changed = self::index()->replace(self::SOURCE, self::key((int) $pageId), $self->documentsOf((int) $pageId, $tree)) || $changed;
        }

        return $changed;
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
        return 'page-' . $id;
    }

    /**
     * @return iterable<SearchDocument>
     */
    public function documents(): iterable
    {
        $tree = new PageTree();
        $ids  = array_column(db_connect()->table('pages')->select('id')->where('deleted_at', null)->whereIn('status', ['published', 'scheduled'])->get()->getResultArray(), 'id');

        foreach ($ids as $id) {
            yield from $this->documentsOf((int) $id, $tree);
        }
    }

    /**
     * The documents of one page (one per site language), or none when it must not be found.
     *
     * @return list<SearchDocument>
     */
    public function documentsOf(int $id, ?PageTree $tree = null): array
    {
        $page = db_connect()->table('pages')->where('id', $id)->get(1)->getRowArray();

        if (! is_array($page) || $page['deleted_at'] !== null || ! in_array($page['status'], ['published', 'scheduled'], true) || (int) $page['noindex'] === 1) {
            return [];
        }

        $tree ??= new PageTree();
        $rows = db_connect()->table('page_translations')->where('page_id', $id)->get()->getResultArray();
        $byLocale = [];

        foreach ($rows as $row) {
            $byLocale[(string) $row['locale']] = $row;
        }

        $storage    = new DateTimeZone(config('App')->appTimezone ?: 'UTC');
        $publishAt  = is_string($page['publish_at']) && $page['publish_at'] !== '' ? new DateTimeImmutable($page['publish_at'], $storage) : null;
        $updated    = is_string($page['updated_at']) && $page['updated_at'] !== '' ? new DateTimeImmutable($page['updated_at'], $storage) : $publishAt;
        $visibility = in_array($page['visibility'], ['public', 'private', 'groups'], true) ? (string) $page['visibility'] : 'private';
        $groups     = $visibility === 'groups' ? $this->groups($id) : [];
        $isHome     = (new HomePageResolver())->id() === $id;
        $sections   = ($page['layout'] ?? 'article') === 'sections' ? (new PageSections())->all($id) : [];
        $documents  = [];

        foreach (ContentTranslations::codes() as $locale) {
            $texts = ContentTranslations::pick($byLocale, ['title', 'summary', 'body', 'meta_title', 'meta_description'], $locale);
            $path  = $isHome ? '/' : $tree->path($id, $locale);

            if ($texts['title'] === '' || $path === null) {
                continue;
            }

            $documents[] = new SearchDocument(
                source: self::SOURCE,
                key: self::key($id),
                locale: $locale,
                title: $texts['title'],
                url: $path,
                summary: $texts['summary'],
                body: trim($texts['body'] . ' ' . $this->sectionText($sections, $locale)),
                metaTitle: $texts['meta_title'],
                metaDescription: $texts['meta_description'],
                date: $updated,
                visibility: $visibility,
                groups: $groups,
                publishFrom: $publishAt,
            );
        }

        return $documents;
    }

    /**
     * The texts of the visible sections in a language (else the default one).
     *
     * @param list<\Modules\Core\Sections\SectionData> $sections
     */
    private function sectionText(array $sections, string $locale): string
    {
        $parts = [];

        foreach ($sections as $section) {
            if ($section->hidden) {
                continue;
            }

            $texts = $section->texts[$locale] ?? $section->texts[ContentTranslations::defaultLocale()] ?? [];
            array_push($parts, ...$this->strings($texts));
        }

        return implode(' ', $parts);
    }

    /**
     * Every text value of a section's translated data (nested lists of cards, links…).
     *
     * @param array<array-key, mixed> $data
     *
     * @return list<string>
     */
    private function strings(array $data): array
    {
        $strings = [];

        foreach ($data as $field => $value) {
            if (is_string($field) && in_array(strtolower($field), self::SKIPPED_FIELDS, true)) {
                continue;
            }

            if (is_array($value)) {
                array_push($strings, ...$this->strings($value));
            } elseif (is_string($value) && $value !== '' && preg_match('#^(https?:)?//|^/#i', $value) !== 1) {
                $strings[] = $value;
            }
        }

        return $strings;
    }

    /**
     * @return list<string> names of the page's groups
     */
    private function groups(int $id): array
    {
        $rows = db_connect()->table('page_groups')->select('groups.name')
            ->join('groups', 'groups.id = page_groups.group_id')
            ->where('page_groups.page_id', $id)
            ->orderBy('groups.name')
            ->get()->getResultArray();

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    private static function index(): SearchIndex
    {
        /** @var SearchIndex */
        return service('search');
    }
}
