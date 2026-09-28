const { test, expect } = require('@playwright/test');

const baseURL = process.env.E2E_BASE_URL || 'https://blockblast-unblocked.io';

test.describe('@pwa read-only PWA contract', () => {
  test('@pwa manifest and service worker endpoints are healthy', async ({ request }) => {
    const manifest = await request.get(new URL('/manifest.json', baseURL).toString());
    expect(manifest.status()).toBe(200);
    expect((manifest.headers()['content-type'] || '')).toMatch(/json/i);
    const manifestJson = await manifest.json();
    expect(manifestJson.name || manifestJson.short_name).toBeTruthy();
    expect(manifestJson.start_url).toBeTruthy();

    const sw = await request.get(new URL('/sw.js', baseURL).toString());
    expect(sw.status()).toBe(200);
    expect((sw.headers()['content-type'] || '')).toMatch(/javascript|text\/plain/i);
    const swText = await sw.text();
    expect(swText).toContain('CACHE_VERSION');
  });

  test('@pwa chromium can register the production service worker', async ({ browserName, browser }) => {
    test.skip(browserName !== 'chromium', 'Registration contract is gated on Chromium for deterministic CI evidence');
    const context = await browser.newContext({ serviceWorkers: 'allow', baseURL });
    const page = await context.newPage();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const supported = await page.evaluate(() => 'serviceWorker' in navigator);
    expect(supported).toBe(true);
    const registration = await page.evaluate(async () => {
      const ready = await Promise.race([
        navigator.serviceWorker.ready,
        new Promise((_, reject) => setTimeout(() => reject(new Error('service worker ready timeout')), 15000)),
      ]);
      return { scope: ready.scope, active: !!ready.active };
    });
    expect(registration.active).toBe(true);
    expect(new URL(registration.scope).origin).toBe(new URL(baseURL).origin);
    await context.close();
  });
});
