/**
 * Live filter of Devtools' PHP info: `data-component="devtools/filter"` on a search input,
 * `data-filter-target` = the rows to filter (CSS selector), `data-filter-group` = their
 * containers, hidden when none of their rows match.
 */
export function mount(input) {
    const rows = Array.from(document.querySelectorAll(input.dataset.filterTarget || '[data-filter-row]'));
    const groups = Array.from(document.querySelectorAll(input.dataset.filterGroup || '[data-filter-group]'));

    input.addEventListener('input', () => {
        const needle = input.value.trim().toLowerCase();

        rows.forEach((row) => {
            row.hidden = needle !== '' && !row.textContent.toLowerCase().includes(needle);
        });

        groups.forEach((group) => {
            const title = group.querySelector('h2');
            const titleMatches = needle !== '' && title !== null && title.textContent.toLowerCase().includes(needle);

            if (titleMatches) {
                group.querySelectorAll('[hidden]').forEach((row) => { row.hidden = false; });
            }

            group.hidden = needle !== '' && !titleMatches && group.querySelector(input.dataset.filterTarget + ':not([hidden])') === null;
        });
    });
}
