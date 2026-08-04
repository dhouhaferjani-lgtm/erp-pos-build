/**
 * MONEY TEST CAMPAIGN — wave W-6 — aged receivables / aged payables:
 * `MTP-GL-10`, `-11`, `-12`, `-13`, `-14` and the NEW `MTP-GL-26`
 * (plan §B.6 rows 77 and 78; campaign-plan row 77 defines `MTP-GL-26`).
 *
 * Live local stack (web :5173 -> api :8010, tenant `demo-pharmacy-tn`), real
 * login, real backend, no mocks. Money compared as EXACT decimal strings.
 *
 * CONCURRENCY — THIS FILE ASSUMES `--workers=1`. `MTP-GL-12` and `MTP-GL-26`
 * assert exact DELTAS on the shared aged-AR grand total, captured immediately
 * around their own mutations. Run only as `--project=chromium --workers=1`.
 *
 * FIXTURE DISCIPLINE. This tenant's aged AR already carries 84 partner lines of
 * W-1/W-2/W-3/W-4/W-5 money (`W4-*`, `W2a-*`, `W2c-*`, `W1b-*`, `W3c-*`) that
 * GROWS on every sibling re-run, so every case here works on a FRESH,
 * run-scoped partner it created itself, or on a scale-free invariant. Nothing
 * `W4`-prefixed is mutated.
 *
 * PERMANENCE. A posted invoice cannot be deleted, so the two invoices this file
 * creates (`MTP-GL-12` 900.000 and `MTP-GL-26` 500.000, each on its own fresh
 * `W6-*` customer) are permanent, deliberate, documented money — itemised in
 * the W-6 "State left behind" section of
 * `docs/sessions/MONEY-CAMPAIGN-RESULTS.md`. `MTP-GL-10`, `-11`, `-13` and
 * `-14` are READ-ONLY and create nothing at all: `MTP-GL-14` takes its overdue
 * boundary evidence from the `HIST-INV-*` historicals W-4 deliberately left
 * behind, which it never mutates.
 */
import { test, expect } from '@playwright/test'
import {
  login,
  createCustomer,
  createPayment,
  getPaymentMethods,
  getRepositories,
  get,
  post,
  type Session,
} from './treasury-support'
import {
  TODAY,
  add4,
  agedLineFor,
  agedPayables,
  agedReceivables,
  bucketSum,
  createPostedInvoiceDated,
  norm4,
  occupiedBuckets,
  sub4,
  uniq,
  type AgedReport,
} from './w6-support'

/** Σ of every line's `total`, as an exact scale-4 string. */
function sumLineTotals(report: AgedReport): string {
  return report.lines.reduce((acc, l) => add4(acc, l.total), '0.0000')
}

test.describe('GL — aged receivables and aged payables', () => {
  test.setTimeout(240_000)

  let owner: Session
  let cashMethodId: string
  let cashRepoId: string

  test.beforeAll(async ({ request }, workerInfo) => {
    expect(
      workerInfo.config.workers,
      'this file asserts exact deltas on the SHARED aged-AR grand total — '
        + 'run it as `--project=chromium --workers=1`',
    ).toBe(1)

    owner = await login(request, 'owner')
    const repos = await getRepositories(request, owner)
    cashRepoId = repos.find((r) => r.code === 'CASH-01')!.id
    const methods = await getPaymentMethods(request, owner)
    cashMethodId = methods.find((m) => m.code === 'CASH')!.id
  })

  test('MTP-GL-10 (BLOCKED): the CoffeeShopSeeder tenant is not provisioned on this stack', async ({
    request,
  }) => {
    // The case is specified against the `CoffeeShopSeeder` tenant
    // (`CUST-001` 300.000 / `CUST-002` 1200.000 / `CUST-003` absent, Σ 1500.000).
    // The campaign has credentials for `demo-pharmacy-tn` ONLY — the same
    // blocker the campaign plan records as C-4 for the whole isolation wave.
    // Evidence, not an assumption: those partners do not exist here.
    test.info().annotations.push({
      type: 'BLOCKED',
      description:
        'MTP-GL-10 needs the CoffeeShopSeeder tenant (CUST-001/002/003). Only demo-pharmacy-tn is '
        + 'provisioned to this campaign (plan blocker C-4). The equivalent assertion on the '
        + 'available tenant is executed by MTP-GL-12 below.',
    })

    for (const code of ['CUST-001', 'CUST-002', 'CUST-003']) {
      const res = await get(request, owner, `/partners?search=${code}`)
      expect(res.ok, `partner lookup -> ${res.status}`).toBeTruthy()
      const rows = res.data as unknown as Array<{ name: string; code?: string }>
      expect(
        rows.some((p) => p.name === code || p.code === code),
        `${code} is a CoffeeShopSeeder fixture and is absent from demo-pharmacy-tn`,
      ).toBeFalsy()
    }
  })

  test('MTP-GL-11 (P0): aged payables is internally consistent — and covers FAR less than the payables ledger', async ({
    request,
  }) => {
    const ap = await agedPayables(request, owner)

    // 1) The report's own invariants (scale-free, immune to fixture growth).
    expect(norm4(ap.grand_total), 'grand_total == Σ line totals').toBe(sumLineTotals(ap))
    const bucketTotals = add4(
      add4(add4(norm4(ap.total_current), norm4(ap.total_days_30)), norm4(ap.total_days_60)),
      add4(norm4(ap.total_days_90), norm4(ap.total_over_90)),
    )
    expect(bucketTotals, 'grand_total == Σ the five bucket totals').toBe(norm4(ap.grand_total))
    for (const line of ap.lines) {
      expect(bucketSum(line), `${line.vendor_name}: Σ buckets == line total`).toBe(norm4(line.total))
      expect(
        occupiedBuckets(line).length,
        `${line.vendor_name}: a vendor's money is not double-counted across buckets`,
      ).toBeLessThanOrEqual(1)
    }

    // 2) The plan's fixture pin (`SUPP-001` 2500.000) belongs to the
    //    CoffeeShopSeeder tenant — unreachable here, same blocker as GL-10.
    // 3) RECORDED BEHAVIOUR (P2), verified against the seeded supplier
    //    `Medis Distribution SARL`, which holds four POs on this tenant
    //    (1 draft, 2 CONFIRMED, 1 normally-RECEIVED): it is ABSENT from aged
    //    payables. `AgedPayablesService::getOutstandingInvoices():152-165`
    //    includes ONLY `status = Posted` purchase orders plus
    //    `autoGeneratedReceivedPurchaseOrdersWithAccrual()` — confirmed POs,
    //    normal received POs and supplier INVOICES are all deliberately
    //    excluded ("so the report does not surface phantom payables", :140-142).
    //    The consequence is that `/finance/aged-payables` is NOT a view of the
    //    `401 Fournisseurs` ledger and must never be reconciled against it.
    expect(
      agedLineFor(ap, 'Medis Distribution SARL'),
      'RECORDED: a supplier holding confirmed + received POs does NOT appear on aged payables',
    ).toBeUndefined()
  })

  test('MTP-GL-12 (P0): a freshly posted, wholly unpaid invoice is INVISIBLE to aged receivables', async ({
    request,
  }) => {
    // The plan pins `Clinique Al Amal` 900 outstanding / `Medis Distribution`
    // 3200 payable against the DemoPharmacySeeder. Both pins are UNMET on the
    // current seed: `Clinique Al Amal` exists as a partner but carries ZERO
    // documents, and `Medis Distribution SARL` is absent from aged payables by
    // design (MTP-GL-11). Recorded, then the case is executed in the only shape
    // that is meaningful on a tenant five waves have written to: a KNOWN amount
    // this wave creates itself.
    const staleCheck = await get(request, owner, '/partners?search=Clinique Al Amal')
    const alAmal = (staleCheck.data as unknown as Array<{ id: string; name: string }>).find(
      (p) => p.name === 'Clinique Al Amal',
    )
    expect(alAmal, 'RECORDED: the plan pin partner exists…').toBeDefined()
    const alAmalDocs = await get(request, owner, `/invoices?partner_id=${alAmal!.id}`)
    expect(
      (alAmalDocs.data as unknown as unknown[]).length,
      'RECORDED: …but carries no invoices at all, so the plan`s `900 outstanding` pin is UNMET',
    ).toBe(0)

    const name = uniq('GL12')
    const customerId = await createCustomer(request, owner, name)

    const before = await agedReceivables(request, owner)
    expect(agedLineFor(before, name), 'a brand-new customer has no receivable').toBeUndefined()
    const grandBefore = norm4(before.grand_total)

    const invoice = await createPostedInvoiceDated(
      request,
      owner,
      customerId,
      '900.000',
      TODAY,
      TODAY,
      'W6 GL-12 fixture',
    )

    // The invoice really IS an open receivable — the document API says so.
    const doc = await get(request, owner, `/documents/${invoice.id}`)
    const docData = doc.data as { status: string; balance_due: string; outstanding_amount: string }
    expect(docData.status).toBe('posted')
    expect(docData.balance_due, 'the document API reports the full amount outstanding').toBe('900.000')
    expect(docData.outstanding_amount).toBe('900.000')

    // --- VERDICT: FAIL (P0). TRIPWIRE, GREEN: pins TODAY's behaviour.
    //
    // `AgedReceivablesService::getOutstandingInvoices():146-151` filters on the
    // PERSISTED `documents.balance_due` column (`where('balance_due','>',0)`),
    // but NOTHING on the invoice create/confirm/post path ever writes that
    // column — it stays NULL until a treasury settlement path recomputes it
    // (`InstrumentLifecycleService:545`, `MultiPaymentService:137`,
    // `CloseInvoiceWithToleranceService:117`) or the document was produced by a
    // converter / AR-opening (`CopiesDocumentData:87`, `ArApOpeningService:309`).
    // The document API hides this: `DocumentData::fromModel():173-177` COMPUTES
    // `balance_due` from `outstanding_amount` for payment-tracked types and
    // never reads the persisted column. Net effect, measured live 2026-08-05:
    // 163 posted invoices totalling 57 732.410 TND have a NULL `balance_due`
    // and are missing from `/finance/aged-receivables` entirely.
    const after = await agedReceivables(request, owner)
    expect(
      agedLineFor(after, name),
      'TRIPWIRE D2 (P0, LAUNCH-BLOCKING): a posted, wholly unpaid invoice never reaches aged AR',
    ).toBeUndefined()
    expect(
      sub4(norm4(after.grand_total), grandBefore),
      'TRIPWIRE D2: the aged-AR grand total does not move at all when a receivable is created',
    ).toBe('0.0000')
  })

  test('MTP-GL-13 (P0): every invoice lands in exactly one bucket and Σ buckets == the total', async ({
    request,
  }) => {
    const ar = await agedReceivables(request, owner)
    expect(ar.lines.length, 'the tenant has receivables to check').toBeGreaterThan(0)

    // Per line: Σ(current + 31-60 + 61-90 + 91-120 + over) == the line total.
    for (const line of ar.lines) {
      expect(bucketSum(line), `${line.customer_name}: Σ buckets == line total`).toBe(norm4(line.total))
    }

    // Report level: Σ(bucket totals) == grand_total == Σ(line totals).
    const bucketTotals = add4(
      add4(add4(norm4(ar.total_current), norm4(ar.total_days_30)), norm4(ar.total_days_60)),
      add4(norm4(ar.total_days_90), norm4(ar.total_over_90)),
    )
    expect(bucketTotals, 'Σ bucket totals == grand_total').toBe(norm4(ar.grand_total))
    expect(sumLineTotals(ar), 'Σ line totals == grand_total').toBe(norm4(ar.grand_total))

    // Per-line bucket totals must reconcile column by column, which is what
    // actually rules out an invoice being counted in two buckets at once.
    for (const bucket of ['current', 'days_30', 'days_60', 'days_90', 'over_90'] as const) {
      const columnSum = ar.lines.reduce((acc, l) => add4(acc, l[bucket]), '0.0000')
      const reported = norm4(
        ({
          current: ar.total_current,
          days_30: ar.total_days_30,
          days_60: ar.total_days_60,
          days_90: ar.total_days_90,
          over_90: ar.total_over_90,
        } as const)[bucket],
      )
      expect(columnSum, `column ${bucket} reconciles`).toBe(reported)
    }
  })

  test('MTP-GL-14 (P0): the aging buckets are SIGN-INVERTED — overdue money never ages', async ({
    request,
  }) => {
    // READ-ONLY. The boundary evidence is taken from fixtures that ALREADY sit
    // on this tenant with materialised balances and real past due dates — the
    // `HIST-INV-*` AR/AP historicals W-4 deliberately left behind ("State
    // deliberately LEFT BEHIND (for W-6)" item 4). Nothing is created or
    // mutated here, and no `W4`-prefixed row is touched.
    //
    // (The alternative — minting invoices at 30/31/60/61/90/91 days overdue —
    // proves nothing today, because D2 above means a freshly posted invoice
    // never reaches this report at all.)
    const ar = await agedReceivables(request, owner)

    // Candidates: aged-AR lines whose partner has EXACTLY ONE outstanding
    // posted invoice, so the line's bucket is unambiguously that invoice's.
    const singles: Array<{ name: string; daysOverdue: number; amount: string; bucket: string }> = []
    for (const line of ar.lines) {
      if (!(line.customer_name ?? '').startsWith('W4-W4AP-Partner-')) continue
      const res = await get(request, owner, `/invoices?partner_id=${line.customer_id}`)
      const invoices = (res.data as unknown as Array<{
        status: string
        due_date: string | null
        document_date: string
        total: string
      }>).filter((i) => i.status === 'posted')
      if (invoices.length !== 1) continue
      const reference = invoices[0]!.due_date ?? invoices[0]!.document_date
      const daysOverdue = Math.round(
        (Date.parse(`${TODAY}T00:00:00Z`) - Date.parse(`${reference}T00:00:00Z`)) / 86_400_000,
      )
      const occupied = occupiedBuckets(line)
      singles.push({
        name: line.customer_name!,
        daysOverdue,
        amount: norm4(line.total),
        bucket: occupied[0] ?? 'none',
      })
      if (singles.length >= 3) break
    }

    expect(
      singles.length,
      'the W-4 AR/AP historicals are the read-only boundary fixtures this case needs',
    ).toBeGreaterThan(0)

    // --- VERDICT: FAIL (P0). TRIPWIRE, GREEN: pins TODAY's behaviour.
    //
    // `AgedReceivablesService::calculateCustomerAging():197` computes
    //   $daysOverdue = (int) $asOfDate->diffInDays(Carbon::parse($referenceDate), false)
    // Carbon's signed `diffInDays` returns (argument − receiver), so for an
    // invoice due in the PAST this is NEGATIVE, and `determineBucket():230-232`
    // maps `$daysOverdue < 0` straight to `current`. The sign is inverted:
    // NOTHING overdue ever ages out of Current, and a NOT-YET-DUE invoice
    // (positive day count) would be aged as if it were late.
    // `AgedPayablesService::calculateVendorAging():273` +
    // `determineBucket():306-320` are byte-identical, so aged payables carries
    // the same inversion (recorded by code citation — every AP fixture on this
    // tenant is < 30 days old, so it cannot be exercised here).
    for (const single of singles) {
      expect(
        single.daysOverdue,
        `${single.name}: the fixture really is more than 30 days overdue`,
      ).toBeGreaterThan(30)
      expect(
        single.bucket,
        `TRIPWIRE D4: ${single.name} is ${single.daysOverdue} days overdue (${single.amount}) `
          + 'and is STILL reported as Current',
      ).toBe('current')
    }

    test.info().annotations.push({
      type: 'recorded-buckets',
      description: singles
        .map((s) => `${s.name}: ${s.daysOverdue}d overdue, ${s.amount} -> bucket "${s.bucket}"`)
        .join('; '),
    })

    // The whole report shows the same shape: with 31- and 61-day-overdue money
    // present, every non-Current bucket is empty.
    expect(
      add4(
        add4(norm4(ar.total_days_30), norm4(ar.total_days_60)),
        add4(norm4(ar.total_days_90), norm4(ar.total_over_90)),
      ),
      'TRIPWIRE D4: not one millime has ever aged out of Current on this tenant',
    ).toBe('0.0000')

    // Consistency half: AR and AP expose the identical bucket contract, which
    // is the part of the case that survives the fix.
    const ap = await agedPayables(request, owner)
    expect(Object.keys(ap).sort(), 'AR and AP expose the identical bucket contract').toEqual(
      Object.keys(ar).sort(),
    )
  })

  test('MTP-GL-26 (P0): a full refund REOPENS the invoice and it reappears on aged receivables', async ({
    request,
  }) => {
    // The read-surface half of the D9 status-revert behaviour that landed in
    // `f670d37bf` and is asserted at the API layer by `MTP-TRE-09`: a fully
    // refunded payment must put the invoice back into the aging report.
    const name = uniq('GL26')
    const customerId = await createCustomer(request, owner, name)
    const invoice = await createPostedInvoiceDated(
      request,
      owner,
      customerId,
      '500.000',
      TODAY,
      TODAY,
      'W6 GL-26 reopened-invoice fixture',
    )

    // 1) Posted and unpaid -> ABSENT, because of D2 above (the persisted
    //    `documents.balance_due` is still NULL). Captured here so the delta at
    //    step 3 is unambiguous.
    const posted = await agedReceivables(request, owner)
    expect(
      agedLineFor(posted, name),
      'D2: a posted, wholly unpaid invoice is not on the aging report yet',
    ).toBeUndefined()
    const grandBeforeAnyPayment = norm4(posted.grand_total)

    // 2) Paid in full -> the settlement path materialises `balance_due = 0`,
    //    so the invoice is still (correctly) off the report.
    const payment = await createPayment(request, owner, {
      partner_id: customerId,
      payment_method_id: cashMethodId,
      repository_id: cashRepoId,
      amount: '500.000',
      currency: 'TND',
      payment_date: TODAY,
      allocations: [{ document_id: invoice.id, amount: '500.000' }],
    })
    expect(payment.ok, `payment -> ${payment.status} ${JSON.stringify(payment.data)}`).toBeTruthy()
    const paymentId = String((payment.data as { id: string }).id)

    const paidDoc = await get(request, owner, `/documents/${invoice.id}`)
    expect((paidDoc.data as { status: string }).status, 'the invoice is Paid').toBe('paid')

    const whilePaid = await agedReceivables(request, owner)
    expect(agedLineFor(whilePaid, name), 'a fully paid invoice is not a receivable').toBeUndefined()
    expect(
      sub4(norm4(whilePaid.grand_total), grandBeforeAnyPayment),
      'settling an invoice that was never on the report leaves the grand total unchanged',
    ).toBe('0.0000')

    // 3) Fully refunded -> the invoice REOPENS and comes BACK on the report.
    const refund = await post(request, owner, `/payments/${paymentId}/refund`, {
      reason: 'W6 MTP-GL-26 full refund',
      refund_request_id: crypto.randomUUID(),
    })
    expect(refund.ok, `refund -> ${refund.status} ${JSON.stringify(refund.data)}`).toBeTruthy()

    const reopenedDoc = await get(request, owner, `/documents/${invoice.id}`)
    expect(
      (reopenedDoc.data as { status: string }).status,
      'D9: a full refund reverts Paid -> Posted',
    ).toBe('posted')
    expect((reopenedDoc.data as { balance_due: string }).balance_due).toBe('500.000')

    const afterRefund = await agedReceivables(request, owner)
    expect(
      agedLineFor(afterRefund, name)?.total,
      'MTP-GL-26: the reopened invoice is back on aged receivables for its full balance',
    ).toBe('500.0000')
    expect(
      sub4(norm4(afterRefund.grand_total), grandBeforeAnyPayment),
      'the grand total rises by exactly the reopened balance',
    ).toBe('500.0000')

    // Side-observation for the ticket (D2): a pay-then-refund round trip is
    // currently the ONLY way an ordinary invoice ever reaches this report,
    // because the refund path is what finally writes the persisted
    // `documents.balance_due` the report filters on.
    test.info().annotations.push({
      type: 'recorded',
      description:
        'The invoice became visible to aged AR only AFTER the pay+refund cycle materialised '
        + 'documents.balance_due — see D2.',
    })
  })
})
