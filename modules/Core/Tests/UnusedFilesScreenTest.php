<?php

declare(strict_types=1);

namespace Modules\Core\Tests;

use CodeIgniter\I18n\Time;
use Modules\Auth\Tests\AuthTestCase;
use Modules\Core\Cache\CoreCache;
use Modules\Core\Modules\ModuleDiscovery;
use Modules\Core\Registry\DeclarationRegistry;
use Modules\Core\Storage\RichTextFiles;
use Modules\Core\Storage\Storage;
use Modules\Core\Storage\StoredFile;
use Modules\Core\Storage\UnusedFiles;
use Modules\Core\System\ReportRow;
use Modules\Core\System\SystemReport;
use RuntimeException;

/**
 * Settings › System › Unused files (`backend/system/unused-files`, `system.files`; D-55-01):
 * admin by default, deleting behind re-authentication and CSRF, every posted id checked again on
 * the server (forged, used, shared, garbage), flash counts, the empty state and the System
 * information row.
 *
 * @internal
 */
final class UnusedFilesScreenTest extends AuthTestCase
{
    use StorageFixturesTrait;

    private const URL = 'backend/system/unused-files';

    private Storage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = $this->makeStorage();
        RichTextFiles::reset();
        // A demo site's test-only grace period in .env must not change these tests.
        config(\Config\Storage::class)->unusedFilesTestGraceDays = null;
        UnusedFiles::forget();
        $this->storeDeclarations();
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        UnusedFiles::forget();
        cache()->delete(SystemReport::IMAGES_CACHE_KEY);
        cache()->delete(SystemReport::CACHE_KEY);
        $this->removeStorageRoot();
        parent::tearDown();
    }

    public function testOnlyHoldersOfThePermissionOpenIt(): void
    {
        $this->createUser('editor@example.com', 'editor');
        $this->login('editor@example.com');
        $this->send('GET', self::URL)->assertStatus(403);
        $this->send('POST', self::URL . '/scan')->assertStatus(403);
        $this->send('POST', self::URL . '/delete', ['mode' => 'all'])->assertStatus(403);

        $_SESSION = [];
        $this->createUser('admin@example.com', 'admin');
        $this->login('admin@example.com');
        $page = $this->send('GET', self::URL);
        $page->assertOK();
        $page->assertSee(lang('System.unused.title'));
    }

    public function testTheListShowsTheUnusedFilesWithTheirPreviewAndTheNotCheckedFolders(): void
    {
        $orphan  = $this->file('modules/news');
        $private = $this->file('modules/tutorial', 'private');
        $used    = $this->file('modules/news');
        $stray   = $this->file('modules/legacy');
        db_connect()->table('news')->insert(['slug' => 'a', 'cover' => $used->key]);
        $this->asAdmin();

        $body = (string) $this->send('GET', self::URL)->getBody();

        $this->assertStringContainsString('data-unused-file="' . $orphan->id . '"', $body);
        $this->assertStringContainsString('data-unused-file="' . $private->id . '"', $body);
        $this->assertStringNotContainsString('data-unused-file="' . $used->id . '"', $body);
        $this->assertStringNotContainsString('data-unused-file="' . $stray->id . '"', $body);
        $this->assertStringContainsString('public:modules/legacy', $body, 'Not-checked folders are explained.');
        $this->assertMatchesRegularExpression('#<img class="kp-unused-thumb[^"]*" src="[^"]*' . preg_quote($orphan->path, '#') . '"#', $body, 'Public images get a preview (ui_image).');
        $this->assertStringNotContainsString('files/private/' . $private->key, $body, 'Private files are never shown inline.');
        $this->assertStringContainsString('data-component="core/ui/unused-files"', $body);
        $this->assertStringContainsString('data-unused-auto="off"', $body);
        $this->assertStringNotContainsString(' style="', $body);

        $filtered = (string) $this->send('GET', self::URL, ['module' => 'Tutorial'])->getBody();
        $this->assertStringContainsString('data-unused-file="' . $private->id . '"', $filtered);
        $this->assertStringNotContainsString('data-unused-file="' . $orphan->id . '"', $filtered);
    }

    public function testTheEmptyState(): void
    {
        $this->asAdmin();

        $page = $this->send('GET', self::URL);
        $page->assertOK();
        $page->assertSee(lang('System.unused.empty'));
        $this->assertStringNotContainsString('data-unused-files', (string) $page->getBody());
    }

    public function testDeletingSelectedFilesReportsDeletedAndSkippedAndLogsOnce(): void
    {
        $orphan  = $this->file('modules/news');
        $used    = $this->file('modules/news');
        $shared  = $this->file('shared/Web');
        $newFile = $this->file('modules/news', 'public', 1);
        $private = $this->file('modules/news', 'private');
        db_connect()->table('news')->insert(['slug' => 'a', 'cover' => $used->key]);
        $this->asAdmin();

        $response = $this->send('POST', self::URL . '/delete', [
            'mode' => 'selected',
            'ids'  => [(string) $orphan->id, (string) $used->id, (string) $shared->id, (string) $newFile->id, (string) $private->id, '999999', 'abc', '-3', '1 OR 1=1'],
        ]);

        $response->assertRedirectTo(site_url(self::URL));
        $this->assertNull($this->storage->findById($orphan->id));
        $this->assertNull($this->storage->findById($private->id), 'The private disk is a module space too.');
        $this->assertNotNull($this->storage->findById($used->id), 'Referenced: kept.');
        $this->assertNotNull($this->storage->findById($shared->id), 'Shared folders are never candidates.');
        $this->assertNotNull($this->storage->findById($newFile->id), 'Too new: kept.');
        $this->assertSame(lang('Core.unused.deleted', [2, SystemReport::bytes($orphan->size + $private->size), 4]), $_SESSION['success'] ?? null);

        $log = db_connect()->table('activity_log')->where('action', 'unused_files_deleted')->get()->getResultArray();
        $this->assertCount(1, $log);
        $this->assertStringContainsString('"via":"screen"', (string) $log[0]['changes']);
        $this->assertNotNull($log[0]['user_id']);
    }

    public function testDeleteAllListedDeletesTheFilesOfTheModuleFilter(): void
    {
        $news  = $this->file('modules/news');
        $pages = $this->file('modules/pages');
        $this->asAdmin();

        $this->send('POST', self::URL . '/delete', ['mode' => 'all', 'module' => 'News'])->assertRedirectTo(site_url(self::URL));
        $this->assertNull($this->storage->findById($news->id));
        $this->assertNotNull($this->storage->findById($pages->id));

        $this->send('POST', self::URL . '/delete', ['mode' => 'all', 'module' => '../../etc'])->assertRedirectTo(site_url(self::URL));
        $this->assertNull($this->storage->findById($pages->id), 'An invalid module is ignored: all listed files.');
    }

    public function testNothingSelectedOrGarbage(): void
    {
        $orphan = $this->file('modules/news');
        $this->asAdmin();

        $this->send('POST', self::URL . '/delete', ['mode' => 'selected'])->assertRedirectTo(site_url(self::URL));
        $this->assertSame(lang('System.unused.noneSelected'), $_SESSION['error'] ?? null);
        $this->send('POST', self::URL . '/delete', ['mode' => 'selected', 'ids' => 'not-a-list'])->assertRedirectTo(site_url(self::URL));
        $this->send('POST', self::URL . '/delete', ['mode' => 'selected', 'ids' => [['nested']]])->assertRedirectTo(site_url(self::URL));
        $this->assertNotNull($this->storage->findById($orphan->id));
    }

    public function testDeletingNeedsReauthenticationAndCsrf(): void
    {
        $orphan = $this->file('modules/news');
        $this->asAdmin();
        Time::setTestNow(Time::now()->addMinutes(16));

        $this->send('POST', self::URL . '/delete', ['mode' => 'selected', 'ids' => [(string) $orphan->id]])->assertRedirectTo(site_url('auth/reauth'));
        $this->assertNotNull($this->storage->findById($orphan->id));
        $this->send('GET', self::URL)->assertOK();
        Time::setTestNow();

        try {
            $this->send('POST', self::URL . '/delete', ['mode' => 'selected', 'ids' => [(string) $orphan->id], 'csrf_test_name' => 'forged'], ['csrf_test_name' => 'real-token'] + $_SESSION);
            $this->fail('A forged CSRF token is refused.');
        } catch (\CodeIgniter\Security\Exceptions\SecurityException) {
            $this->assertNotNull($this->storage->findById($orphan->id));
        }

        $routes = service('routes');
        $routes->loadRoutes();
        $this->assertSame(['can:system.files', 'reauth', 'ratelimit:forms'], $routes->getFiltersForRoute(self::URL . '/delete', 'POST'));
        $this->assertSame(['can:system.files', 'ratelimit:forms'], $routes->getFiltersForRoute(self::URL . '/scan', 'POST'));
    }

    public function testScanNowRefreshesTheCachedList(): void
    {
        $this->asAdmin();
        $this->send('GET', self::URL)->assertSee(lang('System.unused.empty'));
        $orphan = $this->file('modules/news');

        $this->assertStringNotContainsString('data-unused-file="' . $orphan->id . '"', (string) $this->send('GET', self::URL)->getBody(), 'The last scan is cached.');
        $this->send('POST', self::URL . '/scan')->assertRedirectTo(site_url(self::URL));
        $this->assertStringContainsString('data-unused-file="' . $orphan->id . '"', (string) $this->send('GET', self::URL)->getBody());
    }

    public function testTheSystemInformationRow(): void
    {
        $report = SystemReport::build();
        $row    = $this->row($report, lang('System.rows.unusedFiles'));
        $this->assertSame(ReportRow::OK, $row->status);
        $this->assertSame(lang('System.values.unusedNone'), $row->value());

        $orphan = $this->file('modules/news');
        $this->file('modules/legacy');
        SystemReport::refresh();
        $report = SystemReport::build();
        $row    = $this->row($report, lang('System.rows.unusedFiles'));

        $this->assertSame(ReportRow::WARNING, $row->status);
        $this->assertSame(lang('System.values.unusedFiles', ['1', SystemReport::bytes($orphan->size)]), $row->value());
        $this->assertStringContainsString(lang('System.values.unusedAutoOff'), $row->note);
        $this->assertStringContainsString('public:modules/legacy', $this->row($report, lang('System.rows.unusedNotChecked'))->value());

        // The link to the screen is added for holders of the permission only.
        $this->asAdmin();
        $this->assertStringContainsString('href="' . backend_url('system/unused-files') . '"', (string) $this->send('GET', 'backend/system')->getBody());
    }

    private function row(SystemReport $report, string $label): ReportRow
    {
        foreach ($report->sections() as $section) {
            foreach ($section->rows() as $row) {
                if ($row->label() === $label) {
                    return $row;
                }
            }
        }

        throw new RuntimeException('No row ' . $label);
    }

    private function asAdmin(): void
    {
        $this->createUser('admin@example.com', 'admin');
        $this->login('admin@example.com');
    }

    private function file(string $folder, string $disk = 'public', int $days = 40): StoredFile
    {
        $file = $this->storage->storeContents(self::png(), 'f.png', $disk, $folder);
        db_connect()->table('stored_files')->where('id', $file->id)->update(['created_at' => Time::now()->subDays($days)->toDateTimeString()]);

        return $this->storage->findById($file->id) ?? throw new RuntimeException('stored');
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
