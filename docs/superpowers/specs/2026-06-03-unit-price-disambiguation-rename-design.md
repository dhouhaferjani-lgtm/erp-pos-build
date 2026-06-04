# `unit_price` Disambiguation Rename — Design / Spec

> **Status:** Approved design, **v3 (three-zone model)** — 2026-06-04. Spec only — no code.
> **Branch:** `feat/unit-price-rename-spec` (off `dev`).
> **Adversarial review:** [`docs/superpowers/reviews/2026-06-03-unit-price-rename-spec-codex-review.md`](../reviews/2026-06-03-unit-price-rename-spec-codex-review.md) — Codex REQUEST-CHANGES (3 BLOCKER / 4 MAJOR / 4 MINOR / 7+ missed surfaces). All findings verified and addressed; v3 dissolves the two signed-byte blockers by **not renaming the canonical payload**. Changelog in §11.
> **Companion plan:** `docs/superpowers/plans/2026-06-03-unit-price-disambiguation-rename-plan.md` (written after this spec is reviewed).
> **References:** [`docs/architecture/precision-contract.md` — `unit_price` section](../../architecture/precision-contract.md), [CLAUDE.md §19](../../../CLAUDE.md).

---

## 1. Problem & the three-zone model

`unit_price` is **context-overloaded**: tax-**INCLUSIVE** (TTC) on the B2C / POS side, **net / HT** on the B2B / documents side. The same name means different things, which has already caused a real fiscal false-positive. The precision contract documents this overload as the live mitigation and flags the rename as deferred. This spec resolves it.

**Key insight (owner, 2026-06-04):** the overload is only dangerous *where the two worlds meet* — the backend, where a developer can see two `unit_price`s meaning different things. *Inside* the POS ("the boss") everything is uniformly tax-inclusive, so there is nothing there to confuse. We therefore fix the ambiguity at the boundary, not everywhere, via three zones:

| Zone | Scope | Treatment |
|---|---|---|
| **Zone 1 — the boss + fiscal chain** | POS device (`apps/pos`): cart, device storage, canonical payload builder, **signed canonical bytes** (SALE_RECEIPT, ACCOUNT_CHARGE), and the PHP DTOs that *mirror* those bytes | **Keep `unit_price`.** Always inclusive; documented as such. **Never renamed** → no versioned fiscal event, no fixture regeneration for the rename, "device authors / server re-hashes never recomputes" untouched. |
| **Zone 2 — the seam** | The POS→backend ingestion: canonical parse + `PosCoreReceiptProjection` + POS order/receipt persistence | **Translate at the boundary.** Read canonical `unit_price` (inclusive) → write the backend column `unit_price_incl_tax`. One well-defined, tested mapping; zero hash impact (bytes are verified before projection). |
| **Zone 3 — the backend** | All backend storage/DTOs/web | **Rename everything; no bare `unit_price` survives in the backend.** POS-origin inclusive → `unit_price_incl_tax`; B2B/net → `unit_price_excl_tax`. |

After this change the **only** bare `unit_price` left in the system is inside the immutable signed bytes (Zone 1), where it is documented as "always inclusive for device-authored events." Every queryable backend column/DTO is explicit.

---

## 2. Goals / Non-Goals

**Goals**
- Remove the backend `unit_price` overload: every backend per-unit-money column/DTO becomes `unit_price_incl_tax` or `unit_price_excl_tax`.
- Preserve the fiscal model exactly: canonical bytes keep `unit_price`; **device authors bytes, server re-hashes stored `canonical_bytes`, never recomputes/re-serializes.**
- Make the POS→backend tax-context shift explicit and tested (Zone 2 seam).
- Each phase independently shippable as its own PR.

**Non-Goals**
- **No rename of the canonical payload / signed bytes** (`unit_price` stays in SALE_RECEIPT and ACCOUNT_CHARGE). No versioned fiscal event, no cutover lever, no canonical fixture regeneration *for the rename*.
- **No rename inside the POS device** (cart, device SQLite, canonical builder) — it stays `unit_price` (uniformly inclusive). Avoids churn with no ambiguity gain and keeps the device aligned with the canonical key.
- No change to precision/scale/value objects; preserve the **effective** `decimal(15,3)` money scale (§4/§6).
- No change to tax math, rounding, or fiscal-integrity invariants — names change; the one *value* change is the ACCOUNT_CHARGE correction (§4.3), which is separate and unwired.
- No rename of `line_subtotal` / `line_vat` / `vat_breakdown` (already unambiguous).

---

## 3. Sequencing constraint — **AFTER T2 variants**

> **Land this after T2 product-variants merges to `dev`** (`project_t2_variants_impl`): T2 rewrites the same line DTOs/services. Treat T2-merged as a precondition and **re-run the §7 inventory against post-T2 `dev`** before executing.

---

## 4. Phase structure

Two rename phases + one small fiscal-value correction. All backend DB work uses **expand/contract** (add → backfill → dual-write → switch reads → drop in a *later* migration after all tenant DBs are read-switched) at the **effective `decimal(15,3)`** scale with `decimal:3` model casts. Migrations live under `apps/api/database/migrations/tenant/` (per-tenant DB).

### Phase 1 — B2B / net backend → `unit_price_excl_tax` (moderate risk)

All backend net surfaces (authoritative list in §7):
- **Document module:** `DocumentLineData` DTO; `document_lines.unit_price` (`decimal(15,3)`); services (`DocumentTotalsCalculator`, `DraftPersistenceService`, `Conversion/Concerns/CopiesDocumentData` + the 3 converters, `CreditNoteService`, `RefundService`, `FacturXService`, `POSAccountChargeDraftService`); FormRequests/controllers (`UpdateDocumentRequest`, `RefundController`, `Quote/SalesOrder/Invoice/DeliveryNote/PurchaseOrder/ReturnNote` controllers, `DocumentAdditionalCostController`).
- **Immutable events (Rule 8):** `DraftLineAdded`/`DraftLineAddedV2`/`DraftLineModifiedV2` carry `unit_price` → add versioned successors `DraftLineAddedV3`/`DraftLineModifiedV3` with `unit_price_excl_tax`; leave existing event classes + keys untouched.
- **Workshop:** `BundleExpansionLineData`, `WorkOrderLineData`, `ServiceBundleComponentData.override_unit_price` (→ `override_unit_price_excl_tax`) + factories.
- **Billing:** `billing_invoice_items.unit_price` (`decimal(15,3)`, net), `InvoiceItem`, `InvoiceService`.
- **Catalog cart (net):** `catalog_cart_items.unit_price`, `CatalogCartItem` model/factory, `CartService`, `CartConversionService`, `MarketplaceCheckoutService`, `CatalogCartController`, `CatalogCartItemData`.
- **Marketplace (B2B, net):** `marketplace_order_lines.unit_price`, `MarketplaceOrderLine`, `MarketplaceOrderData`, `MarketplaceOrderService`, `MarketplaceListing.price` lineage (synced from `product.sale_price`).
- **Tax/pricing helpers:** `TaxCalculationService`, `PricingController`, `AuditDiscountsCommand`.
- **Web (`apps/web`) B2B:** regenerate `packages/shared/types/generated.d.ts` (`php artisan typescript:transform`); manual mirror `apps/web/src/types/document.ts`; `DocumentLineEditor`, document detail pages, `CreateCreditNotePage`, `CreateReturnNotePage`, landed-cost, goods-receipt; fixtures + e2e.
- **Fiscal-byte impact:** none.

### Phase 2 — backend POS-origin (inclusive) → `unit_price_incl_tax` + the seam (moderate risk)

Rename the backend storage/DTOs that hold POS (inclusive) data, and make the Zone-2 seam explicit. **Canonical bytes and the POS device are NOT touched.**
- **POS order storage:** `pos_order_lines.unit_price` → `unit_price_incl_tax` (`decimal(15,3)`); `OrderLineData` DTO (+ regenerated TS).
- **POS receipt projection/storage:** `pos_receipt_lines.unit_price` → `unit_price_incl_tax` (`decimal(15,3)`); `ReceiptLine`; `ReceiptCreationService`, `ReceiptFinalizationService`, `StoreReceiptRequest`.
- **The seam (Zone 2):** `PosCoreReceiptProjection` reads canonical `LineItemDTO::unitPrice` (key `unit_price`, inclusive) and writes the renamed column `unit_price_incl_tax`. Document the boundary in code + the precision contract; add a test asserting canonical `unit_price` → storage `unit_price_incl_tax` with the value preserved verbatim.
- **Coupon/discount preview (POS, inclusive):** `ValidateCouponRequest`, `CouponController`, `DiscountController`, `apps/web/src/features/coupons/api/couponApi.ts` — request payloads carrying POS cart lines.
- **Web POS (`apps/web/src/features/pos/**`):** consumes the renamed inclusive backend fields.
- **Zone-1 PHP canonical-mirror DTOs stay `unit_price`:** `Fiscal/Domain/DTOs/Canonical/LineItemDTO.php` (`$unitPrice`), `SaleReceiptCanonicalView`, `CanonicalPayloadReader`, `SaleReceiptPayload.php`, `AccountChargePayload.php`, `FiscalPayloadConstraintValidator` — these mirror the signed bytes and are explicitly **not** renamed.
- **Fiscal-byte impact:** none.

### Phase 4.3 — ACCOUNT_CHARGE value correction (net → inclusive) — small, separate, OPTIONAL

> Not a rename — a **value-semantics correction** inside Zone 1, kept under the unchanged key `unit_price`.

ACCOUNT_CHARGE is device-authored from the inclusive cart, so its `line_items[].unit_price` should be the gross/inclusive cart price verbatim (net in `line_subtotal`), exactly like SALE_RECEIPT. Today it is authored **net** *only as a placeholder convention in test fixtures* (`unit_price == line_subtotal`); the feature is **not UI-wired** (verified 2026-06-04: no caller of `authorAccountCharge` in any UI/store/flow on any branch — see the charge-to-account finalization handoff) and the PHP validator is **tax-agnostic** for this event. Correct the authoring + fixtures to inclusive. **No key rename, no version bump** (the canonical key stays `unit_price`). Prerequisite: re-confirm zero production v1 ACCOUNT_CHARGE events before changing fixtures. May be done with the charge-to-account UI finalization rather than here.

### Canonical contract (replaces the old "Phase 3 rename")

Keep `unit_price` in SALE_RECEIPT and ACCOUNT_CHARGE canonical payloads. Update `precision-contract.md` + CLAUDE.md §19 to state the resolved rule: **a device-authored canonical `unit_price` is always tax-inclusive; the backend never stores a bare `unit_price` (it is `unit_price_incl_tax` post-seam or `unit_price_excl_tax` for B2B).**

---

## 5. Cross-cutting deliverables

- **Docs:** update `precision-contract.md` (unit_price section) + CLAUDE.md §19 from "deferred" to the resolved three-zone contract, per phase.
- **REALIGNMENT-LOG:** lives at the **syneriva monorepo root** (`docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md`), outside `apps/erp` — confirm the exact path at impl; log the backend column/DTO renames there.
- **Guards stay green:** PHPStan (`ForbidFloatCastOnDecimalProperty`, `ForbidHardcodedBcmathScale`), ESLint money/quantity rules, Deptrac, Pint.
- **PR-per-phase:** Phase 1, Phase 2 each merge independently. The ACCOUNT_CHARGE value correction is a small separate change (here or folded into the charge-to-account UI work).

---

## 6. Owner-flagged resolutions & notes

- **Catalog cart — net (Phase 1).** Catalog cart prices flow into `DocumentLine::create([...'unit_price'...])` via `CartConversionService` → net.
- **Marketplace — net (Phase 1).** B2B marketplace (`buyer_tenant_id` ⇐ `MarketplaceSeller`); `listing.price` synced from the seller tenant's own `product.sale_price` (`ListingSyncService:54`); no per-line tax; converts to B2B `Document` net lines. *Latent-concern flag (out of scope):* whether `product.sale_price` itself is stored net vs gross is the same overload one level up — raise separately if POS treats the same `sale_price` as gross.
- **Coupon/discount preview — inclusive (Phase 2).** `pos.operate_terminal`-gated POS cart preview.
- **ACCOUNT_CHARGE — inclusive value, key unchanged (§4.3).** Charge amount (`totals.amount_charged_to_account`, `local_balance_snapshot`) is separate and is not a `unit_price`.
- **`fiscal_schema_version` / versioned events — NOT needed** in v3 (no canonical rename). The earlier cutover-lever concern is moot.
- **ACCOUNT_CHARGE UI is unbuilt** — engine/service/projection are on `dev`; the checkout wiring is not. Tracked via a separate finalization handoff (the charge-to-account spec/plan already exist on `dev`).
- **After-T2** — re-run §7 inventory against post-T2 `dev` before executing.

---

## 7. Surface classification table (snapshot — re-verify post-T2)

> Captured 2026-06-03/04 against `origin/dev`. Authoritative scope. Every `unit_price` outside tests/archives must appear with a zone + phase + evidence.

### Zone 1 — keep `unit_price` (NOT renamed)
| Surface | Files | Why kept |
|---|---|---|
| POS device cart/storage/UI | `apps/pos/src/types/cart.ts`, `stores/cartStore.ts`, `types/receipt.ts`, `lib/buildReceiptData.ts`, `lib/offline/*`, `lib/refundFlow/*`, `api/{holdApi,reportApi}.ts`, cart/receipt components, `pages/HomePage.tsx` | uniformly inclusive; aligned with canonical key |
| Canonical builders (device) | `apps/pos/src/lib/fiscal/payloads/SaleReceiptPayload.ts`, `AccountChargePayload.ts`, `FiscalEventEngine.ts`, `FiscalEventPayloadRegistry.ts` | signed bytes — never renamed |
| Canonical-mirror DTOs (server) | `Fiscal/Domain/DTOs/Canonical/LineItemDTO.php`, `SaleReceiptCanonicalView.php`, `CanonicalPayloadReader.php`, `SaleReceiptPayload.php`, `AccountChargePayload.php`, `FiscalPayloadConstraintValidator.php` | mirror the bytes verbatim |

### Zone 3 — Phase 1, net → `unit_price_excl_tax`
| Surface | Files (representative) |
|---|---|
| Document line DTO/DB/events | `DocumentLineData`, `document_lines` (`2025_11_30_080001`, widened `2026_03_11_200000:60`), `DocumentLine`, `DraftLineAdded(V2)`, `DraftLineModifiedV2` |
| Document services/controllers | `DocumentTotalsCalculator`, `DraftPersistenceService`, `Conversion/*`, `CreditNoteService`, `RefundService`, `FacturXService`, `POSAccountChargeDraftService`; `UpdateDocumentRequest`, `RefundController`, `Quote/SalesOrder/Invoice/DeliveryNote/PurchaseOrder/ReturnNote` controllers, `DocumentAdditionalCostController` |
| Workshop | `BundleExpansionLineData`, `WorkOrderLineData`, `ServiceBundleComponentData.override_unit_price`; factories |
| Billing | `billing_invoice_items` (`2025_12_16_100003`, widened `…:161`), `InvoiceItem`, `InvoiceService` |
| Catalog cart | `catalog_cart_items` (`2026_03_10_500000`), `CatalogCartItem`, `CartService`, `CartConversionService`, `MarketplaceCheckoutService`, `CatalogCartController`, `CatalogCartItemData` |
| Marketplace (B2B) | `marketplace_order_lines` (`2026_03_10_400002`), `MarketplaceOrderLine`, `MarketplaceOrderData`, `MarketplaceOrderService` |
| Tax/pricing helpers | `TaxCalculationService:123,206`, `PricingController`, `AuditDiscountsCommand` |
| Web (B2B) | `generated.d.ts` (auto), `apps/web/src/types/document.ts`, `DocumentLineEditor`, document detail pages, credit/return note pages, landed-cost, goods-receipt, fixtures, e2e |

### Zone 3 — Phase 2, inclusive → `unit_price_incl_tax` (+ Zone 2 seam)
| Surface | Files |
|---|---|
| POS order storage | `pos_order_lines` (`2026_03_11_400001`, narrowed `2026_05_29_100001`), `OrderLineData` |
| POS receipt storage | `pos_receipt_lines` (`2026_01_08_190638`, widened `2026_03_11_200000:78`), `ReceiptLine`, `ReceiptCreationService`, `ReceiptFinalizationService`, `StoreReceiptRequest` |
| **Seam (Zone 2)** | `PosCoreReceiptProjection` — canonical `unit_price` → column `unit_price_incl_tax`; document + test |
| Coupon/discount preview | `ValidateCouponRequest`, `CouponController`, `DiscountController`, `apps/web/src/features/coupons/api/couponApi.ts` |
| Web POS | `apps/web/src/features/pos/**` |

### Shared/seed
`packages/shared/types/generated.d.ts`; factories (`CatalogCartItemFactory`, `ServiceBundleComponentFactory`, `Workshop/WorkOrderLineFactory`); `DemoTenantSeeder`. ACCOUNT_CHARGE fixtures corrected to inclusive under §4.3 (key unchanged).

---

## 8. Testing strategy

TDD throughout (PHPUnit + Vitest + real seeders).
- **Phases 1–2:** existing suites stay green post-rename; expand/contract migration tests per column (add, backfill, dual-write equivalence, post-drop reads) on the `tenant/` path; **Zone-2 seam test** (canonical `unit_price` → `pos_receipt_lines.unit_price_incl_tax`, value preserved); web type-check + ESLint + e2e.
- **Canonical regression:** assert SALE_RECEIPT/ACCOUNT_CHARGE canonical bytes and hashes are **unchanged** by Phases 1–2 (the rename must not touch the signed payload).
- **§4.3 ACCOUNT_CHARGE correction:** red test that authored `unit_price` equals the gross/inclusive cart price (not net), fixtures regenerated to inclusive; re-confirm no production v1 events first.

---

## 9. Risks & mitigations

| Risk | Severity | Mitigation |
|---|---|---|
| Accidentally renaming a canonical-mirror DTO and changing signed bytes | High | Zone-1 keep-list (§7); canonical bytes/hash unchanged-regression test |
| Wrong replacement column scale (precision violation) | High | Effective `decimal(15,3)` + `decimal:3` casts, not create-migration scales |
| Seam mismaps inclusive↔net at POS→backend | High | Explicit Zone-2 mapping + value-preservation test |
| Missed surface preserves overload | Medium | Authoritative §7 table; re-inventory post-T2 |
| Expand/contract dual-write divergence across tenant DBs | Medium | Dual-write equivalence test; drop only after all tenants read-switched |
| ACCOUNT_CHARGE value correction regresses a wired flow | Low | Verified not UI-wired + validator tax-agnostic; re-confirm no production v1 events; can ride the charge-to-account UI work |
| Conflict with in-flight T2 | Medium | Hard sequencing gate (§3) + re-inventory |

---

## 10. Open items to resolve in the plan / at execution

1. Re-run the §7 surface inventory against post-T2 `dev`.
2. Confirm the actual `REALIGNMENT-LOG.md` path at the monorepo root.
3. §4.3: re-confirm zero production v1 ACCOUNT_CHARGE events before correcting fixtures; decide whether to do it here or with the charge-to-account UI finalization.
4. Log the `product.sale_price` net-vs-gross latent concern (§6) separately.

---

## 11. Changelog

- **v1:** initial three-phase design (B2B; B2C non-fiscal; canonical SALE_RECEIPT v2, deferred).
- **v2 (Codex review):** per-surface classification; added ACCOUNT_CHARGE as a second signed surface; multi-version parser design; effective `decimal(15,3)`; added Billing/catalog-cart/marketplace/coupon/pos_receipt_lines/extra controllers; REALIGNMENT-LOG path + pos UI split.
- **v2.1:** marketplace = net (Phase 1); coupon = inclusive (Phase 2); ACCOUNT_CHARGE = inclusive (then via versioned rename).
- **v3 (three-zone model, owner 2026-06-04):** **canonical payload no longer renamed** — keep `unit_price` in signed bytes + POS device + canonical-mirror DTOs (Zone 1); rename only the backend (Zone 3) with an explicit POS→backend seam (Zone 2). Dissolves Codex BLOCKER #1 (multi-version parser) and the cutover/versioning MAJORs (no versioned event needed). ACCOUNT_CHARGE becomes a value-only correction under the unchanged key (§4.3). Verified ACCOUNT_CHARGE UI is unbuilt → separate finalization handoff.
