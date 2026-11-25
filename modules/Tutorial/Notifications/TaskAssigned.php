<?php

declare(strict_types=1);

namespace Modules\Tutorial\Notifications;

use Kpcrudy\Crud;
use Modules\Core\Notifications\NotificationMessage;
use Modules\Core\Notifications\NotificationType;

/**
 * "To-do task assigned to you" (tutorial 6): sent to the users who become assignees of a to-do
 * task (form, API), never to the user who made the change. Data: id, title, due_on.
 */
final class TaskAssigned extends NotificationType
{
    public static function title(): string
    {
        return lang('Tutorial.notifications.assigned');
    }

    public static function description(): string
    {
        return lang('Tutorial.notifications.assignedHelp');
    }

    public static function icon(): string
    {
        return 'bi bi-person-check';
    }

    /**
     * Sample data for Devtools' previews (never sent or stored).
     *
     * @return array<string, mixed>
     */
    public static function previewData(): array
    {
        return ['id' => 1, 'title' => 'Fix the projector', 'due_on' => gmdate('Y-m-d', time() + 7 * 86400)];
    }

    public function render(): NotificationMessage
    {
        $id  = (int) ($this->data['id'] ?? 0);
        $due = (string) ($this->data['due_on'] ?? '');

        return new NotificationMessage(
            lang('Tutorial.notifications.assignedTitle', [(string) ($this->data['title'] ?? '')]),
            $due !== '' ? lang('Tutorial.notifications.assignedDue', [$due]) : lang('Tutorial.notifications.assignedBody'),
            url: $id > 0 ? 'backend/tutorial/tasks/show/' . Crud::maskId($id, 'tutorial_tasks') : 'backend/tutorial',
        );
    }
}
