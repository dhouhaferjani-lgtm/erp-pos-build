# Gate 2 Result + Continuation Brief — design-system unification

> Gate 2 review of the fix wave (a63dd37d4..9e4cf2f34), 2026-07-10. Two independent adversarial lanes.
> **Verdict: APPROVE-WITH-FIXES.** Every gate-1 blocker and major is genuinely fixed with red-first regression tests; baseline growth 419→533 reconciled to exact arithmetic with ZERO laundered entries (145/146 added entries reproduce on pre-fix file content; the 146th is a legitimate fingerprint shift); minors batch complete with one logged deferral and zero silent skips. 140+ targeted FE tests green, tools 51/51, manifest↔scanner counts reconcile exactly (240=240).
> **Two new findings gate further progress — fix them FIRST (small), then resume Wave 5.**

## Step 1 — Gate-2 fixes (before any new sweep work)

**G2-1 (major) — Tab silently adds a product line.** `LineItemEntryBar.tsx:200-207`: the commit gate accepts `Enter || Tab` whenever `isOpen && products.length > 0`. Focus auto-opens suggestions with `highlightedIndex` 0, so a keyboard user tabbing THROUGH the field commits `products[0]` as a document line. Introduced by `ab8922666` (pre-fix code bailed on empty query before the commit branch, so Tab passed through).
Fix: gate the Tab-commit on `trimmedQuery !== ''` (Enter may commit the empty-query highlight; Tab must pass through untouched on empty query). Add a Tab regression test (focus → suggestions visible → Tab → NO line added, focus moves on).

**G2-2 (major) — raw i18n keys shown as validation errors.** `SupplierInvoiceCreatePage.tsx:90,:102` and `QuoteRequestCreatePage.tsx:44` put literal `'validation.required'` / `'validation.maxLength'` into zod messages; FormField renders the error string verbatim, so users see the raw key (and `maxLength` loses its `{{max}}` interpolation). Masked because the test i18n mock returns keys — `SupplierInvoiceCreatePage.test.tsx:435` asserts the raw key.
Fix: translate at render (`error={errors.x ? t('common:validation.required') : undefined}`) or build the schema inside the component with `t` (canonical DocumentForm pattern). Update the test to assert the translated string (adjust the i18n mock accordingly).

**G2-3 (minor, ride along):** `ServicePickerValue.default_tax_configuration_id` is never served by the backend (`ServiceData.php` has only `tax_rate`) — dead field, always null. Either drop the field from the FE type + wiring or add it to `ServiceData` (prefer drop; note it).
**G2-4 (minor, ride along):** StandaloneReceiptPage dirty-heuristic hardcodes `'1.0000'/'0.0000'/'0.000'` sentinels coupled to `newLine()` defaults — derive from `newLine()` (compare against a fresh instance) instead of literals.

**G2-5:** commit the two intentionally-uncommitted review docs with this fix commit: `docs/handoff/CODEX-fixlist-gate1-2026-07-10.md` (new) and the modified `docs/superpowers/audits/2026-07-10-design-system-violation-inventory.md` (manifest command fixes — keep the modification exactly as-is).

## Step 2 — Resume Wave 5 (MJ-1 landed, so directory declarations are unblocked)

Per-directory protocol (unchanged): sweep C7 colors → C2/C3 atoms → C1 PageHeader → C6 StatusBadge → C5 tables → C4/C8 forms; then (a) re-run the manifest verification commands for the directory (use the FIXED C2/C3 command from the inventory doc), (b) shrink `audit-design-system-baseline.json` (stale entries must go to zero for the dir), (c) promote the directory to the full-palette ESLint ERROR block (template `eslint.config.js` scheduling/workshop-* blocks), (d) record counts in `docs/handoff/design-system-sweep-progress.md`.

Directory order:
1. **`src/features/documents/`** — biggest and most user-visible (per manifest: ~43 files / ~1048 C7 occurrences; C5 = the 7 document detail pages → read-only `DataTable`, plus the LineEditor cluster → `LineItemsTable`; C6 remnants; C1 stragglers). Reminder from the manifest: `documents/` vs `documents/return-notes/` ReturnNote LIST page — the `documents/ReturnNoteListPage.tsx` is LIVE in the router (already verified), keep it, just sweep it.
2. **`src/features/admin/`** (12 files / ~513) — super-admin panel; PageHeader applies (it's in-app-shell), colors/tables/badges all apply.
3. **`import/`, `inventory-counting/`, `opening-balances/`** (~840 combined).
4. **`partners/`, `loyalty/`, `compliance/`, `parts-catalog/`** (~860 combined).
5. Remaining long tail per the manifest C7 rollup (services, batches, parapharmacy, pricing, crm, vat-reporting, vouchers, categories, products, dashboard, reports, uom, pos, auth*, expenses, coupons, promotions, …). *auth/ and pages/legal/: colors/atoms yes; PageHeader exempt (not in app shell).
6. Modal/drawer forms in C4: RHF+zod+FormField yes; StickyFormFooter NO (modal footer pattern).

## Step 3 — Wave 6 (after Wave 5 directories are done)

- `ProductSelector` → `ProductPicker` (2 sites: CouponFormPage, PromotionFormPage; if multi-select is needed, extend ProductPicker with a documented prop — do not keep the duplicate).
- Location family: unify `features/location/LocationSelector` (3 sites) + `features/locations/components/LocationSelectorMulti` (2 sites) into one family in ONE directory.
- `CategorySelect` (components/catalog, 1 site) vs `CategorySelector` (features/categories, 3 sites) — keep one.
- `UserPicker` (components/ui, 2 sites) vs `UserSelector` (features/users, 1 site) — keep one, move survivor to `components/molecules/pickers/`.
- Orphans — confirm 0 consumers then delete: `DocumentLineVariantSelector`, `ProductDetailVariantPicker`, `TerminalSelector`.
- Move surviving `components/ui/*` pickers (InvoiceSearchSelect, DeliveryNoteSearchSelect, DocumentSearchSelect base) into the atomic tree; add `index.ts` for `StickyFormFooter`.

## Standing rules (all verified this gate — keep them)

1. Never `--write-baseline` to absorb new work — the entry-level replay WILL be run again at gate 3.
2. Every deviation/deferral goes in the progress doc explicitly.
3. Targeted test runs only (vitest by path; no full PHPUnit); kill orphaned vitest workers.
4. Sweeps are pixel-conservative; intentional visual changes listed in the progress doc for the owner's visual pass.
5. No push, no merge — leave the branch for the gate review.
6. Evidence set per report: design audit totals, tanstack audit, typecheck, lint (0 errors), targeted suite counts, per-directory verification-command counts.

## Gate cadence

Report back for **Gate 3 after `documents/` + `admin/` are complete** (don't run all of Wave 5 unreviewed — these two directories are ~30% of remaining volume and de-risk the pattern for the tail). Gate 4 = rest of Wave 5 + Wave 6.
