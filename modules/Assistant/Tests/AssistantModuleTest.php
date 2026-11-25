<?php

declare(strict_types=1);

namespace Modules\Assistant\Tests;

use CodeIgniter\Database\Migration;
use CodeIgniter\I18n\Time;
use Config\Database;
use Kpcrudy\Crud;
use Modules\Assistant\Config\Module;
use Modules\Assistant\Sections\FaqListSection;
use Modules\Assistant\Settings\FaqPageOptions;
use Modules\Core\Cache\CoreCache;
use Modules\Core\Modules\ManifestValidator;
use Modules\Core\Sections\RenderContext;
use Modules\Core\Sections\SectionData;

/**
 * The Assistant module (D-51-01, D-51-02, D-51-03): manifest, permissions of the FAQ CRUD and the
 * unanswered questions (forged POSTs included), the CRUD's writes (sanitised answer, checked link,
 * the search index kept current), the bubble and the FAQ page, migrations up/down/up.
 *
 * @internal
 */
final class AssistantModuleTest extends AssistantTestCase
{
    public function testManifestIsValidAndTheModuleIsDisabledByDefault(): void
    {
        $manifest = new Module();
        $this->assertSame([], (new ManifestValidator())->validate($manifest, dirname(__DIR__)));
        $this->assertFalse($manifest->enabledByDefault);
        $this->assertSame([], $manifest->outboundHosts, 'No outbound requests.');
        $this->assertFalse((bool) $manifest->settings['enabled']['default']);
        $this->assertFalse((bool) $manifest->settings['logUnanswered']['default'], 'D-51-02: nothing stored by default.');
    }

    public function testTheFaqScreensNeedTheirPermissions(): void
    {
        $this->send('GET', backend_path('assistant/faqs'))->assertRedirectTo(site_url('login'));

        $this->loginWith();
        $this->send('GET', backend_path('assistant/faqs'))->assertStatus(403);
        $this->send('GET', backend_path('assistant/unanswered'))->assertStatus(403);

        $before = db_connect()->table('assistant_faqs')->countAllResults();
        $this->send('POST', backend_path('assistant/faqs/add'), $this->form('Falsificada'))->assertStatus(403);
        $this->assertSame($before, db_connect()->table('assistant_faqs')->countAllResults(), 'A forged add changes nothing.');

        db_connect()->table('assistant_unanswered')->insert(['locale' => 'ca', 'question' => 'q', 'hash' => sha1('q'), 'asked_on' => Time::today()->toDateString(), 'times' => 1]);
        $row = (int) db_connect()->insertID();
        $this->send('POST', backend_path('assistant/unanswered/delete/' . $row))->assertStatus(403);
        $this->send('POST', backend_path('assistant/unanswered/faq/' . $row))->assertStatus(403);
        $this->assertSame(1, db_connect()->table('assistant_unanswered')->countAllResults(), 'Forged POSTs change nothing.');
    }

    public function testAnEditorAddsAnFaqWithASanitisedAnswerAndACheckedLink(): void
    {
        $this->createUser('ed@example.com', 'editor');
        $this->login('ed@example.com');

        $this->send('GET', backend_path('assistant/faqs'))->assertOK();

        $refused = $this->send('POST', backend_path('assistant/faqs/add'), $this->form('Enllaç dolent', ['link' => 'javascript:alert(1)']));
        $this->assertSame(0, db_connect()->table('assistant_faqs')->countAllResults(), 'An unsafe link is refused.');
        $this->assertStringContainsString(lang('Assistant.errors.link'), html_entity_decode((string) $refused->response()->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $this->send('POST', backend_path('assistant/faqs/add'), $this->form('Com es paga la quota?', ['link' => '/quota'], '<p>Per <script>x()</script>transferència.</p>'))->assertRedirect();
        $faq  = db_connect()->table('assistant_faqs')->get()->getRowArray();
        $text = db_connect()->table('assistant_faq_translations')->where('faq_id', $faq['id'])->get()->getRowArray();
        $this->assertSame('/quota', $faq['link']);
        $this->assertStringNotContainsString('<script', (string) $text['answer']);
        $this->assertSame(1, db_connect()->table('search_documents')->where('source', 'assistant.faqs')->where('item_key', 'faq-' . $faq['id'])->where('locale', 'ca')->countAllResults(), 'Indexed for the site search.');

        // An editor cannot delete (admin only).
        unset($_SESSION['success'], $_SESSION['message']);
        $this->assertCrudRefused($this->send('POST', backend_path('assistant/faqs/delete/' . Crud::maskId((int) $faq['id'], 'assistant_faqs'))), backend_path('assistant/faqs'), 'delete');
    }

    public function testDeletingAnFaqRemovesItsTextsAndItsDocuments(): void
    {
        $id = $this->faq(['ca' => ['Pregunta per esborrar', 'Resposta.']]);
        $this->createUser('admin@example.com', 'admin');
        $this->login('admin@example.com');

        $this->send('POST', backend_path('assistant/faqs/delete/' . Crud::maskId($id, 'assistant_faqs')))->assertRedirect();

        $this->assertSame(0, db_connect()->table('assistant_faq_translations')->where('faq_id', $id)->countAllResults());
        $this->assertSame(0, db_connect()->table('search_documents')->where('item_key', 'faq-' . $id)->countAllResults());
    }

    public function testUnansweredQuestionsBecomeFaqsOrAreDeleted(): void
    {
        $db = db_connect();
        $db->table('assistant_unanswered')->insertBatch([
            ['locale' => 'ca', 'question' => 'Teniu menjador?', 'hash' => sha1('1'), 'asked_on' => Time::today()->toDateString(), 'times' => 3],
            ['locale' => 'ca', 'question' => 'Spam', 'hash' => sha1('2'), 'asked_on' => Time::today()->toDateString(), 'times' => 1],
        ]);
        [$first, $second] = array_map('intval', array_column($db->table('assistant_unanswered')->orderBy('id')->get()->getResultArray(), 'id'));

        $this->createUser('ed@example.com', 'editor');
        $this->login('ed@example.com');

        $page = html_entity_decode((string) $this->send('GET', backend_path('assistant/unanswered'))->response()->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString('Teniu menjador?', $page);

        $response = $this->send('POST', backend_path('assistant/unanswered/faq/' . $first));
        $faq      = $db->table('assistant_faqs')->orderBy('id', 'desc')->get(1)->getRowArray();
        $this->assertIsArray($faq);
        $response->assertRedirectTo(backend_url('assistant/faqs/edit/' . Crud::maskId((int) $faq['id'], 'assistant_faqs')));
        $this->assertSame(0, (int) $faq['active'], 'Created inactive: it needs an answer first.');
        $this->assertSame('Teniu menjador?', $db->table('assistant_faq_translations')->where('faq_id', $faq['id'])->get()->getRowArray()['question']);

        $this->send('POST', backend_path('assistant/unanswered/delete/' . $second))->assertRedirect();
        $this->assertSame(0, $db->table('assistant_unanswered')->countAllResults());
        $this->assertSame(['unanswered_to_faq', 'unanswered_deleted'], array_column($db->table('activity_log')->where('module', 'Assistant')->orderBy('id')->get()->getResultArray(), 'action'));
    }

    public function testTheBubbleShowsOnlyWhenEnabledWithTheVisitorsSuggestions(): void
    {
        $this->faq(['ca' => ['Quin és l\'horari?', 'De 9 a 14 h.']], position: 1);
        $this->faq(['ca' => ['On són els documents interns?', 'Al back-office.']], 'private', position: 2);

        $html = $this->html('/');
        $this->assertStringContainsString('data-component="assistant/bubble"', $html);
        $this->assertStringContainsString('Quin és l\'horari?', $html);
        $this->assertStringNotContainsString('documents interns', $html, 'Suggestions follow the visitor\'s visibility.');
        $this->assertStringContainsString(lang('Assistant.bubble.privacy', [], 'ca'), $html);
        $this->assertStringContainsString('"askUrl":"' . site_url('assistant/ask') . '"', $html);

        setting('Assistant.logUnanswered', true);
        $this->assertStringContainsString(lang('Assistant.bubble.privacyLogging', [], 'ca'), $this->html('/'), 'Visitors are told when questions are kept.');

        setting('Assistant.enabled', false);
        $this->assertStringNotContainsString('data-component="assistant/bubble"', $this->html('/'));

        setting('Assistant.enabled', true);
        db_connect()->table('modules')->where('name', 'Assistant')->update(['enabled' => 0]);
        (new CoreCache())->invalidate('modules');
        $this->assertStringNotContainsString('data-component="assistant/bubble"', $this->html('/'), 'Never while the module is disabled.');
    }

    public function testTheFaqPageListsWhatTheVisitorMayRead(): void
    {
        $public  = $this->faq(['ca' => ['Quin és l\'horari?', '<p>De 9 a 14 h.</p>']], link: '/contact');
        $private = $this->faq(['ca' => ['On són els documents interns?', 'Al back-office.']], 'private');

        $html = $this->html('faq');
        $this->assertStringContainsString('id="faq-' . $public . '"', $html);
        $this->assertStringContainsString('<p>De 9 a 14 h.</p>', $html);
        $this->assertStringNotContainsString('faq-' . $private . '"', $html);

        $hit = service('search')->search('horari', 'ca', null)->hits[0] ?? null;
        $this->assertNotNull($hit);
        $this->assertSame(service('localeurls')->url('faq#faq-' . $public, 'ca'), $hit->link(), 'The site search links to the FAQ on this page.');
    }

    public function testTheSectionListsTheFaqsTheReaderMayRead(): void
    {
        $public  = $this->faq(['ca' => ['Quin és l\'horari?', '<p>De 9 a 14 h.</p>']]);
        $private = $this->faq(['ca' => ['On són els documents interns?', 'Al back-office.']], 'private');
        service('theme')->setArea('public');
        $section = new SectionData('assistant.faqs', [], ['ca' => ['heading' => 'Dubtes', 'intro' => '']]);
        $type    = new FaqListSection();

        $guest = $type->render($section, new RenderContext('ca', 'ca'));
        $this->assertStringContainsString('id="faq-' . $public . '"', $guest);
        $this->assertStringContainsString('Dubtes', $guest);
        $this->assertStringNotContainsString('faq-' . $private . '"', $guest, 'Guests: public FAQs only.');

        $member = $type->render($section, new RenderContext('ca', 'ca', $this->createUser('ana@example.com', 'user')));
        $this->assertStringContainsString('id="faq-' . $private . '"', $member, 'Signed-in readers also see theirs.');
        $this->assertContains(FaqListSection::class, (new Module())->sectionTypes);
    }

    public function testFaqRedirectsToTheChosenPageInEachLanguage(): void
    {
        $db = db_connect();
        $db->table('pages')->insert(['parent_id' => null, 'position' => 0, 'template' => 'default', 'layout' => 'sections', 'status' => 'published', 'publish_at' => Time::now()->subHours(1)->toDateTimeString(), 'visibility' => 'public', 'noindex' => 0, 'created_at' => Time::now()->toDateTimeString(), 'updated_at' => Time::now()->toDateTimeString()]);
        $page = (int) $db->insertID();
        $db->table('page_translations')->insertBatch([
            ['page_id' => $page, 'locale' => 'ca', 'title' => 'Preguntes freqüents', 'slug' => 'preguntes-frequents', 'summary' => '', 'body' => '', 'meta_title' => '', 'meta_description' => '', 'canonical' => ''],
            ['page_id' => $page, 'locale' => 'es', 'title' => 'Dudas', 'slug' => 'dudas', 'summary' => '', 'body' => '', 'meta_title' => '', 'meta_description' => '', 'canonical' => ''],
        ]);

        $this->assertArrayHasKey((string) $page, (new FaqPageOptions())->options(), 'The pages can be chosen.');
        $this->send('GET', 'faq')->assertOK();

        setting('Assistant.faqPage', (string) $page);
        $this->send('GET', 'faq')->assertRedirectTo(service('localeurls')->url('preguntes-frequents', 'ca'));
        $this->assertSame(301, $this->send('GET', 'faq')->response()->getStatusCode());
        $this->send('GET', 'es/faq')->assertRedirectTo(service('localeurls')->url('dudas', 'es'));

        $db->table('pages')->where('id', $page)->update(['status' => 'draft']);
        $this->send('GET', 'faq')->assertOK();
        service('settings')->forget('Assistant.faqPage');
    }

    public function testMigrationsGoDownAndUpAgain(): void
    {
        $files      = glob(dirname(__DIR__) . '/Database/Migrations/*.php') ?: [];
        $migrations = [];

        foreach ($files as $file) {
            $class = 'Modules\Assistant\Database\Migrations\\' . preg_replace('/^[\d_-]+/', '', basename($file, '.php'));

            if (! class_exists($class, false)) {
                require_once $file;
            }

            $migrations[] = new $class(Database::forge('tests'));
        }

        $this->assertCount(4, $migrations);

        foreach (array_reverse($migrations) as $migration) {
            \assert($migration instanceof Migration);
            $migration->down();
        }

        $this->db->resetDataCache();
        $this->assertFalse($this->db->tableExists('assistant_faqs'));

        foreach ($migrations as $migration) {
            $migration->up();
        }

        $this->db->resetDataCache();

        foreach (['assistant_faqs', 'assistant_faq_translations', 'assistant_faq_groups', 'assistant_unanswered'] as $table) {
            $this->assertTrue($this->db->tableExists($table), $table);
        }
    }

    public function testTheDemoSeederLoadsFaqsThatAnswer(): void
    {
        $manager = new \Modules\Core\Demo\DemoDataManager();
        config('Packages')->allowDemoInProduction = true;

        try {
            $manager->load('Assistant');
        } catch (\Modules\Core\Demo\DemoDataException $e) {
            $this->markTestSkipped($e->getMessage());
        }

        $this->assertSame(5, db_connect()->table('assistant_faqs')->countAllResults());
        $this->assertSame('faq', $this->answer('A quina hora obriu?')['type']);
        $manager->remove('Assistant');
        $this->assertSame(0, db_connect()->table('assistant_faqs')->countAllResults());
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function form(string $question, array $values = [], string $answer = ''): array
    {
        return $values + [
            'kp_tr'      => ['ca' => ['question' => $question, 'answer' => $answer, 'keywords' => '', 'link_label' => '']],
            'link'       => '',
            'visibility' => 'public',
            'position'   => '0',
            'active'     => '1',
        ];
    }

    private function html(string $path): string
    {
        return html_entity_decode((string) $this->send('GET', $path)->response()->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
