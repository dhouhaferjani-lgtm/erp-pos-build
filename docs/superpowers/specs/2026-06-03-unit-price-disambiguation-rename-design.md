# `unit_price` Disambiguation Rename — Design / Spec

> **Status:** Approved design, **v3.1 (three-zone model, post-2nd-Codex-review)** — 2026-06-04. Spec only — no code.
> **Branch:** `feat/unit-price-rename-spec` (off `dev`).
> **Adversarial reviews:** [r1 v1](../reviews/2026-06-03-unit-price-rename-spec-codex-review.md) — REQUEST-CHANGES (2 BLOCKER + MAJOR/MINOR + 7 missed-surface groups); [r2 v3](../reviews/2026-06-04-unit-price-rename-spec-v3-codex-review.md) — REQUEST-CHANGES (1 BLOCKER / 2 MAJOR / 2 MINOR / 1 NIT). All findings verified against code and addressed. v3 dissolved the signed-byte blockers by **not renaming the canonical payload**; v3.1 fixes the ACCOUNT_CHARGE facture-bridge coupling and expands the backend POS seam/read surfaces. Changelog in §11.
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
- No change to tax math, rounding, or fiscal-integrity invariants — only names change. The ACCOUNT_CHARGE net→inclusive *value* correction is **deferred out of this rename** to the charge-to-account finalization (see the ACCOUNT_CHARGE note in §4/§6).
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
- **ACCOUNT_CHARGE → document-net seam (Zone 2, B2B side):** `DocumentAccountChargeFactureBridge` + `POSAccountChargeDraftService` read canonical `unit_price` (Zone 1, key unchanged) and write `document_lines` → rename their write target to `unit_price_excl_tax`. **Value stays net** in this rename (canonical is still net); the eventual canonical→inclusive correction + net derivation is owned by the charge-to-account finalization (§ ACCOUNT_CHARGE note). Update `DocumentAccountChargeFactureBridgeTest`.
- **Web (`apps/web`) B2B:** regenerate `packages/shared/types/generated.d.ts` (`php artisan typescript:transform`); manual mirror `apps/web/src/types/document.ts`; `DocumentLineEditor`, document detail pages, `CreateCreditNotePage`, `CreateReturnNotePage`, landed-cost, goods-receipt; fixtures + e2e.
- **Fiscal-byte impact:** none.

### Phase 2 — backend POS-origin (inclusive) → `unit_price_incl_tax` + the seam (moderate risk)

Rename the backend storage/DTOs that hold POS (inclusive) data, and make the Zone-2 seam explicit. **Canonical bytes and the POS device-local code are NOT touched.**
- **POS order storage:** `pos_order_lines.unit_price` → `unit_price_incl_tax` (`decimal(15,3)`); `OrderLineData` DTO (+ regenerated TS).
- **POS receipt projection/storage:** `pos_receipt_lines.unit_price` → `unit_price_incl_tax` (`decimal(15,3)`); `ReceiptLine`; `ReceiptCreationService`, `ReceiptFinalizationService`, `StoreReceiptRequest`.
- **Backend POS order/held-order ingestion & APIs (Codex r2 M1 — expanded):** `AddOrderLineRequest`, `OrderController`, `OrderManagementService`, `HoldOrderRequest`, `HeldOrderService`, `HeldOrder` (snapshot), `OrderLineResource` (API response), `OrderToReceiptService`. These accept, store, compute from, or return inclusive POS `unit_price`.
- **Receipt / refund / reporting re-exposure:** `ReceiptController`, `ReceiptReturnService`, `Nf525DataProvider` (legacy NF525 export reads `pos_receipt_lines`), `resources/views/pos/receipt.blade.php` (print view).
- **The projection seam (Zone 2):** `PosCoreReceiptProjection` reads canonical `LineItemDTO::unitPrice` (key `unit_price`, inclusive) and writes the renamed column `unit_price_incl_tax`. Document the boundary in code + the precision contract; add a test asserting canonical `unit_price` → storage `unit_price_incl_tax`, value preserved verbatim.
- **Wire-contract rule (Codex r2 M2):** the HTTP boundary between the POS device and the backend keeps the JSON key **`unit_price`** (device speaks inclusive `unit_price`; no app churn, stays aligned with canonical). The backend ingress maps wire `unit_price` → storage `unit_price_incl_tax` at the request/controller layer (`AddOrderLineRequest`, `HoldOrderRequest`, `OrderController`), and `OrderLineResource`/receipt responses map storage `unit_price_incl_tax` → wire `unit_price` on the way out. `apps/pos/src/api/{holdApi,reportApi}.ts` therefore stay `unit_price` **as a wire contract** (not device-local state) — documented as such, with the mapping + tests living on the backend boundary.
- **Coupon/discount preview (POS, inclusive):** `ValidateCouponRequest`, `CouponController`, `DiscountController`, `apps/web/src/features/coupons/api/couponApi.ts` — request payloads carrying POS cart lines (apply the same wire-contract rule: wire `unit_price`, backend treats as inclusive).
- **Web POS (`apps/web/src/features/pos/**`):** consumes the renamed inclusive backend fields.
- **DB constraints (Codex r2 m1):** `pos_receipt_lines` has PostgreSQL CHECK constraints referencing `unit_price` (incl. `line_total = (unit_price * quantity) - discount_amount`, defined `2026_01_08_190638`, replaced `2026_03_09_200000`). Expand/contract sub-step: add new column, backfill, dual-write, **replace constraints to reference `unit_price_incl_tax`**, switch reads, drop old column in the later tenant migration. Check `pos_order_lines` for the same.
- **Zone-1 PHP canonical-mirror DTOs stay `unit_price`:** `Fiscal/Domain/DTOs/Canonical/LineItemDTO.php` (`$unitPrice`), `SaleReceiptCanonicalView`, `CanonicalPayloadReader`, `SaleReceiptPayload.php`, `AccountChargePayload.php`, `FiscalPayloadConstraintValidator` — these mirror the signed bytes and are explicitly **not** renamed.
- **Fiscal-byte impact:** none.

### ACCOUNT_CHARGE value correction (net → inclusive) — **DEFERRED to charge-to-account finalization; NOT in this rename's scope**

> Not a rename — a **value-semantics correction** inside Zone 1, kept under the unchanged key `unit_price`. **Codex r2 B1 showed it is NOT safe in isolation** — it is coupled to a server-side consumer.

ACCOUNT_CHARGE is device-authored from the inclusive cart, so its `line_items[].unit_price` *should* be the gross/inclusive cart price verbatim (net in `line_subtotal`), like SALE_RECEIPT. Today it is authored **net** as a placeholder convention; the feature is **not UI-wired** and the PHP validator is **tax-agnostic** for this event. **But there is a registered server consumer that depends on the current net value:** `DocumentAccountChargeFactureBridge` → `POSAccountChargeDraftService` copies canonical `line_items[].unit_price` straight into `document_lines.unit_price` (the **net** B2B field; `DocumentAccountChargeFactureBridgeTest` asserts `100.000`) for `b2b_facture_draft_requested` charges. The same canonical value also feeds `PosCoreReceiptProjection` → `pos_receipt_lines` (which is **inclusive**). One canonical value cannot be both: correcting it to inclusive REQUIRES the facture bridge to **derive** the document net from canonical net fields (`line_subtotal` / quantity) instead of copying `unit_price`.

Therefore this correction is **out of scope for the rename** and is handed to the **charge-to-account finalization** work (which owns ACCOUNT_CHARGE end-to-end, incl. the facture-bridge conversion + replay/idempotency tests). This rename keeps ACCOUNT_CHARGE canonical key `unit_price` unchanged (Zone 1) and only renames the backend columns its consumers write to (the facture bridge's `document_lines` write becomes `unit_price_excl_tax`, value still net while canonical is net — see the facture-bridge seam in §4 Phase 1 / §7). The finalization handoff prompt records this coupling.

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
- **ACCOUNT_CHARGE — value correction deferred (see §4 ACCOUNT_CHARGE note).** Canonical key stays `unit_price` (Zone 1, unchanged here); the net→inclusive value correction + facture-bridge net derivation are owned by the charge-to-account finalization. The charge amount (`totals.amount_charged_to_account`, `local_balance_snapshot`) is separate and is not a `unit_price`.
- **`fiscal_schema_version` / versioned events — NOT needed** in v3 (no canonical rename). The earlier cutover-lever concern is moot.
- **ACCOUNT_CHARGE UI is unbuilt** — engine/service/projection are on `dev`; the checkout wiring is not. Tracked via a separate finalization handoff (the charge-to-account spec/plan already exist on `dev`).
- **After-T2** — re-run §7 inventory against post-T2 `dev` before executing.

---

## 7. Surface classification table (snapshot — re-verify post-T2)

> Captured 2026-06-03/04 against `origin/dev`. Authoritative scope. Every `unit_price` outside tests/archives must appear with a zone + phase + evidence.

### Zone 1 — keep `unit_price` (NOT renamed)
| Surface | Files | Why kept |
|---|---|---|
| POS device-local cart/storage/UI | `apps/pos/src/types/cart.ts`, `stores/cartStore.ts`, `types/receipt.ts`, `lib/buildReceiptData.ts`, `lib/offline/*`, `lib/refundFlow/*`, cart/receipt components, `pages/HomePage.tsx` | uniformly inclusive; aligned with canonical key |
| Canonical builders (device) | `apps/pos/src/lib/fiscal/payloads/SaleReceiptPayload.ts`, `AccountChargePayload.ts`, `FiscalEventEngine.ts`, `FiscalEventPayloadRegistry.ts` | signed bytes — never renamed |
| Canonical-mirror DTOs (server) | `Fiscal/Domain/DTOs/Canonical/LineItemDTO.php`, `SaleReceiptCanonicalView.php`, `CanonicalPayloadReader.php`, `SaleReceiptPayload.php`, `AccountChargePayload.php`, `FiscalPayloadConstraintValidator.php` | mirror the bytes verbatim |
| POS↔backend **wire contract** (not device-local) | `apps/pos/src/api/holdApi.ts`, `apps/pos/src/api/reportApi.ts` | JSON key stays `unit_price` for back-compat; backend maps to `unit_price_incl_tax` (§4 Phase 2 wire-contract rule) |

### Zone 3 — Phase 1, net → `unit_price_excl_tax`
| Surface | Files (representative) |
|---|---|
| Document line DTO/DB/events | `DocumentLineData`, `document_lines` (`2025_11_30_080001`, widened `2026_03_11_200000:60`), `DocumentLine`, `DraftLineAdded(V2)`, `DraftLineModifiedV2` |
| Document services/controllers | `DocumentTotalsCalculator`, `DraftPersistenceService`, `Conversion/*`, `CreditNoteService`, `RefundService`, `FacturXService`, `POSAccountChargeDraftService`; `UpdateDocumentRequest`, `RefundController`, `Quote/SalesOrder/Invoice/DeliveryNote/PurchaseOrder/ReturnNote` controllers, `DocumentAdditionalCostController` |
| ACCOUNT_CHARGE→document-net seam (B2B) | `DocumentAccountChargeFactureBridge`, `POSAccountChargeDraftService:88,220`, `DocumentAccountChargeFactureBridgeTest:96` — reads canonical `unit_price`, writes `document_lines` → `unit_price_excl_tax` (value stays net; canonical→inclusive correction owned by charge-to-account finalization) |
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
| POS receipt storage | `pos_receipt_lines` (`2026_01_08_190638`, widened `2026_03_11_200000:78`; CHECK constraints `…190638:76,82` replaced `2026_03_09_200000:74,118,124`), `ReceiptLine`, `ReceiptCreationService`, `ReceiptFinalizationService`, `StoreReceiptRequest` |
| Backend POS order/held-order ingestion & API | `AddOrderLineRequest:46`, `OrderController:171`, `OrderManagementService:245`, `HoldOrderRequest:43`, `HeldOrderService:99`, `HeldOrder:195`, `OrderLineResource:34`, `OrderToReceiptService:49` |
| Receipt/refund/reporting re-exposure | `ReceiptController:314`, `ReceiptReturnService:799`, `Nf525DataProvider:1069`, `resources/views/pos/receipt.blade.php:398` |
| **Projection seam (Zone 2)** | `PosCoreReceiptProjection` — canonical `unit_price` → column `unit_price_incl_tax`; document + test (value preserved) |
| **Wire-contract boundary (Zone 2)** | HTTP keeps key `unit_price`; backend ingress (`AddOrderLineRequest`/`HoldOrderRequest`/`OrderController`) maps → storage `unit_price_incl_tax`; `OrderLineResource`/receipt responses map back to wire `unit_price` |
| Coupon/discount preview | `ValidateCouponRequest`, `CouponController`, `DiscountController`, `apps/web/src/features/coupons/api/couponApi.ts` |
| Web POS | `apps/web/src/features/pos/**` |

### Shared/seed + generated/baseline/archive disposition (Codex r2 m2)
- **Regenerate:** `packages/shared/types/generated.d.ts` (via `typescript:transform`); factories (`CatalogCartItemFactory`, `ServiceBundleComponentFactory`, `Workshop/WorkOrderLineFactory`); `DemoTenantSeeder`.
- **Update if still referenced:** `apps/api/phpstan-baseline.neon` entries mentioning `unit_price`.
- **Update or mark test-only:** diagnostic commands `TestTaxRecoverability`, `TestE2EGLPosting`.
- **Exclude (immutable archive, not runtime/source policy):** `apps/api/backup_before_phase0.sql`.
- ACCOUNT_CHARGE fixtures: **not changed by this rename** (value correction deferred to charge-to-account finalization; key unchanged).

---

## 8. Testing strategy

TDD throughout (PHPUnit + Vitest + real seeders).
- **Phases 1–2:** existing suites stay green post-rename; expand/contract migration tests per column (add, backfill, dual-write equivalence, post-drop reads) on the `tenant/` path; **Zone-2 seam test** (canonical `unit_price` → `pos_receipt_lines.unit_price_incl_tax`, value preserved); web type-check + ESLint + e2e.
- **Canonical regression:** assert SALE_RECEIPT/ACCOUNT_CHARGE canonical bytes and hashes are **unchanged** by Phases 1–2 (the rename must not touch the signed payload). This is the key guard that the backend rename never leaks into Zone 1.
- **ACCOUNT_CHARGE value correction:** out of scope here — its test (authored `unit_price` is gross/inclusive; facture bridge derives net) lives in the charge-to-account finalization work.

---

## 9. Risks & mitigations

| Risk | Severity | Mitigation |
|---|---|---|
| Accidentally renaming a canonical-mirror DTO and changing signed bytes | High | Zone-1 keep-list (§7); canonical bytes/hash unchanged-regression test |
| Wrong replacement column scale (precision violation) | High | Effective `decimal(15,3)` + `decimal:3` casts, not create-migration scales |
| Seam mismaps inclusive↔net at POS→backend | High | Explicit Zone-2 projection + wire-contract mappings; value-preservation tests both directions |
| ACCOUNT_CHARGE value correction (net→incl) lands inclusive into the net facture-draft field | High | **Deferred out of this rename**; coupled to `DocumentAccountChargeFactureBridge` which must derive document net from canonical net fields; owned by charge-to-account finalization |
| Missed surface preserves overload (esp. backend POS read/API/print) | Medium | Authoritative §7 table incl. order/held/receipt/NF525/print surfaces; re-inventory post-T2 |
| Stale PG CHECK constraints after column drop | Medium | Migration sub-step replaces constraints to reference `unit_price_incl_tax` before drop |
| Expand/contract dual-write divergence across tenant DBs | Medium | Dual-write equivalence test; drop only after all tenants read-switched |
| Bare `unit_price` lingers in generated/baseline/diagnostic backend files | Low | Explicit disposition list (§7) — regenerate / update / exclude archives |
| Conflict with in-flight T2 | Medium | Hard sequencing gate (§3) + re-inventory |

---

## 10. Open items to resolve in the plan / at execution

1. Re-run the §7 surface inventory against post-T2 `dev`.
2. Confirm the actual `REALIGNMENT-LOG.md` path at the monorepo root.
3. Hand the ACCOUNT_CHARGE value correction + facture-bridge net-derivation to the charge-to-account finalization work (not this rename); ensure that handoff records the coupling.
4. Log the `product.sale_price` net-vs-gross latent concern (§6) separately.
5. Decide the held-order / order-resource / receipt-API external JSON contract: confirm the wire-contract rule (keep wire `unit_price`, map at backend) is acceptable to all clients, or rename the wire too.

---

## 11. Changelog

- **v1:** initial three-phase design (B2B; B2C non-fiscal; canonical SALE_RECEIPT v2, deferred).
- **v2 (Codex review):** per-surface classification; added ACCOUNT_CHARGE as a second signed surface; multi-version parser design; effective `decimal(15,3)`; added Billing/catalog-cart/marketplace/coupon/pos_receipt_lines/extra controllers; REALIGNMENT-LOG path + pos UI split.
- **v2.1:** marketplace = net (Phase 1); coupon = inclusive (Phase 2); ACCOUNT_CHARGE = inclusive (then via versioned rename).
- **v3 (three-zone model, owner 2026-06-04):** **canonical payload no longer renamed** — keep `unit_price` in signed bytes + POS device + canonical-mirror DTOs (Zone 1); rename only the backend (Zone 3) with an explicit POS→backend seam (Zone 2). Dissolves Codex r1 BLOCKER #1 (multi-version parser) and the cutover/versioning MAJORs (no versioned event needed). Verified ACCOUNT_CHARGE UI is unbuilt → separate finalization handoff.
- **v3.1 (Codex r2):** B1 — ACCOUNT_CHARGE value correction is **coupled** to `DocumentAccountChargeFactureBridge` (copies canonical `unit_price` into the net `document_lines` field), so it is **removed from this rename** and handed to charge-to-account finalization; this rename only renames the bridge's write target (value stays net). M1 — expanded Phase 2 with backend POS order/held-order/receipt/refund/NF525/print seam+read surfaces. M2 — wire-contract rule: HTTP key stays `unit_price`, backend maps to `unit_price_incl_tax`; `apps/pos/src/api/*` reclassified as wire contract, not device-local. m1 — PG CHECK-constraint replacement migration sub-step. m2 — generated/baseline/diagnostic/archive disposition. n1 — corrected r1 BLOCKER count (2).
