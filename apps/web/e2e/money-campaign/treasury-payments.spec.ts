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
import {
  createSupplier,
  setupReceivedPoLine,
  createSupplierInvoice,
  postSupplierInvoice,
} from './w2b-support'

let owner: Session
let cashRepoId: string
let cashMethodId: string
let checkMethodId: string

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
    checkMethodId = methods.find((m) => m.code === 'CHECK')!.id
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

  // C-12 UNBLOCK (2026-08-02, plan §C item C-12): the original BLOCKED reason
  // was the missing AP fixture, not the product guard itself — SUPP-PAYABLE-01
  // has no underlying SupplierInvoice Document row, and invoice-first supplier
  // invoices are disabled for this tenant's ProcurementPolicyResolver. W2a's
  // purchasing lane (`w2b-support.ts`) already built a real PO -> goods-receipt
  // -> supplier-invoice chain (`setupReceivedPoLine` + `createSupplierInvoice`
  // + `postSupplierInvoice`) for the 3-way-matching cases; wiring it in here
  // unblocks the guard this case actually targets:
  // PaymentController::store()'s MIXED_ALLOCATION_TYPES guard
  // (`PaymentController.php:583-591`) — a single payment cannot allocate to
  // both a supplier_invoice (AP) and a non-supplier (AR) document, because the
  // two post opposite GL directions and cannot share one journal entry.
  test('MTP-TRE-06: one payment mixing an AR invoice and an AP supplier invoice is refused (MIXED_ALLOCATION_TYPES)', async ({
    request,
    page,
  }) => {
    test.setTimeout(90000)
    await loginAsRole(page, 'owner')

    // Partner-type is NOT enforced by InvoiceController/PurchaseOrderController
    // at document-creation time (confirmed by code read) — the guard under
    // test cares about DOCUMENT.type (supplier_invoice vs not), not
    // partner.type, so one partner can legitimately carry both legs here.
    const partnerName = uniqueName('TRE06')
    const partnerId = await createSupplier(page, partnerName)

    // AR leg.
    const arInvoice = await createPostedInvoice(request, owner, partnerId, '300.000', 'W1 MTP-TRE-06 AR leg')

    // AP leg: PO -> confirm -> receive -> supplier invoice -> post.
    const { poId, lineId } = await setupReceivedPoLine(page, {
      supplierId: partnerId,
      quantity: '5',
      unitPrice: '40.000',
      skuBase: 'TRE06',
    })
    const supplierInvoice = await createSupplierInvoice(page, {
      partnerId,
      sourceDocumentIds: [poId],
      lines: [{ sourceLineId: lineId, quantity: '5', unitPrice: '40.000' }],
    })
    expect(
      supplierInvoice.status,
      `supplier invoice create -> ${supplierInvoice.status} ${JSON.stringify(supplierInvoice.body)}`,
    ).toBe(201)
    const supplierInvoiceId = supplierInvoice.id as string
    const postResult = await postSupplierInvoice(page, supplierInvoiceId)
    expect(
      postResult.status,
      `supplier invoice post -> ${postResult.status} ${JSON.stringify(postResult.body)}`,
    ).toBeLessThan(300)

    // One payment, one allocation to each leg -> must be refused.
    const mixed = await createPayment(request, owner, {
      partner_id: partnerId,
      payment_method_id: cashMethodId,
      repository_id: cashRepoId,
      amount: '500.000',
      currency: 'TND',
      payment_date: TODAY,
      allocations: [
        { document_id: arInvoice.id, amount: '300.000' },
        { document_id: supplierInvoiceId, amount: '200.000' },
      ],
    })
    expect(mixed.status, `mixed AR+AP allocation refused (422) -> ${mixed.status} ${JSON.stringify(mixed.data)}`).toBe(
      422,
    )
    expect((mixed.data as { code?: string }).code, 'MIXED_ALLOCATION_TYPES guard fires').toBe(
      'MIXED_ALLOCATION_TYPES',
    )
  })

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
    // A1.1 (D9 / review N1): refundPayment() now calls recomputeDocumentBalances(),
    // reverting Paid -> Posted on full refund. Both invoices were fully paid
    // by the 1000.000 payment above; the full refund must revert both.
    expect(invAAfter.data.status, 'D9: full refund reverts INV-A Paid -> Posted').toBe('posted')
    expect(invBAfter.data.status, 'D9: full refund reverts INV-B Paid -> Posted').toBe('posted')

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
    // A1.2 (D9): unwindAllocationsProRata() reverts status too, not just the
    // balance — only the balance was asserted before this edit.
    expect(invCAfter.data.status, 'D9: partial refund reverts INV-C Paid -> Posted').toBe('posted')

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
    // A1.3 (D9): same recompute path as full/partial refund
    // (PaymentRefundService.php:666) — reverse also reverts Paid -> Posted.
    expect(invAfter.data.balance_due, 'D9: reverse restores balance_due to 300.000').toBe('300.000')
    expect(invAfter.data.status, 'D9: reverse reverts INV Paid -> Posted').toBe('posted')
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

  test('MTP-TRE-15 / 16: withholding_rate as a fraction — valid rate creates the payment (no TypeError), 4dp/range refused with 422', async ({
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
    // FIXED (2026-08-02, MTP-TRE-15 ticket + 2026-08-02 adversarial-review
    // remediation C4/C5): PaymentController::store() now normalises
    // withholding_rate to a canonical numeric-string ONCE, at the HTTP
    // boundary (normalizeWithholdingRate() — handles a JSON number payload
    // too, not just a JSON string), before calling
    // WithholdingCertificateService::createFromPayment() (`?string`
    // parameter, bcmath domain per precision-contract rule 19). A valid
    // rate now creates the payment (201) with a linked withholding
    // certificate (formatPayment() doesn't surface withholding_certificate_id
    // on the payment payload itself, so confirm the certificate via
    // GET /withholding/certificates instead).
    //
    // C5 (units): withholding_rate is a FRACTION (0-1, matching the
    // max:1 FormRequest rule) — 0.0150 on a 1000.000 invoice must withhold
    // 15.000 (1000.000 * 0.0150), NOT 0.001 (the polarity-flipped
    // percentage-division bug the adversarial review reproduced: routing a
    // fraction through the percentage-scaled `calculateWithOverride()`
    // conversion produced a certificate ~100x too small).
    expect(withWithholding.ok, `payment create -> ${withWithholding.status} ${JSON.stringify(withWithholding.data)}`).toBeTruthy()
    expect(withWithholding.status).toBe(201)

    const certificates = await get(request, owner, `/withholding/certificates?partner_id=${customerId}`)
    expect(certificates.ok, `certificates list -> ${certificates.status} ${JSON.stringify(certificates.data)}`).toBeTruthy()
    const certList = certificates.data as unknown as Array<{
      document_id: string
      payment_id: string
      gross_amount: string
      withholding_rate: string
      withholding_amount: string
      net_amount: string
    }>
    const linkedCertificate = certList.find((c) => c.document_id === inv.id)
    expect(linkedCertificate, 'a withholding certificate linked to the invoice must exist').toBeTruthy()
    expect(linkedCertificate?.payment_id).toBe(withWithholding.data.id)
    expect(linkedCertificate?.gross_amount, 'gross_amount matches the invoice total').toBe('1000.000')
    expect(linkedCertificate?.withholding_rate, 'withholding_rate stored as the submitted fraction').toBe('0.0150')
    expect(linkedCertificate?.withholding_amount, 'C5: 1000.000 * 0.0150 = 15.000, not 0.001').toBe('15.000')
    expect(linkedCertificate?.net_amount, 'net_amount = gross - withholding').toBe('985.000')

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

  // A1.4 (NEW MTP-TRE-78, plan §A1.1 item 4 / D7): refund and partial-refund
  // must both fail closed (422) on a payment whose instrument is still
  // `received` — the money-leak guard (phantom repository outflow) proven at
  // 1a61900d9 review C1/C2/C3. Previously only backend-tested
  // (DeferredTenderGuardsTest.php:238-252,288-303) — zero web coverage.
  test('MTP-TRE-78: refund AND partial-refund both fail closed on a Received-instrument payment (D7)', async ({
    request,
  }) => {
    const customerId = await createCustomer(request, owner, uniqueName('TRE78'))
    const inv = await createPostedInvoice(request, owner, customerId, '400.000', 'W1 MTP-TRE-78 invoice')
    const payment = await createPayment(request, owner, {
      partner_id: customerId,
      payment_method_id: checkMethodId,
      repository_id: cashRepoId,
      amount: '400.000',
      currency: 'TND',
      payment_date: TODAY,
      allocations: [{ document_id: inv.id, amount: '400.000' }],
      instrument: {
        reference: uniqueName('CHQ78'),
        maturity_date: '2026-12-31',
        drawer_name: 'W1 Drawer TRE-78',
      },
    })
    expect(payment.ok, `payment w/ instrument -> ${payment.status} ${JSON.stringify(payment.data)}`).toBeTruthy()
    const paymentId = payment.data.id as string
    const instrumentId = payment.data.instrument_id as string
    expect(instrumentId, 'payment carries an instrument_id').toBeTruthy()

    const instrument = await get(request, owner, `/payment-instruments/${instrumentId}`)
    expect(instrument.data.status, 'instrument starts received (not yet cleared/deposited)').toBe('received')

    const refundAttempt = await post(request, owner, `/payments/${paymentId}/refund`, {
      reason: 'W1 MTP-TRE-78 refund on a received instrument',
      refund_request_id: crypto.randomUUID(),
    })
    expect(refundAttempt.status, 'D7: refund fails closed (422) on a Received instrument').toBe(422)

    const partialRefundAttempt = await post(request, owner, `/payments/${paymentId}/partial-refund`, {
      amount: '100.000',
      reason: 'W1 MTP-TRE-78 partial-refund on a received instrument',
      refund_request_id: crypto.randomUUID(),
    })
    expect(
      partialRefundAttempt.status,
      'D7: partial-refund fails closed (422) on a Received instrument',
    ).toBe(422)

    const invUnchanged = await get(request, owner, `/documents/${inv.id}`)
    expect(invUnchanged.data.balance_due, 'no money moved by either refused attempt').toBe('0.000')
  })

  // A1.5 (NEW MTP-TRE-79, plan §A1.1 item 5 / D8 + review C4): the existing
  // MTP-TRE-15 sends withholding_rate as a JSON STRING. This case sends the
  // exact round-1 TypeError payload shape — a JSON NUMBER, unquoted — which
  // must be normalised identically by PaymentController::normalizeWithholdingRate()
  // and produce the SAME certificate values. Also covers the 4 domain edges.
  test('MTP-TRE-79: withholding_rate as a JSON NUMBER (not string) — 201 + identical certificate values; domain edges 422', async ({
    request,
  }) => {
    const customerId = await createCustomer(request, owner, uniqueName('TRE79'))
    const inv = await createPostedInvoice(request, owner, customerId, '1000.000', 'W1 MTP-TRE-79 WHT invoice')

    const withNumericRate = await createPayment(request, owner, {
      partner_id: customerId,
      payment_method_id: cashMethodId,
      repository_id: cashRepoId,
      amount: '1000.000',
      currency: 'TND',
      payment_date: TODAY,
      allocations: [{ document_id: inv.id, amount: '1000.000' }],
      withholding_enabled: true,
      // JSON NUMBER, unquoted -- the exact round-1 TypeError payload shape
      // (createFromPayment()'s ?float parameter vs. a numeric STRING).
      withholding_rate: 0.015,
    })
    expect(
      withNumericRate.ok,
      `payment create -> ${withNumericRate.status} ${JSON.stringify(withNumericRate.data)}`,
    ).toBeTruthy()
    expect(withNumericRate.status).toBe(201)

    const certificates = await get(request, owner, `/withholding/certificates?partner_id=${customerId}`)
    expect(
      certificates.ok,
      `certificates list -> ${certificates.status} ${JSON.stringify(certificates.data)}`,
    ).toBeTruthy()
    const certList = certificates.data as unknown as Array<{
      document_id: string
      gross_amount: string
      withholding_rate: string
      withholding_amount: string
      net_amount: string
    }>
    const linkedCertificate = certList.find((c) => c.document_id === inv.id)
    expect(linkedCertificate, 'a withholding certificate linked to the invoice must exist').toBeTruthy()
    expect(linkedCertificate?.gross_amount, 'gross_amount matches the invoice total').toBe('1000.000')
    expect(
      linkedCertificate?.withholding_rate,
      'withholding_rate stored as the submitted fraction, JSON-number payload normalised identically to MTP-TRE-15\'s string payload',
    ).toBe('0.0150')
    expect(linkedCertificate?.withholding_amount, 'identical to MTP-TRE-15: 1000.000 * 0.0150 = 15.000').toBe(
      '15.000',
    )
    expect(linkedCertificate?.net_amount).toBe('985.000')

    // Domain edges (all JSON numbers): 5.0E-5 / 0.00015 exceed the 4dp ceiling;
    // 1.5 exceeds the 0-1 (fraction) range; -0.1 fails both the regex (no sign
    // allowed) and min:0.
    const edgeCases: Array<{ label: string; rate: number }> = [
      { label: '5.0E-5', rate: 5.0e-5 },
      { label: '0.00015', rate: 0.00015 },
      { label: '1.5', rate: 1.5 },
      { label: '-0.1', rate: -0.1 },
    ]
    for (const edge of edgeCases) {
      const edgeCustomerId = await createCustomer(request, owner, uniqueName(`TRE79${edge.label.replace(/[^\w]/g, '')}`))
      const edgeInv = await createPostedInvoice(
        request,
        owner,
        edgeCustomerId,
        '500.000',
        `W1 MTP-TRE-79 edge ${edge.label}`,
      )
      const result = await createPayment(request, owner, {
        partner_id: edgeCustomerId,
        payment_method_id: cashMethodId,
        repository_id: cashRepoId,
        amount: '500.000',
        currency: 'TND',
        payment_date: TODAY,
        allocations: [{ document_id: edgeInv.id, amount: '500.000' }],
        withholding_enabled: true,
        withholding_rate: edge.rate,
      })
      expect(result.status, `withholding_rate ${edge.label} (JSON number) rejected (422)`).toBe(422)
    }
  })

  // A1.6 (NEW MTP-TRE-80, TRIPWIRE T-C/N4, plan §A1.1 item 6): partial-refund
  // then reverse the SAME payment. The lineage-wide allocation wipe
  // (reversePayment(), PaymentRefundService.php ~657-681, review C6) restores
  // the invoice fully; the refund CHILD payment itself is not deleted/reversed
  // -- it survives as an orphan (real cash movement + GL entry, zero
  // allocations), per ticket 2026-08-02-treasury-fix-lane-minor-followups.md
  // N4. This TRIPWIRES today's behaviour; when N4 is fixed this must flip.
  test('MTP-TRE-80: partial-refund then reverse — balance restores fully; TRIPWIRE (N4) orphan refund child persists', async ({
    request,
  }) => {
    test.setTimeout(90000)
    const customerId = await createCustomer(request, owner, uniqueName('TRE80'))
    const inv = await createPostedInvoice(request, owner, customerId, '600.000', 'W1 MTP-TRE-80 invoice')
    const payment = await createPayment(request, owner, {
      partner_id: customerId,
      payment_method_id: cashMethodId,
      repository_id: cashRepoId,
      amount: '600.000',
      currency: 'TND',
      payment_date: TODAY,
      allocations: [{ document_id: inv.id, amount: '600.000' }],
    })
    expect(payment.ok).toBeTruthy()
    const paymentId = payment.data.id as string

    const partialRefund = await post(request, owner, `/payments/${paymentId}/partial-refund`, {
      amount: '250.000',
      reason: 'W1 MTP-TRE-80 partial refund before reverse',
      refund_request_id: crypto.randomUUID(),
    })
    expect(
      partialRefund.ok,
      `partial refund -> ${partialRefund.status} ${JSON.stringify(partialRefund.data)}`,
    ).toBeTruthy()

    const invAfterPartial = await get(request, owner, `/documents/${inv.id}`)
    expect(invAfterPartial.data.balance_due, 'partial refund reopens 250.000').toBe('250.000')

    // Capture the refund child's id BEFORE reversing (it cannot be read back
    // off the deleted allocation rows afterward).
    const historyBefore = await get(request, owner, `/payments/${paymentId}/refund-history`)
    const refundsBefore = historyBefore.data.refunds as unknown as Array<{ id: string }>
    expect(refundsBefore.length, 'exactly one refund child from the partial refund above').toBe(1)
    const refundChildId = refundsBefore[0].id

    const reverse = await post(request, owner, `/payments/${paymentId}/reverse`, {
      reason: 'W1 MTP-TRE-80 reverse after partial refund',
    })
    expect(reverse.ok, `reverse -> ${reverse.status} ${JSON.stringify(reverse.data)}`).toBeTruthy()

    const invAfterReverse = await get(request, owner, `/documents/${inv.id}`)
    expect(
      invAfterReverse.data.balance_due,
      'reverse wipes the WHOLE lineage allocation (original + refund child), restoring the invoice to its pre-payment 600.000',
    ).toBe('600.000')
    expect(invAfterReverse.data.status, 'reverse reverts Paid -> Posted (D9 recompute path)').toBe('posted')

    const refundChildAfter = await get(request, owner, `/payments/${refundChildId}`)
    expect(refundChildAfter.ok, `refund child GET -> ${refundChildAfter.status}`).toBeTruthy()
    expect(refundChildAfter.data.payment_type, 'TRIPWIRE (N4): orphan refund child persists, not deleted').toBe(
      'refund',
    )
    expect(
      refundChildAfter.data.status,
      'TRIPWIRE (N4): orphan refund child is NOT reversed/voided alongside the original',
    ).toBe('completed')
    const refundChildAllocations = (refundChildAfter.data.allocations ?? []) as unknown[]
    expect(
      refundChildAllocations,
      'TRIPWIRE (N4): orphan refund child carries zero allocations after the lineage-wide wipe',
    ).toHaveLength(0)
  })
})
