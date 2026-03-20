import { type Page, expect } from '@playwright/test'

/**
 * Shared helpers for smoke tests against live staging.
 * These tests hit the real API — no mocks.
 */

const SMOKE_EMAIL = process.env['SMOKE_TEST_EMAIL'] || 'smoke@test.otospex.dev'
const SMOKE_PASSWORD = process.env['SMOKE_TEST_PASSWORD'] || 'SmokeTest123!'
const SMOKE_NAME = 'Smoke Test User'

export async function register(page: Page): Promise<void> {
  await page.goto('/register')
  await page.getByLabel(/name/i).first().fill(SMOKE_NAME)
  await page.getByLabel(/email/i).fill(uniqueEmail())
  await page.getByLabel(/^password$/i).fill(SMOKE_PASSWORD)
  await page.getByLabel(/confirm password/i).fill(SMOKE_PASSWORD)
  await page.getByRole('button', { name: /register|sign up|create/i }).click()
  await expect(page).toHaveURL(/dashboard/, { timeout: 15_000 })
}

export async function login(page: Page): Promise<void> {
  await page.goto('/login')
  await page.getByLabel(/email/i).fill(SMOKE_EMAIL)
  await page.getByLabel(/password/i).fill(SMOKE_PASSWORD)
  await page.getByRole('button', { name: /sign in|log in/i }).click()
  await expect(page).toHaveURL(/dashboard/, { timeout: 15_000 })
}

export function uniqueEmail(): string {
  const ts = Date.now()
  return `smoke+${ts}@test.otospex.dev`
}

export function uniqueName(prefix: string): string {
  return `${prefix}-${Date.now().toString(36)}`
}
