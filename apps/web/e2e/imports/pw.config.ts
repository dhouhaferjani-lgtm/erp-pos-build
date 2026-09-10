import { defineConfig, devices } from '@playwright/test'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
const __dirname = dirname(fileURLToPath(import.meta.url))
export default defineConfig({
  testDir: '.', testMatch: 'imp1-history-export.spec.ts', workers: 1, retries: 0,
  timeout: 300_000, expect: { timeout: 20_000 },
  outputDir: resolve(__dirname, '../../../../docs/superpowers/reviews/2026-09-10-imp1-evidence/browser'),
  reporter: 'list',
  use: { baseURL: 'http://localhost:5176', actionTimeout: 30_000, trace: 'retain-on-failure', screenshot: 'only-on-failure' },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
