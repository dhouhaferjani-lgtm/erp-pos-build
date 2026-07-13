# Treasury Phase 4 — GATE 3 adversarial review (rc1)

**Scope:** Wave 3, Tasks 13–15 per CODEX brief §3. **Diff:** `phase4-gate-2..HEAD` (`6ed0ebb0a..096d644fb`).

## Port / money-path safety gates — all PASS

- TreasuryMovementService is byte-untouched; the origin/dev diff is empty.
- W3 is read-only: no GeneralLedgerService, ExpenseService/settlement, or instrument-service edits.
- No CompanyContext-read regression; HTTP surfaces use `requireCompanyId()`.
- No float, literal bcmath scale, or no-argument scale use; currency scale is explicit and aggregate SUMs are text-normalized.

## Task 13 (analytics) — VERIFIED

Every sub-aggregate is tenant/company-scoped, including category and partner joins. Two-company and mismatched-tenant leak tests are green. The cross-company partner disclosure finding is repaired by deriving partner identity from the scoped join. Aggregates use one GROUP BY query each; the composite index was added only after confirming no equivalent existed. The zero-total share guard, empty-window zeros/null, equal-length MoM calculation, inclusive date boundaries, posted default, explicit `all` sentinel, snake_case serialization, route order, and deny-path are verified.

## Task 14 (export) — VERIFIED

`ExpenseIndexQuery` is shared by index and export. Filters preserve inclusive `date_to`; the response streams a BOM, header, and all cursor rows (45-row fixture despite pagination), excludes sibling-company/non-expense rows, suppresses cross-company partner identity, preserves legacy nulls/raw decimal strings, and is permission-gated.

## Task 15 (FE) — VERIFIED

Tiles pass only status/category/date filters and show the search-excluded caption. Explicit All maps to `status: 'all'`. Export uses the authenticated blob pattern and permission gate. The analytics page uses the required atoms/tokens/DataTable/DateRangeFilter, RTL-safe matrix, `big.js`, legacy-net caption, accessible `aria-live` loading and `QueryError` retry. EN/FR/AR keys are complete; analytics keys are tenant-scoped; expense and category mutations invalidate only matching tenant/company analytics caches. Route ordering and permission gating are correct.

## Permissions — VERIFIED

The BE seeder and FE permission map agree for `expenses.export` and `expense-recurrences.*` grants.

## Findings (all non-blocking)

1. **LOW:** The list defaults to posted, intentionally hiding drafts on first load for parity.
2. **LOW:** The dedicated analytics page selector does not expose an All option, although the endpoint/list support it.
3. **INFO:** Category rename invalidates analytics/categories but not the expense list; this is pre-existing and non-money-affecting.
4. **INFO:** CSV filename parsing depends on exposed `Content-Disposition`, with a graceful fallback.
5. **INFO:** MoM uses the equal-length prior period as specified; the UI label avoids ambiguity.

**VERDICT: APPROVE**
