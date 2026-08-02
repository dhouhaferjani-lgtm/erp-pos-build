import { test, expect } from '@playwright/test'
import { loginAsRole, logout, apiRequest, ROLE_CREDENTIALS } from './helpers'

/**
 * MONEY TEST CAMPAIGN — login / session basics (agent W1a).
 *
 * These cases are NOT in docs/qa/2026-08-01-money-test-plan.md (it has no dedicated
 * auth/session surface code) — they were assigned directly by the campaign dispatch as a
 * prerequisite sanity layer under every other surface. IDs are informal: AUTH-01..08.
 * Targets the live local stack, tenant demo-pharmacy-tn.
 */

test.describe('AUTH — login / session basics', () => {
  test.setTimeout(60_000) // local single-process dev backend, shared with sibling agents, can be slow

  test('AUTH-01 HAPPY (P1): login page renders the sign-in form', async ({ page }) => {
    await page.goto('/login')
    await expect(page.getByLabel(/email address/i)).toBeVisible()
    await expect(page.getByLabel(/^password$/i)).toBeVisible()
    await expect(page.getByRole('button', { name: /sign in/i })).toBeVisible()
  })

  test('AUTH-02 HAPPY (P0): owner login succeeds and reaches the authenticated shell', async ({ page }) => {
    await loginAsRole(page, 'owner')
    await expect(page).not.toHaveURL(/\/login/)
    await expect(page.getByRole('button', { name: /profile/i })).toBeVisible()
  })

  test('AUTH-03 HAPPY (P0): manager login succeeds and reaches the authenticated shell', async ({ page }) => {
    await loginAsRole(page, 'manager')
    await expect(page).not.toHaveURL(/\/login/)
    await expect(page.getByRole('button', { name: /profile/i })).toBeVisible()
  })

  test('AUTH-04 HAPPY (P0): cashier login succeeds and reaches the authenticated shell', async ({ page }) => {
    await loginAsRole(page, 'cashier')
    await expect(page).not.toHaveURL(/\/login/)
    await expect(page.getByRole('button', { name: /profile/i })).toBeVisible()
  })

  test('AUTH-05 EDGE (P0): wrong password is rejected, stays on /login, no auth state persisted', async ({ page }) => {
    await page.goto('/login')
    await page.getByLabel(/email address/i).fill(ROLE_CREDENTIALS.cashier.email)
    await page.getByLabel(/^password$/i).fill('definitely-wrong-password')
    await page.getByRole('button', { name: /sign in/i }).click()
    // Must NOT navigate away from /login
    await page.waitForTimeout(1500)
    await expect(page).toHaveURL(/\/login/)
    // An error message must be shown
    await expect(page.locator('body')).toContainText(/invalid|incorrect|credentials|failed/i)
    // No auth state should be persisted
    const authState = await page.evaluate(() => localStorage.getItem('autoerp-auth'))
    if (authState) {
      const parsed = JSON.parse(authState) as { state?: { isAuthenticated?: boolean } }
      expect(parsed.state?.isAuthenticated).not.toBe(true)
    }
  })

  test('AUTH-06 EDGE (P0): unauthenticated access to a money route redirects to /login', async ({ page }) => {
    // Fresh context — no prior login in this test.
    await page.goto('/treasury/payments')
    await expect(page).toHaveURL(/\/login/, { timeout: 10_000 })
  })

  test('AUTH-07 EDGE (P1): session survives a full page reload', async ({ page }) => {
    await loginAsRole(page, 'owner')
    await page.reload()
    await expect(page).not.toHaveURL(/\/login/)
    await expect(page.getByRole('button', { name: /profile/i })).toBeVisible({ timeout: 15_000 })
  })

  test('AUTH-08 EDGE (P0): logout clears session; protected route requires login again', async ({ page }) => {
    await loginAsRole(page, 'owner')
    // Confirm the API session is genuinely usable before logout.
    const before = await apiRequest(page, 'GET', '/auth/me')
    expect(before.status).toBe(200)

    await logout(page)
    await expect(page).toHaveURL(/\/login/)

    // Direct navigation to a protected route must bounce back to /login.
    await page.goto('/treasury/payments')
    await expect(page).toHaveURL(/\/login/, { timeout: 10_000 })

    // And the API session itself must be gone (401/419), not just the client route guard.
    const after = await apiRequest(page, 'GET', '/auth/me')
    expect(after.status).not.toBe(200)
  })

  test('AUTH-09 EDGE (P1, defect discovery): TopBar "Sign out" is unreachable by a real mouse click', async ({ page }) => {
    // Documents the click-interception defect described in helpers.ts `logout()`.
    // Not an MTP case — found while implementing AUTH-08. Kept as its own case so
    // the defect has an isolated, re-runnable repro independent of the API-level
    // logout workaround used elsewhere in this file.
    await loginAsRole(page, 'owner')
    await page.getByRole('button', { name: /profile/i }).click()
    const signOut = page.getByRole('button', { name: /sign out/i })
    await expect(signOut).toBeVisible()
    // A plain, unforced click is what a real user does. Expected (per the app's
    // intent): navigates to /login. Actual: times out — pointer events on the
    // overlapping region are captured by <main>, not the dropdown button.
    await expect(async () => {
      await signOut.click({ timeout: 3_000 })
      await expect(page).toHaveURL(/\/login/, { timeout: 2_000 })
    }).rejects.toThrow()
    // Prove the page never navigated — the click was swallowed, not merely slow.
    await expect(page).toHaveURL(/\/reports|\/dashboard/)
  })
})
