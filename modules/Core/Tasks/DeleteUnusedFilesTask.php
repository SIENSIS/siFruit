<?php

declare(strict_types=1);

namespace Modules\Core\Tasks;

use Config\Storage as StorageConfig;
use Modules\Core\Scheduler\Schedule;
use Modules\Core\Scheduler\TaskInterface;
use Modules\Core\Scheduler\TaskResult;
use Modules\Core\Storage\UnusedFiles;
use Modules\Core\Storage\UnusedFilesException;
use Modules\Core\System\SystemReport;
use Throwable;

/**
 * Weekly automatic deletion of unused files (D-55-01), **off by default**: it does nothing until
 * an administrator turns on `Core.unusedFilesAutoDelete` (Settings › Security › Uploads). Then it
 * deletes the unused files older than `Core.unusedFilesAutoDeleteDays` (default 90, never below
 * the grace period) with the same class and safety rules as `storage:unused` and the Unused files
 * screen, in one bounded batch per run (`Config\Storage::$unusedDeleteBatch` files or
 * `$unusedDeleteSeconds`); the next run continues. A failing provider fails the run (nothing is
 * deleted; the failing-task notification reports it). One activity entry per run that deletes.
 */
final class DeleteUnusedFilesTask implements TaskInterface
{
    public function __construct(private readonly ?UnusedFiles $unused = null, private readonly ?StorageConfig $config = null)
    {
    }

    public function name(): string
    {
        return lang('Core.unused.task');
    }

    public function schedule(): Schedule
    {
        // Sunday at a quiet hour, after the image optimisation (03:17).
        return Schedule::weekly(0, '04:23')->withoutOverlapping(30);
    }

    public static function enabled(): bool
    {
        try {
            return (bool) setting('Core.unusedFilesAutoDelete');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The age in days of the files the task deletes: the setting, never below the grace period.
     */
    public static function days(): int
    {
        try {
            $days = setting('Core.unusedFilesAutoDeleteDays');
        } catch (Throwable) {
            $days = null;
        }

        return max(UnusedFiles::graceDays(), is_numeric($days) ? (int) $days : 90);
    }

    public function run(): TaskResult
    {
        if (! self::enabled()) {
            return TaskResult::success(lang('Core.unused.taskDisabled'));
        }

        $config = $this->config ?? config(StorageConfig::class);
        $unused = $this->unused ?? new UnusedFiles();
        $days   = self::days();

        try {
            $scan   = $unused->scan(['olderThan' => $days, 'limit' => max(1, min(UnusedFiles::MAX_DELETE, $config->unusedDeleteBatch))]);
            $ids    = array_map(static fn (array $file): int => (int) $file['id'], $scan['files']);
            $result = $unused->delete($ids, ['olderThan' => $days, 'via' => 'task', 'seconds' => max(10, $config->unusedDeleteSeconds)]);
        } catch (UnusedFilesException $e) {
            return TaskResult::failure(lang('Core.unused.scanFailed', [$e->getMessage()]));
        }

        $left = max(0, $scan['count'] - $result['deleted'] - UnusedFiles::skippedCount($result));

        return TaskResult::success(lang('Core.unused.taskResult', [
            $result['deleted'],
            SystemReport::bytes($result['bytes']),
            UnusedFiles::skippedCount($result),
            $days,
            $left,
        ]));
    }
}
