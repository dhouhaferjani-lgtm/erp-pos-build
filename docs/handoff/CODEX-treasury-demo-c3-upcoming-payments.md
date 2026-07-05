# C3 — Upcoming Payments Endpoint (forward-looking AR/AP)

> Chunk C3 of the treasury demo plan (`docs/superpowers/audits/2026-07-02-treasury-demo-gap-audit-and-plan.md`). Backend (Accounting module). TDD mandatory: failing PHPUnit test first. Never run the full suite — test files by path only.

## Problem

Aged Receivables/Payables (`AgedReceivablesService` / `AgedPayablesService`, `app/Modules/Accounting/Application/Services/Reports/`) bucket **backward** (overdue: current/30/60/90+). There is NO forward-looking "what's due in the next N days, money-in vs money-out" query — the key demo surface ("upcoming payments in or out"). All data exists: `documents.due_date` (nullable date), `documents.balance_due` decimal(15,2), `type` column (invoice / supplier invoice / expense per `Document\Domain\Enums\DocumentType`), status Posted.

## Task

`GET /api/v1/reports/upcoming-payments?days=N` (default 30, validate 1..365) in the Accounting module:

1. **Service** `UpcomingPaymentsService` next to the Aged* services, reusing their query skeleton (posted documents, `balance_due > 0`, company-scoped). Two directions:
   - **IN**: customer invoices (`DocumentType::Invoice`) — expected receipts.
   - **OUT**: supplier invoices + unpaid expenses — expected payments.
   For each open document: partner name, document number, type, due_date, balance_due (string), days_until_due (negative = overdue), overdue flag. Sort by due_date ascending; include already-overdue items (they belong at the top of an "upcoming" list). Totals per direction: `total_in`, `total_out`, `net` (bcmath, scale from currency resolver — constructor-inject `CurrencyScaleResolverInterface`, pass entity currency, never no-arg `getScale()`).
   Documents with NULL due_date: fall back to `document_date` (same convention as `AgedReceivablesService` ~L91-141).
2. **DTOs** under `.../DTOs/Reports/` (follow `ProfitLossData` style — no `mixed`). After adding DTOs run `php artisan typescript:transform` (worktree gotcha: needs `CACHE_STORE=array`) and commit the generated types in `packages/shared/types/`.
3. **Controller + route**: method on the existing `ReportsController`, route registered in `app/Modules/Accounting/Presentation/routes.php` beside `/reports/aged-receivables` with the SAME middleware + `can:reports.view`.
4. **FormRequest** for the `days` param (integer rule; no money params here).

## Tests (write first)

Feature test `tests/Feature/Accounting/Reports/UpcomingPaymentsTest.php`:
- invoices due within window appear in IN with correct days_until_due and string amounts;
- supplier invoice + unpaid expense appear in OUT;
- overdue invoice included and flagged;
- paid document (`balance_due = 0`) excluded;
- document due beyond N days excluded;
- null due_date falls back to document_date;
- totals are exact bc sums;
- 403 without `reports.view`;
- company isolation (doc from another company invisible).
Use `RefreshDatabase` + real models + `RolesAndPermissionsSeeder`, valid UUIDs on all FKs (project testing conventions).

## Constraints

- Hexagonal: service in Application, DTOs typed, controller thin. Constructor injection ONLY (`private readonly`), never `app()`.
- Money as strings end-to-end; bcmath with resolver scale; no float casts (PHPStan rules `ForbidFloatCastOnDecimalProperty`/`ForbidHardcodedBcmathScale` will fail CI otherwise).
- PHPStan level 8 clean on new files; Pint.
- Commit style: `feat(accounting): upcoming payments report endpoint`.

## Verify

`./vendor/bin/phpstan analyse` on new files, `./vendor/bin/pint`, run YOUR test file by path, `php artisan typescript:transform` diff committed.
