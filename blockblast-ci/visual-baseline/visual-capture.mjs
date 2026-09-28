import { chromium } from 'playwright';
import { mkdir, writeFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';

const baseURL = 'https://blockblast-unblocked.io';
const routes = [
  { name: 'home', path: '/' },
  { name: 'games-block', path: '/games/block' },
  { name: 'minesweeper', path: '/minesweeper' },
];
const viewports = [
  { name: '375x812', width: 375, height: 812 },
  { name: '390x844', width: 390, height: 844 },
  { name: '430x932', width: 430, height: 932 },
  { name: '768x1024', width: 768, height: 1024 },
  { name: '1024x768', width: 1024, height: 768 },
  { name: '1366x768', width: 1366, height: 768 },
  { name: '1440x900', width: 1440, height: 900 },
  { name: '1920x1080', width: 1920, height: 1080 },
];

const outDir = 'blockblast-ci/visual-baseline/output';
await mkdir(outDir, { recursive: true });
const browser = await chromium.launch({ headless: true });
const evidence = [];

try {
  for (const vp of viewports) {
    const context = await browser.newContext({
      viewport: { width: vp.width, height: vp.height },
      locale: 'en-US',
      timezoneId: 'Asia/Bangkok',
      colorScheme: 'light',
      reducedMotion: 'reduce',
      serviceWorkers: 'block',
    });
    const page = await context.newPage();

    await page.route('**/*', async route => {
      const req = route.request();
      let url;
      try { url = new URL(req.url()); } catch { return route.continue(); }
      if (req.method() === 'POST' && url.hostname === 'blockblast-unblocked.io' && url.pathname === '/cdn-cgi/rum') {
        return route.abort('blockedbyclient');
      }
      return route.continue();
    });

    for (const route of routes) {
      const response = await page.goto(new URL(route.path, baseURL).toString(), { waitUntil: 'domcontentloaded', timeout: 30000 });
      if (!response || response.status() >= 400) {
        throw new Error(`navigation failed ${route.path}: ${response?.status()}`);
      }
      await page.addStyleTag({ content: `
        *,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important;scroll-behavior:auto!important}
      `});
      await page.evaluate(async () => {
        if (document.fonts?.ready) { try { await document.fonts.ready; } catch {} }
        window.scrollTo(0,0);
      });
      await page.waitForTimeout(500);
      const file = `${outDir}/${route.name}--${vp.name}.png`;
      await page.screenshot({ path: file, fullPage: false });
      const bytes = await readFile(file);
      evidence.push({
        route: route.path,
        viewport: vp,
        file: file.replace(outDir + '/', ''),
        sha256: createHash('sha256').update(bytes).digest('hex'),
        final_url: page.url(),
        title: await page.title(),
      });
    }
    await context.close();
  }
} finally {
  await browser.close();
}

await writeFile(`${outDir}/manifest.json`, JSON.stringify({
  captured_at: new Date().toISOString(),
  base_url: baseURL,
  screenshot_count: evidence.length,
  approval_state: 'CANDIDATE_NOT_APPROVED',
  note: 'Automation may capture candidates but must not self-approve visual baselines.',
  screenshots: evidence,
}, null, 2) + '\n');

console.log(`visual-baseline-candidates: PASS screenshots=${evidence.length}`);
