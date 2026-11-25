<?php

declare(strict_types=1);

namespace Modules\Core\Notifications;

/**
 * A notification type: a class in a module's `Notifications/` folder declared in the manifest
 * (`$notifications`), sent with `notify()`, `notifyGroup()` or `notifyPermission()`
 * (docs/notifications.md).
 *
 * ```php
 * final class NewNotice extends NotificationType
 * {
 *     public static function title(): string { return lang('News.notifications.newNotice'); }
 *     public static function icon(): string { return 'bi bi-megaphone'; }
 *
 *     public function render(): NotificationMessage
 *     {
 *         return new NotificationMessage(
 *             lang('News.notifications.newNoticeTitle', [$this->data['title'] ?? '']),
 *             url: 'backend/news/notices/' . ($this->data['id'] ?? ''),
 *         );
 *     }
 * }
 *
 * notifyPermission('news-read.view', new NewNotice(['id' => 7, 'title' => 'Closed on Monday']));
 * ```
 *
 * The data is stored as JSON and the text is rendered when it is shown, in the reader's language:
 * keep only scalar values in it, never secrets. Emails are rendered in the recipient's language
 * when they are queued.
 */
abstract class NotificationType
{
    public const DATABASE = 'database';
    public const EMAIL    = 'email';

    /** Web Push to the devices the user turned it on in (D-44-02; only while `Core.pushEnabled`). */
    public const PUSH = 'push';

    /** Every channel, in display order. */
    public const CHANNELS = [self::DATABASE, self::EMAIL, self::PUSH];

    /** Default email view: title, body and a link (`Modules\Core\Views\emails\notification`). */
    public const EMAIL_VIEW = 'Modules\Core\Views\emails\notification';

    /**
     * @param array<string, scalar|null|array<array-key, scalar|null>> $data
     */
    public function __construct(protected array $data = [])
    {
    }

    /**
     * Rebuilds a stored notification without calling the subclass's constructor.
     *
     * @param array<string, mixed> $data
     */
    public static function fromData(array $data): static
    {
        $notification       = (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $notification->data = $data;

        return $notification;
    }

    /** Name of the type in the preferences page (a language string). */
    abstract public static function title(): string;

    /** Optional explanation in the preferences page. */
    public static function description(): string
    {
        return '';
    }

    /** Bootstrap Icons class shown in the bell and the list. */
    public static function icon(): string
    {
        return 'bi bi-bell';
    }

    /**
     * Channels used when the user has not chosen (and always, for mandatory types). The default
     * includes `push`: it only reaches devices the user turned push on in (opt-in per device).
     *
     * @return list<string>
     */
    public static function defaultChannels(): array
    {
        return self::CHANNELS;
    }

    /**
     * Mandatory types are always sent through their default channels; users cannot turn them off.
     */
    public static function mandatory(): bool
    {
        return false;
    }

    /**
     * Sample data for Devtools' e-mail and notification previews (session 53, docs/notifications.md):
     * never sent, never stored. The default fills every `$this->data['<key>']` the type's own
     * source reads with a value guessed from the key's name (`id` → 1, `*date*`/`*_on`/`*_at` →
     * today, `title`/`name`/`subject` → a sample text…); override it for realistic values.
     *
     * @return array<string, mixed>
     */
    public static function previewData(): array
    {
        $file = (new \ReflectionClass(static::class))->getFileName();
        $code = is_string($file) ? (string) file_get_contents($file) : '';
        preg_match_all('/\$this->data\[\'([A-Za-z0-9_]+)\'\]/', $code, $m);
        $data = [];

        foreach (array_unique($m[1]) as $key) {
            $data[$key] = match (true) {
                $key === 'id' || str_ends_with($key, '_id') => 1,
                str_contains($key, 'date') || str_ends_with($key, '_on') || $key === 'start' => gmdate('Y-m-d'),
                str_ends_with($key, '_at')                                                   => gmdate('Y-m-d H:i:s'),
                in_array($key, ['count', 'size', 'errors', 'total'], true)                   => 3,
                default                                                                      => lang('Core.notifications.sample', [str_replace('_', ' ', $key)]),
            };
        }

        return $data;
    }

    /** The text shown in the bell, the list and the email, in the current language. */
    abstract public function render(): NotificationMessage;

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    public function emailSubject(): string
    {
        return $this->render()->title;
    }

    /** View of the email body (HTML); it receives `message`, `url`, `subject` and `preferencesUrl`. */
    public function emailView(): string
    {
        return self::EMAIL_VIEW;
    }
}
