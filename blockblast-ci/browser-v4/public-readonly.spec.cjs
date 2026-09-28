const { test, expect } = require('@playwright/test');
const {
  VIEWPORTS,
  REPRESENTATIVE_ROUTES,
  installReadOnlyGuard,
  observeFirstPartyFailures,
  observePageErrors,
  assertPublicPageInvariants,
} = require('./helpers.cjs');

const baseURL = process.env.E2E_BASE_URL || 'https://blockblast-unblocked.io';

test.describe('@readonly public browser certification', () => {
  test.beforeEach(async ({ page }) => {
    const attempts = await installReadOnlyGuard(page, baseURL);
    page.__sameOriginMutationAttempts = attempts;
    page.__firstPartyFailures = observeFirstPartyFailures(page, baseURL);
    page.__pageErrors = observePageErrors(page);
  });

  test.afterEach(async ({ page }) => {
    expect(page.__sameOriginMutationAttempts || [], 'read-only suite attempted a same-origin mutation').toEqual([]);
    expect(page.__firstPartyFailures || [], 'first-party 5xx response observed').toEqual([]);
    expect(page.__pageErrors || [], 'uncaught page JavaScript error observed').toEqual([]);
  });

  for (const viewport of VIEWPORTS) {
    for (const route of REPRESENTATIVE_ROUTES) {
      test(`@readonly geometry ${viewport.name} ${route}`, async ({ page }) => {
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        await assertPublicPageInvariants(page, baseURL, route);
      });
    }
  }

  test('@readonly desktop search autocomplete and results route', async ({ page }) => {
    // Keep a guard-band above the <=1365px mobile/tablet breakpoint. WebKit can
    // subtract scrollbar width from the layout viewport, so 1366px is too close
    // to the breakpoint for a deterministic cross-browser desktop assertion.
    await page.setViewportSize({ width: 1440, height: 900 });
    await assertPublicPageInvariants(page, baseURL, '/');
    const input = page.locator('#search-input');
    await expect(input).toBeVisible();
    await input.focus();
    await input.fill('block');
    await expect(page.locator('#search-results')).toHaveClass(/active/);
    await expect(page.locator('#search-grid a.search-game-card').first()).toBeVisible();

    await input.press('Enter');
    await page.waitForURL(/\/search\?q=block(?:&|$)/);
    await expect(page.locator('main#main, main').first()).toBeVisible();
  });

  test('@readonly mobile search overlay opens, searches, and closes', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await assertPublicPageInvariants(page, baseURL, '/');
    const toggle = page.locator('#search-toggle-mobile');
    await expect(toggle).toBeVisible();
    await toggle.click();
    const overlay = page.locator('#mobile-search-overlay');
    await expect(overlay).toHaveClass(/active/);
    await expect(overlay).toHaveAttribute('aria-hidden', 'false');
    const input = page.locator('#mobile-search-input');
    await input.fill('block');
    await expect(page.locator('#mobile-search-grid a.search-game-card').first()).toBeVisible();
    await page.locator('#mobile-search-close').click();
    await expect(overlay).not.toHaveClass(/active/);
    await expect(overlay).toHaveAttribute('aria-hidden', 'true');
  });

  test('@readonly game start uses internal embed wrapper without mutation', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 768 });
    await assertPublicPageInvariants(page, baseURL, '/');

    // External provider documents are not part of the first-party certification.
    const origin = new URL(baseURL).origin;
    await page.route('**/*', async (route) => {
      const req = route.request();
      let url;
      try { url = new URL(req.url()); } catch (_) { return route.continue(); }
      if (req.resourceType() === 'document' && url.origin !== origin) {
        return route.abort('blockedbyclient');
      }
      return route.continue();
    });

    const start = page.locator('[data-player-action="start"]').first();
    await expect(start).toBeVisible();
    await start.click();
    const iframe = page.locator('iframe#iframehtml5').first();
    await expect(iframe).toBeAttached();
    await expect(iframe).toHaveAttribute('src', /\.embed(?:\?|$)/);
  });

  test('@readonly theater mode enters and exits without layout mutation persistence', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 768 });
    await assertPublicPageInvariants(page, baseURL, '/');
    const theater = page.locator('[data-player-action="theater"]').first();
    await expect(theater).toBeVisible();
    await theater.click();
    await expect(page.locator('body')).toHaveClass(/theater-mode/);
    await page.keyboard.press('Escape');
    await expect(page.locator('body')).not.toHaveClass(/theater-mode/);
  });

  test('@readonly mobile CSS fullscreen enters and exits', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await assertPublicPageInvariants(page, baseURL, '/');
    const fullscreen = page.locator('[data-player-action="fullscreen"]').first();
    await expect(fullscreen).toBeVisible();
    await fullscreen.click();
    const container = page.locator('.game-iframe-wrapper.frame_fix_mobile').first();
    await expect(container).toBeVisible();
    await expect(page.locator('.mobile-fullscreen-exit').first()).toBeVisible();
    await page.locator('.mobile-fullscreen-exit').first().click();
    await expect(page.locator('.game-iframe-wrapper.frame_fix_mobile')).toHaveCount(0);
  });
});
