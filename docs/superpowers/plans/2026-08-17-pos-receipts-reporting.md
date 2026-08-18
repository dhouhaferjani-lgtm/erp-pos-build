# POS Receipts Reporting Implementation Plan

> **For Codex:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. The frozen contracts are `docs/handoff/CODEX-DISPATCH-receipts-build-2026-08-12.md` and `docs/handoff/SPEC-pos-receipts-reporting-2026-08-11.md`; where this plan abbreviates a payload, the spec's exhaustive allowlists and test matrices control.

**Goal:** Deliver the approved receipts reporting lane: a least-privilege SALE register, safe receipt detail, refund/void register, audited reprints, and the conditional chain panel, with backend location authorization and fiscal-safe DTO boundaries.

**Architecture:** Extend the existing Laravel receipt read surface through dedicated FormRequests, explicit Data/Resource allowlists, `LocationContext`, and one bulk refund-reporting enricher. Add React Query-backed register/detail pages using the existing list shell and design tokens. Preserve the legacy double-nested index envelope, keep the two registers disjoint at the UI, and format every money value using its receipt currency without computing cross-receipt aggregates.

**Tech Stack:** Laravel 12, PostgreSQL, Pest/PHPUnit, Spatie Laravel Data/TypeScript Transformer, React 19, TypeScript, TanStack Query, React Router, i18next, Vitest/Testing Library, Playwright.

---

## Milestone M1 — Wave 1

### Task 1: Validate and scope the receipt index

**Files:**
- Create: `apps/api/app/Modules/POS/Presentation/Requests/IndexReceiptsRequest.php`
- Modify: `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php`
- Create: `apps/api/tests/Feature/POS/ReceiptIndexTrainingExclusionTest.php`
- Create: `apps/api/tests/Feature/POS/ReceiptIndexTypeFilterTest.php`
- Create: `apps/api/tests/Feature/POS/RefundRegisterPaginationTest.php`
- Create: `apps/api/tests/Feature/POS/ReceiptIndexLocationScopeTest.php`
- Create: `apps/api/tests/Feature/POS/ReceiptIndexFiscalStatusFilterTest.php`
- Create: `apps/api/tests/Feature/POS/ReceiptIndexEnvelopeTest.php`
- Create: `apps/api/tests/Feature/POS/ReceiptFilterDateBoundaryTest.php`

1. Write failing feature tests for BT-1 through BT-5 and BT-18, including the entire r5 training/type combination table, wildcard escaping, validation bounds, company-timezone half-open date windows, and `LocationContext` null/empty/subset semantics.
2. Run only those test files and confirm failures are due to missing validation/filtering fields.
3. Implement `IndexReceiptsRequest` rules and its cross-field TRAINING check. Resolve `from_date`/`to_date` as company-timezone calendar dates and expose UTC half-open bounds.
4. Inject `LocationContext` and `CurrencyScaleResolverInterface` into `ReceiptController`; apply effective location membership before requested filters; default to SALE and non-training; honor legal code sets; escape `%` and `_`; include the frozen row allowlist and receipt-scale money strings.
5. Preserve exactly `{data:{data:[...],meta:{current_page,last_page,per_page,total,from,to}}}` and fixed `posted_at DESC` ordering.
6. Re-run the targeted tests until green, then format affected PHP.
7. Commit as one logical `Phase 1.1.1: ...` change.

### Task 2: Add scoped filter options and database indexes

**Files:**
- Create: `apps/api/app/Modules/POS/Presentation/Requests/ReceiptFilterOptionsRequest.php`
- Create: `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptFilterOptionsController.php`
- Modify: `apps/api/app/Modules/POS/routes.php`
- Create: `apps/api/database/migrations/*_add_receipt_reporting_indexes.php`
- Create: `apps/api/tests/Feature/POS/ReceiptFilterOptionsTest.php`
- Create: `apps/api/tests/Feature/POS/ReceiptReportingIndexesTest.php`

1. Write failing BT-8/BT-12 filter-option tests for route ordering, permissions, effective location intersection, inactive terminal fields/order, snapshot-cashier latest-name deduplication/order, empty-scope 200, and the single-nested envelope.
2. Write failing BT-16 structural migration tests for the two exact index names, columns, order, and production predicate.
3. Implement the request/controller and register `/pos/receipts/filter-options` before `/{id}`.
4. Add reversible plain `CREATE INDEX IF NOT EXISTS` / `DROP INDEX IF EXISTS` migration SQL using the exact frozen names.
5. Run the two targeted files, migrate forward/back/forward in the test database as appropriate, format, and commit as `Phase 1.1.2: ...`.

### Task 3: Repair least-privilege permission and navigation parity

**Files:**
- Modify: `apps/api/database/seeders/RolesAndPermissionsSeeder.php`
- Modify: `apps/web/src/hooks/permissionsMap.generated.ts` via the exporter
- Modify: `apps/web/src/hooks/usePermissions.ts`
- Modify: `apps/web/src/routes/index.tsx`
- Modify: `apps/web/src/components/organisms/Sidebar/Sidebar.tsx`
- Modify: `apps/web/src/features/compliance/pages/ComplianceExportPage.tsx`
- Modify/Create: focused permission, route, sidebar, and compliance tests beside those files

1. Add failing backend tests proving ACCOUNTANT gains exactly `pos.view_receipts`, `pos.view_reports`, and `deliveries.view`, with no `dashboard.owner` and no unrelated role drift.
2. Add failing frontend tests for the exact POS child-to-route mapping, accountant/cashier parity, empty-parent removal, compliance any-of route access, exact panel guards, and OP-23 fraud route gates/no navigation.
3. Add the three seeder grants only. Regenerate the frontend map with `(cd apps/api && php artisan permissions:export-frontend-map)` in the same commit.
4. Add the six identity permission entries, re-key each POS child per the frozen table while leaving parent and vouchers on `pos`, add the top-level Compliance export item immediately before Settings, and guard each export panel with its exact raw permission.
5. Change fraud-settings and fraud-alerts routes to their exact permissions without adding sidebar entries.
6. Run the focused backend/frontend tests, lint touched frontend files, and commit as `Phase 1.1.3: ...`.

### Task 4: Build the SALE receipt register and retire obsolete UI

**Files:**
- Create: receipt list DTO/type files under `apps/api/app/Modules/POS/Application/DTOs/` and generated web types as required
- Modify: `apps/web/src/features/pos/api/receiptApi.ts`
- Create: `apps/web/src/features/pos/pages/ReceiptListPage/ReceiptListPage.tsx`
- Create: focused tests under `apps/web/src/features/pos/pages/ReceiptListPage/__tests__/`
- Modify: `apps/web/src/routes/index.tsx`
- Modify: `apps/web/src/components/organisms/Sidebar/Sidebar.tsx`
- Modify: `apps/web/src/pages/POS/POSTransactions.tsx`
- Delete: `apps/web/src/pages/POS/components/ShiftReceiptsList.tsx`
- Modify: its barrel export and receipt API comments
- Modify: `apps/web/src/locales/en/pos.json`, `apps/web/src/locales/fr/pos.json`, and common navigation locale files
- Modify: the positive-refund audit ticket named by CL-7 after confirming the live behavior

1. Write failing FT-1/2/3/10/11/14/15/16 tests for today-in-company-timezone defaults, SALE-only request shape, training union semantics, URL state, tenant/location query keys, conditional location column, typed pagination, currency-aware totals, three empty states, and absence of type tabs/aggregate totals/actions.
2. Add a typed serializer that emits `location_ids[]`, fixed SALE codes, and TRAINING only with `include_training=true`; parse the frozen double-nested response without changing it.
3. Build the register with `ListPageLayout`, typed `DataTable`, `SearchInput`, and `OffsetPagination`; use only existing tokens, one emphasized date-window band, and one training row signal. Render explicit receipt currency via `apps/web/src/lib/format.ts`.
4. Route `/pos/receipts`, add the exact receipt sidebar child, rename `receiptSearch` to `receipts`, delete the seven obsolete void keys, and add the frozen EN/FR Wave-1 keys.
5. Delete `ShiftReceiptsList`, preserve `getShiftReceipts` with a `@see`, update `POSTransactions`, replace the quarantine comment with the list route/future-guard note, and close CL-7 only if source verification supports it.
6. Generate backend-derived TypeScript declarations where used, run the focused Vitest files plus scoped lint/typecheck, and commit in logical `Phase 1.1.4+` commits rather than mixing cleanup, locales, and page code when independently reviewable.

### Task 5: Verify and adversarially close M1

**Files:**
- Create: `apps/web/e2e/receipts-permissions.spec.ts`
- Update: `docs/handoff/HANDBACK-receipts-build-2026-08-12.md`
- Update: `docs/handoff/progress/receipts-build.progress.yaml`
- Create: `docs/handoff/reviews/receipts-build/M1-roundN.md`

1. Write the failing Playwright flow using `e2e/money-campaign/helpers.ts::loginAsRole`, then implement fixtures/selectors until the exact permissions flow passes.
2. Run all scoped Wave-1 backend and frontend tests, the one E2E file, scoped lint/typecheck, and capture empty/populated/training screenshots.
3. Append Wave-1 evidence and expected dead detail-link status to the handback; record short SHA in progress.
4. Run `scripts/adversarial-review.sh` for M1 over `7d85232cc...HEAD` with all M1 lenses. Fix and re-run targeted tests/review for up to five rounds; proceed only on ACCEPT.

## Milestone M2 — Wave 2

### Task 6: Replace receipt detail serialization with an exact safe resource

**Files:**
- Create: explicit receipt detail/line/payment/VAT/lineage Data or Resource classes under `apps/api/app/Modules/POS/`
- Create: `apps/api/app/Modules/POS/Application/Services/RefundReportingEnricher.php`
- Modify: `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php`
- Create: `apps/api/tests/Feature/POS/ReceiptShowResourceTest.php`
- Create/Modify: location-scope, aggregate-integrity, recursive-forbidden-key, and print-audit feature tests

1. Write failing BT-6/7/12/13/14/15/17 tests, including exact recursive key sets, null product IDs, snapshot product codes, no unit label/code, current-unit `quantity_decimals` fallback 4, receipt-scale money, both lineage directions, location 404 before print auditing, VAT/payment equations, and recursive `canonical_bytes` absence.
2. Implement the allowlisted detail boundary and widen eager loads only as frozen. Retain `returned_quantity` behavior.
3. Implement a bulk `RefundReportingEnricher`: one fiscal-event query per page/detail set, per-row `CanonicalPayloadReader`, `InvalidArgumentException|TypeError` degradation with warning, and legacy enum fallback; never catch `Throwable` or return canonical bytes.
4. Apply LocationContext non-enumerating scope to show/download/stream before `recordPrint()`.
5. Run the focused files, format, and commit as logical `Phase 1.2.1+` changes.

### Task 7: Build detail, refund register, and audited reprint UI

**Files:**
- Extend: `apps/web/src/features/pos/api/receiptApi.ts`
- Create: receipt detail/refund pages and focused component tests under `apps/web/src/features/pos/pages/`
- Modify: `apps/web/src/routes/index.tsx`, sidebar/tab navigation, voucher provenance links, locales
- Strengthen: `apps/web/src/features/vouchers/components/__tests__/ProvenanceSection.test.tsx`

1. Write failing FT-4 through FT-8 and FT-12/13 tests for allowlisted sections, currency formatting, quantity decimals, no unit symbols, lineage, refund fallback/alerts, three capability banner states, receipt-tab navigation, and a confirmation-gated single PDF request.
2. Build the detail page sections directly from safe DTOs; display the frozen aggregate integrity relation without inventing totals.
3. Build `/pos/receipts/refunds` as a single mixed REFUND+VOID server-paginated query with no client merge and no training flag.
4. Add the reprint confirmation dialog and use the existing audited PDF endpoint once per confirmation.
5. Heal both voucher receipt links and strengthen provenance route tests; add frozen EN/FR Wave-2 keys.
6. Run focused tests, scoped lint/typecheck, and commit as logical `Phase 1.2.n` changes.

### Task 8: Verify M2 and evaluate the conditional A0 gate

1. Add/run the three Wave-2 Playwright flows from the dispatch using the required login helper.
2. Capture detail, refund-banner, refund-register, and reprint-dialog screenshots.
3. Run scoped Wave-2 backend/frontend tests and append evidence to the handback/progress file.
4. Run adversarial review for M2 over the full base range, fix up to five rounds, and require ACCEPT.
5. Inspect the landed device/A0 contract exactly as the dispatch directs. If absent or contradictory, mark M2b `blocked_owner` and continue to M3 with Waves 1–2 delivered; do not invent chain captions.

## Milestone M2b — Conditional Wave 2b

### Task 9: Add the chain-health panel only if A0 is honest and landed

1. Write the frozen conditional backend/frontend tests against the actual A0 schema and semantics.
2. Add the location-scoped terminal selector and chain-health panel without changing device code or authoring new semantics.
3. Add EN/FR captions only after the contract is known; run focused tests and commit as `Phase 1.3.n: ...`.
4. Append evidence and run the M2b adversarial review to ACCEPT. If the gate is not satisfied, leave this task unimplemented and document `blocked_owner` rather than stopping the whole lane.

## Milestone M3 — Whole-lane closure

### Task 10: Run final scoped verification and produce the handback

**Files:**
- Finalize: `docs/handoff/HANDBACK-receipts-build-2026-08-12.md`
- Finalize: `docs/handoff/progress/receipts-build.progress.yaml`
- Create: `docs/handoff/reviews/receipts-build/M3-roundN.md`

1. Run the exact dispatch preflight environment:
   `PREFLIGHT_TEST_PATHS='tests/Feature/POS tests/Feature/Compliance tests/Unit/POS' PREFLIGHT_VITEST_PATHS='src/features/pos src/features/vouchers/components/__tests__ src/components/organisms/Sidebar' ./scripts/preflight.sh`.
2. Run the exact four Playwright files, scoped lint/typecheck, migration/index checks, permission-map drift check, forbidden-key scan, currency-difference assertion, no-aggregate scan, and confirm `apps/pos/**` is untouched.
3. Use react-doctor for the changed React surface and fix in-scope diagnostics.
4. Run the M3 adversarial review over `7d85232cc...HEAD`, repair/retest for up to five rounds, and require ACCEPT.
5. Update the handback with commits, commands/results, screenshots, review verdicts, residual OP-23/OI-17/A0 facts, and explicit no-push/no-merge status. Mark progress complete only after verification evidence is current.
