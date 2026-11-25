<?php

declare(strict_types=1);

namespace Modules\News\Tests;

use CodeIgniter\I18n\Time;
use CodeIgniter\Shield\Entities\User;
use Modules\Core\Search\SearchIndex;
use Modules\News\Search\NewsSearch;
use Modules\News\Services\Publisher;

/**
 * News in the site search (D-50-01, docs/news.md "Search"): the form and the operations keep the
 * index current; guests find only public posts (linked to the public article), signed-in users
 * private ones (linked to the reader), members their groups' posts; scheduled and expired posts
 * are outside their window; the category's name is a keyword.
 *
 * @internal
 */
final class NewsSearchTest extends NewsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        db_connect()->table('search_terms')->truncate();
        db_connect()->table('search_documents')->truncate();
    }

    public function testTheFormAndTheOperationsKeepTheIndexCurrent(): void
    {
        $this->createUser('boss@example.com', 'admin');
        $this->login('boss@example.com');
        $id   = $this->newsPost('Festival', ['status' => 'draft', 'visibility' => 'public']);
        $code = $this->code($id);

        $this->send('POST', 'backend/news/posts/edit/' . $code, [
            'kp_tr' => ['ca' => ['title' => 'Festival de música', 'summary' => 'Concerts al pati', 'body' => '']],
            'type' => 'article', 'priority' => 'info', 'visibility' => 'public', 'slug' => 'festival',
            'event_starts_at' => '', 'event_ends_at' => '', 'event_all_day' => '0',
        ]);
        $this->assertSame([], $this->find('festival'), 'A draft is not found.');

        $this->send('POST', 'backend/news/posts/publish/' . $code);
        $this->assertSame(['post-' . $id], $this->find('musica'));
        $this->assertSame(['post-' . $id], $this->find('concerts'));

        $this->send('POST', 'backend/news/posts/unpublish/' . $code);
        $this->assertSame([], $this->find('musica'));

        $this->send('POST', 'backend/news/posts/publish/' . $code);
        $this->send('POST', 'backend/news/posts/delete/' . $code);
        $this->assertSame([], $this->find('musica'), 'A post in the trash is not found.');
    }

    public function testVisibilityLinksAndWindow(): void
    {
        $public  = $this->newsPost('Jornada pública', ['visibility' => 'public', 'slug' => 'jornada']);
        $private = $this->newsPost('Jornada interna');
        $group   = $this->newsPost('Jornada del claustre', ['visibility' => 'groups'], ['editor']);
        $later   = $this->newsPost('Jornada futura', ['visibility' => 'public', 'status' => 'scheduled', 'published_at' => Time::now()->addDays(3)->toDateTimeString()]);
        $expired = $this->newsPost('Jornada caducada', ['visibility' => 'public', 'type' => 'notice', 'expires_at' => Time::now()->subHours(1)->toDateTimeString()]);
        NewsSearch::syncMany([$public, $private, $group, $later, $expired]);

        $this->assertSame(['post-' . $public], $this->find('jornada'));
        $this->assertEqualsCanonicalizing(['post-' . $public, 'post-' . $private], $this->find('jornada', $this->createUser('ana@example.com', 'user')));
        $this->assertEqualsCanonicalizing(['post-' . $public, 'post-' . $private, 'post-' . $group], $this->find('jornada', $this->createUser('eva@example.com', 'editor')));

        $urls = array_column(db_connect()->table('search_documents')->where('locale', 'ca')->get()->getResultArray(), 'url', 'item_key');
        $this->assertSame('news/jornada', $urls['post-' . $public], 'Public posts link to the public article.');
        $this->assertSame(backend_link('news/read/' . $this->code($private)), $urls['post-' . $private], 'Private posts link to the reader.');

        // The scheduled post appears once its date has passed (no reindex needed).
        Time::setTestNow(Time::now()->addDays(4));
        $this->assertContains('post-' . $later, $this->find('jornada'));
        Time::setTestNow();

        (new Publisher())->expireNotices();
        $this->assertSame(0, db_connect()->table('search_documents')->where('item_key', 'post-' . $expired)->countAllResults(), 'Expired notices leave the index.');
    }

    public function testTheCategoryIsAKeywordAndReindexRebuildsTheSource(): void
    {
        db_connect()->table('news_categories')->insert(['slug' => 'esports', 'position' => 0, 'active' => 1]);
        $category = (int) db_connect()->insertID();
        db_connect()->table('news_category_translations')->insert(['category_id' => $category, 'locale' => 'ca', 'name' => 'Esports']);
        $id = $this->newsPost('Lliga escolar', ['visibility' => 'public', 'category_id' => $category]);

        $this->assertSame(['news.posts' => 3], service('search')->reindex('News'));
        $this->assertSame(['post-' . $id], $this->find('esports'));
    }

    /**
     * @return list<string>
     */
    private function find(string $query, ?User $user = null): array
    {
        /** @var SearchIndex $index */
        $index = service('search');

        return array_map(static fn ($hit): string => $hit->key, $index->search($query, 'ca', $user, 1, 50)->hits);
    }
}
