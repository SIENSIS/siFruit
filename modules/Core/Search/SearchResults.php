<?php

declare(strict_types=1);

namespace Modules\Core\Search;

/**
 * A page of search results.
 */
final class SearchResults
{
    /**
     * @param list<string>    $terms the query's terms (normalised)
     * @param list<SearchHit> $hits  this page's results
     */
    public function __construct(
        public readonly string $query,
        public readonly array $terms,
        public readonly array $hits,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function isEmpty(): bool
    {
        return $this->hits === [];
    }
}
