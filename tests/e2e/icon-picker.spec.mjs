// The Core's icon picker (core/forms/icon-picker.js, docs/frontend.md "Icon fields", D-49-02)
// and the editor's "Insert an icon" (D-49-01): a Menus item form and a kpcrudy form (Users ›
// new user, with an input added later) — open, search "egg", choose with the keyboard, preview,
// clear, Escape; axe on the open dialog in every project (light and dark, phone to desktop); no
// CSP violation; an input without the attribute is left alone. Then an icon inserted in a News
// post, saved, there again after reopening, and shown on the public page with aria-hidden.
import { test, expect, checkPage, axe, backend } from './support.mjs';

const CHOOSE = 'Tria una icona';

async function openPicker(page, input) {
    const id = await input.getAttribute('id');
    const button = page.locator(`button[aria-controls="${id}"]`);
    await expect(button).toHaveAccessibleName(CHOOSE);
    await expect(button).toHaveAttribute('aria-haspopup', 'dialog');
    await button.click();

    const dialog = page.getByRole('dialog', { name: CHOOSE });
    await expect(dialog).toBeVisible();
    const search = dialog.getByRole('searchbox');
    await expect(search).toBeFocused();
    await expect(dialog.getByRole('option').first()).toBeVisible();

    return { button, dialog, search };
}

async function closed(page) {
    await expect(page.locator('.modal:has(.kp-icon-picker)')).toHaveCount(0);
}

async function checkField(page, input, expected) {
    const { button, dialog, search } = await openPicker(page, input);

    // Search by a word of the name, then choose with the keyboard.
    await search.fill('egg');
    const options = dialog.getByRole('option');
    await expect(options.first()).toHaveAccessibleName('egg');
    await expect(dialog.getByRole('option', { name: 'egg-fried' })).toBeVisible();
    await expect(dialog.getByRole('status')).toContainText('icones');

    // After the fade-in and any hover transition (axe measures the colours of the moment).
    await page.mouse.move(0, 0);
    await page.waitForFunction(() => document.querySelector('.modal.show') !== null && document.getAnimations().length === 0);
    const violations = await axe(page);
    expect(violations, 'axe on the open dialog').toEqual([]);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);

    await search.press('ArrowDown');
    await expect(options.first()).toBeFocused();
    await page.keyboard.press('ArrowRight');
    await expect(options.nth(1)).toBeFocused();
    await page.keyboard.press('ArrowLeft');
    await expect(options.first()).toBeFocused();
    await page.keyboard.press('Enter');
    await closed(page);

    await expect(input).toHaveValue(expected.egg);
    await expect(page.locator(`.input-group:has(#${await input.getAttribute('id')}) .kp-icon-preview i`)).toHaveClass(/\bbi-egg\b/);
    await expect(button).toBeFocused();

    // The current icon is marked; Escape changes nothing.
    await button.click();
    await expect(page.getByRole('dialog').getByRole('option', { name: 'egg', selected: true })).toBeVisible();
    await expect(page.getByRole('dialog').getByRole('searchbox')).toBeFocused();
    await page.keyboard.press('Escape');
    await closed(page);
    await expect(input).toHaveValue(expected.egg);

    // "No icon" clears the field.
    await button.click();
    await page.getByRole('dialog').getByRole('button', { name: 'Sense icona' }).click();
    await closed(page);
    await expect(input).toHaveValue('');

    // Typed by hand: the preview follows.
    await input.fill(expected.star);
    await expect(page.locator(`.input-group:has(#${await input.getAttribute('id')}) .kp-icon-preview i`)).toHaveClass(/\bbi-star\b/);

    expect(page.cspViolations, 'CSP violations').toEqual([]);
}

test('a Menus item form', async ({ page }) => {
    await page.goto(backend('menus/public'));
    const href = await page.locator('a[href*="/menus/public/items/"]').first().getAttribute('href');
    test.skip(!href, 'No public menu (php spark modules:demo Menus).');
    await page.goto(href);
    const add = await page.locator('a[href$="/new"]').first().getAttribute('href');
    expect(add).toBeTruthy();

    await checkPage(page, add, 'icon-picker-menu-item');
    await checkField(page, page.locator('#f-icon'), { egg: 'bi bi-egg', star: 'bi bi-star' });
});

test('a kpcrudy form, a field added later and one without the attribute', async ({ page }) => {
    await checkPage(page, backend('users/add'), 'icon-picker-kpcrudy');

    await page.evaluate(() => {
        const form = document.querySelector('form input[name="email"]').form;
        for (const [id, picker] of [['e2e-icon', true], ['e2e-plain', false]]) {
            const wrapper = document.createElement('div');
            wrapper.className = 'mb-3';
            const label = document.createElement('label');
            label.className = 'form-label';
            label.htmlFor = id;
            label.textContent = id;
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control';
            input.id = id;
            input.name = id;
            if (picker) input.dataset.iconPicker = 'name';
            wrapper.append(label, input);
            form.prepend(wrapper);
        }
    });

    await expect(page.locator('button[aria-controls="e2e-plain"]')).toHaveCount(0);
    await expect(page.locator('#e2e-plain')).not.toHaveAttribute('data-icon-picker');
    await checkField(page, page.locator('#e2e-icon'), { egg: 'bi-egg', star: 'bi-star' });
});

test('"Insert an icon" in a News post, saved and shown on the public page', async ({ page }) => {
    // A published demo post: its public address and its title.
    await page.goto('/news');
    const article = page.locator('main a[href*="/news/"]:not([href*="/category/"]):not([href$="feed.xml"])').first();
    test.skip((await article.count()) === 0, 'No published demo news (php spark modules:demo News).');
    const publicPath = new URL(await article.getAttribute('href'), page.url()).pathname;
    const title = (await article.textContent()).trim();

    await page.goto(backend('news/posts'));
    const row = page.locator('tr', { hasText: title }).first();
    const edit = await row.locator('a[href*="/edit/"]').first().getAttribute('href');
    expect(edit, `edit link of "${title}"`).toBeTruthy();

    await page.goto(edit, { waitUntil: 'networkidle' });
    const editor = page.locator('.ql-editor').first();
    await editor.click();
    await page.keyboard.press('Control+End');

    const insert = page.locator('.ql-toolbar button.ql-icon').first();
    await expect(insert).toHaveAccessibleName('Insereix una icona');
    await insert.click();
    const dialog = page.getByRole('dialog', { name: 'Insereix una icona' });
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText('decoratives');
    await dialog.getByRole('searchbox').fill('egg fried');
    await dialog.getByRole('option', { name: 'egg-fried' }).click();
    await closed(page);
    await expect(editor.locator('i.bi.bi-egg-fried[aria-hidden="true"]')).toHaveCount(1);

    // Deleted like a character, then inserted again.
    await page.keyboard.press('Backspace');
    await expect(editor.locator('i.bi-egg-fried')).toHaveCount(0);
    await insert.click();
    await page.getByRole('dialog').getByRole('searchbox').fill('egg fried');
    await page.getByRole('dialog').getByRole('searchbox').press('Enter');
    await closed(page);
    await expect(editor.locator('i.bi-egg-fried')).toHaveCount(1);

    await Promise.all([
        page.waitForURL((url) => !url.pathname.includes('/edit/')),
        page.locator('form button[type=submit]').filter({ hasText: /Desa/ }).first().click(),
    ]);

    // Reopened: the icon is still there (the sanitiser kept it).
    await page.goto(edit, { waitUntil: 'networkidle' });
    await expect(page.locator('.ql-editor').first().locator('i.bi.bi-egg-fried')).toHaveCount(1);

    // The public page shows it, hidden from screen readers.
    await checkPage(page, publicPath, 'icon-picker-public-news');
    const icon = page.locator('main i.bi.bi-egg-fried');
    await expect(icon).toHaveCount(1);
    await expect(icon).toHaveAttribute('aria-hidden', 'true');

    // Clean up: remove the icon again so the demo post is as before.
    await page.goto(edit, { waitUntil: 'networkidle' });
    await page.evaluate(() => {
        for (const node of document.querySelectorAll('.ql-editor i.bi-egg-fried')) node.remove();
    });
    await page.locator('.ql-editor').first().click();
    await page.keyboard.type(' ');
    await page.keyboard.press('Backspace');
    await Promise.all([
        page.waitForURL((url) => !url.pathname.includes('/edit/')),
        page.locator('form button[type=submit]').filter({ hasText: /Desa/ }).first().click(),
    ]);
});
