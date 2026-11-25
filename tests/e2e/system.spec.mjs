// Settings › System (session 53, docs/system.md): System information and the Error logs viewer
// (with a sample log whose secrets must appear masked), and the Devtools tools of session 53
// (module inspector, routes explorer, mail preview) when Devtools is available. Every page at
// every width and colour mode, without overflow, CSP or axe (WCAG 2.1 AA) violations.
import { writeFileSync, unlinkSync } from 'node:fs';
import { test, expect, checkPage, backend } from './support.mjs';

const SAMPLE_DATE = '2001-02-03';
const SAMPLE_LOG = `writable/logs/log-${SAMPLE_DATE}.log`;

/** The log viewer asks for the password again (reauth): answer it when the window expired. */
async function reauthenticate(page, url) {
    await page.goto(url);
    if (/\/auth\/reauth/.test(page.url())) {
        await page.fill('input[name=password]', process.env.E2E_PASSWORD ?? '');
        await Promise.all([page.waitForLoadState('networkidle'), page.click('#main button[type=submit]')]);
    }
}

test.describe('system information', () => {
    test('the report with copy and download', async ({ page }) => {
        await checkPage(page, backend('system'), 'system-info');
        await expect(page.locator('[data-system-section="application"]')).toBeVisible();
        await expect(page.locator('[data-system-section="modules"]')).toContainText('Core');
        await expect(page.locator('[data-component="core/ui/copy"]')).toBeVisible();
        await expect(page.locator('#system-report-text')).toHaveValue(/## /);
        await expect(page.locator('body')).not.toContainText(/hex2bin:|base64:[A-Za-z0-9+/]{20}/);
    });
});

test.describe('error logs', () => {
    test.beforeAll(() => {
        writeFileSync(SAMPLE_LOG, [
            `INFO - ${SAMPLE_DATE} 10:00:00 --> Started the e2e sample`,
            `ERROR - ${SAMPLE_DATE} 10:05:00 --> Login failed for ana password=hunter2-e2e`,
            '[Method: POST, Route: login]',
            '1 SYSTEMPATH/CodeIgniter.php(363): handle()',
            `CRITICAL - ${SAMPLE_DATE} 10:06:00 --> Upstream call with Authorization: Bearer abcdef0123456789e2e`,
            `WARNING - ${SAMPLE_DATE} 10:07:00 --> Disk almost full`,
            '',
        ].join('\n'));
    });

    test.afterAll(() => {
        try { unlinkSync(SAMPLE_LOG); } catch { /* already gone */ }
    });

    test('a sample log, masked', async ({ page }) => {
        await reauthenticate(page, backend('system/logs'));
        await checkPage(page, backend('system/logs'), 'system-logs');
        await expect(page.locator(`[data-log-date="${SAMPLE_DATE}"]`)).toBeVisible();

        await checkPage(page, backend(`system/logs/${SAMPLE_DATE}`), 'system-log');
        await expect(page.locator('[data-log-entries] > li')).toHaveCount(4);
        await expect(page.locator('[data-log-entries]')).toContainText('[masked]');
        await expect(page.locator('[data-log-entries]')).not.toContainText('hunter2-e2e');
        await expect(page.locator('[data-log-entries]')).not.toContainText('abcdef0123456789e2e');
    });
});

test.describe('devtools tools', () => {
    test.beforeEach(async ({ page }) => {
        const response = await page.goto(backend('devtools/modules'));
        test.skip(response === null || response.status() === 404 || response.status() === 403, 'Devtools is not available on this site.');
    });

    test('the module inspector of News', async ({ page }) => {
        await checkPage(page, backend('devtools/modules/News'), 'devtools-inspector');
        await expect(page.locator('[data-inspector="permissions"]')).toContainText('news-posts.edit');
    });

    test('the routes explorer', async ({ page }) => {
        await checkPage(page, backend('devtools/routes?module=News'), 'devtools-routes');
        await expect(page.locator('[data-routes] tbody tr').first()).toBeVisible();
    });

    test('a mail preview', async ({ page }) => {
        await checkPage(page, backend('devtools/mail?item=modules-news-notifications-newnotice&locale=ca'), 'devtools-mail');
        await expect(page.locator('iframe[sandbox]')).toBeVisible();
    });

    test('the other tools', async ({ page }, testInfo) => {
        await checkPage(page, backend('devtools/modules'), 'devtools-modules');
        // The gallery draws each component in both modes; the block of the other mode inherits the
        // page's theme variables, so axe checks only the block of the page's own mode.
        const other = testInfo.project.use.colorScheme === 'dark' ? 'light' : 'dark';
        await checkPage(page, backend('devtools/components'), 'devtools-components', { axeExclude: [`[data-gallery-mode="${other}"]`] });
        await expect(page.locator('[data-gallery-mode="dark"]').first()).toBeVisible();
        await checkPage(page, backend('devtools/phpinfo'), 'devtools-phpinfo');
        await checkPage(page, backend('devtools/checks'), 'devtools-checks');
    });
});
