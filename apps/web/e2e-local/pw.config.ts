import { defineConfig, devices } from '@playwright/test'
export default defineConfig({
  testDir: '.',
  testMatch: /wave2-.*\.spec\.ts$/,
  workers: 1,
  retries: 0,
  timeout: 300000,
  expect: { timeout: 30000 },
  reporter: [['list']],
  outputDir: '../test-results-local',
  use: { baseURL: 'http://localhost:5178', trace: 'retain-on-failure', screenshot: 'only-on-failure' },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
