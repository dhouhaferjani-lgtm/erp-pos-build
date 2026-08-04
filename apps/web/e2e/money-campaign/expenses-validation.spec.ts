/**
 * MONEY TEST CAMPAIGN — wave W-5c — `/expenses/*` validation ceilings:
 * `MTP-TRE-68/69/70` (plan §B.5 row 71).
 *
 * Live local stack (web :5173 -> api :8010, tenant `demo-pharmacy-tn`), real login,
 * real backend, no mocks.
 *
 * Every case here asserts a REFUSAL, so nothing it sends may be allowed to land.
 * Each refused payload is followed by a read-back that proves no expense with that
 * (unique) vendor name exists — a 422 whose row nevertheless persisted is exactly
 * the kind of hole a status-only assertion misses.
 *
 * CONCURRENCY: safe at any worker count — no shared repository, no shared aggregate.
 * Still run as `--workers=1` with the rest of the wave.
 *
 * Junk hygiene: nothing is created on the happy path. The two control expenses
 * (one per boundary) that ARE accepted stay DRAFT and are DELETEd in a `finally`
 * whose `expect` is gated on the case body having succeeded.
 */
import { test, expect } from '@playwright/test'
import { login, get, type Session } from './treasury-support'
import { isCleanupSuccess } from './statement-support'
import { createExpense, retireDraftExpense, uniq } from './w5c-support'

let owner: Session

/** The `{error: {code, message, errors}}` envelope FormRequest failures use. */
interface ValidationEnvelope {
  code?: string
  message?: string
  errors?: Record<string, string[]>
}

test.describe('MTP-TRE — expense validation ceilings (W-5c §B.5 row 71)', () => {
  test.describe.configure({ timeout: 180_000 })

  test.beforeAll(async ({ request }) => {
    owner = await login(request, 'owner')
  })

  /** No expense carrying this (per-run unique) vendor name reached the database. */
  async function assertNothingLanded(
    request: Parameters<typeof get>[0],
    vendorName: string,
  ): Promise<void> {
    const listed = await get(request, owner, `/expenses?search=${encodeURIComponent(vendorName)}`)
    expect(listed.ok, `expense search -> ${listed.status}`).toBeTruthy()
    expect(
      (listed.data as unknown as unknown[]).length,
      `the refused payload '${vendorName}' left no row behind`,
    ).toBe(0)
  }

  test('MTP-TRE-68 (P1): amount floor and 3-decimal ceiling are both enforced server-side', async ({
    request,
  }) => {
    // `total` => ['required','numeric','min:0.01','regex:/^\d+(\.\d{1,3})?$/'] (ExpenseRequest.php:75)
    const refusals: Array<{ label: string; total: string; field: string; message: string }> = [
      { label: 'zero', total: '0', field: 'total', message: 'The amount field must be at least 0.01.' },
      { label: 'zero3dp', total: '0.000', field: 'total', message: 'The amount field must be at least 0.01.' },
      // BELOW the floor but WITHIN the 3-dp ceiling — the two rules are independent
      // and this payload proves `min` is not merely a side-effect of the regex.
      { label: 'sub-cent', total: '0.009', field: 'total', message: 'The amount field must be at least 0.01.' },
      // ABOVE the floor but OVER the ceiling — the mirror case.
      { label: '4dp', total: '1.0001', field: 'total', message: 'The amount must have at most 3 decimal places.' },
    ]

    for (const refusal of refusals) {
      const vendor = uniq(`TRE68-${refusal.label}`)
      const res = await createExpense(request, owner, {
        vendor_name: vendor,
        total: refusal.total,
        is_paid: false,
      })
      const envelope = res.data as unknown as ValidationEnvelope
      expect(
        { label: refusal.label, status: res.status, code: envelope.code },
        `total='${refusal.total}' is refused as a validation error`,
      ).toEqual({ label: refusal.label, status: 422, code: 'VALIDATION_ERROR' })
      expect(envelope.errors?.[refusal.field], `on the '${refusal.field}' field`).toContain(refusal.message)
      await assertNothingLanded(request, vendor)
    }

    // A negative amount trips BOTH rules — the regex is unsigned, so `min` and the
    // format ceiling report together. Pinned because a future "allow refunds"
    // change to the regex must not silently make `-5.000` acceptable.
    const negativeVendor = uniq('TRE68-negative')
    const negative = await createExpense(request, owner, {
      vendor_name: negativeVendor,
      total: '-5.000',
      is_paid: false,
    })
    expect(negative.status, 'a negative expense total is refused').toBe(422)
    expect((negative.data as unknown as ValidationEnvelope).errors?.total?.sort()).toEqual(
      [
        'The amount field must be at least 0.01.',
        'The amount must have at most 3 decimal places.',
      ].sort(),
    )
    await assertNothingLanded(request, negativeVendor)

    // Control: the exact floor and the exact ceiling ARE accepted, so the
    // refusals above are boundary behaviour and not a blanket rejection.
    let caseSucceeded = false
    const floorVendor = uniq('TRE68-floor')
    const accepted = await createExpense(request, owner, {
      vendor_name: floorVendor,
      total: '0.010',
      is_paid: false,
    })
    expect(accepted.status, `the exact floor 0.010 is accepted -> ${JSON.stringify(accepted.data)}`).toBe(201)
    const acceptedId = String(accepted.data.id)
    try {
      expect(accepted.data.total, 'stored at exactly the submitted scale-3 value').toBe('0.010')
      caseSucceeded = true
    } finally {
      const status = await retireDraftExpense(request, owner, acceptedId)
      if (caseSucceeded) {
        expect(isCleanupSuccess(status), `retire draft expense -> ${status}`).toBe(true)
      }
    }
  })

  test('MTP-TRE-69 (P1): VAT >= total is refused by the BACKEND, not just the form', async ({
    request,
  }) => {
    // The frontend rule `vat_amount < total` is client-side only; the plan asks
    // explicitly whether the server enforces it too (client-only enforcement is a
    // gap). It does: ExpenseService::assertVatInvariants() throws a DomainException
    // that surfaces as a 422 BUSINESS_ERROR — NOT a 500.
    for (const [label, vat] of [
      ['vat-equals-total', '119.000'],
      ['vat-exceeds-total', '200.000'],
    ] as const) {
      const vendor = uniq(`TRE69-${label}`)
      const res = await createExpense(request, owner, {
        vendor_name: vendor,
        total: '119.000',
        vat_amount: vat,
        vat_rate: '19.00',
        vat_deductible_percent: '100.00',
        is_paid: false,
      })
      const envelope = res.data as unknown as ValidationEnvelope
      expect(
        { label, status: res.status, code: envelope.code, message: envelope.message },
        `vat_amount=${vat} on total=119.000 is a business refusal, not a 500`,
      ).toEqual({
        label,
        status: 422,
        code: 'BUSINESS_ERROR',
        message: 'VAT amount must be less than the expense total.',
      })
      await assertNothingLanded(request, vendor)
    }

    // Control: one millime below the total IS accepted, and the derived net is
    // exactly total - VAT. This is what makes the refusals above a boundary.
    let caseSucceeded = false
    const vendor = uniq('TRE69-boundary')
    const accepted = await createExpense(request, owner, {
      vendor_name: vendor,
      total: '119.000',
      vat_amount: '118.999',
      vat_rate: '19.00',
      vat_deductible_percent: '100.00',
      is_paid: false,
    })
    expect(accepted.status, `vat 118.999 < total 119.000 -> ${JSON.stringify(accepted.data)}`).toBe(201)
    const acceptedId = String(accepted.data.id)
    try {
      expect(
        { total: accepted.data.total, tax_amount: accepted.data.tax_amount, subtotal: accepted.data.subtotal },
        'net = 119.000 - 118.999 = exactly 0.001',
      ).toEqual({ total: '119.000', tax_amount: '118.999', subtotal: '0.001' })
      caseSucceeded = true
    } finally {
      const status = await retireDraftExpense(request, owner, acceptedId)
      if (caseSucceeded) {
        expect(isCleanupSuccess(status), `retire draft expense -> ${status}`).toBe(true)
      }
    }
  })

  test('MTP-TRE-70 (P1): vat_rate/vat_deductible_percent 2-dp ceilings and the 0-100 range', async ({
    request,
  }) => {
    const refusals: Array<{ label: string; payload: Record<string, unknown>; field: string; message: string }> = [
      {
        label: 'rate-3dp',
        payload: { vat_rate: '19.001' },
        field: 'vat_rate',
        message: 'The vat rate field format is invalid.',
      },
      {
        label: 'deductible-over-100',
        payload: { vat_deductible_percent: '100.01' },
        field: 'vat_deductible_percent',
        message: 'The vat deductible percent field must not be greater than 100.',
      },
      {
        label: 'deductible-3dp',
        payload: { vat_deductible_percent: '99.999' },
        field: 'vat_deductible_percent',
        message: 'The vat deductible percent field format is invalid.',
      },
      {
        // `vat_amount` is CURRENCY-scaled (3 dp), unlike the two percent fields
        // (2 dp) — precision-contract rule 19. Pinned so the two ceilings can
        // never be conflated by a future refactor.
        label: 'vat-amount-4dp',
        payload: { vat_amount: '19.0001' },
        field: 'vat_amount',
        message: 'The vat amount field format is invalid.',
      },
      {
        label: 'rate-over-100',
        payload: { vat_rate: '100.01' },
        field: 'vat_rate',
        message: 'The vat rate field must not be greater than 100.',
      },
      {
        label: 'rate-negative',
        payload: { vat_rate: '-1.00' },
        field: 'vat_rate',
        message: 'The vat rate field must be at least 0.',
      },
    ]

    for (const refusal of refusals) {
      const vendor = uniq(`TRE70-${refusal.label}`)
      const res = await createExpense(request, owner, {
        vendor_name: vendor,
        total: '119.000',
        vat_amount: '19.000',
        vat_rate: '19.00',
        vat_deductible_percent: '100.00',
        is_paid: false,
        ...refusal.payload,
      })
      const envelope = res.data as unknown as ValidationEnvelope
      expect(
        { label: refusal.label, status: res.status, code: envelope.code },
        `${JSON.stringify(refusal.payload)} is refused`,
      ).toEqual({ label: refusal.label, status: 422, code: 'VALIDATION_ERROR' })
      expect(envelope.errors?.[refusal.field], `on the '${refusal.field}' field`).toContain(refusal.message)
      await assertNothingLanded(request, vendor)
    }

    // Control: the exact 2-dp boundaries ARE accepted and round-trip unchanged.
    let caseSucceeded = false
    const vendor = uniq('TRE70-boundary')
    const accepted = await createExpense(request, owner, {
      vendor_name: vendor,
      total: '119.000',
      vat_amount: '19.000',
      vat_rate: '19.99',
      vat_deductible_percent: '100.00',
      is_paid: false,
    })
    expect(accepted.status, `2-dp rate 19.99 accepted -> ${JSON.stringify(accepted.data)}`).toBe(201)
    const acceptedId = String(accepted.data.id)
    try {
      const meta = accepted.data.metadata as Record<string, unknown>
      expect(
        { vat_rate: meta.vat_rate, vat_deductible_percent: meta.vat_deductible_percent },
        'both percent fields round-trip at exactly 2 decimals',
      ).toEqual({ vat_rate: '19.99', vat_deductible_percent: '100.00' })
      caseSucceeded = true
    } finally {
      const status = await retireDraftExpense(request, owner, acceptedId)
      if (caseSucceeded) {
        expect(isCleanupSuccess(status), `retire draft expense -> ${status}`).toBe(true)
      }
    }
  })
})
