<?php

declare(strict_types=1);

namespace Modules\Core\Search;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;
use CodeIgniter\Shield\Entities\User;
use DateTimeInterface;
use InvalidArgumentException;
use Modules\Core\Registry\DeclarationRegistry;
use Modules\Core\Registry\ModuleState;
use Throwable;

/**
 * The site search (`service('search')`, D-50-01, docs/search.md): an index of its own in two tables
 * (`search_documents`, `search_terms`) that works the same on MariaDB and SQLite.
 *
 * Modules keep it current through the contract: `index()` / `replace()` on every change of an
 * item, `remove()` when it must no longer be found, a `SearchSource` for `reindex()`
 * (`php spark search:reindex`, `modules:sync`). `search()` answers a query in one language for a
 * visitor: **visibility is enforced in the query** (audience, publish window, enabled modules), so
 * nothing the visitor may not see is ever loaded.
 *
 * Every value reaches the database through the Query Builder (bound or escaped).
 */
class SearchIndex
{
    /** Weights of each field in the ranking. */
    public const WEIGHTS = ['title' => 5, 'metaTitle' => 3, 'keywords' => 3, 'summary' => 2, 'metaDescription' => 2, 'body' => 1];

    /** The longest query read (characters) and the most terms used. */
    public const MAX_QUERY = 200;

    public const MAX_TERMS = 8;

    /** Matching rows read for one query (the ranking is done on them). */
    public const MAX_CANDIDATES = 5000;

    /** A prefix match counts this share of the term's weight. */
    private const PREFIX_FACTOR = 0.6;

    /** @var array<string, string>|null source id => module */
    private ?array $sources = null;

    /**
     * @param BaseConnection<object|resource, object|resource>|null $db
     */
    public function __construct(
        private readonly ?BaseConnection $db = null,
        private readonly ModuleState $state = new ModuleState(),
    ) {
    }

    /**
     * Stores or updates one document (one item in one language). Idempotent: equal content changes
     * nothing. Throws `InvalidArgumentException` for a source no module declares.
     *
     * @return bool whether the index changed
     */
    public function index(SearchDocument $document): bool
    {
        $module = $this->moduleOf($document->source);
        $row    = $this->row($document, $module);
        $table  = $this->db()->table('search_documents');
        $stored = $table->select('id, hash')->where('source', $document->source)->where('item_key', $document->key)->where('locale', $document->locale)->get(1)->getRowArray();

        if (is_array($stored) && $stored['hash'] === $row['hash']) {
            return false;
        }

        $terms = $this->termsOf($document);
        $db    = $this->db();
        $db->transStart();

        if (is_array($stored)) {
            $id = (int) $stored['id'];
            $db->table('search_documents')->where('id', $id)->update($row);
            $db->table('search_terms')->where('document_id', $id)->delete();
        } else {
            $db->table('search_documents')->insert($row);
            $id = (int) $db->insertID();
        }

        if ($terms !== []) {
            $rows = [];

            foreach ($terms as $term => $weight) {
                $rows[] = ['document_id' => $id, 'term' => (string) $term, 'weight' => min(32000, $weight)];
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                $db->table('search_terms')->insertBatch($chunk);
            }
        }

        $db->transComplete();

        return true;
    }

    /**
     * The documents of one item in every language: stores the given ones and removes the languages
     * no longer given (an empty list removes the item).
     *
     * @param iterable<SearchDocument> $documents
     *
     * @return bool whether the index changed
     */
    public function replace(string $source, string $key, iterable $documents): bool
    {
        $changed = false;
        $locales = [];

        foreach ($documents as $document) {
            if ($document->source !== $source || $document->key !== $key) {
                throw new InvalidArgumentException('replace() takes documents of one item only.');
            }

            $locales[] = $document->locale;
            $changed   = $this->index($document) || $changed;
        }

        $stale = $this->db()->table('search_documents')->select('id')->where('source', $source)->where('item_key', $key);

        if ($locales !== []) {
            $stale->whereNotIn('locale', $locales);
        }

        return $this->deleteIds(array_column($stale->get()->getResultArray(), 'id')) > 0 || $changed;
    }

    /**
     * Removes an item (every language, or one).
     *
     * @return bool whether the index changed
     */
    public function remove(string $source, string $key, ?string $locale = null): bool
    {
        $builder = $this->db()->table('search_documents')->select('id')->where('source', $source)->where('item_key', $key);

        if ($locale !== null) {
            $builder->where('locale', $locale);
        }

        return $this->deleteIds(array_column($builder->get()->getResultArray(), 'id')) > 0;
    }

    /**
     * Deletes every document of a module for good (the module is being uninstalled).
     *
     * @return int how many documents were deleted
     */
    public function purgeModule(string $module): int
    {
        return $this->deleteIds(array_column($this->db()->table('search_documents')->select('id')->where('module', $module)->get()->getResultArray(), 'id'));
    }

    /**
     * Rebuilds the sources of the enabled modules (or of one module) from their `SearchSource`,
     * and, for a full rebuild, drops documents of sources nobody declares any more.
     *
     * @return array<string, int> source id => documents indexed
     */
    public function reindex(?string $module = null): array
    {
        $counts   = [];
        $declared = (new DeclarationRegistry($this->db))->all('searchSource');

        foreach ($declared as $qualified => $declaration) {
            if ($module !== null && strcasecmp($declaration['module'], $module) !== 0) {
                continue;
            }

            $source = self::sourceId($declaration['module'], substr($qualified, strlen($declaration['module']) + 1));
            $class  = $declaration['definition']['source'] ?? null;

            if (! is_string($class) || ! is_a($class, SearchSource::class, true)) {
                continue;
            }

            $this->deleteIds(array_column($this->db()->table('search_documents')->select('id')->where('source', $source)->get()->getResultArray(), 'id'));
            $counts[$source] = 0;

            foreach ((new $class())->documents() as $document) {
                if ($document->source !== $source) {
                    continue;
                }

                $this->index($document);
                $counts[$source]++;
            }
        }

        if ($module === null) {
            $known = array_keys($this->sources(true));
            $stale = $this->db()->table('search_documents')->select('id');

            if ($known !== []) {
                $stale->whereNotIn('source', $known);
            }

            $this->deleteIds(array_column($stale->get()->getResultArray(), 'id'));
        }

        return $counts;
    }

    /**
     * Searches the documents of a language that the visitor may see.
     *
     * @param list<string>|null $sources only these source ids (null: every source)
     */
    public function search(string $query, string $locale, ?User $user, int $page = 1, int $perPage = 10, ?array $sources = null): SearchResults
    {
        $terms   = self::queryTerms($query, $locale);
        $perPage = max(1, min(50, $perPage));
        $page    = max(1, $page);

        if ($terms === []) {
            return new SearchResults($query, $terms, [], 0, $page, $perPage);
        }

        $builder = $this->db()->table('search_terms')
            ->select('search_terms.document_id, search_terms.term, search_terms.weight')
            ->join('search_documents', 'search_documents.id = search_terms.document_id')
            ->where('search_documents.locale', $locale);
        $this->visible($builder, $user);

        if ($sources !== null) {
            if ($sources === []) {
                return new SearchResults($query, $terms, [], 0, $page, $perPage);
            }

            $builder->whereIn('search_documents.source', $sources);
        }

        $builder->groupStart();

        foreach ($terms as $term) {
            mb_strlen($term, 'UTF-8') >= 3 ? $builder->orLike('search_terms.term', $term, 'after') : $builder->orWhere('search_terms.term', $term);
        }

        $rows = $builder->groupEnd()->limit(self::MAX_CANDIDATES)->get()->getResultArray();

        // Ranking: documents matching more of the query's terms first, then by score (an exact
        // term counts its whole weight, a prefix a share of it), then the most recent.
        $scores = [];

        foreach ($rows as $row) {
            $id = (int) $row['document_id'];

            foreach ($terms as $index => $term) {
                $stored = (string) $row['term'];

                if ($stored === $term || (mb_strlen($term, 'UTF-8') >= 3 && str_starts_with($stored, $term))) {
                    $score               = (float) $row['weight'] * ($stored === $term ? 1.0 : self::PREFIX_FACTOR);
                    $scores[$id][$index]        = max($scores[$id][$index] ?? 0.0, $score);
                }
            }
        }

        if ($scores === []) {
            return new SearchResults($query, $terms, [], 0, $page, $perPage);
        }

        $documents = [];

        foreach (array_chunk(array_keys($scores), 500) as $ids) {
            foreach ($this->db()->table('search_documents')->select('id, source, item_key, locale, title, excerpt, url, date, module')->whereIn('id', $ids)->get()->getResultArray() as $document) {
                $documents[(int) $document['id']] = $document;
            }
        }

        $ranked = [];

        foreach ($scores as $id => $perTerm) {
            if (! isset($documents[$id])) {
                continue;
            }

            $ranked[] = ['id' => $id, 'matched' => count($perTerm), 'score' => array_sum($perTerm), 'date' => (string) ($documents[$id]['date'] ?? '')];
        }

        usort($ranked, static fn (array $a, array $b): int => [$b['matched'], $b['score'], $b['date'], $a['id']] <=> [$a['matched'], $a['score'], $a['date'], $b['id']]);

        $total = count($ranked);
        $hits  = [];

        foreach (array_slice($ranked, ($page - 1) * $perPage, $perPage) as $item) {
            $document = $documents[$item['id']];
            $hits[]   = new SearchHit(
                source: (string) $document['source'],
                key: (string) $document['item_key'],
                locale: (string) $document['locale'],
                title: (string) $document['title'],
                excerpt: (string) $document['excerpt'],
                url: (string) $document['url'],
                date: is_string($document['date'] ?? null) && $document['date'] !== '' ? $document['date'] : null,
                module: (string) $document['module'],
                score: $item['score'],
                matched: $item['matched'],
            );
        }

        return new SearchResults($query, $terms, $hits, $total, $page, $perPage);
    }

    /**
     * The terms of a query: at most `MAX_QUERY` characters and `MAX_TERMS` distinct terms.
     *
     * @return list<string>
     */
    public static function queryTerms(string $query, string $locale): array
    {
        $query = mb_substr($query, 0, self::MAX_QUERY, 'UTF-8');

        return array_slice(array_values(array_unique(TextNormalizer::terms($query, $locale))), 0, self::MAX_TERMS);
    }

    /**
     * The id of a source: `<module in lower case>.<key>`.
     */
    public static function sourceId(string $module, string $key): string
    {
        return strtolower($module) . '.' . $key;
    }

    /**
     * The label of each source of the enabled modules (their manifest `label`, translated):
     * source id => text.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];

        foreach ((new DeclarationRegistry())->searchSources() as $qualified => $declaration) {
            $key   = substr($qualified, strlen($declaration['module']) + 1);
            $label = $declaration['definition']['label'] ?? null;

            $labels[self::sourceId($declaration['module'], $key)] = is_string($label) ? lang($label) : $key;
        }

        return $labels;
    }

    /**
     * Declared sources (every module, enabled or not): source id => module.
     *
     * @return array<string, string>
     */
    public function sources(bool $refresh = false): array
    {
        if ($this->sources === null || $refresh) {
            $this->sources = [];

            try {
                $rows = $this->db()->table('module_declarations')->select('module, key')->where('kind', 'searchSource')->get()->getResultArray();
            } catch (Throwable) {
                $rows = [];
            }

            foreach ($rows as $row) {
                $this->sources[self::sourceId((string) $row['module'], (string) $row['key'])] = (string) $row['module'];
            }
        }

        return $this->sources;
    }

    /**
     * The audience, the publish window and the enabled modules, as conditions on `search_documents`.
     *
     * @param BaseBuilder<BaseConnection<object|resource, object|resource>> $builder
     */
    private function visible(BaseBuilder $builder, ?User $user): void
    {
        $now = Time::now()->toDateTimeString();

        $builder->groupStart()->where('search_documents.publish_from', null)->orWhere('search_documents.publish_from <=', $now)->groupEnd()
            ->groupStart()->where('search_documents.publish_until', null)->orWhere('search_documents.publish_until >', $now)->groupEnd();

        $disabled = $this->state->disabled();

        if ($disabled !== []) {
            $builder->whereNotIn('search_documents.module', $disabled);
        }

        if ($user === null) {
            $builder->where('search_documents.visibility', 'public');

            return;
        }

        $groups = array_values(array_filter($user->getGroups() ?? [], static fn ($group): bool => is_string($group) && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $group) === 1));

        $builder->groupStart()->whereIn('search_documents.visibility', ['public', 'private']);

        if ($groups !== []) {
            $builder->orGroupStart()->where('search_documents.visibility', 'groups')->groupStart();

            foreach ($groups as $group) {
                $builder->orLike('search_documents.audience_groups', '|' . $group . '|', 'both');
            }

            $builder->groupEnd()->groupEnd();
        }

        $builder->groupEnd();
    }

    /**
     * @return array<string, int> term => weight
     */
    private function termsOf(SearchDocument $document): array
    {
        $weights = [];

        foreach (self::WEIGHTS as $field => $weight) {
            $counts = array_count_values(TextNormalizer::terms((string) $document->{$field}, $document->locale));

            foreach ($counts as $term => $count) {
                // Repetitions count a little (at most three times per field).
                $weights[(string) $term] = ($weights[(string) $term] ?? 0) + $weight * min(3, $count);
            }
        }

        return $weights;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SearchDocument $document, string $module): array
    {
        $excerpt = TextNormalizer::plain($document->summary);
        $excerpt = $excerpt !== '' ? $excerpt : TextNormalizer::plain($document->metaDescription);
        $excerpt = $excerpt !== '' ? $excerpt : TextNormalizer::plain($document->body);

        $row = [
            'module'          => $module,
            'source'          => $document->source,
            'item_key'        => $document->key,
            'locale'          => $document->locale,
            'title'           => mb_substr(TextNormalizer::plain($document->title), 0, 255, 'UTF-8'),
            'excerpt'         => self::cut($excerpt, 300),
            'url'             => ltrim($document->url, '/'),
            'date'            => self::time($document->date),
            'visibility'      => $document->visibility,
            'audience_groups' => $document->groups === [] ? null : '|' . implode('|', $document->groups) . '|',
            'publish_from'    => self::time($document->publishFrom),
            'publish_until'   => self::time($document->publishUntil),
        ];

        $row['hash']       = sha1(json_encode([$row, $this->termsOf($document)], JSON_THROW_ON_ERROR));
        $row['indexed_at'] = Time::now()->toDateTimeString();

        return $row;
    }

    /**
     * The module of a source: the one that declares it, else (declarations not stored yet, e.g.
     * before the first `modules:sync` of a new source) the registered module whose name in lower
     * case is the source's prefix. A full `reindex()` drops documents of undeclared sources.
     */
    private function moduleOf(string $source): string
    {
        $module = $this->sources()[$source] ?? $this->sources(true)[$source] ?? null;

        if ($module !== null) {
            return $module;
        }

        $prefix = explode('.', $source, 2)[0];

        try {
            $names = array_column($this->db()->table('modules')->select('name')->get()->getResultArray(), 'name');
        } catch (Throwable) {
            $names = [];
        }

        foreach ($names as $name) {
            if (strtolower((string) $name) === $prefix) {
                return (string) $name;
            }
        }

        throw new InvalidArgumentException('Unknown search source "' . $source . '": declare it in the manifest\'s $searchSources and run modules:sync.');
    }

    /**
     * @param list<mixed> $ids
     */
    private function deleteIds(array $ids): int
    {
        $ids = array_map('intval', $ids);

        if ($ids === []) {
            return 0;
        }

        $db = $this->db();
        $db->transStart();

        foreach (array_chunk($ids, 500) as $chunk) {
            $db->table('search_terms')->whereIn('document_id', $chunk)->delete();
            $db->table('search_documents')->whereIn('id', $chunk)->delete();
        }

        $db->transComplete();

        return count($ids);
    }

    private static function time(?DateTimeInterface $time): ?string
    {
        if ($time === null) {
            return null;
        }

        return Time::createFromInstance($time)->setTimezone(config('App')->appTimezone ?: 'UTC')->toDateTimeString();
    }

    private static function cut(string $text, int $length): string
    {
        if (mb_strlen($text, 'UTF-8') <= $length) {
            return $text;
        }

        $cut   = mb_substr($text, 0, $length, 'UTF-8');
        $space = mb_strrpos($cut, ' ', 0, 'UTF-8');

        return rtrim($space !== false && $space > $length / 2 ? mb_substr($cut, 0, $space, 'UTF-8') : $cut, ' ,.;:') . '…';
    }

    /**
     * @return BaseConnection<object|resource, object|resource>
     */
    private function db(): BaseConnection
    {
        return $this->db ?? db_connect();
    }
}
