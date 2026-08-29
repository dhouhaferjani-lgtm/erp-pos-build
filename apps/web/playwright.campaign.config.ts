import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
  testDir: './e2e/campaign',
  testMatch: /.*\.campaign\.ts$/,
  fullyParallel: false,
  retries: 0,
  workers: 1,
  reporter: [
    ['html', { open: 'never' }],
    ['list'],
    ['json', { outputFile: 'test-results/campaign-report.json' }],
  ],
  timeout: 90_000,
  expect: { timeout: 15_000 },
  use: {
    baseURL: process.env['CAMPAIGN_WEB_URL'] || 'http://localhost:5173',
    trace: 'on',
    screenshot: 'on',
    video: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
})
