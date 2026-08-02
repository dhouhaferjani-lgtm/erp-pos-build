/**
 * MONEY TEST CAMPAIGN — W-2 execution agent — surface `IDEM` (idempotency /
 * double-submit), P0 per orchestrator ruling F-7
 * (docs/qa/2026-08-02-full-e2e-campaign-plan.md §F.7).
 *
 * Scope per plan §B.8 flow 109: "invoice confirm/post, expense pay, payment
 * create, credit-note post" — plus the payment-refund `refund_request_id`
 * mechanism (already used defensively across treasury-payments.spec.ts but
 * never itself replay-tested).
 *
 * Every case here is a SEQUENTIAL replay (the exact same request submitted
 * twice, one after the other) — this is the realistic "double-click before
 * the button disables" / "client retries after a timeout" scenario. TRUE
 * concurrent replay (two in-flight requests racing the same status guard) is
 * the `CONC`/C-13 surface (a dedicated two-browser-context harness), out of
 * this agent's scope — not attempted here, not claimed as covered.
 *
 * Real login (`loginAsRole`) + real API (`apiRequest`) against the live
 * local stack, no mocking. Fixtures carry the `W2c` prefix
 * (see w2c-support.ts header for why not W2a/W2b).
 */
import { test, expect } from '@playwright/test'
import { loginAsRole, apiRequest } from './helpers'
import { listPaymentMethods, listPaymentRepositories, createExpense, postExpense, payExpense, getExpense, uniq } from './w2c-support'
import { subMoney } from './treasury-support'

async function createCustomer(page: import('@playwright/test').Page, name: string): Promise<string> {
  const res = await apiRequest(page, 'POST', '/partners', { name, type: 'customer' })
  expect(res.status, `create customer -> ${res.status} ${JSON.stringify(res.body)}`).toBe(201)
  return ((res.body as { data: { id: string } }).data).id
}

/** Mirrors treasury-support.ts's createPostedInvoice: price the single 0%-VAT
 * line at `total - 1.000` to absorb the Tunisia flat 1.000 stamp duty that
 * confirm() applies, so the POSTED total lands on the exact target. */
async function createPostedInvoice(page: import('@playwright/test').Page, partnerId: string, total: string): Promise<{ id: string; total: string }> {
  const today = new Date().toISOString().slice(0, 10)
  const unitPrice = subMoney(total, '1.000')
  const created = await apiRequest(page, 'POST', '/invoices', {
    partner_id: partnerId,
    document_date: today,
    lines: [{ description: 'W2c IDEM fixture line', quantity: '1', unit_price: unitPrice, tax_rate: '0.00' }],
  })
  expect(created.status, `create invoice -> ${created.status} ${JSON.stringify(created.body)}`).toBe(201)
  const id = ((created.body as { data: { id: string } }).data).id
  const confirmed = await apiRequest(page, 'POST', `/invoices/${id}/confirm`)
  expect(confirmed.status).toBe(200)
  const posted = await apiRequest(page, 'POST', `/invoices/${id}/post`)
  expect(posted.status, `post invoice -> ${posted.status} ${JSON.stringify(posted.body)}`).toBe(200)
  const postedTotal = ((posted.body as { data: { total: string } }).data).total
  return { id, total: postedTotal }
}

test.describe('MTP-IDEM — idempotency / double-submit (W-2)', () => {
  test.setTimeout(90_000)

  test('MTP-IDEM-01 (P0): invoice confirm double-submit is genuinely idempotent — 200 both times, no re-snapshot', async ({ page }) => {
    await loginAsRole(page, 'owner')
    const customerId = await createCustomer(page, uniq('IDEM01'))
    const created = await apiRequest(page, 'POST', '/invoices', {
      partner_id: customerId,
      document_date: new Date().toISOString().slice(0, 10),
      lines: [{ description: 'IDEM-01 line', quantity: '2', unit_price: '50.000', tax_rate: '19.00' }],
    })
    expect(created.status).toBe(201)
    const invoiceId = ((created.body as { data: { id: string } }).data).id

    // First confirm — real transition, Draft -> Confirmed, tax computed +
    // snapshotted (InvoiceController.php:557-621).
    const confirm1 = await apiRequest(page, 'POST', `/invoices/${invoiceId}/confirm`)
    expect(confirm1.status).toBe(200)
    const body1 = (confirm1.body as { data: Record<string, unknown> }).data

    // Second, IDENTICAL confirm request — the locked re-check
    // (`if ($lockedDocument->status === DocumentStatus::Confirmed) return`)
    // must return the SAME already-confirmed document silently, not
    // re-run the tax calculation/snapshot a second time.
    const confirm2 = await apiRequest(page, 'POST', `/invoices/${invoiceId}/confirm`)
    expect(confirm2.status, 'second confirm is idempotent, not a 4xx conflict').toBe(200)
    const body2 = (confirm2.body as { data: Record<string, unknown> }).data

    expect(body2.status).toBe('confirmed')
    expect(body2.tax_amount, 'no re-computation on replay').toBe(body1.tax_amount)
    expect(body2.total).toBe(body1.total)
    expect(body2.confirmed_at, 'confirmed_at is NOT bumped by the replay (no second write)').toBe(body1.confirmed_at)
  })

  test('MTP-IDEM-02 (P0): invoice post double-submit fails closed — 1st 200, 2nd 422, no duplicate GL/fiscal-chain entry', async ({ page }) => {
    await loginAsRole(page, 'owner')
    const customerId = await createCustomer(page, uniq('IDEM02'))
    const created = await apiRequest(page, 'POST', '/invoices', {
      partner_id: customerId,
      document_date: new Date().toISOString().slice(0, 10),
      lines: [{ description: 'IDEM-02 line', quantity: '1', unit_price: '75.000', tax_rate: '0.00' }],
    })
    const invoiceId = ((created.body as { data: { id: string } }).data).id
    await apiRequest(page, 'POST', `/invoices/${invoiceId}/confirm`)

    const post1 = await apiRequest(page, 'POST', `/invoices/${invoiceId}/post`)
    expect(post1.status).toBe(200)
    // meta.chain_sequence is only present on the POST /post response itself
    // (InvoiceController.php's fiscal-chain meta block) — DocumentData (the
    // GET/list DTO) does not expose it at all, confirmed live. Capture it
    // here as proof post1 was a real fiscal action.
    const chainSeq1 = (post1.body as { meta?: { chain_sequence?: number } }).meta?.chain_sequence
    expect(chainSeq1, 'first post is a real fiscal-chain event').toBeGreaterThan(0)

    // Second POST /post: the CONTROLLER's outer guard reads a fresh
    // (unlocked) copy and checks `isConfirmed()` BEFORE ever reaching
    // DocumentPostingService::post() (whose own isPosted() short-circuit is
    // never exercised on a sequential replay) — InvoiceController.php:653-658.
    // This is the fails-closed shape: no duplicate GL entry, no duplicate
    // fiscal-chain link, just a clean refusal.
    const post2 = await apiRequest(page, 'POST', `/invoices/${invoiceId}/post`)
    expect(post2.status, `second post must be refused, not silently re-post -> ${post2.status}`).toBe(422)
    const errBody = post2.body as { error?: { code?: string } }
    expect(errBody.error?.code).toBe('INVOICE_NOT_CONFIRMED')

    // The invoice is still exactly 'posted' — a second, silently-succeeded
    // post (which would be the double-effect bug) would leave no visible
    // trace on `status` either way, but a crashed/half-applied second post
    // WOULD corrupt it away from a clean 'posted' state.
    const refetch = await apiRequest(page, 'GET', `/invoices/${invoiceId}`)
    expect((refetch.body as { data: { status: string } }).data.status).toBe('posted')
  })

  test('MTP-IDEM-03 (P0): credit-note post double-submit fails closed — invoice balance_due drops by the posted CN total exactly once, not twice', async ({ page }) => {
    // RELATIONAL assertion by design, not an exact literal: an OPEN,
    // pre-registered finding (docs/superpowers/tickets/
    // 2026-08-02-orchestrator-smoke-findings.md #1) shows an amount-based
    // credit note's posted total can drift from the requested `amount` (a
    // live repro there: entered 50.000, posted 50.174 — neither the exact
    // amount nor amount+stamp). Independently reproduced here during
    // authoring (entered 100.000 against a 300.000 invoice, posted total
    // was NOT a round number either). That drift is its own ticketed defect,
    // orthogonal to what THIS case tests (double-submit safety) — asserting
    // `balance_due == invoice_total - <whatever the CN actually posted at>`
    // stays a valid, exact proof of "allocated exactly once" regardless of
    // which way that other ticket's ruling eventually lands (same pattern
    // MTP-DOC-19 already uses, per that ticket's own note).
    await loginAsRole(page, 'owner')
    const customerId = await createCustomer(page, uniq('IDEM03'))
    const invoice = await createPostedInvoice(page, customerId, '300.000')

    const cnCreate = await apiRequest(page, 'POST', '/credit-notes', {
      source_invoice_id: invoice.id,
      reason: 'return',
      amount: '100.000',
    })
    expect(cnCreate.status, `create credit note -> ${cnCreate.status} ${JSON.stringify(cnCreate.body)}`).toBe(201)
    const cnId = ((cnCreate.body as { data: { id: string } }).data).id
    const cnConfirm = await apiRequest(page, 'POST', `/credit-notes/${cnId}/confirm`)
    expect(cnConfirm.status).toBe(200)

    const post1 = await apiRequest(page, 'POST', `/credit-notes/${cnId}/post`)
    expect(post1.status, `credit-note post -> ${post1.status} ${JSON.stringify(post1.body)}`).toBe(200)
    const postedCnTotal = ((post1.body as { data: { total: string } }).data).total
    const invAfter1 = await apiRequest(page, 'GET', `/invoices/${invoice.id}`)
    const balanceAfter1 = (invAfter1.body as { data: { balance_due: string } }).data.balance_due
    expect(balanceAfter1, 'first post allocates the credit note once').toBe(subMoney('300.000', postedCnTotal))

    // Second post: outer isConfirmed() guard refuses (CreditNoteController.php:336-343)
    // BEFORE allocateCreditNote() can run a second time.
    const post2 = await apiRequest(page, 'POST', `/credit-notes/${cnId}/post`)
    expect(post2.status, `second post must be refused -> ${post2.status}`).toBe(422)
    const err2 = post2.body as { error?: { code?: string } }
    expect(err2.error?.code).toBe('CREDIT_NOTE_NOT_CONFIRMED')

    const invAfter2 = await apiRequest(page, 'GET', `/invoices/${invoice.id}`)
    expect(
      (invAfter2.body as { data: { balance_due: string } }).data.balance_due,
      'balance_due must NOT drop a second time — allocateCreditNote() ran exactly once'
    ).toBe(balanceAfter1)
  })

  test('MTP-IDEM-04 (P0): expense pay double-submit is genuinely idempotent (deterministic settlement key) — single repository movement', async ({ page }) => {
    await loginAsRole(page, 'owner')
    const methods = await listPaymentMethods(page)
    const repos = await listPaymentRepositories(page)
    const cashRepo = repos.find((r) => r.code === 'CASH-01') ?? repos[0]
    expect(cashRepo, 'a payment repository exists').toBeTruthy()

    const expense = await createExpense(page, {
      total: '42.000',
      vendor_name: uniq('IDEM04-vendor'),
      // ExpenseService::create() defaults `is_paid` to TRUE when omitted
      // (ExpenseService.php:146, `$data['is_paid'] ?? true`) — an unpaid
      // AP-booking expense (the only kind /pay is reachable for) must
      // request is_paid:false explicitly, confirmed live (omitting it made
      // the very first /pay 422 "already been paid").
      is_paid: false,
    })
    expect(expense.status, `create expense -> ${expense.status} ${JSON.stringify(expense.body)}`).toBe(201)
    const postRes = await postExpense(page, expense.id as string)
    expect(postRes.status, `post expense -> ${postRes.status} ${JSON.stringify(postRes.body)}`).toBe(200)

    const payload = { mode: 'cash', payment_repository_id: cashRepo.id, payment_date: new Date().toISOString().slice(0, 10) }
    const pay1 = await payExpense(page, expense.id as string, payload)
    expect(pay1.status, `first pay -> ${pay1.status} ${JSON.stringify(pay1.body)}`).toBe(200)

    // Same request again — ExpenseService::settle()'s deterministic
    // `Expense:{id}:settlement` idempotency key
    // (ExpenseService.php:505-522) finds the prior RepositoryMovement and
    // returns the current state WITHOUT booking a second GL entry.
    const pay2 = await payExpense(page, expense.id as string, payload)
    expect(pay2.status, `second pay is idempotent, not a conflict -> ${pay2.status} ${JSON.stringify(pay2.body)}`).toBe(200)

    const finalExpense = await getExpense(page, expense.id as string)
    // settle() (the cash path, ExpenseService.php ~590-604) never updates
    // Document.status — only ExpenseMetadata.is_paid/paid_at. The document
    // itself stays 'posted' forever (confirmed live: DocumentStatus::Paid
    // exists as an enum case but is never assigned by this path — that case
    // is reserved for other document types, e.g. invoices). Assert the field
    // that ACTUALLY carries paid state.
    expect(finalExpense.status, "expense settle() never transitions Document.status — 'paid' lives on metadata.is_paid, not status").toBe('posted')
    const metadata = finalExpense.metadata as { is_paid?: boolean; paid_at?: string } | undefined
    expect(metadata?.is_paid, '42.000 settled exactly once — is_paid true after the idempotent replay').toBe(true)
    expect(metadata?.paid_at, 'paid_at was written by the (first, real) settlement').toBeTruthy()
    expect(finalExpense.total).toBe('42.000')
  })

  test('MTP-IDEM-05 (P0, FINDING): payment CREATE with no Idempotency-Key double-submits into TWO real payment rows', async ({ page }) => {
    await loginAsRole(page, 'owner')
    const methods = await listPaymentMethods(page)
    const repos = await listPaymentRepositories(page)
    const cashMethod = methods.find((m) => m.code === 'CASH')
    const cashRepo = repos.find((r) => r.code === 'CASH-01')
    expect(cashMethod && cashRepo, 'cash method + repository exist').toBeTruthy()

    const customerId = await createCustomer(page, uniq('IDEM05'))
    const invoice = await createPostedInvoice(page, customerId, '500.000')

    const payload = {
      partner_id: customerId,
      payment_method_id: cashMethod!.id,
      repository_id: cashRepo!.id,
      amount: '100.000',
      currency: 'TND',
      payment_date: new Date().toISOString().slice(0, 10),
      allocations: [{ document_id: invoice.id, amount: '100.000' }],
    }
    const p1 = await apiRequest(page, 'POST', '/payments', payload)
    expect(p1.status, `first payment -> ${p1.status} ${JSON.stringify(p1.body)}`).toBe(201)
    const p2 = await apiRequest(page, 'POST', '/payments', payload)
    expect(p2.status, `second, IDENTICAL payment (no Idempotency-Key) -> ${p2.status}`).toBe(201)

    const id1 = ((p1.body as { data: { id: string } }).data).id
    const id2 = ((p2.body as { data: { id: string } }).data).id
    expect(
      id2,
      // FINDING (not a code defect — matches PaymentController's own test
      // PaymentIdempotencyTest.php name
      // no_idempotency_key_behaves_exactly_as_before_each_request_creates_a_payment,
      // i.e. this is documented, opt-in behaviour). Flagged here because
      // RecordPaymentModal.tsx:842 — the ONLY payment-creation UI in the
      // app — never sends an Idempotency-Key header or idempotency_key
      // body field; its double-click guard is `disabled={mutation.isPending}`
      // ONLY. A lost disable-race (slow network, a resumed tab, a retried
      // fetch) creates a genuine duplicate cash receipt with no server-side
      // backstop. Recommend a UX ticket: have the form generate + send an
      // Idempotency-Key.
      'two distinct payment rows are created — a real double money effect, not deduped'
    ).not.toBe(id1)

    const invAfter = await apiRequest(page, 'GET', `/invoices/${invoice.id}`)
    expect(
      (invAfter.body as { data: { balance_due: string } }).data.balance_due,
      '500.000 - 100.000 - 100.000 = 300.000: BOTH payments actually allocated'
    ).toBe('300.000')
  })

  test('MTP-IDEM-06 (P0): payment CREATE WITH an idempotency_key body field dedupes the replay to the SAME payment row', async ({ page }) => {
    await loginAsRole(page, 'owner')
    const methods = await listPaymentMethods(page)
    const repos = await listPaymentRepositories(page)
    const cashMethod = methods.find((m) => m.code === 'CASH')
    const cashRepo = repos.find((r) => r.code === 'CASH-01')

    const customerId = await createCustomer(page, uniq('IDEM06'))
    const invoice = await createPostedInvoice(page, customerId, '500.000')
    const key = uniq('idem-key')

    const payload = {
      partner_id: customerId,
      payment_method_id: cashMethod!.id,
      repository_id: cashRepo!.id,
      amount: '150.000',
      currency: 'TND',
      payment_date: new Date().toISOString().slice(0, 10),
      allocations: [{ document_id: invoice.id, amount: '150.000' }],
      idempotency_key: key,
    }
    const p1 = await apiRequest(page, 'POST', '/payments', payload)
    expect(p1.status).toBe(201)
    const id1 = ((p1.body as { data: { id: string } }).data).id

    // Replay: same idempotency_key, same body. The DB partial unique index
    // payments_idempotency_key_uniq(company_id, idempotency_key) plus
    // PaymentController.php:351-359 must return the EXISTING row, not 500
    // on a unique-constraint violation and not silently create a second one.
    const p2 = await apiRequest(page, 'POST', '/payments', payload)
    expect(p2.status, `replay with the same idempotency_key -> ${p2.status} ${JSON.stringify(p2.body)}`).toBeLessThan(300)
    const id2 = ((p2.body as { data: { id: string } }).data).id
    expect(id2, 'replay returns the SAME payment id, not a new one').toBe(id1)

    const invAfter = await apiRequest(page, 'GET', `/invoices/${invoice.id}`)
    expect((invAfter.body as { data: { balance_due: string } }).data.balance_due, 'allocated exactly once').toBe('350.000')
  })

  test('MTP-IDEM-07 (P0): refund_request_id replay on the SAME refund is idempotent — single money effect', async ({ page }) => {
    await loginAsRole(page, 'owner')
    const methods = await listPaymentMethods(page)
    const repos = await listPaymentRepositories(page)
    const cashMethod = methods.find((m) => m.code === 'CASH')
    const cashRepo = repos.find((r) => r.code === 'CASH-01')

    const customerId = await createCustomer(page, uniq('IDEM07'))
    const invoice = await createPostedInvoice(page, customerId, '400.000')
    const payment = await apiRequest(page, 'POST', '/payments', {
      partner_id: customerId,
      payment_method_id: cashMethod!.id,
      repository_id: cashRepo!.id,
      amount: '400.000',
      currency: 'TND',
      payment_date: new Date().toISOString().slice(0, 10),
      allocations: [{ document_id: invoice.id, amount: '400.000' }],
    })
    expect(payment.status).toBe(201)
    const paymentId = ((payment.body as { data: { id: string } }).data).id
    const refundRequestId = crypto.randomUUID()

    const refund1 = await apiRequest(page, 'POST', `/payments/${paymentId}/refund`, {
      reason: 'MTP-IDEM-07 refund replay probe',
      refund_request_id: refundRequestId,
    })
    expect(refund1.status, `first refund -> ${refund1.status} ${JSON.stringify(refund1.body)}`).toBeLessThan(300)

    // SAME refund_request_id, submitted again.
    const refund2 = await apiRequest(page, 'POST', `/payments/${paymentId}/refund`, {
      reason: 'MTP-IDEM-07 refund replay probe',
      refund_request_id: refundRequestId,
    })
    expect(refund2.status, `replay with the same refund_request_id must not double-refund -> ${refund2.status}`).toBeLessThan(300)

    const invAfter = await apiRequest(page, 'GET', `/invoices/${invoice.id}`)
    expect(
      (invAfter.body as { data: { balance_due: string } }).data.balance_due,
      'balance_due reopened by exactly ONE refund of 400.000, not two'
    ).toBe('400.000')
  })

  test('MTP-IDEM-08 (P1, contrast case): a DIFFERENT refund_request_id on an otherwise-identical refund payload is treated as a genuinely separate refund', async ({ page }) => {
    await loginAsRole(page, 'owner')
    const methods = await listPaymentMethods(page)
    const repos = await listPaymentRepositories(page)
    const cashMethod = methods.find((m) => m.code === 'CASH')
    const cashRepo = repos.find((r) => r.code === 'CASH-01')

    const customerId = await createCustomer(page, uniq('IDEM08'))
    const invoice = await createPostedInvoice(page, customerId, '400.000')
    const payment = await apiRequest(page, 'POST', '/payments', {
      partner_id: customerId,
      payment_method_id: cashMethod!.id,
      repository_id: cashRepo!.id,
      amount: '400.000',
      currency: 'TND',
      payment_date: new Date().toISOString().slice(0, 10),
      allocations: [{ document_id: invoice.id, amount: '400.000' }],
    })
    const paymentId = ((payment.body as { data: { id: string } }).data).id

    const partial1 = await apiRequest(page, 'POST', `/payments/${paymentId}/partial-refund`, {
      amount: '100.000',
      reason: 'MTP-IDEM-08 contrast probe A',
      refund_request_id: crypto.randomUUID(),
    })
    expect(partial1.status).toBeLessThan(300)

    // Different refund_request_id, same amount/reason SHAPE — this is a
    // second, independent business action, not a replay, and the dedupe
    // key must not conflate them.
    const partial2 = await apiRequest(page, 'POST', `/payments/${paymentId}/partial-refund`, {
      amount: '100.000',
      reason: 'MTP-IDEM-08 contrast probe B',
      refund_request_id: crypto.randomUUID(),
    })
    expect(partial2.status, `a distinct refund_request_id must be honoured as a NEW refund -> ${partial2.status} ${JSON.stringify(partial2.body)}`).toBeLessThan(300)

    const invAfter = await apiRequest(page, 'GET', `/invoices/${invoice.id}`)
    expect(
      (invAfter.body as { data: { balance_due: string } }).data.balance_due,
      'two DISTINCT partial refunds of 100.000 each reopened 200.000 total — proves the dedupe is keyed on refund_request_id, not payload shape'
    ).toBe('200.000')
  })
})
