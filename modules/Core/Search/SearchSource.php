<?php

declare(strict_types=1);

namespace Modules\Core\Search;

/**
 * Every document a search source should have in the index now, for `php spark search:reindex`
 * and `modules:sync` (they rebuild the source from it; docs/search.md). Declared as
 * `'source' => Class::class` in the manifest's `$searchSources`.
 *
 * Return only what may be found: published items inside their window, with their audience. One
 * document per item and language.
 */
interface SearchSource
{
    /**
     * @return iterable<SearchDocument>
     */
    public function documents(): iterable;
}
