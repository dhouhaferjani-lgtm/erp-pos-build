# Linked-Cost FE Test Coverage Report

Date: 2026-07-02
Branch: `test/linked-cost-fe-coverage`

## Scope

Test-only change. No shipped FE component or hook code was modified.

Expanded `apps/web/src/features/expenses/components/organisms/ExpenseFormFields.linkedCost.test.tsx` from one raw-key happy path into behavior coverage using the real i18n bundles and mocked API boundaries.

## Added Coverage

- Generic default state: linked-cost invoice/cost controls are hidden until the user selects `linked_cost`.
- Linked-cost toggle behavior: controls appear for linked costs and hide again when toggled back to generic.
- Generic submit payload: keeps `expense_kind: "generic"` and preserves `total` as the exact string entered.
- Invoice picker search: `listLinkableInvoices` fires only after linked-cost mode is selected, and the React Query key is tenant/company scoped as `["expenses", "linkable-invoices", "tenant-1", "company-1"]`.
- Single-operation resolution: selecting a supplier invoice renders the resolved PO chip (`PO-2026-0001`) and does not render the second operation selector.
- Linked submit payload: explicitly includes `linked_invoice_id`, `linked_operation_id`, `cost_type`, `split_method`, and keeps all money/link identifiers as strings.
- Zero-operation downgrade: resolver returning no operations exposes the downgrade action, converts the form back to generic, clears link ids, and submits generic.
- French i18n smoke: linked-cost labels render in French and do not leak raw keys such as `expenses:form.linkedInvoice` or `common:save`.

## Documented Findings

These are marked with `it.todo(...)` in the test file because exposing them as failing tests would require FE fixes, and this task was test-only.

1. `OPERATION_PARTIALLY_RECEIVED` does not have a proven readable FE surface.
   The create/update hooks currently toast `error.message`. For Axios 422 responses this commonly becomes a generic status message unless the API layer has converted the backend payload first. Needs a component/hook fix before a green behavior test can be written.

2. Switching from `linked_cost` back to `generic` does not explicitly clear selected link fields.
   The zero-operation downgrade button clears these fields, but the normal radio toggle only hides the linked controls. A full regression should assert stale linked invoice/operation values cannot leak into a generic submit after a user manually toggles back.

3. Posted linked-cost reversal has no FE action surface.
   `expenseApi.reverse(id)` exists, but `ExpenseDetailPage` has no reverse button, confirm flow, success toast, or error toast. The requested confirm/success/error coverage is therefore documented as missing shipped behavior.

## Verification

- `pnpm --filter @autoerp/web test -- src/features/expenses/components/organisms/ExpenseFormFields.linkedCost.test.tsx`
  Result: pass, 5 tests passed, 3 todos.
- `pnpm --filter @autoerp/web typecheck`
  Result: pass.
- `pnpm --filter @autoerp/web lint:eslint`
  Result: pass with existing repo warnings, 0 errors.

