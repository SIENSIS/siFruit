<?php

declare(strict_types=1);

namespace Modules\Devtools\Mail;

use CodeIgniter\Shield\Entities\User;
use Modules\Core\I18n\Locales;
use Modules\Core\Modules\ModuleDiscovery;
use Modules\Core\Notifications\Channels\PushChannel;
use Modules\Core\Notifications\NotificationType;
use Modules\Core\Notifications\RecipientLocale;
use Modules\Core\Registry\ModuleState;

/**
 * Devtools' e-mail and notification previews (`backend/devtools/mail`, session 53): every
 * notification type of the enabled modules and every e-mail sent through Auth's SecurityMailer, rendered with sample data in a language for each
 * channel: the e-mail (HTML and plain text), the database notification and the push payload.
 * Nothing is sent and nothing is stored: the channels are not called, only what they render.
 */
final class MailPreviews
{
    /** Sample values of the security e-mail views (never a real token or code). */
    private const SECURITY_SAMPLE = [
        'code'      => '123456',
        'minutes'   => 15,
        'ipAddress' => '192.0.2.10',
        'device'    => 'Firefox on Linux',
    ];

    /**
     * Every preview: id => [kind, title, source].
     *
     * @return array<string, array{kind: string, title: string, source: string}>
     */
    public function items(): array
    {
        $items = [];
        $state = new ModuleState();

        foreach ((new ModuleDiscovery())->discover() as $name => $module) {
            if (! $state->isEnabled($name) || $module->manifest === null) {
                continue;
            }

            foreach ($module->manifest->notifications as $class) {
                if (is_subclass_of($class, NotificationType::class)) {
                    $items[PushChannel::tagOf($class)] = ['kind' => 'notification', 'title' => $class::title(), 'source' => $class];
                }
            }

            foreach (self::securityViews($module->path) as $view) {
                $base = substr($view, strrpos($view, '\\') + 1);
                $items['mail-' . strtolower($name) . '-' . str_replace('_', '-', $base)] = [
                    'kind'   => 'security',
                    'title'  => $name . ': ' . str_replace('_', ' ', $base),
                    'source' => $view,
                ];
            }
        }

        ksort($items);

        return $items;
    }

    /**
     * One preview in one language.
     *
     * @return array{subject: string, html: string, text: string, database: array<string, mixed>|null, push: array<string, string>|null}|null
     */
    public function render(string $id, string $locale): ?array
    {
        $item = $this->items()[$id] ?? null;

        if ($item === null || ! Locales::isAvailable($locale)) {
            return null;
        }

        $user = new User(['id' => 0, 'username' => 'anna', 'email' => 'anna@example.com']);

        return RecipientLocale::using($locale, function () use ($item, $user): array {
            if ($item['kind'] === 'notification') {
                /** @var class-string<NotificationType> $class */
                $class        = $item['source'];
                $notification = $class::fromData($class::previewData());
                $message      = $notification->render();
                $subject      = $notification->emailSubject();
                $path         = $message->path();
                $html         = view($notification->emailView(), [
                    'subject'        => $subject,
                    'message'        => $message,
                    'user'           => $user,
                    'url'            => $path !== null ? site_url($path) : null,
                    'preferencesUrl' => backend_url('profile/notifications'),
                ], ['debug' => false]);

                return [
                    'subject'  => $subject,
                    'html'     => $html,
                    'text'     => self::plain($html),
                    'database' => ['type' => $class, 'data' => $notification->data(), 'title' => $message->title, 'body' => $message->body, 'url' => $message->url],
                    'push'     => PushChannel::payload($message, PushChannel::tagOf($class)),
                ];
            }

            $subject = $item['title'];
            $html    = view($item['source'], self::SECURITY_SAMPLE + [
                'subject' => $subject,
                'user'    => $user,
                'date'    => gmdate('Y-m-d H:i:s'),
                'url'     => site_url('auth/preview?token=sample'),
                'denyUrl' => site_url('auth/preview/deny'),
            ], ['debug' => false]);

            return ['subject' => $subject, 'html' => $html, 'text' => self::plain($html), 'database' => null, 'push' => null];
        });
    }

    /**
     * The e-mail views a module sends through Auth's SecurityMailer: the view names written in
     * its PHP files that call `SecurityMailer` (or Auth's `sendLink()`, which does).
     *
     * @return list<string>
     */
    public static function securityViews(string $path): array
    {
        $views = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $name = $file instanceof \SplFileInfo ? $file->getPathname() : '';

            if (! str_ends_with($name, '.php') || str_contains($name, '/Tests/') || str_contains($name, '/Views/')) {
                continue;
            }

            $code = (string) file_get_contents($name);

            if (! str_contains($code, 'SecurityMailer())->send(') && ! str_contains($code, '->sendLink(') && ! str_contains($code, 'mailer ?? new SecurityMailer')) {
                continue;
            }

            preg_match_all("/'(Modules\\\\[A-Za-z0-9]+\\\\Views\\\\emails\\\\[a-z0-9_]+)'/", $code, $m);

            foreach ($m[1] as $view) {
                $views[] = $view;
            }
        }

        $views = array_values(array_unique($views));
        sort($views);

        return $views;
    }

    /**
     * The plain-text version of an e-mail body (what a text-only client shows).
     */
    public static function plain(string $html): string
    {
        $html = (string) preg_replace('#<(head|style|script)\b.*?</\1>#is', '', $html);
        $html = (string) preg_replace('#<a\s[^>]*href="([^"]*)"[^>]*>(.*?)</a>#is', '$2 ($1)', $html);
        $html = (string) preg_replace('#<(br|/p|/div|/h[1-6]|/li|hr)\b[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace("/\n{3,}/", "\n\n", (string) preg_replace('/[ \t]+/', ' ', $text)));
    }
}
