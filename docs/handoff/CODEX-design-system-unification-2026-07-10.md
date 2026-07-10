# CODEX HANDOVER — Design-System Unification + Procurement UI Fixes

> **Date:** 2026-07-10. **Runner:** Codex desktop (CLI-brokered Codex cannot write to `apps/erp.*` worktrees). **Post-run:** Claude session takes over for gate review + merge — do NOT merge to dev or push yourself.
> **Companion manifests (read both before starting):**
> - `docs/superpowers/audits/2026-07-10-procurement-ui-and-document-configurator-audit.md` — findings, file:line evidence
> - `docs/superpowers/audits/2026-07-10-design-system-violation-inventory.md` — **THE tracking manifest** (C1–C10 file lists + verification commands). The sweep is complete only when its verification commands return zero for swept scope.

---

## 0. Mission

Six waves, in order. Waves 0–3 are surgical; Waves 4–6 are the mechanical sweep. Each wave = its own commit series with verification evidence. Track progress in `docs/handoff/design-system-sweep-progress.md` (create it; update after every wave AND after every directory in Wave 5 with the re-run verification counts).

## 1. Ground rules (non-negotiable — from CLAUDE.md, violations block merge)

1. Work in a fresh worktree off origin/dev: `git worktree add ../erp.design-sweep -b feat/design-system-unification origin/dev`. Never commit to shared `dev` directly; never push to `dev`.
2. TDD: failing test first (Vitest FE, PHPUnit BE). Run vitest **by path**, never the full suite unattended (hung worker pools OOM the machine — if a run hangs, `ps aux | grep 'node (vitest'` and kill workers). NEVER run the full PHPUnit suite; run backend tests by path only.
3. TypeScript strict, no `any`; PHP no `mixed`; enums for status/type; constructor injection only (`private readonly`), never `app()`.
4. All user-facing text via `t()` (react-i18next). New keys → check `i18n.ts` 3-place wiring (import, resources, ns array).
5. Design tokens from `@/lib/designTokens` — zero new hardcoded Tailwind colors.
6. Types flow from backend: after changing PHP DTOs run `php artisan typescript:transform` (needs `CACHE_STORE=array`); never hand-edit `packages/shared/types`.
7. TanStack query keys for tenant data MUST use `tenantScopedKey([...])`.
8. `apiGet`/`apiPost` already unwrap `response.data.data` — no double-unwrap. Paginated `{data,meta}`: use `api.get` + return `response.data`.
9. Money/quantity: never `parseFloat`/`Number()`; `<MoneyInput>`/`<QuantityInput>`, string payloads.
10. Before declaring any wave done: `./scripts/preflight.sh` equivalent scoped runs (pnpm typecheck, pnpm lint, targeted tests) + the manifest verification commands for the touched scope.
11. **No new components when a canonical one exists** (inventory doc "Canonical system" table). Creating any new shared-shape component (picker/badge/table/header) requires an explicit note in the progress file with justification.

## 2. Decisions record (owner-approved 2026-07-10; do not re-litigate)

| # | Decision |
|---|---|
| D1 | Line-designation override ships as a **per-company setting** (not env flag). Compliance research verdict: compliant with EN 16931/Factur-X (BT-153 is free text); current Factur-X profile is BASIC WL (no line items emitted), so no XML surface today; French "dénomination précise" is a merchant truthfulness obligation → add soft helper text, no hard validation. |
| D2 | `LineItemEntryBar` becomes a suggestions-on-focus combobox (like `ProductPicker`/`ProductLineSelect`). Barcode scanning behavior unchanged. |
| D3 | Service lines return via pattern (c): product bar stays product/scan-only; adjacent **"Add service"** affordance opens the existing `ServicePicker`, gated `hasModule('Workshop')` — restoring what commit `e941d9b4d` accidentally deleted (see history in audit doc; old gating precedent: commits `961589044`/`da0ffeb1e`). |
| D4 | Partner picking survivor = **`PartnerPicker`** (`components/molecules/pickers/`). `PartnerSearchSelect`, crm `PartnerSelect`, document-ingestions `SupplierPicker` all migrate onto it and are deleted. Required ports FIRST: see Wave 3. |
| D5 | Full design-system sweep across apps/web (Wave 5), category by category, directory by directory, with ESLint/audit ratchet promotion locking each cleaned directory. |
| D6 | Document configurator (per-doc-type column show/hide + editability) is **OUT OF SCOPE** — parked for its own spec cycle. Do not build any of it. |
| D7 | Line-table density: description column is REMOVED as a standalone column; description renders as a muted `line-clamp-2` sub-line under the product name inside the article column; long-text recovery via `LineItemsTable`'s existing `renderLineDetail` expand mechanism. PDF blade templates untouched. |

---

## Wave 0 — Tooling & guardrails FIRST (locks progress as later waves clean)

0.1 **Fix `tools/audit-tanstack-keys.mjs` blind spot**: the approval check at `audit-tanstack-keys.mjs:309` gates on `ts.isPropertyAssignment` only, silently skipping shorthand `useQuery({ queryKey })`. Handle `ShorthandPropertyAssignment` by resolving the referenced declaration. TDD: add a fixture test that fails on the shorthand form first.
0.2 **Fix the three real `tenantScopedKey` violations it was hiding**: `PartnerPicker.tsx:99`, `VehiclePicker.tsx:95`, `ServicePicker.tsx:88` — wrap with `tenantScopedKey` (working template: `ProductPicker.tsx:142`). These are correctness bugs (stale cross-tenant cache on tenant/company switch).
0.3 **Fix `AddPartnerModal` invalidation bug** (`AddPartnerModal.tsx:185`): it invalidates `tenantScopedKey(['partners'])`, which is a prefix of NONE of the live keys (`['partners-search',...]`, `['partner', id]`, `['pickers','partner',...]`). Invalidate the actual key prefixes.
0.4 **Create `tools/audit-design-system.mjs`** (model: audit-tanstack-keys.mjs — AST or rigorous regex, baseline file for pre-existing offenders, default-deny for new ones). Checks, per the inventory doc's verification commands: C1 bespoke `<h1>` headers in pages; C2/C3 raw input/select/textarea/button with `tokens.*`; C4 form files without react-hook-form; C5 raw `<table>` in features; C6 local status maps. Seed the baseline from the current inventory. Wire into `pnpm lint` + preflight + CI like audit:keys. **From this point, every Wave 4–5 directory clean = shrink the baseline; new violations anywhere = build failure.**
0.5 **Widen the ESLint hardcoded-color rule**: global WARN rule currently matches only 8 color families (misses slate/zinc/amber/emerald/etc. — `eslint.config.js:187-193`). Widen to the full palette used by the C7 verification command. Keep WARN globally; per-directory ERROR promotion happens in Wave 5 as each dir is cleaned (template: the full-palette ERROR blocks for `scheduling/**` and `workshop-*` at `eslint.config.js:243-273`).
0.6 Fix the small `vehicles/` regression (1 file / 2 color occurrences in a nominally ERROR-enforced dir).

## Wave 1 — Functional fixes (small, high-value, independent)

1.1 **Picker suggestions on focus** (`LineItemEntryBar.tsx`): drop the `trimmedQuery !== ''` gate from the query `enabled` (line 77) and from the dropdown render condition (line 247), matching `ProductLineSelect`'s `isOpen`-only gate. Loading/empty/results states must render on focus with empty input. Backend already supports empty search (`ProductController::index`). Tests: extend `DocumentLineEditor` tests + a `LineItemEntryBar` unit test (focus → listbox visible with first-page results). Verify the barcode-scan path is untouched.
1.2 **"Add service" affordance** (D3): in `DocumentLineEditor.tsx`, restore a `handleAddService` path wired to `ServicePicker` (`components/molecules/pickers/ServicePicker.tsx` — reusable as-is post-0.2), rendered only when `useCompanyConfig().hasModule('Workshop')`. `document_lines.service_id`/`is_service` and the "service" badge (`DocumentLineEditor.tsx:495-499`) already exist; i18n keys (`sales:lineItems.tabs.service`, `searchServicesPlaceholder`) exist from the deleted implementation. TDD: restore/adapt the service-search assertions stripped from `DocumentLineEditor.test.tsx` in `961589044`/`e941d9b4d`. Do NOT extend barcode resolution to services.
1.3 **Supplier-invoice ProductPicker type filter**: `SupplierInvoiceCreatePage.tsx:680-693` inherits `productType='part'` default → services/consumables silently hidden. Pass an explicit prop covering purchasable types (check `ProductType` enum; likely `part`+`consumable`, plus `service` products).
1.4 **Table density (D7)**: in `DocumentLineEditor.tsx` (:479-531) merge description into the article column as muted `line-clamp-2` sub-line (`DesignationCell`/`NotesCell` views get the same clamp treatment); wire the long-description case through `renderLineDetail` (existing mechanism, `LineItemsTable.tsx:34,107,141-147`, already used at `DocumentLineEditor.tsx:832-843`). Apply the same visual contract to `DocumentLines.tsx` (read-only duplicate) and the `SupplierInvoiceCreatePage` receipt table (:774-851) — full structural unification of those tables happens in Wave 4/5; here only fix the density contract. `QuoteDetailPage.tsx` renders its own line table: migrate it to `DocumentLines`. PDF blades untouched.

## Wave 2 — Designation override: per-company setting (D1)

2.1 Migration (tenant DB): `companies.line_designation_override_enabled boolean default false` (precedent: `2026_03_24_400000_add_receipt_customization_columns_to_companies.php`).
2.2 Backend: `CompanyConfigController.php:92` reads the company column instead of `config('features...')`; settings update endpoint + FormRequest (follow `UpdateReceiptSettingsRequest` shape); keep the env flag as a global kill-switch ANDed in, or remove it — prefer remove for simplicity unless tests depend on it; document choice in progress file.
2.3 Frontend: toggle in Settings (fits the existing Settings page structure — either Company page or a new small "Documents" section; keep minimal, this is ONE toggle + helper text). Helper text (i18n, FR priority): the renamed designation must still precisely identify the goods/services sold (French "dénomination précise" obligation) — soft guidance only, no validation.
2.4 `useLineDesignationFeature.ts` keeps working (it reads company config — verify the payload key is unchanged).
2.5 `php artisan typescript:transform` if any DTO changed. Tests: backend feature test (toggle on → config payload true; off → false), FE test for the settings toggle.
2.6 Add a code comment at the future Factur-X line-emission point (`FacturXService.php` — see `FacturXDescriptionTest.php`) is NOT needed — the test already documents it. Just don't touch FacturX.

## Wave 3 — Partner picking consolidation (D4) — port first, then migrate, then delete

3.1 Ports INTO `PartnerPicker` (each TDD, extending `PartnerPicker.test.tsx`):
  a. **ID-only rehydration**: accept a bare partner id and self-fetch the display object (mirror `PartnerSearchSelect.tsx:70`'s `tenantScopedKey(['partner', value])` lookup) — required for RHF edit-mode forms.
  b. **In-page create affordance**: `allowNewInline` currently `window.open('/partners/new')` (`PartnerPicker.tsx:163-165`) — replace with opening `AddPartnerModal` inline (as `DocumentForm.tsx:681-693` does today). Keep prop name or rename to `onAddNew`; do not silently drop the modal UX.
  c. **Suggestions on focus**: remove the 2-char minimum gate (`PartnerPicker.tsx:98`) → fetch first page on open (consistent with D2).
  d. **Inactive partners**: PartnerPicker filters `is_active=true`; PartnerSearchSelect shows all. Default to active-only, add an `includeInactive` prop, and set it on migrated call sites that need parity (check each; CustomerHistoryAuditPage likely needs inactive too — it's an audit page).
3.2 Migrate the 4 `PartnerSearchSelect` sites (`DocumentForm.tsx:549` — RHF Controller adapter `field.onChange(next?.id ?? null)` + rehydration; `CreateCreditNotePage.tsx:404`; `QuoteRequestCreatePage.tsx:152`; `CustomerHistoryAuditPage.tsx:151`), then the 2 crm `PartnerSelect` sites, then document-ingestions `SupplierPicker` (1 site — it's a raw `<select>`, C2 hit too).
3.3 Delete `PartnerSearchSelect.tsx`, `PartnerSelect.tsx`, `SupplierPicker.tsx` + update any test mocks (`DocumentForm.test.tsx:59-61` mocks PartnerSearchSelect — rewrite against PartnerPicker, do NOT mock it to an empty div again; test the real integration where feasible).
3.4 The i18n default-label trap: `pickers.json partner.label` defaults to "Customer" — every call site must pass explicit `label`. Add this to the migration checklist per site.

## Wave 4 — Procurement family rebuild (the pages that triggered this work)

Rebuild onto canonical components (reference implementation: `DocumentForm.tsx`): **`SupplierInvoiceCreatePage.tsx`** (PageHeader + breadcrumb + unsaved-changes guard; react-hook-form+zod with inline `FormField` errors; both bespoke tables → `LineItemsTable` (manual mode) / `DataTable` read-only (receipt mode); `matchChipClass` → `StatusBadge`/`statusTone`; `StickyFormFooter` + `SaveSplitButton` + Cancel; keep MoneyInput/QuantityInput; PartnerPicker from Wave 3; ProductPicker keeps quick-create parity — wire `AddQuickProductModal` like `DocumentLineEditor.tsx:11`). Then **`SupplierInvoiceListPage.tsx`** (PageHeader, DataTable, StatusBadge, drop `divide-gray-200` literals), **`StandaloneReceiptPage.tsx`**, **`QuoteRequestCreatePage.tsx`** + the other quote-request pages (RHF + PageHeader + atoms; it already uses `ProductLineSelect` correctly), **`SupplierInvoiceDetailPage.tsx`**, **`GoodsReceiptListPage.tsx`**, **`QuoteRequestDetailPage/ComparisonPage`**. Every rebuilt page: verify the end-to-end flow (create a supplier invoice, create a quote request) against the local stack, not just compilation (repo rule 5).

## Wave 5 — Global mechanical sweep (manifest categories, directory by directory)

Order within each directory: C7 colors → C2/C3 atoms → C1 PageHeader → C6 StatusBadge → C5 tables → C4/C8 forms. After EACH directory: re-run the manifest verification commands for it, record counts in the progress file, shrink the audit-design-system baseline, and promote that directory to ESLint full-palette ERROR (template `eslint.config.js:243-273`).

Priorities: 1) `documents/` (43 files/1048 color occ + the 7 detail-page tables + LineEditor cluster — biggest and most user-visible); 2) `admin/`; 3) `import/`, `inventory-counting/`, `opening-balances/`; 4) `partners/`, `loyalty/`, `compliance/`, `parts-catalog/`; 5) the long tail per the C7 rollup.

Category-specific notes:
- C6: the 7 bespoke badge components (vouchers/StatusBadge, pos TableStatusBadge + OrderStatusBadge + StockBadge, inventory-counting CountingStatusBadge, batches BatchStatusBadge, vat-reporting VatPeriodStatusBadge, documents PaymentStatusBadge) → replace with canonical `StatusBadge` (`tone` prop + `statusTone()` helper) and delete. If a status genuinely needs a new tone, extend the atom once, not locally.
- C5: triage per manifest (List→DataTable, LineEditor→LineItemsTable, Detail→read-only DataTable). If `DataTable` lacks a needed feature (e.g., footer rows for finance reports), extend `DataTable` once; do not keep the raw table.
- C4/C8: page-level forms → RHF+zod+FormField+StickyFormFooter. **Modal/drawer forms (`*Modal.tsx`, `*Drawer.tsx`): RHF+zod+FormField yes, StickyFormFooter NO** (use the modal footer pattern).
- C1: `pages/legal/*` + unauthenticated `auth/` pages are exempt from PageHeader (not from color tokens). Dedup first: `reports/AgedReceivablesPage` vs `finance/AgedReceivablesPage` (latter is canonical); `documents/ReturnNote*` vs `documents/return-notes/*` — check the router, delete the dead one.
- Behavioral invariant: sweeps must be pixel-conservative — same layout, same behavior, tokenized colors and canonical components. Any intentional visual change gets a progress-file note.

## Wave 6 — Dedup cleanup + orphans

`ProductSelector` → `ProductPicker` (2 sites; port any missing feature, e.g. multi-select if coupons need it — if multi-select is needed, extend ProductPicker with a documented prop); location: unify `LocationSelector` + `LocationSelectorMulti` into one family under one directory (resolve `location/` vs `locations/` split); `CategorySelect` vs `CategorySelector` → keep one; `UserPicker` vs `UserSelector` → keep one (UserPicker is in components/ui — prefer moving the survivor to `components/molecules/pickers/`). Orphans — confirm 0 consumers then delete: `DocumentLineVariantSelector`, `ProductDetailVariantPicker`, `TerminalSelector` (pos). Move any surviving `components/ui/*` picker into the atomic tree (`molecules/pickers/`) and add an `index.ts` for `StickyFormFooter`.

## Verification gates (every wave)

- `pnpm typecheck`, `pnpm lint` (with the new audit scripts wired), targeted `pnpm vitest run <paths>`.
- Backend changes: targeted `./vendor/bin/phpunit <path>`, `./vendor/bin/phpstan` on touched modules, `./vendor/bin/pint`.
- Wave 4 pages + Wave 1 fixes: live end-to-end verification against the local stack (see `docs/handoff/RESUME-2026-07-08.md` for container bring-up; demo tenant owner@pharmabio.tn).
- Final: full manifest verification-command run; progress file complete; NO pushes to dev — leave the branch for Claude gate review.

## Out of scope (do NOT touch)

Document configurator (D6); Factur-X profile upgrade; POS Tauri app (`apps/pos`); backend document write paths beyond Wave 2's company setting; PDF blade templates; `packages/shared/types` by hand; anything under `apps/api/app/Modules/{Pos,Fiscal*,Treasury}`.
