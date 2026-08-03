# Adversarial review gate — dashboard KPI lane

**Date:** 2026-08-03
**Scope:** `ebf5becfb` (DashboardController Posted∪Paid buckets, bc-string revenue, 7 tests) +
`95176803c` (Dashboard.tsx string retype, big.js sign, fixtures, MOBILE FOLLOW-UP)
**Branch:** `dev` (local, NOT pushed)
**Rulings in force:** `docs/superpowers/tickets/2026-08-02-dashboard-stats-status-buckets-and-float-sum.md` § RESOLUTION
**Reviewer stance:** adversarial; every claim below is verified against code or live probe.

## VERDICT: **APPROVE-WITH-FIXES — NOT promotable as-is**

The revenue widening, the bc-string conversion, and the entire web-consumer change are **sound and
verified**. One ruling (ruling 3, `paymentsPending`) produces a **materially wrong headline KPI on
the launch dashboard** and is **pinned as correct by a new test**. That plus a date-dependent CI
flake must be fixed before promotion. Everything else is carry-over.

| ID | Sev | Must fix before promotion |
|----|-----|---------------------------|
| H1 | HIGH | YES |
| M1 | MED | YES (CI landmine) |
| M2–M4, L1–L6 | MED/LOW | No — carry over |

---

## Verification performed

| Check | Result |
|---|---|
| `phpunit tests/Feature/Dashboard/DashboardStatsTest.php` | **8/8 pass** (7 new + 1 pre-existing) |
| Red check — controller reverted to `ebf5becfb^`, tests re-run | **5 of 7 fail on old code** (see E) |
| `./vendor/bin/phpstan analyse app/Modules/Dashboard` (level 8) | **No errors** |
| `pnpm typecheck` (apps/web) | **clean** |
| `pnpm vitest run src/features/dashboard` | **9/9 pass** |
| Live probe `GET /api/v1/dashboard/stats` (demo-pharmacy-tn, :8010) | 200, values below |
| Live tenant DB cross-check (`tenant019fbe86-…`) | see H1 |
| `erp-mobile` read-only verification of the follow-up section | see D |

Working tree restored to clean after both experiments (`git status` verified).

---

## A. Bucket semantics

### A.1 Full `DocumentStatus` enumeration — no leak (VERIFIED CORRECT)

`apps/api/app/Modules/Document/Domain/Enums/DocumentStatus.php:9-14` has exactly six cases.
Against `DashboardController.php:43` (`$revenueStatuses = [Posted, Paid]`), `:48`, `:57`, `:89`, `:130`:

| Status | Revenue base (`:43`) | Overdue (`:89`) | Pending-invoices count (`:80`) | Correct? |
|---|---|---|---|---|
| `Draft` | out | out | in | ✅ |
| `Confirmed` | out | out | in | ✅ |
| `Posted` | **in** | **in** | out | ✅ |
| `Paid` | **in (new)** | out | out | ✅ per ruling 1/2 |
| `Received` | out | out | out | ✅ (supplier-side status) |
| `Cancelled` | out | out | out | ✅ |

No status leaks into or wrongly exits a bucket. The widening is **status-only**.

### A.2 Type filter — credit notes CANNOT enter revenue (VERIFIED CORRECT)

All three revenue/pending queries use an **exact-match** type filter, untouched by this commit:
`DashboardController.php:47`, `:56`, `:129` — `->where('type', DocumentType::Invoice)`.
`DocumentType` (`DocumentType.php:9-20`) has 12 cases; none of `credit_note`, `return_note`,
`supplier_invoice`, `supplier_credit_note`, `expense`, `income` can match. The Posted∪Paid
widening therefore **cannot** pull posted credit notes in as positive revenue.

Confirmed live on demo-pharmacy-tn: the tenant holds **10 posted credit notes worth 773.268 TND**
and they are correctly **absent** from the reported `revenue.current` of `118658.880`
(invoice-only posted+paid all-time = 119,612.422; current-month subset = 118,658.880).

**Carry-over (pre-existing, needs an owner ruling — L2 below):** revenue is therefore GROSS of
credit notes. A posted credit note never reduces revenue, even though the domain already models
the direction — `DocumentType::receivableDirection()` returns `-1` for `CreditNote`
(`DocumentType.php:79-86`). The dashboard does not consult it. Not introduced here; flagging
because it is the natural next question the widening raises.

### A.3 Sign handling

No sign handling is needed or present on the backend: only `type = invoice` rows are summed, and
`documents.total` for invoices is positive. `sumDecimalStrings` (`:177-190`) does a plain `bcadd`.
Negative totals would flow through unmodified — see M2 for the one place that matters
(negative `previousRevenue` silently yields `"0.00"`).

---

## H1 — HIGH — `payments.pending` now counts fully-PAID invoices as pending money

**`apps/api/app/Modules/Dashboard/Presentation/Controllers/DashboardController.php:127-135`**

```php
$invoiceRevenueBaseTotal = $this->sumDecimalStrings(   // :127-133 — ALL-TIME, Posted ∪ Paid
    Document::where('company_id', $companyId)
        ->where('type', DocumentType::Invoice)
        ->whereIn('status', $revenueStatuses)          // :130
        ->pluck('total')->all()
);
$paymentsPending = bcsub($invoiceRevenueBaseTotal, $paymentsReceived, 3);  // :135
```

`$paymentsReceived` (`:115-123`) is filtered to **this month only** (`:120`
`payment_date >= $currentMonthStart`). The minuend is **all-time**. Widening the minuend to
include `Paid` therefore adds the entire lifetime value of every settled invoice while **not**
adding the payments that settled them (those are in prior months).

**Live evidence — demo-pharmacy-tn (`tenant019fbe86-944a-7252-8a3b-8c341dfa9de9`):**

| Quantity | Value (TND) |
|---|---|
| Posted-only all-time invoice total (OLD minuend) | 57,307.441 |
| Posted ∪ Paid all-time invoice total (NEW minuend) | **119,612.422** |
| — of which 147 **fully PAID** invoices | 62,304.981 |
| `paymentsReceived` (this month, incoming) | 65,145.979 |
| **OLD `payments.pending`** = 57,307.441 − 65,145.979 → clamped at `:137-139` | **`0.000`** |
| **NEW `payments.pending`** (API response, verified) | **`54466.443`** |
| **True outstanding** = `SUM(balance_due)` over posted+paid | **27,842.672** |

So the launch dashboard's "pending payments" tile jumps from `0.000` to `54,466.443` — roughly
**2× the real receivable** — purely from this commit. The number has no arithmetic meaning: it is
`all-time invoiced − one month of receipts`.

The schema already carries the right source: `documents.balance_due numeric(15,3)`. Pending should
be `SUM(balance_due)` over open (Posted) invoices — or, if paid-with-residual is meaningful,
over Posted ∪ Paid — with **no** payment subtraction at all.

**Aggravating:** the new test **pins the defect as correct**.
`apps/api/tests/Feature/Dashboard/DashboardStatsTest.php:325-343` creates one Posted invoice
(100) and one **Paid** invoice (50), records **zero** payments, and asserts
`data.payments.pending === '150.000'` (`:342`). A fully-paid invoice contributing 50 to "pending
payments" is asserted as the expected outcome.

**Required fix:** re-derive `paymentsPending` from `balance_due`, and rewrite
`DashboardStatsTest.php:325-343` to assert the corrected value. Ruling 3 in the ticket § RESOLUTION
should be amended — "same base as revenue" is internally consistent but arithmetically invalid
once the subtrahend is month-scoped.

*(Note: the all-time-vs-this-month asymmetry pre-dates this commit. What this commit changes is the
magnitude: previously the Paid rows were excluded so the clamp at `:137-139` hid the flaw; now the
flaw is the number on screen.)*

---

## M1 — MEDIUM — new date-dependent CI flake (must fix)

`DashboardStatsTest.php:382-386` builds the "previous month" invoice with `now()->subMonth()`.
Carbon's `subMonth()` **overflows**, and the controller's window at `:35-36` uses the same call:

```
now=2026-03-31 | currentStart=2026-03-01 | prevStart=2026-03-01 | prevEnd=2026-03-31 | OVERLAP
now=2026-05-31 | currentStart=2026-05-01 | prevStart=2026-05-01 | prevEnd=2026-05-31 | OVERLAP
now=2026-08-03 | currentStart=2026-08-01 | prevStart=2026-07-01 | prevEnd=2026-07-31 | ok
```

On any day whose day-of-month exceeds the previous month's length (Mar 29/30/31, May 31, Jul 31,
Oct 31, Dec 31 — ~6 days/yr) the "previous month" window **collapses onto the current month**.

**Reproduced empirically** — injecting `Carbon::setTestNow('2026-05-31 12:00:00')` into
`test_revenue_change_is_a_two_decimal_percent_string`:

```
Failed asserting that two strings are identical.
-'25.00'
+'0.00'
```

The underlying window bug at `:35-36` is **pre-existing and unchanged**, but the new test turns it
into a CI landmine on ~6 dates a year. Fix: freeze the clock (`Carbon::setTestNow`) in the test,
and switch the controller to `subMonthNoOverflow()` (which also fixes a real month-end KPI defect:
on those dates `revenue.previous` reports the *current* month).

---

## B. bc math

### Verified correct
- **Empty sets:** `sumDecimalStrings([])` → `CurrencyScale::bcformatStrict('0', 3)` = `'0.000'`
  (`:179`, `:189`). Nulls skipped (`:182-184`). Asserted at `DashboardStatsTest.php:367`.
- **Division by zero:** guarded at `:66` (`bccomp($previousRevenue, '0', 3) > 0`). No error; emits
  the literal `'0.00'` from `:64`. No division is reached.
- **Scale threading:** consistent at 3 across `bcadd`/`bcsub`/`bccomp` (`:135`, `:137`, `:186`),
  intermediate at 10 for the percent (`:67-70`), final `bcround(…, 2)`.
- **No float round-trip in the new pluck path.** `documents.total` is cast `decimal:3`
  (`Document.php:190`), and `Eloquent\Builder::pluck` hydrates through the cast
  (`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php:1091-1100`).
  Laravel 12's `asDecimal` uses `Brick\Math\BigDecimal`
  (`.../Concerns/HasAttributes.php:1512-1519`), **not** `number_format((float)…)`. The precision
  claim in the commit message holds.
- **2dp truncate-vs-round:** `CurrencyScale::bcround` (`CurrencyScale.php:172-198`) is half-away-
  from-zero via a bcmath half-increment at `scale+1` then truncation — **identical** to the
  `bcround` used by `ExpenseAnalyticsService.php:45`. No truncate/round inconsistency.

### M2 — MEDIUM — `revenue.change` semantics diverge from the pattern the commit claims to mirror

The commit message and ticket ruling 3 say the change "mirrors the existing
`ExpenseAnalyticsService::generate()` mom-delta pattern". The **formula** matches; the **guard and
the zero-case emission do not**:

| | `DashboardController.php:64-71` | `ExpenseAnalyticsService.php:40-45` |
|---|---|---|
| Guard | `bccomp(prev,'0',3) **> 0**` (`:66`) | `bccomp(prev,'0',$scale) **!== 0**` (`:41`) |
| previous == 0 emits | `'0.00'` (`:64`) | `null` (`:40`) — UI can omit |
| previous < 0 | silently `'0.00'` | computes the delta |
| Intermediate scale | hardcoded `10` (`:67`) | `$scale + 4` from the injected resolver (`:42`) |

Consequences:
1. **First-tenant month-1 UX.** A brand-new company has `previous = 0`. The API emits `"0.00"`,
   and the web renders it as a **green `TrendingUp` "0%"** next to a large revenue figure
   (`Dashboard.tsx:159-161`, `:243-256`) — indistinguishable from a genuine flat month. The
   `null` convention exists precisely so the caller can suppress the badge. Directly relevant to
   the first-tenant launch program.
2. Negative `previousRevenue` silently reports no change.
3. Neither case is asserted. `test_revenue_values_are_scale_3_strings_not_floats:363-367` asserts
   `previous === '0.000'` and `assertIsString(change)` but never pins `change === '0.00'`.

Carry-over, not gate-blocking. Recommend aligning the guard to `!== 0` and emitting `null`
(a `string | null` wire type the web already handles via the `!== undefined` guard — see C).

---

## C. Web consumer

### Verified correct — no visible regression

- **`formatCurrency` with string input is byte-identical to the old `parseFloat` path.**
  `Dashboard.tsx:153-158` now passes the raw value; `format.ts:73-80` → `safeDecimal`
  (`format.ts:18-25`) → `new Big(str)` → `toFixed(decimals)`. For `"35000.000"` the old path was
  `parseFloat` → `35000` → same `Big` → same `toFixed`. Degenerate inputs also match: `''` → 0
  in both (`format.ts:21` vs old `isNaN` branch); non-numeric → `Big(0)` via `catch` vs `NaN→0`.
  `undefined/null` → `?? 0` in both. **Locale rendering unchanged.**
- **Percent display unchanged.** `formatPercent` (`format.ts:281-290`) → `roundDecimalString`
  (`format.ts:249-279`), whose `.replace(/(\.\d*?)0+$/, '$1')` at `:275` **strips trailing zeros**.
  So `"25.00"` → `"25%"` — the exact string the old `{Math.abs(25)}%` produced. `"10.50"` → `"10.5%"`,
  matching the old `round(…, 1)` backend. No change on screen.
- **big.js sign check is correct on `"0.00"` and `"-0.00"`.** Verified in node against the repo's
  own `big.js`:
  ```
  "-0.00"  lt0=false  abs="0"
  "0.00"   lt0=false  abs="0"
  "-0.004" lt0=true   abs="0.004"
  "-10.50" lt0=true   abs="10.5"
  ```
  `bcround` **can** emit `"-0.00"` (e.g. ratio `-0.004`), and big.js normalises negative zero, so
  `Dashboard.tsx:160` yields `TrendingUp` + `"0%"` rather than a `-0%` leak. Correct.
- TS narrowing at `Dashboard.tsx:159-163` is valid — `stats?.revenue.change !== undefined` narrows
  `stats` itself; `pnpm typecheck` is clean.

### L1 — LOW — five payload consumers missed by the "only web consumer" claim

The commit message says "Dashboard.tsx was the only web consumer". True for production code
(verified by repo-wide grep for `dashboard/stats` and `DashboardStats`), but **five e2e route
mocks still serve the OLD numeric shape**:

- `apps/web/e2e/fixtures.ts:72-83`
- `apps/web/e2e/fixtures.ts:368-379`
- `apps/web/e2e/auth.spec.ts:153-165`
- `apps/web/e2e/company.spec.ts:63-74`
- `apps/web/e2e/company.spec.ts:177-190`

All five emit `revenue: { current: 15000, previous: 12000, change: 25 }` and
`payments: { received: 35000, pending: 8000 }`. They will not break (`new Big(25)` accepts a
number, `formatCurrency` accepts `string | number`), but they now lie about the wire contract —
the exact drift the `__fixtures__/dashboard.ts` update was meant to eliminate. Also
`apps/api/tests/Feature/Performance/BaselinePerformanceTest.php:118` hits the endpoint (latency
only, no value assertions — unaffected).

---

## D. API-shape discipline & mobile

### D.1 Runtime impact on the CURRENTLY-DEPLOYED mobile app: **tolerable degradation, NOT a crash**

Walked through every `revenue.*` and `payments.*` touch point in `erp-mobile` (grep over
`src/` + `app/`, read-only):

| Site | Receives string | Runtime behaviour |
|---|---|---|
| `src/lib/format.ts:10-18` `formatMoney(value: string \| number)` | `"118658.880"` | Already string-first (`String(value)` at `:11`, `split('.')` at `:14`). Renders `118 658,880 TND` — one extra decimal vs the old float. **Cosmetic only.** |
| `app/(app)/index.tsx:110-115` `<Amount value={stats.revenue.current} format={formatMoney}/>` | yes | `Amount` types `value: string \| number` (`src/ui/atoms/Amount.tsx:10`). **No change.** |
| `app/(app)/index.tsx:58` `(stats?.revenue.change ?? 0) >= 0` | `"0.00"`, `"-10.50"` | JS relational operator applies `ToNumber` to the string operand → `0 >= 0` true, `-10.5 >= 0` false. **Correct sign, by coercion.** |
| `src/lib/format.ts:20-23` `formatPercent(change: number)` | `"12344.01"` | `change > 0` coerces → `true`; `String(change).replace('.', ',')` → `"+12344,01 %"`. **Works.** |

No `.toFixed`, `.toLocaleString`, or arithmetic is performed on `revenue.*` anywhere in
`erp-mobile`. **Nothing throws. No white screen. No `TypeError`.**

**Sequencing ruling: a mobile release is NOT required before promoting this backend change.** The
mobile follow-up PR is a type-honesty and cosmetic-decimals fix, not a crash mitigation. (It should
still land soon — the types now lie, and the next mobile dev who writes arithmetic against
`revenue.change: number` gets a silent float bug.)

### D.2 — M3 — MEDIUM — the MOBILE FOLLOW-UP section is **incomplete** against its own claims

The section states it was "confirmed by reading `erp-mobile` directly" and item 5 asserts
"**No change needed**" for everything not listed. That is wrong — it **omits an entire test file**
that encodes the old contract:

- **`erp-mobile/src/features/dashboard/__tests__/dashboardApi.test.ts:52-53`** —
  `expect(typeof stats.revenue.current).toBe('number')` and
  `expect(typeof stats.revenue.change).toBe('number')`. These are the **contract assertions** and
  they must be inverted.
- **`…/dashboardApi.test.ts:20`** — fixture `revenue: { current: 422.366, previous: 397.314, change: 6.3 }`
  is now an invalid wire shape.
- **`…/dashboardApi.test.ts:6`** — doc comment repeats the stale "floats (revenue, counts) and
  scale-3 money strings (payments)" contract.
- **`erp-mobile/src/lib/__tests__/format.test.ts:8`** — `it('formats a float (dashboard revenue) …')`
  is stale in the same way.

(These are local-fixture tests, so they will not fail on the shape change itself — which is exactly
why they need to be on the list: nothing will remind the mobile team.)

**Line-number accuracy of the claims that ARE listed** (all verified read-only):

| Ticket claim | Actual | Verdict |
|---|---|---|
| `dashboardApi.ts:10-16` revenue fields typed `number` | interface at `:11`, fields at `:13-15` | ✅ content correct, minor drift |
| "JSDoc directly above the interface (lines 3-8)" | JSDoc spans `:3-10` | ⚠️ off by two; quoted text is verbatim-correct |
| `app/(app)/index.tsx:58` `changePositive` `>= 0` | **exact match** | ✅ |
| `src/lib/format.ts:20` `formatPercent(change: number)` | **exact match** | ✅ |
| `app/(app)/index.tsx:131` `formatPercent(stats.revenue.change)` | **exact match** | ✅ |
| Item 5: `Amount.tsx:10` `string \| number`, `format.ts:10` `string \| number` | **exact match** | ✅ (but "no change needed" is false in aggregate — see above) |

### D.3 — L5 — LOW — "no changelog convention exists" is overstated

The section asserts that no cross-repo API-contract convention exists in this repo and that "this
ticket section IS the handover record — do not invent a new log location."
`docs/superpowers/coordination/` is the established location for exactly this
(e.g. `docs/superpowers/coordination/2026-06-26-supplier-invoice-api-contract.md`, and 25+ sibling
handover/contract docs). Recording it in the ticket is fine; asserting nothing exists is not.

---

## E. Tests

### E.1 Do the 7 cases pin VALUES? **Yes.**

| Test | Line | Pinned value |
|---|---|---|
| `…includes_both_posted_and_paid_invoices` | `:238` | `revenue.current === '150.000'` |
| `…refund_reverting_paid_to_posted_does_not_change_revenue` | `:258`, `:266` | `'100.000'` before **and** after |
| `…overdue_counts_only_posted_invoices_not_paid` | `:290` | `invoices.overdue === 1` |
| `…refund_revert_can_reenter_overdue_bucket` | `:311`, `:318` | `0` → `1` |
| `…payments_pending_derived_from_posted_and_paid_invoice_base` | `:342` | `'150.000'` ← **pins H1's defect** |
| `…revenue_values_are_scale_3_strings_not_floats` | `:363-367` | types **and** `'100.000'` / `'0.000'` |
| `…revenue_change_is_a_two_decimal_percent_string` | `:392` | `'25.00'` |

No shape-only assertions except the deliberate `assertIsString` trio at `:363-365`, which is paired
with value assertions at `:366-367`.

### E.2 Red→green spot check — **executed, not reasoned**

Restored `DashboardController.php` to `ebf5becfb^` and re-ran the suite:

```
Tests: 8, Assertions: 18, Failures: 5.
1) …includes_both_posted_and_paid_invoices      100 (int!) is not identical to '150.000'
2) …refund_reverting_paid_to_posted…            0 is not identical to '100.000'
3) …payments_pending_derived_from…              '100.000' vs expected '150.000'
4) …revenue_values_are_scale_3_strings…         100 is not of type string
5) …revenue_change_is_a_two_decimal_percent…    0 is not identical to '25.00'
```

**5 of 7 are genuinely red on old code.** The two overdue tests
(`…overdue_counts_only_posted_invoices_not_paid`, `…refund_revert_can_reenter_overdue_bucket`)
**pass on the old code** — correctly so, since ruling 2 is "deliberately do NOT change overdue".
They are regression guards, not red→green cases. The ticket's blanket
"Backend tests (red→green): … 7 new cases" (§ RESOLUTION) is **imprecise**; it should read
"5 red→green + 2 characterisation guards". Cosmetic, but this is a gate record.

Failure (1) also incidentally documents that the old endpoint emitted an **int** `100` for
`revenue.current`, not even a float — a further argument that the string retype was correct.

### E.3 — L2 — LOW/MED — missing bucket-guard tests for the query whose filter just changed

Only `Draft` is pinned as excluded (`:229-232`). **Not covered:**
- `Cancelled` invoice excluded from revenue — the obvious regression risk of a `whereIn` widening.
- `Confirmed` / `Received` excluded from revenue.
- **A posted CREDIT NOTE is not counted as revenue** — verified correct by inspection (A.2) and by
  live data, but unpinned. Given this was flagged as the highest-risk spot, it warrants a test.
- `previous == 0` → `change === '0.00'` (the division-by-zero branch at `:64-66` is exercised by
  `:349-368` but its output is never asserted).
- Negative `previousRevenue`.
- `paymentsPending` clamping to `'0.000'` at `:137-139`.
- A `paymentsPending` case with an actual `Payment` row reducing the base (the current test at
  `:325-343` has zero payments, which is why it silently encodes H1).

---

## Remaining carry-overs

### M4 — MEDIUM — three unbounded, model-hydrating plucks on a dashboard endpoint

`DashboardController.php:45-52`, `:54-61`, `:127-133`. Because `total` carries a `decimal:3` cast,
`Eloquent\Builder::pluck` does **not** take the cheap path — it hydrates a `Document` per row
(`Builder.php:1091-1100`). `:127-133` has **no date bound at all**: it is every invoice ever.

Measured on demo-pharmacy-tn (522 invoices, 221 payments): **283 ms / 580 ms / 587 ms** for three
consecutive calls, against the `< 1000 ms` assertion at
`BaselinePerformanceTest.php:118-124`. A tenant with tens of thousands of invoices will breach it.

Recommended (keeps exactness, drops both the hydration and the float):
`->selectRaw('COALESCE(SUM(total), 0)::text as t')->value('t')` — verified that
`pg_typeof(SUM(total))` is `numeric` on the tenant DB, which PDO/pgsql returns as an exact PHP
string. No `sum()` float, no row hydration.

### L3 — LOW — multi-currency mixing + hardcoded scale

`sumDecimalStrings` (`:177-190`) hardcodes scale `3` and the class injects only `CompanyContext`
(`:23-25`) — no `CurrencyScaleResolverInterface`, contrary to precision rule 19 (contrast
`ExpenseAnalyticsService.php:25,34`). More importantly it sums **across currencies**: the live
tenant holds **77 EUR + 445 TND invoices**, all added into one scale-3 number that the web renders
with the company currency. The bc-exactness framing gives false assurance about a figure that is
semantically mixed. Pre-existing; PHPStan level 8 is clean on the module, so not gate-blocking.

### L4 — LOW — refunds still do not reduce `payments.received`

`PaymentType::Refund::isIncoming()` is `false` (`PaymentType.php:83`), so the `whereIn` at `:119`
excludes them. Demo tenant has **32 completed refund payments totalling −14,900 TND dated this
month**, entirely absent from the reported `received: 65145.979`. For a lane whose stated premise
is "refunds move the dashboard the wrong way", this is arguably the larger remaining refund-KPI
defect. Pre-existing and outside these two commits' diff — flagging for the lane backlog.

### L6 — LOW — new unhandled-exception surface (currently unreachable)

`CurrencyScale::bcformatStrict` throws `InvalidArgumentException` on non-numeric input
(`CurrencyScale.php:130-145`). The revenue sums at `:45` and `:54` sit **outside** the
`try/catch (\Exception)` that wraps the payments block (`:106-142`), whereas the old
`sum('total')` could never throw. `documents.total` is `numeric(15,3) NULL` and nulls are skipped
(`:182-184`), so there is no reachable bad input today. Noted as a shape change only.

---

## Required actions before promotion

1. **H1** — re-derive `payments.pending` from `documents.balance_due` (drop the
   `all-time invoiced − one-month receipts` subtraction); rewrite
   `DashboardStatsTest.php:325-343` to assert the corrected value; amend ruling 3 in the ticket
   § RESOLUTION. **Blocking.**
2. **M1** — freeze the clock in `test_revenue_change_is_a_two_decimal_percent_string` and switch
   `DashboardController.php:35-36` to `subMonthNoOverflow()`. **Blocking (CI + a real month-end
   KPI defect).**

## Recommended, non-blocking
3. **M2** — align the zero/negative-previous guard with `ExpenseAnalyticsService` (`!== 0`, emit
   `null`) so the web can suppress a meaningless "0%" badge in a tenant's first month.
4. **M3** — add `erp-mobile/src/features/dashboard/__tests__/dashboardApi.test.ts:6,20,52-53` and
   `erp-mobile/src/lib/__tests__/format.test.ts:8` to the MOBILE FOLLOW-UP list; correct the
   JSDoc line range to `:3-10`; retract "no change needed" as a blanket statement.
5. **M4** — replace the three plucks with `selectRaw('SUM(total)::text')`.
6. **L1** — update the five e2e mocks to the string wire shape.
7. **L2** — add bucket guards: `Cancelled` out of revenue; posted **credit note** not counted as
   revenue; `previous == 0` → change value; `paymentsPending` clamp.
8. **L3/L4/L5/L6** — carry over as tickets (multi-currency + scale resolver; refunds vs
   `payments.received`; the coordination-doc claim; the throw surface).

**No merge, no push performed. Working tree left clean.**

---

# Re-check 423ea53e2

**Date:** 2026-08-03 · **Scope:** `423ea53e2` only (Taxation-module work by a concurrent agent
ignored) · **Mode:** read-only + independent re-probe of every claim.
**Stack:** api :8010, `demo-pharmacy-tn`, tenant DB `tenant019fbe86-944a-7252-8a3b-8c341dfa9de9`.

## VERDICT: **NOT-PROMOTABLE**

Both original blockers (H1, M1) are **genuinely fixed and independently verified**. M2, M4, L1 and
the documentation items are all correct. But the **L4 refund-netting fix introduces a new HIGH
defect** — it double-subtracts every FULL refund — and its test only exercises the partial-refund
shape, so nothing catches it. This is the same class of defect the whole lane exists to fix,
re-created in the opposite direction. One blocker, narrowly scoped, with a clean fix.

| Item | Claim | Independent verdict |
|---|---|---|
| **H1** pending = `SUM(balance_due)` Posted∪Paid | fixed | ✅ **VERIFIED** — SQL == API exactly |
| **M1** `subMonthNoOverflow` + frozen-clock test | fixed | ✅ **VERIFIED** — red-checked |
| **M2** `change: null` + FE em-dash | fixed | ✅ **VERIFIED** (⚠️ see N2 — mobile renders `"null %"`) |
| **L4** refund netting | fixed | ❌ **NEW BLOCKER N1 — double-nets full refunds** |
| **M4** `CAST(SUM(...) AS TEXT)` | fixed | ✅ **VERIFIED** — hydration eliminated |
| **L1** six e2e mocks | fixed | ✅ **VERIFIED** — incl. the 6th I missed |
| Pinning test rewritten, not deleted | claimed | ✅ **VERIFIED** |
| Coordination record + mobile follow-up | claimed | ✅ **VERIFIED** (one inaccuracy — N2) |

### Gate re-runs

| Check | Result |
|---|---|
| `phpunit tests/Feature/Dashboard/DashboardStatsTest.php` | **13/13 pass**, 36 assertions |
| `phpstan analyse app/Modules/Dashboard` (level 8) | **No errors** |
| `pnpm typecheck` (apps/web) | **clean** |
| `pnpm vitest run src/features/dashboard` | **10/10 pass** (7 dashboard, incl. the null case) |
| M1 red check (revert `subMonthNoOverflow` → `subMonth`) | **new boundary test goes red** |
| Live `GET /dashboard/stats` vs direct tenant-DB SQL | **exact match** on `pending` and `received` |

Working tree restored to clean after both experiments (`git status` verified). No merge, no push.

---

## N1 — HIGH — **BLOCKER (new)** — refund netting double-subtracts every FULL refund

`DashboardController.php:141` now admits `PaymentType::Refund` into the `paymentsReceived` sum:

```php
static fn (PaymentType $type): bool => $type->isIncoming() || $type === PaymentType::Refund
```

justified at `:133-140` by "refund `Payment` rows always carry a NEGATIVE `amount` by convention
… so including `PaymentType::Refund` … nets them automatically — no sign-flipping needed here."

**The sign-convention half of that claim is TRUE.** Verified in
`apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php`: every refund row is
written with a negated amount — `:164` (`refundPayment`), `:184` (allocation mirror), `:324`
(`partialRefund`), `:955` (pro-rata unwind), `:1197`/`:1221` (POS). No exception found.

**The netting half is FALSE for full refunds, because the original payment is also removed from
the sum.** `refundPayment()` marks the original `Reversed`:

```php
// PaymentRefundService.php:213-216
$original->update([
    'status' => PaymentStatus::Reversed,
    ...
]);
```

`paymentsReceived` filters `status = Completed` (`DashboardController.php:150`). So for a
same-month full refund of `X`:

| Row | Old code | New code | Correct |
|---|---|---|---|
| Original `document_payment` `+X` → now `Reversed` | excluded | excluded | excluded ✅ |
| Refund row `−X`, `Completed`, type `Refund` | excluded | **included** | must be excluded |
| **Net contribution** | **0** ✅ | **−X** ❌ | **0** |

The reversal is already expressed by the status flip; adding the negative row subtracts it a
second time. `partialRefund()` is fine — `:350-352` updates only `notes`, so the original stays
`Completed` and the negative row is the *only* expression of the refund. The fix therefore
happens to be correct for partial refunds and wrong for full ones.

### Live proof (demo-pharmacy-tn, this month)

```sql
select count(*) filter (where o.status='reversed')  as refunds_whose_original_is_reversed,
       sum(r.amount) filter (where o.status='reversed') as double_netted,
       count(*) filter (where o.status='completed') as partial_refunds,
       sum(r.amount) filter (where o.status='completed') as correctly_netted
from payments r join payments o on o.id = r.original_payment_id
where r.payment_type='refund' and r.status='completed'
  and r.payment_date >= date_trunc('month', now());
```

```
 refunds_whose_original_is_reversed | double_netted | partial_refunds | correctly_netted
------------------------------------+---------------+-----------------+------------------
                                 17 |    -12650.000 |              21 |        -3450.000
```

| Quantity | Value (TND) |
|---|---|
| Gross completed incoming this month (reversed originals already excluded) | 66,765.979 |
| Partial refunds — **correctly** netted | −3,450.000 |
| Full refunds — **double**-netted (original already excluded) | −12,650.000 |
| **API `payments.received` (probed)** | **50,665.979** |
| **Correct net received** | **63,315.979** |

Arithmetic closes exactly: `66,765.979 − 3,450.000 − 12,650.000 = 50,665.979`. The reported
figure is **understated by 12,650.000 — ~20 % of a headline launch tile**, and the error grows
with every full refund.

### Why no test caught it

`DashboardStatsTest.php:286-330` (`test_payments_received_nets_completed_refund_this_month`)
creates a `+200.000` `DocumentPayment` that stays `Completed` and a `−80.000` `Refund`, asserting
`'120.000'`. That is the **partial**-refund shape only. There is no case where the original is
`PaymentStatus::Reversed` alongside its negative refund row — i.e. no test for
`refundPayment()`'s actual output, which is the dominant path (17 of 38 refunds on the live
tenant).

### Required fix

Exclude refund rows whose original payment is no longer `Completed` — the reversal is already
accounted for by the original's exclusion. E.g. keep `Refund` in the type list but add
`whereNotExists`/`whereHas` on `original_payment_id` requiring the original to still be
`Completed`; or, equivalently, restrict the refund leg to rows whose original survives the same
status filter. Then add the missing test case: original `Reversed` + refund `−X` ⇒ `received`
contribution `0`.

*(Note: `payments.received` can also now go negative in a refund-heavy month. With N1 fixed that
is defensible as "net received"; flagging it only so the label/`0`-clamp question is a conscious
choice rather than a side effect.)*

---

## H1 — VERIFIED FIXED

`DashboardController.php:112-123` now computes
`sumColumnAsString(Document … whereIn(status,[Posted,Paid]), 'balance_due')`, moved **outside**
the `Payment`-class `try/catch` and with the `paymentsReceived` subtraction gone entirely.

**Their SQL cross-check confirmed independently.** My own query, run fresh:

```
select coalesce(sum(balance_due),0) from documents
where type='invoice' and status in ('posted','paid') and deleted_at is null;
→ 31018.322
```

API `payments.pending` = **`"31018.322"`** — exact match.

**Their explanation of the delta vs my earlier 27,842.672 is correct on both counts.** The
tenant DB is being written to concurrently: between my first-round probe and now, posted invoices
went 198 → 225 and posted invoice totals 57,307.441 → 63,711.671. My 27,842.672 was a snapshot of
older data, not a competing answer. Re-measured now, posted `balance_due` 20,478.322 + paid
10,540.000 = 31,018.322 ✅.

**Their refusal to filter the paid-with-balance rows is the right call.** Verified: **23** `paid`
invoices have `balance_due = total` (10,290.000), and 24 have `balance_due > 0` (10,540.000) — so
one further row carries a 250.000 partial residual. Filtering these (`AND balance_due < total`, or
excluding `Paid`) would paper over a data-integrity anomaly — a `Paid` invoice with an untouched
`balance_due` is a seeder/treasury-allocation bug, and a KPI query that hides it removes the only
place it is visible. Summing `balance_due` unconditionally is both the simpler rule and the
honest one. Correctly escalated as a separate data question rather than patched here.

**Tests:** the pinning test was **rewritten, not deleted** — renamed
`test_payments_pending_derived_from_posted_and_paid_invoice_base` →
`test_payments_pending_is_sum_of_balance_due_over_posted_and_paid_base`
(`DashboardStatsTest.php:341-364`) with explicit `balance_due` fixtures and the asserted value
corrected `'150.000'` → `'100.000'`. Plus a genuinely new case,
`test_payments_pending_excludes_settled_prior_month_paid_invoice` (`:375-390`), pinning a settled
prior-month `Paid` invoice at `0.000` — the exact scenario that produced the old ~2× inflation.
All 7 original cases survive (13 tests total via `--list-tests`); nothing was dropped.

---

## M1 — VERIFIED FIXED

`DashboardController.php:36-42` → `Carbon::now()->subMonthNoOverflow()` on both bounds, with the
overflow mechanism documented in-line.

**Red-checked, not taken on trust.** Reverting only those two calls to `subMonth()`:

```
1) …test_revenue_previous_month_window_does_not_overflow_at_month_end_boundary
Failed asserting that two strings are identical.
-'100.000'
+'125.000'
```

`revenue.previous` returns the *current* month's 125.000 — precisely the window collapse from the
first-round finding. Restored immediately; tree clean.

The new test (`DashboardStatsTest.php:459-481`) freezes at `2026-05-31 12:00:00` — the same
boundary as my original repro — and puts the previous-month invoice at an unambiguous
`2026-04-15` rather than a relative offset. `test_revenue_change_is_a_two_decimal_percent_string`
(`:428`) is now frozen at `2026-06-15`, removing the original date-dependence. A `tearDown()`
(`:36-43`) calls `Carbon::setTestNow()` so no frozen clock leaks into sibling tests — a
correctness detail that is easy to omit and wasn't.

---

## M2 — VERIFIED FIXED (with one documentation inaccuracy, N2)

**Backend** `DashboardController.php:65-77`: `$revenueChange = null` by default; the `bccomp(…) > 0`
guard is retained, so `previous <= 0` (zero *or* negative) yields `null`. This is a **stricter**
cutoff than `ExpenseAnalyticsService`'s `!== 0` — deliberate and documented at `:69-71`, and the
better choice: `ExpenseAnalytics`'s `!== 0` would compute a delta against a negative baseline,
whose sign is meaningless.

**Frontend** `Dashboard.tsx:165-176`, `:252-269`: a three-way `undefined` / `null` / string split.
`undefined` (no data yet) renders nothing; `null` renders `colorTokens.text.subtle` + **no arrow
icon** + `—`; a string keeps the existing big.js sign/`formatPercent` path. The `typeof
revenueChange === 'string'` guard at `:167` means `new Big()` can never receive `null`. Correct.

**Test** `dashboard.test.tsx:173-202` asserts both the `—` text *and* the absence of
`.lucide-trending-up` / `.lucide-trending-down` — it pins the arrow suppression, not just the
glyph. Backend equivalents at `DashboardStatsTest.php:490-506` (previous = 0) and `:515-539`
(previous **negative**) both assert `null`.

### N2 — MEDIUM — deployed mobile renders the literal string `"null %"` on a first-month tenant

The M2 ruling adds a value the deployed mobile client has never seen. Simulating
`erp-mobile/src/lib/format.ts:20-23` and `app/(app)/index.tsx:58` verbatim:

```
null      -> "null %"     | changePositive= true
"25.00"   -> "+25,00 %"   | changePositive= true
"-10.50"  -> "-10,50 %"   | changePositive= false
```

`String(null)` → `"null"`, and `(null ?? 0) >= 0` → `true`, so the deployed app shows a **green ↑
"null %"** — for exactly the brand-new-tenant first month that M2 exists to serve. Not a crash,
but a visible garbage string, and a **regression** on the prior `"0 %"`.

This makes two statements inaccurate:
- `docs/superpowers/coordination/2026-08-03-dashboard-stats-api-shape-change.md` § "Who is
  affected": "**Runtime impact on the currently-deployed mobile app**: verified non-breaking (no
  `TypeError`, no white screen)". Literally true, but it hedges the null case only as a
  *future* risk ("or crash on a `null` `change` it doesn't expect") — it is a *present*,
  user-visible defect, and it is not a crash.
- The first-round runtime walkthrough (§ D.1 above) predates the null ruling and no longer covers
  the full value domain.

**Not a blocker on its own** — but it changes the sequencing conclusion from § D.1: the mobile
follow-up is no longer purely a type-honesty cleanup. Either land the mobile null-guard
(follow-up items 2-4) alongside promotion, or accept `"null %"` on the mobile dashboard tab for
any tenant without previous-month revenue. Both docs should be corrected to say so.

---

## M4 — VERIFIED FIXED

New `sumColumnAsString()` (`DashboardController.php:209-227`) issues
`CAST(COALESCE(SUM({$column}), 0) AS TEXT) as total_sum` via `selectRaw(…)->first()`. Checks:

- **No float:** PG `SUM(numeric)` is exact; `CAST … AS TEXT` never yields scientific notation for
  `numeric`. Result goes straight into `CurrencyScale::bcformatStrict(…, 3)` at `:225`.
- **No hydration:** the first-round finding was that `pluck()` on a `decimal:3`-cast column takes
  Eloquent's *expensive* path (`Builder.php:1091-1100`, one model per row). A single aggregate row
  replaces it. `Document` declares no `$with`/`$appends` (verified), so `first()` triggers no
  eager loads.
- **Injection:** `$column` is a compile-time literal (`'total'`, `'balance_due'`) from within the
  class; never request-derived. Documented at `:216-217`.
- **Null-safety:** `COALESCE(…, 0)` plus `?? '0'` at `:225` — an aggregate with no `GROUP BY`
  always returns one row, so `$result` cannot be null in practice; belt-and-braces is fine.
- The `first()`-over-`value()` choice is explained at `:213-215` (`value()`'s column parameter is
  typed against real model properties; `total_sum` is a raw alias). PHPStan level 8 clean.
- Pattern precedent (`GeneralLedgerService::availableAdvanceCredit()`) cited — consistent.

Endpoint timing on the live tenant is 0.27-0.89 s, unchanged within noise — expected, since the
data set is small and latency is dominated by the other queries. The win is structural (the
all-time `balance_due` scan no longer scales with row count in PHP memory), not visible at this
size. `paymentsReceived` still uses `pluck()` + `sumDecimalStrings()` (`:146-155`) — inconsistent
with the new helper but harmless (`payments.amount` sums are month-scoped); worth folding in when
N1 is fixed, since that query is being touched anyway.

---

## L1 — VERIFIED FIXED, including the 6th mock

All six now carry the string wire shape (`revenue: { current: '15000.000', previous: '12000.000',
change: '25.00' }`, `payments: { received: '35000.000', pending: '8000.000' }`):

`e2e/fixtures.ts:81`, `e2e/fixtures.ts:380`, `e2e/auth.spec.ts:162`, `e2e/company.spec.ts:72`,
`e2e/company.spec.ts:189`, **`e2e/company.spec.ts:415`**.

**The 6th is real and my first round genuinely missed it.** `company.spec.ts:406` routes
`'**/api/v1/dashboard/**'` — a wildcard, not `/dashboard/stats` — so my
`grep -rn "dashboard/stats"` could not have found it. Their catch, correctly credited.

---

## Documentation — VERIFIED

**`docs/superpowers/coordination/2026-08-03-dashboard-stats-api-shape-change.md`** exists and is
accurate: before/after table per field (including `change`'s `string | null`), the explicit note
that `payments.*` are unchanged, the three bucket-definition changes, the affected-consumers list,
and an honest "not yet promoted to `origin/dev`" status. Correctly scoped as the short-form
contract with the ticket as the detailed punch list. Only defect is the runtime-impact wording in
N2.

**Ticket § MOBILE FOLLOW-UP** — every M3/D.2/D.3 correction landed and is accurate against the
mobile repo (re-verified read-only):

| Correction | Verdict |
|---|---|
| New item **1a** — `dashboardApi.test.ts:6`, `:20`, `:52-53` | ✅ my omission, now listed with the exact `typeof … 'number'` assertions |
| New item **3a** — `format.test.ts:8` | ✅ listed |
| JSDoc range `:3-8` → **`:3-10`** | ✅ corrected |
| Item 5 "no change needed" → "Partially unchanged, NOT a blanket …" | ✅ retracted with reasoning |
| L5 "no convention exists" retracted; `docs/superpowers/coordination/` named | ✅ corrected, precedent doc cited |
| `change` typed `string \| null` in items 1/2/3/4 | ✅ propagated, incl. the null-guard-at-call-site instruction |

Carry-overs L3 (multi-currency + scale resolver) and "revenue is gross of credit notes" are
recorded in the ticket as explicitly-not-implemented, with the product rulings they need — the
correct disposition. L2 (bucket-guard tests for `Cancelled` / posted credit note / pending clamp)
remains open and is correctly distinguished from the credit-note carry-over.

---

## Disposition

**One blocker: N1.** Everything else in this round is correct and independently verified — H1 and
M1 are properly fixed (both red-checked or SQL-confirmed), the pinning test was rewritten rather
than deleted, and the documentation corrections are complete and honest.

**Required before promotion**
1. **N1** — stop double-subtracting full refunds: exclude refund rows whose original payment is
   no longer `Completed`, and add the missing test (original `Reversed` + refund `−X` ⇒ `0`
   contribution). Currently understating `payments.received` by 12,650.000 (~20 %) on the live
   tenant.

**Should accompany promotion**
2. **N2** — correct the "verified non-breaking" wording in the coordination doc and § D.1, and
   decide the sequencing: ship the mobile null-guard with this, or knowingly accept `"null %"` on
   the deployed mobile dashboard for first-month tenants.

**Carry over** — L2 bucket-guard tests; L3 multi-currency + scale resolver; revenue gross of
credit notes; the `paid`-with-`balance_due` data anomaly (already ticketed separately); folding
`paymentsReceived` onto `sumColumnAsString`; the `received`-can-go-negative labelling question.

**No merge, no push performed. Working tree left clean.**

---

# Final confirm

**Date:** 2026-08-03 · **Scope:** erp `e11afb740` + erp-mobile `fix/dashboard-stats-string-shape`
@ `2c25da7` (Taxation-agent files ignored) · **Mode:** read-only, own probes and own revert.

## VERDICT: **PROMOTABLE**

N1 is fixed exactly as prescribed and the fix is proven three ways: the `whereExists` subquery
(`DashboardController.php:156-168`) admits a `Refund` row only when its `original_payment_id`
resolves to a payment still `Completed`, so `partialRefund()`'s shape (original untouched at
`PaymentRefundService.php:350-352`) still nets while `refundPayment()`'s shape (original flipped
to `Reversed` at `:213-216`) contributes exactly zero — no `payment_type` heuristic, an explicit
join on the linking column; my own revert probe (collapsing it back to the unconditional
`whereIn(... Refund)`) drives both new tests red with precisely the double-netted deltas
(`'300.000'`→`'100.000'`, `'1350.000'`→`'1050.000'`), and the live tenant now reports
`payments.received = 63315.979`, an exact match to `66765.979 gross − 3450.000 partials`, with the
`−12650.000` of full refunds correctly excluded; the two new tests pin both shapes independently
plus a combined case, and the pre-existing L4 test was repaired rather than dropped (it never set
`original_payment_id`, which is why it never exercised the defect). The mobile branch closes N2 —
simulated verbatim against a first-month payload, `change: null` renders `'—'` with **no** arrow
and a muted tone (never `"null %"`, never a green ↑), the sign check is a string `startsWith('-')`
rather than numeric coercion, `typecheck` is clean and both touched suites pass 17/17 with the old
`typeof … 'number'` contract assertions inverted and a null-passthrough case added; the branch is
correctly local-only (not pushed, no remote ref). Both docs are now accurate: the coordination
record retracts its "verified non-breaking" blanket claim with the exact `String(null)` reasoning
and names the prepared mobile commit, and the ticket's SECOND AMENDMENT marks the superseded L4
netting and records the N1 ruling with the live arithmetic. **Promote the ERP change; the mobile
commit still needs the owner's push/release, and until it ships the deployed app shows `"null %"`
for first-month tenants — that is now documented rather than unknown.**

## Verification runs

| Check | Result |
|---|---|
| `phpunit tests/Feature/Dashboard/DashboardStatsTest.php` | **15/15**, 40 assertions |
| `phpstan analyse app/Modules/Dashboard` (level 8) | **No errors** |
| N1 red check (own revert to unconditional `Refund` inclusion) | **both new tests red**, correct deltas |
| Live `GET /dashboard/stats` vs direct SQL | **`received = 63315.979`** — exact |
| erp-mobile `npm run typecheck` | **clean** |
| erp-mobile `jest` (2 touched suites) | **17/17** |
| First-month payload simulated against branch code | `{icon: NONE, tone: muted, text: "—"}` |
| erp-mobile branch pushed? | **no remote ref** — local only, as stated |

Working tree restored after the revert probe; only the concurrent Taxation agent's files remain
modified. No merge, no push.

### Detail — N1 shape

```php
// DashboardController.php:156-168
->where(function (Builder $query) use ($incomingPaymentTypes, $companyId): void {
    $query->whereIn('payment_type', $incomingPaymentTypes)
        ->orWhere(function (Builder $refundQuery) use ($companyId): void {
            $refundQuery->where('payment_type', PaymentType::Refund->value)
                ->whereExists(function (QueryBuilder $originalQuery) use ($companyId): void {
                    $originalQuery->selectRaw('1')->from('payments as original_payment')
                        ->whereColumn('original_payment.id', 'payments.original_payment_id')
                        ->where('original_payment.company_id', $companyId)
                        ->where('original_payment.status', PaymentStatus::Completed->value);
                });
        });
})
```

Matches the prescription. `Refund` was removed from the type-list predicate (`:148-153`) so it can
enter only through the guarded branch. The subquery is company-scoped as well as status-scoped, so
a cross-company `original_payment_id` cannot rescue a refund row.

**Orphan-refund edge (checked, non-issue):** the `EXISTS` also drops any refund with a null or
dangling `original_payment_id`. Every refund-writing path sets it —
`PaymentRefundService.php:170` (full), `:330` (partial), `:1229` (POS) — and the live tenant has
**0** such rows. Noting it only so a future standalone-refund feature knows it must revisit this
predicate.

**Live arithmetic (demo-pharmacy-tn, current month):**

| Component | Value (TND) |
|---|---|
| Gross Completed non-refund incoming | 66,765.979 |
| 21 partial refunds (original still `completed`) — **netted** | −3,450.000 |
| 17 full refunds (original `reversed`) — **excluded** | (−12,650.000 not applied) |
| **Expected** | **63,315.979** |
| **API `payments.received`** | **`63315.979`** ✅ |

`payments.pending` remains `31018.322`, still an exact match to `SUM(balance_due)` over Posted∪Paid.

### Detail — N2 mobile, simulated verbatim from `2c25da7`

| `revenue.change` | icon | tone | text |
|---|---|---|---|
| `null` (first-month tenant) | **NONE** | muted | **`—`** |
| `"25.00"` | TrendingUp | success | `+25,00 %` |
| `"-10.50"` | TrendingDown | danger | `-10,50 %` |
| `"0.00"` | TrendingUp | success | `0,00 %` |

`formatPercent` (`src/lib/format.ts:20-38`) returns `'—'` on `null` before any string work;
`index.tsx:58-65` derives `hasRevenueChange`/`changePositive` from explicit null checks and
`startsWith('-')`, and gates the icon on `hasRevenueChange`. `dashboardApi.ts:11-27` types
`current`/`previous` as `string` and `change` as `string | null` with the stale JSDoc rewritten.

**One cosmetic cross-client divergence (not blocking, not new to this round):** the backend's
`CurrencyScale::bcround` can emit `"-0.00"` for a tiny negative ratio. Web normalises it via
big.js (TrendingUp, `0%`); mobile's `startsWith('-')` yields TrendingDown + `-0,00 %`. Harmless,
sub-0.005 % magnitude; worth a line in the mobile follow-up if anyone touches that helper again.

### Carry-overs (unchanged, all correctly recorded as not-implemented)

L2 bucket-guard tests (`Cancelled` / posted credit note / pending clamp); L3 multi-currency mixing
+ scale resolver; revenue gross of credit notes; the `paid`-with-`balance_due` data anomaly
(separately ticketed); folding `paymentsReceived` onto `sumColumnAsString()`; the
`received`-can-go-negative labelling question.

**No merge, no push performed.**
