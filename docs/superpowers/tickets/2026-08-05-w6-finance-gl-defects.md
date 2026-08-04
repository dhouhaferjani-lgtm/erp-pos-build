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
| **D1** | **P0 — LAUNCH BLOCKING** | trial balance, balance sheet | The general ledger **does not balance**. Credits exceed debits by exactly `19.000`. One posted, hash-chained invoice entry is internally unbalanced, and `AccountingService::createInvoiceGLEntries()` has **no double-entry guard at all** |
| **D2** | **P0 — LAUNCH BLOCKING** | aged receivables | A posted, wholly unpaid invoice is **invisible** to `/finance/aged-receivables`. Nothing on the invoice create/confirm/post path writes the persisted `documents.balance_due` the report filters on. **163 posted invoices totalling `57 732.410` TND are missing from the report**, whose grand total reads `31 892.422` |
| **D3** | P2 | trial balance | Empty-period trial-balance totals render at **scale 2** (`'0.00'`), not the report scale 4 every other report uses |
| **D4** | **P0** | aged receivables + aged payables | The aging buckets are **sign-inverted**. Every overdue invoice is reported as *Current* — nothing has ever aged out of Current on this tenant — while a not-yet-due invoice would be aged as if late |
| **D5** | P1 | all `/finance/*` reports | `reports.view` and `ledger.view` are granted to **no seeded role except `admin`**, while the matching front-end routes are gated on `accounts.view`. The accountant, manager and viewer are all let onto pages whose API then answers **403** |
| **D6** | P1 | `/finance/overview` | The "Finance Overview" widget renders six TND money tiles **labelled EUR, at 2 decimals**, next to four sibling tiles on the same page that correctly render TND at 3 decimals |
| **R1** | recorded | aged payables | `/finance/aged-payables` covers only *posted* POs + auto-generated received POs. Confirmed POs, normally-received POs and supplier invoices are excluded **by design** — the report is not a view of the `401 Fournisseurs` ledger and must never be reconciled against it |
| **R2** | recorded | journal entry form | The client balance indicator uses a **float tolerance of `0.01`**, an order of magnitude wider than the millime the TND ledger stores: a `0.005` gap renders as **Balanced** and submits, and only the server refuses it. Misleading by design today (`MTP-GL-03` records both observations) |
| **R3** | recorded | ledger | `GET /ledger`'s `total_debits` / `total_credits` / `closing_balance` are **page-scoped**, not window-scoped. The running balance itself does carry across pages, so the `closing = opening + Σdr − Σcr` invariant holds per page; only the labels mislead |
| **R4** | recorded | test plan | The plan's DemoPharmacySeeder pins are **stale**: `Clinique Al Amal` exists as a partner but carries zero documents (so "900 outstanding" is unmet), and `Medis Distribution SARL`'s "3200 payable" is unreachable through aged payables (R1). `MTP-GL-10`/`-11`'s `CoffeeShopSeeder` pins (`CUST-001` `300.000`, `CUST-002` `1200.000`, `SUPP-001` `2500.000`) need a second tenant — campaign blocker **C-4** |

---

## D1 — the general ledger does not balance (P0, launch-blocking)

**Symptom, measured live 2026-08-05 on `demo-pharmacy-tn`:**

```
GET /api/v1/reports/trial-balance
  total_debit  433001.8120
  total_credit 433020.8120
  is_balanced  false            <- credits over debits by exactly 19.000

GET /api/v1/reports/balance-sheet
  total_assets      228728.3860
  total_liabilities  37824.6970
  total_equity      190922.6890   -> L+E = 228747.3860
  is_balanced       false          <- the SAME 19.000
```

The money-test-plan is explicit for `MTP-GL-08`: *"Σ debits == Σ credits exactly
at scale 3. Any non-zero difference is launch-blocking."*

**The culprit — one entry, verified against the tenant DB and re-proved through
the API by the spec:**

```
journal_entries.entry_number = INV-20260802000000-019fc2b7da2b734c86a0d01b4cd5294a
  source_type 'Document', description 'Invoice INV-2026-0320', status posted

  411  Clients                 Dr 100.000    "AR from Invoice INV-2026-0320"
  707  Ventes de marchandises  Cr 100.000    "Revenue from Invoice INV-2026-0320 - Line 1"
  4457 TVA collectée           Cr  19.000    "VAT 19.00% from Invoice INV-2026-0320"
                               ------------
                               Dr 100.000 / Cr 119.000   ->  -19.000
```

The document itself says `subtotal 100.000 / tax_amount 0.000 / total 100.000`,
on a single line priced `2 × 50.000` carrying `tax_rate 19.00`.

**Root cause (live on HEAD, independent of the historical data):**
`apps/api/app/Modules/Accounting/Application/Services/AccountingService.php`
`createInvoiceGLEntries()`

1. `:152-155` debits AR with the **header** `$invoice->total`.
2. `:158-176` credits revenue with **each line's** `line_total`.
3. `:180-193` credits VAT from `groupTaxByRate($invoice->lines)` — which at
   `:465-488` **RECOMPUTES** tax as `line_total × tax_rate / 100`, ignoring the
   authoritative `tax_amount` on both the header and the line.
4. `:206-215` computes the stamp-duty residual `total − revenue − lineVAT` and
   posts it **only `if (bccomp($stampDuty,'0',…) > 0)`** — so a **negative**
   residual is silently discarded rather than refused.
5. `:114-126` creates the entry with `'status' => JournalEntryStatus::Posted`
   directly, and `:220-224` seals it into the fiscal hash chain. **No
   `DoubleEntryValidator` call exists anywhere on this path** — unlike the
   manual route, which is guarded at
   `JournalEntryController::store():72-88` (`UNBALANCED_ENTRY` / `INVALID_LINE`).

So any divergence between the header `tax_amount` and the recomputed line tax
mints a permanently unbalanced, hash-chained entry. The specific divergence that
produced `INV-2026-0320` was the (now **CLOSED**) confirm-zeroes-VAT defect —
`docs/superpowers/tickets/2026-08-02-confirm-zeroes-vat-unconfigured-rates.md`,
fixed by `7258a409f` — but that fix closed one *route in*, not the hole itself.

**Two separable follow-ups, both needed:**

- **Guard:** `createInvoiceGLEntries()` (and its credit-note / expense siblings
  on the same service) must refuse to persist an entry whose Σdebits ≠ Σcredits,
  rather than dropping the residual. A negative residual is a bug, never a
  rounding artefact.
- **Data:** the stranded entry cannot be edited (it is chained). Repair needs a
  deliberate, documented correcting entry — and the tenant's trial balance
  cannot be presented to an accountant until it is made.

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

**Root cause:**

- `AgedReceivablesService::getOutstandingInvoices():146-151` filters on the
  **persisted column**: `->where('balance_due', '>', 0)`, and
  `calculateCustomerAging():193` reads `$invoice->balance_due` for the amount.
- **Nothing on the invoice create → confirm → post path ever writes that
  column.** It is written only by converters
  (`CopiesDocumentData:87`, `SalesOrderToInvoiceConverter:386`), AR/AP openings
  (`ArApOpeningService:309/321`), POS account charges
  (`POSAccountChargeDraftService:65/167`) and treasury settlement
  (`InstrumentLifecycleService:545`, `OutboundInstrumentService:680`,
  `MultiPaymentService:137`, `CloseInvoiceWithToleranceService:117`,
  `VendorRefundService:149`). A directly-created invoice keeps `balance_due =
  NULL` forever unless someone pays it.
- The document API **masks** this: `DocumentData::fromModel():167-177` computes
  `balance_due` from `outstanding_amount` for payment-tracked types and never
  reads the persisted column. So the invoice looks open everywhere except the
  one report that matters.

**Blast radius, measured on `demo-pharmacy-tn` 2026-08-05:**

```sql
select count(*) filter (where balance_due is null)          as null_bd,       -- 163
       count(*) filter (where balance_due is not null
                          and balance_due > 0)              as positive_bd,   -- 105
       coalesce(sum(total) filter (where balance_due is null),0)              -- 57 732.410
from documents
where type='invoice' and status='posted' and deleted_at is null;
```

`/finance/aged-receivables` currently reports a grand total of `31 892.422`
while **`57 732.410` of posted receivables is invisible to it** — the report
under-states open AR by roughly two thirds. The same persisted column drives
`AgedPayablesService`.

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

with `grand_total 31 892.422` sitting wholly in `total_current`.

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

Two symptoms fall out of the same grant:

- The **accountant cannot read a single financial report** — the persona whose
  whole job this is.
- The finance hub hides its own Treasury card from every non-admin, because
  `FinanceHubPage`'s `treasuryOverview` entry declares `permission: 'reports.view'`
  (`FinanceHubPage.tsx:44-50`).

**Needs an owner ruling**, not a blind fix: either grant `reports.view` /
`ledger.view` to `accountant` (and probably `manager`), or retire them in favour
of the `reports.financial` / `reports.operational` split the roles already use.
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
**received** PO on this tenant and appears nowhere on aged payables; the report
grand total is `210.000` against a `401 Fournisseurs` credit balance of
`6 089.904`. Do not reconcile one against the other.

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

1. **D1 guard** — no code path may persist an unbalanced journal entry. This is
   the one that lets silent corruption into a hash-chained ledger.
2. **D2** — write `balance_due` on the invoice post path (or make the aged
   reports read `outstanding_amount` the way `DocumentData` already does).
   Without it the AR aging report is not merely wrong, it is empty of most
   receivables.
3. **D4** — one-line sign fix in two services, plus tests at the 30/31/60/61/
   90/91-day boundaries the plan specifies.
4. **D1 data repair** — a documented correcting entry for the `19.000`.
5. **D5** — owner ruling on the permission model, then align FE and API gates.
6. **D6**, **D3** — rendering/scale fixes.
