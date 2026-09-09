import { test, expect, type Page } from '@playwright/test'

const batch = {
  id: 1, uuid: '11111111-1111-4111-8111-111111111111', batch_number: 'WLOTA1A-BROWSER',
  product_id: 'product-a', product: { id: 'product-a', name: 'Browser product', sku: 'BROWSER' },
  is_active: true, is_recalled: false, is_expired: false, expiry_status: 'OK',
  expiry_date: null, days_until_expiry: null, total_quantity: '2.1234',
  reserved_quantity: '0.1000', available_quantity: '2.0234', batch_stock: [],
}
const role: App.Modules.Identity.Application.DTOs.RoleData = {
  id: 42, name: 'general_manager', guard_name: 'sanctum', permissions: [], users_count: 0,
  created_at: null, updated_at: null, is_provisioned_read_only: true,
}

// This browser lane exercises the real web bundle with pre-activation API
// response fixtures. Backend flag-off behavior is separately tested in PHPUnit.
async function mockPreActivationApi(page: Page, state: { permissions: string[]; populated: boolean; moduleEnabled: boolean }) {
  await page.route('**/api/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1', '')
    const company = { id: 'company-a', name: 'Browser Company A', legal_name: 'Browser Company A',
      country_code: 'FR', currency: 'EUR', locale: 'en', timezone: 'Europe/Paris', status: 'active' }
    let data: unknown = []
    if (path === '/auth/me') data = { id: 'user-a', name: 'Browser Viewer', email: 'browser@example.test',
      tenantId: 'tenant-a', roles: ['manager'], permissions: state.permissions, emailVerifiedAt: '2026-09-01T00:00:00Z' }
    if (path === '/user/companies') data = [company]
    if (path === '/company/config') data = { vertical: 'pharmacy', all_enabled_modules: state.moduleEnabled ? ['BatchExpiry', 'Inventory'] : ['Inventory'], default_modules: [], enabled_extras: [] }
    if (path === '/roles') data = [role]
    if (path === '/batches') data = state.populated ? [batch] : []
    if (path === `/batches/${batch.uuid}`) data = batch
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data,
      meta: { total: state.populated ? 1 : 0, current_page: 1, last_page: 1, per_page: 25 } }) })
  })
}

test.describe('W-LOT-A-1a pre-activation web gating', () => {
  test('serves the fingerprint and gates routes and actions while API enforcement is off', async ({ page }) => {
    const state = { permissions: ['inventory.view', 'settings.view'], populated: true, moduleEnabled: true }
    await page.addInitScript(() => localStorage.setItem('autoerp-auth', JSON.stringify({ state: { token: 'wlota1a-browser-fixture', user: { id: 'user-a', tenant_id: 'tenant-a' } }, version: 0 })))
    await mockPreActivationApi(page, state)
    const servedScripts: Promise<string>[] = []
    page.on('response', (response) => {
      if (response.request().resourceType() === 'script') servedScripts.push(response.text().catch(() => ''))
    })
    await page.goto('/inventory/batches?lang=en')
    await expect(page).toHaveURL(/dashboard/)
    expect((await Promise.all(servedScripts)).join('\n')).toContain('wlota1a-batch-permission-gating-v1')
    await expect(page.getByRole('link', { name: /^batches$/i })).toHaveCount(0)
    for (const path of [`/inventory/batches/${batch.uuid}`, '/inventory/batches/create', `/inventory/batches/${batch.uuid}/edit`]) {
      await page.goto(`${path}?lang=en`)
      await expect(page).toHaveURL(/dashboard/)
    }
    state.permissions.push('batches.view')
    await page.goto('/inventory/batches?lang=en')
    await expect(page.getByText('WLOTA1A-BROWSER')).toBeVisible()
    await expect(page.getByRole('link', { name: /add batch/i })).toHaveCount(0)
    state.populated = false
    await page.reload()
    await expect(page.getByText(/no batches found/i)).toBeVisible()
    await expect(page.getByRole('link', { name: /add batch/i })).toHaveCount(0)
    state.populated = true
    await page.goto(`/inventory/batches/${batch.uuid}?lang=en`)
    await expect(page.getByRole('heading', { name: 'WLOTA1A-BROWSER' })).toBeVisible()
    await expect(page.getByRole('link', { name: /edit/i })).toHaveCount(0)
    await expect(page.getByRole('button', { name: /delete|recall/i })).toHaveCount(0)
    await page.goto('/settings/roles?lang=en')
    await expect(page.getByRole('heading', { name: 'general_manager' })).toBeVisible()
    await expect(page.getByRole('button', { name: /edit role|delete role/i })).toHaveCount(0)
    await page.screenshot({ path: 'test-results/wlota1a-protected-role.png', fullPage: true })
    state.moduleEnabled = false
    await page.goto('/inventory/batches?lang=en')
    await expect(page).toHaveURL(/dashboard/)
  })
})
