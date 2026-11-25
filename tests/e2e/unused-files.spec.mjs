// Settings › System › Unused files (session 55, D-55-01, docs/system.md): the screen at every
// width and colour mode (no overflow, CSP or axe violations), then, on desktop light only,
// "Delete selected" with the confirmation and the re-authentication.
//
// Something must be unused on the demo site: delete (purge) a demo post beforehand and run the
// site with the test-only `storage.unusedFilesTestGraceDays = 0` in `.env` (ignored in
// production); otherwise the deletion test is skipped.
import { test, expect, checkPage, backend } from './support.mjs';

const FLOW_PROJECT = 'desktop-light';
const URL = 'system/unused-files';

/** Confirms the password when the re-authentication window expired; true when it was asked. */
async function reauthenticateIfAsked(page) {
    if (!/\/auth\/reauth/.test(page.url())) {
        return false;
    }

    await page.fill('input[name=password]', process.env.E2E_PASSWORD ?? '');
    await Promise.all([page.waitForLoadState('networkidle'), page.click('#main button[type=submit]')]);

    return true;
}

test.describe('unused files', () => {
    test('the screen', async ({ page }) => {
        await checkPage(page, backend(URL), 'unused-files');
        await expect(page.locator('[data-unused-summary]')).toBeVisible();
        await expect(page.locator('[data-unused-auto]')).toHaveAttribute('data-unused-auto', /on|off/);
    });

    test('delete selected after confirming and re-authenticating', async ({ page }, testInfo) => {
        test.skip(testInfo.project.name !== FLOW_PROJECT, 'The deletion runs once.');

        await page.goto(backend(URL), { waitUntil: 'networkidle' });
        await Promise.all([page.waitForLoadState('networkidle'), page.click('form[action$="/unused-files/scan"] button[type=submit]')]);
        const rows = page.locator('[data-unused-files] tbody tr');
        test.skip(await rows.count() === 0, 'Nothing is unused on the demo site (purge a demo post and set storage.unusedFilesTestGraceDays = 0).');

        const id = await rows.first().getAttribute('data-unused-file');
        const button = page.locator('[data-unused-delete-selected]');
        await expect(button).toBeDisabled();

        // "Select all" toggles the page's rows and the confirmation names the count and size.
        await page.locator('[data-unused-all]').check();
        await expect(page.locator('[data-unused-selection]')).toContainText(String(await rows.count()));
        await page.locator('[data-unused-all]').uncheck();
        await page.locator(`#unused-${id}`).check();
        await expect(button).toBeEnabled();
        await expect(button).toHaveAttribute('data-confirm', /1/);

        for (let attempt = 0; attempt < 2; attempt++) {
            await button.click();
            const dialog = page.locator('.modal.show');
            await expect(dialog).toBeVisible();
            await Promise.all([page.waitForLoadState('networkidle'), dialog.locator('.btn-danger').click()]);

            if (!(await reauthenticateIfAsked(page))) {
                break;
            }

            // A POST is never replayed after the re-authentication: choose and confirm again.
            await page.goto(backend(URL), { waitUntil: 'networkidle' });
            await page.locator(`#unused-${id}`).check();
        }

        await expect(page.locator(`[data-unused-file="${id}"]`)).toHaveCount(0);
        await checkPage(page, backend(URL), 'unused-files-after');
    });
});
