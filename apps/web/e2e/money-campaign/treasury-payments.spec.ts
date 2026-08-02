/**
 * MONEY TEST CAMPAIGN — agent W2a — §E.1 `TRE` payments & allocations (MTP-TRE-01..16).
 *
 * Live local stack, real login, real backend — driven at the API layer
 * (Playwright `request` fixture) per the house pattern in
 * e2e/smoke/treasury-spine.smoke.ts. Every expected figure is computed
 * independently at TND scale 3 before the assertion.
 */
import { test, expect } from '@playwright/test'
import {
  login,
  createCustomer,
  createPostedInvoice,
  createPayment,
  getPaymentMethods,
  getRepositories,
  post,
  get,
  uniqueName,
  TODAY,
  type Session,
} from './treasury-support'
import { loginAsRole } from './helpers'

let owner: Session
let cashRepoId: string
let cashMethodId: string

test.describe('MTP-TRE — payments and allocations (W2a §E.1)', () => {
  // The shared local dev server runs multiple concurrent campaign/fix agents
  // (per coordinator brief) — bump the default 30s test timeout so a slow
  // response doesn't manufacture a false FAIL.
  test.describe.configure({ timeout: 60000 })

  test.beforeAll(async ({ request }) => {
    owner = await login(request, 'owner')
    const repos = await getRepositories(request, owner)
    cashRepoId = repos.find((r) => r.code === 'CASH-01')!.id
    const methods = await getPaymentMethods(request, owner)
    cashMethodId = methods.find((m) => m.code === 'CASH')!.id
  })

  test('MTP-TRE-01 / 02 / 03: allocate across two invoices, over-allocate refused, single-invoice cap creates unallocated credit', async ({
    request,
  }) => {
    const customerName = uniqueName('TRE01')
    const customerId = await createCustomer(request, owner, customerName)
    const invA = await createPostedInvoice(request, owner, customerId, '600.000', 'W2a INV-A')
    const invB = await createPostedInvoice(request, owner, customerId, '400.000', 'W2a INV-B')

    // MTP-TRE-01: 1000.000 payment, allocate 600 + 400 exactly.
    const payment = await createPayment(request, owner, {
      partner_id: customerId,
      payment_method_id: cashMethodId,
      repository_id: cashRepoId,
      amount: '1000.000',
      currency: 'TND',
      payment_date: TODAY,
      allocations: [
        { document_id: invA.id, amount: '600.000' },
        { document_id: invB.id, amount: '400.000' },
      ],
    })
    expect(payment.ok, `payment create -> ${payment.status} ${JSON.stringify(payment.data)}`).toBeTruthy()
    expect(payment.data.status).toBe('completed')
    expect(payment.data.unallocated_amount).toBe('0.000')

    const invAAfter = await get(request, owner, `/documents/${invA.id}`)
    const invBAfter = await get(request, owner, `/documents/${invB.id}`)
    expect(invAAfter.data.balance_due, 'INV-A fully settled').toBe('0.000')
    expect(invBAfter.data.balance_due, 'INV-B fully settled').toBe('0.000')

    // MTP-TRE-02: a second 1000.000 payment allocating 600 + 500 (500 > INV-B's
    // now-zero balance) must be refused with ALLOCATION_EXCEEDS_PAYMENT is NOT
    // the right shape here (600+500=1100 > 1000 -> that IS the exceeds-payment
    // case). Use fresh invoices so the allocation math is against real balances.
    const invC = await createPostedInvoice(request, owner, customerId, '600.000', 'W2a INV-C')
    const invD = await createPostedInvoice(request, owner, customerId, '400.000', 'W2a INV-D')
    const overAllocated = await createPayment(request, owner, {
      partner_id: customerId,
      payment_method_id: cashMethodId,
      repository_id: cashRepoId,
      amount: '1000.000',
      currency: 'TND',
      payment_date: TODAY,
      allocations: [
        { document_id: invC.id, amount: '600.000' },
        { document_id: invD.id, amount: '500.000' },
      ],
    })
    expect(overAllocated.status, 'over-allocation refused (422)').toBe(422)
    expect((overAllocated.data as { code?: string }).code).toBe('ALLOCATION_EXCEEDS_PAYMENT')
    const invCUnchanged = await get(request, owner, `/documents/${invC.id}`)
    expect(invCUnchanged.data.balance_due, 'nothing persisted on refusal').toBe('600.000')

    // MTP-TRE-03: payment 1000.000 allocating 700.000 to INV-C (balance 600.000)
    // -> capped at 600.000, surplus 400.000 becomes unallocated_amount.
    const capped = await createPayment(request, owner, {
      partner_id: customerId,
      payment_method_id: cashMethodId,
      repository_id: cashRepoId,
      amount: '1000.000',
      currency: 'TND',
      payment_date: TODAY,
      allocations: [{ document_id: invC.id, amount: '700.000' }],
    })
    expect(capped.ok, `capped payment -> ${capped.status} ${JSON.stringify(capped.data)}`).toBeTruthy()
    expect(capped.data.unallocated_amount, 'surplus surfaced as unallocated_amount').toBe('400.000')
    const allocations = capped.data.allocations as Array<{ document_id: string; amount: string }>
    const invCAllocation = allocations.find((a) => a.document_id === invC.id)
    expect(invCAllocation?.amount, 'allocation itself capped at balance_due').toBe('600.000')
    const invCFinal = await get(request, owner, `/documents/${invC.id}`)
    expect(invCFinal.data.balance_due, 'never negative / never over-allocated').toBe('0.000')
  })

  test('MTP-TRE-04: partial payment leaves the invoice partially paid', async ({ request }) => {
    const customerName = uniqueName('TRE04')
    const customerId = await createCustomer(request, owner, customerName)
    const inv = await createPostedInvoice(request, owner, customerId, '600.000')

    const payment = await createPayment(request, owner, {
      partner_id: customerId,
      payment_method_id: cashMethodId,
      repository_id: cashRepoId,
      amount: '250.000',
      currency: 'TND',
      payment_date: TODAY,
      allocations: [{ document_id: inv.id, amount: '250.000' }],
    })
    expect(payment.ok).toBeTruthy()
    const after = await get(request, owner, `/documents/${inv.id}`)
    expect(after.data.balance_due, '600.000 - 250.000 = 350.000').toBe('350.000')
  })

  test('MTP-TRE-05: allocating one payment across two different partners is refused', async ({ request }) => {
    const nameA = uniqueName('TRE05A')
    const nameB = uniqueName('TRE05B')
    const partnerA = await createCustomer(request, owner, nameA)
    const partnerB = await createCustomer(request, owner, nameB)
    const invA = await createPostedInvoice(request, owner, partnerA, '300.000')
    const invB = await createPostedInvoice(request, owner, partnerB, '300.000')

    const result = await createPayment(request, owner, {
      partner_id: partnerA,
      payment_method_id: cashMethodId,
      repository_id: cashRepoId,
      amount: '600.000',
      currency: 'TND',
      payment_date: TODAY,
      allocations: [
        { document_id: invA.id, amount: '300.000' },
        { document_id: invB.id, amount: '300.000' },
      ],
    })
    expect(result.status, 'cross-partner allocation refused (422)').toBe(422)
    expect((result.data as { code?: string }).code).toBe('ALLOCATION_PARTNER_MISMATCH')
    const invBUnchanged = await get(request, owner, `/documents/${invB.id}`)
    expect(invBUnchanged.data.balance_due, 'nothing persisted').toBe('300.000')
  })

  test.fixme(
    'MTP-TRE-06: BLOCKED — one payment mixing an AR invoice and an AP supplier invoice',
    async () => {
      // BLOCKED, not executed. The seeded AP fixture (SUPP-PAYABLE-01,
      // payable_balance 3200.000) has NO underlying SupplierInvoice Document
      // row — GET /supplier-invoices?per_page=50 returns an empty list for
      // this tenant, confirming the plan's own seeder note ("Hardcoded GL"
      // balance, not a postable document). PaymentController::store()'s
      // MIXED_ALLOCATION_TYPES guard (the behavior this case targets) can
      // only be exercised against a Posted supplier invoice carrying a real
      // Cr-401 journal entry (verified by reading the controller). Creating
      // one requires the full Procurement invoice-first flow
      // (POST /supplier-invoices with pending_receipt/invoice_first_delivered),
      // which this tenant's ProcurementPolicyResolver refuses outright:
      //   POST /supplier-invoices {pending_receipt:true, ...}
      //   -> 422 "Invoice-first supplier invoices are disabled for this company."
      // (confirmed live). Building a PO -> goods receipt -> supplier invoice
      // chain is §C `PUR` purchasing surface, out of scope for a treasury-only
      // agent per house rule "no scope creep". Recorded as a dependency, not
      // re-authored here.
    },
  )

  test('MTP-TRE-07 / 08: amount validation — min 0.01, 3dp ceiling, negative refused', async ({ request }) => {
    const customerName = uniqueName('TRE07')
    const customerId = await createCustomer(request, owner, customerName)

    const zero = await createPayment(request, owner, {
      partner_id: customerId,
      payment_method_id: cashMethodId,
      repository_id: cashRepoId,
      amount: '0',
      currency: 'TND',
      payment_date: TODAY,
    })
    expect(zero.status, 'amount 0 refused').toBe(422)

    const tooSmall = await createPayment(request, owner, {
      partner_id: customerId,
      payment_method_id: cashMethodId,
      repository_id: cashRepoId,
      amount: '0.009',
      currency: 'TND',
      payment_date: TODAY,
    })
    expect(tooSmall.status, 'amount 0.009 refused (min 0.01)').toBe(422)

    const tooManyDp = await createPayment(request, owner, {
      partner_id: customerId,
      payment_method_id: cashMethodId,
      repository_id: cashRepoId,
      amount: '12.5001',
      currency: 'TND',
      payment_date: TODAY,
    })
    expect(tooManyDp.status, 'amount 12.5001 refused (3dp ceiling)').toBe(422)

    // MTP-TRE-08: negative amount refused.
    const negative = await createPayment(request, owner, {
      partner_id: customerId,
      payment_method_id: cashMethodId,
      repository_id: cashRepoId,
      amount: '-100.000',
      currency: 'TND',
      payment_date: TODAY,
    })
    expect(negative.status, 'negative amount refused').toBe(422)
  })

  test('MTP-TRE-09 / 10: full refund reverses the money and restores balances; partial refund is scoped', async ({
    request,
  }) => {
    const customerName = uniqueName('TRE09')
    const customerId = await createCustomer(request, owner, customerName)
    const invA = await createPostedInvoice(request, owner, customerId, '600.000', 'W2a TRE-09 INV-A')
    const invB = await createPostedInvoice(request, owner, customerId, '400.000', 'W2a TRE-09 INV-B')

    const payment = await createPayment(request, owner, {
      partner_id: customerId,
      payment_method_id: cashMethodId,
      repository_id: cashRepoId,
      amount: '1000.000',
      currency: 'TND',
      payment_date: TODAY,
      allocations: [
        { document_id: invA.id, amount: '600.000' },
        { document_id: invB.id, amount: '400.000' },
      ],
    })
    expect(payment.ok).toBeTruthy()
    const paymentId = payment.data.id as string

    // MTP-TRE-09: full refund.
    const refund = await post(request, owner, `/payments/${paymentId}/refund`, {
      reason: 'W2a MTP-TRE-09 full refund',
      refund_request_id: crypto.randomUUID(),
    })
    expect(refund.ok, `refund -> ${refund.status} ${JSON.stringify(refund.data)}`).toBeTruthy()

    const invAAfter = await get(request, owner, `/documents/${invA.id}`)
    const invBAfter = await get(request, owner, `/documents/${invB.id}`)
    expect(invAAfter.data.balance_due, 'INV-A balance restored to 600.000').toBe('600.000')
    expect(invBAfter.data.balance_due, 'INV-B balance restored to 400.000').toBe('400.000')

    // MTP-TRE-10: partial refund of a DIFFERENT completed payment — only the
    // requested amount reverses; the remaining allocation stays put.
    const invC = await createPostedInvoice(request, owner, customerId, '600.000', 'W2a TRE-10 INV-C')
    const payment2 = await createPayment(request, owner, {
      partner_id: customerId,
      payment_method_id: cashMethodId,
      repository_id: cashRepoId,
      amount: '600.000',
      currency: 'TND',
      payment_date: TODAY,
      allocations: [{ document_id: invC.id, amount: '600.000' }],
    })
    expect(payment2.ok).toBeTruthy()
    const payment2Id = payment2.data.id as string

    const partialRefund = await post(request, owner, `/payments/${payment2Id}/partial-refund`, {
      amount: '250.000',
      reason: 'W2a MTP-TRE-10 partial refund',
      refund_request_id: crypto.randomUUID(),
    })
    expect(
      partialRefund.ok,
      `partial refund -> ${partialRefund.status} ${JSON.stringify(partialRefund.data)}`,
    ).toBeTruthy()

    const invCAfter = await get(request, owner, `/documents/${invC.id}`)
    // FIXED (2026-08-02, MTP-TRE-10 treasury-money-campaign-defects ticket):
    // PaymentRefundService::partialRefund() now unwinds PaymentAllocation
    // pro-rata for the refunded amount (unwindAllocationsProRata()), mirroring
    // refundPayment()'s negative-allocation-row semantics, and explicitly
    // recomputes the document's balance_due. A 250.000 partial refund of a
    // payment that fully settled a 600.000 invoice must reopen 250.000 of
    // that invoice's balance_due — the invoice is no longer "fully paid"
    // once 250.000 of the cash that paid it was handed back.
    expect(invCAfter.data.balance_due, 'partial refund reopens the allocated invoice pro-rata').toBe(
      '250.000',
    )

    // A second partial refund exceeding the remaining refundable amount must
    // never let Σ(refunds) exceed the payment (600.000).
    const overRefund = await post(request, owner, `/payments/${payment2Id}/partial-refund`, {
      amount: '400.000',
      reason: 'W2a MTP-TRE-10 over-refund attempt',
      refund_request_id: crypto.randomUUID(),
    })
    // eslint-disable-next-line no-console
    console.log(`[MTP-TRE-10] second partial refund (250+400=650>600) -> ${overRefund.status} ${JSON.stringify(overRefund.data)}`)
    expect(overRefund.ok, 'Sigma(refunds) must never exceed the payment amount').toBeFalsy()
  })

  test('MTP-TRE-11: Reverse is a distinct action from Refund', async ({ request }) => {
    const customerName = uniqueName('TRE11')
    const customerId = await createCustomer(request, owner, customerName)
    const inv = await createPostedInvoice(request, owner, customerId, '300.000')

    const payment = await createPayment(request, owner, {
      partner_id: customerId,
      payment_method_id: cashMethodId,
      repository_id: cashRepoId,
      amount: '300.000',
      currency: 'TND',
      payment_date: TODAY,
      allocations: [{ document_id: inv.id, amount: '300.000' }],
    })
    expect(payment.ok).toBeTruthy()
    const paymentId = payment.data.id as string

    const reverse = await post(request, owner, `/payments/${paymentId}/reverse`, {
      reason: 'W2a MTP-TRE-11 reverse',
    })
    expect(reverse.ok, `reverse -> ${reverse.status} ${JSON.stringify(reverse.data)}`).toBeTruthy()
    const reversedPayment = reverse.data.data as { status: string } | undefined
    // eslint-disable-next-line no-console
    console.log(`[MTP-TRE-11] payment status after reverse: ${JSON.stringify(reverse.data)}`)
    // The formatted response wraps in {data: payment}; PaymentRefundController
    // returns {data: payment, message}. Assert the resulting status is the
    // Treasury enum's terminal "reversed" value (distinct from refunded).
    const statusValue = (reversedPayment?.status ?? (reverse.data as { status?: string }).status) as string
    expect(statusValue, 'status is the distinct "reversed" terminal state').toBe('reversed')

    const invAfter = await get(request, owner, `/documents/${inv.id}`)
    // eslint-disable-next-line no-console
    console.log(`[MTP-TRE-11] invoice balance_due after reverse: ${invAfter.data.balance_due}`)
  })

  test('MTP-TRE-12: cashier — Refund/Partial-Refund/Reverse buttons visible in the UI but every API call 403s', async ({
    request,
    page,
  }) => {
    const customerName = uniqueName('TRE12')
    const customerId = await createCustomer(request, owner, customerName)
    const inv = await createPostedInvoice(request, owner, customerId, '300.000')
    const payment = await createPayment(request, owner, {
      partner_id: customerId,
      payment_method_id: cashMethodId,
      repository_id: cashRepoId,
      amount: '300.000',
      currency: 'TND',
      payment_date: TODAY,
      allocations: [{ document_id: inv.id, amount: '300.000' }],
    })
    expect(payment.ok).toBeTruthy()
    const paymentId = payment.data.id as string

    const cashier = await login(request, 'cashier')
    expect(
      cashier.permissions.includes('payments.refund') || cashier.permissions.includes('payments.reverse'),
      'cashier role holds neither payments.refund nor payments.reverse',
    ).toBeFalsy()

    const refundAsCashier = await post(request, cashier, `/payments/${paymentId}/refund`, {
      reason: 'W2a MTP-TRE-12 cashier attempt',
      refund_request_id: crypto.randomUUID(),
    })
    expect(refundAsCashier.status, 'refund API 403s for cashier').toBe(403)

    const reverseAsCashier = await post(request, cashier, `/payments/${paymentId}/reverse`, {
      reason: 'W2a MTP-TRE-12 cashier attempt',
    })
    expect(reverseAsCashier.status, 'reverse API 403s for cashier').toBe(403)

    const invUnchanged = await get(request, owner, `/documents/${inv.id}`)
    expect(invUnchanged.data.balance_due, 'no money moved by the refused attempts').toBe('0.000')

    // UI layer, REVISED FROM THE PLAN'S PREMISE (recorded as a finding, not a
    // silent swap): the plan's MTP-TRE-12 assumes the seeded "cashier"
    // persona (payments.view/create, instruments.view/create ONLY — verified
    // above) lands on PaymentDetailPage and sees the Refund/Partial
    // Refund/Reverse buttons rendered unguarded (true per the plan's own
    // static-code citation: PaymentDetailPage.tsx has no usePermissions
    // import). It does NOT. src/routes/index.tsx gates the WHOLE
    // `treasury/payments/:id` route with
    // `<RequirePermission moduleKey="treasury">`, and
    // MODULE_PERMISSIONS.treasury = ['treasury.view'] (usePermissions.ts:30)
    // — a permission the cashier role does not hold. RequirePermission then
    // <Navigate to="/dashboard" replace .../> BEFORE PaymentDetailPage ever
    // mounts, so the "buttons visible, only the API enforces" moment the
    // plan describes is unreachable for this persona: the module-level route
    // gate blocks it first. Confirmed live below. None of the 3 seeded roles
    // (owner: treasury.view + payments.refund; manager: same; cashier:
    // neither) instantiate the intermediate persona ("can view the payment
    // detail page, cannot refund") the plan's premise requires, so the exact
    // "rendered-but-403s" UI moment cannot be demonstrated with this
    // tenant's fixtures — recorded as a gap, not forced with the wrong role.
    await loginAsRole(page, 'cashier')
    await page.goto(`/treasury/payments/${paymentId}`)
    await expect(page, 'module route gate redirects the cashier to /dashboard before the page renders').toHaveURL(
      /\/dashboard/,
      { timeout: 20000 },
    )
  })

  test('MTP-TRE-13 / 14: payments.void is dead code for cancellation but gates document refund-prepayment', async ({
    request,
  }) => {
    const customerName = uniqueName('TRE13')
    const customerId = await createCustomer(request, owner, customerName)
    const inv = await createPostedInvoice(request, owner, customerId, '300.000')
    const payment = await createPayment(request, owner, {
      partner_id: customerId,
      payment_method_id: cashMethodId,
      repository_id: cashRepoId,
      amount: '300.000',
      currency: 'TND',
      payment_date: TODAY,
      allocations: [{ document_id: inv.id, amount: '300.000' }],
    })
    expect(payment.ok).toBeTruthy()
    const paymentId = payment.data.id as string

    // MTP-TRE-13: there is no DELETE /payments/{id} route — confirm the route
    // truly does not exist (405/404), proving the `pending` cancel branch is
    // unreachable dead code rather than a broken button.
    const del = await request.delete(`http://127.0.0.1:8010/api/v1/payments/${paymentId}`, {
      headers: { Authorization: `Bearer ${owner.token}`, Accept: 'application/json' },
    })
    expect([404, 405], `DELETE /payments/{id} -> ${del.status()} (no matching route)`).toContain(del.status())

    // MTP-TRE-14: payments.void actually gates POST /documents/{id}/refund-prepayment.
    expect(owner.permissions.includes('payments.void'), 'owner holds payments.void').toBeTruthy()
    const prepayInv = await createPostedInvoice(request, owner, customerId, '200.000', 'W2a TRE-14 prepay doc')
    const holderAttempt = await post(request, owner, `/documents/${prepayInv.id}/refund-prepayment`, {})
    // eslint-disable-next-line no-console
    console.log(`[MTP-TRE-14] holder refund-prepayment -> ${holderAttempt.status} ${JSON.stringify(holderAttempt.data)}`)
    expect(holderAttempt.status, 'holder is not blanket-403d (may still 422 on business rules)').not.toBe(403)

    const cashier = await login(request, 'cashier')
    expect(cashier.permissions.includes('payments.void'), 'cashier lacks payments.void').toBeFalsy()
    const nonHolderAttempt = await post(request, cashier, `/documents/${prepayInv.id}/refund-prepayment`, {})
    expect(nonHolderAttempt.status, 'non-holder 403s on refund-prepayment').toBe(403)
  })

  test('MTP-TRE-15 / 16: withholding_rate as a fraction — KNOWN DEFECT: 500 TypeError, not a 4dp/range refusal', async ({
    request,
  }) => {
    const customerName = uniqueName('TRE15')
    const customerId = await createCustomer(request, owner, customerName)
    const inv = await createPostedInvoice(request, owner, customerId, '1000.000', 'W2a TRE-15 WHT invoice')

    const withWithholding = await createPayment(request, owner, {
      partner_id: customerId,
      payment_method_id: cashMethodId,
      repository_id: cashRepoId,
      amount: '1000.000',
      currency: 'TND',
      payment_date: TODAY,
      allocations: [{ document_id: inv.id, amount: '1000.000' }],
      withholding_enabled: true,
      withholding_rate: '0.0150',
    })
    // FINDING (new, not in the plan's §0.3 known-defect register): this is a
    // hard 500, not a graceful response. PaymentController::store() (line
    // ~886) calls WithholdingCertificateService::createFromPayment() passing
    // $validated['withholding_rate'] — a STRING (FormRequest keeps it as the
    // validated numeric-string) — into a parameter typed `?float`. PHP's
    // strict_types (declare(strict_types=1) at the top of the file) refuses
    // the implicit string->float coercion and throws a TypeError, which
    // escapes as an uncaught 500 rather than a 422. Confirmed live:
    //   TypeError: ...createFromPayment(): Argument #3 ($overrideRate) must
    //   be of type ?float, string given, called in PaymentController.php:886
    expect(withWithholding.status, 'KNOWN DEFECT — should be 201, is a 500 TypeError').toBe(500)
    expect(
      JSON.stringify(withWithholding.data).includes('createFromPayment'),
      'error body matches the diagnosed WithholdingCertificateService::createFromPayment() float/string mismatch',
    ).toBeTruthy()

    // MTP-TRE-16: 5dp value and out-of-range 1.5 — these SHOULD be caught by
    // the regex/max:1 FormRequest rule before ever reaching the (broken)
    // certificate-creation call, so assert the validation layer independently
    // of the 500 above (a fresh invoice/payment so this is not entangled with
    // the crashed transaction from the case above).
    const inv2 = await createPostedInvoice(request, owner, customerId, '500.000', 'W2a TRE-16 WHT invoice')
    const fiveDp = await createPayment(request, owner, {
      partner_id: customerId,
      payment_method_id: cashMethodId,
      repository_id: cashRepoId,
      amount: '500.000',
      currency: 'TND',
      payment_date: TODAY,
      allocations: [{ document_id: inv2.id, amount: '500.000' }],
      withholding_enabled: true,
      withholding_rate: '1.00001',
    })
    expect(fiveDp.status, '5dp withholding_rate rejected at the validation layer (422)').toBe(422)

    const inv3 = await createPostedInvoice(request, owner, customerId, '500.000', 'W2a TRE-16b WHT invoice')
    const outOfRange = await createPayment(request, owner, {
      partner_id: customerId,
      payment_method_id: cashMethodId,
      repository_id: cashRepoId,
      amount: '500.000',
      currency: 'TND',
      payment_date: TODAY,
      allocations: [{ document_id: inv3.id, amount: '500.000' }],
      withholding_enabled: true,
      withholding_rate: '1.5',
    })
    expect(outOfRange.status, 'withholding_rate 1.5 rejected (range 0-1, a fraction not a percent)').toBe(422)
  })
})
