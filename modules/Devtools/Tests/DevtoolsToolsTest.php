<?php

declare(strict_types=1);

namespace Modules\Devtools\Tests;

use Modules\Core\Modules\ModuleDiscovery;
use Modules\Core\Notifications\NotificationType;
use Modules\Core\Tests\ModuleFixturesTrait;
use Modules\Devtools\Controllers\Checks;
use Modules\Devtools\Generator\SparkRunner;
use Modules\Devtools\Inspector\Markdown;
use Modules\Devtools\Inspector\ModuleInspector;
use Modules\Devtools\Inspector\RouteInventory;
use Modules\Devtools\Mail\MailPreviews;
use Modules\Devtools\System\PhpInfo;

/**
 * The tools of session 53 (docs/devtools.md): module inspector, PHP info, routes explorer, mail
 * previews, component gallery and checks — behind the Devtools locks, read only, nothing sent.
 *
 * @internal
 */
final class DevtoolsToolsTest extends DevtoolsTestCase
{
    use ModuleFixturesTrait;

    private const SCREENS = ['devtools/modules', 'devtools/modules/News', 'devtools/phpinfo', 'devtools/routes', 'devtools/mail', 'devtools/components', 'devtools/checks'];

    protected function tearDown(): void
    {
        Checks::$testRunner = null;
        $this->removeTempRoot();
        parent::tearDown();
    }

    public function testTheScreensAnswerOnlyInDevelopmentFromLocalhostToSuperadmins(): void
    {
        // Testing environment: no route at all.
        $this->loginSuperadmin();
        $this->fromLocalhost();

        foreach (self::SCREENS as $path) {
            $this->assertNotFound('GET', backend_path($path));
        }

        $this->assertNotFound('POST', backend_path('devtools/checks'), ['check' => 'csp']);

        $this->withDevtoolsRoutes();
        $this->fromLocalhost();

        foreach (self::SCREENS as $path) {
            $page = $this->send('GET', backend_path($path));
            $page->assertOK();
            $page->assertSee(lang('Devtools.banner'));
            $page->assertSee('data-devtools-nav');
        }

        $this->fromNetwork();

        foreach (self::SCREENS as $path) {
            $this->assertNotFound('GET', backend_path($path));
        }
    }

    public function testAdministratorsAreRefused(): void
    {
        $this->withDevtoolsRoutes();
        $this->createUser('admin@example.com', 'admin');
        $this->login('admin@example.com');
        $this->fromLocalhost();

        foreach (self::SCREENS as $path) {
            $this->send('GET', backend_path($path))->assertStatus(403);
        }

        $runner             = new class () extends SparkRunner {
            public bool $ran = false;

            public function run(array $arguments, string $input = ''): array
            {
                $this->ran = true;

                return ['status' => 0, 'output' => ''];
            }
        };
        Checks::$testRunner = $runner;
        $this->send('POST', backend_path('devtools/checks'), ['check' => 'csp'])->assertStatus(403);
        $this->assertFalse($runner->ran, 'A forged POST runs nothing.');
    }

    public function testTheChecksRunThroughSparkRunnerAndShowTheOutput(): void
    {
        $this->withDevtoolsRoutes();
        $this->loginSuperadmin();
        $this->fromLocalhost();
        $runner             = new class () extends SparkRunner {
            /** @var list<list<string>> */
            public array $calls = [];

            public function run(array $arguments, string $input = ''): array
            {
                $this->calls[] = $arguments;

                return ['status' => 3, 'output' => '<b>fake</b> output'];
            }
        };
        Checks::$testRunner = $runner;

        $this->send('POST', backend_path('devtools/checks'), ['check' => 'assets'])->assertRedirectTo(site_url(backend_path('devtools/checks')));
        $this->assertSame([['assets:publish', '--check']], $runner->calls);
        $page = (string) $this->send('GET', backend_path('devtools/checks'))->getBody();
        $this->assertStringContainsString('data-check-status="3"', $page);
        $this->assertStringContainsString('&lt;b&gt;fake&lt;/b&gt; output', $page);

        $this->send('POST', backend_path('devtools/checks'), ['check' => 'language']);
        $this->assertCount(1, $runner->calls, 'The language check runs in this process.');
        $this->assertStringContainsString('data-check-status="0"', (string) $this->send('GET', backend_path('devtools/checks'))->getBody());

        $this->send('POST', backend_path('devtools/checks'), ['check' => 'rm -rf /']);
        $this->assertCount(1, $runner->calls, 'Only the listed checks run.');
        $this->assertSame(2, db_connect()->table('activity_log')->where('action', 'check_run')->countAllResults());
    }

    public function testTheInspectorShowsAFixtureModule(): void
    {
        $name = $this->fixtureModule(<<<'PHP'
            public array $resources = ['books' => ['label' => 'Books', 'type' => 'crud']];
            public array $defaultGrants = ['editor' => ['{{name}}-books.list']];
            public array $settings = [
                'apiKey'  => ['type' => 'string', 'default' => 'default-secret-value', 'label' => 'Key'],
                'perPage' => ['type' => 'int', 'default' => 25, 'label' => 'Per page'],
            ];
            PHP);
        $manifest = $this->tempRoot() . '/' . $name . '/Config/Module.php';
        file_put_contents($manifest, str_replace('{{name}}', strtolower($name), (string) file_get_contents($manifest)));
        require_once $manifest;
        setting($name . '.apiKey', 'stored-secret-value');

        $routes = service('routes')->loadRoutes();
        $routes->get(backend_path(strtolower($name) . '/open'), '\Modules\\' . $name . '\Controllers\Books::index', ['as' => 'fixture-open']);
        $routes->get(backend_path(strtolower($name) . '/books'), '\Modules\\' . $name . '\Controllers\Books::index', ['filter' => 'can:' . strtolower($name) . '-books.list']);

        $report = (new ModuleInspector(new ModuleDiscovery([ROOTPATH . 'modules', $this->tempRoot()]), $routes))->inspect($name);
        $this->assertIsArray($report);

        $permissions = array_column($report['permissions'], 'groups', 'name');
        $this->assertArrayHasKey(strtolower($name) . '-books.list', $permissions);
        $this->assertSame(['editor'], $permissions[strtolower($name) . '-books.list']);
        $this->assertArrayHasKey(strtolower($name) . '-books.delete', $permissions);

        $paths = array_column($report['routes'], 'problem', 'path');
        $this->assertSame('no can: filter with a permission', $paths['/' . backend_path(strtolower($name) . '/open')]);
        $this->assertSame('', $paths['/' . backend_path(strtolower($name) . '/books')]);

        $settings = array_column($report['settings'], null, 'key');
        $this->assertTrue($settings[$name . '.apiKey']['secret']);
        $this->assertSame(lang('Install.about.set'), $settings[$name . '.apiKey']['value']);
        $this->assertSame('25', $settings[$name . '.perPage']['default']);
        $this->assertFalse($settings[$name . '.perPage']['secret']);
        $this->assertStringNotContainsString('secret-value', (string) json_encode($report));
        $this->assertSame(['met' => true], array_intersect_key($report['requires'][0], ['met' => true]));
    }

    public function testTheInspectorPageOfNews(): void
    {
        $this->withDevtoolsRoutes();
        $this->loginSuperadmin();
        $this->fromLocalhost();

        $page = (string) $this->send('GET', backend_path('devtools/modules/News'))->getBody();
        $this->assertStringContainsString('news-posts.edit', $page);
        $this->assertStringContainsString('data-inspector="routes"', $page);
        $this->assertStringContainsString('data-inspector="claude"', $page, 'Its CLAUDE.md is rendered.');
        $this->assertStringContainsString('data-inspector="events"', $page);
        $this->assertStringNotContainsString('<script', (string) preg_replace('#<script[^>]*(type="importmap"|nonce=)[^>]*>.*?</script>#s', '', $page) === '' ? '' : '', 'No script from the Markdown.');

        $this->assertNotFound('GET', backend_path('devtools/modules/Nope'));
        $this->assertNotFound('GET', backend_path('devtools/modules/news..'));
    }

    public function testTheRoutesExplorerFlagsARouteWithoutCan(): void
    {
        $this->withDevtoolsRoutes();
        service('routes')->get(backend_path('fixture-unguarded'), '\Modules\Devtools\Controllers\Home::index');
        service('routes')->post('fixture-public-form', '\Modules\Devtools\Controllers\Home::index');
        $this->loginSuperadmin();
        $this->fromLocalhost();

        $page = (string) $this->send('GET', backend_path('devtools/routes'), ['problems' => '1'])->getBody();
        $this->assertStringContainsString('/' . backend_path('fixture-unguarded'), $page);
        $this->assertStringContainsString('data-route-problem="no can: filter with a permission"', $page);
        $this->assertStringNotContainsString('/' . backend_path('devtools/routes') . '<', $page, 'Guarded routes are not listed with problems=1.');

        $inventory = array_column((new RouteInventory(service('routes')))->all(), null, 'path');
        $this->assertSame('', $inventory['/fixture-public-form']['problem'], 'The global csrf filter covers it.');
        $this->assertSame('Devtools', $inventory['/' . backend_path('devtools/routes')]['module']);
        $this->assertSame('devtools-routes', $inventory['/' . backend_path('devtools/routes')]['name']);
        $filters = config(\Config\Filters::class);
        $before  = $filters->globals;
        $filters->globals['before']['csrf']['except'][] = 'fixture-public-*';

        try {
            $this->assertFalse((new RouteInventory())->csrfCovers('fixture-public-form'), 'A path excluded from csrf is flagged.');
            $this->assertTrue((new RouteInventory())->csrfCovers('api/v1/x'), 'The token-only API is not.');
        } finally {
            $filters->globals = $before;
        }
    }

    public function testMailPreviewsRenderEveryTypeInTheThreeLanguagesWithoutSending(): void
    {
        $previews = new MailPreviews();
        $items    = $previews->items();
        $types    = array_filter($items, static fn (array $i): bool => $i['kind'] === 'notification');
        $this->assertGreaterThanOrEqual(10, count($types), 'Every bundled notification type.');

        foreach ($types as $type) {
            $this->assertTrue(is_subclass_of($type['source'], NotificationType::class), $type['source']);
        }

        $this->assertNotSame([], array_filter($items, static fn (array $i): bool => $i['kind'] === 'security'), 'The security e-mails of Auth are listed.');

        $mailBefore = db_connect()->table('mail_queue')->countAllResults();
        $pushBefore = db_connect()->table('push_queue')->countAllResults();
        $notesBefore = db_connect()->table('notifications')->countAllResults();

        foreach (array_keys($items) as $id) {
            foreach (['ca', 'es', 'en'] as $locale) {
                $preview = $previews->render($id, $locale);
                $this->assertIsArray($preview, $id);
                $this->assertNotSame('', $preview['subject'], "{$id} {$locale}");
                $this->assertStringContainsString('<html', $preview['html'], $id);
                $this->assertNotSame('', $preview['text'], $id);
                $this->assertDoesNotMatchRegularExpression('/^[A-Z][A-Za-z]+\.[a-zA-Z0-9_.]+$/', $preview['subject'], "{$id} {$locale}: a language key");

                if ($items[$id]['kind'] === 'notification') {
                    $this->assertIsArray($preview['push']);
                    $this->assertIsArray($preview['database']);
                }
            }
        }

        $this->assertSame([], $this->sentEmails, 'Nothing is sent.');
        $this->assertSame($mailBefore, db_connect()->table('mail_queue')->countAllResults(), 'Nothing is queued.');
        $this->assertSame($pushBefore, db_connect()->table('push_queue')->countAllResults());
        $this->assertSame($notesBefore, db_connect()->table('notifications')->countAllResults(), 'Nothing is stored.');
        $this->assertNull($previews->render('nope', 'en'));
        $this->assertNull($previews->render((string) array_key_first($items), 'xx'));
    }

    public function testEveryBundledTypeHasPreviewData(): void
    {
        foreach (array_filter(glob(ROOTPATH . 'modules/*/Notifications/*.php') ?: [], static fn (string $f): bool => ! str_contains($f, '/Core/')) as $file) {
            $this->assertStringContainsString('function previewData', (string) file_get_contents($file), basename($file));
        }

        foreach (glob(ROOTPATH . 'modules/Core/Notifications/Types/*.php') ?: [] as $file) {
            $this->assertStringContainsString('function previewData', (string) file_get_contents($file), basename($file));
        }

        // The default reads the keys the type uses.
        $type = new class () extends NotificationType {
            public static function title(): string
            {
                return 'x';
            }

            public function render(): \Modules\Core\Notifications\NotificationMessage
            {
                return new \Modules\Core\Notifications\NotificationMessage((string) ($this->data['title'] ?? '') . (string) ($this->data['id'] ?? '') . (string) ($this->data['due_on'] ?? ''));
            }
        };
        $data = $type::previewData();
        $this->assertSame(1, $data['id']);
        $this->assertSame(gmdate('Y-m-d'), $data['due_on']);
        $this->assertIsString($data['title']);
    }

    public function testThePreviewPageUsesASandboxedFrame(): void
    {
        $this->withDevtoolsRoutes();
        $this->loginSuperadmin();
        $this->fromLocalhost();

        $page = (string) $this->send('GET', backend_path('devtools/mail'), ['item' => 'modules-news-notifications-newnotice', 'locale' => 'es'])->getBody();
        $this->assertStringContainsString('sandbox=""', $page);
        $this->assertStringContainsString('srcdoc=', $page);
        $this->assertStringNotContainsString('<iframe class="border rounded bg-white" sandbox="" referrerpolicy="no-referrer" title="E-mail (HTML)" srcdoc=\'<', $page, 'The e-mail HTML is escaped in the attribute.');
        $this->assertStringContainsString('data-mail-locale="es"', $page);
    }

    public function testPhpInfoIsRebuiltWithoutInlineCode(): void
    {
        $html = '<!DOCTYPE html><html><head><style>body{color:red}</style></head><body><div class="center"><table><tr class="h"><td><a href="https://php.net"><img src="data:image/png;base64,xx" alt="PHP"></a><h1 class="p">PHP Version 8.3</h1></td></tr></table>'
            . '<h2>date</h2><table><tr><td class="e" style="color:red">date.timezone</td><td class="v">UTC <script>alert(1)</script></td></tr></table></div></body></html>';
        $sections = PhpInfo::sections($html);
        $this->assertSame('date', $sections[1]['title']);
        $this->assertSame(['date.timezone', 'UTC alert(1)'], $sections[1]['rows'][0]['cells']);

        $this->withDevtoolsRoutes();
        $this->loginSuperadmin();
        $this->fromLocalhost();
        $page = (string) $this->send('GET', backend_path('devtools/phpinfo'))->getBody();
        $this->assertStringContainsString('data-phpinfo-row', $page);
        $this->assertStringContainsString('PHP Version', $page);
        $this->assertDoesNotMatchRegularExpression('/<[^>]+\s(style|on[a-z]+)=/i', $page, 'No inline style or handler (escaped text of PHP\'s own values aside).');
        $this->assertStringContainsString('no-store', $this->send('GET', backend_path('devtools/phpinfo'))->response()->getHeaderLine('Cache-Control'));
    }

    public function testTheGalleryRendersAndPreviewsAnotherThemeWithoutChangingTheActiveOne(): void
    {
        $this->withDevtoolsRoutes();
        $this->loginSuperadmin();
        $this->fromLocalhost();

        $page = (string) $this->send('GET', backend_path('devtools/components'))->getBody();

        foreach (['page_header', 'card', 'form_layout', 'ui_badge', 'empty_state', 'alerts'] as $id) {
            $this->assertStringContainsString('data-gallery-item="' . $id . '"', $page);
        }

        $this->assertStringContainsString('data-bs-theme="dark"', $page);
        $this->assertStringContainsString('data-icon-picker="name"', $page);
        $this->assertStringContainsString('data-component="core/forms/editor"', $page);

        $active = setting('Theme.backend');
        $this->send('GET', backend_path('devtools/components'), ['theme' => 'tutorial-demo'])->assertOK();
        $this->assertSame($active, setting('Theme.backend'), 'The active theme does not change.');
        $this->assertNotFound('GET', backend_path('devtools/components') . '?theme=../../etc');
    }

    public function testTheMarkdownRendererEscapesEverything(): void
    {
        $html = Markdown::toHtml("# Title\n\nSome **bold** and `code <b>` text <script>alert(1)</script>.\n\n- one\n- [link](https://example.org)\n- [js](javascript:alert(1))\n\n```php\n<?php echo 1;\n```");
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<code>code &lt;b&gt;</code>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('<a href="https://example.org" rel="noopener noreferrer">link</a>', $html);
        $this->assertStringContainsString('&lt;?php echo 1;', $html);
    }
}
