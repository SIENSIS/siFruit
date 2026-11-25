<?php

declare(strict_types=1);

namespace Modules\Core\Search;

use DateTimeInterface;
use InvalidArgumentException;
use Modules\Core\Content\Audience;

/**
 * One item of a module in one language, as the site search stores it (D-50-01, docs/search.md).
 *
 * - `source` is a declared search source (`<module in lower case>.<key>`, manifest `$searchSources`)
 *   and `key` the item's stable id inside it (`[A-Za-z0-9._:-]{1,150}`, e.g. `page-12`);
 * - the texts are plain text or HTML (tags are stripped, entities decoded); their weights in the
 *   ranking: `title` ×5, `metaTitle` and `keywords` ×3, `summary` and `metaDescription` ×2, `body` ×1;
 * - `url` is the item's path without the language prefix (`about/team`, `news/my-post`), which the
 *   index localises when it shows the result (D-37-02), or a stored back-office link
 *   (`backend_link('news/read/…')`, D-40-02);
 * - `visibility` is the audience (`public`, `private` = signed-in users, `groups` = members of
 *   `groups`, group names), and `publishFrom` / `publishUntil` the publish window: the search
 *   query filters by them, so nothing a visitor may not see is ever returned.
 *
 * Drafts, items in the trash and anything that must not be found are simply not indexed (the
 * module calls `remove()`).
 */
final class SearchDocument
{
    public const KEY = '/^[A-Za-z0-9._:-]{1,150}$/';

    public const SOURCE = '/^[a-z][a-z0-9]*\.[a-z][a-z0-9]*$/';

    /** @var list<string> */
    public readonly array $groups;

    public readonly string $keywords;

    /**
     * @param list<string>|string $keywords extra words (a list or a comma/space separated string)
     * @param list<string>        $groups   group names when `visibility` is `groups`
     */
    public function __construct(
        public readonly string $source,
        public readonly string $key,
        public readonly string $locale,
        public readonly string $title,
        public readonly string $url,
        public readonly string $summary = '',
        public readonly string $body = '',
        array|string $keywords = '',
        public readonly string $metaTitle = '',
        public readonly string $metaDescription = '',
        public readonly ?DateTimeInterface $date = null,
        public readonly string $visibility = 'public',
        array $groups = [],
        public readonly ?DateTimeInterface $publishFrom = null,
        public readonly ?DateTimeInterface $publishUntil = null,
    ) {
        if (preg_match(self::SOURCE, $source) !== 1) {
            throw new InvalidArgumentException('Invalid search source "' . $source . '": expected "<module>.<key>" in lower case.');
        }

        if (preg_match(self::KEY, $key) !== 1) {
            throw new InvalidArgumentException('Invalid search document key "' . $key . '".');
        }

        if (preg_match('/^[a-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/', $locale) !== 1) {
            throw new InvalidArgumentException('Invalid locale "' . $locale . '".');
        }

        if (trim(TextNormalizer::plain($title)) === '') {
            throw new InvalidArgumentException('A search document needs a title.');
        }

        if (! in_array($visibility, Audience::VISIBILITIES, true)) {
            throw new InvalidArgumentException('Unknown visibility "' . $visibility . '".');
        }

        // Internal paths only: no scheme, no protocol-relative URL, no backslashes.
        if ($url === '' || strlen($url) > 500 || preg_match('#^[a-z][a-z0-9+.-]*:|^//|\\\\#i', $url) === 1) {
            throw new InvalidArgumentException('A search document URL must be an internal path.');
        }

        $groups = array_values(array_unique(array_filter(array_map(static fn ($group): string => trim((string) $group), $groups), static fn (string $group): bool => $group !== '')));

        foreach ($groups as $group) {
            if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $group) !== 1) {
                throw new InvalidArgumentException('Invalid group name "' . $group . '".');
            }
        }

        // A `groups` document without groups is found by nobody (closed by default).
        $this->groups  = $visibility === 'groups' ? $groups : [];
        $this->keywords = is_array($keywords) ? implode(' ', array_map('strval', $keywords)) : $keywords;
    }

    /**
     * The id of the module that declares the source (`pages` for `pages.pages`), in lower case.
     */
    public function modulePrefix(): string
    {
        return explode('.', $this->source, 2)[0];
    }
}
