# HANDOFF - Product Page Quality

Date: 2026-07-09
Branch: `fix/percent-precision`
Worktree: `/Users/houssamr/Projects/syneriva/apps/erp.precision`

## Status

A0 through A4 are implemented and committed. Track B is intentionally not
implemented because Rev 2 requires an owner-approved shared-section extraction
proposal before code changes.

Completed commits:

- `c6ced1db2` - Phase 0.1.0: Add safe percent formatter
- `e4fe7e8d6` - Phase 0.1.1: Align backend percent precision
- `bc96605c1` - Phase 0.1.2: Add draft pricing inputs
- `12a71f79b` - Phase 0.1.3: Normalize web percent displays
- `216fe493f` - Phase 0.1.4: Format POS fiscal rates at display

## Scope Notes

- `eco_tax_rate` and `withholding_rate` remain fraction columns/casts where
  applicable; they were not narrowed to 2dp percent-value storage.
- POS receipt and Z-report `tax_rate` fields remain numeric stored/wire values.
  A4 formats only display cells in POS report modals.
- `services.base_price`, coupon, and promotion polymorphic value splits remain
  deferred; no half-split was attempted.
- Track B is proposal-only pending sign-off.

## A4 POS Fiscal Gate

A4 added a POS-local string-safe `formatPercent` helper and used it only in:

- `apps/pos/src/components/pos/EndOfDayPreviewModal.tsx`
- `apps/pos/src/components/pos/XReportModal.tsx`
- `apps/pos/src/components/pos/ZReportModal.tsx`

Canonical comments were added near numeric `tax_rate` DTO/build boundaries in:

- `apps/pos/src/lib/offline/types.ts`
- `apps/pos/src/lib/offline/endOfDayPreview.ts`
- `apps/pos/src/lib/offline/zReportService.ts`
- `apps/pos/src/api/reportApi.ts`

Opus fiscal POS review verdict: no blockers. Non-blocking formatter test
coverage suggestions were reconciled in `apps/pos/src/lib/format.test.ts`.

## Track B Proposal - Shared View/Edit Product Sections

Owner sign-off required before implementation.

### Current State

- Edit mode uses local `EDITOR_SECTIONS` and `HERO_BLOCKS` in
  `apps/web/src/features/inventory/ProductForm.tsx`.
- View mode uses a separate `PRODUCT_DETAIL_SECTIONS` registry from
  `apps/web/src/features/products/editor/viewSections.ts`.
- Edit hero behavior is not passive layout: `ProductEditHero` owns barcode
  lookup, scanner handoff, manual refresh, and image upload/buffering.
- View renders `ProductHero` plus a separate `PricingIntelligencePanel`; edit
  renders `ProductEditHero` ready-to-sell strip plus a separate editable
  Pricing & Tax section. This is the duplicated pricing surface.
- View automotive rendering is data-presence gated; edit automotive rendering
  is vertical gated. Pharmacy and loyalty sections exist in edit but not in the
  current view registry.

### Proposed Target

The signed-off implementation would create a shared product editor/view section
package, but would not reuse the edit registry directly.

1. Define shared section keys and ordering:
   `hero`, `general`, `pricing`, `inventory`, `automotive`, `pharmacy`,
   `loyalty`, `suppliers`, `media`, `variants`.
2. Extract section components with a `mode: 'view' | 'edit'` prop and
   mode-specific adapters:
   - edit adapter supplies RHF `control`, `register`, `watch`, `setValue`,
     errors, draft input commit handlers, image buffer handlers, and barcode
     lookup callbacks.
   - view adapter supplies resolved product values and read-only display
     formatters.
3. Keep the hero as a shared layout shell with separate mode content:
   - edit content preserves `ProductEditHero` barcode lookup and upload flows.
   - view content displays the same slots read-only and links to existing image
     assets without scanner/upload handlers.
4. Replace duplicated pricing with one canonical pricing section:
   - read-only mode shows WAC/cost, margin verdict/traffic-light, and
     cost-derived floor only when `pricing.view_cost_prices` allows it; HT,
     TTC, max discount, and tax remain non-cost facts.
   - edit mode preserves Track C draft behavior for margin/HT/TTC and keeps
     HT-canonical `sale_price`.
   - edit mode must also honor `pricing.view_cost_prices`; users without the
     permission must not see WAC/cost/margin-only information through the
     collapsed pricing section.
   - the margin/HT/TTC interlock should remain in a single pricing-controller
     adapter/hook owned by the edit adapter, so extraction does not split the
     Track C blur-commit behavior across independent child components.
5. Align section gating deliberately:
   - automotive should render by vertical in edit; in view, either render the
     same section with empty read-only states or document owner preference to
     hide empty vertical sections.
   - pharmacy and loyalty need explicit owner decisions for view mode because
     edit has them and current view does not; both must remain vertical/module
     gated at least as strictly as edit mode.
   - variants remain edit-only until a persisted product exists, unless owner
     wants read-only variants in detail.
6. Keep the shared hero from becoming a second pricing surface:
   - the hero may show identity, barcode, enrichment, media, and non-sensitive
     readiness cues.
   - price/cost/margin facts belong in the canonical pricing section unless the
     owner explicitly approves a non-duplicative hero summary.

### Before/After Layout

Before:

- View: header actions -> enrichment card -> tabs -> `ProductHero` ->
  Product Information -> Pricing Intelligence -> Stock Levels -> optional
  Automotive -> Metadata.
- Edit: page header/actions -> `ProductEditHero` with ready-to-sell strip ->
  optional enrichment/opt-in -> section nav -> General -> Pricing & Tax ->
  Inventory -> optional Automotive -> optional Pharmacy -> optional Loyalty ->
  Suppliers -> Media -> optional Variants.

After:

- View details tab: shared hero shell and signed-off shared section stack in
  read-only mode, with the pricing section appearing once.
- Edit: same shared sections that render in both modes appear in the same order,
  in edit mode, preserving barcode/image/enrichment and draft-input behavior.

### Acceptance Criteria For Track B

- Shared sections that render in both view and edit keep identical ordering and
  do not duplicate pricing.
- Pricing appears once in both modes.
- `pricing.view_cost_prices` hides cost/WAC/margin-only information in both view
  and edit modes.
- Barcode lookup, scanner flow, manual refresh, image upload/buffering, and
  enrichment states keep current edit behavior.
- Product vertical/module gates are explicitly tested for default, Otospex,
  parapharmacy, and loyalty-enabled cases.
- ProductForm draft inputs from Track C remain blur-commit and do not reformat
  while focused.

### Owner Sign-Off Questions

1. In view mode, should vertical sections render as empty read-only sections
   for layout parity, or hide when the product has no data?
2. Should pharmacy and loyalty sections render in product detail when their
   vertical/module gates are active, or remain edit-only?
3. Should variants appear read-only in product detail to match the edit stack,
   or remain edit-only because variant management requires a persisted product?
4. Should media in view use the exact edit hero media slot only, or keep a
   separate media section in the shared stack?
5. May the hero show any non-editable price summary, or must all price/cost/
   margin facts live only in the canonical pricing section?

## Final Verification

Backend:

- `php artisan test tests/Feature/Billing/CreateManualInvoicePrecisionTest.php tests/Feature/POS/DiscountAdminBypassParityTest.php tests/Feature/Precision/PercentIngressGuardTest.php tests/Feature/Pricing/CheckMarginTest.php tests/Feature/Pricing/MarginCheckPrecisionTest.php tests/Feature/Workshop/Bundle/TaxRateScaleConsistencyTest.php tests/Feature/Workshop/IngressPrecisionTest.php tests/Unit/Product/MarginServiceTest.php tests/Unit/Shared/Precision/PercentScaleDriftScannerTest.php tests/Unit/Taxation/CertificatePDFServiceTest.php tests/Unit/Taxation/WithholdingCalculationServiceTest.php`
  - exited 0; 233 assertions. Existing PHPUnit doc-comment/file-get-content
    warnings were emitted.
- `./vendor/bin/phpstan analyse --level=8 ...`
  - 21 changed backend source paths, no errors.
- `./vendor/bin/pint --test ...`
  - changed backend PHP paths passed.

Web:

- `pnpm --filter @autoerp/web test src/lib/format.test.ts src/components/atoms/DraftMoneyInput.test.tsx src/components/atoms/TaxConfigurationSelect/TaxConfigurationSelect.test.tsx src/features/inventory/ProductForm.test.tsx src/features/inventory/components/pricing/PriceInputWithMargin.test.tsx src/features/inventory/components/pricing/PricingIntelligencePanel.test.tsx src/features/settings/TaxSettingsPage.test.tsx src/features/settings/components/InventorySettings.test.tsx src/features/treasury/components/ToleranceSettingsDisplay.test.tsx src/features/documents/components/DocumentTotals.test.tsx src/features/documents/components/__tests__/DocumentLineEditor.test.tsx src/features/withholding/components/WithholdingPreviewModal.test.tsx src/features/withholding/pages/WithholdingRulesPage.test.tsx src/features/withholding/pages/SalesWithholdingTrackingPage.test.tsx src/features/withholding/WithholdingCertificatesList.test.tsx src/features/withholding/WithholdingCertificateDetail.test.tsx`
  - 16 files, 133 tests passed. Existing `--localstorage-file` and React
    `act(...)` warnings were emitted.
- `pnpm --filter @autoerp/web typecheck`
  - passed.
- `pnpm --filter @autoerp/web exec eslint . --quiet`
  - passed.

POS:

- `pnpm --filter @autoerp/pos test src/lib/offline/__tests__/endOfDayPreview.test.ts src/lib/offline/__tests__/zReportService.test.ts src/lib/format.test.ts src/components/pos/EndOfDayPreviewModal.test.tsx src/components/pos/FiscalReportModals.test.tsx`
  - 5 files, 74 tests passed.
- `pnpm --filter @autoerp/pos typecheck`
  - passed.
- `pnpm --filter @autoerp/pos exec eslint . --quiet`
  - passed after removing one stale `react-doctor/exhaustive-deps` inline rule
    token from `ProductGrid.tsx`; the active `react-hooks/exhaustive-deps`
    disable remains.
- `apps/pos/scripts/check-fiscal-fixture-parity.sh`
  - 2 files, 29 tests passed.
- `apps/api/scripts/check-saleReceipt-chokepoints.sh`
  - manifest receiver validator passed; 8 chokepoint call sites reconciled.

Browser check:

- Vite served the web app at `http://localhost:5174/`.
- One-off Playwright browser check with mocked local API data opened
  `/inventory/products/new`.
- Verified `TVA 19% (19%)` is visible and raw `19.0000%` is absent.
- Verified Margin, HT, and TTC fields keep literal typed drafts while focused:
  `12.345`, `123.456`, and `146.912`.
- Verified edit section anchors for General/Pricing/Inventory are present.

Track B:

- Proposal reviewed by Opus; blockers reconciled.
- Implementation remains blocked pending owner answers to the sign-off questions
  above.
