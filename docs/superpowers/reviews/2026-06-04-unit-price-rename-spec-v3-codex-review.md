# Adversarial Design Re-Review: Unit-Price Disambiguation Rename (Spec v3)

**Date:** 2026-06-04
**Reviewer:** Codex (adversarial)
**Spec:** docs/superpowers/specs/2026-06-03-unit-price-disambiguation-rename-design.md
**Prior review:** docs/superpowers/reviews/2026-06-03-unit-price-rename-spec-codex-review.md

## VERDICT
REQUEST-CHANGES — Confidence: High
v3 removes the signed-byte rename risk for SALE_RECEIPT/ACCOUNT_CHARGE by keeping the canonical key, but it overstates ACCOUNT_CHARGE value-correction safety and still under-specifies backend POS seam/read surfaces where bare `unit_price` will survive.

## Summary
BLOCKERs: 1, MAJORs: 2, MINORs: 2, NITs: 1

## BLOCKER Findings

### B1 — ACCOUNT_CHARGE value correction is not safe while the facture bridge consumes canonical `unit_price` as document net
- **Spec / code:** Spec §4.3 says "no consumer ... depends on the current net value" and that the correction is "safe" under unchanged key; code shows `apps/api/app/Modules/Document/Application/Projections/DocumentAccountChargeFactureBridge.php:55` passes `lineItems` from canonical bytes into `POSAccountChargeDraftService`; `apps/api/app/Modules/Document/Application/Services/POSAccountChargeDraftService.php:88` and `:220` write `$lineItem['unit_price']` into `document_lines.unit_price`; `apps/api/tests/Feature/Fiscal/DocumentAccountChargeFactureBridgeTest.php:96` asserts `DocumentLine::$unit_price === '100.000'`.
- **What is wrong:** The UI authoring function is not wired (`rg "authorAccountCharge\\("` outside tests found only `apps/pos/src/lib/accountCharge/accountChargeService.ts:432`), and the validator is line-tax-agnostic (`FiscalPayloadConstraintValidator.php:1328`, `:1383`). But server projections are already registered consumers. For B2B `invoice_classification=b2b_facture_draft_requested`, the document bridge currently treats canonical `line_items[].unit_price` as the B2B document line price. If §4.3 changes authored `unit_price` from net to inclusive without changing this bridge, future ACCOUNT_CHARGE facture drafts will store inclusive prices in the document net field.
- **What to change:** Before allowing §4.3, specify and test the facture bridge translation: canonical inclusive `unit_price` must not be copied to `document_lines.unit_price_excl_tax`; derive the document net unit value from the canonical net fields or explicitly defer facture-draft projection until the charge-to-account finalization work defines the conversion. Update `POSAccountChargeDraftService`, `DocumentAccountChargeFactureBridgeTest`, and replay/idempotency checks accordingly.

## MAJOR Findings

### M1 — Zone-2 seam inventory misses backend POS APIs and projections that persist or re-expose inclusive `unit_price`
- **Spec / code:** Spec §1 says Zone 2 is "POS→backend ingestion" and §7 calls the table "Authoritative scope"; code shows additional backend POS surfaces: `apps/api/app/Modules/POS/Presentation/Requests/AddOrderLineRequest.php:46`, `apps/api/app/Modules/POS/Presentation/Controllers/OrderController.php:171`, `apps/api/app/Modules/POS/Application/Services/OrderManagementService.php:245`, `apps/api/app/Modules/POS/Presentation/Requests/HoldOrderRequest.php:43`, `apps/api/app/Modules/POS/Application/Services/HeldOrderService.php:99`, `apps/api/app/Modules/POS/Domain/HeldOrder.php:195`, `apps/api/app/Modules/POS/Presentation/Resources/OrderLineResource.php:34`, `apps/api/app/Modules/POS/Application/Services/OrderToReceiptService.php:49`, `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:314`, `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:799`, `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:1069`, and `apps/api/resources/views/pos/receipt.blade.php:398`.
- **What is wrong:** v3 names `pos_order_lines`, `OrderLineData`, `pos_receipt_lines`, `ReceiptLine`, `ReceiptCreationService`, `ReceiptFinalizationService`, `StoreReceiptRequest`, and `PosCoreReceiptProjection`, but the backend also accepts, stores, computes from, and returns inclusive POS `unit_price` through order-line creation, held-order snapshots, order resources, order-to-receipt conversion, return hydration, receipt APIs, NF525 legacy export, and receipt print views. These are not signed canonical bytes and are not in the Zone-1 keep-list.
- **What to change:** Expand Phase 2 with an explicit "backend POS inclusive API/read surfaces" checklist. Rename or compatibility-map these fields to `unit_price_incl_tax`, including external JSON contract decisions for held orders, order resources, receipt return payloads, NF525 legacy DTO mapping, and receipt print views.

### M2 — `apps/pos` keep-list includes backend-facing API contracts without a boundary rule
- **Spec / code:** Spec §7 Zone 1 keeps `apps/pos/src/api/{holdApi,reportApi}.ts`; `apps/pos/src/api/holdApi.ts:7` and `:27` define request/response `unit_price`; `apps/pos/src/api/reportApi.ts:106`, `:336`, and `:554` define/read report and receipt-line `unit_price`; backend counterparts are `HoldOrderRequest.php:43`, `HeldOrderService.php:99`, and `HeldOrder.php:195`.
- **What is wrong:** The "no rename inside POS device" rule is correct for local cart/offline/canonical authoring, but `apps/pos/src/api/*` is a backend-facing surface, not just device-local state. Keeping the client type as bare `unit_price` may be acceptable, but only if the backend boundary explicitly translates between client `unit_price` and backend `unit_price_incl_tax`. v3 currently treats these files as pure Zone 1 and does not say where that translation happens.
- **What to change:** Split `apps/pos` into device-local/canonical surfaces and backend API client surfaces. For backend API clients, document whether the wire contract remains `unit_price` for backward compatibility or changes to `unit_price_incl_tax`; either way, specify the server-side mapping and tests.

## MINOR Findings

### m1 — POS receipt migration plan omits PostgreSQL check constraints that reference `unit_price`
- **Spec / code:** Spec §4 says expand/contract and §7 lists `pos_receipt_lines`; existing migrations define constraints in `apps/api/database/migrations/tenant/2026_01_08_190638_create_pos_receipt_lines_table.php:76` and `:82`, then replace them in `apps/api/database/migrations/tenant/2026_03_09_200000_add_return_fields_to_pos_receipts.php:74`, `:118`, and `:124`.
- **What is wrong:** Dropping or retiring `pos_receipt_lines.unit_price` after read-switch will fail or leave stale constraints unless the migration explicitly drops/recreates constraints against `unit_price_incl_tax`. The spec's generic expand/contract language does not call out this ordering.
- **What to change:** Add a Phase 2 migration sub-step for PostgreSQL constraints: add the new column, backfill, dual-write, replace constraints to reference `unit_price_incl_tax`, switch reads, then drop the old column in the later tenant migration.

### m2 — "no bare unit_price survives in backend" needs generated/baseline/archive handling
- **Spec / code:** Spec §1 says "no bare `unit_price` survives in the backend"; grep found backend generated/baseline/archive surfaces: `packages/shared/types/generated.d.ts:250`, `:540`, `:1030`, `:1696`, `:1949`; `apps/api/phpstan-baseline.neon:40`, `:130`, `:418`, `:610`; `apps/api/backup_before_phase0.sql:198`, `:1327`; `apps/api/app/Console/Commands/TestTaxRecoverability.php:84`, `:183`; `apps/api/app/Console/Commands/TestE2EGLPosting.php:74`, `:133`.
- **What is wrong:** Generated TS is named in §7, but phpstan baselines, command fixtures, and the SQL backup/archive are not classified. Some can be regenerated/updated; some may be explicit archives. Without a disposition, broad greps will keep finding backend bare `unit_price` after implementation.
- **What to change:** Add a "generated, baseline, archive, diagnostic command" disposition: regenerate shared TS, update PHPStan baselines only if still needed, update diagnostic commands or mark them test-only, and explicitly exclude immutable backups if they are not part of runtime/source policy.

## NIT Findings

### n1 — Changelog count does not match the prior review file
- **Spec / code:** Spec line 5 says the prior Codex review had "3 BLOCKER"; the prior review file has two `## BLOCKER` entries at `docs/superpowers/reviews/2026-06-03-unit-price-rename-spec-codex-review.md:5` and `:10`.
- **What is wrong:** This is not implementation-blocking, but it makes the regression checklist ambiguous.
- **What to change:** Fix the changelog count or identify the missing blocker if another review artifact was intended.

## Missed seams / bare unit_price still in backend

Grep found no results for `unit_price_incl` or `unit_price_excl` under `apps/api`, `apps/pos`, or `packages`, as expected for a spec-only branch.

Confirmed backend bare `unit_price` surfaces that v3 fails to classify specifically enough:

- Backend POS seam/read surfaces: `apps/api/app/Modules/POS/Presentation/Requests/AddOrderLineRequest.php:46`, `apps/api/app/Modules/POS/Presentation/Controllers/OrderController.php:171`, `apps/api/app/Modules/POS/Application/Services/OrderManagementService.php:245`, `apps/api/app/Modules/POS/Presentation/Resources/OrderLineResource.php:34`, `apps/api/app/Modules/POS/Presentation/Requests/HoldOrderRequest.php:43`, `apps/api/app/Modules/POS/Application/Services/HeldOrderService.php:99`, `apps/api/app/Modules/POS/Domain/HeldOrder.php:195`, `apps/api/app/Modules/POS/Application/Services/OrderToReceiptService.php:49`.
- Receipt/refund/reporting re-exposure: `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:314`, `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:799`, `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:1069`, `apps/api/resources/views/pos/receipt.blade.php:398`.
- Zone-1 canonical mirror keep-list confirmed: `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/LineItemDTO.php:58`, `apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php:142`, `apps/api/app/Modules/Fiscal/Domain/DTOs/AccountChargePayload.php:7`, `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:1337`, `:1351`, `:1638`, `:1653`. These should remain bare by design.
- ACCOUNT_CHARGE consumer that depends on current value semantics: `apps/api/app/Modules/Document/Application/Projections/DocumentAccountChargeFactureBridge.php:55`, `apps/api/app/Modules/Document/Application/Services/POSAccountChargeDraftService.php:88`, `:220`, and `apps/api/tests/Feature/Fiscal/DocumentAccountChargeFactureBridgeTest.php:96`.
- Generated/baseline/archive surfaces needing disposition: `packages/shared/types/generated.d.ts:250`, `:540`, `:1030`, `:1696`, `:1709`, `:1949`; `apps/api/phpstan-baseline.neon:40`, `:130`, `:418`, `:610`; `apps/api/backup_before_phase0.sql:198`, `:1327`.

## v1 findings regression check

- Prior B1, multi-version parser would reject v1 SALE_RECEIPT bytes: resolved-correctly. v3 no longer renames canonical SALE_RECEIPT, and spec §2/§4 says no versioned fiscal event is needed.
- Prior B2, ACCOUNT_CHARGE canonical payload omitted: partially resolved but newly-regressed. v3 keeps ACCOUNT_CHARGE canonical key and classifies it, but §4.3 now claims the value correction is safe despite the existing facture bridge copying canonical `unit_price` into document net storage.
- Prior MAJOR, stale DB scale: resolved-correctly for scale. Spec §4 and §7 consistently use effective `decimal(15,3)`; residual constraint-order detail remains in MINOR m1.
- Prior MAJOR, `fiscal_schema_version` cutover under-specified: resolved-correctly. No canonical rename means no cutover lever is needed for this rename.
- Prior MAJOR, POS receipt projection/storage omitted: mostly resolved. v3 names `pos_receipt_lines`, `ReceiptLine`, `ReceiptCreationService`, `StoreReceiptRequest`, and `PosCoreReceiptProjection`; still-open for receipt/read/report/refund surfaces listed in M1.
- Prior MAJOR, whole net-price modules omitted: mostly resolved. v3 adds billing, marketplace, catalog cart, coupon, tax/pricing, workshop, and web B2B; still needs generated/baseline/archive and backend read/view dispositions.
- Prior MAJOR, catalog cart deferred: resolved-correctly. v3 classifies catalog cart as Phase 1 net.
- Prior MINOR, REALIGNMENT-LOG path absent: still-open but explicitly acknowledged. Spec §5 and §10 say to confirm the monorepo-root path; `find ... REALIGNMENT-LOG.md` in this worktree returned no results.
- Prior NIT, POS UI path ambiguity: mostly resolved. v3 separates `apps/pos` and `apps/web/src/features/pos`, but backend-facing `apps/pos/src/api/*` clients still need a boundary rule (M2).

## Conclusion
Do not start implementation from v3 as written. Fix the ACCOUNT_CHARGE §4.3 safety claim and specify the facture-bridge conversion, then expand Phase 2 to cover every backend POS API/read/report/refund seam where inclusive `unit_price` is accepted or re-exposed. After that, re-run the inventory against post-T2 `dev` and add the migration constraint ordering before writing the implementation plan.
