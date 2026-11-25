# Site search

A search box in the public header finds pages, news and any module's content **in the visitor's
language**, ranked by where the words appear, and **never shows anything the visitor may not see**
(decision D-50-01). It is a Core service fed by the modules through a contract, like the
[calendar](calendar.md); the chat assistant (session 51) uses the same index.

No external service and no database-specific full-text feature: an index of our own in two tables
that works the same on MariaDB and SQLite.

## For visitors

- The box is in the public navbar (inside the collapsed menu on phones; at tablet widths, where
  the expanded navbar is narrow, a search icon links to the results page and its form). It is a theme option of
  the default theme, `showSearch` (on by default; Settings › Themes, [`themes.md`](themes.md)).
- The results page is `search?q=<words>` with the language prefix (`/search` in the default
  language, `/es/search?q=…` in Spanish, D-37-02). Each result shows its source (Pages, News…),
  date, title and excerpt with the matching words highlighted; 10 results per page, up to 50 pages.
- With no results: tips and, when a route named `contact` exists and its module is enabled (the
  Contact module), a link to the contact form.
- Signed-in users also find what they may read when signed in (private pages, private news through
  the reader, their groups' content). Results are never cached across visitors
  (`Cache-Control: private, no-store`) and the page is `noindex`.
- Rate limit: `Core.searchRateLimit` searches per minute (30 by default; Settings › Security ›
  Rate limits), per IP address for guests and per user when signed in (`ratelimit:search`); past
  it the page answers 429.

## How it works

| Piece | Where |
|---|---|
| Contract | `modules/Core/Search/`: `SearchDocument`, `SearchSource`, `SearchIndex` (`service('search')`), `SearchResults`, `SearchHit`, `TextNormalizer`, `StopWords`, `Highlighter` |
| Tables | `search_documents` (one row per item and language: title, excerpt, path, date, module, source, audience, groups, publish window, hash), `search_terms` (document, term, weight) |
| Public page | `Modules\Core\Controllers\Search` (route `search`, filter `ratelimit:search`), view `modules/Core/Views/search/results.php` |
| Box | `themes/default/Views/partials/search_box.php` (theme option `showSearch`) |
| Command | `php spark search:reindex [<Module>]` |
| Setting | `Core.searchRateLimit` (Settings › Security › Rate limits) |

### Terms

Texts are reduced to terms the same way when indexing and when searching (`TextNormalizer`):
HTML stripped, lower case, accents folded (`àéïçñ` → `aeicn`, `l·l` → `ll`), split on anything
that is not a letter or a digit, terms of 2 to 64 characters, the language's **stop words**
removed (`StopWords`: Catalan, Spanish, English) and a light suffix rule in those three languages
(a final `s`, then a final `a`/`e`/`o`, dropped from words of 4+ characters: `cases`, `casa` →
`cas`; `notícies`, `notícia` → `notici`).

A query uses at most 200 characters and 8 distinct terms. A term of 3+ characters also matches
the terms that start with it (`bibli` finds `biblioteca`), at 60 % of the weight; a two-letter
term matches whole terms only.

### Ranking

Weight of each field: **title ×5**, **meta title and keywords ×3**, **summary and meta
description ×2**, **body ×1** (a word repeated counts up to three times per field). Results are
ordered by the number of query terms they match, then by score, then by date (newest first).
The ranking is computed on at most 5,000 matching rows (`SearchIndex::MAX_CANDIDATES`).

### Visibility (enforced in the query)

Every document stores its audience and publish window, and the search query filters by them:

- guests: `public` documents only;
- signed-in users: `public`, `private` and `groups` documents of one of their groups (superadmins
  follow the same rule);
- only inside the window: `publish_from` passed (or empty) and `publish_until` not passed (or
  empty) — a scheduled page appears by itself when its date comes, an expiring notice leaves;
- never documents of a **disabled module**; **uninstalling** a module deletes its documents
  (`SearchIndex::purgeModule()`, called by the Core's `Uninstaller`).

Drafts, items in the trash and anything that must not be found are not indexed at all. Every
value reaches the database through the Query Builder (bound or escaped); highlighting escapes the
text and only adds `<mark>`.

## For module developers

### 1. Declare the source

```php
// modules/Courses/Config/Module.php
public array $searchSources = [
    'courses' => [
        'label'  => 'Courses.search.source',                  // language key (the results' source label)
        'source' => \Modules\Courses\Search\CoursesSearch::class, // a SearchSource
    ],
];
```

The source's id is `<module in lower case>.<key>` (`courses.courses`). `php spark modules:sync`
stores the declaration (and rebuilds the index).

### 2. Keep it current on every change

```php
use Modules\Core\Search\SearchDocument;

$documents = [];

foreach (ContentTranslations::codes() as $locale) {           // one document per site language
    $text = ContentTranslations::pick($translations, ['title', 'summary', 'body'], $locale);

    $documents[] = new SearchDocument(
        source: 'courses.courses',
        key: 'course-' . $id,                    // stable id: [A-Za-z0-9._:-]{1,150}
        locale: $locale,
        title: $text['title'],
        url: 'courses/' . $slug,                 // path WITHOUT the language prefix, or backend_link('…')
        summary: $text['summary'],
        body: $text['body'],                     // HTML is stripped
        keywords: ['robotics', 'stem'],          // list or string
        metaTitle: '', metaDescription: '',
        date: $updatedAt,
        visibility: 'groups',                    // public | private (signed-in users) | groups
        groups: ['students'],                    // group names
        publishFrom: $publishAt, publishUntil: $endsAt,
    );
}

service('search')->replace('courses.courses', 'course-' . $id, $documents); // every language at once
service('search')->remove('courses.courses', 'course-' . $id);             // deleted, draft, trashed
```

- `index(SearchDocument)` stores one document (idempotent: equal content changes nothing);
  `replace()` stores the item's documents and removes the languages no longer given (an empty list
  removes the item); `remove()` deletes it (every language or one). All return whether the index
  changed.
- Index **only what may be found**, with its real audience: the index trusts the document.
- The URL is a public path without the language prefix (the result links to it in the document's
  language, D-37-02) or a stored back-office link (`backend_link()`, D-40-02). Absolute URLs are
  refused.
- Invalid input (unknown source, a key or locale out of format, an empty title, an absolute URL,
  an unknown visibility, a group name with other characters) throws `InvalidArgumentException`.
- Call it wherever the item changes: the CRUD's `afterWrite()`, custom operations, scheduled
  tasks, the demo seeder (the Core also rebuilds a module's sources after `modules:demo`).

Reference implementations: `modules/Assistant/Search/FaqSearch.php` (the FAQs of the chat
assistant, `assistant.faqs`, [`assistant.md`](assistant.md)), `modules/Pages/Search/PagesSearch.php` (one call in
`Revisions::record()` covers every content change; subpages follow their parent's slug) and
`modules/News/Search/NewsSearch.php` (next to `NoticeCalendar::sync()`).

### 3. The source class (rebuilds)

```php
final class CoursesSearch implements \Modules\Core\Search\SearchSource
{
    public function documents(): iterable
    {
        foreach ($this->publishedCourses() as $course) {
            yield from $this->documentsOf($course);   // the same documents as in step 2
        }
    }
}
```

`php spark search:reindex [<Module>]` and `modules:sync` delete each source's documents and index
what `documents()` returns; a full rebuild also drops documents of sources nobody declares.

### Searching from code

```php
$results = service('search')->search($query, $locale, $user, page: 1, perPage: 10, sources: ['news.posts']);
foreach ($results->hits as $hit) { $hit->title; $hit->excerpt; $hit->link(); }
Highlighter::highlight($hit->excerpt, $results->terms, $locale); // escaped HTML with <mark>
```

### The chat assistant

The Assistant module ([`assistant.md`](assistant.md)) answers from the source `assistant.faqs`
first and then from the sources chosen in `Assistant.sources`. It uses `SearchHit::$matched` (how
many of the query's terms a result matched) and `$score` to decide whether an FAQ is close enough.
`SearchIndex::labels()` gives each source's label.

## Adding a language

A language without a stop-word list keeps every word and has no suffix rule. Add its list to
`Modules\Core\Search\StopWords` (and the language to `TextNormalizer::PLURAL_S` if the rule suits
it), then `php spark search:reindex`.

## Tests

`modules/Core/Tests/SearchIndexTest.php` (normalisation, weights, locales, idempotency, visibility,
disabled modules, forged queries, reindex, purge), `SiteSearchPageTest.php` (box, results per
language, escaped highlighting, contact link, rate limit), `modules/Pages/Tests/PagesSearchTest.php`,
`modules/News/Tests/NewsSearchTest.php`. To run them on MariaDB, see `phpunit.xml.dist`.
