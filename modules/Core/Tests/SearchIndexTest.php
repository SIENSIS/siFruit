<?php

declare(strict_types=1);

namespace Modules\Core\Tests;

use CodeIgniter\I18n\Time;
use CodeIgniter\Test\Filters\CITestStreamFilter;
use InvalidArgumentException;
use Modules\Auth\Tests\AuthTestCase;
use Modules\Core\Cache\CoreCache;
use Modules\Core\Modules\ModuleDiscovery;
use Modules\Core\Registry\DeclarationRegistry;
use Modules\Core\Search\Highlighter;
use Modules\Core\Search\SearchDocument;
use Modules\Core\Search\SearchIndex;
use Modules\Core\Search\TextNormalizer;

/**
 * The site search index (D-50-01, docs/search.md): normalisation per language, weights and order,
 * documents per locale, idempotent updates and removals, visibility enforced in the query
 * (audience, publish window, disabled modules), forged queries, reindex and purge.
 *
 * @internal
 */
final class SearchIndexTest extends AuthTestCase
{
    private SearchIndex $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new SearchIndex();
        db_connect()->table('search_terms')->truncate();
        db_connect()->table('search_documents')->truncate();
    }

    protected function tearDown(): void
    {
        db_connect()->table('modules')->update(['enabled' => 1]);
        (new CoreCache())->invalidate('modules');
        parent::tearDown();
    }

    public function testTermsAreFoldedWithoutStopWordsPerLanguage(): void
    {
        $this->assertSame(['cami', 'escol', 'collegi'], TextNormalizer::terms('<p>El <b>camí</b> de l\'escola i el col·legi</p>', 'ca'));
        $this->assertSame(['nin', 'arbol', 'jardin', 'arbol'], TextNormalizer::terms('El niño y el árbol del jardín, árboles', 'es'));
        $this->assertSame(['garden', 'hous'], TextNormalizer::terms('The garden of the house', 'en'));
        $this->assertSame(['notici', 'notici', 'cas', 'cas'], TextNormalizer::terms('Notícies notícia cases casa', 'ca'), 'A light suffix rule.');
        $this->assertSame(['der', 'garten'], TextNormalizer::terms('Der Garten', 'de'), 'A language without a list keeps every word.');
        $this->assertSame([], TextNormalizer::terms('a i o', 'ca'));
        $this->assertSame(64, mb_strlen(TextNormalizer::terms(str_repeat('x', 300), 'en')[0]));
    }

    public function testTheTitleWeighsMoreThanTheBodyAndRecencyBreaksTies(): void
    {
        $this->doc('body', ['title' => 'Horaris', 'body' => 'La biblioteca obre a les nou.']);
        $this->doc('title', ['title' => 'Biblioteca', 'body' => 'Horaris i normes.']);
        $this->doc('summary', ['title' => 'Normes', 'summary' => 'Normes de la biblioteca.']);
        $this->doc('old', ['title' => 'Biblioteca antiga', 'date' => Time::now()->subYears(2)]);
        $this->doc('new', ['title' => 'Biblioteca antiga', 'date' => Time::now()]);

        $keys = $this->keys('biblioteca');

        $this->assertSame(['new', 'old', 'title', 'summary', 'body'], array_slice($keys, 0, 5), 'Title ×5 > summary ×2 > body ×1; the newest first among equals.');
        $this->assertEqualsCanonicalizing(['title', 'body'], array_slice($this->keys('biblioteca horaris'), 0, 2), 'Documents matching more terms come first.');
        $this->assertContains('title', $this->keys('bibli'), 'Prefixes of three letters or more match.');
        $this->assertSame([], $this->keys('bi'), 'Two letters match whole terms only.');
    }

    public function testAccentsAndCaseDoNotMatterAndEachLanguageHasItsDocuments(): void
    {
        $this->doc('ca', ['title' => 'Matrícula del curs', 'locale' => 'ca']);
        $this->doc('es', ['title' => 'Matrícula del curso', 'locale' => 'es']);

        $this->assertSame(['ca'], $this->keys('MATRICULA', 'ca'));
        $this->assertSame(['es'], $this->keys('matricula', 'es'));
        $this->assertSame([], $this->keys('matricula', 'en'));
    }

    public function testIndexingIsIdempotentAndReplaceAndRemoveKeepItCurrent(): void
    {
        $doc = $this->document('item', ['title' => 'Concert de primavera']);

        $this->assertTrue($this->index->index($doc));
        $this->assertFalse($this->index->index($doc), 'Equal content changes nothing.');
        $this->assertTrue($this->index->index($this->document('item', ['title' => 'Concert de tardor'])));
        $this->assertSame([], $this->keys('primavera'), 'Old terms are gone.');
        $this->assertSame(['item'], $this->keys('tardor'));

        $this->index->replace('pages.pages', 'item', [$this->document('item', ['title' => 'Concierto', 'locale' => 'es'])]);
        $this->assertSame([], $this->keys('tardor', 'ca'), 'replace() drops the languages no longer given.');
        $this->assertSame(['item'], $this->keys('concierto', 'es'));

        $this->assertTrue($this->index->remove('pages.pages', 'item'));
        $this->assertSame([], $this->keys('concierto', 'es'));
        $this->assertSame(0, db_connect()->table('search_terms')->countAllResults(), 'Terms go with their document.');
    }

    public function testGuestsOnlyFindPublicDocumentsInsideTheirWindow(): void
    {
        $this->doc('public', ['title' => 'Excursió pública']);
        $this->doc('private', ['title' => 'Excursió interna', 'visibility' => 'private']);
        $this->doc('group', ['title' => 'Excursió del claustre', 'visibility' => 'groups', 'groups' => ['editor']]);
        $this->doc('scheduled', ['title' => 'Excursió futura', 'publishFrom' => Time::now()->addDays(2)]);
        $this->doc('expired', ['title' => 'Excursió caducada', 'publishUntil' => Time::now()->subDays(1)]);
        $this->doc('window', ['title' => 'Excursió vigent', 'publishFrom' => Time::now()->subDays(1), 'publishUntil' => Time::now()->addDays(1)]);

        $this->assertEqualsCanonicalizing(['public', 'window'], $this->keys('excursio'));

        $user = $this->createUser('ana@example.com', 'user');
        $this->assertEqualsCanonicalizing(['public', 'window', 'private'], $this->keys('excursio', 'ca', $user), 'Signed-in users also find documents for signed-in users.');

        $editor = $this->createUser('eva@example.com', 'editor');
        $this->assertEqualsCanonicalizing(['public', 'window', 'private', 'group'], $this->keys('excursio', 'ca', $editor), 'Group members find their group\'s documents.');

        $admin = $this->createUser('root@example.com', 'superadmin');
        $this->assertNotContains('group', $this->keys('excursio', 'ca', $admin), 'Superadmins follow the same rule.');
    }

    public function testAGroupNameIsNotMatchedInsideAnotherOne(): void
    {
        $this->doc('group', ['title' => 'Reunió', 'visibility' => 'groups', 'groups' => ['admin']]);

        $this->assertSame([], $this->keys('reunio', 'ca', $this->createUser('ana@example.com', 'user')));
        $this->assertSame(['group'], $this->keys('reunio', 'ca', $this->createUser('adm@example.com', 'admin')));
    }

    public function testDocumentsOfADisabledModuleDisappear(): void
    {
        $this->doc('page', ['title' => 'Jornada de portes obertes']);
        $this->index->index(new SearchDocument('news.posts', 'post-1', 'ca', 'Jornada de portes obertes', 'news/jornada'));

        db_connect()->table('modules')->where('name', 'News')->update(['enabled' => 0]);
        (new CoreCache())->invalidate('modules');

        $this->assertSame(['page'], $this->keys('jornada'));
    }

    public function testForgedQueriesAreHarmless(): void
    {
        $this->doc('doc', ['title' => 'Biblioteca']);

        foreach (["' OR 1=1 --", '%', '_', '"; DROP TABLE search_terms; --', str_repeat('biblioteca ', 500), implode(' ', array_map(static fn (int $i): string => 'mot' . $i, range(1, 300))), "\0\x01biblio"] as $query) {
            $results = $this->index->search($query, 'ca', null);
            $this->assertLessThanOrEqual(SearchIndex::MAX_TERMS, count($results->terms));
        }

        $this->assertSame(1, db_connect()->table('search_documents')->countAllResults());
        $this->assertSame([], $this->keys('%'));
        $this->assertSame([], $this->keys("' OR 1=1 --"));
        $this->assertSame(['doc'], $this->keys(str_repeat('biblioteca ', 500)));
        $this->assertSame([], $this->index->search('', 'ca', null)->hits);
    }

    public function testInvalidDocumentsAreRefused(): void
    {
        foreach ([
            static fn () => new SearchDocument('pages.pages', 'k', 'ca', 'T', 'https://evil.example/'),
            static fn () => new SearchDocument('pages.pages', 'k', 'ca', 'T', '//evil.example/'),
            static fn () => new SearchDocument('pages.pages', 'k k', 'ca', 'T', 'x'),
            static fn () => new SearchDocument('Pages', 'k', 'ca', 'T', 'x'),
            static fn () => new SearchDocument('pages.pages', 'k', 'ca', '  <b></b> ', 'x'),
            static fn () => new SearchDocument('pages.pages', 'k', 'ca', 'T', 'x', visibility: 'everyone'),
            static fn () => new SearchDocument('pages.pages', 'k', 'ca', 'T', 'x', visibility: 'groups', groups: ['a|b']),
        ] as $i => $make) {
            try {
                $make();
                $this->fail("Document {$i} should be refused.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->index->index(new SearchDocument('nobody.things', 'k', 'ca', 'T', 'x'));
    }

    public function testHighlightingEscapesTheText(): void
    {
        $html = Highlighter::highlight('<script>alert(1)</script> Biblioteca & Notícies', SearchIndex::queryTerms('biblioteca notícia', 'ca'), 'ca');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('<mark>Biblioteca</mark>', $html);
        $this->assertStringContainsString('<mark>Notícies</mark>', $html);
        $this->assertStringContainsString('&amp;', $html);
    }

    public function testReindexRebuildsTheSourcesAndDropsUndeclaredOnes(): void
    {
        $this->storeDeclarations();
        $this->index->index(new SearchDocument('pages.pages', 'page-999', 'ca', 'Pàgina que ja no existeix', 'x'));
        db_connect()->table('search_documents')->insert(['module' => 'Gone', 'source' => 'gone.things', 'item_key' => 'k', 'locale' => 'ca', 'title' => 'T', 'url' => 'x', 'visibility' => 'public', 'hash' => str_repeat('0', 40)]);

        $counts = $this->index->reindex();

        $this->assertArrayHasKey('pages.pages', $counts);
        $this->assertArrayHasKey('news.posts', $counts);
        $this->assertSame(0, db_connect()->table('search_documents')->where('item_key', 'page-999')->countAllResults(), 'The source is rebuilt from its SearchSource.');
        $this->assertSame(0, db_connect()->table('search_documents')->where('source', 'gone.things')->countAllResults(), 'Undeclared sources are dropped.');
    }

    public function testTheReindexCommandAndThePurgeOfAModule(): void
    {
        $this->storeDeclarations();
        $this->doc('page', ['title' => 'Pàgina']);

        CITestStreamFilter::registration();
        CITestStreamFilter::addOutputFilter();
        CITestStreamFilter::addErrorFilter();
        command('search:reindex Pages');
        command('search:reindex "../x"');
        $output = CITestStreamFilter::$buffer;
        CITestStreamFilter::removeOutputFilter();
        CITestStreamFilter::removeErrorFilter();

        $this->assertStringContainsString('pages.pages', $output);
        $this->assertStringNotContainsString('news.posts', $output, 'Only the module asked for.');

        $this->index->index(new SearchDocument('news.posts', 'post-1', 'ca', 'Notícia', 'news/n'));
        $this->assertSame(1, $this->index->purgeModule('News'));
        $this->assertSame(0, db_connect()->table('search_documents')->where('module', 'News')->countAllResults());
    }

    private function storeDeclarations(): void
    {
        $manifests = [];

        foreach ((new ModuleDiscovery())->discover() as $name => $module) {
            if ($module->isValid() && $module->manifest !== null) {
                $manifests[$name] = $module->manifest;
            }
        }

        $registry = new DeclarationRegistry();
        $registry->store($registry->build($manifests));
        (new CoreCache())->invalidate('modules');
    }

    /**
     * @param array<string, mixed> $values
     */
    private function doc(string $key, array $values): void
    {
        $this->index->index($this->document($key, $values));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function document(string $key, array $values): SearchDocument
    {
        return new SearchDocument(
            source: 'pages.pages',
            key: $key,
            locale: $values['locale'] ?? 'ca',
            title: $values['title'],
            url: 'page/' . $key,
            summary: $values['summary'] ?? '',
            body: $values['body'] ?? '',
            date: $values['date'] ?? null,
            visibility: $values['visibility'] ?? 'public',
            groups: $values['groups'] ?? [],
            publishFrom: $values['publishFrom'] ?? null,
            publishUntil: $values['publishUntil'] ?? null,
        );
    }

    /**
     * @return list<string>
     */
    private function keys(string $query, string $locale = 'ca', ?\CodeIgniter\Shield\Entities\User $user = null): array
    {
        return array_map(static fn ($hit): string => $hit->key, $this->index->search($query, $locale, $user, 1, 50)->hits);
    }
}
