<?php

declare(strict_types=1);

namespace Modules\Tutorial\Notifications;

use Kpcrudy\Crud;
use Modules\Core\Notifications\NotificationMessage;
use Modules\Core\Notifications\NotificationType;

/**
 * "New to-do task request" (tutorial 12): sent to the users who may review requests
 * (`tutorial-requests.list`) when a visitor sends the public form. Data: id, title, name (the
 * visitor's name; the email address is not copied into the notification).
 */
final class TaskRequested extends NotificationType
{
    public static function title(): string
    {
        return lang('Tutorial.notifications.requested');
    }

    public static function description(): string
    {
        return lang('Tutorial.notifications.requestedHelp');
    }

    public static function icon(): string
    {
        return 'bi bi-inbox';
    }

    /**
     * Sample data for Devtools' previews (never sent or stored).
     *
     * @return array<string, mixed>
     */
    public static function previewData(): array
    {
        return ['id' => 1, 'title' => 'Fix the projector', 'name' => 'Anna Puig'];
    }

    public function render(): NotificationMessage
    {
        $id = (int) ($this->data['id'] ?? 0);

        return new NotificationMessage(
            lang('Tutorial.notifications.requestedTitle', [(string) ($this->data['title'] ?? '')]),
            lang('Tutorial.notifications.requestedBody', [(string) ($this->data['name'] ?? '')]),
            url: $id > 0 ? 'backend/tutorial/requests/show/' . Crud::maskId($id, 'tutorial_task_requests') : 'backend/tutorial/requests',
        );
    }
}
