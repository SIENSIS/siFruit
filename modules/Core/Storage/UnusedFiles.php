<?php

declare(strict_types=1);

namespace Modules\Core\Storage;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;
use Modules\Core\Registry\ModuleState;
use Throwable;

/**
 * The stored files of the module spaces that nothing uses (D-55-01, docs/storage.md "Unused
 * files"), for `php spark storage:unused`, Settings › System › Unused files and the automatic
 * deletion task (`DeleteUnusedFilesTask`, off by default).
 *
 * Safety rules (each one has a test):
 *
 * - Only files inside a folder some **enabled** module's `FileReferenceProvider` answers for are
 *   candidates; every other module folder is "not checked" (reported with its count and size,
 *   never listed or deleted), and so are the folders of disabled modules. Files outside the
 *   module spaces (`users/…`, `shared/…`) are never candidates.
 * - The references of **every** provider count, those of disabled modules too (a disabled
 *   module may still point at another module's files).
 * - If any provider fails (an exception, a missing class, a folder outside the module spaces),
 *   the whole scan fails: nothing is listed or deleted.
 * - A file younger than the grace period (`Core.unusedFilesGraceDays`, at least
 *   MIN_GRACE_DAYS) is never a candidate: it may sit in a form not saved yet.
 * - Deleting collects the references again first and checks every file once more (a reference
 *   added after the scan saves it); files are deleted only through `Storage::delete()` (file,
 *   variants and row) and one activity entry is written per run.
 */
final class UnusedFiles
{
    public const MIN_GRACE_DAYS     = 7;
    public const DEFAULT_GRACE_DAYS = 30;

    /** Most files kept in a scan's list (the totals count them all). */
    public const MAX_LISTED = 2000;

    /** Most files one deletion run handles. */
    public const MAX_DELETE = 1000;

    /** Paths kept in the activity entry of a run. */
    public const LOG_PATHS = 100;

    public const BATCH = 500;

    /** The last scan (screen, System information), dropped after a deletion. */
    public const CACHE_KEY = 'core_unused_files';

    public const CACHE_TTL = 3600;

    /** Reasons a file was not deleted. */
    public const SKIP_MISSING     = 'missing';
    public const SKIP_NOT_CHECKED = 'notChecked';
    public const SKIP_TOO_NEW     = 'tooNew';
    public const SKIP_USED        = 'used';
    public const SKIP_FAILED      = 'failed';

    /**
     * @param array<string, array{module: string, enabled: bool}>|null $declared provider class => its module (null: the manifests')
     * @param BaseConnection<object|resource, object|resource>|null    $db
     */
    public function __construct(
        private readonly ?Storage $storage = null,
        private readonly ?array $declared = null,
        private readonly ?BaseConnection $db = null,
    ) {
    }

    /**
     * The grace period in days: the setting, never below MIN_GRACE_DAYS (in development and
     * testing only, `Config\Storage::$unusedFilesTestGraceDays` may lower it for the e2e tests).
     */
    public static function graceDays(): int
    {
        $config = config(\Config\Storage::class);

        if (ENVIRONMENT !== 'production' && $config->unusedFilesTestGraceDays !== null && $config->unusedFilesTestGraceDays >= 0) {
            return (int) $config->unusedFilesTestGraceDays;
        }

        try {
            $value = setting('Core.unusedFilesGraceDays');
        } catch (Throwable) {
            $value = null;
        }

        return max(self::MIN_GRACE_DAYS, is_numeric($value) ? (int) $value : self::DEFAULT_GRACE_DAYS);
    }

    /**
     * Lists the unused files.
     *
     * Options: `module` (a module name: only its folders), `olderThan` (days; never below the
     * grace period), `limit` (most files listed; the totals count them all).
     *
     * @param array{module?: string|null, olderThan?: int|null, limit?: int|null} $options
     *
     * @return array{scannedAt: string, graceDays: int, olderThan: int, module: string|null, files: list<array<string, mixed>>, count: int, bytes: int, variants: int, truncated: bool, checked: list<array{folder: string, module: string}>, notChecked: list<array{disk: string, folder: string, files: int, bytes: int, module: string|null, reason: string}>, providers: int}
     *
     * @throws UnusedFilesException when a provider fails (nothing is listed)
     */
    public function scan(array $options = []): array
    {
        $grace     = self::graceDays();
        $olderThan = max($grace, (int) ($options['olderThan'] ?? 0));
        $module    = isset($options['module']) && $options['module'] !== '' ? (string) $options['module'] : null;
        $limit     = max(1, min(self::MAX_LISTED, (int) ($options['limit'] ?? self::MAX_LISTED)));
        $plan      = $this->plan();
        $cutoff    = Time::now()->subDays($olderThan)->toDateTimeString();

        $files      = [];
        $count      = 0;
        $bytes      = 0;
        $variants   = 0;
        $notChecked = [];

        foreach ($this->moduleFiles() as $rows) {
            $candidates = [];

            foreach ($rows as $row) {
                $folder = Storage::baseFolder((string) $row['path']);

                if (! isset($plan['checked'][$folder])) {
                    $key                = $row['disk'] . '|' . $folder;
                    $notChecked[$key] ??= ['disk' => (string) $row['disk'], 'folder' => $folder, 'files' => 0, 'bytes' => 0, 'module' => $plan['disabled'][$folder] ?? null, 'reason' => isset($plan['disabled'][$folder]) ? 'disabled' : 'noProvider'];
                    $notChecked[$key]['files']++;
                    $notChecked[$key]['bytes'] += (int) $row['size'];

                    continue;
                }

                if ($module !== null && strcasecmp($plan['checked'][$folder], $module) !== 0) {
                    continue;
                }

                if (self::isTooNew($row, $cutoff) || $plan['references']->contains($row)) {
                    continue;
                }

                $candidates[(int) $row['id']] = $row + ['folder' => $folder, 'module' => $plan['checked'][$folder]];
            }

            foreach ($this->withVariants($candidates, $plan['references']) as $file) {
                $count++;
                $bytes += $file['size'] + $file['variantsBytes'];
                $variants += $file['variants'];

                if (count($files) < $limit) {
                    $files[] = $file;
                }
            }
        }

        ksort($notChecked);
        $checked = [];

        foreach ($plan['checked'] as $folder => $owner) {
            $checked[] = ['folder' => (string) $folder, 'module' => $owner];
        }

        return [
            'scannedAt'  => Time::now()->toDateTimeString(),
            'graceDays'  => $grace,
            'olderThan'  => $olderThan,
            'module'     => $module,
            'files'      => $files,
            'count'      => $count,
            'bytes'      => $bytes,
            'variants'   => $variants,
            'truncated'  => $count > count($files),
            'checked'    => $checked,
            'notChecked' => array_values($notChecked),
            'providers'  => $plan['providers'],
        ];
    }

    /**
     * The last scan of every module (cached CACHE_TTL seconds), scanning when there is none.
     *
     * @return array<string, mixed>
     *
     * @throws UnusedFilesException
     */
    public function cached(bool $refresh = false): array
    {
        $cached = $refresh ? null : cache()->get(self::CACHE_KEY);

        if (is_array($cached) && isset($cached['scannedAt'], $cached['files'])) {
            return $cached;
        }

        $report = $this->scan();
        cache()->save(self::CACHE_KEY, $report, self::CACHE_TTL);

        return $report;
    }

    /**
     * The cached scan, if any (System information never scans by itself… unless nothing is
     * cached; then it scans once, which is bounded).
     *
     * @return array<string, mixed>|null
     */
    public static function lastScan(): ?array
    {
        $cached = cache()->get(self::CACHE_KEY);

        return is_array($cached) && isset($cached['scannedAt']) ? $cached : null;
    }

    public static function forget(): void
    {
        cache()->delete(self::CACHE_KEY);
    }

    /**
     * Deletes files that are still unused: the references are collected again and every id is
     * checked once more just before its file is deleted. One activity entry per run.
     *
     * Options: `olderThan` (days, never below the grace period), `module` (only its folders),
     * `via` (`screen`, `command`, `task`; logged), `seconds` (stop after this time; the rest is
     * reported as left).
     *
     * @param list<int>                                                                          $ids
     * @param array{olderThan?: int|null, module?: string|null, via?: string, seconds?: int|null} $options
     *
     * @return array{deleted: int, bytes: int, skipped: array<string, int>, paths: list<string>, left: int}
     *
     * @throws UnusedFilesException when a provider fails (nothing is deleted)
     */
    public function delete(array $ids, array $options = []): array
    {
        $ids       = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        $ids       = array_slice($ids, 0, self::MAX_DELETE);
        $olderThan = max(self::graceDays(), (int) ($options['olderThan'] ?? 0));
        $module    = isset($options['module']) && $options['module'] !== '' ? (string) $options['module'] : null;
        $deadline  = isset($options['seconds']) ? microtime(true) + max(1, (int) $options['seconds']) : null;
        $result    = ['deleted' => 0, 'bytes' => 0, 'skipped' => [], 'paths' => [], 'left' => 0];

        if ($ids === []) {
            return $result;
        }

        $plan   = $this->plan(); // fresh references: a file used since the scan is kept
        $cutoff = Time::now()->subDays($olderThan)->toDateTimeString();
        $skip   = static function (string $reason) use (&$result): void {
            $result['skipped'][$reason] = ($result['skipped'][$reason] ?? 0) + 1;
        };

        foreach ($ids as $i => $id) {
            if ($deadline !== null && microtime(true) > $deadline) {
                $result['left'] = count($ids) - $i;

                break;
            }

            $row = $this->db()->table('stored_files')->select('id, file_key, disk, path, original_name, mime, size, created_at')->where('id', $id)->get()->getRowArray();

            if (! is_array($row)) {
                $skip(self::SKIP_MISSING);

                continue;
            }

            $folder = Storage::baseFolder((string) $row['path']);

            if (! Storage::isModuleFolder($folder) || ! isset($plan['checked'][$folder]) || ($module !== null && strcasecmp($plan['checked'][$folder], $module) !== 0)) {
                $skip(self::SKIP_NOT_CHECKED);

                continue;
            }

            if (self::isTooNew($row, $cutoff)) {
                $skip(self::SKIP_TOO_NEW);

                continue;
            }

            $file = $this->withVariants([(int) $row['id'] => $row + ['folder' => $folder, 'module' => $plan['checked'][$folder]]], $plan['references']);

            if ($file === [] || $plan['references']->contains($row)) {
                $skip(self::SKIP_USED);

                continue;
            }

            $stored = $this->storage()->findById($id);

            if ($stored === null) {
                $skip(self::SKIP_MISSING);

                continue;
            }

            try {
                $this->storage()->delete($stored);
            } catch (Throwable) {
                $skip(self::SKIP_FAILED);

                continue;
            }

            $result['deleted']++;
            $result['bytes'] += $file[0]['size'] + $file[0]['variantsBytes'];
            $result['paths'][] = $row['disk'] . ':' . $row['path'];
        }

        self::forget();
        $this->log($result, (string) ($options['via'] ?? 'screen'), $olderThan);

        return $result;
    }

    /**
     * The total of a deletion's skipped files.
     *
     * @param array{skipped: array<string, int>} $result
     */
    public static function skippedCount(array $result): int
    {
        return array_sum($result['skipped']);
    }

    /**
     * The providers, their folders and their references.
     *
     * @return array{checked: array<string, string>, disabled: array<string, string>, references: FileReferenceSet, providers: int}
     *
     * @throws UnusedFilesException
     */
    private function plan(): array
    {
        $references = new FileReferenceSet();
        $checked    = [];
        $disabled   = [];
        $count      = 0;

        foreach ($this->declared() as $class => $declaration) {
            try {
                if (! class_exists($class) || ! is_subclass_of($class, FileReferenceProvider::class)) {
                    throw new UnusedFilesException(lang('Core.unused.providerMissing', [$class, $declaration['module']]));
                }

                /** @var FileReferenceProvider $provider */
                $provider = new $class();

                foreach ($provider->folders() as $folder) {
                    if (! self::isValidFolder($folder)) {
                        throw new UnusedFilesException(lang('Core.unused.badFolder', [self::describe($folder), $class]));
                    }

                    if ($declaration['enabled']) {
                        $checked[$folder] ??= $declaration['module'];
                    } else {
                        $disabled[$folder] = $declaration['module'];
                    }
                }

                foreach ($provider->references() as $reference) {
                    $references->add($reference);
                }
            } catch (UnusedFilesException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new UnusedFilesException(lang('Core.unused.providerFailed', [$class, $e->getMessage()]), 0, $e);
            }

            $count++;
        }

        // A folder a disabled module answers for keeps its files, even if another module answers for it too.
        $checked = array_diff_key($checked, $disabled);

        return ['checked' => $checked, 'disabled' => $disabled, 'references' => $references, 'providers' => $count];
    }

    /**
     * A module folder a provider may answer for: `modules/<module>[/<sub>…]`, no date subfolder.
     */
    private static function isValidFolder(mixed $folder): bool
    {
        return is_string($folder)
            && preg_match('#^modules/[a-z0-9_-]+(?:/[a-z0-9_-]+)*$#', $folder) === 1
            && preg_match('#/[0-9][0-9-]*(?:/|$)#', $folder) !== 1;
    }

    private static function describe(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }

    /**
     * The declared providers: class => module and whether the module is enabled.
     *
     * @return array<string, array{module: string, enabled: bool}>
     */
    private function declared(): array
    {
        if ($this->declared !== null) {
            return $this->declared;
        }

        $disabled = (new ModuleState())->disabled();
        $rows     = $this->db()->table('module_declarations')->select('module, key')->where('kind', 'fileReference')->orderBy('module')->orderBy('id')->get()->getResultArray();
        $declared = [];

        foreach ($rows as $row) {
            $declared[ltrim((string) $row['key'], '\\')] = ['module' => (string) $row['module'], 'enabled' => ! in_array($row['module'], $disabled, true)];
        }

        return $declared;
    }

    /**
     * The stored files of the module spaces, in batches.
     *
     * @return \Generator<int, list<array<string, mixed>>>
     */
    private function moduleFiles(): \Generator
    {
        $last = 0;

        while (true) {
            $rows = $this->db()->table('stored_files')->select('id, file_key, disk, path, original_name, mime, size, created_at')
                ->like('path', 'modules/', 'after')->where('id >', $last)->orderBy('id')->limit(self::BATCH)->get()->getResultArray();

            if ($rows === []) {
                return;
            }

            yield $rows;

            if (count($rows) < self::BATCH) {
                return;
            }

            $last = (int) $rows[count($rows) - 1]['id'];
        }
    }

    /**
     * The candidates with their variants (count, size); a candidate whose variant is referenced
     * by its path is dropped.
     *
     * @param array<int, array<string, mixed>> $candidates
     *
     * @return list<array<string, mixed>>
     */
    private function withVariants(array $candidates, FileReferenceSet $references): array
    {
        if ($candidates === []) {
            return [];
        }

        $variants = [];

        if ($this->db()->tableExists('stored_file_variants')) {
            foreach (array_chunk(array_keys($candidates), self::BATCH) as $chunk) {
                foreach ($this->db()->table('stored_file_variants')->select('stored_file_id, path, size')->whereIn('stored_file_id', $chunk)->get()->getResultArray() as $variant) {
                    $variants[(int) $variant['stored_file_id']][] = $variant;
                }
            }
        }

        $files = [];

        foreach ($candidates as $id => $row) {
            $own   = $variants[$id] ?? [];
            $paths = array_map(static fn (array $variant): string => (string) $variant['path'], $own);

            if ($references->containsPath((string) $row['disk'], $paths)) {
                continue;
            }

            $files[] = [
                'id'            => $id,
                'key'           => (string) $row['file_key'],
                'disk'          => (string) $row['disk'],
                'path'          => (string) $row['path'],
                'folder'        => (string) $row['folder'],
                'module'        => (string) $row['module'],
                'name'          => (string) $row['original_name'],
                'mime'          => (string) $row['mime'],
                'size'          => (int) $row['size'],
                'variants'      => count($own),
                'variantsBytes' => array_sum(array_map(static fn (array $variant): int => (int) $variant['size'], $own)),
                'createdAt'     => (string) $row['created_at'],
                'image'         => str_starts_with((string) $row['mime'], 'image/'),
                'public'        => $this->isPublic((string) $row['disk']),
            ];
        }

        return $files;
    }

    /**
     * Whether a file is younger than the cutoff (a file without a date counts as new).
     *
     * @param array<string, mixed> $row
     */
    private static function isTooNew(array $row, string $cutoff): bool
    {
        $created = is_string($row['created_at'] ?? null) ? $row['created_at'] : '';

        return $created === '' || $created > $cutoff;
    }

    private function isPublic(string $disk): bool
    {
        try {
            return $this->storage()->disk($disk)->isPublic();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array{deleted: int, bytes: int, skipped: array<string, int>, paths: list<string>, left: int} $result
     */
    private function log(array $result, string $via, int $olderThan): void
    {
        if ($result['deleted'] === 0 && $result['skipped'] === []) {
            return;
        }

        helper('activity');
        activity_log('Core', 'unused_files_deleted', [
            'resource' => 'system',
            'summary'  => lang('Core.unused.summary', [$result['deleted'], \Modules\Core\System\SystemReport::bytes($result['bytes']), self::skippedCount($result)]),
            'after'    => [
                'via'       => $via,
                'deleted'   => $result['deleted'],
                'bytes'     => $result['bytes'],
                'skipped'   => $result['skipped'],
                'olderThan' => $olderThan,
                'paths'     => array_slice($result['paths'], 0, self::LOG_PATHS),
                'more'      => max(0, count($result['paths']) - self::LOG_PATHS),
            ],
        ]);
    }

    private function storage(): Storage
    {
        /** @var Storage */
        return $this->storage ?? service('storage');
    }

    /**
     * @return BaseConnection<object|resource, object|resource>
     */
    private function db(): BaseConnection
    {
        return $this->db ?? db_connect();
    }
}
