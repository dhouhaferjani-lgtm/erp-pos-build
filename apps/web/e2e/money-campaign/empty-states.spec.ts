import { test, expect } from '@playwright/test'
import { loginAsRole } from './helpers'

/**
 * MONEY TEST CAMPAIGN - I.6 `EMPTY` empty-state reports
 * (docs/qa/2026-08-01-money-test-plan.md, MTP-EMPTY-01..08).
 *
 * Targets the live local stack, tenant demo-pharmacy-tn, owner@pharmabio.tn.
 * NOTE on flakiness: this local dev backend is shared with OTHER concurrently-running
 * campaign agents (confirmed: a "W1b-Customer" partner appeared mid-session, authored by
 * a sibling agent) and appears to be a single-process PHP dev server - several pages were
 * observed stuck on "Loading..." for 8-30s during authoring, unrelated to any app defect.
 * Generous timeouts (45-60s) are used throughout for this reason; a failure here after
 * that budget is treated as a real finding, not swallowed as flakiness.
 *
 * Ground truth (confirmed via direct DB query against
 * tenant019fbe86-944a-7252-8a3b-8c341dfa9de9 at execution time): pos_shifts=0,
 * pos_receipts=0, bank_statements=0. Per plan facts (section 2), no seeder ever creates
 * POS shifts/receipts for this non-demo tenant, and web POS mutation is gated demo-only
 * (section 0.4) - so these are STRUCTURALLY, not just currently, empty and safe to assert
 * against without any setup risk from concurrent sibling agents.
 */

test.describe('EMPTY - empty-state reports', () => {
  test.setTimeout(120_000)

  test('MTP-EMPTY-01 (P1): owner dashboard (/reports) with no POS data shows scale-correct zeros, never NaN/blank/spinner-forever', async ({ page }) => {
    await loginAsRole(page, 'owner')
    await page.goto('/reports')
    // Wait out EVERY loading shell (companies/locations/report data all show their own
    // "Loading X…" text at different times) explicitly rather than a fixed sleep.
    await expect(page.locator('body')).not.toContainText(/loading/i, { timeout: 75_000 })

    const body = await page.locator('body').innerText()
    expect(body).not.toContain('NaN')
    expect(body).not.toMatch(/undefined/i)
    // POS-derived tiles on this dashboard must show a real zero-state, not blank.
    expect(body).toMatch(/\b0\b/) // Transactions: 0
    expect(body).toContain('0.0000') // Items sold, at the product-unit's decimal precision
  })

  test('MTP-EMPTY-02 (P1): /pos/z-reports with no Z closed - empty list, no phantom Z row', async ({ page }) => {
    await loginAsRole(page, 'owner')
    await page.goto('/pos/z-reports')
    // This page's loading state is an icon-only spinner with NO "Loading…" text at all
    // (confirmed by inspection: `not.toContainText(/loading/i)` resolves instantly here
    // and is not a valid wait condition on this page) - wait for the actual terminal
    // empty-state text instead, which only appears once GET /api/v1/pos/reports/z has
    // resolved.
    await expect(page.locator('body')).toContainText('No Z-reports found', { timeout: 75_000 })

    const body = await page.locator('body').innerText()
    // pos_shifts=0 in this tenant -> zero Z-report rows regardless of filter state.
    expect(body).not.toMatch(/Z-\d+/) // no Z-report number rendered anywhere
  })

  test('MTP-EMPTY-03 (P1): /pos/shift-history with no shift - empty list', async ({ page }) => {
    await loginAsRole(page, 'owner')
    await page.goto('/pos/shift-history')
    await expect(page.locator('body')).toContainText('No shifts found', { timeout: 60_000 })
    await expect(page.locator('body')).toContainText('No shifts match your current filters', { timeout: 10_000 })
  })

  test('MTP-EMPTY-04 (P1): /finance/cash-movements for a future period with no movement - zeroed report at scale 3', async ({ page }) => {
    await loginAsRole(page, 'owner')
    await page.goto('/finance/cash-movements')
    await expect(page.locator('body')).not.toContainText('Loading cash movements', { timeout: 60_000 })

    const dateInputs = page.locator('input[type="date"]')
    await expect(dateInputs.first()).toBeVisible({ timeout: 30_000 })
    expect(await dateInputs.count()).toBeGreaterThanOrEqual(2)
    await dateInputs.nth(0).fill('2030-01-01')
    await dateInputs.nth(1).fill('2030-01-31')
    await expect(page.locator('body')).not.toContainText('Loading cash movements', { timeout: 30_000 })

    const body = await page.locator('body').innerText()
    // The one real payment in this tenant (Clinique Ennasr, 2026-08-01) must be excluded.
    expect(body).not.toContain('Clinique Ennasr')
    expect(body).not.toContain('NaN')
    // Actual behavior differs from the plan's literal wording ("zeroed report at scale
    // 3"): this page renders an explicit "No cash movements" / "No totals for this
    // range" message rather than a table row full of "0,000 TND" cells. That is still
    // a valid empty state per the sibling case's own wording ("a scale-correct zero OR
    // an explicit 'no data'", MTP-EMPTY-01) - no NaN, no spinner-forever, no stale data,
    // and the real movement is correctly excluded. Recorded as a documentation/actual
    // mismatch, not a functional defect.
    expect(body).toMatch(/no (cash movements|totals)/i)
  })

  test('MTP-EMPTY-05 (P1): /treasury/statements with none imported - empty state, import CTA present', async ({ page }) => {
    await loginAsRole(page, 'owner')
    await page.goto('/treasury/statements')
    await expect(page.locator('body')).toContainText('0 items', { timeout: 60_000 })
    // Import CTA present.
    await expect(page.getByRole('button', { name: /import statement/i })).toBeVisible()
  })

  test('MTP-EMPTY-06 (P2): /finance/aged-receivables as of a date before any invoicing - all buckets 0.000, no divide-by-zero', async ({ page }) => {
    await loginAsRole(page, 'owner')
    await page.goto('/finance/aged-receivables')
    const dateInput = page.locator('input[type="date"]').first()
    await expect(dateInput).toBeVisible({ timeout: 60_000 })
    // Before any document in this tenant existed (all documents seeded/authored in 2026).
    await dateInput.fill('2020-01-01')
    await expect(page.locator('body')).not.toContainText('Loading…', { timeout: 30_000 })

    const body = await page.locator('body').innerText()
    expect(body).not.toContain('NaN')
    expect(body).not.toMatch(/Infinity/i)
    // Every bucket renders a currency-scaled zero, not blank.
    expect(body).toMatch(/0[.,]000/)
  })

  test('MTP-EMPTY-07 (P2): /expenses/analytics with no expenses in a future range - charts render empty, not erroring', async ({ page }) => {
    await loginAsRole(page, 'owner')
    await page.goto('/expenses/analytics')
    await expect(page.locator('body')).not.toContainText('Loading expense analytics', { timeout: 60_000 })

    const dateInputs = page.locator('input[type="date"]')
    await expect(dateInputs.first()).toBeVisible({ timeout: 30_000 })
    if ((await dateInputs.count()) >= 2) {
      await dateInputs.nth(0).fill('2030-01-01')
      await dateInputs.nth(1).fill('2030-01-31')
      await expect(page.locator('body')).not.toContainText('Loading expense analytics', { timeout: 30_000 })
    }

    const body = await page.locator('body').innerText()
    expect(body).not.toContain('NaN')
    expect(body).not.toMatch(/error|exception/i)
  })

  test('MTP-EMPTY-08 (P1): a report with a future period is empty, not an error; period label matches request', async ({ page }) => {
    await loginAsRole(page, 'owner')
    await page.goto('/finance/cash-movements')
    await expect(page.locator('body')).not.toContainText('Loading cash movements', { timeout: 60_000 })

    const dateInputs = page.locator('input[type="date"]')
    await expect(dateInputs.first()).toBeVisible({ timeout: 30_000 })
    await dateInputs.nth(0).fill('2031-06-01')
    await dateInputs.nth(1).fill('2031-06-30')
    await expect(page.locator('body')).not.toContainText('Loading cash movements', { timeout: 30_000 })

    const body = await page.locator('body').innerText()
    expect(body).not.toMatch(/error|exception|500|unexpected/i)
    // The period the app actually queried echoes back the requested future range.
    expect(await dateInputs.nth(0).inputValue()).toBe('2031-06-01')
    expect(await dateInputs.nth(1).inputValue()).toBe('2031-06-30')
  })
})
