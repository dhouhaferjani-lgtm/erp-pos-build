import { test, expect } from './fixtures'

// ---------------------------------------------------------------------------
// Test suite: Composite item delete (BG7 + UB3)
//
// HOW TESTS ARE SPLIT
// -------------------
//
// Test 1 — "cancel in ConfirmDialog leaves item in the list"
//   UI-only: does NOT require a live backend. Runs in CI with the composite-
//   items list mocked via page.route(). The /active-menu endpoint is NOT
//   touched by this test.
//
// Test 2 — "delete from list page removes item from /active-menu"
//   LIVE-BACKEND REQUIRED. Guarded by the E2E_LIVE_BACKEND env var.
//   Asserts that after a real DELETE request the backend's /active-menu
//   query no longer returns the deleted item — this is the cross-surface
//   assertion that cannot be made meaningful with a mocked response.
//
//   Prerequisites to run:
//     export E2E_LIVE_BACKEND=1
//     # API server listening at http://localhost:8080
//     # Web dev server listening at http://localhost:5173
//     # Tenant DB seeded with at least one composite item (e.g. via
//     #   php artisan db:seed --class=CompositeItemSeeder)
//     pnpm exec playwright test e2e/composite-item-delete.spec.ts
//
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Mock data — used only by the UI-only cancel test
// ---------------------------------------------------------------------------

const ITEM_ID = 'ci-uuid-0001-0000-0000-000000000001'
const ITEM_NAME = 'Burger Spécial'

const mockCompositeItems = [
  {
    id: ITEM_ID,
    code: 'BURGER-01',
    name: ITEM_NAME,
    category_name: 'Burgers',
    base_price: '12.5000',
    production_type: 'made_to_order',
    is_active: true,
    created_at: '2025-01-01T00:00:00Z',
    updated_at: '2025-01-01T00:00:00Z',
  },
  {
    id: 'ci-uuid-0002-0000-0000-000000000002',
    code: 'PIZZA-01',
    name: 'Pizza Margherita',
    category_name: 'Pizzas',
    base_price: '9.0000',
    production_type: 'made_to_order',
    is_active: true,
    created_at: '2025-01-01T00:00:00Z',
    updated_at: '2025-01-01T00:00:00Z',
  },
]

// ---------------------------------------------------------------------------
// Describe block 1: UI-only tests (no live backend needed)
// ---------------------------------------------------------------------------

test.describe('Composite item delete — UI-only (cancel flow)', () => {
  test.beforeEach(async ({ authenticatedPage: page }) => {
    // Mock composite-items list (paginated)
    await page.route('**/api/v1/composite-items**', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: mockCompositeItems,
          meta: { current_page: 1, last_page: 1, total: mockCompositeItems.length, per_page: 25 },
        }),
      })
    })

    // Mock categories used in any related selects on the page
    await page.route('**/api/v1/categories**', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [] }),
      })
    })
  })

  test('cancel in ConfirmDialog leaves item in the list', async ({ authenticatedPage: page }) => {
    await page.goto('/catalog/composite-items')

    const firstRow = page.locator('tbody tr').first()
    await expect(firstRow.locator('td').nth(1)).toHaveText(ITEM_NAME)

    // Open the dialog
    await firstRow.getByRole('button', { name: /delete/i }).click()
    const confirmDialog = page.getByRole('dialog')
    await expect(confirmDialog).toBeVisible()

    // Cancel
    await page.getByRole('button', { name: /cancel/i }).click()

    // Dialog must close and item must still be in the table
    await expect(page.locator('tbody')).toContainText(ITEM_NAME)
  })
})

// ---------------------------------------------------------------------------
// Describe block 2: live-backend tests (cross-surface assertion)
// ---------------------------------------------------------------------------

test.describe('Composite item delete — live backend required (BG7 + UB3)', () => {
  test('delete from list page removes item from /active-menu', async ({ authenticatedPage: page }) => {
    test.skip(
      !process.env['E2E_LIVE_BACKEND'],
      'Requires live API (localhost:8080) + web server (localhost:5173) + seeded composite item. Set E2E_LIVE_BACKEND=1 to run.',
    )

    // -----------------------------------------------------------------------
    // Pre-condition: /active-menu must include at least one item before delete.
    // -----------------------------------------------------------------------
    const beforeResponse = await page.request.get('/api/v1/active-menu', {
      headers: { Accept: 'application/json' },
    })
    expect(beforeResponse.ok(), '/active-menu must be reachable before delete').toBeTruthy()

    const beforePayload = (await beforeResponse.json()) as {
      data: {
        categories: Array<{
          items: Array<{ name: string; sellable_id: string }>
        }>
      }
    }

    const allItemsBefore = (beforePayload.data?.categories ?? []).flatMap(
      (c) => c.items ?? [],
    )
    expect(
      allItemsBefore.length,
      'Seed at least one composite item via CompositeItemSeeder before running this test',
    ).toBeGreaterThan(0)

    // Pick the first item visible in /active-menu to delete via the UI.
    const targetItem = allItemsBefore[0]
    const targetName = targetItem.name

    // -----------------------------------------------------------------------
    // Navigate to composite-items list and find the row for targetItem.
    // -----------------------------------------------------------------------
    await page.goto('/catalog/composite-items')

    // Wait for the table to render
    await expect(page.locator('tbody tr').first()).toBeVisible({ timeout: 10_000 })

    // Locate the row that matches the target item name
    const targetRow = page.locator('tbody tr').filter({ hasText: targetName })
    await expect(targetRow).toBeVisible({ timeout: 5_000 })

    // Click the delete (trash) icon on that row
    await targetRow.getByRole('button', { name: /delete/i }).click()

    // ConfirmDialog must appear — click the confirm button
    const confirmDialog = page.getByRole('dialog')
    await expect(confirmDialog).toBeVisible()
    await page.getByRole('button', { name: /confirm/i }).click()

    // After confirmation the row should disappear from the table body
    await expect(page.locator('tbody')).not.toContainText(targetName, { timeout: 5_000 })

    // -----------------------------------------------------------------------
    // Cross-surface check: /active-menu must no longer contain the deleted item.
    // This hits the REAL backend — no mock — so it validates the tombstone query.
    // -----------------------------------------------------------------------
    const afterResponse = await page.request.get('/api/v1/active-menu', {
      headers: { Accept: 'application/json' },
    })
    expect(afterResponse.ok(), '/active-menu must be reachable after delete').toBeTruthy()

    const afterPayload = (await afterResponse.json()) as {
      data: {
        categories: Array<{
          items: Array<{ name: string }>
        }>
      }
    }

    const itemNamesAfter = (afterPayload.data?.categories ?? []).flatMap(
      (c) => (c.items ?? []).map((i) => i.name),
    )
    expect(itemNamesAfter, `"${targetName}" should have been removed from /active-menu by the tombstone`).not.toContain(targetName)
  })
})
