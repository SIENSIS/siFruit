/**
 * Unused files screen (Settings › System › Unused files, D-55-01): `data-component="core/ui/unused-files"`
 * on the form. "Select all" checks the rows of the page, the selection's count and size are shown,
 * and the "Delete selected" button's confirmation (`data-confirm`, core/ui/confirm.js) says how
 * many files and how much space. Without JavaScript the form works as is; the server checks
 * every id again.
 *
 * Options: `confirm` ("Delete {count} files ({size})?"), `selection` ("{count} selected · {size}"),
 * `units` (["B", "KB", "MB", "GB"]).
 */
function formatBytes(bytes, units) {
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    const digits = unit === 0 ? 0 : 1;

    return `${new Intl.NumberFormat(document.documentElement.lang || undefined, { maximumFractionDigits: digits }).format(value)} ${units[unit]}`;
}

function fill(template, count, size) {
    return String(template || '').replace('{count}', String(count)).replace('{size}', size);
}

export function mount(form, options) {
    const units = Array.isArray(options.units) && options.units.length > 0 ? options.units : ['B', 'KB', 'MB', 'GB'];
    const boxes = () => Array.from(form.querySelectorAll('input[type="checkbox"][name="ids[]"]'));
    const all = form.querySelector('[data-unused-all]');
    const button = form.querySelector('[data-unused-delete-selected]');
    const output = form.querySelector('[data-unused-selection]');

    const update = () => {
        const chosen = boxes().filter((box) => box.checked);
        const bytes = chosen.reduce((sum, box) => sum + (Number.parseInt(box.dataset.bytes || '0', 10) || 0), 0);
        const size = formatBytes(bytes, units);

        if (output) {
            output.textContent = fill(options.selection, chosen.length, size);
        }

        if (button) {
            button.disabled = chosen.length === 0;
            button.dataset.confirm = fill(options.confirm, chosen.length, size);
        }

        if (all) {
            const total = boxes().length;
            all.checked = total > 0 && chosen.length === total;
            all.indeterminate = chosen.length > 0 && chosen.length < total;
        }
    };

    if (all) {
        all.hidden = false;
        all.closest('[data-unused-all-wrap]')?.removeAttribute('hidden');
        all.addEventListener('change', () => {
            boxes().forEach((box) => {
                box.checked = all.checked;
            });
            update();
        });
    }

    form.addEventListener('change', (event) => {
        if (event.target instanceof HTMLInputElement && event.target.name === 'ids[]') {
            update();
        }
    });

    update();
}
