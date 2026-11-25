<?php

declare(strict_types=1);

namespace Modules\Events\Tests;

use CodeIgniter\Shield\Entities\User;
use Modules\Core\Cache\CoreCache;
use Modules\Core\Menus\MenuBuilder;
use Modules\Core\Menus\MenuEntry;
use Modules\Events\Config\Module;
use Modules\Events\Controllers\Events;
use Modules\Events\Services\AgendaPreferences;
use Modules\Events\Services\Calendars;

/**
 * Session 57: the back-office Calendar group (Agenda, Events, Categories; D-57-01), the links
 * between the events list and the agenda, the remembered view, and the "All events
 * (administration)" switch (D-57-02).
 *
 * @internal
 */
final class AgendaTest extends EventsTestCase
{
    public function testTheManifestDeclaresACalendarGroupWithTheAgendaFirst(): void
    {
        $menu = (new Module())->menu;

        $this->assertSame(['group' => 'group.calendar', 'label' => 'Events.menu.calendar', 'icon' => 'bi bi-calendar3', 'order' => 25], $menu[0]);
        $children = array_values(array_filter($menu, static fn (array $entry): bool => ($entry['parent'] ?? null) === 'group.calendar'));
        $this->assertSame(['calendar', 'events', 'categories'], array_column($children, 'resource'));
        $this->assertSame([1, 2, 3], array_column($children, 'order'));
    }

    public function testTheGroupShowsOnlyTheEntriesTheUserMayOpen(): void
    {
        $user   = $this->createUser('ana@example.com', 'user');
        $editor = $this->createUser('edi@example.com', 'editor');
        $admin  = $this->createUser('boss@example.com', 'admin');

        $this->assertSame(['Events.calendar'], $this->children($user), 'A user only sees the agenda.');
        $this->assertSame(['Events.calendar', 'Events.events', 'Events.categories'], $this->children($editor));
        $this->assertSame(['Events.calendar', 'Events.events', 'Events.categories'], $this->children($admin));
    }

    public function testTheGroupIsOpenOnEachOfItsPages(): void
    {
        $admin = $this->createUser('boss@example.com', 'admin');

        foreach (['backend/calendar' => 'Events.calendar', 'backend/calendar/event/abc' => 'Events.calendar', 'backend/events' => 'Events.events', 'backend/events/edit/abc' => 'Events.events', 'backend/events/categories' => 'Events.categories', 'backend/events/categories/add' => 'Events.categories'] as $path => $active) {
            $group = $this->group($admin, $path);
            $this->assertTrue($group->open, $path);

            foreach ($group->children as $child) {
                $this->assertSame($child->key === $active, $child->active, $path . ' ' . $child->key);
            }
        }
    }

    public function testTheEventsListAndTheAgendaLinkToEachOther(): void
    {
        $this->createUser('edi@example.com', 'editor');
        $this->login('edi@example.com');

        $list = $this->body('GET', 'backend/events');
        $this->assertStringContainsString(lang('Events.viewInAgenda'), $list);
        $this->assertStringContainsString(site_url('backend/calendar'), $list);

        $agenda = $this->body('GET', 'backend/calendar');
        $this->assertStringContainsString(lang('Events.newEvent'), $agenda);
        $this->assertStringContainsString(lang('Events.manage'), $agenda);
        $this->assertStringContainsString('"addUrl":"' . site_url('backend/events/add') . '"', $agenda);
    }

    public function testWithoutTheAddPermissionTheAgendaOffersNoNewEvent(): void
    {
        $this->createUser('ana@example.com', 'user');
        $this->login('ana@example.com');

        $agenda = $this->body('GET', 'backend/calendar');
        $this->assertStringNotContainsString(lang('Events.newEvent'), $agenda);
        $this->assertStringContainsString('"addUrl":null', $agenda);
        $this->assertStringNotContainsString('events-scope', $agenda, 'No administration switch without events-events.all.');
    }

    public function testTheAddFormTakesOnlyAValidStartFromTheUrl(): void
    {
        $storage = new \DateTimeZone(config('App')->appTimezone ?: 'UTC');
        $local   = static fn (string $value): string => (new \DateTimeImmutable($value, new \DateTimeZone(site_timezone())))->setTimezone($storage)->format('Y-m-d H:i:s');

        $this->assertSame($local('2026-12-03 09:00'), Events::startFromQuery('2026-12-03'));
        $this->assertSame($local('2026-12-03 16:30'), Events::startFromQuery('2026-12-03T16:30'));

        foreach (['', '2026-02-30', '2026-12-3', '2026-12-03 16:30', '2026-12-03T25:00', 'tomorrow', '2026-12-03<script>'] as $bad) {
            $this->assertNull(Events::startFromQuery($bad), $bad);
        }

        $this->assertNull(Events::startFromQuery(['2026-12-03']));

        $this->createUser('edi@example.com', 'editor');
        $this->login('edi@example.com');
        $this->assertStringContainsString('2026-12-03', $this->body('GET', 'backend/events/add?start=2026-12-03'));
        $this->assertStringNotContainsString('<script>', $this->body('GET', 'backend/events/add?start=2026-12-03<script>'));
    }

    public function testTheAgendaRemembersTheLastViewPerUserAndScreen(): void
    {
        $ana = $this->createUser('ana@example.com', 'user');
        $this->createUser('john@example.com', 'user');
        $this->login('ana@example.com');

        $this->assertStringContainsString('"view":"dayGridMonth"', $this->body('GET', 'backend/calendar'), 'Month by default.');
        $this->assertStringContainsString('"mobileView":"listMonth"', $this->body('GET', 'backend/calendar'), 'The list on phones by default.');

        $this->send('POST', 'backend/calendar/view', ['view' => 'timeGridWeek', 'mobile' => '0'])->assertOK();
        $this->send('POST', 'backend/calendar/view', ['view' => 'timeGridDay', 'mobile' => '1'])->assertOK();

        $page = $this->body('GET', 'backend/calendar');
        $this->assertStringContainsString('"view":"timeGridWeek"', $page);
        $this->assertStringContainsString('"mobileView":"timeGridDay"', $page);

        foreach (['multiMonthYear', '', 'dayGridMonth"', '<b>'] as $bad) {
            $this->send('POST', 'backend/calendar/view', ['view' => $bad, 'mobile' => '0'])->assertStatus(422);
        }

        $this->assertSame('timeGridWeek', (new AgendaPreferences())->view($ana, false), 'A refused view stores nothing.');

        // Another user is not affected, and the hidden calendars stay as they were.
        $this->send('POST', 'logout');
        $this->login('john@example.com');
        $this->assertStringContainsString('"view":"dayGridMonth"', $this->body('GET', 'backend/calendar'));
    }

    public function testSavingTheViewKeepsTheHiddenCalendars(): void
    {
        $ana = $this->createUser('ana@example.com', 'user');
        (new Calendars())->setHidden($ana, [Calendars::OWN]);
        $this->login('ana@example.com');

        $this->send('POST', 'backend/calendar/view', ['view' => 'timeGridWeek'])->assertOK();

        $this->assertContains(Calendars::OWN, (new Calendars())->hidden($ana));
        $this->assertSame(1, db_connect()->table('event_calendar_preferences')->where('user_id', (int) $ana->id)->countAllResults());
    }

    public function testSavingTheViewNeedsTheCsrfToken(): void
    {
        $this->createUser('ana@example.com', 'user');
        $this->login('ana@example.com');

        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);
        $this->send('POST', 'backend/calendar/view', ['view' => 'timeGridWeek', 'csrf_test_name' => 'forged'], ['csrf_test_name' => 'real-token'] + $_SESSION);
    }

    public function testTheAdministrationScopeNeedsTheAllPermission(): void
    {
        $john = $this->createUser('john@example.com', 'user');
        $this->createUser('ana@example.com', 'user');
        $this->createUser('edi@example.com', 'editor');
        $this->createUser('boss@example.com', 'admin');
        $this->event('Només per al John', ['visibility' => 'restricted'], [], [(int) $john->id]);
        $this->event('Per a tothom');

        foreach (['ana@example.com', 'edi@example.com'] as $email) {
            $this->login($email);
            $start = date('Y-m-d', time() - 86400);
            $end   = date('Y-m-d', time() + 30 * 86400);
            $this->send('GET', 'backend/calendar/feed?start=' . $start . '&end=' . $end . '&scope=admin')->assertStatus(403);
            $this->send('POST', 'backend/calendar/scope', ['scope' => 'admin'])->assertStatus(403);
            $this->send('POST', 'logout');
        }

        $this->login('boss@example.com');
        $this->assertNotContains('Només per al John', $this->feedTitles(), 'Off by default: the personal agenda.');
        $this->assertStringContainsString('id="events-scope"', $this->body('GET', 'backend/calendar'));
        $this->assertStringNotContainsString('id="events-scope" aria-describedby="events-scope-help" checked', $this->body('GET', 'backend/calendar'));

        $titles = $this->feedTitles('backend/calendar/feed', 30, '&scope=admin');
        $this->assertContains('Només per al John', $titles);
        $this->assertContains('Per a tothom', $titles);
    }

    public function testTheSwitchIsRememberedAndIgnoredOnceThePermissionIsGone(): void
    {
        $boss = $this->createUser('boss@example.com', 'user');
        $boss->addPermission('events-events.all');
        $this->login('boss@example.com');

        $this->send('POST', 'backend/calendar/scope', ['scope' => 'admin'], ['csrf_test_name' => 'test-csrf-token'] + $_SESSION)->assertRedirect();
        $this->assertSame(AgendaPreferences::SCOPE_ADMIN, (new AgendaPreferences())->scope($boss, true));
        $this->assertStringContainsString('checked', $this->switchHtml());

        $boss->removePermission('events-events.all');
        $this->assertSame(AgendaPreferences::SCOPE_PERSONAL, (new AgendaPreferences())->scope($boss, false), 'A stored admin scope is ignored without the permission.');
        $this->assertSame(AgendaPreferences::SCOPE_PERSONAL, (new AgendaPreferences())->setScope($boss, 'admin', false), 'And never stored without it.');
    }

    public function testAnEventSeenOnlyAsAnAdministratorHasNoReminderNorExport(): void
    {
        $john = $this->createUser('john@example.com', 'user');
        $this->createUser('boss@example.com', 'admin');
        $only = $this->event('Només per al John', ['visibility' => 'restricted'], [], [(int) $john->id]);
        $this->login('boss@example.com');

        $page = $this->body('GET', 'backend/calendar/event/' . $this->code($only));
        $this->assertStringContainsString('Només per al John', $page);
        $this->assertStringContainsString(lang('Events.adminScope.badge'), $page);
        $this->assertStringContainsString('john', $page, 'The audience is shown.');
        $this->assertStringContainsString(lang('Events.adminScope.noReminder'), $page);
        $this->assertStringNotContainsString('export.ics', $page);
        $this->assertStringContainsString(site_url('backend/events/edit/' . $this->code($only)), $page);
        $this->assertStringContainsString(site_url('backend/events/duplicate/' . $this->code($only)), $page);

        $this->assertNotFound('POST', 'backend/calendar/event/' . $this->code($only) . '/reminder', ['minutes' => '60']);
        $this->assertSame(0, db_connect()->table('event_reminders')->where('event_id', $only)->countAllResults(), 'No reminder for an event not meant for them.');
        $this->assertNotFound('GET', 'backend/calendar/event/' . $this->code($only) . '/export.ics');
    }

    public function testAUserWithoutEditRightsSeesNoEditNorDuplicate(): void
    {
        $this->createUser('ana@example.com', 'user');
        $id = $this->event('Per a tothom');
        $this->login('ana@example.com');

        $page = $this->body('GET', 'backend/calendar/event/' . $this->code($id));
        $this->assertStringNotContainsString('backend/events/edit/', $page);
        $this->assertStringNotContainsString('backend/events/duplicate/', $page);
    }

    private function switchHtml(): string
    {
        $page = $this->body('GET', 'backend/calendar');
        $this->assertSame(1, preg_match('/<input[^>]*id="events-scope"[^>]*>/', $page, $match));

        return $match[0];
    }

    /**
     * @return list<string>
     */
    private function children(User $user): array
    {
        return array_map(static fn (MenuEntry $entry): string => $entry->key, $this->group($user)->children);
    }

    private function group(User $user, ?string $path = null): MenuEntry
    {
        // Another test may have cached a menu of the same group set with other declarations.
        (new CoreCache())->invalidate('menus');

        // The route names of the entries, so the test does not depend on the router service an
        // earlier test of the suite may have replaced.
        $routes = ['events-calendar' => 'backend/calendar', 'events-events' => 'backend/events', 'events-categories' => 'backend/events/categories'];

        foreach ((new MenuBuilder(routes: static fn (string $name): ?string => $routes[$name] ?? null))->zone('sidebar', $user, $path) as $entry) {
            if ($entry->key === 'group.calendar') {
                return $entry;
            }
        }

        $this->fail('The Calendar group is in the sidebar.');
    }
}
