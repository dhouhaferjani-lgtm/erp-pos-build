# Task D5 Report — Cash-position widget on both dashboards

## Outcome

Implemented the approved C9 frontend contract from D5 base `74187dd36`:

- `useCashPosition()` remains backward-compatible and accepts `{ flowsWindow?: number }`.
- `CashPositionWidget` self-gates on both Treasury permission and company-module axes.
- The allowed state displays the server grand total, all three repository-type totals/counts, seven-day inbound/outbound flows, and links to the Treasury overview and cash-movements report.
- Owner and generic dashboards mount the same self-gating component without duplicating authorization logic.
- English, French, and Arabic treasury bundles contain all widget copy.

Money remains canonical decimal strings through `formatCurrency`; no `Number`, `parseFloat`, or other numeric money coercion was added.

## TDD evidence

RED command:

```text
pnpm --filter @autoerp/web exec vitest run \
  src/features/treasury/hooks/__tests__/cashPositionAndMovementsTenantScope.test.tsx \
  src/features/treasury/components/CashPositionWidget.test.tsx \
  src/features/dashboard/dashboard.test.tsx \
  src/features/owner-dashboard/__tests__/OwnerDashboardPage.test.tsx
```

Result: exit 1; 4 test files failed. The seven-day key/parameter assertion failed while the legacy hook assertion stayed green; the widget module and both host mounts were absent.

GREEN result for the same command: exit 0; 4 files, 20 tests passed.

Pinned behaviors:

- exact argument-less tenant/company key remains unchanged;
- seven-day option participates in the tenant-scoped key and request params;
- permission deny and module deny each return an empty tree without mounting the data hook;
- allowed state renders total, type counts, in/out flows, and both link targets;
- both host pages contain the widget mount.

## Verification

- Focused D5: 4 files / 20 tests passed.
- Treasury + dashboard + owner regression: 46 files / 276 tests passed.
- `pnpm --filter @autoerp/web typecheck`: exit 0.
- D5 production/widget ESLint: exit 0 with no diagnostics.
- Full ESLint phase: 0 errors (repository warning baseline only).
- TanStack query-key audit: 0 new, 0 stale.
- `git diff --check`: exit 0.
- React Doctor v0.7.6: `--scope changed --base 74187dd36 --no-telemetry --blocking none` → `No issues found!`.

The full `pnpm --filter @autoerp/web lint` pipeline stops at two design-system audit findings already present at the D5 base in `CashMovementsReportPage.tsx` (D4 raw button/table). No D5 file is listed. They were not refactored because D5 explicitly forbids prior-task/giant-host scope creep. An optional global Arabic coverage run likewise found only unrelated existing gaps in other namespaces; the D5 widget test passed.

## Scope and protected areas

No backend, fiscal, movement-port, bank-picker, `RepositoryDetailPage`, or `ExpenseDetailPage` file was touched. The existing large dashboard hosts received import-and-mount changes only.
