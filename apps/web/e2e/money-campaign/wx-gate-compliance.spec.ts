import { test, expect } from '@playwright/test'
import type { APIRequestContext } from '@playwright/test'
import { loginResilient, authHeaders, API_BASE, uniqueName, type Session } from './wx-support'

/**
 * WAVE W-X — §F `GATE` web-POS demo-only gate + `CMP` terminals / compliance
 * export / quarantine resolution. Cases MTP-GATE-01..06, MTP-CMP-01..05.
 *
 * DEMO-FLAG EVIDENCE (required by the brief): the seeded tenant
 * `demo-pharmacy-tn` has central `tenants.is_demo = false` (verified directly in
 * the central DB: `select is_demo from tenants where id=019fbe86-944a-…` -> `f`;
 * ALL tenants on this stack are `f`). Therefore the EXPECTED verdict for every
 * browser-originated /pos/* MUTATION is the 403 `WEB_POS_DEMO_ONLY` refusal, and
 * GATE-04 (the demo happy-path) is BLOCKED — no demo tenant exists here.
 *
 * These cases MUTATE nothing that needs restoration: the gate refuses before the
 * controller runs (no pos_shifts/receipts), CMP-02 creates a terminal and
 * hard-deletes it (204), and the compliance/quarantine cases are reads/refusals.
 *
 * Run scoped only: --project=chromium --workers=1.
 */

const DEMO_GATED_MUTATIONS: Array<{ label: string; method: 'POST'; path: string; body: unknown }> = [
  { label: 'open shift', method: 'POST', path: '/pos/shifts/open', body: { terminal_code: 'POS01', opening_float: '100.000' } },
  { label: 'close shift', method: 'POST', path: '/pos/shifts/00000000-0000-4000-8000-000000000000/close', body: {} },
  { label: 'cash deposit', method: 'POST', path: '/pos/cash-drawer/deposit', body: {} },
  { label: 'cash payout', method: 'POST', path: '/pos/cash-drawer/payout', body: {} },
  { label: 'X report', method: 'POST', path: '/pos/reports/x', body: {} },
  { label: 'Z report', method: 'POST', path: '/pos/reports/z', body: {} },
]

async function bodyCode(res: { json(): Promise<unknown> }): Promise<string | undefined> {
  try {
    const b = (await res.json()) as { error?: { code?: string } }
    return b?.error?.code
  } catch {
    return undefined
  }
}

test.describe.serial('W-X §F web-POS demo gate + compliance/terminals (non-demo tenant; enforcement path)', () => {
  let owner: Session
  let cashier: Session

  test.beforeAll(async ({ request }) => {
    owner = await loginResilient(request, 'owner')
    cashier = await loginResilient(request, 'cashier')
  })

  test('MTP-GATE-01 [P0] open shift from the browser -> 403 WEB_POS_DEMO_ONLY (is_demo=false); no shift row', async ({ request }) => {
    const res = await request.post(`${API_BASE}/pos/shifts/open`, {
      headers: authHeaders(owner),
      data: { terminal_code: 'POS01', opening_float: '100.000' },
    })
    expect(res.status(), 'browser open-shift refused on non-demo tenant').toBe(403)
    expect(await bodyCode(res), 'refusal code').toBe('WEB_POS_DEMO_ONLY')
    // The middleware runs BEFORE the controller, so no pos_shifts row is authored.
    test.info().annotations.push({
      type: 'note',
      description: 'is_demo=false confirmed in central DB. 403 fires in EnsureWebPosDemoTenant before ShiftController::open, so no pos_shifts row is created.',
    })
  })

  test('MTP-GATE-02 [P0] every browser /pos/* mutation -> 403 WEB_POS_DEMO_ONLY', async ({ request }) => {
    for (const m of DEMO_GATED_MUTATIONS) {
      const res = await request.post(`${API_BASE}${m.path}`, { headers: authHeaders(owner), data: m.body })
      expect(res.status(), `${m.label} -> 403`).toBe(403)
      expect(await bodyCode(res), `${m.label} code`).toBe('WEB_POS_DEMO_ONLY')
    }
  })

  test('MTP-GATE-03 [P0] read-only POS back-office views stay open to everyone', async ({ request }) => {
    const zList = await request.get(`${API_BASE}/pos/reports/z`, { headers: authHeaders(owner) })
    expect(zList.status(), 'z-report list allowed').toBe(200)
    const shifts = await request.get(`${API_BASE}/pos/shifts`, { headers: authHeaders(owner) })
    expect(shifts.status(), 'shift history allowed').toBe(200)
  })

  test('MTP-GATE-04 [P1] demo happy-path (open shift + sale on a demo tenant) — BLOCKED (no demo tenant on this stack)', async () => {
    test.info().annotations.push({
      type: 'BLOCKED',
      description: 'No tenant on this stack has central is_demo=true (all rows are f). The demo browser-selling path is not exercisable. It belongs to a demo-tenant fixture, not this leg.',
    })
    expect(true).toBeTruthy()
  })

  test('MTP-GATE-05 [P1] non-demo nav / is_demo exposure — record; enforcement is the 403', async ({ request }) => {
    const me = await request.get(`${API_BASE}/auth/me`, { headers: authHeaders(owner) })
    const body = await me.text()
    // is_demo is a central directory flag not exposed in the tenant auth payload
    // (known open item: POS hub visibility for non-demo tenants is UX, the 403 is
    // the enforcement).
    // NOTE: this is a RECORD case, not an enforcement gate. The assertion below
    // deliberately only rules out `"is_demo": true`, so it would ALSO pass on a
    // tenant that exposed `is_demo: false` — that is intentional (the point is
    // "not surfaced as true / hub-hiding is UX"), not a demo-gate check. The real
    // enforcement is GATE-01/02's server-side 403; do not read this as one.
    expect(body, 'is_demo not surfaced as true in /auth/me (hiding is UX; 403 is enforcement)').not.toMatch(/"is_demo"\s*:\s*true/)
    test.info().annotations.push({
      type: 'note',
      description: 'is_demo not present in /auth/me; POS hub hiding for non-demo tenants is an open UX item. Enforcement remains the server-side 403 (GATE-01/02).',
    })
  })

  test('MTP-GATE-06 [P1] back-office DEPOSIT_RECEIPT + device-header path are UNAFFECTED by the gate', async ({ request }) => {
    // (a) Server-authored DEPOSIT_RECEIPT (POST /partners/{id}/deposits) is NOT
    //     demo-gated: an empty body yields 422 validation, never WEB_POS_DEMO_ONLY.
    const partners = await request.get(`${API_BASE}/partners?type=customer&per_page=1`, { headers: authHeaders(owner) })
    const list = (await partners.json()).data as Array<{ id: string }>
    expect(list.length, 'a customer exists for the deposit path').toBeGreaterThan(0)
    const dep = await request.post(`${API_BASE}/partners/${list[0].id}/deposits`, { headers: authHeaders(owner), data: {} })
    expect(dep.status(), 'deposit path reachable (422 validation, not a gate 403)').not.toBe(403)
    expect(await bodyCode(dep), 'deposit path is not WEB_POS_DEMO_ONLY').not.toBe('WEB_POS_DEMO_ONLY')

    // (b) A device caller (X-Client-Type: pos-tauri) BYPASSES the gate: the same
    //     open-shift that 403s for a browser reaches the controller (422 business
    //     validation), proving the gate keys on the header, not a blanket block.
    const device = await request.post(`${API_BASE}/pos/shifts/open`, {
      headers: { ...authHeaders(owner), 'X-Client-Type': 'pos-tauri' },
      data: {},
    })
    expect(device.status(), 'device caller passes the gate (not 403)').not.toBe(403)
    expect(await bodyCode(device), 'device path is not gate-refused').not.toBe('WEB_POS_DEMO_ONLY')
  })

  // ---- CMP: terminals / compliance export / quarantine resolution ----------

  test('MTP-CMP-01 [P1] terminals management — owner lists terminals', async ({ request }) => {
    const res = await request.get(`${API_BASE}/pos/terminals`, { headers: authHeaders(owner) })
    expect(res.status(), 'owner list terminals -> 200').toBe(200)
    const rows = (await res.json()).data as Array<{ code: string; name: string; status: string | null }>
    expect(Array.isArray(rows) && rows.length, 'seeded terminals present').toBeTruthy()
    // The seeded VADMIN (virtual admin terminal) and the per-shop POS01s exist.
    expect(rows.some((t) => t.code === 'VADMIN'), 'virtual admin terminal present').toBeTruthy()
  })

  test('MTP-CMP-02 [P1] create a terminal then hard-delete it (self-cleaning)', async ({ request }) => {
    const locations = await request.get(`${API_BASE}/locations`, { headers: authHeaders(owner) })
    const locId = String(((await locations.json()).data as Array<{ id: string }>)[0].id)
    const created = await request.post(`${API_BASE}/pos/terminals`, {
      headers: authHeaders(owner),
      data: { name: uniqueName('CMP02-terminal'), location_id: locId },
    })
    expect(created.status(), `create terminal -> ${created.status()} ${await created.text()}`).toBe(201)
    const id = String(((await created.json()).data as { id: string }).id)
    try {
      const got = await request.get(`${API_BASE}/pos/terminals/${id}`, { headers: authHeaders(owner) })
      expect(got.status(), 'created terminal is readable').toBe(200)
    } finally {
      const del = await request.delete(`${API_BASE}/pos/terminals/${id}`, { headers: authHeaders(owner) })
      expect(del.status(), 'terminal hard-deleted (204) — no fixture footprint').toBe(204)
      const gone = await request.get(`${API_BASE}/pos/terminals/${id}`, { headers: authHeaders(owner) })
      expect(gone.status(), 'terminal gone (404)').toBe(404)
    }
  })

  test('MTP-CMP-03 [P1] cashier cannot manage terminals (create/list gated)', async ({ request }) => {
    const locations = await request.get(`${API_BASE}/locations`, { headers: authHeaders(owner) })
    const locId = String(((await locations.json()).data as Array<{ id: string }>)[0].id)
    const create = await request.post(`${API_BASE}/pos/terminals`, {
      headers: authHeaders(cashier),
      data: { name: uniqueName('CMP03-should-fail'), location_id: locId },
    })
    expect(create.status(), 'cashier create terminal -> 403').toBe(403)
    const list = await request.get(`${API_BASE}/pos/terminals`, { headers: authHeaders(cashier) })
    expect(list.status(), 'cashier list terminals -> 403 (management-gated)').toBe(403)
  })

  test('MTP-CMP-04 [P1] compliance NF525 export — owner authorized, cashier 403', async ({ request }) => {
    const payload = { company_id: owner.companyId, from: '2026-01-01', to: '2026-12-31' }
    const ownerExport = await request.post(`${API_BASE}/compliance/nf525/export-jet`, { headers: authHeaders(owner), data: payload })
    expect(ownerExport.status(), 'owner NF525 export authorized (not 403)').not.toBe(403)
    const cashierExport = await request.post(`${API_BASE}/compliance/nf525/export-jet`, { headers: authHeaders(cashier), data: payload })
    expect(cashierExport.status(), 'cashier NF525 export -> 403 (compliance.export_jet)').toBe(403)
    // Companion compliance surfaces gate the same way.
    const ownerVerify = await request.post(`${API_BASE}/compliance/nf525/verify-chains`, { headers: authHeaders(owner), data: { company_id: owner.companyId } })
    expect(ownerVerify.status(), 'owner verify-chains authorized').not.toBe(403)
    const cashierReprint = await request.get(`${API_BASE}/compliance/nf525/reprint-log?company_id=${owner.companyId}`, { headers: authHeaders(cashier) })
    expect(cashierReprint.status(), 'cashier reprint-log -> 403').toBe(403)
  })

  test('MTP-CMP-05 [P1] quarantine resolution — owner authorized (empty), cashier 403', async ({ request }) => {
    const ownerDL = await request.get(`${API_BASE}/fiscal/dead-lettered-projections`, { headers: authHeaders(owner) })
    expect(ownerDL.status(), 'owner dead-lettered projections -> 200').toBe(200)
    const cashierDL = await request.get(`${API_BASE}/fiscal/dead-lettered-projections`, { headers: authHeaders(cashier) })
    expect(cashierDL.status(), 'cashier dead-lettered projections -> 403').toBe(403)

    // best-effort-parse: owner passes the permission gate (404 not-found on a fake
    // id), cashier is refused at the gate (403) — proves resolve_quarantine gating.
    const fakeId = '00000000-0000-4000-8000-000000000000'
    const ownerParse = await request.post(`${API_BASE}/fiscal/quarantine/${fakeId}/best-effort-parse`, { headers: authHeaders(owner), data: {} })
    expect(ownerParse.status(), 'owner quarantine-parse authorized past the gate (404 fake id)').toBe(404)
    const cashierParse = await request.post(`${API_BASE}/fiscal/quarantine/${fakeId}/best-effort-parse`, { headers: authHeaders(cashier), data: {} })
    expect(cashierParse.status(), 'cashier quarantine-parse -> 403 (fiscal.events.resolve_quarantine)').toBe(403)
  })
})
