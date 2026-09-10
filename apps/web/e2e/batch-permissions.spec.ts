import { execFileSync } from 'node:child_process'
import { randomUUID } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { test, expect, type Page, type APIResponse } from '@playwright/test'

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
    for (const path of [`/inventory/batches/${batch.uuid}`, '/inventory/batches/new', `/inventory/batches/${batch.uuid}/edit`]) {
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

// Run only after the orchestrator's ON gate. Use a disposable, already
// activated tenant and its canonical database mapping. PG* variables point to
// that tenant database; this test neither derives a database name nor flips a flag.
test.describe('W-LOT-A-1a post-activation isolation', () => {
  test.skip(!process.env.WLOTA1A_POST_ACTIVATION, 'Requires the orchestrator post-activation gate and disposable tenant.')
  test('isolates selected B2 stock and history and preserves duplicate-create state', async ({ page, request }) => {
    test.setTimeout(180_000)
    const required = (name: string): string => {
      const value = process.env[name]
      if (!value) throw new Error(`Post-activation evidence requires ${name}`)
      return value
    }
    expect(required('WLOTA1A_POST_ACTIVATION')).toBe('1')
    const activation = readFileSync(required('WLOTA1A_ON_EVIDENCE'), 'utf8').trim().split('\n').sort()
    expect(activation).toEqual(['api ON', 'scheduler ON', 'worker ON'])
    const api = required('WLOTA1A_API_BASE').replace(/\/$/, '')
    const tenantId = required('WLOTA1A_TENANT_ID')
    const password = required('WLOTA1A_ADMIN_PASSWORD')
    const sqlLiteral = (value: string): string => "'" + value.replaceAll("'", "''") + "'"
    const sql = (query: string): string => execFileSync('psql', ['-X', '-qAt', '-v', 'ON_ERROR_STOP=1'], {
      input: query, encoding: 'utf8', timeout: 30_000,
    }).trim()
    expect(sql('SELECT current_database();')).toBe(required('WLOTA1A_TENANT_DATABASE'))
    expect(sql(`SELECT count(*) FROM users WHERE tenant_id <> ${sqlLiteral(tenantId)};`)).toBe('0')
    const body = async <T,>(response: APIResponse, status = 200): Promise<T> => {
      expect(response.status(), `Unexpected HTTP response from ${response.url()}`).toBe(status)
      return (await response.json() as { data: T }).data
    }
    const login = async (email: string) => body<{ token: string; user: { id: string } }>(await request.post(`${api}/auth/login`, {
      data: { email, password, tenant_id: tenantId },
    }))
    const admin = await login(required('WLOTA1A_ADMIN_EMAIL'))
    const headers = (token: string, company: string) => ({ Authorization: `Bearer ${token}`, 'X-Company-ID': company, Accept: 'application/json' })
    const companies = await body<Array<{ id: string }>>(await request.get(`${api}/user/companies`, {
      headers: { Authorization: `Bearer ${admin.token}` },
    }))
    const companyA = companies[0]?.id
    expect(companyA, 'The disposable tenant must have company A').toBeTruthy()
    if (!companyA) throw new Error('Missing company A')
    const run = randomUUID()
    const post = async <T,>(path: string, company: string, data: object): Promise<T> => body<T>(await request.post(`${api}${path}`, {
      headers: headers(admin.token, company), data,
    }), 201)
    const companyB = await post<{ id: string }>('/companies', companyA, { name: `WLOTA B ${run}`, legal_name: `WLOTA B ${run}`,
      country_code: 'FR', currency: 'EUR', locale: 'fr_FR', timezone: 'Europe/Paris' })
    const a2 = await post<{ id: string }>('/locations', companyA, { name: `A2 ${run}`, type: 'shop', pos_enabled: true })
    const b1 = await post<{ id: string }>('/locations', companyB.id, { name: `B1 ${run}`, type: 'shop', pos_enabled: false })
    const b2 = await post<{ id: string }>('/locations', companyB.id, { name: `B2 ${run}`, type: 'shop', pos_enabled: true })
    const expiry = new Date(Date.now() + 30 * 86_400_000).toISOString().slice(0, 10)
    const lotNumber = `WLOTA-${run}`
    const productA = await post<{ id: string }>('/products', companyA, { name: `A product ${run}`, sku: lotNumber, type: 'consumable' })
    const productB = await post<{ id: string }>('/products', companyB.id, { name: `B product ${run}`, sku: lotNumber, type: 'consumable' })
    const batchPayload = { product_id: productB.id, batch_number: lotNumber, expiry_date: expiry }
    const lotA = await post<{ uuid: string; id: number }>('/batches', companyA, { ...batchPayload, product_id: productA.id })
    const lotB = await post<{ uuid: string; id: number }>('/batches', companyB.id, batchPayload)
    const partnerA = await post<{ id: string }>('/partners', companyA, { name: `A partner ${run}`, type: 'customer' })
    const partnerB = await post<{ id: string }>('/partners', companyB.id, { name: `B partner ${run}`, type: 'customer' })
    const traces = [
      { company: companyA, location: a2.id, product: productA.id, lot: lotA.id, partner: partnerA.id, quantity: '91.0000', name: `TRACE-A-${run}` },
      { company: companyB.id, location: b1.id, product: productB.id, lot: lotB.id, partner: partnerB.id, quantity: '81.0000', name: `TRACE-B1-${run}` },
      { company: companyB.id, location: b2.id, product: productB.id, lot: lotB.id, partner: partnerB.id, quantity: '3.1234', name: `TRACE-B2-${run}` },
    ]
    // Identifiable non-fiscal fixture rows; no stock/GL command behavior changes.
    for (const fixture of traces) {
      const documentId = randomUUID()
      sql(`BEGIN;
        INSERT INTO inventory_batch_stock (tenant_id, batch_id, location_id, quantity, reserved_quantity)
          VALUES (${sqlLiteral(tenantId)}, ${fixture.lot}, ${sqlLiteral(fixture.location)}, ${sqlLiteral(fixture.quantity)}, '0.0000');
        INSERT INTO documents (id, tenant_id, company_id, location_id, partner_id, type, status, fiscal_category, fiscal_status, document_number, document_date, currency, subtotal, total, balance_due)
          VALUES (${sqlLiteral(documentId)}, ${sqlLiteral(tenantId)}, ${sqlLiteral(fixture.company)}, ${sqlLiteral(fixture.location)}, ${sqlLiteral(fixture.partner)}, 'invoice', 'draft', 'TAX_INVOICE', 'DRAFT', ${sqlLiteral(fixture.name)}, CURRENT_DATE, 'EUR', '10.000', '10.000', '10.000');
        INSERT INTO document_lines (id, document_id, product_id, batch_id, location_id, line_number, description, quantity, unit_price, line_total)
          VALUES (${sqlLiteral(randomUUID())}, ${sqlLiteral(documentId)}, ${sqlLiteral(fixture.product)}, ${fixture.lot}, ${sqlLiteral(fixture.location)}, 1, ${sqlLiteral(fixture.name)}, '1.0000', '10.000', '10.000');
        COMMIT;`)
    }
    const actors: Record<string, { token: string; user: { id: string } }> = {}
    for (const roleName of ['viewer', 'manager', 'general_manager']) {
      const email = `wlota-${roleName}-${run}@example.test`
      const user = await post<{ id: string }>('/users', companyB.id, { name: `WLOTA ${roleName}`, email, role: roleName,
        allowed_location_ids: roleName === 'general_manager' ? null : [b2.id] })
      // The invitation API deliberately chooses a random password. Reuse the
      // disposable test owner's hash solely to log into these new test accounts.
      sql(`UPDATE users SET password = (SELECT password FROM users WHERE id = ${sqlLiteral(admin.user.id)}), status = 'active', email_verified_at = NOW() WHERE id = ${sqlLiteral(user.id)};`)
      actors[roleName] = await login(email)
      const persisted = sql(`SELECT COALESCE(allowed_location_ids::text, 'null') FROM user_company_memberships WHERE user_id = ${sqlLiteral(user.id)} AND company_id = ${sqlLiteral(companyB.id)};`)
      expect(JSON.parse(persisted) as unknown).toEqual(roleName === 'general_manager' ? null : [b2.id])
    }
    const viewer = actors['viewer']!
    const manager = actors['manager']!
    const gm = actors['general_manager']!
    const visible = await body<{ total_quantity: string; batch_stock: Array<{ location_id: string }> }>(await request.get(`${api}/batches/${lotB.uuid}`, { headers: headers(viewer.token, companyB.id) }))
    expect(visible.total_quantity).toBe('3.1234')
    expect(visible.batch_stock.map((row) => row.location_id)).toEqual([b2.id])
    const trace = await body<unknown>(await request.get(`${api}/batches/${lotB.uuid}/traceability`, { headers: headers(manager.token, companyB.id) }))
    const traceText = JSON.stringify(trace)
    expect(traceText).toContain(`TRACE-B2-${run}`)
    expect(traceText).not.toContain(`TRACE-B1-${run}`)
    expect(traceText).not.toContain(`TRACE-A-${run}`)
    expect((await request.get(`${api}/batches/${lotA.uuid}`, { headers: headers(viewer.token, companyB.id) })).status()).toBe(404)
    const snapshot = (): string => sql(`SELECT jsonb_build_object(
      'batches', (SELECT jsonb_agg(to_jsonb(t) ORDER BY id) FROM product_batches t),
      'stock', (SELECT jsonb_agg(to_jsonb(t) ORDER BY id) FROM inventory_batch_stock t),
      'reservations', (SELECT jsonb_agg(to_jsonb(t) ORDER BY id) FROM stock_reservations t),
      'history', (SELECT jsonb_agg(to_jsonb(t) ORDER BY id) FROM document_lines t),
      'pos_history', (SELECT jsonb_agg(to_jsonb(t) ORDER BY id) FROM pos_receipt_line_batch_allocations t),
      'gl_entries', (SELECT jsonb_agg(to_jsonb(t) ORDER BY id) FROM journal_entries t),
      'gl_lines', (SELECT jsonb_agg(to_jsonb(t) ORDER BY id) FROM journal_lines t));`)
    const before = snapshot()
    const duplicate = await request.post(`${api}/batches`, { headers: headers(admin.token, companyB.id), data: batchPayload })
    expect(duplicate.status()).toBe(422)
    expect((await duplicate.json() as { meta: { outcome: string } }).meta.outcome).toBe('already_exists')
    expect(sql(`SELECT count(*) FROM product_batches WHERE company_id = ${sqlLiteral(companyB.id)} AND product_id = ${sqlLiteral(productB.id)} AND batch_number = ${sqlLiteral(lotNumber)};`)).toBe('1')
    expect(snapshot()).toBe(before)
    for (const [method, path, data] of [
      ['post', '/batches', batchPayload], ['patch', `/batches/${lotB.uuid}`, { notes: 'denied' }],
      ['delete', `/batches/${lotB.uuid}`, {}], ['post', `/batches/${lotB.uuid}/recall`, {}],
    ] as const) {
      const response = await request[method](`${api}${path}`, { headers: headers(viewer.token, companyB.id), data })
      expect(response.status()).toBe(403)
    }
    expect(snapshot()).toBe(before)
    const openAs = async (actor: { token: string; user: { id: string } }, path: string) => {
      await page.goto('/login')
      await page.evaluate(({ token, userId, tenant, company }) => {
        localStorage.setItem('autoerp-auth', JSON.stringify({ state: { token, user: { id: userId, tenant_id: tenant } }, version: 0 }))
        localStorage.setItem('autoerp-company-selection', company)
      }, { token: actor.token, userId: actor.user.id, tenant: tenantId, company: companyB.id })
      await page.goto(`${path}?lang=en`)
    }
    await openAs(viewer, '/inventory/batches')
    await expect(page.getByText(lotNumber, { exact: true })).toBeVisible()
    await expect(page.getByRole('link', { name: /add batch/i })).toHaveCount(0)
    await page.goto(`/inventory/batches/${lotB.uuid}?lang=en`)
    await expect(page.getByRole('heading', { name: lotNumber })).toBeVisible()
    await expect(page.getByRole('link', { name: /edit/i })).toHaveCount(0)
    await expect(page.getByRole('button', { name: /delete|recall/i })).toHaveCount(0)
    await openAs(gm, `/inventory/batches/${lotB.uuid}`)
    await expect(page.getByRole('button', { name: /recall batch/i })).toBeVisible()
    await expect(page.getByRole('button', { name: /request recall|release hold|reject recall|place hold/i })).toHaveCount(0)
    await expect(page.getByRole('link', { name: /recall requests|holds/i })).toHaveCount(0)
    await page.screenshot({ path: `test-results/wlota1a-post-activation-${run}.png`, fullPage: true })
  })
})
