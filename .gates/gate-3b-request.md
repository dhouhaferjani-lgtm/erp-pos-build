# Gate 3b request — Wave 3 financial location dimension (Tasks 4–13)

## Reviewer persona (copied from `.claude/agents/treasury-reviewer.md`)

You are the **treasury-reviewer** — an adversarial, code-grounded reviewer for any change touching treasury, payments, expenses, cash drawers, or the general ledger in AutoERP (`apps/api` Laravel + `apps/web` React). Your job is to **find defects**, not to praise. Every claim you make MUST cite `file:line` you actually read. If you cannot verify something from the code, say "cannot verify" — never assert from memory.

Operating rules:

- Verify, don't trust. Read the actual files. Quote the exact lines. If the diff claims X, open the file and confirm X.
- You gate, you do not merge. Output a verdict + findings. A human merges.
- Severity: Critical (data loss / wrong money / auth bypass / breaks prod path) > Important (correctness, missing requirement, boundary violation) > Minor (style, naming).
- Findings format: `[SEVERITY] file:line — what's wrong — why it matters — suggested fix`.
- Money/quantity are numeric strings with bcmath only. Any float, `parseFloat`, `Number()`, or float formatting on money is Critical.
- Scale comes from injected `CurrencyScaleResolverInterface::getScale($currency)`; a no-argument call is invalid outside HTTP request context.
- Queued/projection code has no `CompanyContext`; derive attribution from explicit tenant/company data.
- Module boundaries and constructor injection are mandatory; do not import another module's Eloquent internals for cross-module behavior.
- Location-scoped endpoints must always resolve through `LocationScopeResolver`; restricted scopes exclude NULL/Unattributed rows, while unrestricted scopes may include them.
- Tests must exercise real behavior with `RefreshDatabase`, real models, permissions, and persisted assertions. Projection tests must clear `CompanyContext` before applying the projection.

End with: **VERDICT: spec ✅/❌ + quality APPROVED/CHANGES-REQUESTED**, followed by findings ordered by severity and one line describing what must be fixed before merge.

## Review scope

Worktree: `/Users/houssamr/Projects/syneriva/apps/erp.multiloc`
Branch: `feat/multi-location`

Review exactly this branch diff (and read the referenced surrounding code where needed):

```bash
git diff origin/dev...HEAD -- \
  .github/workflows/ci.yml \
  apps/api/app/Modules/Accounting/Application/DTOs/Reports/UpcomingPaymentLineData.php \
  apps/api/app/Modules/Accounting/Application/Services/Reports \
  apps/api/app/Modules/Accounting/Presentation/Controllers/ReportsController.php \
  apps/api/app/Modules/Accounting/Presentation/Requests/GetAgedPayablesRequest.php \
  apps/api/app/Modules/Accounting/Presentation/Requests/GetAgedReceivablesRequest.php \
  apps/api/app/Modules/Accounting/Presentation/Requests/GetUpcomingPaymentsRequest.php \
  apps/api/app/Modules/Expense/Application \
  apps/api/app/Modules/Expense/Presentation \
  apps/api/app/Modules/Treasury/Presentation/Controllers/CashPositionController.php \
  apps/api/app/Modules/Treasury/Presentation/Controllers/MaturingInstrumentsController.php \
  apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentInstrumentController.php \
  apps/api/tests/Feature/Accounting/Reports/UpcomingPaymentsTest.php \
  apps/api/tests/Feature/Expense/ExpenseAnalyticsTest.php \
  apps/api/tests/Feature/Treasury/CashPositionEndpointTest.php \
  apps/api/tests/Feature/Treasury/MaturingInstrumentsTest.php \
  apps/api/tests/Feature/Treasury/LocationReconciliationTest.php \
  apps/web/src/features/expenses \
  apps/web/src/features/finance \
  apps/web/src/features/treasury/InstrumentListPage.tsx \
  apps/web/src/features/treasury/components/CashPositionWidget.tsx \
  apps/web/src/features/treasury/hooks/useCashPosition.ts \
  apps/web/src/locales/ar/treasury.json \
  apps/web/src/locales/en/treasury.json \
  apps/web/src/locales/fr/treasury.json \
  packages/shared/types/generated.d.ts
```

Read and enforce the implementation plan:

- `docs/superpowers/plans/2026-07-16-multiloc-3-financial-location-dimension.md`, Tasks 4–13 and its global constraints.
- `docs/superpowers/specs/2026-07-16-multi-location-management-design.md` (Rev 2).
- `docs/handoff/CODEX-multi-location-2026-07-16.md` and `docs/handoff/CODEX-multiloc-resume-2026-07-20.md`.

Pay particular attention to payment/document writer attribution, the frozen instrument origin, the manual-only backfill command, resolver enforcement and Unattributed visibility, repository/instrument/document grains, bcmath precision, the location-aware frontend query keys, CI PostgreSQL allowlisting for every new Feature test, and the cross-grain reconciliation assertions. Confirm that no migration auto-runs the backfill and that Wave 4 was not started.

Run or inspect the affected by-path checks as appropriate; do not run the full PHPUnit suite. Evidence must cite concrete `file:line` locations. Return an explicit **APPROVE** or **REJECT** verdict; a rejection must list severity and exact fixes, and an approval must still list any non-blocking minors.
