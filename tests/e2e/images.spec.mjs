// Responsive images (session 54, D-54-01): the News list and the gallery of the demo page
// "Més seccions" print stored images with `srcset`/`sizes` (ui_image()) and width/height, so at
// phone width the browser downloads a 400/800 px variant instead of the full image, with no
// layout shift; axe, CSP and overflow through checkPage().
import { test, expect, checkPage } from './support.mjs';

test.use({ storageState: { cookies: [], origins: [] } });

/** Scrolls through the page so lazy images load. */
async function loadLazyImages(page) {
    await page.evaluate(async () => {
        for (let y = 0; y < document.body.scrollHeight; y += 300) {
            window.scrollTo(0, y);
            await new Promise((resolve) => setTimeout(resolve, 40));
        }
        window.scrollTo(0, 0);
    });
    await page.waitForLoadState('networkidle');
}

/** The image elements of a selector with their attributes and what the browser chose. */
async function images(page, selector) {
    return page.locator(selector).evaluateAll((nodes) => nodes.map((img) => ({
        src: img.getAttribute('src'),
        srcset: img.getAttribute('srcset') ?? '',
        currentSrc: img.currentSrc,
        width: img.getAttribute('width'),
        height: img.getAttribute('height'),
        complete: img.complete,
    })));
}

/**
 * Layout shift caused by the images or the blocks that hold them (other components, such as a
 * carousel's enhancement, are not this test's business).
 */
async function layoutShift(page, selector) {
    return page.evaluate((selector) => new Promise((resolve) => {
        const images = [...document.querySelectorAll(selector)];
        let total = 0;
        new PerformanceObserver((list) => {
            for (const entry of list.getEntries()) {
                const byImages = entry.sources.some(({ node }) => node && images.some((img) => node === img || node.contains(img) || img.contains(node)));
                if (!entry.hadRecentInput && byImages) total += entry.value;
            }
        }).observe({ type: 'layout-shift', buffered: true });
        setTimeout(() => resolve(total), 300);
    }), selector);
}

for (const [path, selector, name] of [
    ['/news', 'main img.news-card-cover', 'images-news-list'],
    ['/mes-seccions', '[data-component="files/gallery"] img.kp-gallery-image', 'images-gallery'],
]) {
    test(`${path} loads responsive variants`, async ({ page }) => {
        const response = await page.request.get(path);
        test.skip(response.status() === 404, `No demo page ${path}.`);

        await checkPage(page, path, name, { ready: loadLazyImages });
        const list = await images(page, selector);
        test.skip(list.length === 0, `No stored images on ${path} (demo data).`);

        for (const image of list) {
            expect(image.width, 'width attribute (no layout shift)').not.toBeNull();
            expect(image.height, 'height attribute (no layout shift)').not.toBeNull();
            expect(image.srcset, 'srcset of the variants').toMatch(/-w400\.(webp|png|jpg) 400w/);
        }

        const viewport = test.info().project.use.viewport.width;
        const loaded = list.filter((image) => image.complete && image.currentSrc !== '');
        expect(loaded.length).toBeGreaterThan(0);

        if (viewport <= 390) {
            // A phone never downloads the full image.
            for (const image of loaded) {
                expect(image.currentSrc, 'a variant at phone width').toMatch(/-w(400|800)\.(webp|png|jpg)$/);
            }
        }

        expect(await layoutShift(page, selector), 'no layout shift from the images').toBeLessThan(0.1);
    });
}
