// Session 57 (D-57-01 … D-57-03): the back-office Calendar group with the agenda, the agenda
// remembering the user's view and the administration switch, and the collapsible folder tree of
// the file manager. The pages are checked at every width and colour mode (no overflow, CSP or
// axe violations); the flows run on desktop light only.
import { test, expect, checkPage, backend } from './support.mjs';

// FullCalendar 7 hashes its class names: its view buttons are tabs (month, week, day, list).
const VIEW_TABS = '.events-calendar [role="tab"]';
const calendarReady = async (page) => page.locator(VIEW_TABS).first().waitFor();

const FLOW_PROJECT = 'desktop-light';
const TREE_ROOT = 'e2e-arbre';

/** Creates TREE_ROOT/<n>/sub folders in the first space the administrator may manage (once). */
async function makeFolders(page) {
    await page.goto(backend('files'), { waitUntil: 'networkidle' });

    return page.evaluate(async ({ api, root }) => {
        const { get, post } = await import('core/http.js');
        const flatten = (nodes, list = []) => {
            for (const node of nodes) {
                list.push(node);
                flatten(node.children || [], list);
            }

            return list;
        };
        const tree = (await get(api + '/tree')).tree;
        const all = flatten(tree);
        const existing = all.find((node) => node.name === root);

        if (existing) {
            return existing.disk + ':' + existing.path;
        }

        for (const space of tree) {
            const folder = async (parent, name) => {
                const body = new FormData();
                body.append('disk', space.disk);
                body.append('path', parent);
                body.append('name', name);

                return (await post(api + '/folder', { body, silent: true })).path;
            };

            try {
                const top = await folder(space.path, root);

                for (let i = 1; i <= 12; i++) {
                    const child = await folder(top, 'carpeta-' + String(i).padStart(2, '0'));
                    await folder(child, 'subcarpeta');
                }

                return space.disk + ':' + top;
            } catch (error) {
                // not allowed here: try the next space
            }
        }

        return null;
    }, { api: backend('files/api'), root: TREE_ROOT });
}

test.describe('calendar agenda', () => {
    test('the agenda in the Calendar group', async ({ page }, testInfo) => {
        await checkPage(page, backend('calendar'), 'agenda', {
            ready: calendarReady,
        });

        if (testInfo.project.use.viewport.width >= 992) {
            const group = page.locator('#menu-group-calendar');
            await expect(group).toBeVisible();
            await expect(group.locator('a.nav-link')).toHaveCount(3);
            await expect(group.locator('a.nav-link').first()).toHaveAttribute('aria-current', 'page');
        }
    });

    test('the events list links to the agenda', async ({ page }) => {
        await checkPage(page, backend('events'), 'events-list');
        await expect(page.getByRole('link', { name: /agenda/i }).first()).toBeVisible();
    });

    test('the agenda remembers the last view and the administration switch', async ({ page }, testInfo) => {
        test.skip(testInfo.project.name !== FLOW_PROJECT, 'The flow runs once.');

        await page.goto(backend('calendar'), { waitUntil: 'networkidle' });
        await calendarReady(page);
        const tabs = page.locator(VIEW_TABS);
        const saved = page.waitForResponse((response) => /\/calendar\/view$/.test(response.url()));
        await tabs.nth(1).click();
        await saved;
        await page.reload({ waitUntil: 'networkidle' });
        await calendarReady(page);
        await expect(page.locator(VIEW_TABS).nth(1)).toHaveAttribute('aria-selected', 'true');
        await page.screenshot({ path: 'writable/e2e/screenshots/desktop-light/agenda-week-remembered.png' });

        // Back to the month view for the next runs.
        const back = page.waitForResponse((response) => /\/calendar\/view$/.test(response.url()));
        await page.locator(VIEW_TABS).nth(0).click();
        await back;

        const scope = page.locator('#events-scope');
        if (await scope.count()) {
            const feed = page.waitForRequest((request) => /\/calendar\/feed\?.*scope=admin/.test(request.url()));
            await scope.check();
            await feed;
            await checkPage(page, backend('calendar'), 'agenda-admin-scope', { ready: calendarReady });
            await expect(page.locator('#events-scope')).toBeChecked();
            const off = page.waitForResponse((response) => /\/calendar\/scope$/.test(response.url()));
            await page.locator('#events-scope').uncheck();
            await off;
        }
    });
});

test.describe('file manager tree', () => {
    test('the tree folds, filters and answers the keyboard', async ({ page }, testInfo) => {
        test.skip(testInfo.project.name !== FLOW_PROJECT, 'The flow runs once.');

        const rootKey = await makeFolders(page);
        test.skip(rootKey === null, 'No space where the administrator may create folders.');

        await page.evaluate(() => {
            Object.keys(localStorage).filter((key) => key.startsWith('files.tree.')).forEach((key) => localStorage.removeItem(key));
        });
        await page.reload({ waitUntil: 'networkidle' });

        const tree = page.locator('[data-fm-tree]');
        const rootItem = tree.locator(`[data-key="${rootKey}"]`);
        await expect(rootItem).toBeVisible();
        const rootNode = rootItem.locator('xpath=ancestor::li[@role="treeitem"][1]');
        await expect(rootNode).toHaveAttribute('aria-expanded', 'false');
        await expect(tree.getByText('carpeta-01', { exact: true })).toHaveCount(0);

        // Open with the chevron; the state survives a reload.
        await rootNode.locator('.fm-tree-toggle').first().click();
        await expect(rootNode).toHaveAttribute('aria-expanded', 'true');
        await expect(tree.getByText('carpeta-01', { exact: true })).toBeVisible();
        await page.reload({ waitUntil: 'networkidle' });
        await expect(tree.getByText('carpeta-12', { exact: true })).toBeVisible();
        await checkPage(page, backend('files'), 'files-tree-open', { ready: async (p) => p.locator('[data-fm-tree] .fm-tree-item').first().waitFor() });

        // Keyboard: → opens, ↓ moves, ← goes back to the parent, then closes it.
        const first = tree.getByText('carpeta-01', { exact: true });
        await first.locator('xpath=ancestor::button[1]').focus();
        await page.keyboard.press('ArrowRight');
        const firstNode = first.locator('xpath=ancestor::li[@role="treeitem"][1]');
        await expect(firstNode).toHaveAttribute('aria-expanded', 'true');
        await page.keyboard.press('ArrowDown');
        await expect(page.locator(':focus')).toHaveText(/subcarpeta/);
        await page.keyboard.press('ArrowLeft');
        await expect(page.locator(':focus')).toHaveText(/carpeta-01/);
        await page.keyboard.press('ArrowLeft');
        await expect(firstNode).toHaveAttribute('aria-expanded', 'false');
        await page.keyboard.press('Home');
        await expect(tree.locator('.fm-tree-item').first()).toBeFocused();

        // Filter: the matches and their ancestors; clearing it restores the state.
        await page.fill('[data-fm-tree-filter]', 'carpeta-07');
        await expect(tree.getByText('carpeta-07', { exact: true })).toBeVisible();
        await expect(tree.getByText('carpeta-06', { exact: true })).toHaveCount(0);
        await page.screenshot({ path: 'writable/e2e/screenshots/desktop-light/files-tree-filtered.png' });
        await page.fill('[data-fm-tree-filter]', '');
        await expect(tree.getByText('carpeta-06', { exact: true })).toBeVisible();

        // Collapse all.
        await page.locator('[data-fm-tree-collapse]').click();
        await expect(tree.getByText('carpeta-01', { exact: true })).toHaveCount(0);
        await page.screenshot({ path: 'writable/e2e/screenshots/desktop-light/files-tree-collapsed.png' });
    });
});
