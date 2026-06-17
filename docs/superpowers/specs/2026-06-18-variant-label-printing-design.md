# Spec C — Variant Label Printing (web back-office PDF)

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
- If the encoded value is a **valid 13-digit numeric GTIN** (EAN-13 check digit valid) → render **EAN-13**.
- Else → render **Code 128** (covers SKU-derived alphanumeric codes and any non-GTIN barcode). 
- Human-readable text printed under the bars. (UPC-A handled as the 12-digit case → render as EAN-13/UPC per the lib; not a v1 priority since own-label codes are Code 128.)

### 3.2 Barcode generation (backend)
- Add **`picqer/php-barcode-generator`** (renders Code 128 / EAN-13 as **SVG**, embeddable in dompdf HTML — consistent with how `ReceiptPdfService` embeds `endroid` QR SVG). No binary image dependency.
- A `VariantLabelBarcodeRenderer` produces the SVG for a given (value, symbology).

### 3.3 Label data assembly (backend)
- `VariantLabelService` builds, per requested variant + quantity, a `VariantLabelData` row: `product_name`, `name_suffix`, `effective_price` (via `PricingService`, formatted with `CurrencyScale`/`formatCurrency` — money is a decimal string, never a float), `barcode_value` (post-assign), `symbology`, `sku`, `shop_name` (company name).
- **Barcode assignment** (decision #1): for variants with empty `barcode`, assign `barcode = sku` and persist via the variant repository **inside a transaction**, honoring Spec B's tenant-unique barcode rule; on collision, do not persist — mark that variant `needs_barcode` and exclude it from the PDF (report back which were skipped).

### 3.4 PDF generation (backend)
- `VariantLabelPdfService` (parallels `ReceiptPdfService`): a Blade template `catalog.variant-labels` renders the chosen sheet format (CSS grid sized to the template's label dimensions; page size A4 or the 4×6 label) with one cell per label instance (a variant repeated `quantity` times), each cell showing name/suffix/price/barcode-SVG/human-readable/shop-name.
- Sheet templates are defined as a small server-side registry (`LabelSheetFormat` value objects: key, page size, label w/h, rows, cols, margins, gutters) — extensible.

### 3.5 API (Catalog module)
- `POST api/v1/labels/variants` — body `{ format: <formatKey>, items: [{ variant_id, quantity }], start_cell?: int }` → streams/downloads a `application/pdf`. (`start_cell` lets a user resume on a partly-used sheet — common retail need; optional, default 0.)
- `GET api/v1/labels/formats` — returns the available `LabelSheetFormat`s (key, label, dimensions) for the UI picker.
- Permission: a new `catalog.labels.print` permission (gated by the standard middleware). Response for skipped/needs-barcode variants: a `meta.skipped: [{variant_id, reason}]` channel (the PDF still returns for the printable ones; the UI surfaces skips).
- Validation: `format` in the registry; `items.*.variant_id` uuid + tenant/company-scoped; `quantity` integer 1..N (cap, e.g. 1000 labels/request, to bound the PDF).

### 3.6 Frontend (web-admin, catalog feature)
- **Per-product:** a **"Print labels"** action in/near `ProductVariantMatrixEditor` → opens a `VariantLabelDialog`: list the product's variants with a per-variant **quantity** input + a **format** select (from `GET /labels/formats`) + optional start-cell → calls the endpoint and downloads the PDF (`api.post` with `responseType: 'blob'`). Surfaces `meta.skipped` ("3 variants need a unique barcode").
- **Bulk:** product-list multi-select → "Print labels" → the same dialog seeded with all selected products' active variants (qty default 1).
- API client `labelApi.ts` (`getLabelFormats`, `printVariantLabels`); a `useLabelFormats` hook. Design tokens only; all text via `t()` (en + fr).

### 3.7 Forward-compatibility (designed-for, NOT built)
- **ZPL / dedicated label printers:** the label-data assembly (`VariantLabelData` + format registry) is renderer-agnostic; a future `ZplLabelRenderer` consumes the same data. The PDF renderer is one implementation behind a `LabelRenderer` seam.
- **2D / GS1 Digital Link:** `symbology` is an enum; adding a `qr`/`gs1_digital_link` case + a QR renderer (reusing `endroid/qr-code`) is additive. The `barcode_value` abstraction means a GTIN can later be expressed as a Digital Link URL without changing callers.

---

## 4. Error handling
- Barcode auto-assign collision (`sku` already used as another variant's `barcode`): do not persist; exclude variant; return it in `meta.skipped` with reason `barcode_conflict`. Never emit a 500 or a duplicate.
- Variant not found / cross-tenant: 404 / scoped-out (standard controller guards).
- Quantity over cap or empty `items`: 422.
- Unknown format key: 422.
- EAN-13 with invalid check digit: fall back to Code 128 rather than erroring.
- Empty result (all variants skipped): 422 with the skip list (nothing to print).

## 5. Testing (TDD; milestone Codex review)
**Backend:**
- Symbology selection: 13-digit valid GTIN → EAN-13; alpha SKU → Code 128; invalid-check-digit 13-digit → Code 128 fallback.
- Barcode assign-on-print: empty barcode → persisted `barcode = sku`, encoded; pre-existing barcode → untouched; sku-collides-with-other-variant-barcode → skipped + `meta.skipped` `barcode_conflict`, not persisted.
- Effective price: label uses `PricingService::getPrice` result (override vs price-list vs base), as a currency-scaled string.
- PDF endpoint: returns `application/pdf`; correct label count = Σ quantity; `start_cell` offsets; `meta.skipped` populated; quantity cap + unknown format → 422; permission enforced.
- `GET /labels/formats` returns the registry.
**Frontend (Vitest):**
- Dialog lists variants + qty inputs + format select; posts `{format, items, start_cell}`; triggers a blob download; surfaces `meta.skipped`.
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
Backend: scoped PHPUnit (`--filter`, never full suite; PG harness recipe per the repo), PHPStan L8, Pint. Frontend: Vitest (scoped), `pnpm typecheck`, ESLint (color guard). `composer require picqer/php-barcode-generator`. `php artisan typescript:transform` if DTOs change. E2E: generate a sheet for a product with 3 variants (one with no barcode → see it assigned = sku and printed; one whose sku collides → see it skipped), open the PDF, confirm Code 128 scans back to the variant via the POS scan path (Spec A).
