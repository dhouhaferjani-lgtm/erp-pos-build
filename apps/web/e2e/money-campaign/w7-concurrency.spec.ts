/**
 * MONEY TEST CAMPAIGN — wave W-7 — `MTP-CONC-01..06`: concurrency and stale
 * edits (money-test-plan §I.5; campaign plan §C debt item **C-13**, "No
 * concurrency harness … Nothing in the repo does this today").
 *
 * The harness C-13 asks for now lives in `helpers.ts`:
 *   * `withTwoSessions()` — two REAL browser contexts (own cookie jar, own
 *     token, own client cache). The only way to model a STALE EDIT.
 *   * `raceTwo()` — `Promise.allSettled` over two thunks fired from the API
 *     layer, for simultaneous-POST replays. Driving two BROWSERS through a UI
 *     flow serialises on render and proves nothing about the server's race
 *     window, so the money races below are fired as two `fetch`es.
 *
 * ── HOW EVERY CASE IS JUDGED ──────────────────────────────────────────────
 * Never "the second call failed" — a duplicate-suppressing product may
 * legitimately answer 200 twice while writing once. Always **exactly one
 * money effect**: one allocation, one repository movement, one stock blend.
 * Where the product's answer is a deliberate last-write-wins with no version
 * check, that is recorded as a GREEN TRIPWIRE (the assertion pins TODAY's
 * behaviour and goes red when it changes) plus a ticket — not as a silent
 * pass and not as a red assertion.
 *
 * Everything authored here carries the `W7-` prefix.
 */
import { test, expect } from '@playwright/test'
import { apiRequest, raceTwo, withTwoSessions } from './helpers'
import { type Session } from './treasury-support'
import { accountsByPurpose, ledgerLinesFor, movementsFor } from './w5c-support'
import {
  TODAY,
  createCustomer,
  createDraftInvoice,
  createSupplier,
  getInvoice,
  isCleanupSuccess,
  loginAsRoleResilient,
  loginResilient,
  paymentMethodIdByCode,
  postInvoice,
  repositoryIdByCode,
  retireDraftInvoice,
  sumMoney,
  uniq,
} from './w7-support'

const WAREHOUSE_CODE = 'WH-01'
const CASH_REPOSITORY_CODE = 'CASH-01'

test.describe('CONC — concurrency and stale edits', () => {
  test.setTimeout(180_000)

  let owner: Session

  test.beforeAll(async ({ request }, workerInfo) => {
    // Shared-state runtime guard (house rule): these cases race the SAME
    // server and reconcile per-source money effects; a parallel worker firing
    // its own races would make "exactly one movement for MY source id" true
    // but the surrounding timing meaningless.
    expect(
      workerInfo.config.workers,
      'CONC must run at --workers=1: it races the live API deliberately',
    ).toBe(1)
    owner = await loginResilient(request, 'owner')
  })

  test('MTP-CONC-01 (P0): FINDING — a stale second save silently overwrites a draft money document (last-write-wins)', async ({
    browser,
  }) => {
    await withTwoSessions(browser, { a: 'owner', b: 'manager' }, async ({ pageA, pageB }) => {
      const partnerId = await createCustomer(pageA, 'CONC01')
      const { id: invoiceId } = await createDraftInvoice(pageA, partnerId, [
        { description: 'W7 CONC-01 baseline', quantity: '1', unit_price: '100.000' },
      ])

      try {
        // BOTH sessions load the document — this is what makes B's later save
        // STALE rather than merely second.
        const loadedByA = await getInvoice(pageA, invoiceId)
        const loadedByB = await getInvoice(pageB, invoiceId)
        expect(loadedByB.total, 'both sessions loaded the same document state').toBe(loadedByA.total)
        const baseline = String(loadedByA.total)

        // A saves first.
        const saveA = await apiRequest(pageA, 'PATCH', `/invoices/${invoiceId}`, {
          lines: [{ description: 'W7 CONC-01 A edit', quantity: '2', unit_price: '100.000', tax_rate: '0.00' }],
        })
        expect(saveA.status, 'the first save succeeds').toBe(200)
        const totalAfterA = String((saveA.body as { data: { total: string } }).data.total)
        expect(totalAfterA, 'A really moved the money').not.toBe(baseline)

        // B saves SECOND, from the state it loaded BEFORE A's save.
        const saveB = await apiRequest(pageB, 'PATCH', `/invoices/${invoiceId}`, {
          lines: [{ description: 'W7 CONC-01 B edit (stale)', quantity: '3', unit_price: '100.000', tax_rate: '0.00' }],
        })

        // ── FINDING F-5 (TRIPWIRE, GREEN: pins TODAY's behaviour) ──────────
        // `documents` carries NO version / lock / etag column (53 columns,
        // none of them a concurrency token) and `UpdateDocumentRequest` asks
        // for none, so the second writer cannot be detected. B's save is
        // accepted with 200 and B's totals — computed from a document state
        // that no longer existed when B pressed save — become the document.
        // A's edit is gone with no notification to either party.
        //
        // The money-test-plan's own wording for this case is "a silent
        // last-write-wins on a money document is launch-blocking". Recorded
        // here as the observed behaviour; the grading is the orchestrator's.
        // Scope of the exposure: DRAFT documents only — a posted invoice is
        // immutable (MTP-DOC-08) — so no FISCAL record is at risk, but a
        // draft invoice is still the thing a user is about to bill from.
        expect(
          saveB.status,
          'TRIPWIRE F-5: the stale second save is ACCEPTED — no 409, no conflict, no refetch prompt',
        ).toBe(200)

        const final = await getInvoice(pageA, invoiceId)
        const finalLines = final.lines as Array<{ description: string; quantity: string }>
        expect(
          finalLines.map((l) => l.description),
          'TRIPWIRE F-5: the LAST writer won outright — A`s line is not merged, it is gone',
        ).toEqual(['W7 CONC-01 B edit (stale)'])
        expect(
          String(final.total),
          'TRIPWIRE F-5: the surviving total is B`s, computed from stale state',
        ).toBe(String((saveB.body as { data: { total: string } }).data.total))

        // The one thing that IS right: the surviving document is internally
        // consistent — no interleaved half-state, no line from A next to a
        // total from B.
        expect(
          finalLines.length,
          'the surviving document is internally consistent (one writer`s payload, whole)',
        ).toBe(1)
      } finally {
        const retired = await retireDraftInvoice(pageA, invoiceId)
        test.info().annotations.push({
          type: 'CLEANUP',
          description: `DELETE /invoices/${invoiceId} -> ${retired}`,
        })
      }
    })
  })

  test('MTP-CONC-02 (P0): two simultaneous payments allocating the same balance produce EXACTLY ONE allocation', async ({
    page,
    request,
  }) => {
    await loginAsRoleResilient(page, 'owner')
    const manager = await loginResilient(request, 'manager')

    const partnerId = await createCustomer(page, 'CONC02')
    const { id: invoiceId } = await createDraftInvoice(page, partnerId, [
      { description: 'W7 CONC-02 fixture', quantity: '1', unit_price: '499.000' },
    ])
    const posted = await postInvoice(page, invoiceId)
    expect(posted.balance_due, 'the fixture invoice opens with its full balance').toBe(posted.total)

    const methodId = await paymentMethodIdByCode(page, 'CASH')
    const repositoryId = await repositoryIdByCode(page, CASH_REPOSITORY_CODE)
    const payload = {
      partner_id: partnerId,
      payment_method_id: methodId,
      repository_id: repositoryId,
      amount: posted.total,
      currency: 'TND',
      payment_date: TODAY,
      allocations: [{ document_id: invoiceId, amount: posted.total }],
    }

    // Fired from the API layer with two DIFFERENT principals' tokens, so the
    // two requests land within microseconds of each other.
    const race = await raceTwo(
      () => apiRequest(page, 'POST', '/payments', payload),
      () =>
        request.post('http://127.0.0.1:8010/api/v1/payments', {
          headers: {
            Authorization: `Bearer ${manager.token}`,
            Accept: 'application/json',
            'Content-Type': 'application/json',
          },
          data: payload,
        }).then(async (res) => ({ status: res.status(), body: await res.json() })),
    )
    expect(race.rejected, 'neither request threw at the transport layer').toEqual([])
    test.info().annotations.push({
      type: 'OBSERVED',
      description: `simultaneous POST /payments -> ${race.fulfilled.map((r) => r.status).join(' / ')}`,
    })

    // ── THE MONEY INVARIANT ───────────────────────────────────────────────
    // Exactly ONE allocation against the invoice, and never more than the
    // invoice's own total allocated to it. (The plan: "Exactly one allocation
    // persists; no double-allocation, no over-allocation past the invoice
    // balance.")
    const docPayments = await apiRequest(page, 'GET', `/documents/${invoiceId}/payments`)
    expect(docPayments.status).toBe(200)
    const doc = (docPayments.body as {
      data: {
        balance_due: string
        payment_allocations: Array<{ id: string; payment_id: string; allocated_amount?: string; amount?: string }>
      }
    }).data

    expect(
      doc.payment_allocations.length,
      'CONC-02: exactly ONE allocation survives the race',
    ).toBe(1)
    const allocated = sumMoney(
      doc.payment_allocations.map((a) => String(a.allocated_amount ?? a.amount ?? '0')),
    )
    expect(allocated, 'CONC-02: the allocated total equals the invoice total, never twice it').toBe(
      posted.total,
    )
    expect(doc.balance_due, 'CONC-02: the invoice is settled exactly once').toBe('0.000')

    // RECORDED (not a defect): the loser's money is not lost or rejected — it
    // becomes an UNALLOCATED on-account credit for the same partner. That is
    // a defensible answer to "the customer paid twice", and it is the
    // behaviour a successor should expect to find on this tenant.
    const paymentIds = race.fulfilled
      .map((r) => (r.body as { data?: { id?: string } }).data?.id)
      .filter((id): id is string => typeof id === 'string')
    expect(paymentIds.length, 'both racers created a payment row').toBe(2)
    const loserId = paymentIds.find((id) => id !== doc.payment_allocations[0]!.payment_id)
    expect(loserId, 'one of the two payments is the unallocated loser').toBeTruthy()
    const loser = await apiRequest(page, 'GET', `/payments/${loserId}`)
    expect(loser.status).toBe(200)
    const loserBody = (loser.body as { data: { amount: string; unallocated_amount: string } }).data
    expect(
      loserBody.unallocated_amount,
      'CONC-02: the losing payment survives in full as unallocated on-account credit',
    ).toBe(posted.total)

    // RECORDED (sibling of finding F-2): `allocated_amount` is emitted
    // WITHOUT a scale (`"500"`), while `unallocated_amount` on the same
    // payload is a correct scale-3 string (`"0.000"`). Two scales in one
    // object.
    const winner = await apiRequest(page, 'GET', `/payments/${doc.payment_allocations[0]!.payment_id}`)
    const winnerBody = (winner.body as { data: { allocated_amount: string; unallocated_amount: string } }).data
    expect(
      /^-?\d+\.\d{3}$/.test(String(winnerBody.unallocated_amount)),
      'unallocated_amount is a scale-3 money string',
    ).toBe(true)
    expect(
      /^-?\d+\.\d{3}$/.test(String(winnerBody.allocated_amount)),
      'TRIPWIRE F-2b: allocated_amount is NOT scale-3 on the same payload (recorded, not fixed)',
    ).toBe(false)
  })

  test('MTP-CONC-03 (P0): two simultaneous expense payments settle the expense EXACTLY ONCE', async ({
    page,
    request,
  }) => {
    await loginAsRoleResilient(page, 'owner')
    const manager = await loginResilient(request, 'manager')

    const supplierId = await createSupplier(page, 'CONC03')
    const methodId = await paymentMethodIdByCode(page, 'CASH')
    const repositoryId = await repositoryIdByCode(page, CASH_REPOSITORY_CODE)

    // `is_paid: false` EXPLICITLY — `ExpenseService::create()` defaults it to
    // TRUE when omitted (`$data['is_paid'] ?? true`), which would produce an
    // already-settled expense and make the race vacuous. (Documented by W-5c
    // in `w5c-support.ts`; re-confirmed live here.)
    const created = await apiRequest(page, 'POST', '/expenses', {
      partner_id: supplierId,
      total: '77.000',
      document_date: TODAY,
      is_paid: false,
      notes: uniq('CONC03'),
    })
    expect(created.status, `expense create -> ${created.status} ${JSON.stringify(created.body)}`).toBe(201)
    const expense = (created.body as { data: { id: string; metadata: { is_paid: boolean } } }).data
    expect(expense.metadata.is_paid, 'the fixture expense starts UNPAID').toBe(false)

    const postRes = await apiRequest(page, 'POST', `/expenses/${expense.id}/post`)
    expect(postRes.status, 'the expense posts').toBe(200)

    const payPayload = {
      amount: '77.000',
      payment_method_id: methodId,
      payment_repository_id: repositoryId,
      payment_date: TODAY,
    }
    const race = await raceTwo(
      () => apiRequest(page, 'POST', `/expenses/${expense.id}/pay`, payPayload),
      () =>
        request.post(`http://127.0.0.1:8010/api/v1/expenses/${expense.id}/pay`, {
          headers: {
            Authorization: `Bearer ${manager.token}`,
            Accept: 'application/json',
            'Content-Type': 'application/json',
          },
          data: payPayload,
        }).then(async (res) => ({ status: res.status(), body: await res.json() })),
    )
    expect(race.rejected, 'neither request threw at the transport layer').toEqual([])
    test.info().annotations.push({
      type: 'OBSERVED',
      description: `simultaneous POST /expenses/{id}/pay -> ${race.fulfilled.map((r) => r.status).join(' / ')}`,
    })

    // ── I5 (review fix round 1): BOTH racers must have REACHED THE WRITE
    // PATH. Without this, a permission or routing regression that turns one
    // racer into a 403/404 leaves a test that still "passes" while proving
    // nothing about concurrency — a silent false pass on a P0. A racer is
    // admissible only if it either succeeded or was refused by the DOMAIN
    // guard (with that guard's own message); an authz/routing refusal is not
    // a race outcome.
    for (const result of race.fulfilled) {
      expect(
        [401, 403, 404],
        `a racer was refused by authz/routing (${result.status}), not by the settlement guard — this case would be a false pass`,
      ).not.toContain(result.status)
      const settled = result.status >= 200 && result.status < 300
      const domainRefusal =
        result.status === 422 && /already been paid/i.test(JSON.stringify(result.body))
      expect(
        settled || domainRefusal,
        `each racer either settles or hits the domain guard -> got ${result.status} ${JSON.stringify(result.body)}`,
      ).toBe(true)
    }

    // ── THE MONEY INVARIANT ("Exactly one Payment row; no duplicated GL
    // leg") — asserted on the effects, never on the response codes, because
    // BOTH racers can legitimately answer 200 while only one writes.
    const movements = await movementsFor(request, owner, repositoryId, expense.id)
    expect(
      movements.length,
      `CONC-03: exactly ONE repository movement for this expense (got ${movements.length})`,
    ).toBe(1)
    expect(movements[0]!.direction, 'the single movement is an outflow').toBe('out')
    expect(sumMoney([String(movements[0]!.amount)]), '…of exactly the expense total').toBe('77.000')

    // …and the GL half of the plan's wording ("no duplicated GL leg"), which
    // fix round 1 (I6) turned from a claim into an assertion. The settlement
    // JE credits the CASH account for the expense; the expense's own posting
    // JE lands on 65/401, so filtering the cash-account ledger by this
    // document's `source_id` isolates the settlement legs exactly.
    const cashAccountId = (await accountsByPurpose(request, owner)).cash!.id
    const settlementLines = await ledgerLinesFor(request, owner, cashAccountId, expense.id)
    expect(
      settlementLines.length,
      `CONC-03: exactly ONE settlement leg on the cash account (got ${settlementLines.length})`,
    ).toBe(1)
    expect(
      settlementLines[0]!.credit,
      'CONC-03: …crediting cash once, for the expense total (the ledger renders scale 4)',
    ).toBe('77.0000')

    const after = await apiRequest(page, 'GET', `/expenses/${expense.id}`)
    expect(
      ((after.body as { data: { metadata: { is_paid: boolean } } }).data).metadata.is_paid,
      'the expense is settled',
    ).toBe(true)

    // RECORDED (not filed as a defect — no money moved twice): when both
    // racers answer 200, the loser's 200 describes a settlement it did not
    // perform. A caller that trusts its own 2xx to mean "I paid this" would
    // double-count in a client-side tally.
    const successes = race.fulfilled.filter((r) => r.status >= 200 && r.status < 300).length
    test.info().annotations.push({
      type: 'RECORDED',
      description: `${successes} of 2 racers answered 2xx for a single settlement (money effect verified once).`,
    })
  })

  test('MTP-CONC-04 (P1): two simultaneous goods-receipt posts move stock ONCE and blend WAC ONCE', async ({
    page,
    request,
  }) => {
    await loginAsRoleResilient(page, 'owner')
    const manager = await loginResilient(request, 'manager')

    const locations = await apiRequest(page, 'GET', '/locations')
    const warehouseId = ((locations.body as { data: Array<{ id: string; code: string }> }).data).find(
      (l) => l.code === WAREHOUSE_CODE,
    )!.id
    // `GET /uom/units` (not `/units`) — resolved live so a reseed cannot
    // strand a hardcoded unit id. `Piece` keeps the fixture at whole units.
    const units = await apiRequest(page, 'GET', '/uom/units')
    expect(units.status, 'GET /uom/units').toBe(200)
    const unitRows = (units.body as { data: Array<{ id: string; code: string }> }).data
    const unitId = (unitRows.find((u) => u.code === 'pc' || u.code === 'piece') ?? unitRows[0]!).id

    const supplierId = await createSupplier(page, 'CONC04')
    // `requires_batch_tracking: false` — the tenant's batch-tracking default
    // makes a `part` batch-tracked, and a batch-tracked line refuses to post
    // without batch data, which would make the race vacuous.
    const sku = uniq('CONC04-P')
    const productRes = await apiRequest(page, 'POST', '/products', {
      name: sku,
      sku,
      type: 'part',
      unit_id: unitId,
      requires_batch_tracking: false,
    })
    expect(productRes.status, `product create -> ${productRes.status} ${JSON.stringify(productRes.body)}`).toBe(201)
    const productId = ((productRes.body as { data: { id: string } }).data).id

    // A fresh product starts at zero, so "moved once" is an exact delta.
    const before = await apiRequest(page, 'GET', `/products/${productId}/stock-levels`)
    expect(
      ((before.body as { data: { totals: { quantity: string } } }).data).totals.quantity,
      'a fresh product holds no stock',
    ).toBe('0.0000')

    const receiptRes = await apiRequest(page, 'POST', '/goods-receipts/standalone', {
      supplier_id: supplierId,
      location_id: warehouseId,
      idempotency_key: uniq('CONC04-idem').slice(0, 64),
      post_immediately: false,
      lines: [{ product_id: productId, qty: '10', unit_price: '5.000' }],
    })
    expect(receiptRes.status, `standalone receipt -> ${receiptRes.status}`).toBe(201)

    const receipts = await apiRequest(page, 'GET', '/goods-receipts?per_page=5')
    const receiptRow = ((receipts.body as { data: Array<{ id: string; supplier_id: string; status: string }> }).data)
      .find((r) => r.supplier_id === supplierId)
    expect(receiptRow, 'the draft receipt is findable').toBeTruthy()
    expect(receiptRow!.status, 'and it is a DRAFT').toBe('draft')

    const race = await raceTwo(
      () => apiRequest(page, 'POST', `/goods-receipts/${receiptRow!.id}/post`),
      () =>
        request.post(`http://127.0.0.1:8010/api/v1/goods-receipts/${receiptRow!.id}/post`, {
          headers: {
            Authorization: `Bearer ${manager.token}`,
            Accept: 'application/json',
            'Content-Type': 'application/json',
          },
          data: {},
        }).then(async (res) => ({ status: res.status(), body: await res.json() })),
    )
    expect(race.rejected, 'neither request threw at the transport layer').toEqual([])
    test.info().annotations.push({
      type: 'OBSERVED',
      description: `simultaneous POST /goods-receipts/{id}/post -> ${race.fulfilled.map((r) => r.status).join(' / ')}`,
    })

    // ── I5/I6 (review fix round 1): both racers must have REACHED THE WRITE
    // PATH, and the loser's refusal must be the POSTING guard's — not an
    // authz/routing refusal, which would make this P1 a silent false pass.
    for (const result of race.fulfilled) {
      expect(
        [401, 403, 404],
        `a racer was refused by authz/routing (${result.status}), not by the posting guard`,
      ).not.toContain(result.status)
    }
    const winners = race.fulfilled.filter((r) => r.status >= 200 && r.status < 300)
    expect(winners.length, 'CONC-04: exactly ONE racer posted the receipt').toBe(1)
    const loser = race.fulfilled.find((r) => r.status >= 400)
    expect(loser, 'CONC-04: the other racer was refused').toBeTruthy()
    expect(loser!.status, 'CONC-04: …with a 422 domain refusal').toBe(422)
    expect(
      JSON.stringify(loser!.body),
      'CONC-04: …naming the state guard it hit (the receipt was no longer Draft)',
    ).toMatch(/must be Draft before posting/i)

    // ── THE MONEY INVARIANT ("exactly one posting; stock moves once; WAC
    // blends once") ───────────────────────────────────────────────────────
    const after = await apiRequest(page, 'GET', `/products/${productId}/stock-levels`)
    expect(
      ((after.body as { data: { totals: { quantity: string } } }).data).totals.quantity,
      'CONC-04: stock moved ONCE — 10, never 20',
    ).toBe('10.0000')

    const product = await apiRequest(page, 'GET', `/products/${productId}`)
    expect(
      ((product.body as { data: { cost_price: string } }).data).cost_price,
      'CONC-04: WAC blended ONCE — the unit price itself, not a re-blend of it',
    ).toBe('5.000000')

    const finalReceipt = await apiRequest(page, 'GET', `/goods-receipts/${receiptRow!.id}`)
    expect(
      ((finalReceipt.body as { data: { status: string } }).data).status,
      'the receipt is posted exactly once',
    ).not.toBe('draft')
  })

  test('MTP-CONC-05 (P1): two simultaneous opening-batch creations produce EXACTLY ONE — the loser is refused', async ({
    page,
    request,
  }) => {
    await loginAsRoleResilient(page, 'owner')
    const manager = await loginResilient(request, 'manager')
    const companyId = owner.companyId

    // ── HALF OF THIS CASE IS BLOCKED, ON PURPOSE ──────────────────────────
    // The plan's literal setup is "one opening-balance batch, two sessions
    // POST it simultaneously -> `OpeningAlreadyExistsException` on the loser".
    // Posting needs an unlocked, validated batch of a given type, and all four
    // slots on this tenant are held by W-4's LOCKED fixtures
    // (`W4-OPB04-*`, `W4-INV15-*`, `W4-OPB02-*`, `W4-OPB03-*`); authoring a
    // postable one and posting it would write opening GL/stock against W-4's
    // own cutover, and W-7 must not touch W-4 fixtures. Recorded BLOCKED in
    // the wave report.
    //
    // What IS exercisable without touching anything is the SAME uniqueness
    // guard at CREATE time, raced. RULING (recorded): the rule is "one
    // UNLOCKED batch per type", not "one batch per type" — W-4 legitimately
    // left several LOCKED `ACCOUNTING` batches behind, and the server's own
    // message is "Company already has an unlocked GL Opening Balances batch".
    const statusBefore = await apiRequest(page, 'GET', `/companies/${companyId}/opening-batches/status`)
    expect(statusBefore.status, 'GET opening-batches/status').toBe(200)
    const types = ((statusBefore.body as {
      data: { types: Record<string, { has_batch: boolean; batch: { id: string; name: string; status: string } | null }> }
    }).data).types
    const occupied = Object.entries(types).find(
      ([, v]) => v.has_batch && v.batch !== null && v.batch.status === 'LOCKED',
    )
    expect(occupied, 'a LOCKED opening-batch slot exists to race against').toBeTruthy()
    const [slotType, slot] = occupied!
    test.info().annotations.push({
      type: 'BLOCKED',
      description:
        `The "post the same batch twice" half of MTP-CONC-05 is BLOCKED: every opening-batch ` +
        `slot is held by a W-4 LOCKED fixture (${slotType} holds "${slot.batch!.name}") and W-7 ` +
        `must not touch W-4 fixtures. The create-time uniqueness guard is raced instead.`,
    })

    const payload = { type: slotType, cutover_date: TODAY }
    const race = await raceTwo(
      () => apiRequest(page, 'POST', `/companies/${companyId}/opening-batches`, { ...payload, name: uniq('CONC05-A') }),
      () =>
        request.post(`http://127.0.0.1:8010/api/v1/companies/${companyId}/opening-batches`, {
          headers: {
            Authorization: `Bearer ${manager.token}`,
            Accept: 'application/json',
            'Content-Type': 'application/json',
          },
          data: { ...payload, name: uniq('CONC05-B') },
        }).then(async (res) => ({ status: res.status(), body: await res.json() })),
    )
    expect(race.rejected, 'neither request threw at the transport layer').toEqual([])
    test.info().annotations.push({
      type: 'OBSERVED',
      description: `simultaneous POST opening-batches (${slotType}) -> ${race.fulfilled
        .map((r) => r.status)
        .join(' / ')}`,
    })

    // Clean up FIRST, so a failing assertion below never leaves a draft batch
    // on the tenant; the cleanup statuses are asserted afterwards.
    const createdIds = race.fulfilled
      .map((r) => (r.body as { data?: { id?: string } }).data?.id)
      .filter((id): id is string => typeof id === 'string')
    const cleanupStatuses: number[] = []
    for (const id of createdIds) {
      const removed = await apiRequest(page, 'DELETE', `/companies/${companyId}/opening-batches/${id}`)
      cleanupStatuses.push(removed.status)
      test.info().annotations.push({
        type: 'CLEANUP',
        description: `DELETE opening-batch ${id} -> ${removed.status} (${isCleanupSuccess(removed.status) ? 'removed' : 'LEFT BEHIND'})`,
      })
    }

    // ── THE INVARIANT ─────────────────────────────────────────────────────
    // Exactly ONE batch exists after the race — the guard is not merely a
    // sequential pre-check that both racers can walk past.
    expect(
      createdIds.length,
      `CONC-05: exactly ONE of the two simultaneous creations succeeded (got ${createdIds.length})`,
    ).toBe(1)

    // …and the loser is refused with the guard's own message, not a 500 and
    // not a silent second batch.
    const loser = race.fulfilled.find((r) => r.status >= 400)
    expect(loser, 'the losing racer was refused').toBeTruthy()
    expect(loser!.status, 'CONC-05: the loser gets a 422 domain refusal').toBe(422)
    expect(
      JSON.stringify(loser!.body),
      'CONC-05: …naming the already-existing unlocked batch',
    ).toMatch(/already has an unlocked/i)

    expect(
      cleanupStatuses.every(isCleanupSuccess),
      `CONC-05 cleanup: every created batch was deleted (statuses ${cleanupStatuses.join(', ')})`,
    ).toBe(true)

    // …and W-4's fixture is exactly as it was.
    const statusAfter = await apiRequest(page, 'GET', `/companies/${companyId}/opening-batches/status`)
    const slotAfter = ((statusAfter.body as {
      data: { types: Record<string, { batch: { id: string; status: string } | null }> }
    }).data).types[slotType]!
    expect(slotAfter.batch?.id, 'the W-4 fixture batch still owns the slot').toBe(slot.batch!.id)
    expect(slotAfter.batch?.status, '…with its status untouched').toBe(slot.batch!.status)
  })

  test('MTP-CONC-06 (P0): a payment allocated to a CANCELLED invoice is refused, and the terminal status stands', async ({
    browser,
  }) => {
    await withTwoSessions(browser, { a: 'owner', b: 'manager' }, async ({ pageA, pageB }) => {
      const partnerId = await createCustomer(pageA, 'CONC06')
      const { id: invoiceId } = await createDraftInvoice(pageA, partnerId, [
        { description: 'W7 CONC-06 fixture', quantity: '1', unit_price: '199.000' },
      ])
      const posted = await postInvoice(pageA, invoiceId)

      // Session B loads the POSTED invoice — this is the stale tab.
      const seenByB = await getInvoice(pageB, invoiceId)
      expect(seenByB.status, 'B sees a posted, payable invoice').toBe('posted')
      expect(String(seenByB.balance_due), 'B sees the full balance outstanding').toBe(posted.total)

      // Session A cancels it out from under B.
      const cancel = await apiRequest(pageA, 'POST', `/invoices/${invoiceId}/cancel`, {
        reason: 'W7 CONC-06 — cancelled while another tab held it',
      })
      expect(cancel.status, 'the cancel succeeds on a POSTED invoice').toBe(200)
      const cancelled = await getInvoice(pageA, invoiceId)
      expect(cancelled.status, 'the document is now cancelled').toBe('cancelled')

      // B, still believing the invoice is payable, records the payment.
      const methodId = await paymentMethodIdByCode(pageB, 'CASH')
      const repositoryId = await repositoryIdByCode(pageB, CASH_REPOSITORY_CODE)
      const stalePayment = await apiRequest(pageB, 'POST', '/payments', {
        partner_id: partnerId,
        payment_method_id: methodId,
        repository_id: repositoryId,
        amount: posted.total,
        currency: 'TND',
        payment_date: TODAY,
        allocations: [{ document_id: invoiceId, amount: posted.total }],
      })

      // ── F-6 FIXED (L2 lane) ─────────────────────────────────────────────
      // The shared per-allocation guard now refuses a WITHDRAWN document, so a
      // terminal status can never be rewritten by a stale tab. Previously this
      // returned 201 and flipped the document from `cancelled` to `paid` with
      // `cancelled_at` still set — a cancelled sale reappearing as collected
      // revenue in the partner ledger, aged AR, and every status-keyed report.
      expect(
        stalePayment.status,
        'F-6: a payment allocated to a CANCELLED invoice is refused with a state error',
      ).toBe(422)
      expect(
        (stalePayment.body as { error?: { code?: string } }).error?.code,
        'F-6: …naming the document state, not a validation failure',
      ).toBe('DOCUMENT_NOT_ALLOCATABLE')

      const afterPayment = await getInvoice(pageA, invoiceId)
      expect(
        afterPayment.status,
        'F-6: the cancelled document keeps its terminal status',
      ).toBe('cancelled')

      // No allocation row attached, and no payment was created.
      const docPayments = await apiRequest(pageA, 'GET', `/documents/${invoiceId}/payments`)
      const allocations = ((docPayments.body as {
        data: { payment_allocations: Array<{ allocated_amount?: string; amount?: string }> }
      }).data).payment_allocations
      expect(
        allocations.length,
        'F-6: no allocation row is attached to the cancelled document',
      ).toBe(0)

      // A posted/cancelled document has no delete route, so the fixture invoice
      // stays; the payment no longer does, because it is never created.
      test.info().annotations.push({
        type: 'FOOTPRINT',
        description: `CONC-06 leaves one cancelled invoice (${posted.total}) on partner ${partnerId}; it is not deletable. No payment is created.`,
      })
    })
  })
})
