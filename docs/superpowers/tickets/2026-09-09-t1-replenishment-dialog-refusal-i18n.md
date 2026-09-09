# T1: Translate replenishment transfer refusals

Status: open · severity: low · owner: T-2/T-3 replenishment UI

`apps/web/src/features/replenishment/components/CreateTransferDialog.tsx:99-100` surfaces the raw English server message through `getErrorMessage`. The new 403 therefore remains untranslated; the prior 422 had the same limitation.

Acceptance: reuse the typed refusal-to-i18n mapping pattern in `apps/web/src/features/stock-adjustments/api/refusals.ts`, including `LOCATION_ACCESS_DENIED`, with replenishment translation keys in English and French and an appropriate fallback for unknown errors. Cover hidden-location 403 and controller company-validation 422 without exposing implementation details to operators. No frontend source changes in T-1 round 2.
