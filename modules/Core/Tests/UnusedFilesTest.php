<?php

declare(strict_types=1);

namespace Modules\Core\Tests;

use CodeIgniter\CLI\CLI;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\Mock\MockInputOutput;
use Config\Storage as StorageConfig;
use Modules\Assistant\Storage\FaqFileReferences;
use Modules\Core\Cache\CoreCache;
use Modules\Core\Modules\ModuleDiscovery;
use Modules\Core\Registry\DeclarationRegistry;
use Modules\Core\Storage\FileReferenceProvider;
use Modules\Core\Storage\FileReferenceSet;
use Modules\Core\Storage\RichTextFiles;
use Modules\Core\Storage\SettingsFileReferences;
use Modules\Core\Storage\Storage;
use Modules\Core\Storage\StoredFile;
use Modules\Core\Storage\UnusedFiles;
use Modules\Core\Storage\UnusedFilesException;
use Modules\Core\Tasks\DeleteUnusedFilesTask;
use Modules\Events\Storage\EventFileReferences;
use Modules\Files\Storage\FileManagerReferences;
use Modules\Menus\Storage\MenuFileReferences;
use Modules\News\Storage\NewsFileReferences;
use Modules\Pages\Storage\PageFileReferences;
use Modules\Tutorial\Storage\AttachmentReferences;
use RuntimeException;

/**
 * Session 55 (D-55-01): the providers of every module that stores files, the safety rules of
 * `UnusedFiles`, `php spark storage:unused` and the automatic deletion task (off by default).
 *
 * @internal
 */
final class UnusedFilesTest extends RbacTestCase
{
    use StorageFixturesTrait;

    private const NOW = '2026-11-23 10:00:00';

    /** The real providers, as the manifests declare them. */
    private const PROVIDERS = [
        SettingsFileReferences::class => 'Core',
        NewsFileReferences::class     => 'News',
        PageFileReferences::class     => 'Pages',
        EventFileReferences::class    => 'Events',
        FaqFileReferences::class      => 'Assistant',
        AttachmentReferences::class   => 'Tutorial',
        MenuFileReferences::class     => 'Menus',
        FileManagerReferences::class  => 'Files',
    ];

    private Storage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        Time::setTestNow(self::NOW);
        $this->storage = $this->makeStorage();
        RichTextFiles::reset();
        // A demo site's test-only grace period in .env must not change these tests.
        config(\Config\Storage::class)->unusedFilesTestGraceDays = null;
        UnusedFiles::forget();
        FailingProvider::$calls = 0;
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        UnusedFiles::forget();
        $this->removeStorageRoot();
        parent::tearDown();
    }

    // A. Contract ---------------------------------------------------------------------------------

    public function testEveryModuleThatStoresFilesDeclaresItsProvider(): void
    {
        $declared = [];

        foreach ((new ModuleDiscovery())->discover() as $name => $module) {
            foreach ($module->manifest->fileReferences ?? [] as $class) {
                $declared[ltrim($class, '\\')] = $name;
                $this->assertTrue(is_subclass_of($class, FileReferenceProvider::class), $class);
            }
        }

        foreach (self::PROVIDERS as $class => $module) {
            $this->assertSame($module, $declared[$class] ?? null, $class . ' is declared by ' . $module);
        }

        $this->storeDeclarations();
        $this->assertArrayHasKey(NewsFileReferences::class, (new DeclarationRegistry())->fileReferenceProviders());
    }

    public function testRichTextFilesFindsKeysNamesAndUploadPaths(): void
    {
        $key  = str_repeat('a', 32);
        $name = str_repeat('b', 32);
        $html = '<p><img src="https://example.com/uploads/modules/news/2026/11/' . $name . '.png" alt=""></p>'
            . '<a href="/files/private/' . $key . '">doc</a>'
            . '{"image":"uploads\/modules\/pages\/old%20name.png"}'
            . '<img src="/uploads/modules/pages/' . str_repeat('c', 32) . '-w800.webp">';

        $found = RichTextFiles::references($html);

        $this->assertContains($key, $found);
        $this->assertContains($name, $found);
        $this->assertContains(str_repeat('c', 32), $found, 'Variant names give their source hex.');
        $this->assertContains('/uploads/modules/pages/old name.png', $found);
        $this->assertSame([], RichTextFiles::references(null));
        $this->assertSame([], RichTextFiles::references('no files here, ' . str_repeat('A', 32)));
    }

    public function testAReferenceSetMatchesIdsKeysNamesAndPaths(): void
    {
        $set = new FileReferenceSet();
        $set->add(7);
        $set->add(str_repeat('d', 32));
        $set->add('see /uploads/modules/news/x.png');

        $this->assertTrue($set->contains(['id' => 7, 'file_key' => 'k', 'disk' => 'public', 'path' => 'p']));
        $this->assertTrue($set->contains(['id' => 1, 'file_key' => str_repeat('d', 32), 'disk' => 'private', 'path' => 'p']));
        $this->assertTrue($set->contains(['id' => 1, 'file_key' => 'k', 'disk' => 'public', 'path' => 'modules/a/' . str_repeat('d', 32) . '.jpg']));
        $this->assertTrue($set->contains(['id' => 1, 'file_key' => 'k', 'disk' => 'public', 'path' => 'modules/news/x.png']));
        $this->assertFalse($set->contains(['id' => 1, 'file_key' => 'k', 'disk' => 'private', 'path' => 'modules/news/x.png']), 'A URL is a file of a public disk.');
        $this->assertFalse($set->contains(['id' => 2, 'file_key' => str_repeat('e', 32), 'disk' => 'public', 'path' => 'modules/news/y.png']));
    }

    // Providers -----------------------------------------------------------------------------------

    public function testNewsProvider(): void
    {
        $cover   = $this->file('modules/news');
        $banner  = $this->file('modules/news');
        $bodyCa  = $this->file('modules/news');
        $bodyEn  = $this->file('modules/news');
        $trashed = $this->file('modules/news');
        $orphan  = $this->file('modules/news');
        $db      = db_connect();

        $db->table('news')->insert(['slug' => 'a', 'cover' => $cover->key]);
        $news = (int) $db->insertID();
        $db->table('news')->insert(['slug' => 'b', 'cover' => $trashed->key, 'deleted_at' => self::NOW]);
        $db->table('news_translations')->insertBatch([
            ['news_id' => $news, 'locale' => 'ca', 'title' => 'A', 'body' => '<p><img src="' . $this->storage->url($bodyCa) . '" alt=""></p>'],
            ['news_id' => $news, 'locale' => 'en', 'title' => 'A', 'body' => '<p><img src="/uploads/' . $bodyEn->path . '" alt=""></p>'],
        ]);
        $db->table('news_categories')->insert(['slug' => 'c', 'banner' => $banner->key]);

        $this->assertUnused([$orphan], [$cover, $banner, $bodyCa, $bodyEn, $trashed]);
    }

    public function testPagesProviderIncludingRevisionsBlocksAndHiddenSections(): void
    {
        $featured = $this->file('modules/pages');
        $section  = $this->file('modules/pages');
        $hidden   = $this->file('modules/pages');
        $block    = $this->file('modules/pages');
        $revision = $this->file('modules/pages');
        $body     = $this->file('modules/pages');
        $orphan   = $this->file('modules/pages');
        $db       = db_connect();

        $db->table('pages')->insert(['featured_image' => $featured->key]);
        $page = (int) $db->insertID();
        $db->table('page_translations')->insert(['page_id' => $page, 'locale' => 'es', 'title' => 'P', 'slug' => 'p', 'body' => '<img src="' . $this->storage->url($body) . '">']);
        $db->table('page_sections')->insert(['page_id' => $page, 'type' => 'pages.image', 'data' => json_encode(['image' => $section->key])]);
        $db->table('page_sections')->insert(['page_id' => $page, 'type' => 'pages.hero', 'options' => json_encode(['hidden' => true, 'background' => $hidden->key])]);
        $db->table('page_blocks')->insert(['name' => 'Footer']);
        $db->table('page_block_sections')->insert(['block_id' => (int) $db->insertID(), 'type' => 'pages.image', 'data' => json_encode(['image' => $block->key])]);
        // The image was removed from the page, but a revision can bring it back.
        $db->table('page_revisions')->insert(['page_id' => $page, 'snapshot' => json_encode(['sections' => [['data' => ['image' => $revision->key]]]])]);

        $this->assertUnused([$orphan], [$featured, $section, $hidden, $block, $revision, $body]);
    }

    public function testEventsAssistantTutorialMenusFilesAndCoreProviders(): void
    {
        $event    = $this->file('modules/pages');
        $faq      = $this->file('modules/pages');
        $menu     = $this->file('modules/pages');
        $theme    = $this->file('modules/pages');
        $manager  = $this->file('modules/pages');
        $attached = $this->file('modules/tutorial', 'private');
        $orphan   = $this->file('modules/tutorial', 'private');
        $db       = db_connect();

        $db->table('events')->insert(['starts_at' => self::NOW]);
        $db->table('event_translations')->insert(['event_id' => (int) $db->insertID(), 'locale' => 'ca', 'title' => 'E', 'description' => '<img src="' . $this->storage->url($event) . '">']);
        $db->table('assistant_faqs')->insert(['position' => 1]);
        $db->table('assistant_faq_translations')->insert(['faq_id' => (int) $db->insertID(), 'locale' => 'ca', 'question' => 'Q', 'answer' => '<p><img src="' . $this->storage->url($faq) . '"></p>']);
        $db->table('tutorial_tasks')->insert(['title' => 'T']);
        $db->table('tutorial_task_attachments')->insert(['task_id' => (int) $db->insertID(), 'file_key' => $attached->key]);
        $db->table('menus')->insert(['name' => 'main']);
        $db->table('menu_items')->insert(['menu_id' => (int) $db->insertID(), 'type' => 'url', 'value' => $this->storage->url($menu)]);
        $db->table('settings')->insert(['class' => 'Config\Theme', 'key' => 'logo', 'value' => $this->storage->url($theme), 'type' => 'string', 'context' => 'theme:default', 'created_at' => self::NOW, 'updated_at' => self::NOW]);
        $db->table('files')->insert(['stored_file_id' => $manager->id, 'disk' => 'public', 'folder' => 'modules/pages', 'name' => 'x.png', 'deleted_at' => self::NOW]);

        $this->assertUnused([$orphan], [$event, $faq, $menu, $theme, $manager, $attached]);
    }

    // B. Safety rules -----------------------------------------------------------------------------

    public function testADeletedPostsCoverIsListedOnlyAfterTheGracePeriod(): void
    {
        $cover = $this->storage->storeContents(self::png(), 'cover.png', 'public', 'modules/news');
        $db    = db_connect();
        $db->table('news')->insert(['slug' => 'a', 'cover' => $cover->key]);
        $db->table('news')->where('slug', 'a')->delete(); // purged from the trash

        $this->assertSame([], $this->ids($this->unused()->scan()), 'Too new: it may sit in a form not saved yet.');

        Time::setTestNow(Time::parse(self::NOW)->addDays(29));
        $this->assertSame([], $this->ids($this->unused()->scan()));

        Time::setTestNow(Time::parse(self::NOW)->addDays(31));
        $scan = $this->unused()->scan();
        $this->assertSame([$cover->id], $this->ids($scan));
        $this->assertSame('News', $scan['files'][0]['module']);
        $this->assertSame('modules/news', $scan['files'][0]['folder']);
        $this->assertSame(30, $scan['graceDays']);
    }

    public function testTheGracePeriodNeverGoesBelowTheMinimum(): void
    {
        $this->assertSame(30, UnusedFiles::graceDays());
        service('settings')->set('Core.unusedFilesGraceDays', 1);
        $this->assertSame(UnusedFiles::MIN_GRACE_DAYS, UnusedFiles::graceDays());
        service('settings')->set('Core.unusedFilesGraceDays', 45);
        $this->assertSame(45, UnusedFiles::graceDays());

        // A test-only value is ignored in production.
        $config                           = config(StorageConfig::class);
        $config->unusedFilesTestGraceDays = 0;

        try {
            $this->assertSame(0, UnusedFiles::graceDays(), 'testing');
        } finally {
            $config->unusedFilesTestGraceDays = null;
        }

        $this->assertStringContainsString("ENVIRONMENT !== 'production'", (string) file_get_contents(ROOTPATH . 'modules/Core/Storage/UnusedFiles.php'));
    }

    public function testAnImageUsedByTwoPostsStaysAfterDeletingOne(): void
    {
        $image = $this->file('modules/news');
        $db    = db_connect();
        $db->table('news')->insertBatch([['slug' => 'a', 'cover' => $image->key], ['slug' => 'b', 'cover' => $image->key]]);
        $db->table('news')->where('slug', 'a')->delete();

        $this->assertSame([], $this->ids($this->unused()->scan()));
    }

    public function testFoldersWithoutAProviderAreNotCheckedAndNeverListed(): void
    {
        $stray  = $this->file('modules/legacy');
        $nested = $this->file('modules/news/attachments');
        $scan   = $this->unused()->scan();

        $this->assertNotContains($stray->id, $this->ids($scan));
        $this->assertNotContains($nested->id, $this->ids($scan), 'Only the exact folders a provider declares.');
        $folders = array_column($scan['notChecked'], 'reason', 'folder');
        $this->assertSame('noProvider', $folders['modules/legacy'] ?? null);
        $this->assertSame('noProvider', $folders['modules/news/attachments'] ?? null);

        $result = $this->unused()->delete([$stray->id, $nested->id]);
        $this->assertSame(0, $result['deleted']);
        $this->assertSame(2, $result['skipped'][UnusedFiles::SKIP_NOT_CHECKED] ?? 0);
        $this->assertNotNull($this->storage->findById($stray->id));
    }

    public function testADisabledModulesFilesAreNotListedButItsReferencesCount(): void
    {
        $newsFile  = $this->file('modules/news');
        $pageImage = $this->file('modules/pages');
        db_connect()->table('news')->insert(['slug' => 'a']);
        db_connect()->table('news_translations')->insert(['news_id' => (int) db_connect()->insertID(), 'locale' => 'ca', 'title' => 'A', 'body' => '<img src="' . $this->storage->url($pageImage) . '">']);

        $declared                                  = $this->declared();
        $declared[NewsFileReferences::class]['enabled'] = false;
        $scan                                      = (new UnusedFiles($this->storage, $declared))->scan();

        $this->assertNotContains($newsFile->id, $this->ids($scan));
        $this->assertNotContains($pageImage->id, $this->ids($scan), 'A disabled module still protects what it links to.');
        $this->assertContains(['disk' => 'public', 'folder' => 'modules/news', 'files' => 1, 'bytes' => $newsFile->size, 'module' => 'News', 'reason' => 'disabled'], $scan['notChecked']);

        $result = (new UnusedFiles($this->storage, $declared))->delete([$newsFile->id]);
        $this->assertSame(1, $result['skipped'][UnusedFiles::SKIP_NOT_CHECKED] ?? 0);
    }

    public function testADisabledModuleIsNotCheckedThroughTheRealDeclarations(): void
    {
        $this->storeDeclarations();
        $orphan = $this->file('modules/news');

        $this->assertContains($orphan->id, $this->ids((new UnusedFiles($this->storage))->scan()));

        db_connect()->table('modules')->where('name', 'News')->update(['enabled' => 0]);
        (new CoreCache())->invalidate('modules');

        $this->assertNotContains($orphan->id, $this->ids((new UnusedFiles($this->storage))->scan()));
    }

    public function testAProviderThatThrowsAbortsTheScanAndTheDeletion(): void
    {
        $orphan   = $this->file('modules/news');
        $declared = $this->declared() + [FailingProvider::class => ['module' => 'Broken', 'enabled' => true]];

        try {
            (new UnusedFiles($this->storage, $declared))->scan();
            $this->fail('The scan must fail.');
        } catch (UnusedFilesException $e) {
            $this->assertStringContainsString(FailingProvider::class, $e->getMessage());
        }

        $this->expectException(UnusedFilesException::class);

        try {
            (new UnusedFiles($this->storage, $declared))->delete([$orphan->id]);
        } finally {
            $this->assertNotNull($this->storage->findById($orphan->id), 'Nothing is deleted.');
            $this->seeNumRecords(0, 'activity_log', ['action' => 'unused_files_deleted']);
        }
    }

    public function testAMissingProviderOrABadFolderAbortsTheScan(): void
    {
        foreach ([['Modules\Nope\Missing', 'Nope'], [BadFolderProvider::class, 'Bad'], [DatedFolderProvider::class, 'Bad']] as [$class, $module]) {
            try {
                (new UnusedFiles($this->storage, $this->declared() + [$class => ['module' => $module, 'enabled' => true]]))->scan();
                $this->fail($class);
            } catch (UnusedFilesException $e) {
                $this->assertStringContainsString($class, $e->getMessage());
            }
        }
    }

    public function testUserAndSharedFoldersAreNeverListed(): void
    {
        $personal = $this->file('users/3', 'private');
        $shared   = $this->file('shared/Web', 'public');
        $scan     = $this->unused()->scan();

        $this->assertNotContains($personal->id, $this->ids($scan));
        $this->assertNotContains($shared->id, $this->ids($scan));
        $this->assertNotContains('users/3', array_column($scan['notChecked'], 'folder'), 'Not even as "not checked": they are not module folders.');

        $result = $this->unused()->delete([$personal->id, $shared->id]);
        $this->assertSame(2, $result['skipped'][UnusedFiles::SKIP_NOT_CHECKED] ?? 0);
    }

    public function testAReferenceToAMissingFileIsIgnored(): void
    {
        $orphan = $this->file('modules/news');
        db_connect()->table('news')->insert(['slug' => 'a', 'cover' => str_repeat('f', 32)]);

        $this->assertSame([$orphan->id], $this->ids($this->unused()->scan()));
    }

    public function testDeletionRemovesFileVariantsAndRowAndRechecksReferences(): void
    {
        $image = $this->file('modules/news', 'public', self::png(900, 600));
        $used  = $this->file('modules/news');
        $scan  = $this->unused()->scan();
        $this->assertSame([$image->id, $used->id], $this->ids($scan));
        $variants = db_connect()->table('stored_file_variants')->where('stored_file_id', $image->id)->get()->getResultArray();
        $this->assertNotEmpty($variants);
        $this->assertSame(count($variants), $scan['files'][0]['variants']);

        // Used after the scan: the re-check keeps it.
        db_connect()->table('news')->insert(['slug' => 'late', 'cover' => $used->key]);
        $result = $this->unused()->delete([$image->id, $used->id, 999999], ['via' => 'command']);

        $this->assertSame(1, $result['deleted']);
        $this->assertSame([UnusedFiles::SKIP_USED => 1, UnusedFiles::SKIP_MISSING => 1], $result['skipped']);
        $this->assertNull($this->storage->findById($image->id));
        $this->assertFalse($this->storage->disk('public')->exists($image->path));
        $this->seeNumRecords(0, 'stored_file_variants', ['stored_file_id' => $image->id]);

        foreach ($variants as $variant) {
            $this->assertFalse($this->storage->disk('public')->exists((string) $variant['path']));
        }

        $this->assertNotNull($this->storage->findById($used->id));
        $log = db_connect()->table('activity_log')->where('action', 'unused_files_deleted')->get()->getResultArray();
        $this->assertCount(1, $log, 'One entry per run.');
        $this->assertStringContainsString('"via":"command"', (string) $log[0]['changes']);
        $this->assertStringContainsString($image->path, (string) $log[0]['changes']);
    }

    public function testDeletingRefusesFilesYoungerThanTheGracePeriod(): void
    {
        $new = $this->storage->storeContents(self::png(), 'n.png', 'public', 'modules/news');

        $result = $this->unused()->delete([$new->id]);

        $this->assertSame([UnusedFiles::SKIP_TOO_NEW => 1], $result['skipped']);
        $this->assertNotNull($this->storage->findById($new->id));
    }

    public function testModuleFilterAndLimit(): void
    {
        $news  = $this->file('modules/news');
        $pages = $this->file('modules/pages');

        $this->assertSame([$news->id], $this->ids($this->unused()->scan(['module' => 'News'])));
        $this->assertSame([$pages->id], $this->ids($this->unused()->scan(['module' => 'pages'])));
        $limited = $this->unused()->scan(['limit' => 1]);
        $this->assertSame(2, $limited['count']);
        $this->assertCount(1, $limited['files']);
        $this->assertTrue($limited['truncated']);

        $result = $this->unused()->delete([$news->id, $pages->id], ['module' => 'News']);
        $this->assertSame(1, $result['deleted']);
        $this->assertNotNull($this->storage->findById($pages->id));
    }

    // C. Command ----------------------------------------------------------------------------------

    public function testTheCommandListsAsksDeletesAndLogsOnce(): void
    {
        $this->storeDeclarations();
        $orphan = $this->file('modules/news');
        $pages  = $this->file('modules/pages');
        $this->file('modules/legacy');
        $io = new MockInputOutput();
        CLI::setInputOutput($io);

        try {
            command('storage:unused');
            $listing = $io->getOutput();
            $this->assertStringContainsString('public:' . $orphan->path, $listing);
            $this->assertStringContainsString('modules/legacy', $listing, 'Not-checked folders are printed.');
            $this->assertNotNull($this->storage->findById($orphan->id));

            $io->setInputs(['n']);
            command('storage:unused --delete --module=News');
            $this->assertNotNull($this->storage->findById($orphan->id), 'Answering no deletes nothing.');

            $io->setInputs(['y']);
            command('storage:unused --delete --module=News');
            $this->assertNull($this->storage->findById($orphan->id));
            $this->assertNotNull($this->storage->findById($pages->id), '--module limits the deletion.');

            command('storage:unused --delete --yes --older-than=1');
            $output = $io->getOutput();
        } finally {
            CLI::resetInputOutput();
        }

        $this->assertNull($this->storage->findById($pages->id), '--yes skips the question.');
        $this->assertStringContainsString(lang('Core.unused.olderThanRaised', ['1', 30]), $output);
        $this->seeNumRecords(2, 'activity_log', ['module' => 'Core', 'action' => 'unused_files_deleted']);
    }

    public function testOlderThanOnlyMakesTheGracePeriodLonger(): void
    {
        $file = $this->file('modules/news', 'public', null, 40);

        $this->assertSame([$file->id], $this->ids($this->unused()->scan(['olderThan' => 1])));
        $this->assertSame([], $this->ids($this->unused()->scan(['olderThan' => 60])));
        $this->assertSame(30, $this->unused()->scan(['olderThan' => 1])['olderThan']);
    }

    // E. Automatic deletion -----------------------------------------------------------------------

    public function testTheTaskIsDeclaredAndOffByDefault(): void
    {
        $this->assertContains(DeleteUnusedFilesTask::class, (new \Modules\Core\Config\Module())->tasks);
        $this->assertSame('23 4 * * 0', (new DeleteUnusedFilesTask())->schedule()->expression());
        $this->assertFalse(DeleteUnusedFilesTask::enabled());
        $file = $this->file('modules/news', 'public', null, 400);

        $result = (new DeleteUnusedFilesTask($this->unused()))->run();

        $this->assertFalse($result->failed());
        $this->assertStringContainsString(lang('Core.unused.taskDisabled'), $result->storedOutput());
        $this->assertNotNull($this->storage->findById($file->id));
        $this->seeNumRecords(0, 'activity_log', ['action' => 'unused_files_deleted']);
    }

    public function testTheTaskDeletesOnlyUnusedFilesOlderThanItsAge(): void
    {
        service('settings')->set('Core.unusedFilesAutoDelete', true);
        $recent  = $this->file('modules/news', 'public', null, 60);
        $old     = $this->file('modules/news', 'public', null, 100);
        $used    = $this->file('modules/news', 'public', null, 100);
        $legacy  = $this->file('modules/legacy', 'public', null, 400);
        db_connect()->table('news')->insert(['slug' => 'a', 'cover' => $used->key]);

        $result = (new DeleteUnusedFilesTask($this->unused()))->run();

        $this->assertFalse($result->failed());
        $this->assertNull($this->storage->findById($old->id));
        $this->assertNotNull($this->storage->findById($recent->id), '60 days < 90 days.');
        $this->assertNotNull($this->storage->findById($used->id));
        $this->assertNotNull($this->storage->findById($legacy->id), 'Not-checked folders are never touched.');
        $log = db_connect()->table('activity_log')->where('action', 'unused_files_deleted')->get()->getResultArray();
        $this->assertCount(1, $log);
        $this->assertStringContainsString('"via":"task"', (string) $log[0]['changes']);

        // Nothing left: no entry.
        (new DeleteUnusedFilesTask($this->unused()))->run();
        $this->seeNumRecords(1, 'activity_log', ['action' => 'unused_files_deleted']);
    }

    public function testTheTaskProcessesOneBatchPerRun(): void
    {
        service('settings')->set('Core.unusedFilesAutoDelete', true);
        $config                    = new StorageConfig();
        $config->unusedDeleteBatch = 2;

        foreach (range(1, 3) as $i) {
            $this->file('modules/news', 'public', null, 100);
        }

        $first = (new DeleteUnusedFilesTask($this->unused(), $config))->run();
        $this->assertFalse($first->failed());
        $this->assertSame(1, db_connect()->table('stored_files')->countAllResults());
        (new DeleteUnusedFilesTask($this->unused(), $config))->run();
        $this->assertSame(0, db_connect()->table('stored_files')->countAllResults());
        $this->seeNumRecords(2, 'activity_log', ['action' => 'unused_files_deleted']);
    }

    public function testTheTaskFailsWhenAProviderThrows(): void
    {
        service('settings')->set('Core.unusedFilesAutoDelete', true);
        $file   = $this->file('modules/news', 'public', null, 100);
        $broken = new UnusedFiles($this->storage, $this->declared() + [FailingProvider::class => ['module' => 'Broken', 'enabled' => true]]);

        $result = (new DeleteUnusedFilesTask($broken))->run();

        $this->assertTrue($result->failed());
        $this->assertNotNull($this->storage->findById($file->id));
    }

    public function testTheAgeNeverGoesBelowTheGracePeriod(): void
    {
        $this->assertSame(90, DeleteUnusedFilesTask::days());
        service('settings')->set('Core.unusedFilesGraceDays', 60);
        service('settings')->set('Core.unusedFilesAutoDeleteDays', 30);
        $this->assertSame(60, DeleteUnusedFilesTask::days());

        $rules = new \Modules\Core\Settings\SecurityRules();
        $error = null;
        $this->assertFalse($rules->not_below_setting('30', 'Core.unusedFilesGraceDays', ['Core-unusedFilesGraceDays' => '45'], $error));
        $this->assertNotNull($error);
        $this->assertTrue($rules->not_below_setting('45', 'Core.unusedFilesGraceDays', ['Core-unusedFilesGraceDays' => '45']));
        $this->assertFalse($rules->not_below_setting('59', 'Core.unusedFilesGraceDays', []), 'Without a posted value: the stored one.');
        $this->assertFalse($rules->not_below_setting('x', 'Core.unusedFilesGraceDays', []));
    }

    // Helpers -------------------------------------------------------------------------------------

    /**
     * A stored file of a folder, `$days` old (default 40: past the grace period).
     */
    private function file(string $folder, string $disk = 'public', ?string $contents = null, int $days = 40): StoredFile
    {
        $file = $this->storage->storeContents($contents ?? self::png(), 'f.png', $disk, $folder);
        db_connect()->table('stored_files')->where('id', $file->id)->update(['created_at' => Time::parse(self::NOW)->subDays($days)->toDateTimeString()]);

        return $this->storage->findById($file->id) ?? throw new RuntimeException('stored');
    }

    /**
     * @return array<string, array{module: string, enabled: bool}>
     */
    private function declared(): array
    {
        $declared = [];

        foreach (self::PROVIDERS as $class => $module) {
            $declared[$class] = ['module' => $module, 'enabled' => true];
        }

        return $declared;
    }

    private function unused(): UnusedFiles
    {
        return new UnusedFiles($this->storage, $this->declared());
    }

    /**
     * @param array<string, mixed> $scan
     *
     * @return list<int>
     */
    private function ids(array $scan): array
    {
        $ids = array_map(static fn (array $file): int => (int) $file['id'], $scan['files']);
        sort($ids);

        return $ids;
    }

    /**
     * @param list<StoredFile> $unused
     * @param list<StoredFile> $used
     */
    private function assertUnused(array $unused, array $used): void
    {
        $ids = $this->ids($this->unused()->scan());

        foreach ($unused as $file) {
            $this->assertContains($file->id, $ids, $file->path . ' is unused');
        }

        foreach ($used as $file) {
            $this->assertNotContains($file->id, $ids, $file->path . ' is used');
        }
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
}

/**
 * @internal
 */
final class FailingProvider implements FileReferenceProvider
{
    public static int $calls = 0;

    public function folders(): array
    {
        return [];
    }

    public function references(): iterable
    {
        self::$calls++;

        throw new RuntimeException('database gone');
    }
}

/**
 * @internal
 */
final class BadFolderProvider implements FileReferenceProvider
{
    public function folders(): array
    {
        return ['users/1'];
    }

    public function references(): iterable
    {
        return [];
    }
}

/**
 * @internal
 */
final class DatedFolderProvider implements FileReferenceProvider
{
    public function folders(): array
    {
        return ['modules/news/2026'];
    }

    public function references(): iterable
    {
        return [];
    }
}
