# Spec C — Variant Label Printing (web back-office PDF)

**Revision:** v2 (Codex adversarial review resolved — see §9)
**Date:** 2026-06-18
**Branch:** `feat/variant-label-printing` (off `dev`)
**Worktree:** `apps/erp.variant-labels`
**Program:** Variant program, Spec C of 3 (A = POS offline scan-to-variant — merged to local dev; B = web-admin authoring — merged to local dev; **C = label printing**).
**Surface:** `apps/api` (Catalog + a new label service; Pricing for effective price) **and** `apps/web` (catalog/products admin UI). **NOT** the POS terminal.

---

## 0. Purpose & program fit

Retail variants usually have **no manufacturer barcode** (the `barcode` field is nullable and typically empty for size/colour SKUs), so there is nothing to scan at the till. Spec A built **scan-to-variant** (scan a variant's barcode → add that variant; scan a product barcode for a `has_variants` product → open the variant picker). Spec A can only scan a code that **exists**. **Spec C generates and prints that code** as an own-label tag, closing the loop: print (C) → stick on item/box/shelf → scan at POS (A).

This is a **back-office** function (confirmed by research — mature platforms generate labels from Products/Item-Search/Purchase-Orders, never from the sale terminal). v1 is a **web-admin PDF label-sheet generator**, designed so dedicated thermal label printers (ZPL) and a 2D/QR layer can be added later without reworking the model.

### Research basis (cited report `docs/superpowers/research/` / workflow 2026-06-17)
- **Symbology = Code 128** for own-label internal SKU/variant codes (full ASCII, no GTIN structure). GS1 **RCN** (02/20–29) is national-only / EAN-13-only — too restrictive; **GS1-128** is logistics-only — not for retail item tags.
- **"2D-ready" ≠ build GS1 Digital Link now.** Sunrise/Ambition 2027's minimum is *reading* a GTIN from a 2D code at POS; on-pack 2D must still carry a 1D barcode until ~90% scanner coverage. **1D Code 128 today is correct and future-safe**; 2D is an additive later layer.
- **Output = PDF label sheets** (Avery/A4 on office/laser printers) is the standard simple path; dedicated thermal label printers (ZPL) are the best-case upgrade.

---

## 1. Current state (grounded in code)

- **Variant model** (`product_variants`): `sku` (required, tenant-unique), `barcode` (nullable, **tenant-unique** partial index `product_variants_tenant_barcode_unique`), `name_suffix`, `price_override`, junction `attribute_values`. DTO `ProductVariantData` (now incl. `attribute_values` from Spec B).
- **Scan round-trip (Spec A):** the variant-barcode tier matches a variant's **`barcode`** field (`getVariantByBarcode`). A printed code only scans back if it equals a stored `barcode`.
- **Pricing:** `App\Modules\Pricing\Domain\Services\PricingService::getPrice(...)` resolves effective price for a product **+ optional variant** (order: variant `price_override` → partner price-list variant-specific → variant-agnostic → …). This is the source of truth for the label price.
- **PDF:** `barryvdh/laravel-dompdf` present (used by `ReceiptPdfService` — HTML Blade → PDF). `endroid/qr-code` present (QR SVG). **No 1D barcode generator** in `composer.json` — must add one.
- **Frontend:** `ProductVariantMatrixEditor` (Spec B) lists variants; no print/label UI. No PDF lib in web (`apps/web`), and none needed — PDF is generated server-side and downloaded.
- **POS printing:** `escpos.rs` can emit Code128/QR to thermal printers — **out of scope for C** (labels are not printed from the POS terminal).

---

## 2. Decisions (locked with owner, 2026-06-18)

1. **Barcode value + scan round-trip:** the label encodes the variant's **`barcode`**. When a variant's `barcode` is **empty at print time**, the system **assigns `barcode = sku` and persists it**, then encodes that — one source of truth that both printing (C) and scanning (A) use. (Edge: if `sku` already collides with another variant's `barcode` in the tenant, skip the auto-assign and surface the variant as "needs a unique barcode" rather than persist a duplicate — see §4.)
2. **Price = effective price**, resolved via `PricingService::getPrice(product, variantId, …)` — not the raw `price_override` (which is usually null).
3. **Trigger/scope (v1):** (a) **"Print labels" from the product/variant editor** — pick variants of one product + a per-variant quantity; (b) **bulk multi-product select → print** from the product list. A full Label Queue / per-PO automation is deferred.
4. **Formats (v1):** **2–3 sheet templates** — Avery **L7160** (63.5×38.1 mm, 3×7=21/sheet), a **single 4×6** label, and **one configurable grid** (rows×cols, margins). No template designer.

---

## 3. Design

### 3.1 Symbology selection (per variant code)
- **13-digit numeric** with a valid EAN-13 check digit → render **EAN-13**.
- **12-digit numeric** (UPC-A) → **pad to 13 digits** (leading zero) and validate the EAN-13 check digit; if valid → render **EAN-13**, else → Code 128. (Mirrors the existing `BarcodeLookupService` 12→13 padding precedent.) *(Codex MED-3.)*
- **Everything else** (alphanumeric SKU-derived codes, 8-digit, invalid check digit, any non-GTIN value) → render **Code 128**. This is the dominant case for own-label.
- Human-readable text printed under the bars.

### 3.2 Barcode generation (backend) — PNG, not SVG
- Add **`picqer/php-barcode-generator`** and use its **PNG renderer**, embedded as a **base64 `<img>`** in the dompdf HTML. *(Codex HIGH-3.)* Rationale: dompdf's SVG rendering of fine barcode bars (sub-mm thin/thick) is historically unreliable; the codebase already fell back to **base64 PNG** for QR in `Taxation\…\CertificatePDFService` for exactly this reason (while `ReceiptPdfService` got away with inline QR SVG). PNG is the proven path.
- A `VariantLabelBarcodeRenderer` produces the base64 PNG `data:` URI for a given (value, symbology).
- **Plan must include a render proof-of-concept** (generate a real Code 128 + EAN-13 label PDF and visually confirm the bars render and scan) as the first implementation step, before building the full flow.

### 3.3 Label data assembly (backend)
- `VariantLabelService` builds, per requested variant, a `VariantLabelData` row: `product_name`, `name_suffix`, `effective_price`, `barcode_value` (post-assign), `symbology`, `sku`, `shop_name`.
- **Effective price** *(decision #2; Codex HIGH-2)*: resolve via the real signature
  `PricingService::getPrice(productId: $product->id, partnerId: null, quantity: '1.00', currency: $company->currency, date: null, variantId: $variant->id)` — **named args** (the params are productId, partnerId, quantity, currency, date, variantId in that order; passing variantId positionally as partnerId is the trap). A label is partner-less, qty 1, today, company currency. Result is a decimal **string**; format with the money helper (never cast to float). `shop_name = Company::$name` (trade name, not `legal_name`). *(Codex LOW-3.)*
- **Barcode assignment** *(decision #1; happens in the `prepare` step, §3.5 — an explicit user action, NOT a download side-effect — Codex MED-1)*: for a variant with empty `barcode`, assign `barcode = sku` and persist through **`ProductVariantService::saveBarcodeSafe()`** (NOT the bare repository `save()`, which would surface a raw `QueryException`/500 — Codex HIGH-1). Before assigning, the value must be **unambiguous for scan round-trip** *(Codex BLOCKER-1)*: Spec A resolves **product** barcode/SKU (in-memory Tier 1) **before** the variant tier, and product codes are not unique-indexed — so the candidate value must not collide with **(a)** any other variant's `barcode`, **(b)** any product's `barcode`, or **(c)** any product's `sku` in the tenant. On any collision (or a `saveBarcodeSafe` 422), do **not** persist — mark the variant `skipped` with reason `barcode_conflict` and exclude it. (A variant whose `sku` happens to equal a product code is rare given `variant.sku = product.sku + '-' + suffix`, but must be guarded so the printed code never mis-scans to a product.)
- **Variant scoping before pricing** *(Codex MED-2)*: `PricingService` resolves the variant via an **unscoped** `ProductVariantLookup::findById`. The controller MUST validate each `variant_id` is tenant+company-scoped **before** calling the service, so a cross-tenant id can't be priced/printed.

### 3.4 PDF generation (backend)
- `VariantLabelPdfService` (parallels `ReceiptPdfService`): a Blade template `catalog.variant-labels` renders the chosen sheet format (CSS grid sized to the template's label dimensions; page size A4 or the 4×6 label) with one cell per label instance (a variant repeated `quantity` times), each cell showing name/suffix/price/barcode-PNG (base64 `<img>`)/human-readable/shop-name.
- Sheet templates are defined as a small server-side registry (`LabelSheetFormat` value objects: key, page size, label w/h, rows, cols, margins, gutters) — extensible.

### 3.5 API (Catalog module) — two endpoints (a single response can't be both PDF and JSON)
*(Codex BLOCKER-2: the prior single-endpoint design tried to stream a PDF AND return `meta.skipped` JSON — impossible. Split into a JSON prepare step + a binary download step.)*

1. **`POST api/v1/labels/variants/prepare`** (JSON) — body `{ items: [{ variant_id, quantity }] }`. Validates + tenant/company-scopes each variant; performs the **barcode assignment** (§3.3); returns
   `{ data: { ready: [{ variant_id, quantity, barcode_value, symbology }] }, meta: { skipped: [{ variant_id, reason }] } }`.
   This is the only step that mutates (assigns `barcode=sku`), so it's an explicit action.
2. **`POST api/v1/labels/variants/pdf`** (binary) — body `{ format: <formatKey>, items: [{ variant_id, quantity }], start_cell?: int }` → streams `application/pdf` for the given (already-prepared) variants. Idempotent: it only reads + renders; it does **not** assign barcodes. A variant still lacking a usable barcode here is an error (422) — the client should only send variants `prepare` returned as `ready`.
3. **`GET api/v1/labels/formats`** — returns the `LabelSheetFormat` registry (key, label, dimensions, rows×cols) for the UI picker.

- **Permission:** new `catalog.labels.print`, gated by the standard middleware, **added to `RolesAndPermissionsSeeder` + the manager-role mapping** (alongside the existing `catalog.attributes.*`/`catalog.variants.*`). *(Codex MED-4.)*
- **Validation:** `format` ∈ registry; `items` non-empty, **array length ≤ a sane cap** (not just total labels); `items.*.variant_id` uuid + tenant/company-scoped; `quantity` integer ≥ 1; **Σ quantity ≤ `MAX_LABELS_PER_REQUEST` (1000)**; `start_cell` integer ≥ 0 and **< rows×cols** of the chosen format (zero-based; same for the custom grid) — else 422. *(Codex MED-5, LOW-2.)*

### 3.6 Frontend (web-admin, catalog feature)
- **Per-product:** a **"Print labels"** action in/near `ProductVariantMatrixEditor` → opens a `VariantLabelDialog` listing the product's variants with a per-variant **quantity** input, a **format** select (from `GET /labels/formats`), and an optional **start-cell**.
- **Bulk:** product-list multi-select → "Print labels" → same dialog seeded with the selected products' active variants (qty default 1).
- **Two-step UX matching the API:** on confirm, the dialog (1) calls **`prepare`** → shows any **skipped** variants inline ("3 variants need a unique barcode — assign/fix before printing") and the ready count; then (2) calls **`pdf`** for the ready set with `responseType: 'blob'` and triggers a download. (Prepare is what assigns missing barcodes, so the user sees the consequence before the PDF.)
- API client `labelApi.ts` (`getLabelFormats`, `prepareVariantLabels`, `downloadVariantLabelsPdf`) using `api.post` (raw axios, blob for the PDF); a `useLabelFormats` hook. Design tokens only; all text via `t()` (en + fr).

### 3.7 Forward-compatibility (designed-for, NOT built)
- **ZPL / dedicated label printers:** the label-data assembly (`VariantLabelData` + format registry) is renderer-agnostic; a future `ZplLabelRenderer` consumes the same data. The PDF renderer is one implementation behind a `LabelRenderer` seam.
- **2D / GS1 Digital Link:** `symbology` is an enum; adding a `qr`/`gs1_digital_link` case + a QR renderer (reusing `endroid/qr-code`) is additive. The `barcode_value` abstraction means a GTIN can later be expressed as a Digital Link URL without changing callers.

---

## 4. Error handling
- Barcode auto-assign collision (`prepare`): candidate `sku` collides with another variant's `barcode`, a product's `barcode`, or a product's `sku` in the tenant — OR `saveBarcodeSafe` raises the tenant-unique 422. Do not persist; exclude; return in `meta.skipped` reason `barcode_conflict`. Never a 500 or a duplicate.
- `prepare` with all variants skipped: still 200 with `ready: []` + the skip list (the UI shows "nothing printable").
- `pdf` receives a variant with no usable barcode (client bug — should only send `ready`): 422.
- Variant not found / cross-tenant: scoped-out (standard controller guards) before pricing (§3.3).
- Empty `items` / over the items-array cap / Σ quantity over `MAX_LABELS_PER_REQUEST` / `start_cell` out of range / unknown `format`: 422.
- EAN-13/UPC with invalid check digit: fall back to Code 128 rather than erroring.

## 5. Testing (TDD; milestone Codex review)
**Backend:**
- Symbology: valid 13-digit → EAN-13; 12-digit UPC-A → pad-to-13, valid → EAN-13; alpha SKU / invalid-check-digit / 8-digit → Code 128.
- Barcode renderer: produces a base64 PNG `data:` URI (not SVG); the render PoC PDF visibly shows scannable Code 128 + EAN-13.
- `prepare`: empty barcode → persisted `barcode=sku` via `saveBarcodeSafe`, returned in `ready`; pre-existing barcode → untouched; sku collides with another variant `barcode` **OR a product `barcode`/`sku`** → `skipped`/`barcode_conflict`, not persisted (no 500); cross-tenant variant → scoped out.
- Effective price: uses `PricingService::getPrice` with the correct named args (partnerId null, qty '1.00', company currency, variantId) → currency-scaled string (override vs price-list vs base covered).
- `pdf`: returns `application/pdf`; label count = Σ quantity; `start_cell` offset honored; `start_cell` ≥ rows×cols / unknown format / over-cap / variant-without-barcode → 422; permission `catalog.labels.print` enforced (seeded + role-mapped).
- `GET /labels/formats` returns the registry.
**Frontend (Vitest):**
- Dialog lists variants + qty inputs + format select; confirm → calls `prepare`, renders `meta.skipped` inline, then calls `pdf` (blob) for the ready set and triggers download.
- All-skipped → no PDF call; shows "nothing printable".
- Bulk select seeds the dialog with selected products' variants.
- i18n keys present (en+fr); design tokens only.

## 6. Out of scope (deferred)
- POS-terminal / ESC-POS label printing (labels are back-office).
- ZPL / dedicated thermal label-printer drivers (forward-compatible seam only).
- 2D / QR / GS1 Digital Link encoding (seam only).
- GS1 RCN / price-embedded EAN-13 (Type-2) codes.
- A visual label-template designer; per-PO / Label-Queue automation; shelf-edge vs hang-tag distinct layouts beyond the format registry.
- Auto-generating distinct internal barcodes (we use `sku` as the value per decision #1).

## 7. Constants
- `MAX_LABELS_PER_REQUEST` (e.g. 1000) — bounds PDF size; client + server.
- Label sheet format registry keys: `avery_l7160`, `label_4x6`, `grid_custom`.

## 8. Verification gates
Backend: scoped PHPUnit (`--filter`, never full suite; PG harness recipe per the repo), PHPStan L8, Pint. Frontend: Vitest (scoped), `pnpm typecheck`, ESLint (color guard). `composer require picqer/php-barcode-generator`. `php artisan typescript:transform` if DTOs change. E2E: `prepare` a product with 3 variants (one with no barcode → assigned = sku, in `ready`; one whose sku collides with a product/variant code → `skipped`/`barcode_conflict`); then `pdf` the ready set; open the PDF, confirm the Code 128 **PNG** renders and scans back to the variant via the POS scan path (Spec A).

---

## 9. Codex review round 1 — resolutions (2026-06-18)

Verdict: **REVISE** (2 BLOCKER, 4 HIGH, 5 MED, 3 LOW), grounded against the repo (incl. the sibling POS worktree, the real `PricingService` signature, and the Taxation PNG-QR precedent). All adopted.

| # | Sev | Finding | Resolution | §|
|---|-----|---------|-----------|---|
| B1 | BLOCKER | Spec A resolves product barcode/SKU (Tier 1) before the variant tier; product codes not unique-indexed → a `barcode=sku` label can mis-scan to a product | Collision check covers other-variant `barcode` + product `barcode` + product `sku` tenant-wide; skip on any collision | §3.3, §4 |
| B2 | BLOCKER | One HTTP response can't be both a PDF and `meta.skipped` JSON | Split into `prepare` (JSON, does assignment, returns ready+skipped) + `pdf` (binary, read-only) | §3.5, §3.6 |
| H1 | HIGH | "persist via the variant repository" → bare `save()` → 500 on barcode collision | Persist through `ProductVariantService::saveBarcodeSafe()` (Spec B's 23505→422 path) | §3.3 |
| H2 | HIGH | `PricingService::getPrice` signature wrong (variantId is the 6th param, not 2nd) | Use named args: partnerId null, quantity '1.00', currency company, variantId | §3.3 |
| H3 | HIGH | dompdf SVG barcode rendering unproven; codebase uses base64 PNG for QR in Taxation PDF | Use picqer **PNG renderer → base64 `<img>`**; render PoC as first impl step | §3.2 |
| M1 | MED | Mutating data (`barcode=sku`) inside a download flow is surprising | Mutation now lives in the explicit `prepare` step | §3.3, §3.5 |
| M2 | MED | `ProductVariantLookup::findById` (used by pricing) is unscoped | Controller tenant/company-scopes the variant before pricing | §3.3 |
| M3 | MED | UPC-A (12-digit) handling contradictory | Pad 12→13, validate EAN-13 check digit, else Code 128 | §3.1 |
| M4 | MED | `catalog.labels.print` not seeded/role-mapped | Add to `RolesAndPermissionsSeeder` + manager role | §3.5 |
| M5 | MED | `start_cell` edge cases unspecified | Validate ≥0 and < rows×cols; zero-based; same for custom grid | §3.5 |
| L2 | LOW | No per-items-array cap | Cap `items` length in addition to Σ quantity | §3.5 |
| L3 | LOW | `shop_name` source ambiguous | `Company::$name` (trade name) | §3.3 |

(L1 "A1 notation" — the spec uses an integer `start_cell`, not A1 notation; no change needed. UNVERIFIED: picqer-PNG-in-dompdf render — addressed by requiring the §3.2 render PoC before building the flow.)
