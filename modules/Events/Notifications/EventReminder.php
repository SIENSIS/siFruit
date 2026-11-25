<?php

declare(strict_types=1);

namespace Modules\Events\Notifications;

use Modules\Core\Notifications\NotificationMessage;
use Modules\Core\Notifications\NotificationType;

/**
 * "Event reminder": sent by the SendReminders task to a user who asked for it in the event's
 * details, before each occurrence. Data: code (masked id), title, start (site time), date.
 */
final class EventReminder extends NotificationType
{
    public static function title(): string
    {
        return lang('Events.notifications.reminder');
    }

    public static function description(): string
    {
        return lang('Events.notifications.reminderHelp');
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
        return ['code' => 'demo', 'title' => 'Parents\' meeting', 'date' => gmdate('Y-m-d'), 'start' => gmdate('Y-m-d') . ' 17:30:00'];
    }

    public function render(): NotificationMessage
    {
        $code = (string) ($this->data['code'] ?? '');
        $date = (string) ($this->data['date'] ?? '');

        return new NotificationMessage(
            lang('Events.notifications.reminderTitle', [(string) ($this->data['title'] ?? '')]),
            lang('Events.notifications.reminderBody', [(string) ($this->data['start'] ?? '')]),
            url: $code !== '' ? backend_link('calendar/event/' . rawurlencode($code) . ($date !== '' ? '?date=' . rawurlencode($date) : '')) : backend_link('calendar'),
        );
    }
}
