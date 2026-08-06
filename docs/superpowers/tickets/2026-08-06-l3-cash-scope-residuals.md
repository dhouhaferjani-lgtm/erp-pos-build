# Ticket: location-scope residuals surfaced by the L3 multi-branch cash lane

**Filed by:** the L3 multi-branch cash-visibility fix lane, at the request of both merge gates
(`docs/superpowers/reviews/2026-08-06-l3-authz-gate.md` — CHANGES-REQUESTED, and
`docs/superpowers/reviews/2026-08-06-l3-web-gate.md` — APPROVE-WITH-FIXES).
**Date:** 2026-08-06 · **Branch that raised it:** `fix/l3-multibranch-cash`
**Source defect:** W-7 F-3 (`docs/superpowers/tickets/2026-08-03-w7-cross-cutting-findings.md`).

L3 implemented `location_ids[]` scoping for `/reports/cash-movements` end-to-end. Six things the
gates proved along the way are **out of that lane's scope** and are collected here so none of them
is rediscovered by a later campaign wave. None blocks tenant #1's launch; (a) is the one with an
authorization flavour and should be looked at first.

---

## (a) [P1] `LocationScopeBoundary::isUnrestricted()` is unsound when a location is DEACTIVATED

**Where:** `apps/api/app/Modules/Company/Services/LocationScopeBoundary.php:24-27`, reached from
`ReportsController::reportLocationScope()` (`:864-873`).

`isUnrestricted()` compares the principal's effective grant against **`is_active = true`**
locations only, but the scope it collapses to is `[]` — which applies **no predicate at all**. So a
user granted only Shop A, on a company whose Shop B has been deactivated, is treated as
unrestricted and reads Shop B's cash.

The authz reviewer's probe F, live:

```
[PROBE F] clampedRead status=200 count=2 totals={"EUR":{"in":"109.00",...}}   # A=10.00 + B=99.00
[PROBE F] explicit inactive-B status=403
```

The same principal is **403'd** when naming Shop B explicitly and is **served Shop B's 99.00** on
the implicit read — the two paths disagree.

**Pre-existing** in the shared helper (aged-AR/AP, upcoming-payments and `CashPosition` all route
through it), but L3 newly routes **cash** through it, which is why it is written down rather than
silently inherited.

**Fix shape (two options, pick one for the family):** either compare against *all* company
locations, matching `LocationScopeResolver::allCompanyLocationIds()` (`:72-80`); or have
`reportLocationScope()` keep the explicit id list instead of returning `[]`, and add `orWhereNull`
the way `CashPositionController.php:89-93` and `MaturingInstrumentsController.php:64-66` already do.

## (b) [P2] `agedReceivables` / `agedPayables` / `upcomingPayments` turn a 403 into a 500 — and leak the authz message

**Where:** `apps/api/app/Modules/Accounting/Presentation/Controllers/ReportsController.php:746/761`,
`:799/814`, `:837/846`.

`reportLocationScope()` is called **inside** `try { … } catch (\Exception $e)`, and
`AuthorizationException extends \Exception`. Probe G, live:

```
aged-receivables=500 body={"error":{"code":"REPORT_GENERATION_ERROR",
  "message":"Failed to generate aged receivables report: Requested location is outside your allowed scope."}}
aged-payables=500   upcoming-payments=500
```

Two defects in one: an authorization refusal is reported as a server error, and the authz message
is echoed in the 500 body. `cashMovements` is the only one of the four that gets this right — L3
deliberately placed its `reportLocationScope()` call outside the catch — so the fix is to move the
other three the same way **and** strip the exception message from the generic 500.

## (c) [P2] `journal_entries.location_id` is a reserved, never-written column

**Where:** created by `database/migrations/tenant/2025_12_27_150002_add_location_id_to_journal_entries_table.php`;
zero occurrences of `location_id` in `app/Modules/Accounting/Domain/JournalEntry.php`; no write site
anywhere in the codebase.

Because the column is dead, L3's journal-lines leg attributes a cash line to a branch through the
**owning register** (`payment_repositories.location_id`) instead — with a fail-closed guard so a GL
account shared by several branches' registers yields *unattributed* cash rather than per-branch
duplicates. That is correct for launch but is an indirection.

**Fix shape:** populate `journal_entries.location_id` at the posting sites
(`GeneralLedgerService`, `AccountingService`, `AccountingOpeningService`,
`JournalEntryController`), then scope the leg on it directly and drop the repository indirection
together with its fail-closed clause. Until that lands the column must be documented as *reserved*,
so a future reader does not assume it is authoritative (the service docblock now says so).

## (d) [P2] `/reports/cash-movements` never names the cash it withheld

Under a strict branch scope the report silently omits cash that no single branch owns: a pure
advance (`payments.location_id` NULL by design, `PaymentController.php:596-598`), a manual journal
entry on a location-less safe, and — after L3's fail-closed guard — any journal line on a cash GL
account shared across branches. Hiding it is the established convention
(`ReportsController.php:856-860`) and every sibling surface behaves the same way, but the siblings
**name** the residue: `CashPositionController.php:139-146` emits an `unattributed` group and
`MaturingInstrumentsController.php:137-145` an `unattributed` key. Cash-movements just omits it —
on the surface a branch manager reconciles a drawer against.

**Fix shape:** add an `unattributed` count/total to `meta` (populated in both modes) so a
strict-scope reader can see that something was withheld, and have the FE render a
"company-level cash is not included in this branch view" note. Until then, Σ over the branch views
legitimately falls short of the company view and nothing on screen explains why.

## (e) [MINOR] Offset pagination is not reset when the view scope changes

**Where:** `apps/web/src/features/finance/pages/CashMovementsReportPage.tsx:56` holds `page`;
`resetPage()` (`:74-76`) is wired to the date range (`:191-198`), the repository select (`:209-212`)
and the direction select (`:230-233`) — but a view-scope change never touches it, and `page` rides
into the query key.

**Co-affected siblings (verified by the web gate):** `apps/web/src/features/inventory/pages/StockByLocationPage.tsx:20-22`
and `apps/web/src/features/inventory/components/ProductMovementsTab.tsx:106`. It is **not** shared
with `useExpenses` — `ExpenseFilters` (`features/expenses/types/index.ts:301-309`) has no `page` and
`ExpenseListPage.tsx:42-46` keeps no page state, so that surface is not offset-paginated. An earlier
draft of this residual mis-attributed it there.

**Bounded impact:** `meta.totals` is computed over the whole filtered set
(`CashMovementsReportService.php:118-150`), so the totals panel stays correct under the new scope;
only the table falls empty with `OffsetPagination` rendering "page N of 1". Confusing, not a money
misstatement.

**Fix shape:** one shared helper applied to all three surfaces, rather than three one-off resets.

## (f) [MINOR] Three pre-existing red web tests need an owner

Red on this branch's base (`b54f45160`) **and** on the `dev` tip, untouched by L3, left alone under
rule 4:

1. `apps/web/src/features/finance/api.test.ts:31` — expects `/reports/upcoming-payments?days=45`,
   actual `…&group_by=location`.
2. `apps/web/src/features/finance/hooks/__tests__/tenantScope.test.tsx:207` — expects the
   **pre-`locationScopedKey`** aged-payables key shape.
3. `apps/web/src/hooks/__tests__/usePermissions.expenseRecurrences.test.ts:9` —
   `expense-recurrences.view` role-set drift.

**(1) and (2) are the stale expectations of the aged-* / upcoming-payments location-scoping work
and need an owner**: they are the regression guard for the very convention L3 extends, so
cash-movements should be added to that suite once they are repaired. (3) is unrelated and
unclaimed.
