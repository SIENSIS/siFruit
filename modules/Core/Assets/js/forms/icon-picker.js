/**
 * Icon picker for icon fields (docs/frontend.md, "Icon fields"; D-49-02).
 *
 * Every `<input data-icon-picker>` gets a preview of its icon and a "Choose an icon" button that
 * opens a dialog with a searchable grid of the vendored Bootstrap Icons. Started by core/app.js
 * for the whole page and for fields added later (a MutationObserver), like the password button.
 *
 * - Value: `data-icon-picker` (empty) or `="class"` writes `bi bi-<name>` (menus, CRUD fields);
 *   `="name"` writes `bi-<name>` (the section icon field). Both forms are read. The field stays a
 *   text input: without JavaScript the name is typed, and the server validates it either way.
 * - The list of names is `core::icons.json`, generated from the vendored stylesheet by
 *   `assets:publish` (Modules\Core\Frontend\Icons); no network request leaves the site.
 * - Dialog: a search box (by name and by the words of the name: "egg" finds `egg`, `egg-fill`
 *   and `egg-fried`), a listbox grid (arrow keys, Home/End, Enter or Space chooses, Escape
 *   closes), a live region with the number of results, and "No icon" to clear the field.
 *
 * `pickIcon()` opens the same dialog for other components (the rich-text editor's "Insert an
 * icon").
 */
import { config, t } from 'core/config.js';
import { openModal } from 'core/ui/modal.js';

const SELECTOR = 'input[data-icon-picker]';
const NAME = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;
const LIMIT = 300;
let listPromise = null;
let counter = 0;
let started = false;

function element(tag, className = '', attributes = {}) {
    const node = document.createElement(tag);

    if (className !== '') {
        node.className = className;
    }

    for (const [name, value] of Object.entries(attributes)) {
        node.setAttribute(name, value);
    }

    return node;
}

function icon(name, extra = '') {
    return element('i', 'bi bi-' + name + (extra ? ' ' + extra : ''), { 'aria-hidden': 'true' });
}

function loadStyle() {
    const url = config().icons?.css;

    if (url && document.querySelector('link[data-icon-picker-css]') === null) {
        const link = element('link', '', { rel: 'stylesheet', href: url, 'data-icon-picker-css': '' });
        document.head.append(link);
    }
}

/**
 * The names of the vendored icons (sorted), loaded once.
 */
export function loadIcons() {
    if (listPromise === null) {
        const url = config().icons?.list;
        listPromise = !url ? Promise.resolve([]) : fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error(String(response.status)))))
            .then((data) => (Array.isArray(data?.icons) ? data.icons.filter((name) => typeof name === 'string' && NAME.test(name)) : []))
            .catch((error) => {
                listPromise = null;
                throw error;
            });
    }

    return listPromise;
}

/**
 * The icon name of a field value (`bi bi-egg`, `bi-egg`), or '' when it is not one.
 */
export function parseIcon(value) {
    const match = /^(?:bi\s+)?bi-([a-z0-9]+(?:-[a-z0-9]+)*)$/.exec(String(value || '').trim());

    return match ? match[1] : '';
}

export function formatIcon(name, mode = 'class') {
    if (name === '') {
        return '';
    }

    return mode === 'name' ? 'bi-' + name : 'bi bi-' + name;
}

/**
 * The names that match a search: every word of the query must be in the name; whole names
 * first, then names whose words start with the query's words, then the rest (alphabetical
 * inside each group, as the list is sorted).
 */
export function searchIcons(names, query) {
    const words = String(query || '').toLowerCase().trim().replace(/^bi\s+/, '').replace(/^bi-/, '')
        .split(/[\s\-_.,]+/).filter(Boolean);

    if (words.length === 0) {
        return names.slice();
    }

    const joined = words.join('-');
    const groups = [[], [], [], []];

    for (const name of names) {
        if (!words.every((word) => name.includes(word))) {
            continue;
        }

        const parts = name.split('-');

        if (name === joined) {
            groups[0].push(name);
        } else if (name.startsWith(joined)) {
            groups[1].push(name);
        } else if (words.every((word) => parts.some((part) => part.startsWith(word)))) {
            groups[2].push(name);
        } else {
            groups[3].push(name);
        }
    }

    return groups.flat();
}

/**
 * Opens the dialog. Settings: `current` (the name chosen now), `clear` (offer "No icon"),
 * `title`, `help` (a note above the search). Resolves with the chosen name, '' for "No icon" or
 * null when it is closed without a choice.
 */
export async function pickIcon(settings = {}) {
    loadStyle();
    counter += 1;
    const id = 'kp-icon-picker-' + counter;
    const current = settings.current || '';
    let names = [];
    let close = () => {};

    const body = element('div', 'kp-icon-picker');
    const label = element('label', 'form-label', { for: id + '-search' });
    label.textContent = t('iconPicker.search');
    const search = element('input', 'form-control', {
        type: 'search', id: id + '-search', autocomplete: 'off', spellcheck: 'false',
        'aria-describedby': id + '-help ' + id + '-status', 'aria-controls': id + '-options',
    });
    const help = element('div', 'form-text mt-1', { id: id + '-help' });
    help.textContent = t('iconPicker.searchHelp');
    const status = element('p', 'small text-body-secondary mt-2 mb-2', { id: id + '-status', 'aria-live': 'polite', role: 'status' });
    status.textContent = t('iconPicker.loading');
    const options = element('div', 'kp-icon-options', { id: id + '-options', role: 'listbox', 'aria-label': settings.title || t('iconPicker.choose') });
    const shown = element('p', 'kp-icon-picker-name small font-monospace text-body-secondary mt-2 mb-0', { 'aria-hidden': 'true' });

    if (settings.help) {
        const note = element('div', 'alert alert-info small py-2 mb-3', { id: id + '-note' });
        note.textContent = settings.help;
        body.append(note);
        search.setAttribute('aria-describedby', id + '-note ' + search.getAttribute('aria-describedby'));
    }

    body.append(label, search, help, status, options, shown);

    function focusOption(button) {
        for (const other of options.querySelectorAll('[role="option"]')) {
            other.tabIndex = -1;
        }

        button.tabIndex = 0;
        button.focus();
        shown.textContent = 'bi-' + button.dataset.icon;
    }

    function render() {
        const found = searchIcons(names, search.value);

        // The current icon first while nothing is searched (the list is long).
        if (current !== '' && search.value.trim() === '' && found.includes(current)) {
            found.splice(found.indexOf(current), 1);
            found.unshift(current);
        }
        options.replaceChildren();

        found.slice(0, LIMIT).forEach((name, index) => {
            const button = element('button', 'kp-icon-option', {
                type: 'button', role: 'option', 'aria-label': name, title: name,
                'aria-selected': name === current ? 'true' : 'false',
            });
            button.dataset.icon = name;
            button.tabIndex = index === 0 ? 0 : -1;
            button.append(icon(name));
            options.append(button);
        });

        if (found.length === 0) {
            status.textContent = t('iconPicker.none');
        } else if (found.length > LIMIT) {
            status.textContent = t('iconPicker.count', { count: found.length }) + ' ' + t('iconPicker.more', { shown: LIMIT });
        } else {
            status.textContent = found.length === 1 ? t('iconPicker.one') : t('iconPicker.count', { count: found.length });
        }
    }

    search.addEventListener('input', render);
    search.addEventListener('keydown', (event) => {
        const first = options.querySelector('[aria-selected="true"]') || options.querySelector('[role="option"]');

        if ((event.key === 'ArrowDown' || (event.key === 'Enter' && first !== null)) && first !== null) {
            event.preventDefault();

            if (event.key === 'Enter' && search.value.trim() !== '') {
                close(first.dataset.icon);

                return;
            }

            focusOption(first);
        } else if (event.key === 'Enter') {
            event.preventDefault();
        }
    });

    options.addEventListener('click', (event) => {
        const button = event.target.closest('[role="option"]');

        if (button !== null) {
            close(button.dataset.icon);
        }
    });

    options.addEventListener('focusin', (event) => {
        const button = event.target.closest('[role="option"]');

        if (button !== null) {
            shown.textContent = 'bi-' + button.dataset.icon;
        }
    });

    options.addEventListener('keydown', (event) => {
        const buttons = Array.from(options.querySelectorAll('[role="option"]'));
        const index = buttons.indexOf(document.activeElement);

        if (index < 0) {
            return;
        }

        const top = buttons[0].offsetTop;
        const columns = Math.max(1, buttons.findIndex((button) => button.offsetTop !== top) > 0 ? buttons.findIndex((button) => button.offsetTop !== top) : buttons.length);
        const moves = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: columns, ArrowUp: -columns, Home: -index, End: buttons.length };

        if (event.key === 'ArrowUp' && index < columns) {
            event.preventDefault();
            search.focus();

            return;
        }

        if (event.key in moves) {
            event.preventDefault();
            focusOption(buttons[Math.min(buttons.length - 1, Math.max(0, index + moves[event.key]))]);
        }
    });

    loadIcons().then((list) => {
        names = list;
        render();
    }).catch(() => {
        status.textContent = t('iconPicker.error');
    });

    const buttons = [{ label: t('iconPicker.cancel'), value: null, variant: 'secondary' }];

    if (settings.clear) {
        buttons.unshift({ label: t('iconPicker.clear'), value: '', variant: 'outline-danger' });
    }

    const result = await openModal({
        title: settings.title || t('iconPicker.choose'),
        body,
        size: 'lg',
        focus: search,
        buttons,
        bind: (closer) => { close = closer; },
    });

    return typeof result === 'string' && (result === '' || NAME.test(result)) ? result : null;
}

function update(input) {
    const preview = input.iconPickerPreview;
    const name = parseIcon(input.value);

    if (preview instanceof HTMLElement) {
        preview.className = 'bi bi-' + (name !== '' ? name : 'question-square');
        preview.parentElement?.classList.toggle('text-body-tertiary', name === '');
    }
}

function groupFor(input) {
    const parent = input.parentElement;

    if (parent.classList.contains('input-group')) {
        parent.classList.add('flex-nowrap', 'kp-icon-picker-group');

        return parent;
    }

    const group = element('div', 'input-group flex-nowrap kp-icon-picker-group');
    const hadFocus = document.activeElement === input;
    parent.insertBefore(group, input);
    group.append(input);

    if (hadFocus) {
        input.focus();
    }

    return group;
}

function moveFeedback(group, input) {
    let next = group.nextElementSibling;
    const moved = [];

    while (next !== null && (next.classList.contains('invalid-feedback') || next.classList.contains('valid-feedback'))) {
        moved.push(next);
        next = next.nextElementSibling;
    }

    if (moved.length > 0 || input.classList.contains('is-invalid')) {
        group.classList.add('has-validation');
    }

    group.append(...moved);
}

export function enhance(input) {
    if (!(input instanceof HTMLInputElement) || input.iconPickerButton !== undefined || input.parentElement === null) {
        return;
    }

    loadStyle();
    counter += 1;

    if (input.id === '') {
        input.id = 'kp-icon-field-' + counter;
    }

    const mode = input.dataset.iconPicker === 'name' ? 'name' : 'class';
    const group = groupFor(input);
    const box = element('span', 'input-group-text kp-icon-preview', { 'aria-hidden': 'true' });
    const preview = element('i', 'bi');
    box.append(preview);
    input.before(box);

    const button = element('button', 'btn btn-outline-secondary kp-icon-picker-button', {
        type: 'button', 'aria-haspopup': 'dialog', 'aria-controls': input.id,
    });
    // The name shows when the field is wide enough (a container query in icon-picker.css).
    const text = element('span', 'kp-icon-picker-text ms-1');
    text.textContent = t('iconPicker.choose');
    button.append(icon('grid-3x3-gap'), text);
    button.setAttribute('aria-label', t('iconPicker.choose'));
    button.title = t('iconPicker.choose');
    button.disabled = input.disabled || input.readOnly;
    input.after(button);
    moveFeedback(group, input);

    const live = element('span', 'visually-hidden', { 'aria-live': 'polite' });
    group.after(live);

    input.iconPickerButton = button;
    input.iconPickerPreview = preview;
    update(input);

    input.addEventListener('input', () => update(input));
    input.addEventListener('change', () => update(input));
    button.addEventListener('click', async () => {
        const name = await pickIcon({ current: parseIcon(input.value), clear: true });
        button.focus();

        if (name === null) {
            return;
        }

        input.value = formatIcon(name, mode);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        live.textContent = name === '' ? t('iconPicker.cleared') : t('iconPicker.chosen', { name: formatIcon(name, mode) });
    });
}

export function initIconPickers(root = document) {
    for (const input of root.querySelectorAll(SELECTOR)) {
        enhance(input);
    }

    if (started) {
        return;
    }

    started = true;

    if (typeof MutationObserver === 'function' && document.body !== null) {
        new MutationObserver((records) => {
            for (const record of records) {
                for (const node of record.addedNodes) {
                    if (!(node instanceof Element)) {
                        continue;
                    }

                    if (node.matches(SELECTOR)) {
                        enhance(node);
                    }

                    for (const input of node.querySelectorAll(SELECTOR)) {
                        enhance(input);
                    }
                }
            }
        }).observe(document.body, { childList: true, subtree: true });
    }
}
