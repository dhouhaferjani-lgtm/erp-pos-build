# Ticket: W-6 finance / GL defects — `MTP-GL-01..23`, `MTP-GL-26..28`

Filed by the pre-launch full-E2E money campaign, wave **W-6** (finance / GL),
2026-08-05. Brief: `.superpowers/sdd/2026-08-02-full-e2e-campaign-plan/wave-6-brief.md`.
Results: `docs/sessions/MONEY-CAMPAIGN-RESULTS.md` § `W-6`.

**No product code was changed.** Every finding below is pinned by a GREEN
tripwire in the wave's spec files — the assertion PASSES today because it
records today's behaviour, and goes RED the moment the behaviour changes, which
forces this ticket to be revisited.

| # | Sev | Surface | Summary |
|---|---|---|---|
| **D1a** | **P1 — must fix before launch** (re-graded, fix round 1) | invoice GL posting | `AccountingService::createInvoiceGLEntries()` has **no double-entry guard at all** and auto-posts straight into the hash chain. Defence-in-depth: the one route-in that ever fired is CLOSED, so this is not demonstrated-live-P0 — but an unguarded auto-posting path into an immutable ledger must not ship |
| **D1b** | **P1 — evidence hygiene** | trial balance, balance sheet | The ledger is out of balance by exactly `19.000` and **cannot be edited back** (immutable, 915 entries chain off it). The unbalanced entry is **the campaign's own `MTP-DOC-06` probe**, not seeded or customer data. Needs a forward correcting entry or an annotation on the evidence pack |
| **D2** | **P0 — LAUNCH BLOCKING** | aged receivables | A posted, wholly unpaid invoice is **invisible** to `/finance/aged-receivables`. `documents.balance_due` is a **PostgreSQL trigger cache fired by `payment_allocations` DML only**, so a never-allocated document stays `NULL` forever. **As of run 3 (2026-08-05): 165 posted invoices totalling `59 532.410` TND missing**, against a reported grand total of `32 892.422`. Both numbers GROW per campaign run |
| **D3** | P2 | trial balance | Empty-period trial-balance totals render at **scale 2** (`'0.00'`), not the report scale 4 every other report uses |
| **D4** | **P0** | aged receivables + aged payables | The aging buckets are **sign-inverted**. Every overdue invoice is reported as *Current* — nothing has ever aged out of Current on this tenant — while a not-yet-due invoice would be aged as if late |
| **D5** | P1 | all `/finance/*` reports | `reports.view` and `ledger.view` are granted to **no seeded role except `admin`**, while the matching front-end routes are gated on `accounts.view`. The accountant, manager and viewer are all let onto pages whose API then answers **403** |
| **D6** | P1 | `/finance/overview` | The "Finance Overview" widget renders six TND money tiles **labelled EUR, at 2 decimals**, next to four sibling tiles on the same page that correctly render TND at 3 decimals |
| **R1** | recorded | aged payables | `/finance/aged-payables` covers only *posted* POs + auto-generated received POs. Confirmed POs, normally-received POs and supplier invoices are excluded **by design** — the report is not a view of the `401 Fournisseurs` ledger and must never be reconciled against it |
| **R2** | recorded | journal entry form | The client balance indicator uses a **float tolerance of `0.01`**, an order of magnitude wider than the millime the TND ledger stores: a `0.005` gap renders as **Balanced** and submits, and only the server refuses it. Misleading by design today (`MTP-GL-03` records both observations) |
| **R3** | recorded | ledger | `GET /ledger`'s `total_debits` / `total_credits` / `closing_balance` are **page-scoped**, not window-scoped. The running balance itself does carry across pages, so the `closing = opening + Σdr − Σcr` invariant holds per page; only the labels mislead |
| **R4** | recorded | test plan | The plan's DemoPharmacySeeder pins are **stale**: `Clinique Al Amal` exists as a partner but carries zero documents (so "900 outstanding" is unmet), and `Medis Distribution SARL`'s "3200 payable" is unreachable through aged payables (R1). `MTP-GL-10`/`-11`'s `CoffeeShopSeeder` pins (`CUST-001` `300.000`, `CUST-002` `1200.000`, `SUPP-001` `2500.000`) need a second tenant — campaign blocker **C-4** |

---

## D1 — the invoice GL path is unguarded, and one probe entry left the ledger `19.000` out of balance

> **RE-GRADED in fix round 1.** The wave originally filed this as a single
> demonstrated-live P0. The reviewer ran the trigger predicate over the whole
> invoice population and the result splits the finding in two, with a materially
> different escalation: **D1a** (the missing guard) is a **P1 must-fix-before-
> launch defence-in-depth** item, not a live P0, because the only route-in that
> ever fired is closed; **D1b** (the stranded `19.000`) is a **P1 evidence-
> hygiene** item on *campaign test money*, not on seeded or customer data.
> The three facts that drive the re-grade are stated below and must not be
> dropped from any escalation summary.

### Fact 1 — the unbalanced entry is the campaign's own probe

```
journal_entries.entry_number = INV-20260802000000-019fc2b7da2b734c86a0d01b4cd5294a
  chain_sequence 495, source_type 'Document', description 'Invoice INV-2026-0320', status posted

  411  Clients                 Dr 100.000    "AR from Invoice INV-2026-0320"
  707  Ventes de marchandises  Cr 100.000    "Revenue from Invoice INV-2026-0320 - Line 1"
  4457 TVA collectée           Cr  19.000    "VAT 19.00% from Invoice INV-2026-0320"
                               ------------
                               Dr 100.000 / Cr 119.000   ->  -19.000
```

The document is `INV-2026-0320` on partner **`DOC06-probe`**, created
`2026-08-02 13:43:11`. That partner is **this campaign's own `MTP-DOC-06`
fixture** (`apps/web/e2e/money-campaign/documents-lifecycle.spec.ts:155`,
`uniqueName('DOC06')`). It is **campaign test money — not seeded data, not
customer data.** It was minted through the confirm-zeroes-VAT defect that
`7258a409f` closed **the same day**
(`docs/superpowers/tickets/2026-08-02-confirm-zeroes-vat-unconfigured-rates.md`,
CLOSED): the document header was written `tax_amount 0.000` while its line kept
`tax_rate 19.00`.

### Fact 2 — the known route-in is CLOSED; the truncation bias runs positive

The trigger predicate — *residual = `total − Σline_total − Σ(line_total ×
tax_rate/100)`*, negative meaning "the credit side over-runs the AR debit" — run
over **every posted invoice on the tenant**:

```sql
with per_doc as (
  select d.id, d.total,
         coalesce(sum(dl.line_total),0)                                       as rev,
         coalesce(sum(round(dl.line_total * coalesce(dl.tax_rate,0)/100,3)),0) as vat
  from documents d left join document_lines dl on dl.document_id = d.id
  where d.type='invoice' and d.status='posted' and d.deleted_at is null
  group by d.id, d.total)
select count(*)                                        as total_posted,      -- 274
       count(*) filter (where total-rev-vat < 0)        as negative_residual, -- 1
       count(*) filter (where total-rev-vat = 0)        as zero_residual,     -- 0
       count(*) filter (where total-rev-vat > 0)        as positive_residual  -- 273
from per_doc;
```

**Exactly one** negative residual across 274 posted invoices — the `DOC06-probe`
document above. The other 273 are **positive**, which is the benign direction:
a positive residual is absorbed by the stamp-duty leg
(`AccountingService.php:203-214`), and the `bcmul` truncation in
`groupTaxByRate()` biases the recomputed VAT *down*, so the residual it produces
is positive by construction. The defect only bites when the header `tax_amount`
is *smaller* than the recomputed line tax — which is precisely what the closed
confirm-zeroes-VAT bug did, and nothing else on HEAD does.

**Consequence for the grade:** the guard is real and still missing, but it is
**not currently reachable through any known path**. It is a
**must-fix-before-launch defence-in-depth** item (P1), not a live P0.

### Fact 3 — chain integrity is intact, and the GL chain is not the E-7 chain

- `GeneralLedgerHashService::serializeForHashing()`
  (`apps/api/app/Modules/Accounting/Application/Services/GeneralLedgerHashService.php:70-91`)
  hashes `entry_number|entry_date|company_id|Σdebit|Σcredit`, so the unbalanced
  totals *are* inside the hash.
- But `verifyChain()` (`:99-138`) checks only three things: contiguous
  `chain_sequence`, `previous_hash` linkage, and hash recomputation. It **never
  asserts `Σdebit == Σcredit`.** An unbalanced entry therefore passes chain
  verification.
- Verified live: **1 410 entries, `fiscal_hash` non-null, `chain_sequence`
  contiguous `1..1410`, no gaps or duplicates.** The chain passes today.
- The GL hash chain is **distinct from the document / POS fiscal chain**, so
  **E-7 Z-EOD evidence is untouched** by this finding.

### D1a — the missing guard (P1, must fix before launch)

Root cause, `apps/api/app/Modules/Accounting/Application/Services/AccountingService.php`
`createInvoiceGLEntries()` (line cites corrected in fix round 1):

1. `:147-154` debits AR with the **header** `$invoice->total`.
2. `:156-177` credits revenue with **each line's** `line_total`.
3. `:179-194` credits VAT from `groupTaxByRate($invoice->lines)` — which at
   `:465-488` **RECOMPUTES** tax as `line_total × tax_rate / 100`, ignoring the
   authoritative `tax_amount` on both the header and the line.
4. `:203-214` computes the stamp-duty residual `total − revenue − lineVAT` and
   posts it **only `if (bccomp($stampDuty,'0',…) > 0)`** — so a **negative**
   residual is silently discarded rather than refused.
5. `:115-126` creates the entry with `'status' => JournalEntryStatus::Posted`
   directly, and `:216-223` seals it into the fiscal hash chain. **No
   `DoubleEntryValidator` call exists anywhere on this path** — unlike the
   manual route, which is guarded at `JournalEntryController::store():72-88`
   (`UNBALANCED_ENTRY` / `INVALID_LINE`).

**Fix:** `createInvoiceGLEntries()` — and its credit-note sibling on the same
service, which repeats the pattern at `:351` — must refuse to persist an entry
whose Σdebits ≠ Σcredits rather than dropping the residual. A negative residual
is a bug, never a rounding artefact. Consider also having `verifyChain()` assert
the balance invariant, so a future unbalanced entry is caught by the compliance
tooling rather than by a QA campaign.

### D1b — the stranded `19.000` (P1, evidence hygiene)

The entry is immutable and **915 entries chain off it**, so it cannot be edited
or removed. Two acceptable dispositions, and the choice is the accountant's:

- **A forward correcting entry** — the accountant chooses the absorbing account
  (the `19.000` was credited to `4457 TVA collectée` against no debit).
- **Or annotate the evidence pack** as campaign contamination, since the money
  is a QA probe on `DOC06-probe` rather than a real receivable.

Until one of the two is done, `demo-pharmacy-tn`'s trial balance and balance
sheet **cannot be presented to an accountant** — see the E-7 note in the
results file.

**Tripwires:** `finance-reports.spec.ts` `MTP-GL-08` (gap `19.0000`, culprit's
three legs re-derived through `GET /ledger`), `MTP-GL-15` (same gap on the
balance sheet, cross-checked against the trial balance), `MTP-GL-09` (a
correctly balanced entry leaves the gap untouched).

---

## D2 — a posted, unpaid invoice never reaches aged receivables (P0, launch-blocking)

**Symptom:** post a `900.000` invoice for a brand-new customer.
`GET /documents/{id}` reports `status posted`, `balance_due 900.000`,
`outstanding_amount 900.000`. `GET /reports/aged-receivables` does **not list
the customer at all**, and its `grand_total` does not move by a millime.

**Root cause — `documents.balance_due` is a PostgreSQL TRIGGER CACHE, not a
PHP-maintained column** (deepened in fix round 1; the first version of this
ticket looked for a PHP writer and therefore mis-stated the fix surface):

- `AgedReceivablesService::getOutstandingInvoices():146-151` filters on the
  **persisted column**: `->where('balance_due', '>', 0)`, and
  `calculateCustomerAging():193` reads `$invoice->balance_due` for the amount.
- That column is maintained **entirely in the database**. Migration
  `database/migrations/tenant/2026_01_08_214145_add_balance_due_cache_trigger.php:57-58`
  installs

  ```sql
  CREATE TRIGGER payment_allocation_balance_update
  AFTER INSERT OR UPDATE OR DELETE ON payment_allocations …
  ```

  and its sibling
  `2026_01_10_100001_add_credit_note_allocation_trigger.php:18-19` does the same
  `AFTER INSERT OR UPDATE OR DELETE ON credit_note_allocations`. **Both fire on
  ALLOCATION DML only.** `Document.php:698-702` states the design explicitly:
  *"This is the SOURCE OF TRUTH — computed from allocations. The balance_due
  column is a CACHED value maintained by PostgreSQL trigger."*
- Therefore a document that has **never been allocated against** has no trigger
  event to fire and its `balance_due` stays `NULL` **forever, regardless of what
  any PHP code does**. Posting an invoice creates no allocation row, so no
  ordinary receivable is ever cached.
- The document API **masks** it: `DocumentData::fromModel():167-177` computes
  `balance_due` from `outstanding_amount` for payment-tracked types and never
  reads the persisted column. So the invoice looks open everywhere except the
  one report that matters.

**This means the fix is NOT "call a PHP setter on the post path".** It is either
(a) extend trigger coverage so a document is cached at creation/post as well as
on allocation DML, or (b) make the aged reports read `outstanding_amount` — the
declared source of truth — the way `DocumentData` already does. (b) is the
smaller change and removes a cache from the critical path entirely.

**W-1's D9 (`f670d37bf`, full-refund status revert) is NOT implicated.** Refund
and payment both write allocation rows, which is exactly why paid-then-refunded
invoices *do* appear on the report — that behaviour is correct and is what
`MTP-GL-26` asserts.

**Blast radius — AS OF RUN 3 (2026-08-05). These numbers GROW with every
campaign run; treat them as a magnitude, never as a fixture constant:**

```sql
select count(*) filter (where balance_due is null)          as null_bd,       -- 165
       count(*) filter (where balance_due is not null
                          and balance_due > 0)              as positive_bd,   -- 107
       coalesce(sum(total) filter (where balance_due is null),0)              -- 59 532.410
from documents
where type='invoice' and status='posted' and deleted_at is null;
```

`/finance/aged-receivables` reported a grand total of `32 892.422` at the same
moment, so **`59 532.410` of posted receivables was invisible to it** — the
report under-states open AR by roughly two thirds. The same persisted column
drives `AgedPayablesService`.

A pay-then-refund round trip is presently the *only* way an ordinary invoice
ever appears on the aging report, because the refund path is what finally
materialises the column (`MTP-GL-26` documents exactly this sequence).

**Tripwires:** `finance-aged.spec.ts` `MTP-GL-12` (posted invoice absent, grand
total unmoved), `MTP-GL-26` (absent → paid → refunded → present).

---

## D3 — empty-period trial-balance totals render at scale 2 (P2)

```
GET /reports/trial-balance?as_of_date=2019-01-01
  { total_debit: "0.00", total_credit: "0.00", is_balanced: true, lines: [] }
GET /reports/balance-sheet?as_of_date=2019-01-01
  { total_assets: "0.0000", … }
GET /reports/profit-loss?date_from=2019-01-01&date_to=2019-01-31
  { total_revenue: "0.0000", … }
```

`TrialBalanceService` seeds its accumulators with the literal `'0.00'` and only
ever `bcadd`s onto that seed, so a period with no lines never reaches the
report scale. Not blank, not `NaN`, not a dash — so `MTP-GL-19` still PASSES —
but the scale is inconsistent with every sibling report and with the TND
currency scale of 3.

**Tripwire:** `finance-reports.spec.ts` `MTP-GL-19`.

---

## D4 — the aging buckets are sign-inverted (P0)

**Symptom:** the tenant carries `HIST-INV-*` receivables **31 and 61 days
overdue** (W-4's deliberately-left-behind AR/AP historicals). Every one of them
is reported in **Current**, and across the entire report

```
total_days_30 + total_days_60 + total_days_90 + total_over_90 == 0.0000
```

with the whole grand total sitting in `total_current` (`32 892.422` as of run 3,
2026-08-05 — a magnitude that grows per campaign run, not a fixture constant).
The zero above is the durable assertion; the grand total is context only.

**Root cause:**
`AgedReceivablesService::calculateCustomerAging():196-200`

```php
$referenceDate = $invoice->due_date ?? $invoice->document_date;
$daysOverdue   = (int) $asOfDate->diffInDays(Carbon::parse($referenceDate), false);
$bucket        = $this->determineBucket($daysOverdue);
```

Carbon's signed `diffInDays` returns **(argument − receiver)**, so for an
invoice due in the PAST this value is **negative**. `determineBucket():230-232`
then maps

```php
if ($daysOverdue < 0 || $daysOverdue <= 30) { return 'current'; }
```

— every overdue invoice, at any age, to `current`. The parameter's own docblock
says the opposite (`@param int $daysOverdue Positive = overdue, Negative = not
due yet`, `:227`), which is what makes this a sign bug rather than a policy
choice. The consequence runs both ways: a **not-yet-due** invoice produces a
positive day count and is aged as though it were late.

`AgedPayablesService::calculateVendorAging():273` and `determineBucket():306-320`
are byte-identical, so aged payables carries the same inversion. It could not be
exercised live because every AP fixture on this tenant is under 30 days old —
recorded by code citation, not executed.

Net effect: **`/finance/aged-receivables` and `/finance/aged-payables` cannot be
used for collections or for cash-flow planning.** They currently claim every
receivable is current.

**Tripwire:** `finance-aged.spec.ts` `MTP-GL-14` (per-partner: the fixture is
proven >30 days overdue through `GET /invoices?partner_id=…`, then asserted to
sit in `current`; plus the report-wide "nothing has ever aged" assertion).

---

## D5 — `reports.view` / `ledger.view` are admin-only, but the FE routes are not (P1)

`RolesAndPermissionsSeeder::rolePermissionGrants()` grants `reports.view` and
`ledger.view` to **`admin` only** (`admin` gets `Permission::all()` at `:474`).
The finance-relevant roles get neither:

| Role | GL-relevant grants | `reports.view`? | `ledger.view`? |
|---|---|---|---|
| `accountant` (`:717-745`) | `journal.view/create/post`, `accounts.view/manage`, `reports.financial`, `reports.manage` | **no** | **no** |
| `manager` (`:519`) | `journal.view`, `accounts.view/manage`, `reports.financial/operational/manage` | **no** | **no** |
| `viewer` (`:643`) | `journal.view`, `accounts.view`, `reports.operational` | **no** | **no** |

Every financial report route is `->middleware('can:reports.view')`
(`Accounting/Presentation/routes.php:162-192`) and the ledger is
`can:ledger.view` (`:157-159`). Verified live for all three roles:

```
accountant / viewer / manager  ->  GET /reports/trial-balance     403
                                   GET /reports/aged-receivables  403
                                   GET /reports/balance-sheet     403
                                   GET /reports/finance-summary   403
                                   GET /ledger                    403
                                   GET /journal-entries           200
                                   GET /accounts                  200
```

Meanwhile the front end gates `/finance/trial-balance`, `/finance/profit-loss`,
`/finance/balance-sheet`, `/finance/aged-receivables` and
`/finance/aged-payables` on **`accounts.view`** (`apps/web/src/routes/index.tsx`),
which all three roles hold — so `RequirePermission` lets them in and the page
then fails its fetch. `/finance/ledger` is gated on `journal.view` for the same
mismatch.

**The front end is not even internally consistent** (added in fix round 1):
`apps/web/src/routes/index.tsx:1940` gates `/finance/cash-movements` on
**`reports.view`** — the API-matching permission — while the five sibling
finance reports two blocks below it are gated on `accounts.view`. So one
`/finance/*` report route already agrees with the API and blocks non-admins at
the door, and five do not. Whichever way the ruling goes, these six routes must
end up on the same rule.

Two symptoms fall out of the same grant:

- The **accountant cannot read a single financial report** — the persona whose
  whole job this is.
- The finance hub hides its own Treasury card from every non-admin, because
  `FinanceHubPage`'s `treasuryOverview` entry declares `permission: 'reports.view'`
  (`FinanceHubPage.tsx:44-50`).

**Needs an owner ruling**, not a blind fix. The brief for that ruling:

1. Either grant `reports.view` / `ledger.view` to `accountant` (and probably
   `manager`), **or** retire them in favour of the `reports.financial` /
   `reports.operational` split the roles already carry.
2. Decide which of the two existing FE conventions wins — `reports.view`
   (`cash-movements`, `routes/index.tsx:1940`) or `accounts.view` (the five
   finance reports) — and align all six routes plus `/finance/ledger` to it.
3. Decide whether the finance hub's `treasuryOverview` card
   (`FinanceHubPage.tsx:44-50`, `permission: 'reports.view'`) should be visible
   to the accountant; today it is admin-only, which is a consequence of (1)
   rather than a deliberate choice.

Either way the FE and API gates must be made to agree.

**Tripwires:** `finance-permissions.spec.ts` `MTP-GL-23` (seven 403s + the FE
route that lets the accountant in anyway), `MTP-GL-28` (the hub card is absent
for the accountant).

---

## D6 — the finance widget renders TND as EUR at 2 decimals (P1)

`apps/web/src/features/finance/components/FinanceWidget.tsx` renders all six of
its tiles with `formatCurrency(data?.total_assets ?? '0')` — **no options**.
`apps/web/src/lib/format.ts:77` defaults `const currency = options?.currency ?? 'EUR'`,
and the decimal count is derived from that currency, so on the Tunisian
`PharmaBio Tunisie SARL` company every widget tile renders as
`228 728,39 EUR` instead of `228 728,386 TND`.

The four `StatCard`s directly above it on the same page use
`formatReportCurrency(amount, currentCompany)`
(`TreasuryOverviewPage.tsx:171`, `reportPageUtils.ts:27-37`) and render TND at 3
decimals correctly — so `/finance/overview` shows **two different currencies in
one viewport**. The underlying numbers are right; this is purely a rendering
defect, and the fix is to pass the company currency the way its sibling does.

Affected tiles: Total Assets, Total Liabilities, Net Income (MTD), Net Income
(YTD), Accounts Receivable, Accounts Payable.

**Tripwire:** `finance-permissions.spec.ts` `MTP-GL-27` — asserts the four
StatCards match the cash-position API digit-for-digit and carry `TND`, and that
all six widget tiles carry `EUR` at 2 decimals.

---

## R1..R4 — recorded behaviours (no fix requested)

**R1 — aged payables is deliberately narrow.**
`AgedPayablesService::getOutstandingInvoices():152-165` unions *posted* purchase
orders with `autoGeneratedReceivedPurchaseOrdersWithAccrual()` only. Its own
docblock (`:140-142`) states the intent: *"Normal received POs are deliberately
excluded so the report does not surface phantom payables."* Live consequence:
`Medis Distribution SARL` holds one draft, two **confirmed** and one normally
**received** PO on this tenant and appears nowhere on aged payables; as of run 3
(2026-08-05) the report grand total was `210.000` against a `401 Fournisseurs`
credit balance of `6 089.904` — both magnitudes move with sibling waves. Do not
reconcile one against the other.

**R2 — the journal-entry balance indicator is misleading by design.**
`JournalEntryForm.tsx:51-53` computes `parseFloat`-based totals and
`Math.abs(totalDebits - totalCredits) < 0.01`. A `Dr 100.000 / Cr 99.995` entry
renders **Balanced**, submits, and is refused server-side with
`UNBALANCED_ENTRY`. Both observations are recorded by `MTP-GL-03`, as the case
asks. (`MoneyInput` itself is precision-safe; only the indicator is not.)

**R3 — ledger totals are page-scoped.** `total_debits`, `total_credits` and
`closing_balance` describe the requested PAGE, not the requested window, so page
1 of a multi-page window under-reports. The running balance does carry across
pages (page 2's `opening_balance` is page 1's `closing_balance`), so the
`closing == opening + Σdr − Σcr` invariant `MTP-GL-17` asserts holds per page.
Pinned by `MTP-GL-17`'s final block.

**R4 — the test plan's aged-report fixture pins are stale/unreachable.**
`MTP-GL-10` and `MTP-GL-11` are written against the `CoffeeShopSeeder` tenant
(`CUST-001` `300.000`, `CUST-002` `1200.000`, `CUST-003` absent, Σ `1500.000`;
`SUPP-001` `2500.000`), which this campaign has no credentials for — the same
blocker the campaign plan records as **C-4**. `MTP-GL-12`'s DemoPharmacySeeder
pins are unmet on the current seed: `Clinique Al Amal` exists as a partner but
holds zero documents, and `Medis Distribution SARL`'s payable is invisible per
R1. Both were re-expressed as self-created known amounts, which is the only
stable shape on a tenant six waves have written to.

---

## Fix-order recommendation

Re-ordered in fix round 1 to match the re-graded severities.

1. **D2 (P0)** — make the aged reports read `outstanding_amount`, or extend the
   trigger's coverage beyond allocation DML. Until this lands, the AR aging
   report is not merely wrong, it is empty of most receivables — and it is the
   only P0 left after the D1 re-grade.
2. **D4 (P0)** — one-line sign fix in two services, plus tests at the
   30/31/60/61/90/91-day boundaries the plan specifies.
3. **D1a (P1, must fix before launch)** — no code path may persist an unbalanced
   journal entry. Not currently reachable, but it is the guard that stops silent
   corruption from entering an immutable, hash-chained ledger; consider adding
   the same invariant to `verifyChain()`.
4. **D1b (P1)** — accountant's choice: a forward correcting entry for the
   `19.000`, or an annotation recording it as campaign contamination. Must be
   settled BEFORE the fiscal evidence run, not during it.
5. **D5 (P1)** — owner ruling on the permission model (three questions above),
   then align all six `/finance/*` report routes and the hub card.
6. **D6 (P1)**, **D3 (P2)** — rendering / scale fixes.
