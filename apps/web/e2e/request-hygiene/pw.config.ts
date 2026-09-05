import { defineConfig, devices } from '@playwright/test'

/**
 * Request-hygiene Phase A browser-evidence config.
 *
 * Runs against the ALREADY RUNNING local stack (no `webServer` block — the
 * orchestrator owns the API on :8011 and vite on :5174 and this config must
 * never restart them).
 *
 *   cd apps/web && ./node_modules/.bin/playwright test --config e2e/request-hygiene/pw.config.ts
 *
 * `outputDir` deliberately points OUTSIDE apps/web: vite watches the web root
 * and a trace/screenshot written under it full-reloads the SPA mid-leg.
 */
const SCRATCH = process.env['RH_OUTPUT_DIR']
  ?? '/private/tmp/claude-501/-Users-houssamr-Projects-syneriva-apps-erp/e659976d-e163-429c-8afe-6aaef9cec86d/scratchpad/rh-browser/test-results'

export default defineConfig({
  testDir: '.',
  testMatch: /.*\.spec\.ts$/,
  fullyParallel: false,
  forbidOnly: false,
  retries: 0,
  workers: 1,
  reporter: [['list']],
  timeout: 300_000,
  expect: { timeout: 30_000 },
  outputDir: SCRATCH,
  use: {
    baseURL: process.env['CAMPAIGN_WEB_URL'] ?? 'http://localhost:5174',
    // Without these, a locator action on an element that never appears waits for the
    // whole test timeout instead of failing fast.
    actionTimeout: 45_000,
    navigationTimeout: 90_000,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
