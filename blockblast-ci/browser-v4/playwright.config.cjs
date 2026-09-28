const { defineConfig } = require('@playwright/test');

const baseURL = process.env.E2E_BASE_URL || 'https://blockblast-unblocked.io';

module.exports = defineConfig({
  testDir: __dirname,
  testMatch: ['**/*.spec.cjs'],
  outputDir: '../../test-results/playwright',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  timeout: 45_000,
  expect: { timeout: 8_000 },
  reporter: [
    ['list'],
    ['html', { outputFolder: '../../test-results/playwright-report', open: 'never' }],
  ],
  snapshotPathTemplate: '{testDir}/snapshots/{projectName}/{testFilePath}/{arg}{ext}',
  use: {
    baseURL,
    headless: true,
    locale: 'en-US',
    timezoneId: 'Asia/Bangkok',
    colorScheme: 'light',
    reducedMotion: 'reduce',
    serviceWorkers: 'block',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    actionTimeout: 10_000,
    navigationTimeout: 30_000,
  },
  projects: [
    { name: 'chromium', use: { browserName: 'chromium' } },
    { name: 'firefox', use: { browserName: 'firefox' } },
    { name: 'webkit', use: { browserName: 'webkit' } },
  ],
});
