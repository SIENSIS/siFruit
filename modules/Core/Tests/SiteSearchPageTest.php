<?php

declare(strict_types=1);

namespace Modules\Core\Tests;

use CodeIgniter\I18n\Time;
use Config\App;
use Config\Services;
use Modules\Auth\Tests\AuthTestCase;
use Modules\Core\Cache\CoreCache;
use Modules\Core\Search\SearchDocument;

/**
 * The public site search (D-50-01, docs/search.md): the box in the header (theme option
 * `showSearch`), the results page per language with escaped highlighting, visibility for guests
 * and signed-in users, "no results" with the contact link, private caching, `noindex` and the
 * rate limit.
 *
 * @internal
 */
final class SiteSearchPageTest extends AuthTestCase
{
    private string $defaultLocale = 'en';

    protected function setUp(): void
    {
        parent::setUp();
        $this->defaultLocale = config(App::class)->defaultLocale;
        config(App::class)->defaultLocale = 'ca';
        setting('Core.locales', ['ca', 'es', 'en']);
        setting('Core.defaultLocale', 'ca');
        service('theme')->reset();
        db_connect()->table('search_terms')->truncate();
        db_connect()->table('search_documents')->truncate();
    }

    protected function tearDown(): void
    {
        service('settings')->forget('Core.searchRateLimit');
        service('settings')->forget('Core.locales');
        service('settings')->forget('Core.defaultLocale');
        config(App::class)->defaultLocale = $this->defaultLocale;
        db_connect()->table('modules')->update(['enabled' => 1]);
        (new CoreCache())->invalidate('modules');
        Services::resetSingle('settings');
        Services::resetSingle('language');
        Services::resetSingle('request');
        Services::resetSingle('localeurls');
        parent::tearDown();
    }

    public function testTheHeaderHasTheSearchBoxUnlessTheThemeTurnsItOff(): void
    {
        $html = $this->html('/');

        $this->assertMatchesRegularExpression('#<form class="site-search-box[^"]*" action="' . preg_quote(site_url('search'), '#') . '" method="get" role="search">#', $html);
        $this->assertStringContainsString('name="q"', $html);

        service('settings')->set('Theme.showSearch', false, 'theme:default');
        service('theme')->reset();
        $this->assertStringNotContainsString('site-search-box', $this->html('/'));
        service('settings')->forget('Theme.showSearch', 'theme:default');
    }

    public function testResultsArePerLanguageAndHighlightedAndEscaped(): void
    {
        $this->index('ca', 'Horari de la <b>biblioteca</b>', 'La biblioteca obre <script>x()</script> cada dia.', 'horari');
        $this->index('es', 'Horario de la biblioteca', 'La biblioteca abre cada día.', 'horario');

        $response = $this->send('GET', 'search?q=biblioteca');
        $response->assertOK();
        $html = html_entity_decode((string) $response->response()->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertStringContainsString('Horari de la <mark>biblioteca</mark>', $html, 'Tags of the title are stripped when indexed, the match highlighted.');
        $this->assertStringNotContainsString('<script>x()', (string) $response->response()->getBody());
        $this->assertStringNotContainsString('Horario', $html);
        $this->assertStringContainsString('href="' . service('localeurls')->url('horari', 'ca') . '"', $html);
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $html);
        $this->assertStringContainsString('private', $response->response()->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->response()->getHeaderLine('Cache-Control'));

        $html = $this->html('es/search?q=biblioteca');
        $this->assertStringContainsString('<html lang="es"', $html);
        $this->assertStringContainsString('Horario de la <mark>biblioteca</mark>', $html);
        $this->assertStringContainsString('href="' . service('localeurls')->url('horario', 'es') . '"', $html, 'Links carry the language prefix.');
    }

    public function testGuestsDoNotFindPrivateContentAndSignedInUsersDo(): void
    {
        $this->index('ca', 'Reunió de famílies', '', 'public');
        $this->index('ca', 'Reunió interna', '', 'private', 'private');
        $this->index('ca', 'Reunió programada', '', 'later', 'public', Time::now()->addDays(1));

        $html = $this->html('search?q=reunio');
        $this->assertStringContainsString('famílies', $html);
        $this->assertStringNotContainsString('interna', $html);
        $this->assertStringNotContainsString('programada', $html);

        $this->createUser('ana@example.com', 'user');
        $this->login('ana@example.com')->assertRedirect();
        $html = $this->html('search?q=reunio');
        $this->assertStringContainsString('interna', $html);
        $this->assertStringNotContainsString('programada', $html);
    }

    public function testNoResultsOffersTheContactPageWhenItsModuleIsEnabled(): void
    {
        $html = $this->html('search?q=inexistent');
        $this->assertStringContainsString(lang('Core.search.none', ['inexistent'], 'ca'), $html);
        $this->assertStringContainsString('href="' . site_url('contact') . '"', $html);

        db_connect()->table('modules')->where('name', 'Contact')->update(['enabled' => 0]);
        (new CoreCache())->invalidate('modules');
        $this->assertStringNotContainsString('href="' . site_url('contact') . '"', $this->html('search?q=inexistent'));
    }

    public function testEmptyAndForgedQueriesAndDeepPages(): void
    {
        $this->send('GET', 'search')->assertOK();
        $this->assertStringContainsString(lang('Core.search.tooShort', [], 'ca'), $this->html('search?q=i'));
        $this->send('GET', 'search?q=' . rawurlencode("' OR 1=1 -- " . str_repeat('x', 5000)))->assertOK();
        $this->send('GET', 'search?q[]=a')->assertOK();
        $this->assertNotFound('GET', 'search?q=a&page=51');
        $this->assertNotFound('GET', 'search?q=biblioteca&page=2');
    }

    public function testTheRateLimitAnswers429(): void
    {
        setting('Core.searchRateLimit', 2);

        $this->send('GET', 'search?q=a')->assertOK();
        $this->send('GET', 'search?q=b')->assertOK();
        $this->send('GET', 'search?q=c')->assertStatus(429);

        $routes = service('routes');
        $routes->loadRoutes();
        $this->assertContains('ratelimit:search', $routes->getFiltersForRoute('search', 'GET'));
    }

    private function index(string $locale, string $title, string $body, string $url, string $visibility = 'public', ?Time $from = null): void
    {
        service('search')->index(new SearchDocument('pages.pages', 'page-' . $url, $locale, $title, $url, body: $body, visibility: $visibility, publishFrom: $from));
    }

    private function html(string $path): string
    {
        return html_entity_decode((string) $this->send('GET', $path)->response()->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
