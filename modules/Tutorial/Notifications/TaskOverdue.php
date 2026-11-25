<?php

declare(strict_types=1);

namespace Modules\Tutorial\Notifications;

use Kpcrudy\Crud;
use Modules\Core\Notifications\NotificationMessage;
use Modules\Core\Notifications\NotificationType;

/**
 * "Overdue to-do task" (tutorial 7): sent by the scheduled task `OverdueReminder` to the
 * assignees of an open to-do task past its due date, at most once a day. Data: id, title, due_on.
 */
final class TaskOverdue extends NotificationType
{
    public static function title(): string
    {
        return lang('Tutorial.notifications.overdue');
    }

    public static function description(): string
    {
        return lang('Tutorial.notifications.overdueHelp');
    }

    public static function icon(): string
    {
        return 'bi bi-alarm';
    }

    /**
     * Sample data for Devtools' previews (never sent or stored).
     *
     * @return array<string, mixed>
     */
    public static function previewData(): array
    {
        return ['id' => 1, 'title' => 'Fix the projector', 'due_on' => gmdate('Y-m-d', time() - 86400)];
    }

    public function render(): NotificationMessage
    {
        $id = (int) ($this->data['id'] ?? 0);

        return new NotificationMessage(
            lang('Tutorial.notifications.overdueTitle', [(string) ($this->data['title'] ?? '')]),
            lang('Tutorial.notifications.overdueBody', [(string) ($this->data['due_on'] ?? '')]),
            url: $id > 0 ? 'backend/tutorial/tasks/show/' . Crud::maskId($id, 'tutorial_tasks') : 'backend/tutorial',
        );
    }
}
