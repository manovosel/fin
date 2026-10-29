const { defineConfig, devices } = require('@playwright/test');
const fs = require('node:fs');

const configuredChromium = process.env.PLAYWRIGHT_CHROMIUM_PATH;
const systemChromium = '/usr/bin/chromium';
const executablePath = configuredChromium || (fs.existsSync(systemChromium) ? systemChromium : undefined);

module.exports = defineConfig({
  testDir: './tests/browser',
  testIgnore: ['**/debug-*.spec.js', '**/*.debug.js'],
  fullyParallel: false,
  workers: 1,
  retries: 0,
  expect: { timeout: 10000 },
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    headless: true,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    launchOptions: executablePath ? { executablePath } : {},
  },
  projects: [
    { name: 'desktop-chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'mobile-chromium', grep: /@responsive/, use: { ...devices['Pixel 5'] } },
  ],
});
