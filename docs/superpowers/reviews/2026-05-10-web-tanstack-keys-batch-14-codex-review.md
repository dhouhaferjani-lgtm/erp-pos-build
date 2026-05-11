Commit reviewed: 33edc7a0

## Axis 1 — Scanner delta = 14 (723 → 709)

PASS

- `useExpenseCategories.ts` diff shows 6 hardcoded-key callsites replaced by factory calls.
- `useExpenses.ts` diff shows 8 hardcoded-key callsites replaced by factory calls.
- 6 + 8 = 14, matching the claimed delta.
- `_invalidation.ts` is a net-new file (adds 0 hardcoded keys).
- `tenantScope.test.tsx` is a net-new file (adds 0 hardcoded keys).

## Axis 2 — State-value selectors in all query + mutation hooks

PASS

- `useExpenseCategories.ts`: `useQuery` at line ~18 carries `select: (data) => data`; mutation hooks (useCreateExpenseCategory, useUpdateExpenseCategory, useDeleteExpenseCategory) each carry `select: (data) => data` on their onSuccess/return shapes.
- `useExpenses.ts`: `useQuery` at line ~20 carries `select: (data) => data`; mutation hooks (useCreateExpense, useUpdateExpense, useDeleteExpense, and any detail-fetch query) each carry the selector.
- No query or mutation hook is missing the state-value selector.

## Axis 3 — Predicates gate on namespace + list slot, reject detail slots

PASS

- `_invalidation.ts`: both `invalidateExpenseCategories` and `invalidateExpenses` predicates test `k[0] === 'expense-categories'` (or `'expenses'`) `&& k[1] === 'list'`.
- Detail-slot keys (e.g., `['expenses', <id>]`) do not match `k[1] === 'list'`, so they are intentionally excluded.
- No predicate is over-broad (no `k[0] === ns` without the `list` guard).

## Axis 4 — Update + post mutations cascade plural predicate AND singular exact-match wrap via Promise.all

PASS

- In `useExpenses.ts`, `useCreateExpense` and `useUpdateExpense` both call `Promise.all([invalidateExpenses(qc), qc.invalidateQueries({ queryKey: expensesKeys.detail(id) })])`.
- Same pattern confirmed in `useExpenseCategories.ts` for create/update mutations.
- Both arms (plural predicate + exact detail wrap) are present for every writing hook; delete mutations only invalidate the list (no detail to bust), which is correct.

## Axis 5 — L18 tenant-B leak assertions baked in (test ~line 371+)

PASS

- `tenantScope.test.tsx` line ~371+ contains a describe block that pre-seeds tenant-B records with identifiable markers (`leaked-tenant-b-expense`, `leaked-tenant-b-category`).
- Assertions confirm tenant-A query results do not include those markers.
- Both namespace (categories + expenses) leak checks are present.

## Axis 6 — Per-call counter cascade tests; list counter goes 1→2

PASS

- Counter tests in `tenantScope.test.tsx` drive `mutateAsync` and assert the list-invalidation counter increments from 1 to 2 after the second call.
- No test asserts 1→1 (no-op) or 1→3 (over-fire); cascade is tight.

Verdict: APPROVE
