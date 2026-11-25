<?php

declare(strict_types=1);

namespace Modules\Pages\Tests;

use CodeIgniter\I18n\Time;
use Modules\Core\Cache\CoreCache;
use Modules\Core\Registry\DeclarationRegistry;
use Modules\Core\Search\SearchIndex;
use Modules\Pages\Config\Module;
use Modules\Pages\Search\PagesSearch;
use Modules\Pages\Services\Revisions;

/**
 * Pages in the site search (D-50-01, docs/pages.md "Search"): every change keeps the index
 * current (create, edit, sections, publish/unpublish, trash/restore), drafts, scheduled,
 * `noindex` and private pages are never found by guests, a group's page only by its members,
 * one document per site language with the language's path, subpages follow their parent's slug.
 *
 * @internal
 */
final class PagesSearchTest extends PagesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        db_connect()->table('search_terms')->truncate();
        db_connect()->table('search_documents')->truncate();
        service('routes')->loadRoutes();
        $registry = new DeclarationRegistry();
        $registry->store($registry->build(['Pages' => new Module()]));
        (new CoreCache())->invalidate('modules');
    }

    public function testThePagesOfTheCrudAreIndexedAndFollowEveryChange(): void
    {
        $this->editor();

        $this->send('POST', 'backend/pages/add', $this->form(['title' => 'Menjador escolar', 'body' => '<p>El menú de la setmana</p>']))->assertRedirect();
        $id = (int) db_connect()->table('page_translations')->where('title', 'Menjador escolar')->get()->getRowArray()['page_id'];
        $this->assertSame([], $this->find('menjador'), 'A new page is a draft: not found.');

        $this->send('POST', 'backend/pages/publish/' . $this->code($id))->assertRedirect();
        $this->assertSame(['page-' . $id], $this->find('menjador'));
        $this->assertSame(['page-' . $id], $this->find('menu'), 'The body is indexed.');

        $this->send('POST', 'backend/pages/edit/' . $this->code($id), $this->form(['title' => 'Cantina', 'slug' => 'menjador-escolar']))->assertRedirect();
        $this->assertSame([], $this->find('menjador'));
        $this->assertSame(['page-' . $id], $this->find('cantina'));

        $this->send('POST', 'backend/pages/unpublish/' . $this->code($id))->assertRedirect();
        $this->assertSame([], $this->find('cantina'), 'Unpublished: gone.');
    }

    public function testTrashAndRestoreAndSections(): void
    {
        $id = $this->page('Secretaria', ['layout' => 'sections']);
        (new Revisions())->record($id, null, 'create');
        $this->assertSame(['page-' . $id], $this->find('secretaria'));

        $this->createUser('admin@example.com', 'admin');
        $this->login('admin@example.com');

        $this->send('POST', 'backend/pages/' . $id . '/sections/save', ['type' => 'pages.cta', 'f' => ['title' => ['ca' => 'Horari d\'atenció'], 'text' => ['ca' => 'Matins de nou a dues'], 'link' => ['type' => 'url', 'value' => ['url' => '/contacte'], 'label' => ['ca' => 'Contacte']]], 'o' => ['background' => 'primary']])->assertRedirect();
        $this->assertSame(['page-' . $id], $this->find('matins'), 'The texts of the sections are indexed.');
        $this->assertSame([], $this->find('primary'), 'Options and links are not.');

        $this->send('POST', 'backend/pages/delete/' . $this->code($id))->assertRedirect();
        $this->assertSame([], $this->find('secretaria'), 'In the trash: gone.');

        $this->send('POST', 'backend/pages/restore/' . $this->code($id))->assertRedirect();
        $this->assertSame(['page-' . $id], $this->find('secretaria'), 'Restored: back.');
    }

    public function testOnlyWhatTheVisitorMayReadIsFound(): void
    {
        $this->page('Reglament públic');
        $draft     = $this->page('Reglament esborrany', ['status' => 'draft']);
        $scheduled = $this->page('Reglament futur', ['status' => 'scheduled', 'publish_at' => Time::now()->addDays(2)->toDateTimeString()]);
        $hidden    = $this->page('Reglament amagat', ['noindex' => 1]);
        $private   = $this->page('Reglament intern', ['visibility' => 'private']);
        $group     = $this->page('Reglament del claustre', ['visibility' => 'groups'], [], ['editor']);
        $this->assertSame(['pages.pages' => 12], service('search')->reindex('Pages'), 'Four published, indexable pages in three languages.');
        PagesSearch::syncMany([$draft, $scheduled, $hidden, $private, $group]);

        $this->assertCount(1, $this->find('reglament'), 'Guests: only the public page.');
        $this->assertCount(2, $this->find('reglament', $this->createUser('ana@example.com', 'user')), 'Signed-in users: also private pages.');
        $this->assertCount(3, $this->find('reglament', $this->createUser('eva@example.com', 'editor')), 'Members: also their group\'s page.');
        $this->assertSame(0, db_connect()->table('search_documents')->whereIn('item_key', ['page-' . $draft, 'page-' . $hidden])->countAllResults(), 'Drafts and noindex pages are not indexed.');
    }

    public function testOneDocumentPerLanguageWithItsPathAndSubpagesFollowTheirParent(): void
    {
        $parent = $this->page('Escola', [], ['es' => ['title' => 'Escuela', 'slug' => 'escuela']]);
        $child  = $this->page('Equip docent', ['parent_id' => $parent], ['es' => ['title' => 'Equipo docente', 'slug' => 'equipo-docente']]);
        PagesSearch::sync($parent);

        $rows = db_connect()->table('search_documents')->where('item_key', 'page-' . $child)->orderBy('locale')->get()->getResultArray();
        $urls = array_column($rows, 'url', 'locale');
        $this->assertSame('escola/equip-docent', $urls['ca']);
        $this->assertSame('escuela/equipo-docente', $urls['es']);
        $this->assertSame('escola/equip-docent', $urls['en'], 'A language without a translation uses the fallback.');

        db_connect()->table('page_translations')->where('page_id', $parent)->where('locale', 'ca')->update(['slug' => 'centre']);
        PagesSearch::sync($parent);
        $this->assertSame('centre/equip-docent', db_connect()->table('search_documents')->where('item_key', 'page-' . $child)->where('locale', 'ca')->get()->getRowArray()['url']);

        $this->assertSame(['page-' . $child], $this->find('equipo', null, 'es'));
    }

    /**
     * @return list<string>
     */
    private function find(string $query, ?\CodeIgniter\Shield\Entities\User $user = null, string $locale = 'ca'): array
    {
        /** @var SearchIndex $index */
        $index = service('search');

        return array_map(static fn ($hit): string => $hit->key, $index->search($query, $locale, $user, 1, 50)->hits);
    }
}
