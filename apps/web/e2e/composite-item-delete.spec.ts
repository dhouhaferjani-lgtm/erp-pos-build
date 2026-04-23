import { test, expect } from './fixtures'

// ---------------------------------------------------------------------------
// Mock data
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

// active-menu response that includes the item to be deleted
const mockActiveMenuWithItem = {
  id: 'menu-uuid-0001-0000-0000-000000000001',
  name: 'Menu Principal',
  description: null,
  is_default: true,
  categories: [
    {
      id: 'cat-uuid-0001-0000-0000-000000000001',
      menu_id: 'menu-uuid-0001-0000-0000-000000000001',
      name: 'Burgers',
      description: null,
      icon: null,
      display_order: 1,
      is_active: true,
      items: [
        {
          id: 'pivot-uuid-0001',
          sellable_id: ITEM_ID,
          sellable_type: 'composite_item',
          name: ITEM_NAME,
          code: 'BURGER-01',
          base_price: '12.5000',
          override_price: null,
          effective_price: '12.5000',
          tax_rate: null,
          display_order: 1,
          is_available: true,
          image_url: null,
          modifier_groups: [],
        },
      ],
      created_at: '2025-01-01T00:00:00Z',
      updated_at: null,
    },
  ],
}

// active-menu response after deletion — the item is gone
const mockActiveMenuWithoutItem = {
  ...mockActiveMenuWithItem,
  categories: [
    {
      ...mockActiveMenuWithItem.categories[0],
      items: [],
    },
  ],
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test.describe('Composite item delete (BG7 + UB3)', () => {
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

  // ---------------------------------------------------------------------------
  // Test 1: delete row removes item from list and from /active-menu
  // ---------------------------------------------------------------------------
  test('delete from list page removes item from /active-menu', async ({ authenticatedPage: page }) => {
    // Set up active-menu mock — first call returns item present, subsequent calls
    // return the post-delete state (simulating the backend tombstone taking effect).
    let activeMenuCallCount = 0
    await page.route('**/api/v1/active-menu**', (route) => {
      activeMenuCallCount++
      const payload = activeMenuCallCount === 1 ? mockActiveMenuWithItem : mockActiveMenuWithoutItem
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: payload }),
      })
    })

    // Mock the DELETE endpoint — returns 204 No Content on success
    await page.route(`**/api/v1/composite-items/${ITEM_ID}`, (route) => {
      if (route.request().method() === 'DELETE') {
        route.fulfill({ status: 204 })
      } else {
        route.continue()
      }
    })

    // Navigate to the composite-items list page
    await page.goto('/catalog/composite-items')

    // The first item's name must be visible in the table
    const firstRow = page.locator('tbody tr').first()
    await expect(firstRow).toBeVisible()
    await expect(firstRow.locator('td').nth(1)).toHaveText(ITEM_NAME)

    // Click the delete (trash) icon on the first row
    await firstRow.getByRole('button', { name: /delete/i }).click()

    // ConfirmDialog must appear — click the confirm button
    const confirmDialog = page.locator('[role="dialog"], .fixed.inset-0')
    await expect(confirmDialog).toBeVisible()
    await page.getByRole('button', { name: /confirm/i }).click()

    // After confirmation the row should disappear from the table body
    await expect(page.locator('tbody')).not.toContainText(ITEM_NAME, { timeout: 5000 })

    // -----------------------------------------------------------------------
    // Cross-surface check: verify /active-menu no longer contains the item.
    // We simulate a fresh active-menu fetch (the second call returns the
    // post-delete payload) and assert the item name is absent.
    // -----------------------------------------------------------------------
    const activeMenuResponse = await page.request.get('/api/v1/active-menu', {
      headers: { Accept: 'application/json' },
    })
    expect(activeMenuResponse.ok()).toBeTruthy()

    const payload = (await activeMenuResponse.json()) as {
      data: {
        categories: Array<{
          items: Array<{ name: string }>
        }>
      }
    }

    const itemNames = (payload.data?.categories ?? []).flatMap(
      (c) => (c.items ?? []).map((i) => i.name),
    )
    expect(itemNames).not.toContain(ITEM_NAME)
  })

  // ---------------------------------------------------------------------------
  // Test 2: cancel in ConfirmDialog leaves the row intact
  // ---------------------------------------------------------------------------
  test('cancel in ConfirmDialog leaves item in the list', async ({ authenticatedPage: page }) => {
    await page.goto('/catalog/composite-items')

    const firstRow = page.locator('tbody tr').first()
    await expect(firstRow.locator('td').nth(1)).toHaveText(ITEM_NAME)

    // Open the dialog
    await firstRow.getByRole('button', { name: /delete/i }).click()
    const confirmDialog = page.locator('[role="dialog"], .fixed.inset-0')
    await expect(confirmDialog).toBeVisible()

    // Cancel
    await page.getByRole('button', { name: /cancel/i }).click()

    // Dialog must close and item must still be in the table
    await expect(page.locator('tbody')).toContainText(ITEM_NAME)
  })

  // ---------------------------------------------------------------------------
  // Test 3: /active-menu includes the composite item before deletion
  // ---------------------------------------------------------------------------
  test('/active-menu includes the composite item before any deletion', async ({ authenticatedPage: page }) => {
    await page.route('**/api/v1/active-menu**', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: mockActiveMenuWithItem }),
      })
    })

    await page.goto('/catalog/composite-items')

    const activeMenuResponse = await page.request.get('/api/v1/active-menu', {
      headers: { Accept: 'application/json' },
    })
    expect(activeMenuResponse.ok()).toBeTruthy()

    const payload = (await activeMenuResponse.json()) as {
      data: {
        categories: Array<{
          items: Array<{ name: string }>
        }>
      }
    }

    const itemNames = (payload.data?.categories ?? []).flatMap(
      (c) => (c.items ?? []).map((i) => i.name),
    )
    expect(itemNames).toContain(ITEM_NAME)
  })
})
