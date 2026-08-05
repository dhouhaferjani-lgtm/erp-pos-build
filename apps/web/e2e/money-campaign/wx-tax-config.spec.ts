import { test, expect } from '@playwright/test'
import {
  loginResilient,
  authHeaders,
  API_BASE,
  TODAY,
  addMoney,
  listTaxConfigs,
  getCompanyTaxFields,
  putCompany,
  uniqueName,
  type Session,
} from './wx-support'
import type { APIRequestContext } from '@playwright/test'

/**
 * WAVE W-X — §A `CFG` / §B `TAX` country-scoped tax-configuration + company
 * tax-status mutations. DESTRUCTIVE-BY-DESIGN, but on this launch-realistic
 * tenant EVERY mutation is REFUSED by the product, so W-X mutates NOTHING here
 * (safest possible outcome for state restoration — see exit checklist item 1).
 *
 * Two hard product facts discovered live (both GREEN-tripwired, both ticketed
 * in docs/superpowers/tickets/2026-08-05-wx-tax-config-unmanageable.md):
 *
 *  1. company `tax_status` is PERMANENTLY fiscal-locked once posted documents
 *     exist (422 BUSINESS_ERROR). demo-pharmacy-tn has posted docs, so TAX-02's
 *     "flip to NON_REGISTERED" is refused — a correct fiscal control.
 *  2. `taxation.tax_configurations.manage` is NOT seeded into tenant DBs by the
 *     canonical RolesAndPermissionsSeeder (only by the inert legacy
 *     PermissionSeeder). The tenant DB has 288 permission rows, ZERO `taxation.*`.
 *     So the `can:taxation.tax_configurations.manage` route middleware denies
 *     EVERY role incl. admin -> tax-config store/update/delete/reorder are DEAD.
 *     TAX-03/06 + CFG-09's mutation halves are therefore UNREACHABLE (recorded
 *     BLOCKED via annotation); the deterministic 403 refusal is pinned GREEN.
 *
 * Run scoped only: --project=chromium --workers=1. NEVER the whole suite.
 */

const CODE_STAMP_INVOICE = 'STAMP_TAX_INVOICE'
const CODE_TVA_19 = 'TVA_19'

async function createCustomer(request: APIRequestContext, session: Session, name: string): Promise<string> {
  const res = await request.post(`${API_BASE}/partners`, {
    headers: authHeaders(session),
    data: { name, type: 'customer' },
  })
  expect(res.ok(), `create customer -> ${res.status()} ${await res.text()}`).toBeTruthy()
  return String(((await res.json()).data as { id: string }).id)
}

interface Totals {
  id: string
  subtotal: string
  tax_amount: string
  total: string
  status: string
}

async function createConfirmedInvoice(
  request: APIRequestContext,
  session: Session,
  partnerId: string,
  unitPrice: string,
  taxRate: string,
): Promise<Totals> {
  const createRes = await request.post(`${API_BASE}/invoices`, {
    headers: authHeaders(session),
    data: {
      partner_id: partnerId,
      document_date: TODAY,
      lines: [{ description: 'WX tax fixture line', quantity: '1', unit_price: unitPrice, tax_rate: taxRate }],
    },
  })
  expect(createRes.ok(), `create invoice -> ${createRes.status()} ${await createRes.text()}`).toBeTruthy()
  const invoiceId = String(((await createRes.json()).data as { id: string }).id)
  const confirmRes = await request.post(`${API_BASE}/invoices/${invoiceId}/confirm`, {
    headers: authHeaders(session),
  })
  expect(confirmRes.ok(), `confirm invoice -> ${confirmRes.status()} ${await confirmRes.text()}`).toBeTruthy()
  const d = (await confirmRes.json()).data as { subtotal: string; tax_amount: string; total: string; status: string }
  return { id: invoiceId, subtotal: d.subtotal, tax_amount: d.tax_amount, total: d.total, status: d.status }
}

test.describe.serial('W-X §A/§B tax-configuration + company tax-status (country-scoped; all mutations product-refused)', () => {
  let ownerSession: Session
  let cashierSession: Session
  test.beforeAll(async ({ request }) => {
    ownerSession = await loginResilient(request, 'owner')
    cashierSession = await loginResilient(request, 'cashier')
  })

  test('MTP-TAX-02 [P0] tax_status change is fiscal-locked once posted docs exist (422); no mutation', async ({ request }) => {
    const session = ownerSession
    const entry = await getCompanyTaxFields(request, session)
    expect(entry.tax_status, 'entry tax_status is REGISTERED').toBe('REGISTERED')

    // Attempt the flip to NON_REGISTERED. On a tenant with posted fiscal
    // documents this is PERMANENTLY refused (fiscal-compliance lock).
    const flip = await putCompany(request, session, { tax_status: 'NON_REGISTERED' })
    expect(flip.status, `PUT tax_status=NON_REGISTERED -> ${flip.status}`).toBe(422)
    const err = flip.data as { code?: string; message?: string }
    expect(err.code, 'refusal is a BUSINESS_ERROR fiscal lock').toBe('BUSINESS_ERROR')
    expect(String(err.message)).toMatch(/tax status is permanently locked|posted fiscal documents/i)

    // VERIFY: unchanged. W-X mutated nothing.
    const after = await getCompanyTaxFields(request, session)
    expect(after.tax_status, 'tax_status still REGISTERED (no mutation)').toBe('REGISTERED')

    // The plan's "no VAT charged under NON_REGISTERED" sub-assertion is
    // UNREACHABLE on a launch-realistic tenant (posted docs => status locked).
    test.info().annotations.push({
      type: 'note',
      description: 'NON_REGISTERED VAT-suppression behaviour not exercisable: tax_status is fiscal-locked after first posted document. The lock itself is the GREEN-pinned correct behaviour.',
    })
  })

  test('MTP-TAX-03 [P1] deactivate STAMP_TAX_INVOICE is 403 (perm unseeded) for admin AND cashier; reads open', async ({ request }) => {
    const owner = ownerSession
    const cashier = cashierSession
    const configs = await listTaxConfigs(request, owner)
    const stampRow = configs.find((c) => c.code === CODE_STAMP_INVOICE)
    expect(stampRow, 'STAMP_TAX_INVOICE present + readable').toBeTruthy()
    const stampId = String(stampRow!.id)
    expect(stampRow!.is_active, 'stamp active at entry').toBe(true)

    // Owner (admin) write -> 403: taxation.tax_configurations.manage is unseeded.
    const ownerPatch = await request.patch(`${API_BASE}/taxation/configurations/${stampId}`, {
      headers: authHeaders(owner),
      data: { is_active: false },
    })
    expect(ownerPatch.status(), 'owner/admin PATCH tax config -> 403 (perm unseeded)').toBe(403)

    // Cashier write -> 403 too (same permission absence, not a role difference).
    const cashierPatch = await request.patch(`${API_BASE}/taxation/configurations/${stampId}`, {
      headers: authHeaders(cashier),
      data: { is_active: false },
    })
    expect(cashierPatch.status(), 'cashier PATCH tax config -> 403').toBe(403)

    // Reads stay open to any authenticated tenant user.
    const cashierRead = await request.get(`${API_BASE}/taxation/configurations`, { headers: authHeaders(cashier) })
    expect(cashierRead.status(), 'cashier read tax configs -> 200').toBe(200)

    // VERIFY untouched.
    const stillActive = (await listTaxConfigs(request, owner)).find((c) => c.code === CODE_STAMP_INVOICE)
    expect(stillActive!.is_active, 'stamp still active (no mutation)').toBe(true)

    test.info().annotations.push({
      type: 'BLOCKED',
      description: 'Stamp-deactivation behaviour (stamp_duty=0 on new invoice) unreachable: taxation.tax_configurations.manage unseeded -> write refused for ALL roles. See 2026-08-05-wx-tax-config-unmanageable ticket.',
    })
  })

  test('MTP-TAX-06 [P0] posted-doc rate is snapshotted; config rate-change is 403 (unmanageable)', async ({ request }) => {
    const session = ownerSession
    const configs = await listTaxConfigs(request, session)
    const tva = configs.find((c) => c.code === CODE_TVA_19)!
    expect(tva.percentage_rate).toBe('19.00')

    // Post an invoice at 19% and capture its snapshot.
    const partnerId = await createCustomer(request, session, uniqueName('TAX06'))
    const inv = await createConfirmedInvoice(request, session, partnerId, '100.000', '19')
    // 100.000 net @19% -> line VAT 19.000 + stamp 1.000 = 20.000; total 120.000.
    expect(inv.tax_amount).toBe('20.000')
    expect(inv.total).toBe('120.000')
    const postRes = await request.post(`${API_BASE}/invoices/${inv.id}/post`, { headers: authHeaders(session) })
    expect(postRes.ok(), `post -> ${postRes.status()} ${await postRes.text()}`).toBeTruthy()
    const posted = (await postRes.json()).data as { subtotal: string; tax_amount: string; total: string }

    // Config rate-change is REFUSED (403) -> cannot exercise the retroactive-recompute path.
    const patch = await request.patch(`${API_BASE}/taxation/configurations/${tva.id}`, {
      headers: authHeaders(session),
      data: { percentage_rate: '20.00' },
    })
    expect(patch.status(), 'TVA_19 rate change -> 403 (unmanageable)').toBe(403)

    // What we CAN prove P0: the posted document's figures are stable on re-read
    // (immutability of a posted fiscal doc; the config is unchanged anyway).
    const reread = await request.get(`${API_BASE}/invoices/${inv.id}`, { headers: authHeaders(session) })
    const now = (await reread.json()).data as { subtotal: string; tax_amount: string; total: string }
    expect(now.subtotal).toBe(posted.subtotal)
    expect(now.tax_amount, 'posted tax_amount stable').toBe(posted.tax_amount)
    expect(now.total, 'posted total stable').toBe(posted.total)
    // Config still 19.00 (no mutation).
    const tvaAfter = (await listTaxConfigs(request, session)).find((c) => c.code === CODE_TVA_19)!
    expect(tvaAfter.percentage_rate, 'TVA_19 still 19.00').toBe('19.00')

    test.info().annotations.push({
      type: 'BLOCKED',
      description: 'Retroactive-recompute-after-rate-change path unreachable (config write 403). Posted-doc immutability is separately proven by W1b MTP-DOC-08.',
    })
  })

  test('MTP-CFG-08 [P0] country-scoped cross-company visibility — BLOCKED→C-9 (single-company tenant)', async ({ request }) => {
    const session = ownerSession
    const companies = await request.get(`${API_BASE}/user/companies`, { headers: authHeaders(session) })
    const rows = (await companies.json()).data as Array<{ id: string }>
    expect(rows.length, 'tenant is single-company; cross-company half not exercisable').toBe(1)
    test.info().annotations.push({
      type: 'BLOCKED',
      description: 'C-9: no second company in country TN on this stack; cross-company visibility not exercisable. Country-scoping confirmed structurally (TaxConfigurationController::update/destroy key on country_code).',
    })
  })

  test('MTP-CFG-09 [P1] deactivate TVA_19 config is 403 (unmanageable); posted docs keep snapshotted rate', async ({ request }) => {
    const session = ownerSession
    const tva = (await listTaxConfigs(request, session)).find((c) => c.code === CODE_TVA_19)!
    expect(tva.is_active).toBe(true)

    // A posted invoice at 19% BEFORE any attempt — its rate must survive regardless.
    const partnerId = await createCustomer(request, session, uniqueName('CFG09'))
    const inv = await createConfirmedInvoice(request, session, partnerId, '100.000', '19')
    const postRes = await request.post(`${API_BASE}/invoices/${inv.id}/post`, { headers: authHeaders(session) })
    expect(postRes.ok()).toBeTruthy()
    const postedBefore = (await postRes.json()).data as { tax_amount: string; total: string }

    // Soft-delete (deactivate) is refused (403) — cannot exercise "no longer offered".
    const del = await request.delete(`${API_BASE}/taxation/configurations/${tva.id}`, { headers: authHeaders(session) })
    expect(del.status(), 'soft-delete TVA_19 -> 403 (unmanageable)').toBe(403)

    // TVA_19 still active (no mutation); posted doc figures unchanged.
    const stillActive = (await listTaxConfigs(request, session)).find((c) => c.code === CODE_TVA_19)!
    expect(stillActive.is_active, 'TVA_19 still active').toBe(true)
    const reread = await request.get(`${API_BASE}/invoices/${inv.id}`, { headers: authHeaders(session) })
    const now = (await reread.json()).data as { tax_amount: string; total: string }
    expect(now.tax_amount).toBe(postedBefore.tax_amount)
    expect(now.total).toBe(postedBefore.total)
    expect(addMoney('0.000', now.tax_amount)).toBe(now.tax_amount) // money-string sanity

    test.info().annotations.push({
      type: 'BLOCKED',
      description: 'Deactivate-then-not-offered path unreachable (config delete 403). Ticket 2026-08-05-wx-tax-config-unmanageable.',
    })
  })
})
