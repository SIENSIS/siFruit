<?php

declare(strict_types=1);

namespace Modules\Assistant\Tasks;

use Modules\Assistant\Services\UnansweredLog;
use Modules\Core\Scheduler\Schedule;
use Modules\Core\Scheduler\TaskInterface;
use Modules\Core\Scheduler\TaskResult;

/**
 * Retention of the unanswered questions (D-51-02): every day at 03:50, deletes the questions asked
 * more than `Assistant.unansweredDays` days ago (90 by default, never fewer than 7). Runs even when
 * logging was turned off, so nothing stays longer than promised.
 */
final class PurgeUnanswered implements TaskInterface
{
    public function name(): string
    {
        return lang('Assistant.scheduled.purge');
    }

    public function schedule(): Schedule
    {
        return Schedule::daily('03:50')->withoutOverlapping();
    }

    public function run(): TaskResult
    {
        $days    = max(7, (int) setting('Assistant.unansweredDays'));
        $deleted = (new UnansweredLog())->purge($days);
        $output  = lang('Assistant.scheduled.purgeOutput', [$deleted, $days]);

        if ($deleted > 0) {
            activity_log('Assistant', 'unanswered_purged', [
                'resource' => 'assistant-faqs',
                'summary'  => $output,
                'changes'  => ['deleted' => $deleted, 'days' => $days],
            ]);
        }

        return TaskResult::success($output);
    }
}
