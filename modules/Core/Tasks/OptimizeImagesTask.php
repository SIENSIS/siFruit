<?php

declare(strict_types=1);

namespace Modules\Core\Tasks;

use Config\Storage as StorageConfig;
use Modules\Core\Commands\StorageOptimize;
use Modules\Core\Scheduler\Schedule;
use Modules\Core\Scheduler\TaskInterface;
use Modules\Core\Scheduler\TaskResult;
use Modules\Core\Storage\ImageOptimizer;

/**
 * Weekly image optimisation (D-54-01): what `php spark storage:optimize` does, in one bounded
 * batch per run (`Config\Storage::$optimizeBatch` files or `$optimizeSeconds`, whichever comes
 * first); the next run continues, so a large backlog never blocks the cron line. A file that
 * fails is marked and skipped by the next runs (listed in the result and in System information).
 * The run succeeds even with failed files; only a crash fails it (and then the Core's failing-task
 * notification and widget report it).
 */
final class OptimizeImagesTask implements TaskInterface
{
    public function __construct(private readonly ?ImageOptimizer $optimizer = null, private readonly ?StorageConfig $config = null)
    {
    }

    public function name(): string
    {
        return lang('Core.optimize.task');
    }

    public function schedule(): Schedule
    {
        // Sunday at a quiet hour.
        return Schedule::weekly(0, '03:17')->withoutOverlapping(30);
    }

    public function run(): TaskResult
    {
        $config  = $this->config ?? config(StorageConfig::class);
        $summary = ($this->optimizer ?? new ImageOptimizer())->run([
            'limit'   => max(1, $config->optimizeBatch),
            'seconds' => max(10, $config->optimizeSeconds),
        ]);
        $output = StorageOptimize::summary($summary);

        foreach ($summary['failed'] as $failed) {
            $output .= "\n" . lang('Core.optimize.failed', [$failed['path'], $failed['error']]);
        }

        if ($summary['processed'] > 0 || $summary['failed'] !== []) {
            activity_log('Core', 'images_optimized', [
                'summary' => StorageOptimize::summary($summary),
                'after'   => ['via' => 'task', 'processed' => $summary['processed'], 'resized' => $summary['resized'], 'variants' => $summary['variants'], 'saved' => $summary['bytesBefore'] - $summary['bytesAfter'], 'failed' => count($summary['failed']), 'left' => $summary['left']],
            ]);
        }

        return TaskResult::success($output);
    }
}
