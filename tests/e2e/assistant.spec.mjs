// The chat assistant (session 51, D-51-01): the bubble opens a panel, a question gets an FAQ
// answer with a link, an unknown one the contact link; keyboard only (Enter sends, Escape closes
// and the focus goes back to the button), no CSP violation, no horizontal overflow and no axe
// violation with the panel open, at every width and colour mode.
// Needs the Assistant module enabled with its demo FAQs and `Assistant.enabled` on (docs/assistant.md).
import { test, expect, axe } from './support.mjs';

test.use({ storageState: { cookies: [], origins: [] } });

async function openBubble(page) {
    await page.goto('/', { waitUntil: 'networkidle' });
    const toggle = page.locator('[data-assistant-toggle]');
    test.skip((await toggle.count()) === 0, 'The assistant is off (enable the module and Assistant.enabled).');
    await expect(toggle).toBeVisible();

    return toggle;
}

test('ask the assistant with the keyboard', async ({ page }) => {
    const toggle = await openBubble(page);

    await toggle.focus();
    await page.keyboard.press('Enter');
    const panel = page.locator('[data-assistant-panel]');
    await expect(panel).toBeVisible();
    await expect(page.locator('[data-assistant-input]')).toBeFocused();

    await page.keyboard.type('A quina hora obriu?');
    await page.keyboard.press('Enter');
    const answer = page.locator('.assistant-bot').last();
    await expect(answer).toContainText('dilluns a divendres');
    await expect(answer.locator('a[href*="/contact"]')).toBeVisible();

    await page.keyboard.type('Teniu un drac de companyia?');
    await page.keyboard.press('Enter');
    await expect(page.locator('.assistant-bot').last().locator('a[href*="/contact"]')).toBeVisible();

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
    expect(await axe(page)).toEqual([]);

    await page.keyboard.press('Escape');
    await expect(panel).toBeHidden();
    await expect(toggle).toBeFocused();
    expect(page.cspViolations).toEqual([]);

    await page.screenshot({ path: `writable/e2e/screenshots/${test.info().project.name}/assistant.png` });
});

test('a suggested question answers from the FAQs', async ({ page }) => {
    const toggle = await openBubble(page);
    await toggle.click();

    const suggestion = page.locator('[data-assistant-suggestion]').first();
    await expect(suggestion).toBeVisible();
    const question = (await suggestion.textContent()).trim();
    await suggestion.click();

    await expect(page.locator('.assistant-you').last()).toContainText(question);
    await expect(page.locator('.assistant-bot').last().locator('.assistant-answer')).toBeVisible();
    expect(page.cspViolations).toEqual([]);

    // The open state is remembered for the session only.
    await page.reload({ waitUntil: 'networkidle' });
    await expect(page.locator('[data-assistant-panel]')).toBeVisible();
    await page.locator('[data-assistant-close]').click();
});
