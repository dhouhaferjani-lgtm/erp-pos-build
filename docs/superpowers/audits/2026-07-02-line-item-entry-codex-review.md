# Line-Item Entry Standard — Codex Adversarial Review

## 1. Verdict

**REVISE.** The direction is right for the document editor: a persistent entry bar, default-first-result keyboard behavior, and scan-to-add would materially improve the current click-heavy flow. It is not yet the go-live line-entry UX for parapharmacy or scan-heavy inventory work because the spec leaves batch/FEFO and variant auto-add behavior underdefined, overstates how directly POS barcode lookup can be lifted, and does not harden the scan loop against realistic scanner and retail barcode edge cases. Ship Phase 1 only after the doc defines these contracts; otherwise the first demo that scans a batch-tracked or variant-coded parapharmacy item will expose a workflow break.

## 2. Ranked Findings

### 1. Batch/FEFO and variant scan auto-add is underspecified for the transfer rollout

**Severity:** Critical

**Evidence:** The design says scan single match should append or increment a line, and only adds a variant note: parent scans with active variants open a variant chooser before appending (`docs/superpowers/specs/2026-07-02-line-item-entry-standard-design.md:113-119`). The stock-transfer rollout says to replace the per-row picker while keeping variant/batch columns and `renderLineDetail` (`docs/superpowers/specs/2026-07-02-line-item-entry-standard-design.md:211`). Current transfer code requires more than product selection: batch-tracked lines are invalid at submit if `batchAllocations.length === 0` (`apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:443-445`), and variant-bearing products are invalid unless `variantId` is selected (`apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:447-452`). FEFO allocation only happens when the user opens the batch toggle; `BatchToggleCell.handleToggle` calls `buildFefoAllocations(...)` only on expand (`apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:214-218`). Quantity changes clear allocations (`apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:545-551`).

**Impact:** A scanned batch-tracked product can appear to “auto-add” but remain unpostable until a hidden second action occurs. For parapharmacy, where batch/expiry is core, that is worse than the current explicit picker because it creates false completion. Variant scans are similarly ambiguous: a parent match cannot safely increment an existing product line unless the variant identity is known.

**Concrete fix/doc change:** Define a scan result state machine before allowing transfer adoption:

- If scan resolves a variant barcode, add/increment `{product_id, variant_id}` directly.
- If scan resolves only a parent with active variants, do not append. Open a required variant chooser and keep scan pending.
- If scan resolves a batch-tracked product and source location is set, auto-allocate FEFO immediately for qty 1 and show an editable allocation summary; if FEFO cannot cover qty, add a blocked “needs allocation” line or reject with a specific toast.
- If source location is not set, reject transfer scans with “select source first” rather than appending an invalid line.

### 2. The lookup contract ignores existing variant barcode infrastructure

**Severity:** High

**Evidence:** The proposed `useProductLineLookup` is described as generalized from POS lookup and backed by `GET /products?barcode=` (`docs/superpowers/specs/2026-07-02-line-item-entry-standard-design.md:100-101`, `113-115`). Current product barcode filtering only exact-matches `products.barcode` or `products.sku` (`apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:234-245`). Variant barcodes are a separate model column with a unique tenant index (`apps/api/database/migrations/tenant/2026_06_02_100003_create_product_variants_table.php:19-22`, `37-41`), a server-side lookup contract exists (`apps/api/app/Modules/Catalog/Infrastructure/Adapters/EloquentProductVariantLookup.php:26-33`), and the POS variant feed exposes `barcode`, `product_id`, and price override data (`apps/api/app/Modules/POS/Presentation/Controllers/PosVariantController.php:62-75`). The variant label service explicitly documents product-code precedence over variant barcodes (`apps/api/app/Modules/Catalog/Application/Services/VariantLabelService.php:60-80`).

**Impact:** A real variant barcode can fail against `/products?barcode=` or resolve to the parent/product tier, forcing a chooser when a direct variant add should happen. This is a direct miss against Lightspeed-style variant scan behavior and parapharmacy variants.

**Concrete fix/doc change:** Replace the lookup contract with a typed endpoint/result shape such as `GET /line-entry/resolve-code?code=` returning `{kind: "product"|"variant"|"multiple"|"not_found", product, variant?, matched_code_type}`. Define precedence: exact product barcode/SKU, exact active variant barcode/SKU, then ambiguity list if multiple dimensions match. Do not make the frontend infer variant identity from `/products?barcode=`.

### 3. Scan-loop hardening is missing: in-flight scans, no-Enter scanners, AZERTY, and embedded-weight/price barcodes

**Severity:** High

**Evidence:** Current `useBarcodeScanner` only emits a scan on Enter (`apps/web/src/hooks/useBarcodeScanner.ts:68-80`) and accumulates `e.key` printable characters without layout normalization (`apps/web/src/hooks/useBarcodeScanner.ts:83-87`). The POS lookup function is async and has no in-flight request guard, abort, queue, or stale-result token (`apps/web/src/features/pos/hooks/useBarcodeLookup.ts:56-100`). Product exact lookup sends the raw code to `/products?barcode=` (`apps/web/src/features/pos/api/productApi.ts:46-53`), and the backend exact filter matches only barcode/SKU (`apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:238-245`). The design does not mention in-flight scans, scanners configured without Enter suffix, AZERTY keyboard-wedge digit behavior, or EAN/GS1 embedded price/weight parsing (`docs/superpowers/specs/2026-07-02-line-item-entry-standard-design.md:112-119`).

**Impact:** Fast repeated scans can resolve out of order or double-add unpredictably. Scanners configured with Tab/no suffix will never trigger the global scanner hook. Tunisia AZERTY deployments can produce shifted digit characters depending on wedge mode. Scale/weighted EAN-13 labels will be treated as unknown products even though they should resolve base PLU plus quantity/price.

**Concrete fix/doc change:** Add a scanner compatibility subsection:

- Normalize input from scanner events and document tested layouts: QWERTY, AZERTY, numpad, and device HID modes.
- Support suffix modes Enter, Tab, and timeout-complete, configurable per tenant/device.
- Serialize lookups with a scan queue and stale-result guard; define whether rescan during in-flight increments after resolution or is coalesced.
- Add parser hooks for GS1/EAN-13 embedded weight/price before exact product lookup. If deferred, mark weighted/scale barcode support as Phase 2+ and exclude it from demo claims.

### 4. POS barcode primitives are useful but not “already proven” for this surface

**Severity:** Medium-High

**Evidence:** `useBarcodeLookup` is not POS-store-coupled, but it is shaped around `POSProduct`, imports `fetchProductByBarcode` from POS API code, and returns POS products (`apps/web/src/features/pos/hooks/useBarcodeLookup.ts:1-24`). It only local-matches product barcode or SKU (`apps/web/src/features/pos/hooks/useBarcodeLookup.ts:62-67`). POS add behavior increments cart items without modifiers (`apps/web/src/features/pos/pages/POSPage/POSPage.tsx:144-213`) and does not carry document fields, batch allocations, variant ID, tax/discount columns, or transfer availability. Scanner hook coverage is minimal: tests only mount and disabled behavior, not timing, suffix, propagation, or layout behavior (`apps/web/src/__tests__/hooks/useBarcodeScanner.test.ts:5-20`).

**Impact:** Treating the POS path as “wiring existing parts together” understates implementation risk. The reusable hook must become a line-entry resolver with domain outcomes, not a thin wrapper around POS product lookup.

**Concrete fix/doc change:** Reword Phase 1 from “generalize POS lookup” to “build a new line-entry resolver using the POS hook as reference.” Require tests for scan suffix handling, stale lookup ordering, product vs variant resolution, duplicate increment key `{product_id, variant_id, batch_allocation_state}`, and invalid transfer preconditions.

### 5. Pricing-context Phase 2 needs a bulk API and a stricter margin policy contract

**Severity:** Medium

**Evidence:** The spec proposes singular `GET /products/{id}/pricing-context?partner_id=` (`docs/superpowers/specs/2026-07-02-line-item-entry-standard-design.md:177-204`) but also says “Fetch strategy: batch” (`docs/superpowers/specs/2026-07-02-line-item-entry-standard-design.md:204`). Current live margin check is per product+sell price via `POST /pricing/check-margin` (`apps/api/app/Modules/Pricing/Presentation/routes.php:80-83`), and the frontend `PriceInputWithMargin` debounces per product+price (`apps/web/src/features/inventory/components/pricing/PriceInputWithMargin.tsx:69-81`). Existing `MarginService` does not merely warn: below minimum margin returns `allowed: false` without `pricing.sell_below_minimum_margin` (`apps/api/app/Modules/Product/Application/Services/MarginService.php:328-337`), and below target can also block without permission (`apps/api/app/Modules/Product/Application/Services/MarginService.php:339-347`). Product already stores `last_purchase_cost` (`apps/api/app/Modules/Product/Domain/Product.php:49-53`, `115-160`) and WAC updates maintain it (`apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:284-287`), so the “single indexed StockMovement lookup” should be only for `last_purchase_at` or audit detail, not the main cost value.

**Impact:** A 50-line invoice can create per-line pricing-context and per-edit margin-check storms. Worse, UI semantics can contradict backend permission enforcement: the spec says “default: warn, allow with permission,” while current service can block below target/minimum based on permission.

**Concrete fix/doc change:** Define `POST /line-entry/pricing-context/bulk` with `{partner_id, lines:[{product_id, variant_id?, unit_price?}]}` and a response keyed by line/product. Include `policy: {level, allowed, requires_permission?}` from `MarginService::canSellAtPrice`, not a client-side interpretation. Use `products.last_purchase_cost` for the value and optionally include `last_purchase_movement_at` from stock movements.

### 6. Phase 1 demo scope is too broad; stock transfers are the risky cut

**Severity:** Medium

**Evidence:** Phase 1 bundles `ProductCell`, `useProductLineLookup`, `LineItemEntryBar`, typed keyboard loop, scan-to-add, multi-match chooser, not-found toast, `DocumentLineEditor` integration, stock-transfer integration, and scanner verification into 10-14 agent-hours (`docs/superpowers/specs/2026-07-02-line-item-entry-standard-design.md:221-228`). The transfer page has product, variant, availability, quantity, batch, and actions columns (`apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:499-592`) and source-location-dependent batch behavior (`apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:620-628`, `680-709`).

**Impact:** The broadest integration is the one with the hardest correctness constraints. Rushing it for demo risks showing a slick add action followed by validation failure.

**Concrete fix/doc change:** Cut stock-transfer scan-to-add from demo unless the batch/variant state machine above is implemented. Demo Phase 1 should be `DocumentLineEditor` only plus non-batch product scan tests. Keep transfer adoption as Phase 1B with a narrow demo dataset or Phase 2.

## 3. Industry-Gap Table

| Capability | This design | Odoo 17/18 | ERPNext | Dynamics BC | SAP B1 | Lightspeed/Cin7 |
|---|---|---|---|---|---|---|
| Persistent scan-to-add above document lines | Supported in spec | Partial/strong in Barcode app, not generic doc-line claim | Partial/unknown | Partial via field entry, not native scan bar | Partial/add-on-heavy | Supported in retail/inventory flows |
| Re-scan increments existing line | Supported in spec | Unknown by doc reviewed | Unknown | Unknown | Unknown | Supported/expected in retail flows |
| Product+SKU+barcode single lookup | Supported | Supported/partial | Supported/partial | Partial | Partial | Supported |
| Variant barcode resolves exact variant | Partial/missing contract | Supported/partial via variants/barcodes | Unknown | Partial item tracking | Partial | Supported/expected |
| Batch/lot/FEFO scan allocation | Missing/underspecified | Supported in barcode/inventory scope; docs include lots, serials, GS1, FEFO navigation | Partial/unknown | Supported item tracking, separate lines page | Supported/partial | Partial; Cin7 stronger for inventory |
| GS1 / weighted / embedded price barcodes | Missing | Supported/partial; Odoo docs list GS1 nomenclature | Unknown | Unknown | Unknown | Partial/retail-dependent |
| Keyboard-first document line entry | Supported in spec | Partial | Partial | Supported; BC docs cover field shortcuts and item tracking pages | Partial | Partial |
| Pricing context: last cost / margin warning | Partial Phase 2 | Partial; margins/pricelists | Partial | Partial | Partial | Supported/partial; Cin7 stronger for purchasing context |
| Bulk multi-select catalog picker | Phase 3 | Supported/partial | Unknown | Partial | Unknown | Supported/partial |

Reference notes: Odoo 18 docs list barcode setup and operations including lot/serial barcodes, transfers, GS1 usage, and FEFO removal strategy navigation (https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/barcode.html). Business Central item tracking docs require serial/lot quantities to match document lines and expose availability warnings on item tracking pages (https://learn.microsoft.com/en-us/dynamics365/business-central/inventory-how-work-item-tracking). Business Central data-entry docs document keyboard picker and field-navigation behavior (https://learn.microsoft.com/en-us/dynamics365/business-central/ui-enter-data).

## 4. Phase 1 Demo-Day Scope-Cut Recommendation

The riskiest piece is `CreateStockTransferPage` scan-to-add because it crosses product resolution, variant selection, source-location availability, and mandatory batch allocations. Cut transfer scan-to-add first. Keep the visible demo to `DocumentLineEditor`: persistent entry bar, first-result Enter, exact product barcode scan, duplicate rescan increments qty, and not-found create-product prompt. If transfers must be shown, restrict it to non-variant, non-batch products and label it as Phase 1B pending the batch/variant state machine.

## 5. Concrete Doc Changes

1. **Section 3.1 B, replace the scan single-match bullets (`docs/...standard-design.md:114-119`) with:**

   ```md
   On scan, resolve the code through a line-entry resolver that returns a typed result:
   - `variant`: exact active variant barcode/SKU. Add/increment the line by `{product_id, variant_id}`.
   - `product`: exact product barcode/SKU with no active variants. Add/increment by `{product_id, null}`.
   - `product_requires_variant`: open a required variant chooser; do not append until selected.
   - `batch_required`: if source/location context exists, auto-allocate FEFO for qty 1; otherwise reject with "select source first". If FEFO cannot cover qty, create a blocked needs-allocation state or open the batch allocation panel immediately.
   - `multiple`: open chooser with product/variant/batch context.
   - `not_found`: toast and offer create-product where allowed.
   ```

2. **Section 3.1 B, add a scanner compatibility subsection after Enter-suffix safety:**

   ```md
   Scanner compatibility contract: support Enter, Tab, and timeout-complete suffix modes; normalize keyboard-wedge input for QWERTY/AZERTY/numpad digit paths; serialize scans through a FIFO queue with stale-result guards; parse configured GS1/EAN-13 embedded weight/price codes before exact lookup. Weighted/scale barcodes are not demo-ready until this parser has tests.
   ```

3. **Section 3.1 new components, replace the `useProductLineLookup` bullet (`docs/...standard-design.md:100-101`) with:**

   ```md
   `useProductLineLookup` calls a new line-entry code resolver, not `/products?barcode=` directly. The resolver must check product and active variant identifiers and return a typed add outcome (`product`, `variant`, `requires_variant`, `batch_required`, `multiple`, `not_found`).
   ```

4. **Section 3.4 rollout step 3 (`docs/...standard-design.md:211`) change to:**

   ```md
   CreateStockTransferPage adopts the entry bar only after the batch/variant scan state machine is implemented. Until then, keep the existing row picker for batch/variant transfer lines or restrict scan-to-add to products with no active variants and no batch tracking.
   ```

5. **Section 3.3 pricing endpoint (`docs/...standard-design.md:177-204`) replace singular endpoint with:**

   ```md
   POST /line-entry/pricing-context/bulk
   Request: { partner_id, lines: [{ product_id, variant_id?, unit_price? }] }
   Response: { items: { [product_id_or_line_key]: { cost_wac, last_purchase_cost, last_purchase_at?, last_sale_to_partner?, suggested_price, margins, policy } } }
   `policy` is computed server-side through MarginService::canSellAtPrice so warn/block semantics match permissions.
   ```

6. **Section 3.5 Phase 1 (`docs/...standard-design.md:221-228`) revise scope to:**

   ```md
   Phase 1 demo: ProductCell, LineItemEntryBar, product-only resolver, DocumentLineEditor integration, keyboard-first add, exact product barcode scan, rescan qty increment, not-found create-product prompt. Exclude stock transfers, variant barcode direct-add, batch/FEFO auto-allocation, GS1/weighted barcodes, and bulk catalog picker unless explicitly pulled into Phase 1B.
   ```

7. **Section 6 open questions (`docs/...standard-design.md:284-288`) add two owner decisions before implementation:**

   ```md
   4. Transfer scan behavior: should batch-tracked products auto-allocate FEFO on scan, or should scan open the batch allocation panel and require explicit confirmation?
   5. Scanner estate: what suffix and keyboard layout are deployed for Tunisia sites (Enter, Tab, none; AZERTY keyboard-wedge vs HID numeric)?
   ```
