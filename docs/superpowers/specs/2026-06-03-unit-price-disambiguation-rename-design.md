# `unit_price` Disambiguation Rename — Design / Spec

> **Status:** Approved design, **v2 (post-Codex-review)** — 2026-06-03. Spec only — no code.
> **Branch:** `feat/unit-price-rename-spec` (off `dev`).
> **Adversarial review:** [`docs/superpowers/reviews/2026-06-03-unit-price-rename-spec-codex-review.md`](../reviews/2026-06-03-unit-price-rename-spec-codex-review.md) — Codex REQUEST-CHANGES (3 BLOCKER / 4 MAJOR / 4 MINOR / 7+ missed surfaces). All findings verified against code and incorporated below; the changelog is in §11.
> **Companion plan:** `docs/superpowers/plans/2026-06-03-unit-price-disambiguation-rename-plan.md` (written after this spec is reviewed).
> **References:** [`docs/architecture/precision-contract.md` — `unit_price` section](../../architecture/precision-contract.md), [CLAUDE.md §19](../../../CLAUDE.md).

---

## 1. Problem

The field name `unit_price` is **context-overloaded** with two incompatible tax semantics that share the same name across many layers and modules:

| Context | `unit_price` means | Canonical evidence |
|---|---|---|
| **B2C POS cart / SALE_RECEIPT** | **tax-INCLUSIVE (TTC)** | `cartStore.computeTaxAmount` extracts VAT from gross; canonical `SALE_RECEIPT.line_items[].unit_price` is the inclusive cart price; net is `line_subtotal` |
| **B2B / documents** (quotes, orders, invoices, credit/return notes, delivery notes, workshop, billing) | **net / HT** | `DocumentTotalsCalculator`/`FacturXService` do `bcmul(quantity, unit_price)` = net subtotal |

The overload has already produced a real false-positive in fiscal line-arithmetic work. The precision contract documents the overload as the live mitigation and flags the rename as **deferred**. This spec resolves the deferral by renaming to **English**, unambiguous names: `unit_price_excl_tax` (net) and `unit_price_incl_tax` (inclusive).

### Locked classification rule (CORRECTED — by tax semantics, verified per-surface)

> **The Codex review disproved a naive "B2C app ⇒ inclusive" rule.** The signed `ACCOUNT_CHARGE` event is a B2C POS event (`invoice_classification: b2c_charge_receipt`) whose canonical `unit_price` is **net** (`unit_price == line_subtotal == 100.000`, VAT separate). Classification is therefore **per surface, by what the stored number actually means**, not by which app or channel it lives in.

- **`unit_price_incl_tax`** ⇐ the value INCLUDES VAT (gross). Today the only confirmed inclusive surfaces are the **SALE_RECEIPT** cart→storage→canonical path.
- **`unit_price_excl_tax`** ⇐ the value EXCLUDES VAT (net/HT). Documents, workshop, billing, catalog cart, **and the `ACCOUNT_CHARGE` canonical line** (net despite being B2C).
- Every `unit_price` occurrence MUST be assigned in the §7 classification table with cited evidence before it is touched. No blanket per-app assumption.

---

## 2. Goals / Non-Goals

**Goals**
- Eliminate the `unit_price` name overload by renaming each occurrence to `unit_price_excl_tax` or `unit_price_incl_tax` per verified semantics.
- Preserve the fiscal model exactly: **device authors canonical bytes; server re-hashes stored `canonical_bytes`, never recomputes/re-serializes.**
- Never invalidate an existing signed chain: already-signed canonical bytes (carrying the old `unit_price` key) must keep verifying byte-for-byte forever, for **both** SALE_RECEIPT and ACCOUNT_CHARGE.
- Each phase independently shippable as its own PR.

**Non-Goals**
- No change to precision/scale/storage tiers/value objects (precision contract stands; this rename preserves the **effective** `decimal(15,3)` scale — see §4/§6).
- No change to tax math, rounding, or aggregate fiscal-integrity invariants — only field *names* change.
- No renaming of `line_subtotal` / `line_vat` / `vat_breakdown` (already unambiguous).
- No new product/pricing features.

---

## 3. Sequencing constraint — **AFTER T2 variants**

> **This rename MUST land after T2 product-variants implementation is merged to `dev`** (see `project_t2_variants_impl`). T2 rewrites the same line DTOs/services this rename touches; running concurrently produces large conflict-prone diffs. Treat T2-merged as a precondition, and **re-run the §7 surface inventory against post-T2 `dev`** before executing — T2 may add/move `unit_price` callsites.

---

## 4. Phase structure (by risk)

Four phases. **Phase 3 (canonical signed bytes) is OPTIONAL / DEFERRED** behind owner sign-off; Phases 1–2 remove the overload from every *editable / projected* surface. The canonical payloads can remain on their current version (documented) until the owner elects to do Phase 3.

> **DB rule for all phases (CORRECTED):** every replacement column is created at the **effective** schema scale, which is `decimal(15,3)` for money columns (document lines were widened in `2026_03_11_200000`; POS order columns were narrowed to scale 3 in `2026_05_29_100001`). **Do not** copy the original create-migration scales (15,2 / 15,4) — that would lose TND precision or reintroduce a 4th POS decimal the precision contract intentionally removed. Update the corresponding model `decimal:3` casts. All migrations live under `apps/api/database/migrations/tenant/` (DB-per-tenant; the `tenant/` path runs per tenant DB). **Drop the old column only in a later migration, after every tenant DB has completed read-switch verification.**

### Phase 1 — B2B / net → `unit_price_excl_tax` (moderate risk)

Every net `unit_price` (see §7 for the authoritative list). Highlights:
- **Document module:** `DocumentLineData` DTO; `document_lines.unit_price` → `unit_price_excl_tax` (`decimal(15,3)`, model cast `decimal:3`); services (`DocumentTotalsCalculator`, `DraftPersistenceService`, `Conversion/Concerns/CopiesDocumentData` + the 3 converters, `CreditNoteService`, `RefundService`, `FacturXService`, `POSAccountChargeDraftService`); FormRequests/controllers (`UpdateDocumentRequest`, `RefundController`, `QuoteController`, `SalesOrderController`, `InvoiceController`, `DeliveryNoteController`, `PurchaseOrderController`, `ReturnNoteController`, `DocumentAdditionalCostController`).
- **Immutable events (Rule 8):** `unit_price` is in `DraftLineAdded` / `DraftLineAddedV2` / `DraftLineModifiedV2` payloads → introduce versioned successors (`DraftLineAddedV3`, `DraftLineModifiedV3`) carrying `unit_price_excl_tax`; leave existing event classes + their serialized key untouched.
- **Workshop:** `BundleExpansionLineData`, `WorkOrderLineData`, `ServiceBundleComponentData.override_unit_price` (→ `override_unit_price_excl_tax`) + factories.
- **Billing:** `billing_invoice_items.unit_price` (`decimal(15,3)`, net — `tax_rate`/`tax_amount` separate), `InvoiceItem` model, `InvoiceService`.
- **Catalog cart (RESOLVED as net — was deferred):** `catalog_cart_items.unit_price`, `CatalogCartItem` model/factory, `CartService`, `CartConversionService` (creates document lines via `DocumentLine::create([... 'unit_price' => ...])`), `MarketplaceCheckoutService`, `CatalogCartController`, `CatalogCartItemData`.
- **Taxation / pricing helpers** consuming generic net `unit_price`: `TaxCalculationService`, `PricingController`, `Treasury/.../AuditDiscountsCommand`.
- **Web (`apps/web`):** regenerate `packages/shared/types/generated.d.ts` (`php artisan typescript:transform`); update manual mirror `apps/web/src/types/document.ts`; `DocumentLineEditor`, detail pages, `CreateCreditNotePage`, `CreateReturnNotePage`, `PurchaseOrderLandedCostBreakdown`, `GoodsReceiptListPage`, coupon API types (if classified net), fixtures + e2e.
- **Fiscal-byte impact:** none.

### Phase 2 — B2C inclusive, non-fiscal storage + projections → `unit_price_incl_tax` (moderate risk)

Rename the inclusive `unit_price` everywhere **except** the signed canonical bytes. Lands **before** Phase 3.
- **POS order storage:** `pos_order_lines.unit_price` → `unit_price_incl_tax` (`decimal(15,3)`); `OrderLineData` DTO (+ regenerated TS).
- **POS receipt projection/storage (ADDED — was missed):** `pos_receipt_lines.unit_price` (`decimal(15,3)`); `ReceiptLine` model; `PosCoreReceiptProjection` (currently writes `LineItemDTO::unitPrice` → `pos_receipt_lines.unit_price`); `ReceiptCreationService`, `ReceiptFinalizationService`, `StoreReceiptRequest`. **`canonical_bytes` stays untouched** — only the projection target column/readers are renamed.
- **POS device (`apps/pos`):** `src/types/cart.ts` (`CartItem.unit_price`), `src/stores/cartStore.ts`, `src/types/receipt.ts`, `src/lib/buildReceiptData.ts`, `src/lib/offline/receiptService.ts` + `offline/types.ts` + `offline/zReportService.ts`, `src/lib/refundFlow/hydrateFromReceipt.ts`, `src/api/holdApi.ts` + `reportApi.ts`, cart/receipt components (`TransactionCart`, `CartLineItem`), `src/pages/HomePage.tsx`.
- **Web POS (`apps/web/src/features/pos/**`) — kept distinct from device** (the prior spec conflated them): `POSPage` and related admin/web-POS components.
- **Discount/coupon preview (classify first — see §6):** `ValidateCouponRequest`, `CouponController`, `DiscountController`, `couponApi.ts` — if confirmed B2C-inclusive they belong here; if net, Phase 1.
- **Fiscal-byte impact:** none — the canonical builders still emit the current `unit_price` key; the cart/projection field names and the canonical key are intentionally **decoupled** until Phase 3 (the only documented name-mismatch window).

### Phase 3 — Canonical signed bytes (SALE_RECEIPT + ACCOUNT_CHARGE) (HIGH RISK) — **OPTIONAL / DEFERRED, owner-gated**

Renames the key *inside the signed canonical payloads* — the irreversible, chain-affecting part. Covers **both** signed events that carry `line_items[].unit_price`:
- **SALE_RECEIPT** → `unit_price_incl_tax` (inclusive).
- **ACCOUNT_CHARGE** → `unit_price_excl_tax` (net) — *or* an explicit carve-out with rationale + follow-up owner decision. Same registry/parser/golden-vector discipline; must not mutate existing v1 bytes.

**Multi-version registry + parser design (CORRECTED — the core BLOCKER fix):**
- The current server parser validates `envelope.event_version === registry.eventVersionFor(type)` (single value) and the device append path asks the registry for one version. **Merely bumping the mapping would reject all stored prior-version bytes.**
- Required design: split **accepted/parseable versions** from **authoring version**.
  - Parsing/validation resolves the DTO + validator by `(event_type, envelope.event_version)` — a multi-version map, not a single `eventVersionFor`.
  - Authoring selects the version via `eventVersionForAuthoring(event_type, terminal_capability)`.
  - Tests must prove a stored prior-version fixture still parses/verifies **after** the new version is registered.

**Version numbering (CORRECTED — derive from actual current state):**
- Fixtures already contain `tests/Fixtures/Fiscal/sale-receipt-golden/v4/` and `v3-golden-hashes/`, so SALE_RECEIPT is **not** at v1 — the new version is **N+1 from the current maximum**, derived at implementation time, **not** "v2".
- Freeze all current-version golden vectors as permanent regression anchors; generate new-version vectors (TND-3dp + EUR-2dp) from the new builder.

**Cutover lever (CORRECTED):**
- `pos_terminals.fiscal_schema_version` is **already** a 2→3 lever (`FiscalSchemaCutoverService`, sync accepts `2|3`, finalization branches on 2 vs 3). "Bump it" is ambiguous and could collide with existing v3 behavior.
- Phase 3 must define a **distinct** lever: either a new `fiscal_schema_version` step beyond the current max (derive the exact value at impl), **or** a dedicated per-terminal capability such as `sale_receipt_payload_version`. State explicitly whether existing v3 terminals stay on the current canonical version until a separate cutover.
- Device authors the new version only once its lever is advanced; server accepts old + new indefinitely (mixed-version fleet).

**v-aware readers:** `SaleReceiptCanonicalView`, `LineItemDTO`, `CanonicalPayloadReader`, `Nf525DataProvider`, `FiscalPayloadConstraintValidator`, `AccountChargePayload` (TS + PHP) must read the version and select the correct key.

---

## 5. Cross-cutting deliverables

- **Docs:** update `precision-contract.md` (unit_price section) + CLAUDE.md §19 incrementally per phase (after Phase 2: overload gone from editable/projected surfaces, canonical keys unchanged; after Phase 3: fully resolved).
- **REALIGNMENT-LOG (path corrected):** the integration realignment log lives at the **syneriva monorepo root** (`docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md`), **outside** `apps/erp` — it is absent from this worktree. Confirm the exact path at impl and log the canonical DB/API shape changes (and, for Phase 3, the canonical payload shape change) there.
- **Guards stay green:** PHPStan (`ForbidFloatCastOnDecimalProperty`, `ForbidHardcodedBcmathScale`), ESLint money/quantity rules, Deptrac, Pint.
- **PR-per-phase:** Phases 1, 2, (optional) 3 each merge independently. Phase 3 carries the owner-sign-off gate.

---

## 6. Owner-flagged notes (carry into the plan)

**Catalog cart — RESOLVED net (no longer deferred).** Code shows catalog cart prices flow into `DocumentLine::create([... 'unit_price' => ...])` via `CartConversionService` and feed PO/SO creation → net. Classified **Phase 1 / excl-tax** unless product owners identify a separate B2C online-ordering flow that displays VAT-inclusive prices.

**Marketplace order lines — RESOLVED net (Phase 1).** Verified 2026-06-04: `marketplace_order_lines.unit_price` (`MarketplaceOrderService`, `MarketplaceOrderData`, `MarketplaceOrderLine`, migration `2026_03_10_400002`) takes its value from `listing->price`, and `MarketplaceListing.price` is synced from **the seller tenant's own `product.sale_price`** (`ListingSyncService::syncProduct:54` → `'price' => $product->sale_price`) — i.e. the tenant's own product price, **not** a centralized Synerivia platform price. This is a **B2B marketplace** (`buyer_tenant_id` buys from a `MarketplaceSeller` = another tenant); the order line has **no per-line tax columns** and the order is converted into a **B2B `Document`** (net lines). Classified **Phase 1 / excl-tax**. *Latent-concern flag (out of scope for the rename, log only):* whether `product.sale_price` itself is stored net vs gross is the same overload one level up — the marketplace path treats it as net; if POS treats the same `sale_price` as gross there is a pre-existing inconsistency to raise separately, not fix here.

**Coupon / discount preview — RESOLVED inclusive (Phase 2).** Verified 2026-06-04: `ValidateCouponRequest` requires the `pos.operate_terminal` permission and `CouponController::validate` is documented "for POS preview"; the `items.*.unit_price` are POS cart lines, which are tax-**inclusive**. Classified **Phase 2 / incl-tax** (`ValidateCouponRequest`, `CouponController`, `DiscountController`, `couponApi.ts`).

**`fiscal_schema_version` note.** Already a 2→3 cutover lever (default 2, advanced to 3 by `FiscalSchemaCutoverService`); distinct from `fiscal_events.event_version` (per-event payload version). Phase 3 needs a NEW lever value/capability (§4). Existing `sale-receipt-golden/v4` fixtures imply the canonical schema is further along than the cutover service's "3" — reconcile the version landscape at impl before choosing numbers.

**After-T2 note.** Do not start execution until T2 product-variants is merged to `dev` (§3); re-run §7 inventory against post-T2 `dev` first.

---

## 7. Surface classification table (snapshot — re-verify post-T2)

> Captured 2026-06-03 against `origin/dev`; expanded per Codex missed-surfaces. **Authoritative scope lives here** — every `unit_price` outside tests/archives must appear with a phase + evidence. Re-run before execution.

### Phase 1 — net → `unit_price_excl_tax`
| Surface | Files (representative) | Evidence of net |
|---|---|---|
| Document line DTO/DB/events | `Document/Application/DTOs/DocumentLineData.php`; `migrations/tenant/2025_11_30_080001_*`, widened `2026_03_11_200000_*:60`; `DocumentLine.php`; `DraftLineAdded(V2)`, `DraftLineModifiedV2` | `bcmul(qty, unit_price)`=net subtotal; tax separate |
| Document services | `DocumentTotalsCalculator`, `DraftPersistenceService`, `Conversion/*`, `CreditNoteService`, `RefundService`, `FacturXService`, `POSAccountChargeDraftService` | net line math |
| Document controllers/requests | `UpdateDocumentRequest`, `RefundController`, `Quote/SalesOrder/Invoice/DeliveryNote/PurchaseOrder/ReturnNote Controller`, `DocumentAdditionalCostController` | document net lines |
| Workshop | `BundleExpansionLineData`, `WorkOrderLineData`, `ServiceBundleComponentData.override_unit_price`; factories | service/component net cost |
| Billing | `billing_invoice_items.unit_price` (`2025_12_16_100003_*`, widened `2026_03_11_200000_*:161`); `InvoiceItem.php` (`decimal:3`, separate `tax_rate`/`tax_amount`); `InvoiceService` | `qty*unit_price` net |
| Catalog cart | `catalog_cart_items.unit_price` (`2026_03_10_500000_*`); `CatalogCartItem`, `CartService`, `CartConversionService`, `MarketplaceCheckoutService`, `CatalogCartController`, `CatalogCartItemData` | converts to document net lines |
| Tax/pricing helpers | `TaxCalculationService:123,206`, `PricingController`, `AuditDiscountsCommand` | net inputs |
| Marketplace (B2B) | `marketplace_order_lines.unit_price` (`2026_03_10_400002_*`); `MarketplaceOrderLine`, `MarketplaceOrderData`, `MarketplaceOrderService`; `MarketplaceListing.price` (synced from `product.sale_price` via `ListingSyncService`) | seller-tenant product price; converts to B2B Document net lines; no per-line tax |
| Web | `generated.d.ts` (auto), `apps/web/src/types/document.ts`, `DocumentLineEditor`, detail pages, credit/return note pages, landed-cost, goods-receipt, fixtures, e2e | mirrors document DTO |

### Phase 2 — inclusive non-fiscal/projection → `unit_price_incl_tax`
| Surface | Files | Note |
|---|---|---|
| POS order storage | `pos_order_lines.unit_price` (`2026_03_11_400001_*`, narrowed `2026_05_29_100001_*`); `OrderLineData` | inclusive cart price |
| POS receipt projection/storage | `pos_receipt_lines.unit_price` (`2026_01_08_190638_*`, widened `2026_03_11_200000_*:78`); `ReceiptLine`, `PosCoreReceiptProjection`, `ReceiptCreationService`, `ReceiptFinalizationService`, `StoreReceiptRequest` | projection of canonical line; **bytes untouched** |
| POS device | `apps/pos/src/types/cart.ts`, `stores/cartStore.ts`, `types/receipt.ts`, `lib/buildReceiptData.ts`, `lib/offline/{receiptService,types,zReportService}.ts`, `lib/refundFlow/hydrateFromReceipt.ts`, `api/{holdApi,reportApi}.ts`, `components/.../{TransactionCart,CartLineItem}`, `pages/HomePage.tsx` | inclusive |
| Web POS | `apps/web/src/features/pos/**` | inclusive (distinct from device) |
| Coupon/discount preview | `ValidateCouponRequest`, `CouponController`, `DiscountController`, `apps/web/src/features/coupons/api/couponApi.ts` | POS cart lines (`pos.operate_terminal`); inclusive |

### Phase 3 — signed canonical bytes (OPTIONAL/DEFERRED)
| Event | Files | Target name | Note |
|---|---|---|---|
| SALE_RECEIPT | TS `payloads/SaleReceiptPayload.ts`, `FiscalEventEngine.ts`, `FiscalEventPayloadRegistry.ts`; PHP `Fiscal/Domain/DTOs/SaleReceiptPayload.php`, `Canonical/LineItemDTO.php`, `Canonical/SaleReceiptCanonicalView.php`, `CanonicalPayloadReader.php`, `FiscalEventPayloadRegistry.php`, `FiscalPayloadConstraintValidator.php`, `Nf525DataProvider.php` | `unit_price_incl_tax` | inclusive |
| ACCOUNT_CHARGE | TS `payloads/AccountChargePayload.ts`, `accountCharge/accountChargeService.ts`; PHP `Fiscal/Domain/DTOs/AccountChargePayload.php`, `FiscalPayloadConstraintValidator.php:1328` | `unit_price_excl_tax` | **net** (`unit_price==line_subtotal`) |
| Fixtures/helpers | `tests/Fixtures/Fiscal/canonical-golden-vectors.json`, `sale-receipt-golden/v4/*`, `v3-golden-hashes/*`; `GoldenFixtureBuilder`, `LargeReceiptFixtureGenerator`; parity tests `apps/pos/.../__tests__/*CanonicalParity.test.ts`, `FiscalPayloadConstraintValidatorTest` | — | freeze current as regression; gen new-version |

### Resolved classifications (formerly classify-first — see §6)
- `marketplace_order_lines.unit_price` → **Phase 1 / net** (B2B marketplace; seller-tenant `product.sale_price`; converts to B2B documents).
- Coupon/discount preview `unit_price` → **Phase 2 / inclusive** (POS cart preview).

### Shared/seed surfaces (touched by whichever phase owns the DTO)
`packages/shared/types/generated.d.ts`; factories (`CatalogCartItemFactory`, `ServiceBundleComponentFactory`, `Workshop/WorkOrderLineFactory`); `DemoTenantSeeder`.

---

## 8. Testing strategy

TDD throughout (red → green → refactor); existing PHPUnit + Vitest + real-seeder discipline.
- **Phases 1–2:** existing suites stay green post-rename; add expand/contract migration tests per column (add, backfill correctness, dual-write equivalence, post-drop reads) on the `tenant/` migration path; web type-check + ESLint + e2e.
- **Phase 3 (if executed):**
  1. **Red:** prior-version regression — a stored current-version fixture (old `unit_price` key) still parses + verifies after the new version is registered (per signed event).
  2. **Red:** cross-language parity — device-built new-version bytes == PHP-built new-version bytes, byte-for-byte (SALE_RECEIPT and ACCOUNT_CHARGE).
  3. **Red:** semantic + routing — new version carries the renamed key; parser resolves DTO/validator by `(event_type, event_version)`; authoring picks version by terminal capability.
  4. Implement until green.

---

## 9. Risks & mitigations

| Risk | Severity | Mitigation |
|---|---|---|
| Breaking an existing signed chain (SALE_RECEIPT or ACCOUNT_CHARGE) | Critical | Multi-version parse-by-`(type,version)`; freeze current golden vectors; server only re-hashes stored bytes |
| Single-version registry/parser silently rejects prior bytes | Critical | Explicit accepted-vs-authoring version split + regression test (§4/§8) |
| Wrong replacement column scale (precision-contract violation) | High | Use effective `decimal(15,3)` + `decimal:3` casts, not create-migration scales |
| Cutover lever collision with existing v3 | High | New `fiscal_schema_version` step or dedicated `sale_receipt_payload_version` capability |
| Device/server drift on new bytes | High | Cross-language parity test as Phase 3 merge gate |
| Missed surface preserves overload | High | Authoritative §7 table; re-inventory post-T2; classify marketplace/coupon first |
| Expand/contract dual-write divergence across tenant DBs | Medium | Dual-write equivalence test; drop column only after all tenants read-switched |
| Conflict with in-flight T2 | Medium | Hard sequencing gate (§3) + re-inventory |
| Stale name-mismatch window (cart/projection renamed, canonical key unchanged) | Low | Documented decoupling; closes at Phase 3 |

---

## 10. Open items to resolve in the plan / at execution

1. ~~Classify `marketplace_order_lines.unit_price` and coupon/discount-preview `unit_price`.~~ **RESOLVED 2026-06-04** — marketplace = Phase 1/net, coupon/discount = Phase 2/inclusive (§6). Remaining: log the `product.sale_price` net-vs-gross latent concern separately.
2. Re-run the §7 surface inventory against post-T2 `dev`.
3. Reconcile the canonical version landscape (cutover service "3" vs `sale-receipt-golden/v4` fixtures) and choose the exact new version number + cutover lever — Phase 3 only.
4. Decide ACCOUNT_CHARGE in Phase 3: rename to `unit_price_excl_tax` vs explicit carve-out.
5. Confirm the actual `REALIGNMENT-LOG.md` path at the monorepo root.
6. Owner decision on whether/when to execute Phase 3 at all.

---

## 11. Changelog — v1 → v2 (Codex review incorporation)

- **BLOCKER (parser):** added explicit multi-version registry/parser design (accepted-vs-authoring split); was "register v2" only.
- **BLOCKER (ACCOUNT_CHARGE):** removed the false "canonical line_items only on SALE_RECEIPT" claim; added ACCOUNT_CHARGE as a second signed surface (net) to Phase 3.
- **BLOCKER (cutover):** `fiscal_schema_version` already a 2→3 lever; Phase 3 now requires a distinct new lever; version numbers derived from actual state (fixtures at v4), not hardcoded "v2".
- **MAJOR (DB scale):** corrected to effective `decimal(15,3)` + `decimal:3` casts on `tenant/` path; not create-migration scales.
- **MAJOR (missed modules):** added Billing, Catalog cart (resolved net), Marketplace (classify), Coupon/discount (classify), Taxation/Pricing helpers, additional document controllers.
- **MAJOR (POS projection):** added `pos_receipt_lines` + projection/printing/offline/reporting readers to Phase 2.
- **MAJOR/MINOR (cart, realignment-log, pos UI split):** catalog cart resolved in-spec; REALIGNMENT-LOG path corrected to monorepo root; `apps/pos` device vs `apps/web/src/features/pos` split.
- Restructured into a per-surface classification table (§7) as the authoritative scope.
