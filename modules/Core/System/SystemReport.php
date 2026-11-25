<?php

declare(strict_types=1);

namespace Modules\Core\System;

use CodeIgniter\CodeIgniter;
use CodeIgniter\Events\Events;
use Modules\Core\Config\Version;
use Modules\Core\Frontend\AssetPublisher;
use Modules\Core\I18n\Locales;
use Modules\Core\Models\ModuleModel;
use Modules\Core\Modules\ModuleDiscovery;
use Modules\Core\Modules\ModuleMigrator;
use Modules\Core\Storage\ImageOptimizer;
use Modules\Core\Storage\UnusedFiles;
use Modules\Core\Storage\UnusedFilesException;
use Modules\Core\Tasks\DeleteUnusedFilesTask;
use Modules\Core\Tasks\OptimizeImagesTask;
use Throwable;

/**
 * The System information report (D-53-01, docs/system.md): what `php spark about` prints and
 * what Settings › System › System information shows, computed once here so they never differ.
 *
 * Sections of rows (label/value, or several cells), each row with an optional status (`ok`,
 * `warning`, `error`). Never a secret: keys are only "set / not set", no environment variables,
 * no paths outside the project root. Modules add their own sections or rows from the
 * `systemReport` event:
 *
 *     Events::on(SystemReport::EVENT, static function (SystemReport $report): void {
 *         if ((new ModuleState())->isEnabled('Backup')) {
 *             $report->section('backup', lang('Backup.system.title'))->add(lang('Backup.system.last'), '…');
 *         }
 *     });
 *
 * Expensive rows (folder sizes, the assets check) are cached for CACHE_TTL seconds; `refresh()`
 * drops that cache.
 */
final class SystemReport
{
    public const EVENT = 'systemReport';

    /** Seconds the folder sizes and the assets check are cached. */
    public const CACHE_TTL = 300;

    /** Cache key of the expensive rows. */
    public const CACHE_KEY = 'kp_system_report_expensive';

    /** Cache key and lifetime of the stored-files rows (D-54-01). */
    public const IMAGES_CACHE_KEY = 'kp_system_report_images';

    public const IMAGES_CACHE_TTL = 120;

    /** Days without a run of OptimizeImagesTask after which waiting images are a stronger warning. */
    public const OPTIMIZE_STALE_DAYS = 14;

    /** Free space of `writable/` under which the disk row is a warning (bytes). */
    public const LOW_DISK_BYTES = 1073741824;

    /** Minutes an active scheduled task may be overdue before the cron row is a warning. */
    public const CRON_GRACE_MINUTES = 60;

    /** Files counted at most per folder size (a bounded scan). */
    public const MAX_SCAN_FILES = 20000;

    /** Folders of `writable/` whose size is shown. */
    public const FOLDERS = ['logs', 'cache', 'backups', 'uploads', 'storage'];

    /** @var array<string, ReportSection> */
    private array $sections = [];

    private readonly SystemEnvironment $env;

    public function __construct(?SystemEnvironment $env = null)
    {
        $this->env = $env ?? new SystemEnvironment();
    }

    /**
     * Builds the whole report: the Core's sections, then the modules' (event).
     */
    public static function build(?SystemEnvironment $env = null): self
    {
        $report = new self($env);
        $report->application();
        $report->php();
        $report->storage();
        $report->database();
        $report->jobs();
        $report->assets();
        $report->modules();

        Events::trigger(self::EVENT, $report);

        return $report;
    }

    /**
     * Drops the cached expensive rows (the "Refresh" button).
     */
    public static function refresh(): void
    {
        cache()->delete(self::CACHE_KEY);
        cache()->delete(self::IMAGES_CACHE_KEY);
        UnusedFiles::forget();
    }

    /**
     * The section with this key, created at the end of the report when it does not exist.
     *
     * @param list<string>|null $headers Column headers; null = item / value
     */
    public function section(string $key, string $title = '', ?array $headers = null): ReportSection
    {
        return $this->sections[$key] ??= new ReportSection($key, $title !== '' ? $title : $key, $headers ?? [lang('Install.about.item'), lang('Install.about.value')]);
    }

    /**
     * @return list<ReportSection>
     */
    public function sections(): array
    {
        return array_values($this->sections);
    }

    /**
     * Rows with a warning or an error per status.
     *
     * @return array{warning: int, error: int}
     */
    public function counts(): array
    {
        $counts = ['warning' => 0, 'error' => 0];

        foreach ($this->sections as $section) {
            foreach ($section->rows() as $row) {
                if ($row->isProblem()) {
                    $counts[(string) $row->status]++;
                }
            }
        }

        return $counts;
    }

    /**
     * The plain-text version (`php spark about --plain`, "Copy report for support", the download):
     * exactly what the screen shows, nothing more.
     */
    public function plain(): string
    {
        $lines = [lang('System.info.title') . ' — ' . gmdate('Y-m-d H:i:s') . ' UTC'];

        foreach ($this->sections as $section) {
            $lines[] = '';
            $lines[] = '## ' . $section->title;

            foreach ($section->rows() as $row) {
                $line = '- ' . $row->label() . ': ' . $row->value();

                if ($row->isProblem()) {
                    $line .= ' [' . self::statusLabel((string) $row->status) . ($row->note !== '' ? ': ' . $row->note : '') . ']';
                }

                $lines[] = $line;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    public static function statusLabel(string $status): string
    {
        return lang('System.status.' . $status);
    }

    // ---- Sections of the Core -------------------------------------------------------------

    private function application(): void
    {
        $state = service('installstate');
        $lock  = $state->lockData();
        $set   = static fn (bool $value): string => lang($value ? 'Install.about.set' : 'Install.about.notSet');

        $section = $this->section('application', lang('Install.about.application'));
        helper('ui');
        $section->add(lang('Install.about.name'), site_name())
            ->add(lang('Install.about.core'), Version::CORE)
            ->add('CodeIgniter', CodeIgniter::CI_VERSION)
            ->add('PHP', PHP_VERSION . ' (' . PHP_SAPI . ')')
            ->add(lang('Install.about.environment'), $this->env->environment(), $this->env->environment() === 'production' ? null : ReportRow::WARNING, lang('System.notes.environment'))
            ->add(lang('Install.about.baseURL'), (string) config('App')->baseURL)
            ->add(lang('System.rows.backendPath'), backend_path())
            ->add(lang('Install.about.installed'), is_array($lock) ? (string) ($lock['installed_at'] ?? '?') : lang('Install.about.notInstalled'))
            ->add('.env', $set($state->hasEnv()))
            ->add('encryption.key', $set((string) config('Encryption')->key !== ''), (string) config('Encryption')->key !== '' ? ReportRow::OK : ReportRow::WARNING)
            ->add('app.maskKey', $set(trim((string) config('App')->maskKey) !== ''), trim((string) config('App')->maskKey) !== '' ? ReportRow::OK : ReportRow::ERROR)
            ->add(lang('Install.about.timezone'), $this->setting('Core.timezone', config('App')->appTimezone))
            ->add(lang('Install.about.locale'), Locales::default() . ' (' . implode(', ', Locales::available()) . ')')
            ->add(lang('Install.about.cache'), (string) config('Cache')->handler);

        $writable = is_writable(WRITEPATH);
        $section->add(lang('Install.about.writable'), lang($writable ? 'Core.list.yes' : 'Core.list.no'), $writable ? ReportRow::OK : ReportRow::ERROR);
    }

    private function php(): void
    {
        $section      = $this->section('php', lang('System.sections.php'));
        $requirements = new PhpRequirements();

        foreach ($requirements->required() as $extension) {
            $present = $this->env->extensionLoaded($extension);
            $section->add(lang('System.rows.extension', [$extension]), lang($present ? 'System.values.present' : 'System.values.missing'), $present ? ReportRow::OK : ReportRow::ERROR, $present ? '' : lang('System.notes.requiredExtension'));
        }

        foreach ($requirements->recommended() as $extension) {
            $present = $this->env->extensionLoaded($extension);
            $section->add(lang('System.rows.extension', [$extension]), lang($present ? 'System.values.present' : 'System.values.missing'), $present ? ReportRow::OK : ReportRow::WARNING, $present ? '' : lang('System.notes.recommendedExtension'));
        }

        foreach (['memory_limit', 'upload_max_filesize', 'post_max_size', 'max_execution_time'] as $name) {
            $section->add($name, $this->env->ini($name) !== '' ? $this->env->ini($name) : '-');
        }

        $opcache = $this->env->opcache();

        if ($opcache === null || ! $opcache['enabled']) {
            $section->add('OPcache', lang('System.values.disabled'), $this->env->environment() === 'production' ? ReportRow::WARNING : null, lang('System.notes.opcache'));
        } else {
            $section->add('OPcache', lang('System.values.opcache', [self::bytes($opcache['used']), self::bytes($opcache['used'] + $opcache['free'])]), ReportRow::OK);
        }

        $display = filter_var($this->env->ini('display_errors'), FILTER_VALIDATE_BOOLEAN) || strtolower($this->env->ini('display_errors')) === 'stderr';
        $section->add('display_errors', lang($display ? 'System.values.on' : 'System.values.off'), $display && $this->env->environment() === 'production' ? ReportRow::WARNING : null, lang('System.notes.displayErrors'));
    }

    private function storage(): void
    {
        $section = $this->section('storage', lang('System.sections.storage'));
        $free    = $this->env->diskFreeSpace(WRITEPATH);
        $total   = $this->env->diskTotalSpace(WRITEPATH);

        if ($free === null) {
            $section->add(lang('System.rows.diskFree'), lang('System.values.unknown'));
        } else {
            $low = $free < self::LOW_DISK_BYTES;
            $section->add(
                lang('System.rows.diskFree'),
                $total !== null && $total > 0 ? lang('System.values.diskFree', [self::bytes($free), self::bytes($total), (string) round($free / $total * 100)]) : self::bytes($free),
                $low ? ReportRow::WARNING : ReportRow::OK,
                $low ? lang('System.notes.lowDisk', [self::bytes(self::LOW_DISK_BYTES)]) : '',
            );
        }

        foreach ($this->expensive()['sizes'] as $folder => $size) {
            if ($size === null) {
                $section->add('writable/' . $folder, '-');

                continue;
            }

            $partial = $size['complete'] ? '' : ' (≥, ' . lang('System.values.partial', [number_format(self::MAX_SCAN_FILES)]) . ')';
            $section->add('writable/' . $folder, self::bytes($size['bytes']) . $partial . ' · ' . lang('System.values.files', [number_format($size['files'])]));
        }

        $this->storedFiles($section);
        $this->unusedFiles($section);
    }

    /**
     * Unused files of the module folders (D-55-01): the count and size of the last scan (scanning
     * once when none is cached), a warning when there are some, and the folders not checked.
     */
    private function unusedFiles(ReportSection $section): void
    {
        try {
            if (! db_connect()->tableExists('module_declarations')) {
                return;
            }

            $scan = UnusedFiles::lastScan() ?? (new UnusedFiles())->cached();
        } catch (UnusedFilesException $e) {
            $section->add(lang('System.rows.unusedFiles'), lang('System.values.unknown'), ReportRow::WARNING, lang('System.notes.unusedFailed', [$e->getMessage()]));

            return;
        } catch (Throwable) {
            $section->add(lang('System.rows.unusedFiles'), lang('System.values.unknown'));

            return;
        }

        $count = (int) $scan['count'];
        $auto  = DeleteUnusedFilesTask::enabled() ? lang('System.values.unusedAutoOn', [DeleteUnusedFilesTask::days()]) : lang('System.values.unusedAutoOff');

        $section->add(
            lang('System.rows.unusedFiles'),
            $count === 0 ? lang('System.values.unusedNone') : lang('System.values.unusedFiles', [number_format($count), self::bytes((int) $scan['bytes'])]),
            $count === 0 ? ReportRow::OK : ReportRow::WARNING,
            $count === 0 ? '' : lang('System.notes.unusedFiles', [(int) $scan['olderThan'], $auto]),
        );

        if ($scan['notChecked'] !== []) {
            $folders = array_map(static fn (array $f): string => $f['disk'] . ':' . $f['folder'] . ' (' . lang('System.values.files', [number_format((int) $f['files'])]) . ')', $scan['notChecked']);
            $section->add(lang('System.rows.unusedNotChecked'), implode(', ', $folders));
        }
    }

    /**
     * Stored files per disk and module folder, the variants, the images waiting for the optimiser
     * and the files it could not process (D-54-01).
     */
    private function storedFiles(ReportSection $section): void
    {
        try {
            $data = cache()->get(self::IMAGES_CACHE_KEY);

            if (! is_array($data) || ! isset($data['usage'], $data['pending'], $data['failures'])) {
                $db = db_connect();

                if (! $db->tableExists('stored_file_variants')) {
                    return;
                }

                $optimizer = new ImageOptimizer();
                $data      = ['usage' => $optimizer->usage(), 'pending' => $optimizer->pending(), 'failures' => $optimizer->failures(5), 'max' => $optimizer->maxDimension()];
                cache()->save(self::IMAGES_CACHE_KEY, $data, self::IMAGES_CACHE_TTL);
            }
        } catch (Throwable) {
            $section->add(lang('System.rows.imagesPending'), lang('System.values.unknown'));

            return;
        }

        foreach ($data['usage']['disks'] as $disk => $usage) {
            $section->add(lang('System.rows.disk', [$disk]), self::bytes($usage['bytes']) . ' · ' . lang('System.values.files', [number_format($usage['files'])]));
        }

        foreach ($data['usage']['modules'] as $folder => $usage) {
            $section->add($folder, self::bytes($usage['bytes']) . ' · ' . lang('System.values.files', [number_format($usage['files'])]));
        }

        $variants = $data['usage']['variants'];
        $section->add(lang('System.rows.variants'), self::bytes($variants['bytes']) . ' · ' . lang('System.values.files', [number_format($variants['files'])]));

        $oversized = (int) $data['pending']['oversized'];
        $without   = (int) $data['pending']['withoutVariants'];
        $value     = lang('System.values.imagesPending', [number_format($oversized), (string) $data['max'], number_format($without)]);

        if ($oversized + $without === 0) {
            $section->add(lang('System.rows.imagesPending'), $value, ReportRow::OK);
        } else {
            $task = $this->optimizeTask();

            if ($task['healthy']) {
                $section->add(lang('System.rows.imagesPending'), $value, ReportRow::WARNING, lang('System.notes.imagesTask', [$task['next'] ?? '-']));
            } else {
                $section->add(lang('System.rows.imagesPending'), $value, ReportRow::WARNING, lang('System.notes.imagesStale', [(string) self::OPTIMIZE_STALE_DAYS]));
            }
        }

        $failed = (int) $data['pending']['failed'];
        $names  = array_map(static fn (array $row): string => $row['disk'] . ':' . $row['path'], $data['failures']);
        $section->add(
            lang('System.rows.imagesFailed'),
            (string) $failed,
            $failed > 0 ? ReportRow::WARNING : ReportRow::OK,
            $failed > 0 ? lang('System.notes.imagesFailed', [implode(', ', $names)]) : '',
        );
    }

    /**
     * Whether OptimizeImagesTask is declared, active and ran (or was declared) within
     * OPTIMIZE_STALE_DAYS, and its next run (UTC).
     *
     * @return array{healthy: bool, next: string|null}
     */
    private function optimizeTask(): array
    {
        $row = db_connect()->table('scheduled_tasks')->where('class', OptimizeImagesTask::class)->get()->getRowArray();

        if (! is_array($row)) {
            // Declared by the Core but not yet seen by schedule:run (just installed or updated):
            // its first run is the next one of its schedule (the jobs rows warn if cron is not running).
            $next = (new OptimizeImagesTask())->schedule()->nextRun(new \DateTimeImmutable('@' . $this->env->now()));

            return ['healthy' => true, 'next' => $next->format('Y-m-d H:i T')];
        }

        if ((int) $row['active'] !== 1) {
            return ['healthy' => false, 'next' => null];
        }

        $since = is_string($row['last_run_at'] ?? null) ? $row['last_run_at'] : (is_string($row['created_at'] ?? null) ? $row['created_at'] : null);
        $limit = gmdate('Y-m-d H:i:s', $this->env->now() - self::OPTIMIZE_STALE_DAYS * 86400);
        $next  = is_string($row['next_run_at'] ?? null) ? $row['next_run_at'] . ' UTC' : null;

        return ['healthy' => $since !== null && $since >= $limit, 'next' => $next];
    }

    private function database(): void
    {
        $section = $this->section('database', lang('Install.about.database'));

        try {
            $db = db_connect();
            $db->initialize();
            $section->add(lang('Install.about.driver'), $db->getPlatform())
                ->add(lang('Install.about.version'), $db->getVersion())
                ->add(lang('Install.about.dbName'), $this->databaseName($db->getDatabase()))
                ->add(lang('Install.about.tables'), (string) count($db->listTables()));
        } catch (Throwable $e) {
            $section->add(lang('Install.about.driver'), lang('Install.about.dbError', [$e->getMessage()]), ReportRow::ERROR);

            return;
        }

        $pending = $this->pendingMigrations();

        if ($pending === null) {
            return;
        }

        if ($pending === []) {
            $section->add(lang('System.rows.migrations'), lang('System.values.upToDate'), ReportRow::OK);

            return;
        }

        foreach ($pending as $module => $count) {
            $section->add(lang('System.rows.pendingMigrations', [$module]), (string) $count, ReportRow::WARNING, lang('System.notes.pendingMigrations'));
        }
    }

    private function jobs(): void
    {
        $section = $this->section('jobs', lang('System.sections.jobs'));

        try {
            $db = db_connect();

            if (! $db->tableExists('scheduled_tasks')) {
                $section->add(lang('System.rows.lastCron'), lang('Install.about.notInstalled'));

                return;
            }

            $last = $db->tableExists('task_runs')
                ? $db->table('task_runs')->selectMax('started_at', 'last')->whereIn('source', ['cron', 'web'])->get()->getRowArray()['last'] ?? null
                : null;
            $now     = $this->env->now();
            $limit   = gmdate('Y-m-d H:i:s', $now - self::CRON_GRACE_MINUTES * 60);
            $active  = $db->table('scheduled_tasks')->where('active', 1)->countAllResults();
            $overdue = $db->table('scheduled_tasks')->where('active', 1)->where('next_run_at <', $limit)->countAllResults();
            $stale   = $active > 0 && ($overdue > 0 || ($last === null && $db->table('scheduled_tasks')->where('active', 1)->where('next_run_at IS NOT NULL')->where('next_run_at <', gmdate('Y-m-d H:i:s', $now))->countAllResults() > 0));

            $section->add(
                lang('System.rows.lastCron'),
                is_string($last) ? $last . ' UTC' : lang('System.values.never'),
                $active === 0 ? null : ($stale ? ReportRow::WARNING : ReportRow::OK),
                $stale ? lang('System.notes.cron', [(string) self::CRON_GRACE_MINUTES]) : '',
            );

            $failedTasks = $db->table('scheduled_tasks')->where('active', 1)->where('last_status', 'failed')->countAllResults();
            $section->add(lang('System.rows.failedTasks'), (string) $failedTasks, $failedTasks > 0 ? ReportRow::WARNING : ReportRow::OK);

            foreach (['mail_queue' => 'mail', 'push_queue' => 'push'] as $table => $key) {
                if (! $db->tableExists($table)) {
                    continue;
                }

                $pending = $db->table($table)->where('status', 'pending')->countAllResults();
                $failed  = $db->table($table)->where('status', 'failed')->countAllResults();
                $section->add(lang('System.rows.' . $key . 'Pending'), (string) $pending);
                $section->add(lang('System.rows.' . $key . 'Failed'), (string) $failed, $failed > 0 ? ReportRow::WARNING : null);
            }
        } catch (Throwable) {
            $section->add(lang('System.rows.lastCron'), lang('System.values.unknown'));
        }
    }

    private function assets(): void
    {
        $problems = $this->expensive()['assets'];
        $section  = $this->section('assets', lang('System.sections.assets'));

        if ($problems === null) {
            $section->add(lang('System.rows.assets'), lang('System.values.unknown'));

            return;
        }

        $section->add(
            lang('System.rows.assets'),
            $problems === 0 ? lang('System.values.upToDate') : lang('System.values.outdated', [(string) $problems]),
            $problems === 0 ? ReportRow::OK : ReportRow::WARNING,
            $problems === 0 ? '' : lang('System.notes.assets'),
        );
    }

    private function modules(): void
    {
        $section       = $this->section('modules', lang('Install.about.modules'), [lang('Core.list.module'), lang('Core.list.version'), lang('Core.list.installed'), lang('Core.list.enabled'), lang('System.rows.developmentOnly')]);
        $section->link = ['url' => backend_url('modules'), 'label' => lang('System.info.modulesLink')];

        try {
            $registry = db_connect()->tableExists('modules') ? model(ModuleModel::class, false)->allByName() : [];
        } catch (Throwable) {
            $registry = [];
        }

        foreach ((new ModuleDiscovery())->discover() as $name => $module) {
            $installed = $registry[$name] ?? null;
            $section->addCells([
                $name,
                $module->manifest->version ?? '?',
                is_array($installed) ? $installed['version'] : '-',
                $installed === null ? '-' : lang(! empty($installed['enabled']) ? 'Core.list.yes' : 'Core.list.no'),
                lang(($module->manifest->developmentOnly ?? false) ? 'Core.list.yes' : 'Core.list.no'),
            ]);
        }
    }

    // ---- Helpers ---------------------------------------------------------------------------

    /**
     * Pending migrations per installed module, or null when they cannot be read.
     *
     * @return array<string, int>|null
     */
    private function pendingMigrations(): ?array
    {
        try {
            if (! db_connect()->tableExists('modules') || ! db_connect()->tableExists('migrations')) {
                return null;
            }

            $registry = model(ModuleModel::class, false)->allByName();
            $migrator = new ModuleMigrator();
            $pending  = [];

            foreach ((new ModuleDiscovery())->discover() as $name => $module) {
                if (! isset($registry[$name])) {
                    continue;
                }

                $count = count($migrator->pending($module->namespace));

                if ($count > 0) {
                    $pending[$name] = $count;
                }
            }

            return $pending;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Folder sizes and the assets check, cached for CACHE_TTL seconds.
     *
     * @return array{sizes: array<string, array{bytes: int, files: int, complete: bool}|null>, assets: int|null}
     */
    private function expensive(): array
    {
        $cached = cache()->get(self::CACHE_KEY);

        if (is_array($cached) && isset($cached['sizes']) && array_key_exists('assets', $cached)) {
            return $cached;
        }

        $sizes = [];

        foreach (self::FOLDERS as $folder) {
            $sizes[$folder] = is_dir(WRITEPATH . $folder) ? self::folderSize(WRITEPATH . $folder) : null;
        }

        try {
            $assets = count((new AssetPublisher())->check());
        } catch (Throwable) {
            $assets = null;
        }

        $value = ['sizes' => $sizes, 'assets' => $assets];
        cache()->save(self::CACHE_KEY, $value, self::CACHE_TTL);

        return $value;
    }

    /**
     * The size of a folder, counting at most MAX_SCAN_FILES files; symbolic links are not followed.
     *
     * @return array{bytes: int, files: int, complete: bool}
     */
    public static function folderSize(string $path, int $maxFiles = self::MAX_SCAN_FILES): array
    {
        $bytes = 0;
        $files = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if (! $file instanceof \SplFileInfo || $file->isLink() || ! $file->isFile()) {
                    continue;
                }

                if ($files >= $maxFiles) {
                    return ['bytes' => $bytes, 'files' => $files, 'complete' => false];
                }

                $bytes += (int) $file->getSize();
                $files++;
            }
        } catch (Throwable) {
            return ['bytes' => $bytes, 'files' => $files, 'complete' => false];
        }

        return ['bytes' => $bytes, 'files' => $files, 'complete' => true];
    }

    public static function bytes(float|int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit  = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) (int) $value : number_format($value, 1)) . ' ' . $units[$unit];
    }

    /**
     * The database name; a SQLite file is shown relative to the project (never a path outside it).
     */
    private function databaseName(string $name): string
    {
        if (str_starts_with($name, ROOTPATH)) {
            return substr($name, strlen(ROOTPATH));
        }

        return str_contains($name, '/') || str_contains($name, '\\') ? basename($name) : $name;
    }

    /**
     * A setting as text; the fallback before installation (no settings table).
     */
    private function setting(string $name, string $fallback = '-'): string
    {
        try {
            $value = setting($name);
        } catch (Throwable) {
            $value = null;
        }

        return is_scalar($value) && (string) $value !== '' ? (string) $value : $fallback;
    }
}
