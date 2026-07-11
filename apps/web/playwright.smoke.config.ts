import { defineConfig, devices } from '@playwright/test'

/**
 * Playwright config for smoke tests against a live staging environment.
 * No local webServer — tests run against STAGING_URL.
 */
export default defineConfig({
  testDir: './e2e/smoke',
  // Playwright's default testMatch only matches *.spec.ts / *.test.ts, so
  // without this override every *.smoke.ts file (the repo's live-test naming
  // convention) is silently invisible to this config — pre-existing gap,
  // fixed here as part of moving treasury-spine onto the smoke convention.
  testMatch: /.*\.smoke\.ts$/,
  fullyParallel: false,
  retries: 1,
  workers: 1,
  reporter: [['html', { open: 'never' }], ['list']],
  timeout: 60_000,
  expect: { timeout: 10_000 },
  use: {
    baseURL: process.env['STAGING_URL'] || 'https://erp.otospex.dev',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'on-first-retry',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
})
