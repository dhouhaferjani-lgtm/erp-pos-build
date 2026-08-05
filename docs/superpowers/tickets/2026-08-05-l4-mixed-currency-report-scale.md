# Ticket: unify owner-report money emission — mixed-currency scale, and three emission conventions on one dashboard

**Filed by:** the L4 currency-blind-emission fix lane, at the request of the API/precision merge gate
(`docs/superpowers/reviews/2026-08-05-l4-api-precision-gate.md`, verdict APPROVE-WITH-FIXES).
**Date:** 2026-08-05 · **Branch that raised it:** `fix/l4-currency-emission`
**Severity:** P2 — **not launch-blocking for tenant #1** (a single TND company), but it is the *permanent contract*
for the owner-report family and it relocates the very defect L4 was chartered to remove.

L4 fixed *how* report numbers are rendered (currency scale, no float, no `rtrim`). It did **not** settle *whose*
currency a multi-company report renders in, and it did not reach every emitter in the directory. This ticket carries
the three residuals the gate recorded, because they are one decision, not three.

---

## Finding 1 — the lane picked the weaker of two in-repo patterns, on the same dashboard

Two services in `app/Modules/Accounting/Application/Services/Reports/` now answer "what scale?" differently:

| Service | Answer | Mixed-currency scope |
|---|---|---|
| `OwnerSalesSummaryService` (`:39-40`, `:80-87`) | derives the scale from the **data** — `resolveCurrency($companyIds)` → `getScale($currency)` | **REFUSES**: `throw new AuthorizationException('Owner reporting cannot aggregate across companies with different currencies.')` |
| `SalesReportService::moneyScale()`, `CashRegisterReportService`, `TrialBalanceService::emissionScale()` (L4) | takes the scale from `CompanyContext` via `getScaleSafe()` | **renders silently, at the ROOT company's scale** |

Live consequence on one `/finance` viewport: `GET /reports/sales/summary` **403s** a mixed-currency parent/child
scope while `GET /reports/sales/by-location` and `GET /reports/cash-register/reconciliation` return cross-currency
sums rendered at the root's scale.

**Why it is not cosmetic.** `ReportsController::cashRegisterReconciliation` calls
`OwnerReportScope::companyIds(null, $user)` = root + **every** child (`OwnerReportScope.php:28-41`), and the endpoint
offers no way to filter a foreign-currency child out. So a **TND child under a EUR root has its Z/EOD cash variance
rendered at 2 dp** — the millime loss W-7 F-2 exists to kill, moved rather than removed. W-8 proved a single tenant
can hold both a TND and a EUR company, so this is reachable, not hypothetical.

Note the underlying cross-currency `SUM` is **already meaningless before any formatting** (pre-existing, not caused
by L4). That is an argument for the *refuse* pattern, not for the silent-render one.

## Finding 2 — `getScaleSafe()`'s fallback is dead code that would fail quietly if it ever woke up

The only consumer of all four services is `ReportsController` (`:85-95`), and every action resolves
`CompanyContext::requireCompanyId()` **before** calling them (`:106`, `:169`, `:184`, `:317`). No queued job, no
console command, no scheduler entry. So the `fallback = 3` branch is unreachable today — and if a queued/console
caller is ever added, it would render a **EUR** tenant at 3 dp instead of failing loudly.

Rule 20's `getScaleSafe` carve-out exists for queued/console code. These are request-only paths, where `getScale()`
is the correct call *precisely because it throws*.

> The lane's original docblocks justified `getScaleSafe()` with "also reachable from console contexts". The gate
> proved that false; the comments were corrected in the same lane to state the real limitation. Only the code
> choice remains open, and it belongs with the Finding 1 ruling rather than on its own.

## Finding 3 — three emission conventions in one owner-dashboard viewport

| Emitter | Convention |
|---|---|
| `FormatsReportNumbers::decimalString` (L4) | `CurrencyScale::bcround` — **rounds** half away from zero, at the currency scale |
| `OwnerSalesSummaryService` (`:57-59`, `:65`) | `CurrencyScale::bcformatStrict` — **truncates**, at the currency scale |
| `LiveSalesReportService` (`:82`) | `total: (string) $row->total` — **unscaled and currency-blind**, i.e. the exact W-7 F-2 shape |

`LiveSalesReportService` was not in F-2's blast-radius list, so leaving it is not an L4 failure — but the plan's
"one contract fix" (§2 L4) is **not yet true** for this directory, and the residual should not be rediscovered by a
later campaign wave. The rounding-vs-truncation split is the more interesting half: per the Q4 ruling, *rounding* is
correct at a presentation boundary (it matches the POS device), so `OwnerSalesSummaryService`'s truncation is the
side that should move.

---

## Proposed resolution (needs a ruling before implementation)

**Decide one rule for the family**, then apply it everywhere:

- **(a) Refuse** a mixed-currency scope — extend `OwnerSalesSummaryService`'s guard into a shared concern used by
  every owner-scope report. Consistent and loud; costs a 403 on a scope that produces meaningless sums anyway.
- **(b) Per-row currency** where the row *is* per-company, and refuse only where it genuinely aggregates across
  companies. Cheapest correct answer for two of the three surfaces:
  - `SalesReportService::salesByLocation` **already joins `companies`** (`:51`) — add `companies.currency` to the
    select and call `getScale($row->currency)`;
  - `CashRegisterReportService::reconciliationSummary` has `pos_terminals.company_id` in hand (`:35-38`) — join
    `companies` for the currency; rows are per shift, so no GROUP BY change;
  - `topSkus` / `revenueByCategory` / `paymentMethodBreakdown` aggregate **across** companies, so a per-row currency
    is impossible — these need the (a) refusal.

Whichever wins:

1. Replace `getScaleSafe()` with `getScale()` (or an explicit per-row currency) on the three L4 services, so an
   unbound context fails loudly instead of silently choosing 3.
2. Move `OwnerSalesSummaryService` onto `bcround` so the dashboard stops carrying two rounding modes.
3. Scale `LiveSalesReportService.php:82` — it currently emits raw, unscaled money.
4. Update `docs/architecture/precision-contract.md` § Emission & display with the ruling.

## Evidence / references

- Gate record: `docs/superpowers/reviews/2026-08-05-l4-api-precision-gate.md` (Important findings 1, 2, 5; Q5 ruling:
  *"acceptable for tenant #1, NOT acceptable as the permanent contract"*).
- L4 lane spec: `docs/handoff/PLAN-p0-fix-lanes-pre-production-2026-08-05.md` §2 L4.
- Source tickets: `docs/superpowers/tickets/2026-08-03-w7-cross-cutting-findings.md` (F-2),
  `docs/superpowers/tickets/2026-08-03-w8-isolation-findings.md` (F-3).
- In-code pointers back to this ticket: `SalesReportService::moneyScale()`,
  `CashRegisterReportService::reconciliationSummary()`, `TrialBalanceService::emissionScale()`.

## Explicitly NOT in this ticket

- `TrialBalanceService` is **single-company by construction** — `ReportsController.php:317` passes
  `CompanyContext::requireCompanyId()` as the report's own `$companyId`, so scale and subject cannot disagree there.
  It is listed above only for the `getScaleSafe()` → `getScale()` sweep.
- The server-vs-device **fiscal-hash** canonicalization divergence (`ZReportHashService.php:114` truncates;
  `apps/pos/src/lib/decimal.ts` rounds) — a live fiscal issue, belongs to the **L1 fiscal lane**, not here.
- The trial balance's `lines[]` (net per account) never footing to its `total_debit`/`total_credit` (gross sums) —
  pre-existing, unchanged by L4, outside this ticket.
