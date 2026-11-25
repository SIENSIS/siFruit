/**
 * Modals built from code (Bootstrap): full-screen on phones, keyboard accessible, text content
 * unless a Node is given.
 *
 *   const value = await openModal({ title: 'Export', body: 'Choose a format', buttons: [
 *       { label: 'Cancel', value: null, variant: 'secondary' },
 *       { label: 'CSV', value: 'csv', variant: 'primary' },
 *   ] });
 *
 * Resolves with the value of the button pressed, or null when the modal is dismissed.
 * Optional settings: `size` ('lg', 'xl'…), `focus` (the element focused when shown) and
 * `bind(close)`, called with a function that closes the modal with a value (for choices made
 * inside the body, like the icon picker's grid).
 */
import { t } from 'core/config.js';

let counter = 0;

export function openModal(settings = {}) {
    counter += 1;
    const id = 'kp-modal-' + counter;
    const modal = document.createElement('div');
    modal.className = 'modal fade';
    modal.id = id;
    modal.tabIndex = -1;
    modal.setAttribute('aria-labelledby', id + '-title');
    modal.setAttribute('aria-hidden', 'true');

    const dialog = document.createElement('div');
    dialog.className = 'modal-dialog modal-dialog-centered modal-fullscreen-sm-down' + (settings.size ? ' modal-' + settings.size : '');

    const content = document.createElement('div');
    content.className = 'modal-content';

    const header = document.createElement('div');
    header.className = 'modal-header';
    const title = document.createElement('h2');
    title.className = 'modal-title h5';
    title.id = id + '-title';
    title.textContent = settings.title || '';
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn-close';
    close.setAttribute('data-bs-dismiss', 'modal');
    close.setAttribute('aria-label', t('ui.close'));
    header.append(title, close);

    const body = document.createElement('div');
    body.className = 'modal-body';

    if (settings.body instanceof Node) {
        body.append(settings.body);
    } else {
        const paragraph = document.createElement('p');
        paragraph.className = 'mb-0';
        paragraph.textContent = settings.body || '';
        body.append(paragraph);
    }

    const footer = document.createElement('div');
    footer.className = 'modal-footer';

    let result = null;
    const buttons = settings.buttons || [{ label: t('ui.close'), value: null, variant: 'secondary' }];
    let focus = null;

    for (const definition of buttons) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-' + (definition.variant || 'secondary');
        button.textContent = definition.label;
        button.addEventListener('click', () => {
            result = definition.value === undefined ? null : definition.value;
            hide();
        });
        footer.append(button);

        if (definition.autofocus) {
            focus = button;
        }
    }

    content.append(header, body, footer);
    dialog.append(content);
    modal.append(dialog);
    document.body.append(modal);

    const instance = window.bootstrap.Modal.getOrCreateInstance(modal);

    // Bootstrap ignores hide() while the modal is still opening: wait for it.
    let shown = false;
    let pending = false;
    const hide = () => {
        if (shown) {
            instance.hide();
        } else {
            pending = true;
        }
    };

    if (typeof settings.bind === 'function') {
        settings.bind((value) => {
            result = value === undefined ? null : value;
            hide();
        });
    }

    if (settings.focus instanceof HTMLElement) {
        focus = settings.focus;
    }

    // The element the user focused inside the dialog before it finished opening.
    let moved = null;
    modal.addEventListener('focusin', (event) => {
        if (!shown && event.target !== modal) {
            moved = event.target;
        }
    });

    return new Promise((resolve) => {
        modal.addEventListener('shown.bs.modal', () => {
            shown = true;

            if (pending) {
                instance.hide();

                return;
            }

            // Keep the focus where the user already moved it inside the dialog while it opened
            // (Bootstrap's focus trap focuses the modal itself just before this event).
            const target = moved !== null && moved !== focus && modal.contains(moved) ? moved : (focus || footer.lastElementChild || close);
            target.focus();
        });
        modal.addEventListener('hidden.bs.modal', () => {
            instance.dispose();
            modal.remove();
            resolve(result);
        });
        instance.show();
    });
}
