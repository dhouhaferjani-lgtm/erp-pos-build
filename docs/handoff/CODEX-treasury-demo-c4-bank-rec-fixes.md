# C4 — Bank Reconciliation Fixes

> Chunk C4 of the treasury demo plan (`docs/superpowers/audits/2026-07-02-treasury-demo-gap-audit-and-plan.md`). Small full-stack. TDD: failing test first per task. Backend tests by path only — never the full suite.

## Context

Bank rec is fully built (service/controller/routes/740-line page/20 backend tests) but four defects make it feel broken. Statement import is OUT OF SCOPE (deferred feature — do not build it).

## Tasks

### T1 (FE) — Permission gate mismatch
Sidebar shows Bank Reconciliation to the `treasury` module roles (`components/organisms/Sidebar/Sidebar.tsx:262`), but the route demands `repositories.manage` (`routes/index.tsx:1585-1589`), which in the hardcoded map (`hooks/usePermissions.ts:57`) = admin+accountant only → `treasury`/`manager` users click the visible link and get Access Denied. Backend list/show/summary only require `repositories.view` (`Treasury/Presentation/routes.php:186-195`).
Fix: align route gate with backend + sidebar — use `repositories.view` if present in the `usePermissions` map for the same roles that see the sidebar entry; otherwise gate both sidebar and route on the same key. Rule: link-visibility == route-access. Vitest test on the route guard/permission map.

### T2 (FE) — Broken back-link
`features/treasury/BankReconciliationPage.tsx:662` back button navigates to `/settings`. Point it to the finance hub `/finance` (or `/treasury/repositories` if the page header pattern elsewhere prefers the section root — match the convention used by sibling treasury pages). Test asserts the link target.

### T3 (BE) — `ReconciliationCompleted.matchedTotal` always 0
`BankReconciliationService.php:204` reads `$item->amount` but `bank_reconciliation_items` has no `amount` column — should be `$item->payment->amount` (the summary at service:267 already does this correctly). PHPUnit test first: complete a reconciliation with matched items, assert the dispatched `ReconciliationCompleted` event carries the exact bc-sum. Money via bcmath at resolver scale — no float.

### T4 (BE+FE) — Difference semantics: make "Complete" honestly reachable
Today: `opening_balance = repository->last_reconciled_balance ?? '0.00'` (service:54), `closing = opening + Σ matched` (service:305-312), `can_complete` requires `difference == 0` (service:288). With a seeded bank balance of 25,000 and no reconciliation history, no realistic statement number ever completes.
Minimal honest fix (keep it small):
- Allow an explicit `opening_balance` on `startReconciliation` (validated decimal string, default stays `last_reconciled_balance ?? '0.00'`), persist it on the reconciliation row, and use it in the difference math.
- Surface opening balance + live difference clearly in the FE start-modal and summary panel so the user understands what must net to zero.
Do NOT redesign the model (no statement lines). Tests: service math with explicit opening balance; `can_complete` true when statement == opening + matched; FE summary renders the difference.

## Constraints

- Precision rule 19 everywhere: amounts are strings; bcmath server-side with `CurrencyScaleResolverInterface` (constructor-injected, entity currency); FE uses `formatCurrency`/`MoneyInput` — no `parseFloat` on money.
- FormRequest for the new `opening_balance` param: `numeric` + money regex ceiling `/^-?\d+(\.\d{1,3})?$/`.
- i18n: new FE strings via `t()` in the existing `treasury` namespace (en/fr/ar).
- The 20 existing tests in `tests/Feature/Treasury/BankReconciliationTest.php` must stay green (run that file by path).
- Migration note: persisting `opening_balance` likely needs a tenant migration on `bank_reconciliations` — decimal(15,3)-compatible, follow neighboring migration style.
- Commit per task: `fix(treasury): ...`.

## Verify

BE: phpstan on touched files, pint, `BankReconciliationTest.php` + your new tests by path. FE: `pnpm typecheck`, your Vitest files by path.
