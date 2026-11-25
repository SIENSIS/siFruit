<?php

declare(strict_types=1);

namespace Modules\Core\Search;

use Modules\Core\I18n\LocaleUrls;
use Modules\Core\Routing\BackendPath;

/**
 * One result of `service('search')->search()`: `$score` is the sum of the weights of the query's
 * terms it matched, `$matched` how many of the query's terms it matched (callers such as the chat
 * assistant compare them with `count(SearchResults::$terms)` to decide whether it is close enough).
 */
final class SearchHit
{
    public function __construct(
        public readonly string $source,
        public readonly string $key,
        public readonly string $locale,
        public readonly string $title,
        public readonly string $excerpt,
        public readonly string $url,
        public readonly ?string $date,
        public readonly string $module,
        public readonly float $score,
        public readonly int $matched = 0,
    ) {
    }

    /**
     * The absolute URL of the result: a public path in the document's language (with its prefix,
     * D-37-02), or a stored back-office link resolved under the current prefix (D-40-02).
     */
    public function link(): string
    {
        $path = BackendPath::resolve($this->url);

        /** @var LocaleUrls $urls */
        $urls = service('localeurls');

        if ($urls->enabled() && $urls->isPublic($path)) {
            return $urls->url($path, $this->locale);
        }

        return site_url($path);
    }
}
