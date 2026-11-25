<?php

declare(strict_types=1);

namespace Modules\Core\Tests;

use CodeIgniter\Events\Events;
use CodeIgniter\Test\StreamFilterTrait;
use Modules\Auth\Tests\AuthTestCase;
use Modules\Core\System\ReportRow;
use Modules\Core\System\SystemEnvironment;
use Modules\Core\System\SystemReport;

/**
 * Settings › System › System information (`backend/system`, `system.info`; D-53-01): the same
 * report as `php spark about`, no secrets, the warnings, the `systemReport` event, `--plain`,
 * the permission and forged POSTs, the logged download.
 *
 * @internal
 */
final class SystemInfoTest extends AuthTestCase
{
    use StreamFilterTrait;

    private string $encryptionKey = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->encryptionKey = (string) config('Encryption')->key;
        SystemReport::refresh();
    }

    protected function tearDown(): void
    {
        config('Encryption')->key = $this->encryptionKey;
        Events::removeAllListeners(SystemReport::EVENT);
        // The modules' own listeners (Backup) come back with the events file.
        Events::on(SystemReport::EVENT, static function (SystemReport $report): void {
            if ((new \Modules\Core\Registry\ModuleState())->isEnabled('Backup')) {
                (new \Modules\Backup\Services\BackupSystemReport())->add($report);
            }
        });
        SystemReport::refresh();
        parent::tearDown();
    }

    public function testAdminsSeeTheScreenAndEditorsAreRefused(): void
    {
        $this->createUser('editor@example.com', 'editor');
        $this->login('editor@example.com');
        $this->send('GET', 'backend/system')->assertStatus(403);
        $this->send('GET', 'backend/system/report.txt')->assertStatus(403);

        cache()->save(SystemReport::CACHE_KEY, ['sizes' => [], 'assets' => 7], 300);
        $this->send('POST', 'backend/system/refresh')->assertStatus(403);
        $this->assertIsArray(cache()->get(SystemReport::CACHE_KEY), 'A forged POST clears nothing.');

        $_SESSION = [];
        $this->createUser('admin@example.com', 'admin');
        $this->login('admin@example.com');
        $page = $this->send('GET', 'backend/system');
        $page->assertOK();
        $page->assertSee(lang('System.info.copy'));
        $this->assertStringNotContainsString('data-system-log-errors', (string) $page->getBody(), 'The log hint needs system.logs.');
        $this->assertSame(0, db_connect()->table('activity_log')->where('action', 'system_report_downloaded')->countAllResults(), 'Opening the screen is not logged.');

        $this->send('POST', 'backend/system/refresh')->assertRedirectTo(site_url('backend/system'));
        $this->assertNull(cache()->get(SystemReport::CACHE_KEY), 'Refresh drops the cached rows.');
    }

    public function testTheScreenAndAboutShowTheSameReport(): void
    {
        $this->createUser('admin@example.com', 'admin');
        $this->login('admin@example.com');
        $page = html_entity_decode((string) $this->send('GET', 'backend/system')->getBody(), ENT_QUOTES);

        command('about');
        $about = $this->getStreamFilterBuffer();
        $this->resetStreamFilterBuffer();
        command('about --plain');
        $plain = $this->getStreamFilterBuffer();

        $report = SystemReport::build();
        $this->assertNotSame([], $report->sections());

        foreach ($report->sections() as $section) {
            $this->assertStringContainsString('data-system-section="' . $section->key . '"', $page);
            $this->assertStringContainsString($section->title, $about);
            $this->assertStringContainsString('## ' . $section->title, $plain);

            foreach ($section->rows() as $row) {
                // Values that change between two builds (the clock) are compared by label.
                $this->assertStringContainsString($row->label(), $page, $section->key);
                $this->assertStringContainsString($row->label(), $about, $section->key);
                $this->assertStringContainsString('- ' . $row->label() . ': ', $plain, $section->key);
            }
        }

        $this->assertStringContainsString(lang('System.rows.backendPath'), $plain);
        $this->assertStringContainsString('backend', $plain);
        // The copy button copies exactly the plain text shown on the page.
        $this->assertStringContainsString('id="system-report-text"', $page);
    }

    public function testNoSecretIsShown(): void
    {
        $secret = 'hex2bin:' . str_repeat('ab12', 16);
        config('Encryption')->key = $secret;
        $mask = (string) config('App')->maskKey;
        $this->assertNotSame('', $mask);

        $this->createUser('admin@example.com', 'admin');
        $this->login('admin@example.com');
        $page     = (string) $this->send('GET', 'backend/system')->getBody();
        $download = $this->send('GET', 'backend/system/report.txt');
        $text     = (string) $download->getBody();
        command('about');
        command('about --plain');
        $cli = $this->getStreamFilterBuffer();

        foreach ([$page, $text, $cli] as $output) {
            $this->assertStringNotContainsString(str_repeat('ab12', 16), $output);
            $this->assertStringNotContainsString($mask, $output);
            $this->assertStringNotContainsString(self::$password, $output);
            $this->assertStringContainsString(lang('Install.about.set'), $output);
        }

        $this->assertStringContainsString('attachment;', $download->response()->getHeaderLine('Content-Disposition'));
        $this->assertSame(1, db_connect()->table('activity_log')->where('action', 'system_report_downloaded')->countAllResults(), 'The download is logged.');
    }

    public function testWarnings(): void
    {
        $env = new class () extends SystemEnvironment {
            public function extensionLoaded(string $extension): bool
            {
                return $extension !== 'intl';
            }

            public function ini(string $name): string
            {
                return $name === 'display_errors' ? '1' : parent::ini($name);
            }

            public function diskFreeSpace(string $path): float
            {
                return 1048576.0;
            }

            public function environment(): string
            {
                return 'production';
            }
        };

        // A task overdue for three hours: the cron is not running.
        db_connect()->table('scheduled_tasks')->insert([
            'class' => 'Tests\\OverdueTask', 'module' => 'Core', 'expression' => '* * * * *', 'active' => 1,
            'next_run_at' => gmdate('Y-m-d H:i:s', time() - 3 * 3600),
        ]);
        // A module migration that has not run.
        $row = db_connect()->table('migrations')->where('namespace', 'Modules\\Core')->orderBy('id', 'DESC')->get(1)->getRowArray();
        $this->assertIsArray($row);
        db_connect()->table('migrations')->where('id', $row['id'])->delete();

        try {
            $report = SystemReport::build($env);
        } finally {
            db_connect()->table('migrations')->insert($row);
        }

        $rows   = [];

        foreach ($report->sections() as $section) {
            foreach ($section->rows() as $r) {
                $rows[$r->label()] = $r;
            }
        }

        $this->assertSame(ReportRow::ERROR, $rows[lang('System.rows.extension', ['intl'])]->status);
        $this->assertSame(ReportRow::OK, $rows[lang('System.rows.extension', ['mbstring'])]->status);
        $this->assertSame(ReportRow::WARNING, $rows['display_errors']->status);
        $this->assertSame(ReportRow::WARNING, $rows[lang('System.rows.diskFree')]->status);
        $this->assertSame(ReportRow::WARNING, $rows[lang('System.rows.lastCron')]->status);
        $this->assertSame(ReportRow::WARNING, $rows[lang('System.rows.pendingMigrations', ['Core'])]->status);
        $this->assertSame('1', $rows[lang('System.rows.pendingMigrations', ['Core'])]->value());
        $this->assertGreaterThanOrEqual(5, $report->counts()['warning'] + $report->counts()['error']);
        $this->assertStringContainsString('[' . lang('System.status.error'), $report->plain());
    }

    public function testNoCronWarningWhenTasksAreOnTime(): void
    {
        db_connect()->table('scheduled_tasks')->insert([
            'class' => 'Tests\\FutureTask', 'module' => 'Core', 'expression' => '0 3 * * *', 'active' => 1,
            'next_run_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);
        db_connect()->table('scheduled_tasks')->where('class !=', 'Tests\\FutureTask')->update(['next_run_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);

        foreach (SystemReport::build()->sections() as $section) {
            foreach ($section->rows() as $row) {
                if ($row->label() === lang('System.rows.lastCron')) {
                    $this->assertNotSame(ReportRow::WARNING, $row->status);

                    return;
                }
            }
        }

        $this->fail('No cron row.');
    }

    public function testAModuleAddsRowsThroughTheEvent(): void
    {
        Events::on(SystemReport::EVENT, static function (SystemReport $report): void {
            $report->section('fixture', 'Fixture module')->add('Fixture row', 'fixture value', ReportRow::WARNING, 'Fixture note');
            $report->section('php')->add('Fixture PHP row', 'x');
        });

        $report = SystemReport::build();
        $this->assertStringContainsString("## Fixture module\n- Fixture row: fixture value [" . lang('System.status.warning') . ': Fixture note]', $report->plain());
        $this->assertStringContainsString('- Fixture PHP row: x', $report->plain());

        $this->createUser('admin@example.com', 'admin');
        $this->login('admin@example.com');
        $page = (string) $this->send('GET', 'backend/system')->getBody();
        $this->assertStringContainsString('data-system-section="fixture"', $page);
        $this->assertStringContainsString('Fixture note', $page);
    }

    public function testTheBackupModuleAddsItsLastBackup(): void
    {
        $report = SystemReport::build();
        $labels = [];

        foreach ($report->sections() as $section) {
            $labels[$section->key] = array_map(static fn (ReportRow $r): string => $r->label(), $section->rows());
        }

        $this->assertArrayHasKey('backup', $labels);
        $this->assertContains(lang('Backup.system.last'), $labels['backup']);
    }

    public function testFolderSizeIsBounded(): void
    {
        $dir = sys_get_temp_dir() . '/kp-size-' . bin2hex(random_bytes(4));
        mkdir($dir . '/sub', 0700, true);

        for ($i = 0; $i < 5; $i++) {
            file_put_contents($dir . '/sub/f' . $i, str_repeat('x', 10));
        }

        $this->assertSame(['bytes' => 50, 'files' => 5, 'complete' => true], SystemReport::folderSize($dir));
        $this->assertSame(['bytes' => 30, 'files' => 3, 'complete' => false], SystemReport::folderSize($dir, 3));

        array_map('unlink', glob($dir . '/sub/*') ?: []);
        rmdir($dir . '/sub');
        rmdir($dir);
    }
}
