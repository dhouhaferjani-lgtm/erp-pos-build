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

  test('MTP-GL-12 (P0): a freshly posted, wholly unpaid invoice REACHES aged receivables', async ({
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

    // --- D2 FIXED (L2 lane).
    //
    // The report used to filter on the PERSISTED `documents.balance_due`
    // column, which is a PostgreSQL trigger cache fired by allocation DML only:
    // a posted invoice that was never allocated against had no trigger event
    // and stayed NULL forever, so it never reached this report at all. Measured
    // live 2026-08-05: 165 posted invoices / 59 532.410 TND invisible against a
    // reported grand total of 32 892.422. The aged services now read the
    // outstanding COMPUTED from allocations — the trigger's own formula — so no
    // cache has to be warm for a receivable to be seen.
    const after = await agedReceivables(request, owner)
    expect(
      agedLineFor(after, name)?.total,
      'D2 (P0, LAUNCH-BLOCKING): a posted, wholly unpaid invoice is reported at its full balance',
    ).toBe('900.0000')
    expect(
      sub4(norm4(after.grand_total), grandBefore),
      'D2: the aged-AR grand total rises by exactly the new receivable',
    ).toBe('900.0000')
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

  test('MTP-GL-14 (P0): overdue money ages into the bucket its age names', async ({
    request,
  }) => {
    // READ-ONLY. The boundary evidence is taken from fixtures that ALREADY sit
    // on this tenant with materialised balances and real past due dates — the
    // `HIST-INV-*` AR/AP historicals W-4 deliberately left behind ("State
    // deliberately LEFT BEHIND (for W-6)" item 4). Nothing is created or
    // mutated here, and no `W4`-prefixed row is touched.
    //
    // (Minting invoices at 30/31/60/61/90/91 days overdue would also work now
    // that D2 is fixed, but the read-only historicals keep this case free of
    // footprint.)
    const ar = await agedReceivables(request, owner)

    // Candidates: aged-AR lines whose partner holds EXACTLY ONE outstanding
    // posted invoice AND whose invoice is a `HIST-INV-*` historical — so the
    // line's bucket is unambiguously that invoice's, and the fixture selected
    // is the one the narrative and the ticket actually name.
    //
    // FIX ROUND 1: selection used to key on the `W4-W4AP-Partner-` PARTNER
    // prefix while the comment and the ticket both cited the `HIST-INV-*`
    // DOCUMENTS. Both were true of the same rows, but the code and the story
    // have to name the same thing, so the document number is now the selector.
    const singles: Array<{
      name: string
      documentNumber: string
      daysOverdue: number
      amount: string
      bucket: string
    }> = []
    for (const line of ar.lines) {
      const res = await get(request, owner, `/invoices?partner_id=${line.customer_id}`)
      const invoices = (res.data as unknown as Array<{
        status: string
        document_number: string
        due_date: string | null
        document_date: string
        total: string
      }>).filter((i) => i.status === 'posted')
      if (invoices.length !== 1) continue
      const invoice = invoices[0]!
      if (!invoice.document_number.startsWith('HIST-INV-')) continue
      const reference = invoice.due_date ?? invoice.document_date
      const daysOverdue = Math.round(
        (Date.parse(`${TODAY}T00:00:00Z`) - Date.parse(`${reference}T00:00:00Z`)) / 86_400_000,
      )
      if (daysOverdue <= 30) continue
      const occupied = occupiedBuckets(line)
      singles.push({
        name: line.customer_name!,
        documentNumber: invoice.document_number,
        daysOverdue,
        amount: norm4(line.total),
        bucket: occupied[0] ?? 'none',
      })
      if (singles.length >= 3) break
    }

    expect(
      singles.length,
      'the `HIST-INV-*` historicals W-4 left behind are the read-only boundary fixtures this case '
        + 'needs — each on a partner holding exactly one posted invoice, more than 30 days overdue',
    ).toBeGreaterThan(0)

    // --- D4 FIXED (L2 lane).
    //
    // `AgedReceivablesService::calculateCustomerAging()` used to compute
    //   $daysOverdue = (int) $asOfDate->diffInDays(Carbon::parse($reference), false)
    // and Carbon's signed `diffInDays` returns (argument − receiver), so for an
    // invoice due in the PAST this was NEGATIVE — which `determineBucket()`
    // maps to `current`. Nothing overdue ever aged out of Current, and a
    // not-yet-due invoice (positive count) was aged as if it were late. The
    // receiver is now the REFERENCE date, so the count reads `asOf − reference`:
    // positive = overdue, exactly what `determineBucket()`'s docblock claims.
    // `AgedPayablesService` carried the byte-identical inversion and is fixed
    // with it (every AP fixture on this tenant is < 30 days old, so it is
    // covered by the API suite rather than exercised here).
    const expectedBucket = (days: number): string => {
      if (days <= 30) return 'current'
      if (days <= 60) return 'days_30'
      if (days <= 90) return 'days_60'
      if (days <= 120) return 'days_90'
      return 'over_90'
    }

    for (const single of singles) {
      expect(
        single.daysOverdue,
        `${single.name}: the fixture really is more than 30 days overdue`,
      ).toBeGreaterThan(30)
      expect(
        single.bucket,
        `D4: ${single.documentNumber} (${single.name}) is ${single.daysOverdue} days `
          + `overdue for ${single.amount} and must age out of Current`,
      ).toBe(expectedBucket(single.daysOverdue))
    }

    test.info().annotations.push({
      type: 'recorded-buckets',
      description: singles
        .map(
          (s) =>
            `${s.documentNumber} (${s.name}): ${s.daysOverdue}d overdue, ${s.amount} -> bucket "${s.bucket}"`,
        )
        .join('; '),
    })

    // The whole report shows the same shape: with 31- and 61-day-overdue money
    // present, the non-Current buckets are no longer empty.
    expect(
      add4(
        add4(norm4(ar.total_days_30), norm4(ar.total_days_60)),
        add4(norm4(ar.total_days_90), norm4(ar.total_over_90)),
      ),
      'D4: overdue money has aged out of Current on this tenant',
    ).not.toBe('0.0000')

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

    // 1) Posted and unpaid -> PRESENT for its full balance. Before D2 was fixed
    //    this step recorded the invoice as ABSENT, because the report filtered
    //    on a `documents.balance_due` cache that no allocation had yet warmed.
    const posted = await agedReceivables(request, owner)
    expect(
      agedLineFor(posted, name)?.total,
      'D2: a posted, wholly unpaid invoice is already on the aging report',
    ).toBe('500.0000')
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
      'settling the invoice takes its balance back off the report',
    ).toBe('-500.0000')

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
      'the grand total returns to where it stood before the payment',
    ).toBe('0.0000')
  })
})
