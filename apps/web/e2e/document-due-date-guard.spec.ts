import { test, expect } from './fixtures'

/**
 * DEV-QA-008 / DEV-QA-057 — the quote/PO form must not let a due date precede
 * the issue date. This drives the client-side mirror of the backend
 * `after_or_equal:document_date` guard: the Due Date input is bounded by `min`
 * and, on save, RHF surfaces the localized error. (The backend 422 is the
 * authoritative guard and is covered by DocumentDueDateGuardTest.)
 */

test.describe('Quote due-date guard (DEV-QA-008/057)', () => {
  test('blocks a due date that precedes the issue date', async ({ authenticatedPage: page }) => {
    await page.goto('/sales/quotes/new')

    const issue = page.locator('#issue_date')
    const due = page.locator('#due_date')
    await expect(issue).toBeVisible()

    await issue.fill('2026-09-10')
    await due.fill('2026-09-05') // earlier than issue

    // Native guard: the Due Date input is bounded by the issue date.
    await expect(due).toHaveAttribute('min', '2026-09-10')

    // RHF guard: saving surfaces the localized message (Save & Close runs
    // handleSubmit → validate).
    await page.getByRole('button', { name: 'More save options' }).click()
    await page.getByRole('menuitem', { name: 'Save & Close' }).click()

    await expect(page.getByText('Due date cannot be before the issue date')).toBeVisible()
  })

  test('accepts a due date on or after the issue date', async ({ authenticatedPage: page }) => {
    await page.goto('/sales/quotes/new')

    const issue = page.locator('#issue_date')
    const due = page.locator('#due_date')
    await expect(issue).toBeVisible()

    await issue.fill('2026-09-10')
    await due.fill('2026-09-20')

    await page.getByRole('button', { name: 'More save options' }).click()
    await page.getByRole('menuitem', { name: 'Save & Close' }).click()

    // The due-date guard must NOT fire (other required-field errors may still
    // appear, but never this one).
    await expect(page.getByText('Due date cannot be before the issue date')).toHaveCount(0)
  })
})
