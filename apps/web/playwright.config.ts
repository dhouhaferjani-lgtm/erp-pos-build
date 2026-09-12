import { defineConfig, devices } from '@playwright/test'

// An explicit port runs an isolated worktree server and never reuses another checkout.
const port = process.env.PLAYWRIGHT_PORT ?? '5173'
const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? `http://localhost:${port}`

export default defineConfig({
  testDir: './e2e',
  // Live-stack evidence harness: registers a tenant and shells out to psql.
  // Never part of `pnpm test:e2e`; run it via e2e/request-hygiene/pw.config.ts.
  testIgnore: ['**/request-hygiene/**'],
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: 'html',
  use: {
    baseURL,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  webServer: {
    command: `pnpm exec vite --host localhost --port ${port} --strictPort`,
    url: baseURL,
    reuseExistingServer: !process.env.CI && !process.env.PLAYWRIGHT_PORT,
    timeout: 120000,
  },
})
