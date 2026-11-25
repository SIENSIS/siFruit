<?php

declare(strict_types=1);

namespace Modules\Events\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Entities\User;
use Modules\Core\Controllers\BackendController;
use Modules\Core\Routing\BackendPath;
use Modules\Events\Services\AgendaPreferences;
use Modules\Events\Services\CalendarComponent;
use Modules\Events\Services\Calendars;
use Modules\Events\Services\EventDetails;
use Modules\Events\Services\EventRepository;
use Modules\Events\Services\IcalExport;
use Modules\Events\Services\IcalFeeds;
use Modules\Events\Services\ProvidedEvents;
use Modules\Events\Services\Reminders;

/**
 * The calendar of the back office (resource `events-calendar`, `events-calendar.view`, every
 * group): only the events the signed-in user can see (public, private and restricted to one of
 * their groups or to them), **administrators included** (`EventVisibility`).
 *
 * - `GET backend/calendar` (`?category=<id>`): month, week, day and list views;
 * - `GET backend/calendar/feed?start=&end=[&category=]`: JSON for the calendar component;
 * - `GET backend/calendar/event/{code}[?date=YYYY-MM-DD]`: an event's details (anything not
 *   visible is a 404) and the user's reminder;
 * - `POST backend/calendar/event/{code}/reminder` (`minutes`: one of
 *   `Config\Calendar::$reminderOptions`, empty to remove it; refused when the event does not
 *   allow reminders);
 * - `GET backend/calendar/event/{code}/export.ics`: one-off iCal export;
 * - `POST backend/calendar/calendars`: the calendars the user shows or hides (`calendars[]`, or
 *   `only=<id>` to show one calendar, or `all` to show every calendar);
 * - `POST backend/calendar/view` (`view`, `mobile=0|1`): the view the agenda opens in next time
 *   (session 57, D-57-01; only views of `CalendarComponent::VIEWS`, else 422);
 * - `POST backend/calendar/scope` (`scope=admin|personal`): the "All events (administration)"
 *   switch of holders of `events-events.all` (D-57-02); the feed's `?scope=admin` needs the same
 *   permission on every request (403 otherwise).
 *
 * Events of other modules' calendars and providers are included (docs/calendar.md).
 */
final class CalendarView extends BackendController
{
    public function index(): string
    {
        $repository = new EventRepository();
        $service    = new Calendars();
        $user       = $this->currentUser();
        $prefs      = new AgendaPreferences();
        $mayAdmin   = $this->can('events-events.all');
        $options    = CalendarComponent::options(backend_url('calendar/feed'), 'events-category', $prefs->view($user, false)) + [
            'mobileView' => $prefs->view($user, true) ?? 'listMonth',
            'viewSave'   => backend_url('calendar/view'),
            'scope'      => $mayAdmin ? 'events-scope' : null,
            'addUrl'     => $this->can('events-events.add') ? backend_url('events/add') : null,
        ];

        return $this->render('Modules\Events\Views\calendar\index', [
            'calendars'  => array_values($service->visibleTo($user)),
            'hidden'     => $service->hidden($user),
            'saveUrl'    => backend_url('calendar/calendars'),
            'title'      => lang('Events.calendarTitle'),
            'icon'       => 'bi bi-calendar3',
            'categories' => $repository->categories(),
            'selected'   => max(0, (int) $this->request->getGet('category')),
            'options'    => $options,
            'scope'      => $mayAdmin ? $prefs->scope($user, true) : null,
            'scopeUrl'   => backend_url('calendar/scope'),
            'actions'    => [
                ...($this->can('events-events.add') ? [['label' => lang('Events.newEvent'), 'url' => backend_url('events/add'), 'icon' => 'bi bi-plus-lg']] : []),
                ...($this->can('events-events.list') ? [['label' => lang('Events.manage'), 'url' => backend_url('events'), 'icon' => 'bi bi-pencil-square']] : []),
                ['label' => lang('Events.subscription.menu'), 'url' => backend_url('calendar/subscription'), 'icon' => 'bi bi-rss'],
            ],
        ]);
    }

    public function feed(): ResponseInterface
    {
        $range = CalendarComponent::range($this->request);

        if ($range === null) {
            return $this->json(['error' => lang('Events.errors.range')], 400);
        }

        $user     = $this->currentUser();
        $admin    = $this->request->getGet('scope') === AgendaPreferences::SCOPE_ADMIN;

        // The administration scope needs `events-events.all` on every request (D-57-02).
        if ($admin && ! $this->can('events-events.all')) {
            return $this->json(['error' => lang('Events.errors.scope')], 403);
        }

        $category = max(0, (int) $this->request->getGet('category'));
        $service  = new Calendars();
        $shown    = array_values(array_diff(array_keys($service->visibleTo($user)), $service->hidden($user)));
        $rows     = (new EventRepository())->occurrences($user, $range[0], $range[1], false, ['category' => $category, 'calendars' => $shown, 'admin' => $admin]);
        $base     = backend_url('calendar/event');
        $events   = array_map(static fn (array $row): array => EventRepository::toFeed($row, $base), $rows);

        // Provided events have no category: a category filter shows only own events.
        if ($category === 0) {
            foreach ((new ProvidedEvents())->occurrences($user, $range[0], $range[1], $shown) as $row) {
                $events[] = ProvidedEvents::toFeed($row);
            }
        }

        return $this->json(['events' => $events])->setHeader('Cache-Control', 'private, no-store');
    }

    public function show(string $code): string
    {
        $user        = $this->currentUser();
        $id          = EventRepository::idFromCode($code);
        $repository  = new EventRepository();
        $event       = $id === null ? null : $repository->find($user, $id);
        $participant = $event !== null;

        // Holders of `events-events.all` also open events they only see in the administration
        // scope (D-57-02): no reminder and no export for those, the audience is shown.
        if ($event === null && $id !== null && $this->can('events-events.all')) {
            $event = $repository->find($user, $id, false, true);
        }

        if ($event === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $date  = $this->request->getGet('date');
        $local = ($event['source_module'] ?? null) === null || $event['source_module'] === '';

        $this->breadcrumb(lang('Events.calendarTitle'), backend_url('calendar'));

        return $this->render('Modules\Events\Views\calendar\show', [
            'title'     => (string) $event['title'],
            'icon'      => 'bi bi-calendar-event',
            'event'     => EventDetails::build($event, is_string($date) ? $date : null),
            'reminders' => (bool) ($event['allow_reminders'] ?? true),
            'participant' => $participant,
            'audience'  => $participant ? [] : self::audienceLabels((int) $event['id']),
            'exportUrl' => $participant ? backend_url('calendar/event/' . $code . '/export.ics') : null,
            'managedBy' => is_string($event['source_module'] ?? null) && $event['source_module'] !== '' ? self::moduleLabel($event['source_module']) : null,
            'sourceUrl' => self::sourceUrl($event['source_url'] ?? null),
            'reminder'  => (new Reminders())->of((int) $event['id'], (int) $user->id),
            'options'   => array_map('intval', (array) config(\Modules\Events\Config\Calendar::class)->reminderOptions),
            'formUrl'   => backend_url('calendar/event/' . $code . '/reminder'),
            'backUrl'   => backend_url('calendar'),
            'editUrl'   => $this->can('events-events.edit') && $local ? backend_url('events/edit/' . $code) : null,
            'duplicateUrl' => $this->can('events-events.duplicate') && $this->can('events-events.add') && $local ? backend_url('events/duplicate/' . $code) : null,
        ]);
    }

    /**
     * `GET backend/calendar/event/{code}/export.ics`: a static copy of the event to import.
     */
    public function export(string $code): ResponseInterface
    {
        $event = $this->visible($code, $this->currentUser());

        return IcalExport::download((new IcalFeeds())->single($event, backend_url('calendar/event/' . $code)), (string) $event['title'], $this->response);
    }

    /**
     * `POST backend/calendar/calendars` (`calendars[]`: the calendars to **show**): the calendars
     * the user hides in the calendar view. JSON for the component, a redirect without JavaScript.
     */
    public function calendars(): ResponseInterface
    {
        $user    = $this->currentUser();
        $service = new Calendars();
        $visible = array_keys($service->visibleTo($user));
        $only    = (string) $this->request->getPost('only');
        $shown   = match (true) {
            $this->request->getPost('all') !== null => $visible,                // "Show all"
            $only !== ''                            => [$only],                 // "Only this one"
            default                                 => array_map('strval', (array) ($this->request->getPost('calendars') ?? [])),
        };
        // A calendar the user may not see changes nothing.
        $hidden = $only !== '' && ! in_array($only, $visible, true)
            ? $service->hidden($user)
            : $service->setHidden($user, array_values(array_diff($visible, $shown)));

        if ($this->request->isAJAX() || str_contains($this->request->getHeaderLine('Accept'), 'application/json')) {
            return $this->json(['hidden' => $hidden]);
        }

        return redirect()->to(backend_url('calendar'))->with('success', lang('Events.calendars.saved'));
    }

    /**
     * `POST backend/calendar/view`: the view the agenda opens in next time, for this screen size.
     */
    public function view(): ResponseInterface
    {
        $view   = (string) $this->request->getPost('view');
        $mobile = $this->request->getPost('mobile') === '1';

        if (! (new AgendaPreferences())->setView($this->currentUser(), $view, $mobile)) {
            return $this->json(['error' => lang('Events.errors.view')], 422);
        }

        return $this->json(['view' => $view]);
    }

    /**
     * `POST backend/calendar/scope`: the "All events (administration)" switch (D-57-02). Only
     * holders of `events-events.all` reach it (route filter); `admin` is checked again here.
     */
    public function scope(): ResponseInterface
    {
        $scope = (new AgendaPreferences())->setScope($this->currentUser(), (string) $this->request->getPost('scope'), $this->can('events-events.all'));

        if ($this->request->isAJAX() || str_contains($this->request->getHeaderLine('Accept'), 'application/json')) {
            return $this->json(['scope' => $scope]);
        }

        return redirect()->to(backend_url('calendar'));
    }

    public function reminder(string $code): RedirectResponse
    {
        $user    = $this->currentUser();
        $event   = $this->visible($code, $user);
        $value   = (string) $this->request->getPost('minutes');
        $allowed = array_map('intval', (array) config(\Modules\Events\Config\Calendar::class)->reminderOptions);
        $minutes = $value === '' ? null : (int) $value;

        if ($minutes !== null && (! in_array($minutes, $allowed, true) || ! (bool) ($event['allow_reminders'] ?? true))) {
            return redirect()->to(backend_url('calendar/event/' . $code))->with('error', lang('Events.errors.reminder'));
        }

        (new Reminders())->set((int) $event['id'], (int) $user->id, $minutes);

        return redirect()->to(backend_url('calendar/event/' . $code))
            ->with('success', lang($minutes === null ? 'Events.reminderRemoved' : 'Events.reminderSaved'));
    }

    /**
     * The name of a module as shown to users ("Managed by News").
     */
    public static function moduleLabel(string $module): string
    {
        // The convention is `<Module>.module.name`; an older plain `<Module>.module` is accepted.
        foreach ([$module . '.module.name', $module . '.module'] as $key) {
            $label = lang($key);

            if (is_string($label) && $label !== $key && $label !== '') {
                return $label;
            }
        }

        return $module;
    }

    /**
     * A safe link back to the source record: an internal path or an http(s) URL, else null.
     */
    public static function sourceUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || preg_match('/[\s\\\\]/', $url) === 1) {
            return null;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return site_url(ltrim(BackendPath::resolve($url), '/'));
        }

        return preg_match('#^https?://[^/@]+(/|$)#', $url) === 1 ? $url : null;
    }

    /**
     * Names of the groups and users of a restricted event (shown in the administration scope).
     *
     * @return list<string>
     */
    private static function audienceLabels(int $id): array
    {
        $db     = db_connect();
        $groups = $db->table('event_audience a')->select('g.title')->join('groups g', 'g.id = a.group_id', 'inner')->where('a.event_id', $id)->get()->getResultArray();
        $users  = $db->table('event_audience a')->select('u.username')->join('users u', 'u.id = a.user_id', 'inner')->where('a.event_id', $id)->get()->getResultArray();

        return [...array_map(static fn (array $r): string => (string) $r['title'], $groups), ...array_map(static fn (array $r): string => (string) $r['username'], $users)];
    }

    /**
     * The event behind a masked code if the user can see it; otherwise a 404.
     *
     * @return array<string, mixed>
     */
    private function visible(string $code, User $user): array
    {
        $id    = EventRepository::idFromCode($code);
        $event = $id === null ? null : (new EventRepository())->find($user, $id);

        if ($event === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $event;
    }
}
