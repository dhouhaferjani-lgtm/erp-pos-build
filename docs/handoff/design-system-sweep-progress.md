# Design-System Sweep Progress

> Branch: `feat/design-system-unification` in `/Users/houssamr/Projects/syneriva/apps/erp.design-sweep`.
> Source handoff: `docs/handoff/CODEX-design-system-unification-2026-07-10.md`.

## Wave 0 — Tooling & Guardrails

Status: complete.

- Worktree: created from `origin/dev` as required by the handoff.
- Companion manifests copied into the branch because they were untracked in the main checkout.
- 0.1 TanStack audit shorthand-property blind spot:
  - RED: `pnpm vitest run tools/__tests__/audit-tanstack-keys.test.mjs` failed on `flags shorthand queryKey declarations with no tenant scope`.
  - GREEN: same command passed after resolving shorthand `queryKey` declarations.
- 0.2 Tenant-scoped picker keys:
  - RED: `pnpm audit:keys` reported `PartnerPicker`, `VehiclePicker`, `ServicePicker`, plus an additional real `UserPicker` violation exposed by the scanner fix.
  - GREEN: `pnpm audit:keys` reports 0 violations after wrapping all four picker keys with `tenantScopedKey`.
- 0.3 `AddPartnerModal` invalidation:
  - RED: `pnpm vitest run src/components/organisms/AddPartnerModal/AddPartnerModal.test.tsx` failed on live partner search/detail cache invalidation.
  - GREEN: same command passed after replacing the stale exact key with a tenant/company-aware predicate for `partners`, `partners-search`, `pickers/partner`, and the created `partner` detail.
- 0.4 Design-system audit:
  - RED: `pnpm vitest run tools/__tests__/audit-design-system.test.mjs` failed because `tools/audit-design-system.mjs` did not exist.
  - GREEN: same command passed after adding the C1-C6 scanner.
  - Baseline seeded with 509 current C1-C6 entries using `node tools/audit-design-system.mjs --write-baseline`.
  - `node tools/audit-design-system.mjs` passes with 509 acknowledged, 0 new, 0 stale.
  - Wired into `apps/web/package.json`, `scripts/preflight.sh`, and `.github/workflows/ci.yml`.
- 0.5 ESLint hardcoded-color rule:
  - Global WARN and strict new-feature ERROR regexes widened to the full C7 palette/utility set.
- 0.6 Vehicles regression:
  - Replaced `hover:bg-gray-50` and `bg-gray-100` literals in `VehicleDetailPage` with existing design tokens.
- Verification:
  - `pnpm vitest run tools/__tests__/audit-tanstack-keys.test.mjs tools/__tests__/audit-design-system.test.mjs src/components/organisms/AddPartnerModal/AddPartnerModal.test.tsx` passed: 37 tests.
  - `pnpm typecheck` passed.
  - `pnpm lint` passed; existing warning count remains high, but 0 errors. The chained audits passed:
    - TanStack query key audit: 0 violations.
    - Design-system audit: 509 acknowledged, 0 new, 0 stale.
  - Vehicles C7 scoped check returned zero matches:
    `rg -n --pcre2 '(bg|text|border|ring|divide|from|to|via|placeholder|fill|stroke|outline|accent|caret|shadow|decoration)-(gray|red|green|blue|yellow|amber|orange|purple|pink|indigo|emerald|rose|slate|zinc|neutral|stone)-[0-9]{2,3}\b' src/features/vehicles -g '!**/*.test.tsx' -g '!**/__tests__/**' -g '!**/*.stories.tsx' -c`

New shared-shape components: none.

## Wave 1 — High-Impact UX Corrections

Status: complete.

- 1.1 `LineItemEntryBar` suggestions-on-focus:
  - RED: `pnpm vitest run src/components/molecules/line-items/LineItemEntryBar.test.tsx` failed on an empty-query focus suggestion test.
  - GREEN: the entry bar now opens and fetches first-page product suggestions on focus while keeping scanner Enter resolution unchanged.
- 1.2 Workshop service affordance:
  - RED: `pnpm vitest run src/features/documents/components/__tests__/DocumentLineEditor.test.tsx` failed because the Workshop-gated `Service` control was absent.
  - GREEN: `DocumentLineEditor` now exposes a `ServicePicker` when Workshop is enabled and adds `is_service` document lines with service ids and service pricing.
- 1.3 Supplier-invoice ProductPicker filter:
  - RED: `pnpm vitest run src/features/purchases/supplier-invoices/SupplierInvoiceCreatePage.test.tsx --testNamePattern "all-products picker"` failed with `productType: undefined`.
  - GREEN: manual supplier-invoice lines now pass `productType="all"` so the picker does not inherit the `part` default.
- 1.4 Table density:
  - RED: focused density tests failed while `DocumentLineEditor` and `QuoteDetailPage` still exposed separate description tables/columns.
  - GREEN: editable document lines, read-only `DocumentLines`, quote detail lines, and supplier-invoice receipt rows now use article-cell description/notes density with `line-clamp-2`; long text remains available through `LineItemsTable` detail rows.
  - Baseline shrunk from 509 to 507 after removing two stale bespoke table fingerprints for `DocumentLines` and `QuoteDetailPage`.
- Verification:
  - `pnpm vitest run src/components/molecules/line-items/LineItemEntryBar.test.tsx src/features/documents/components/__tests__/DocumentLineEditor.test.tsx src/features/purchases/supplier-invoices/SupplierInvoiceCreatePage.test.tsx src/features/documents/quotes/__tests__/QuoteDetailPage.tenantScope.test.tsx` passed: 49 tests.
  - `pnpm typecheck` passed.
  - `pnpm lint` passed; existing warning count remains high, but 0 errors. The chained audits passed:
    - TanStack query key audit: 0 violations.
    - Design-system audit: 507 acknowledged, 0 new, 0 stale.
  - `npx react-doctor@latest --verbose --scope changed --base origin/dev` passed with no issues after replacing changed-page barrel imports in `QuoteDetailPage`.

New shared-shape components: `DocumentLines` now renders via `LineItemsTable`.
