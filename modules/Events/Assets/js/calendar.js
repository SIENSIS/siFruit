/**
 * Calendar component: <div data-component="events/calendar" data-options='{…}'>.
 *
 * Loads the vendored FullCalendar 7 (MIT, public/assets/vendor/fullcalendar) on demand from the
 * URLs in the options, then shows the month, week, day and list views. FullCalendar 7 ships its
 * CSS as files and only touches styles through the CSSOM, so it works under the strict CSP.
 *
 * Options (Modules\Events\Services\CalendarComponent::options()):
 *   feed      JSON feed URL (start/end dates of the visible range are added, plus `category`)
 *   scripts   library, theme and locale scripts, loaded in order
 *   styles    stylesheets (the library's and events::css/calendar.css)
 *   locale    page language (ca, es, en)
 *   view      first view on larger screens; phones start with the list
 *   category  select element id of the category filter (optional)
 *   categories ids of the only categories shown (optional; a calendar section of a page)
 *   toggles   form id of the calendar toggles (optional; back office): a change, "only this
 *             one" or "show all" is saved with a POST to the form's action and the events are
 *             reloaded; each toggle gets its calendar's colour (data-color)
 *   error     text shown when the library or the events cannot be loaded
 *   mobileView first view on phones (back office: the user's last one, default the list)
 *   viewSave  URL that remembers the view the user picks (back office, session 57): a POST with
 *             `view` and `mobile` (0/1) on every change of view
 *   scope     id of the "All events (administration)" switch (back office, D-57-02): when it
 *             is checked the feed gets `scope=admin`; a change is saved with a POST to its form
 *   addUrl    add form of an event (back office, `events-events.add`): clicking an empty day or
 *             time opens it with `?start=YYYY-MM-DD` or `YYYY-MM-DDTHH:MM`
 *
 * Events are links to their details page (the feed's `url`): a click opens it like any link.
 * Dates are the site's wall-clock times (the feed sends them without offset and the calendar
 * runs in UTC, so a browser in another time zone shows the same times as the site).
 */
import { get, post } from 'core/http.js';

const loading = new Map();

function load(tag, url) {
    if (!loading.has(url)) {
        loading.set(url, new Promise((resolve, reject) => {
            const element = document.createElement(tag);

            if (tag === 'link') {
                element.rel = 'stylesheet';
                element.href = url;
            } else {
                element.src = url;
                element.async = false;
            }

            element.addEventListener('load', () => resolve());
            element.addEventListener('error', () => reject(new Error('Cannot load ' + url)));
            document.head.appendChild(element);
        }));
    }

    return loading.get(url);
}

async function loadLibrary(options) {
    await Promise.all((options.styles || []).map((url) => load('link', url)));

    for (const url of options.scripts || []) {
        await load('script', url);
    }

    if (typeof window.FullCalendar === 'undefined') {
        throw new Error('FullCalendar is not available');
    }

    return window.FullCalendar;
}

function isPhone() {
    return window.matchMedia('(max-width: 575.98px)').matches;
}

function dateOnly(date) {
    return date.toISOString().slice(0, 10);
}

export async function mount(element, options) {
    const status = element.querySelector('[data-calendar-status]');
    let library;

    try {
        library = await loadLibrary(options);
    } catch (error) {
        if (status) {
            status.textContent = options.error || '';
            status.classList.remove('visually-hidden');
        }

        console.error(error);

        return;
    }

    const target = document.createElement('div');
    target.className = 'events-calendar';
    element.appendChild(target);

    const filter = options.category ? document.getElementById(options.category) : null;
    const scope = options.scope ? document.getElementById(options.scope) : null;
    const phone = isPhone();
    let currentView = null;

    function saveView(view) {
        if (!options.viewSave || currentView === null || view === currentView) {
            currentView = view;

            return;
        }

        currentView = view;
        const body = new FormData();
        body.append('view', view);
        body.append('mobile', phone ? '1' : '0');
        post(options.viewSave, { body, silent: true }).catch(() => {});
    }

    const calendar = new library.Calendar(target, {
        locale: options.locale || 'ca',
        timeZone: 'UTC',
        initialView: phone ? (options.mobileView || 'listMonth') : (options.view || 'dayGridMonth'),
        headerToolbar: phone
            ? { start: 'prev,next', center: 'title', end: 'today' }
            : { start: 'prev,next today', center: 'title', end: 'dayGridMonth,timeGridWeek,timeGridDay,listMonth' },
        footerToolbar: phone ? { center: 'listMonth,dayGridMonth,timeGridDay' } : false,
        height: 'auto',
        nowIndicator: true,
        dayMaxEvents: true,
        navLinks: true,
        eventDisplay: 'block',
        datesSet(info) {
            saveView(info.view.type);
        },
        dateClick: options.addUrl ? (info) => {
            // The calendar runs in UTC with the site's wall-clock times: the ISO string is the local time.
            const start = info.allDay ? info.dateStr.slice(0, 10) : info.date.toISOString().slice(0, 16);
            window.location.assign(options.addUrl + '?' + new URLSearchParams({ start }).toString());
        } : undefined,
        events(info, success, failure) {
            const params = new URLSearchParams({ start: dateOnly(info.start), end: dateOnly(info.end) });

            if (scope && scope.checked) {
                params.set('scope', 'admin');
            }

            if (filter && filter.value !== '') {
                params.set('category', filter.value);
            } else if (Array.isArray(options.categories) && options.categories.length > 0) {
                params.set('categories', options.categories.join(','));
            }

            get(options.feed + '?' + params.toString(), { silent: true })
                .then((data) => success(Array.isArray(data.events) ? data.events : []))
                .catch((error) => {
                    if (status) {
                        status.textContent = options.error || '';
                        status.classList.remove('visually-hidden');
                    }

                    failure(error);
                });
        },
    });

    calendar.render();

    if (filter) {
        filter.addEventListener('change', () => calendar.refetchEvents());
    }

    if (scope && scope.form) {
        scope.form.querySelectorAll('[data-scope-submit]').forEach((button) => button.classList.add('d-none'));
        scope.addEventListener('change', () => {
            post(scope.form.action, { body: new FormData(scope.form) })
                .then(() => calendar.refetchEvents())
                .catch(() => {});
        });
    }

    const toggles = options.toggles ? document.getElementById(options.toggles) : null;

    if (toggles) {
        const save = () => post(toggles.action, { body: new FormData(toggles) })
            .then(() => calendar.refetchEvents())
            .catch(() => {});

        toggles.querySelectorAll('[data-calendar-submit]').forEach((button) => button.classList.add('d-none'));
        // The calendar's colour (CSSOM: allowed by the strict CSP, unlike style="").
        toggles.querySelectorAll('[data-color]').forEach((pill) => pill.style.setProperty('--events-calendar-color', pill.dataset.color));
        toggles.addEventListener('change', save);
        // "Only this one" and "Show all" switch the checkboxes here and save without reloading.
        toggles.querySelectorAll('button[name="only"], button[name="all"]').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                toggles.querySelectorAll('input[name="calendars[]"]').forEach((input) => {
                    input.checked = button.name === 'all' || input.value === button.value;
                });
                save();
            });
        });
    }
}
