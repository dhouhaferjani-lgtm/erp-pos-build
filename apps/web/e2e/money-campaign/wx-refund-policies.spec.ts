import { test, expect } from '@playwright/test'
import type { APIRequestContext } from '@playwright/test'
import {
  loginResilient,
  authHeaders,
  API_BASE,
  getReservationSettings,
  putReservationSettings,
  pickRefundPolicy,
  type Session,
} from './wx-support'

/**
 * WAVE W-X — §F `RFP` POS refund-policy configuration
 * (`/settings/pos-refund-policies` -> `companies/{id}/reservation-settings`).
 * Cases MTP-RFP-01..12.
 *
 * STATE RESTORATION: the full refund-policy field-set is captured in beforeAll
 * and restored+verified in afterAll. Validation-rejection cases (03..07) never
 * write (422 == no mutation); the two mutating cases (02 set a cap, 08 clear it,
 * 09 flip out_of_window_policy) are undone by the afterAll restore.
 *
 * Two cases are DEVICE-dependent and recorded BLOCKED (web leg cannot author a
 * POS return): RFP-09's legacy-return-exercise half, RFP-10 (v4 device refund).
 *
 * Run scoped only: --project=chromium --workers=1.
 */

test.describe.serial('W-X §F refund-policy configuration (reservation settings; snapshot+restore)', () => {
  let owner: Session
  let cashier: Session
  let entry: Record<string, unknown>

  test.beforeAll(async ({ request }) => {
    owner = await loginResilient(request, 'owner')
    cashier = await loginResilient(request, 'cashier')
    entry = pickRefundPolicy(await getReservationSettings(request, owner))
  })

  test.afterAll(async ({ request }) => {
    // RESTORE only the fields the mutating cases actually changed. Re-PUTting
    // an untouched monetary field would REFORMAT it through the currency-scale
    // resolver (e.g. seeded '50.00' -> '50.000'), which is itself a byte-mutation
    // — the controller only reformats fields present in the payload, so the
    // safest restore touches ONLY what we changed:
    //   RFP-02/08 -> daily_refund_cap_per_cashier ; RFP-09 -> out_of_window_policy.
    const res = await putReservationSettings(request, owner, {
      daily_refund_cap_per_cashier: entry.daily_refund_cap_per_cashier,
      out_of_window_policy: entry.out_of_window_policy,
    })
    expect([200, 201], `restore reservation settings -> ${res.status}`).toContain(res.status)
    // VERIFY the FULL captured policy set is byte-identical to entry (untouched
    // fields were never reformatted, so they still match seeded values).
    const after = pickRefundPolicy(await getReservationSettings(request, owner))
    for (const [k, v] of Object.entries(entry)) {
      expect(after[k], `refund policy '${k}' restored byte-identical`).toEqual(v)
    }
  })

  test('MTP-RFP-01 [P1] defaults render exactly (launch tenant is at seeded defaults)', async ({ request }) => {
    const s = await getReservationSettings(request, owner)
    expect(s.daily_refund_cap_per_cashier, 'no cap by default').toBeNull()
    expect(s.daily_refund_cap_override_allowed).toBe(true)
    expect(s.manager_override_threshold_amount).toBe('50.00')
    expect(s.manager_override_threshold_percent).toBe('10.00')
    expect(s.customer_history_window_days).toBe(14)
    expect(s.out_of_window_policy).toBe('voucher_only')
    expect(s.allowed_refund_destinations).toEqual(['original_payment', 'cash', 'store_voucher'])
    expect(s.voucher_default_expiry_days).toBe(365)
  })

  test('MTP-RFP-02 [P1] set daily_refund_cap_per_cashier=200.000 — persists as a decimal string', async ({ request }) => {
    const res = await putReservationSettings(request, owner, { daily_refund_cap_per_cashier: '200.000' })
    expect(res.status, `set cap -> ${res.status} ${JSON.stringify(res.data)}`).toBe(200)
    const s = await getReservationSettings(request, owner)
    expect(typeof s.daily_refund_cap_per_cashier, 'cap round-trips as a string').toBe('string')
    expect(Number(s.daily_refund_cap_per_cashier)).toBe(200)
  })

  test('MTP-RFP-03 [P1] manager_override_threshold_percent=100.01 rejected (max:100)', async ({ request }) => {
    const res = await putReservationSettings(request, owner, { manager_override_threshold_percent: 100.01 })
    expect(res.status, 'percent > 100 rejected').toBe(422)
  })

  test('MTP-RFP-04 [P1] manager_override_threshold_amount=-1 rejected (min:0)', async ({ request }) => {
    const res = await putReservationSettings(request, owner, { manager_override_threshold_amount: -1 })
    expect(res.status, 'negative amount rejected').toBe(422)
  })

  test('MTP-RFP-05 [P1] customer_history_window_days=366 rejected (max:365)', async ({ request }) => {
    const res = await putReservationSettings(request, owner, { customer_history_window_days: 366 })
    expect(res.status, 'window > 365 rejected').toBe(422)
  })

  test('MTP-RFP-06 [P1] voucher_default_expiry_days=0 rejected (min:1)', async ({ request }) => {
    const res = await putReservationSettings(request, owner, { voucher_default_expiry_days: 0 })
    expect(res.status, 'expiry < 1 rejected').toBe(422)
  })

  test('MTP-RFP-07 [P1] allowed_refund_destinations=[bitcoin] rejected (in:enum)', async ({ request }) => {
    const res = await putReservationSettings(request, owner, { allowed_refund_destinations: ['bitcoin'] })
    expect(res.status, 'unknown destination rejected').toBe(422)
    // no partial write: current destinations unchanged
    const s = await getReservationSettings(request, owner)
    expect(s.allowed_refund_destinations).toEqual(['original_payment', 'cash', 'store_voucher'])
  })

  test('MTP-RFP-08 [P1] clear daily_refund_cap_per_cashier -> persists as null ("no cap"), not 0', async ({ request }) => {
    // ensure it is non-null first (RFP-02 may or may not have run in isolation)
    await putReservationSettings(request, owner, { daily_refund_cap_per_cashier: '150.000' })
    const res = await putReservationSettings(request, owner, { daily_refund_cap_per_cashier: null })
    expect(res.status, `clear cap -> ${res.status}`).toBe(200)
    const s = await getReservationSettings(request, owner)
    expect(s.daily_refund_cap_per_cashier, 'cleared to null, not 0').toBeNull()
  })

  test('MTP-RFP-09 [P1] set out_of_window_policy=refuse persists; legacy-return exercise is DEVICE (BLOCKED)', async ({ request }) => {
    const res = await putReservationSettings(request, owner, { out_of_window_policy: 'refuse' })
    expect(res.status, `set refuse -> ${res.status}`).toBe(200)
    const s = await getReservationSettings(request, owner)
    expect(s.out_of_window_policy, 'refuse persisted').toBe('refuse')
    test.info().annotations.push({
      type: 'BLOCKED',
      description: 'Exercising a legacy /return outside the window requires a device/POS-authored return (web POS is demo-gated, §0.4). The RefundDestinationResolver refusal is not exercisable from this web leg. Config-persistence half is GREEN.',
    })
  })

  test('MTP-RFP-10 [P0] v4 device refund outside window -> advisory-only (DEVICE, BLOCKED)', async () => {
    // Author-on-device only. Web leg cannot produce a v4 device refund.
    test.info().annotations.push({
      type: 'BLOCKED',
      description: 'RFP-10 requires a v4 DEVICE refund authored outside the window (§0.4/§Z). Not exercisable from Playwright. Spec §3.6: server-advisory only -> refund_policy_alerts row, NOT a block. Assigned to the §Z computer-use campaign.',
    })
    expect(true).toBeTruthy()
  })

  test('MTP-RFP-11 [P1] cashier cannot write refund policies (API 403)', async ({ request }) => {
    const res = await request.put(`${API_BASE}/companies/${cashier.companyId}/reservation-settings`, {
      headers: authHeaders(cashier),
      data: { manager_override_threshold_percent: 25 },
    })
    expect(res.status(), 'cashier PUT reservation-settings -> 403 (no settings.update)').toBe(403)
  })

  test('MTP-RFP-12 [P1] tenant scoping — reading another tenant/company id does not leak (404)', async ({ request }) => {
    // Route scopes Company::where('tenant_id', user->tenant_id)->firstOrFail.
    // A company id outside the caller's tenant is a 404 (no policy values leak).
    const foreignCompanyId = '00000000-0000-4000-8000-000000000000'
    const res = await request.get(`${API_BASE}/companies/${foreignCompanyId}/reservation-settings`, {
      headers: authHeaders(owner),
    })
    expect([403, 404], `foreign company reservation-settings -> ${res.status()} (no leak)`).toContain(res.status())
    const body = await res.text()
    expect(body).not.toMatch(/manager_override_threshold|allowed_refund_destinations/)
  })
})
