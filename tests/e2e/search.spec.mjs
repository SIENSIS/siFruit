// The site search (session 50, D-50-01): the box in the public header (inside the collapsed menu
// on phones, an icon link to the results page on tablets) sends the words to the results page,
// which highlights them; the results page and "no results" pass the usual checks (overflow, CSP,
// axe) at every width and colour mode.
import { test, expect, checkPage } from './support.mjs';

test.use({ storageState: { cookies: [], origins: [] } });

test('search from the header box', async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });

    let box = page.locator('form.site-search-box input[name=q]');
    const toggler = page.locator('.navbar-toggler');
    const link = page.locator('a.site-search-link');
    if (!(await box.isVisible()) && (await toggler.isVisible())) {
        await toggler.click(); // phones: inside the collapsed menu
    } else if (!(await box.isVisible()) && (await link.isVisible())) {
        await link.click(); // tablets: an icon link to the results page and its form
        await expect(page).toHaveURL(/\/search$/);
        box = page.locator('#site-search-page-q');
    }
    await expect(box).toBeVisible();
    await box.fill('organitzacio');
    await box.press('Enter');

    await expect(page).toHaveURL(/\/search\?q=organitzacio/);
    await expect(page.locator('.site-search-result').first()).toBeVisible();
    await expect(page.locator('.site-search-result mark').first()).toBeVisible();
    expect(page.cspViolations).toEqual([]);
});

test('search results page', async ({ page }) => {
    await checkPage(page, '/search?q=organitzacio', 'public-search');
    await expect(page.locator('.site-search-result').first()).toBeVisible();
});

test('search in Spanish keeps the language', async ({ page }) => {
    await checkPage(page, '/es/search?q=organizacion', 'public-search-es');
    await expect(page.locator('html')).toHaveAttribute('lang', 'es');
});

test('search without results', async ({ page }) => {
    await checkPage(page, '/search?q=zzzxqv', 'public-search-empty');
    await expect(page.locator('.site-search-empty')).toBeVisible();
});
