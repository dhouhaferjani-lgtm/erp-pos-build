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
