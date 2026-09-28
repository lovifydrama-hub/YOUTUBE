const { expect } = require('@playwright/test');

const PRODUCTION_HOST = 'blockblast-unblocked.io';
const VIEWPORTS = [
  { name: '375x812', width: 375, height: 812 },
  { name: '390x844', width: 390, height: 844 },
  { name: '430x932', width: 430, height: 932 },
  { name: '768x1024', width: 768, height: 1024 },
  { name: '1024x768', width: 1024, height: 768 },
  { name: '1366x768', width: 1366, height: 768 },
  { name: '1440x900', width: 1440, height: 900 },
  { name: '1920x1080', width: 1920, height: 1080 },
];
const REPRESENTATIVE_ROUTES = ['/', '/games/block', '/minesweeper'];

function absoluteBase(baseURL) {
  return new URL(baseURL || 'https://blockblast-unblocked.io');
}

async function installReadOnlyGuard(page, baseURL) {
  const base = absoluteBase(baseURL);
  const attemptedMutations = [];
  await page.route('**/*', async (route) => {
    const req = route.request();
    let url;
    try { url = new URL(req.url()); } catch (_) { return route.continue(); }
    const method = req.method().toUpperCase();
    const sameOrigin = url.origin === base.origin;
    if (sameOrigin && !['GET', 'HEAD', 'OPTIONS'].includes(method)) {
      // Cloudflare Browser Insights/RUM is edge-injected telemetry, not an
      // application mutation. Keep certification read-only by aborting it, but
      // do not misclassify this known telemetry endpoint as app state.
      if (method === 'POST' && url.pathname === '/cdn-cgi/rum') {
        return route.abort('blockedbyclient');
      }
      attemptedMutations.push(`${method} ${url.pathname}`);
      return route.abort('blockedbyclient');
    }
    return route.continue();
  });
  return attemptedMutations;
}

function observeFirstPartyFailures(page, baseURL) {
  const base = absoluteBase(baseURL);
  const failures = [];
  page.on('response', (response) => {
    let url;
    try { url = new URL(response.url()); } catch (_) { return; }
    if (url.origin === base.origin && response.status() >= 500) {
      failures.push(`${response.status()} ${url.pathname}`);
    }
  });
  return failures;
}

function observePageErrors(page) {
  const errors = [];
  page.on('pageerror', (error) => errors.push(String(error && error.message ? error.message : error)));
  return errors;
}

async function stabilizeForGeometry(page) {
  await page.addStyleTag({ content: `
    *, *::before, *::after {
      animation-duration: 0s !important;
      animation-delay: 0s !important;
      transition-duration: 0s !important;
      scroll-behavior: auto !important;
      caret-color: transparent !important;
    }
  `});
  await page.evaluate(async () => {
    if (document.fonts && document.fonts.ready) {
      try { await document.fonts.ready; } catch (_) {}
    }
  });
}

async function assertPublicPageInvariants(page, baseURL, route) {
  const response = await page.goto(route, { waitUntil: 'domcontentloaded' });
  expect(response, `missing navigation response for ${route}`).not.toBeNull();
  expect(response.status(), `${route} should not be an error`).toBeLessThan(400);
  await stabilizeForGeometry(page);

  await expect(page.locator('main#main, main').first()).toBeVisible();
  await expect(page.locator('h1')).toHaveCount(1);
  await expect(page.locator('link[rel="canonical"]')).toHaveCount(1);

  const canonical = await page.locator('link[rel="canonical"]').getAttribute('href');
  expect(canonical).toBeTruthy();
  const base = absoluteBase(baseURL);
  expect(new URL(canonical, base).host).toBe(base.host);

  const geometry = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    bodyScrollWidth: document.body ? document.body.scrollWidth : 0,
  }));
  const widest = Math.max(geometry.scrollWidth, geometry.bodyScrollWidth);
  expect(widest, `horizontal overflow on ${route}`).toBeLessThanOrEqual(geometry.viewport + 1);
}

function assertMutationTargetIsSafe(baseURL) {
  const base = absoluteBase(baseURL);
  if (base.hostname === PRODUCTION_HOST || base.hostname === `www.${PRODUCTION_HOST}`) {
    throw new Error('Mutation E2E is forbidden against production host');
  }
  if (process.env.ALLOW_E2E_MUTATIONS !== '1') {
    throw new Error('Mutation E2E requires ALLOW_E2E_MUTATIONS=1');
  }
  return base;
}

module.exports = {
  PRODUCTION_HOST,
  VIEWPORTS,
  REPRESENTATIVE_ROUTES,
  absoluteBase,
  installReadOnlyGuard,
  observeFirstPartyFailures,
  observePageErrors,
  stabilizeForGeometry,
  assertPublicPageInvariants,
  assertMutationTargetIsSafe,
};
