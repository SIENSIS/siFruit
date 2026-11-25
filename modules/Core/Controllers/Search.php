<?php

declare(strict_types=1);

namespace Modules\Core\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;
use Modules\Core\Frontend\ContactLink;
use Modules\Core\Search\SearchIndex;

/**
 * The public results page of the site search (`GET search?q=…&page=…`, D-50-01, docs/search.md),
 * in the visitor's language (`/es/search`, D-37-02). Rate-limited (`ratelimit:search`,
 * `Core.searchRateLimit`), `noindex`, never cached across visitors; what a visitor finds is
 * decided by the index's query (audience, publish window, enabled modules).
 */
final class Search extends FrontendController
{
    public const PER_PAGE = 10;

    /** The deepest page served (deeper pages answer 404). */
    public const MAX_PAGE = 50;

    public function index(): string
    {
        $query = $this->request->getGet('q');
        $query = is_string($query) ? trim(mb_substr($query, 0, SearchIndex::MAX_QUERY, 'UTF-8')) : '';
        $page  = $this->request->getGet('page');
        $page  = is_string($page) && ctype_digit($page) ? (int) $page : 1;

        if ($page < 1 || $page > self::MAX_PAGE) {
            throw PageNotFoundException::forPageNotFound();
        }

        $locale  = $this->request->getLocale();
        $user    = auth()->loggedIn() ? auth()->user() : null;
        $results = null;

        if ($query !== '') {
            /** @var SearchIndex $index */
            $index   = service('search');
            $results = $index->search($query, $locale, $user, $page, self::PER_PAGE);

            if ($page > 1 && $results->isEmpty()) {
                throw PageNotFoundException::forPageNotFound();
            }
        }

        // Results depend on the visitor: never stored by shared caches, never indexed.
        $this->response->setHeader('Cache-Control', 'private, no-store');
        $this->meta(['robots' => 'noindex, follow', 'canonical' => site_url('search')]);

        $pager = '';

        if ($results !== null && $results->total > self::PER_PAGE) {
            $pager = service('pager')->setPath('search')->makeLinks($page, self::PER_PAGE, min($results->total, self::PER_PAGE * self::MAX_PAGE));
        }

        return $this->render('Modules\Core\Views\search\results', [
            'title'      => $query === '' ? lang('Core.search.title') : lang('Core.search.titleFor', [$query]),
            'query'      => $query,
            'results'    => $results,
            'locale'     => $locale,
            'pager'      => $pager,
            'labels'     => SearchIndex::labels(),
            'contactUrl' => $results !== null && $results->isEmpty() ? ContactLink::url() : null,
        ]);
    }
}
