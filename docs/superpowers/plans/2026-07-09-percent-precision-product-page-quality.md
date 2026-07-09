# Percent Precision Product Page Quality Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove percent precision drift, fix product pricing input reformat/caret resets, and prepare the product view/edit layout unification without altering fiscal canonical bytes.

**Architecture:** First establish a single string-safe percent formatter and backend string contracts. Then make backend storage and serialization safe with tenant preflight migrations before frontend display and draft-input work. POS fiscal serialization is isolated as the last Track A phase, and Track B is limited to an owner-reviewed shared-section extraction proposal until sign-off.

**Tech Stack:** Laravel 12, PHP 8.4, PostgreSQL tenant migrations, bcmath, Spatie TypeScript Transformer, Vite, React 19, Vitest, Testing Library, `apps/web/src/lib/decimal.ts` bc helpers.

## Global Constraints

- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp.precision` on branch `fix/percent-precision`, based on local `dev`.
- Do not merge to `dev`; do not push `origin/dev`.
- Tests by path only. Do not run full suites.
- No new dependencies.
- Frontend decimal arithmetic must use `apps/web/src/lib/decimal.ts` helpers. Do not import `Big` directly at new call sites.
- Do not use `parseFloat`, `Number`, or native numeric operators for money, quantities, or percents.
- Percent values store and validate at fixed 2dp. Fraction rates such as `eco_tax_rate` and `withholding_rate` stay `decimal(...,4)`.
- Every percent-column narrowing needs validators/writers first plus a tenant preflight rejecting rows where `value != round(value, 2)`.
- Before any destructive percent-column narrowing is promoted, run a read-only fleet scan across all tenant databases. The migration-level preflight is a last guard, not the first discovery mechanism.
- POS receipt and Z-report `tax_rate` affect signed/hash bytes. A4 is last, gated by golden fixture parity and chokepoint scripts.
- POS receipt and Z-report canonical `tax_rate` bytes are frozen for this work. A true stored/wire type change is a versioned fiscal change and is out of scope.
- `Coupon.discount_value`, `Promotion.discount_value`, and `services.base_price` are polymorphic percent-or-money fields. Defer structural split unless doing dual-read/write plus backfill.
- Track B is a shared-section extraction refactor. Build only after before/after proposal and owner sign-off.
- Each milestone is implementation, Opus adversarial review, reconciliation, then continue.

---

## Phase M1: Plan Review Gate

**Files:**
- Create: `docs/superpowers/plans/2026-07-09-percent-precision-product-page-quality.md`

**Interfaces:**
- Produces: a phase-by-phase checklist for A0, A1/A2, C1-C4, A3, A4, Track B proposal, and final verification.

- [ ] **Step 1: Save the implementation plan**

  Save this file under `docs/superpowers/plans/`.

- [ ] **Step 2: Run Opus adversarial review**

  Run:

  ```bash
  claude -p --model claude-opus-4-8 "Review docs/superpowers/plans/2026-07-09-percent-precision-product-page-quality.md against docs/sessions/CODEX-TASK-precision-and-pricing-unify.md, docs/superpowers/audits/2026-07-09-precision-plan-codex-review.md, docs/superpowers/audits/2026-07-09-percent-precision-audit.md, docs/superpowers/audits/2026-07-09-input-reformat-caret-audit.md, CLAUDE.md rule 19, and docs/architecture/precision-contract.md. Be adversarial. Identify blockers, unsafe sequencing, fiscal-byte risks, missing tests, or violations of Rev 2 constraints. Do not edit files."
  ```

- [ ] **Step 3: Reconcile review**

  If Opus reports blockers, edit this plan before implementation. If it reports implementation caveats, carry them into the relevant phase.

## Phase A0: String-Safe Percent Formatter and Type Guardrails

**Files:**
- Modify: `apps/web/src/lib/format.ts`
- Test: `apps/web/src/lib/format.test.ts` or the existing local format test path
- Modify call sites that define duplicate local `formatPercent` helpers:
  - `apps/web/src/components/organisms/ProductPricingCard/ProductPricingCard.tsx`
  - `apps/web/src/components/organisms/LandedCostBreakdown/LandedCostBreakdown.tsx`

**Interfaces:**
- Produces: `formatPercent(value: string | number | null | undefined, options?: { maximumFractionDigits?: number }): string`
- Produces: `formatPercentage` either removed or re-pointed to the same string-safe implementation so no float-laundering percent formatter remains.
- Behavior: string-safe, trims trailing zeros to at most 2dp, returns `19%` for `19.0000`, `19.5%` for `19.50`, `19.25%` for `19.2500`, and `0%` for empty values.

- [ ] **Step 1: Write failing formatter tests**

  Add tests asserting:

  ```ts
  expect(formatPercent('19.0000')).toBe('19%')
  expect(formatPercent('19.5000')).toBe('19.5%')
  expect(formatPercent('19.2500')).toBe('19.25%')
  expect(formatPercent('0.0000')).toBe('0%')
  expect(formatPercent(null)).toBe('0%')
  ```

- [ ] **Step 2: Verify RED**

  Run:

  ```bash
  pnpm --filter @autoerp/web test apps/web/src/lib/format.test.ts
  ```

  Expected: fails because `formatPercent` is missing or the old formatter emits fixed `19.00%`.

- [ ] **Step 3: Implement formatter**

  Implement with string operations and existing decimal-safe utilities only. Do not call `parseFloat`, `Number`, or `Intl.NumberFormat` for percent canonicalization. Remove the old unsafe `formatPercentage` body or make it delegate to `formatPercent`.

- [ ] **Step 4: Replace duplicate helpers**

  Import `formatPercent` at the local duplicate helper files and delete local helper implementations.

- [ ] **Step 5: Verify GREEN and review**

  Run the formatter test by path. Then run Opus review focused on A0:

  ```bash
  claude -p --model claude-opus-4-8 "Review the A0 diff in this worktree. Requirements: string-safe formatPercent, trimmed max 2dp, no parseFloat/Number/native float laundering for percents, no new deps, duplicate local formatPercent helpers removed. Return blockers only first."
  ```

## Phase A1/A2: Backend Percent Storage, Preflight, and String Serialization

**Files:**
- Modify model casts:
  - `apps/api/app/Modules/Taxation/Domain/Entities/TaxConfiguration.php`
  - `apps/api/app/Modules/Service/Domain/Service.php`
  - `apps/api/app/Modules/Workshop/Bundle/Domain/ServiceBundle.php`
  - `apps/api/app/Modules/Workshop/WorkOrder/Domain/WorkOrderLine.php`
  - `apps/api/app/Modules/Product/Domain/Product.php`
- Modify validators/writers before migrations:
  - service, workshop bundle, workshop work order line, product margin request/service paths identified by `rg "tax_rate|target_margin_override|minimum_margin_override"`
- Create tenant corrective migration under `apps/api/database/migrations/tenant/`.
- Modify serialization/services:
  - `apps/api/app/Modules/Product/Application/Services/MarginService.php`
  - `apps/api/app/Modules/Pricing/Presentation/Controllers/PricingController.php`
  - `apps/api/app/Modules/POS/Presentation/Controllers/DiscountController.php`
  - `apps/api/app/Modules/Taxation/Domain/Entities/WithholdingCertificate.php`
  - `apps/api/app/Modules/Taxation/Domain/ValueObjects/WithholdingCalculation.php`
  - `apps/api/app/Modules/Taxation/Application/Services/CertificatePDFService.php`
  - `apps/api/app/Modules/Taxation/Application/Services/TEJExportService.php`
  - `apps/api/app/Modules/Billing/Application/Services/InvoiceService.php`
  - `apps/api/app/Modules/Document/Application/Services/FacturXService.php` only with regression tests and no legal-byte ambiguity.
- Create read-only scan command before narrowing:
  - `apps/api/app/Console/Commands/ScanPercentScaleDrift.php` or existing command namespace equivalent.
- Regenerate DTO types after `#[TypeScript]` changes.

**Interfaces:**
- Produces: percent value columns/casts at 2dp, fraction columns unchanged, public percent methods returning strings, and a tenant preflight migration that aborts unsafe narrowing.
- Produces: a deploy-gating fleet scan report command; narrowing migration rollback widens columns only and is not a data-recovery path for truncated decimals.

- [ ] **Step 1: Write failing backend tests**

  Add or extend path tests for:
  - `TaxConfiguration` resource serializes `percentage_rate` as a `decimal:2` string.
  - `Service`, `ServiceBundle`, `WorkOrderLine`, and product margin writes reject >2dp percent values before migration narrowing.
  - Tenant migration preflight throws on a seeded `19.125` percent-value row.
  - `WithholdingCertificate::getRateAsPercentage()` and `WithholdingCalculation::ratePercentage` return strings like `5.00`.
  - `CertificatePDFService` renders withholding rate as `5.00%`, not `5%`.
  - `TEJExportService` emits `TauxRetenue` with fixed expected precision.
  - `MarginService` returns string suggested prices/margins.
  - `PricingController` suggested-price/margin payload returns strings at the expected scale.
  - `DiscountController` discount-limit payload returns strings and never float-casts decimal limits.
  - `InvoiceService::generateInvoiceFromSubscription()` uses bcmath and writes scale-correct money strings.
  - FacturX regression preserves expected XML numeric strings if touched.

- [ ] **Step 2: Verify RED by path**

  Run only touched tests, for example:

  ```bash
  cd apps/api
  php artisan test tests/Feature/Service/IngressPrecisionTest.php
  php artisan test tests/Feature/Workshop/IngressPrecisionTest.php tests/Feature/Workshop/Bundle/TaxRateScaleConsistencyTest.php
  php artisan test tests/Feature/Product/ProductDataMarginSerializationTest.php tests/Unit/Product/MarginServiceTest.php
  php artisan test tests/Feature/Pricing/CheckMarginTest.php tests/Feature/Pricing/MarginCheckPrecisionTest.php
  php artisan test tests/Feature/POS/ReceiptPaymentServiceToleranceTest.php
  php artisan test tests/Feature/Taxation/WithholdingPrecisionTest.php tests/Unit/Taxation/WithholdingCalculationServiceTest.php
  php artisan test tests/Feature/Billing/CreateManualInvoicePrecisionTest.php
  php artisan test tests/Unit/Document/FacturXServiceTest.php
  ```

- [ ] **Step 3: Implement validators/writers and casts**

  Change percent-value validators/writers and casts to `decimal:2`. Keep `eco_tax_rate`, `withholding_rate`, and withholding rule `rate` at `decimal:4`. Treat cast changes and narrowing migration as one atomic deploy unit; do not land casts separately from the migration/preflight guard.

- [ ] **Step 4: Implement read-only fleet scan**

  Add a command that runs the same SQL predicate across all tenant databases before the migration is promoted:

  ```sql
  WHERE value IS NOT NULL AND value <> round(value, 2)
  ```

  It must report `tenant/table/column/count` and exit non-zero when offenders exist. This command is the fleet-wide deployment gate.

- [ ] **Step 5: Implement preflight migration**

  Before narrowing, query each target column in SQL with `WHERE value IS NOT NULL AND value <> round(value, 2)`. Throw with table/column/count details. Then alter to `decimal(...,2)`. The `down()` method widens columns only; it must document that rollback cannot restore discarded decimals.

- [ ] **Step 6: Implement string return contracts**

  Remove float returns/casts from listed services/controllers. Use bcmath and existing `CurrencyScale`/percent patterns.

- [ ] **Step 7: Transform DTOs and verify**

  Run path tests, `php artisan typescript:transform` if DTOs changed, and PHPStan only after the phase diff is green enough:

  ```bash
  cd apps/api
  ./vendor/bin/phpstan analyse --level=8 app/Modules/Product app/Modules/Pricing app/Modules/Taxation app/Modules/Billing app/Modules/Document
  ```

- [ ] **Step 8: Opus review**

  Run Opus with the `fiscal-pos-reviewer` lens for FacturX/withholding and general backend precision review. Reconcile blockers before continuing.

## Phase C1-C4: Draft Inputs and Caret Regression

**Files:**
- Create: `apps/web/src/components/atoms/DraftMoneyInput.tsx`
- Create: `apps/web/src/components/atoms/DraftQuantityInput.tsx`
- Export from atoms barrel if one exists.
- Modify local duplicate draft inputs:
  - `apps/web/src/features/catalog/components/RecipeLineEditor.tsx`
  - `apps/web/src/features/catalog/components/VariantEditor.tsx`
  - `apps/web/src/features/catalog/components/ModifierGroupFormPage.tsx`
- Modify buggy fields:
  - `apps/web/src/features/inventory/ProductForm.tsx`
  - `apps/web/src/features/settings/components/InventorySettings.tsx`
  - `apps/web/src/features/documents/components/DocumentLineEditor.tsx`
- Test colocated Vitest files for ProductForm, InventorySettings, and DocumentLineEditor behavior.

**Interfaces:**
- Produces: draft inputs that pass through `min`, `max`, `placeholder`, `error`, `disabled`, and `className`, commit on blur, and resync from `initialValue` only while unfocused.

- [ ] **Step 1: Write failing component tests**

  Tests must prove:
  - Product margin and TTC fields keep typed multi-digit drafts without mid-edit reformat.
  - Blur commits the derived `sale_price`.
  - Inventory settings allow empty while typing and preserve `0` on blur.
  - DocumentLineEditor total-entry mode does not feed rounded totals back on each keystroke.
  - Shared draft input resyncs when `initialValue` changes while unfocused and does not overwrite focused drafts.

- [ ] **Step 2: Verify RED by path**

  Run:

  ```bash
  pnpm --filter @autoerp/web test apps/web/src/components/atoms/DraftMoneyInput.test.tsx
  pnpm --filter @autoerp/web test apps/web/src/features/inventory/ProductForm.test.tsx
  pnpm --filter @autoerp/web test apps/web/src/features/settings/components/InventorySettings.test.tsx
  pnpm --filter @autoerp/web test apps/web/src/features/documents/components/DocumentLineEditor.test.tsx
  ```

- [ ] **Step 3: Extract shared draft inputs**

  Implement the shared components by wrapping existing `MoneyInput` and `QuantityInput`. Keep result semantics unchanged; only move commit timing to blur.

- [ ] **Step 4: Replace local duplicates and buggy fields**

  Replace the three local `Blur*Input` implementations and adopt draft inputs in ProductForm pricing, InventorySettings integer fields, and DocumentLineEditor total mode.

- [ ] **Step 5: Verify GREEN and Opus review**

  Run the same path tests plus ESLint on touched paths if available. Then run Opus review focused on C1-C4 draft behavior and precision contract violations.

## Phase A3: Web Display Sweep

**Files:**
- Modify raw percent display and `toFixed` sites listed in `docs/superpowers/audits/2026-07-09-percent-precision-audit.md`.
- High-risk files include:
  - `apps/web/src/components/atoms/TaxConfigurationSelect/TaxConfigurationSelect.tsx`
  - `apps/web/src/hooks/useTaxConfigName.ts`
  - `apps/web/src/features/settings/TaxSettingsPage.tsx`
  - `apps/web/src/features/inventory/components/pricing/PricingIntelligencePanel.tsx`
  - `apps/web/src/features/inventory/components/pricing/ProductPricingCard.tsx`
  - `apps/web/src/features/inventory/components/pricing/PriceInputWithMargin.tsx`
  - `apps/web/src/features/services/pages/ServiceDetailPage.tsx`
  - `apps/web/src/features/services/pages/ServiceListPage.tsx`
  - `apps/web/src/features/taxation/withholding/**`
  - `apps/web/src/features/documents/**`

**Interfaces:**
- Consumes: A0 `formatPercent` and `lib/decimal.ts` bc helpers.
- Produces: percent displays show trimmed fixed-2dp semantics without raw `19.0000%`; service polymorphic fields stop rendering money `base_price` as percent.

- [ ] **Step 1: Write/extend failing tests**

  Add targeted assertions for `TVA 19%`, product pricing margin formatting, service percentage-vs-money display, and withholding/tolerance percent displays.

- [ ] **Step 2: Verify RED by path**

  Run only the test files created or modified.

- [ ] **Step 3: Implement display sweep**

  Replace raw `{rate}%` and percent `toFixed` with `formatPercent`. Rewrite `ProductPricingCard` and other live math with `bc*` helpers. Fix `PriceInputWithMargin` parseFloat usage.

- [ ] **Step 4: Verify GREEN and Opus review**

  Run touched Vitest paths and Opus review focused on frontend precision and display-only behavior.

## Phase A4: POS Fiscal Device Sweep

**Files:**
- Modify only after A0-A3/C are reconciled:
  - `apps/pos/src/lib/offline/types.ts`
  - `apps/pos/src/lib/offline/endOfDayPreview.ts`
  - `apps/pos/src/lib/offline/zReportService.ts`
  - `apps/pos/src/api/reportApi.ts`
  - `apps/pos/src/lib/offline/receiptService.ts`
  - POS discount and modifier modal files listed in the audit.

**Interfaces:**
- Produces: display/input precision fixes without changing existing signed/hash bytes. Receipt and Z-report canonical `tax_rate` bytes are frozen; any canonical type change is blocked as a versioned fiscal change outside this branch.

- [ ] **Step 1: Write parity/fiscal tests first**

  Add tests showing existing receipt and Z-report canonical JSON/hash bytes are unchanged for existing fixtures.

- [ ] **Step 2: Verify RED only where new protections are missing**

  Run POS path tests, fixture parity, and chokepoint scripts:

  ```bash
  pnpm --filter @autoerp/pos test apps/pos/src/lib/offline/endOfDayPreview.test.ts
  pnpm --filter @autoerp/pos test apps/pos/src/lib/offline/zReportService.test.ts
  apps/pos/scripts/check-fiscal-fixture-parity.sh
  apps/api/scripts/check-saleReceipt-chokepoints.sh
  ```

- [ ] **Step 3: Implement display/input-only POS changes**

  Do not alter stored/wire `tax_rate` or hash input bytes. Format at display only. Add chokepoint comments near frozen canonical fields so future work does not convert `number` to `string` without a versioned fiscal migration.

- [ ] **Step 4: Verify parity and Opus fiscal review**

  Re-run parity/chokepoint scripts and Opus with explicit `fiscal-pos-reviewer` instructions.

## Phase B: Shared Product View/Edit Section Proposal Gate

**Files:**
- Read-only before sign-off:
  - `apps/web/src/features/inventory/ProductForm.tsx`
  - `apps/web/src/features/inventory/ProductDetailPage.tsx`
  - `apps/web/src/features/inventory/viewSections.ts`
  - `apps/web/src/features/inventory/components/ProductEditHero.tsx`
  - `apps/web/src/features/inventory/components/ProductHero.tsx`

**Interfaces:**
- Produces: before/after proposal describing shared sections, view/edit modes, pricing surface collapse, RHF coupling, media/barcode behavior, and vertical gating.

- [ ] **Step 1: Draft proposal only**

  Save proposal to `docs/sessions/HANDOFF-product-page-quality.md` or a linked session note. Include owner decision points.

- [ ] **Step 2: Opus review**

  Ask Opus to challenge the extraction plan and identify hidden coupling.

- [ ] **Step 3: Stop for owner sign-off**

  Do not implement Track B until sign-off is recorded.

## Final Verification and Handoff

**Files:**
- Create: `HANDOFF-product-page-quality.md` at the requested location if owner insists; otherwise prefer `docs/sessions/HANDOFF-product-page-quality.md` per CLAUDE.md root-file rule.

- [ ] **Step 1: Run by-path verification**

  Run all touched PHP and Vitest paths, PHPStan level 8 on touched PHP modules, TypeScript typecheck, ESLint, fiscal fixture parity, and chokepoint scripts.

- [ ] **Step 2: Browser check**

  Start the web dev server. Verify product form displays `TVA 19%`, pricing fields do not reformat mid-edit, caret remains usable, and view/edit layout status matches Track B gate.

- [ ] **Step 3: Commit**

  Commit with the required footer:

  ```text
  Co-Authored-By: Codex <noreply@openai.com>
  ```

- [ ] **Step 4: Push branch only**

  Push `fix/percent-precision`. Do not merge and do not push `origin/dev`.
