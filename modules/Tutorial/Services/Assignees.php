<?php

declare(strict_types=1);

namespace Modules\Tutorial\Services;

use CodeIgniter\Shield\Models\UserModel;
use Modules\Core\Permissions\Authorization;
use Modules\Tutorial\Calendar\DueDates;
use Modules\Tutorial\Notifications\TaskAssigned;

/**
 * The assignees of a to-do task (tutorial 6): who was added by a change, and the notification
 * to them. The CRUD and the API call `before()` with the assignees before the write and
 * `notifyNew()` after it; the user who made the change is never notified.
 */
final class Assignees
{
    /**
     * Notifies the users added to a to-do task. Returns how many were notified.
     *
     * @param list<int> $before assignee ids before the change
     */
    public static function notifyNew(int $taskId, array $before, ?int $actor = null): int
    {
        $repository = new TaskRepository();
        $task       = $repository->find($taskId);

        if ($task === null) {
            return 0;
        }

        $added = array_values(array_diff($repository->assigneeIds($taskId), $before, $actor === null ? [] : [$actor]));

        if ($added === []) {
            return 0;
        }

        return notify($added, new TaskAssigned([
            'id'     => $taskId,
            'title'  => (string) $task['title'],
            'due_on' => (string) ($task['due_on'] ?? ''),
        ]));
    }

    /**
     * Removes a user from the open to-do tasks when they can no longer use the board (the
     * `privilegesChanged` event of the Core, tutorial 6). Returns the number of to-do tasks.
     */
    public static function dropIfNoAccess(int $userId): int
    {
        $db = db_connect();

        if (! $db->tableExists('tutorial_task_assignees')) {
            return 0;
        }

        $user = model(UserModel::class)->findById($userId);

        if ($user !== null && Authorization::for($user)->can('tutorial-board.view')) {
            return 0;
        }

        $ids = array_map(static fn (array $row): int => (int) $row['task_id'], $db->table('tutorial_task_assignees')->select('tutorial_task_assignees.task_id')
            ->join('tutorial_tasks', 'tutorial_tasks.id = tutorial_task_assignees.task_id')
            ->where('tutorial_task_assignees.user_id', $userId)
            ->where('tutorial_tasks.status !=', 'done')
            ->get()->getResultArray());

        if ($ids === []) {
            return 0;
        }

        $db->table('tutorial_task_assignees')->where('user_id', $userId)->whereIn('task_id', $ids)->delete();
        DueDates::syncMany($ids);

        activity_log('Tutorial', 'assignee_removed', [
            'resource' => 'tutorial-tasks',
            'summary'  => lang('Tutorial.activity.assigneeRemovedSummary', [$userId, count($ids)]),
            'changes'  => ['user_id' => $userId, 'tasks' => $ids],
        ]);

        return count($ids);
    }
}
