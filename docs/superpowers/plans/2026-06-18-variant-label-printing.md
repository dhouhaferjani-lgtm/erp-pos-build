# Variant Label Printing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A web back-office feature that generates printable PDF sheets of per-variant barcode labels (Code 128 / EAN-13), so own-label variant tags become scannable at the POS (closing the loop with Spec A).

**Architecture:** Backend (Catalog module): a barcode-PNG renderer (`picqer/php-barcode-generator`), a label-sheet-format registry, a two-step API — `prepare` (JSON: validates/scopes variants, assigns `barcode=sku` for unbarcoded variants via the Spec-B safe path, resolves effective price, returns ready + skipped) and `pdf` (binary: dompdf renders the Blade label sheet with base64-PNG barcodes). Frontend (apps/web catalog): a `VariantLabelDialog` driving prepare→pdf, launched from the variant editor and a bulk product-list selection.

**Tech Stack:** Laravel 12 / PHP 8.2 strict / hexagonal / multi-tenant PG; `barryvdh/laravel-dompdf` (present), `picqer/php-barcode-generator` (to add); React 19 / TS strict / TanStack Query / Vitest.

**Spec:** `docs/superpowers/specs/2026-06-18-variant-label-printing-design.md` (v2, Codex-reviewed). Reviews under `docs/superpowers/reviews/2026-06-18-*`.

**Constants:** `MAX_LABELS_PER_REQUEST = 1000`; `MAX_LABEL_ITEMS = 500` (items-array cap); format keys `avery_l7160`, `label_4x6`, `grid_custom`.

**Test-run safety (project memory):** NEVER run the full PHPUnit suite — always `--filter`. Barcode-assign/collision tests need real PostgreSQL (partial unique indexes). The worktree needs a copied `.env` (gitignored, not in a fresh worktree). PG recipe:
`DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_t6_test DB_USERNAME=houssamr DB_PASSWORD= DB_CENTRAL_DATABASE=autoerp_t6_test php artisan test -c phpunit-pgsql.xml --filter=<Class>` (run `composer install` + `cp <main-worktree>/apps/api/.env apps/api/.env` first if vendor/.env are missing).

---

## File map

**Backend — create:**
- `apps/api/app/Modules/Catalog/Domain/Support/LabelSheetFormat.php` — value object + registry of sheet formats.
- `apps/api/app/Modules/Catalog/Application/Services/VariantLabelBarcodeRenderer.php` — symbology selection + base64-PNG.
- `apps/api/app/Modules/Catalog/Application/Services/VariantLabelService.php` — prepare logic (scope, assign, price, ready/skipped).
- `apps/api/app/Modules/Catalog/Application/Services/VariantLabelPdfService.php` — dompdf render.
- `apps/api/app/Modules/Catalog/Application/DTOs/VariantLabelData.php` — one label row's data.
- `apps/api/app/Modules/Catalog/Presentation/Controllers/VariantLabelController.php` — prepare/pdf/formats.
- `apps/api/app/Modules/Catalog/Presentation/Requests/{PrepareLabelsRequest,GenerateLabelPdfRequest}.php`
- `apps/api/resources/views/catalog/variant-labels.blade.php` — the label sheet template.
- Tests under `apps/api/tests/Feature/Catalog/` + `tests/Unit/Catalog/`.

**Backend — modify:**
- `apps/api/composer.json` (+ `picqer/php-barcode-generator`).
- `apps/api/app/Modules/Catalog/Presentation/routes.php` (3 label routes).
- `apps/api/database/seeders/RolesAndPermissionsSeeder.php` (`catalog.labels.print` + manager role).

**Frontend — create/modify:**
- `apps/web/src/features/catalog/api/labelApi.ts` (create), `hooks/useLabels.ts` (create).
- `apps/web/src/features/catalog/components/VariantLabelDialog.tsx` (create) + test.
- Modify `ProductVariantMatrixEditor.tsx` (a "Print labels" action) + the product-list page (bulk select → "Print labels").
- `apps/web/src/locales/{en,fr}/catalog.json`.

---

## Milestone A — Backend foundation

### Task A1: Barcode PNG renderer + symbology selection + render PoC

**Files:**
- Modify: `apps/api/composer.json`
- Create: `apps/api/app/Modules/Catalog/Application/Services/VariantLabelBarcodeRenderer.php`
- Test: `apps/api/tests/Unit/Catalog/VariantLabelBarcodeRendererTest.php`

- [ ] **Step 1: Add the library** — `cd apps/api && composer require picqer/php-barcode-generator` (provides `Picqer\Barcode\BarcodeGeneratorPNG` with `getBarcode($value, $type)` returning raw PNG bytes; types `TYPE_CODE_128`, `TYPE_EAN_13`).

- [ ] **Step 2: Write the failing test**

```php
public function test_selects_ean13_for_valid_gtin(): void
{
    $r = new VariantLabelBarcodeRenderer();
    $out = $r->render('5012345678900'); // valid EAN-13 check digit
    $this->assertSame('ean13', $out->symbology);
    $this->assertStringStartsWith('data:image/png;base64,', $out->dataUri);
}

public function test_pads_upc_a_12_to_ean13(): void
{
    $r = new VariantLabelBarcodeRenderer();
    $out = $r->render('012345678905'); // 12-digit UPC-A, valid when padded
    $this->assertSame('ean13', $out->symbology);
}

public function test_falls_back_to_code128_for_alpha_sku(): void
{
    $out = (new VariantLabelBarcodeRenderer())->render('TSHIRT-S-BLACK');
    $this->assertSame('code128', $out->symbology);
    $this->assertStringStartsWith('data:image/png;base64,', $out->dataUri);
}

public function test_invalid_13_digit_check_digit_uses_code128(): void
{
    $out = (new VariantLabelBarcodeRenderer())->render('5012345678901'); // bad check digit
    $this->assertSame('code128', $out->symbology);
}
```

- [ ] **Step 3: Run — expect fail.** `php artisan test --filter=VariantLabelBarcodeRendererTest` → FAIL.

- [ ] **Step 4: Implement** (return a small readonly DTO `RenderedBarcode { string $symbology; string $dataUri; }`):

```php
<?php
declare(strict_types=1);
namespace App\Modules\Catalog\Application\Services;

use Picqer\Barcode\BarcodeGeneratorPNG;

final class VariantLabelBarcodeRenderer
{
    public function render(string $value): RenderedBarcode
    {
        $gen = new BarcodeGeneratorPNG();
        [$symbology, $type, $encoded] = $this->select($value);
        $png = $gen->getBarcode($encoded, $type);
        return new RenderedBarcode($symbology, 'data:image/png;base64,'.base64_encode($png));
    }

    /** @return array{0:string,1:string,2:string} [symbology, picqerType, encodedValue] */
    private function select(string $value): array
    {
        $digits = preg_match('/^\d{12,13}$/', $value) === 1;
        if ($digits) {
            $candidate = strlen($value) === 12 ? '0'.$value : $value; // pad UPC-A to 13
            if (strlen($candidate) === 13 && $this->validEan13($candidate)) {
                return ['ean13', BarcodeGeneratorPNG::TYPE_EAN_13, $candidate];
            }
        }
        return ['code128', BarcodeGeneratorPNG::TYPE_CODE_128, $value];
    }

    private function validEan13(string $code): bool
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $code[$i] * ($i % 2 === 0 ? 1 : 3);
        }
        return (10 - ($sum % 10)) % 10 === (int) $code[12];
    }
}
```

(Plus `RenderedBarcode` readonly class in the same namespace.)

- [ ] **Step 5: Run — expect pass.** PASS.

- [ ] **Step 6: Render PoC (de-risk dompdf+PNG, Codex HIGH-3).** Add a test that embeds the data URI into a minimal Blade and renders via dompdf, asserting a non-trivial PDF is produced:

```php
public function test_png_barcode_embeds_into_dompdf(): void
{
    $uri = (new VariantLabelBarcodeRenderer())->render('TSHIRT-S-BLACK')->dataUri;
    $html = '<html><body><img src="'.$uri.'" style="height:12mm"></body></html>';
    $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->output();
    $this->assertStringStartsWith('%PDF', $pdf);
    $this->assertGreaterThan(1500, strlen($pdf)); // image actually embedded
}
```

Run it. (Human scan-check of a printed sheet is in the final gate.)

- [ ] **Step 7: Commit.** `git add apps/api/composer.json apps/api/composer.lock app/Modules/Catalog/Application/Services/VariantLabelBarcodeRenderer.php tests/Unit/Catalog/VariantLabelBarcodeRendererTest.php && git commit -m "feat(catalog): variant label barcode PNG renderer (Code128/EAN-13) + dompdf PoC"`

---

### Task A2: Label sheet format registry + `GET /labels/formats`

**Files:**
- Create: `apps/api/app/Modules/Catalog/Domain/Support/LabelSheetFormat.php`
- Modify: `apps/api/app/Modules/Catalog/Presentation/Controllers/VariantLabelController.php` (create with `formats()`), `routes.php`
- Test: `apps/api/tests/Feature/Catalog/LabelFormatsTest.php`

- [ ] **Step 1: Failing test**

```php
public function test_lists_label_formats(): void
{
    // auth user with catalog.labels.print (seeded in Task D1; until then use a super-admin/grant)
    $res = $this->getJson('/api/v1/labels/formats');
    $res->assertOk()->assertJsonStructure(['data' => [['key','label','label_width_mm','label_height_mm','rows','cols']]]);
    $this->assertContains('avery_l7160', array_column($res->json('data'), 'key'));
}
```

- [ ] **Step 2: Run — expect fail.** FAIL.

- [ ] **Step 3: Implement the registry** — `LabelSheetFormat` readonly VO (`key, label, pageSize, labelWidthMm, labelHeightMm, rows, cols, marginTopMm, marginLeftMm, gutterXmm, gutterYmm`) with a static `all(): array` returning: `avery_l7160` (A4, 63.5×38.1, 7 rows × 3 cols, margins 15.0/7.2, gutters 2.5/0), `label_4x6` (101.6×152.4 single, 1×1), `grid_custom` (A4, defaults 50×30, derived rows/cols). Add `find(string $key): ?self`.

- [ ] **Step 4: Controller `formats()`** returns `['data' => array_map(fn($f)=>$f->toArray(), LabelSheetFormat::all())]`. Add route `Route::get('labels/formats', [VariantLabelController::class, 'formats'])->middleware('can:catalog.labels.print');` in the catalog route group.

- [ ] **Step 5: Run — expect pass.** PASS. **Step 6: Commit** `feat(catalog): label sheet format registry + GET /labels/formats`.

---

## Milestone B — Prepare flow

### Task B1: Barcode value collision checker (product + variant scope)

**Files:**
- Create: `apps/api/app/Modules/Catalog/Application/Services/VariantLabelService.php` (start it here with the collision method)
- Test: `apps/api/tests/Feature/Catalog/LabelBarcodeCollisionTest.php` (**PG**)

The printed value must round-trip through Spec A, whose Tier-1 matches **product** `barcode`/`sku` before the variant tier (Codex B1). So a candidate value is usable only if it collides with nothing in the tenant.

- [ ] **Step 1: Failing test**

```php
public function test_value_is_unusable_if_it_matches_a_product_barcode_or_sku_or_other_variant_barcode(): void
{
    $svc = app(VariantLabelService::class);
    // seed: a product with barcode 'P-BC' and sku 'P-SKU'; a variant with barcode 'V-BC'
    $this->assertFalse($svc->valueIsUsable($tenantId, 'P-BC', exceptVariantId: $someId));
    $this->assertFalse($svc->valueIsUsable($tenantId, 'P-SKU', exceptVariantId: $someId));
    $this->assertFalse($svc->valueIsUsable($tenantId, 'V-BC', exceptVariantId: $otherId));
    $this->assertTrue($svc->valueIsUsable($tenantId, 'TOTALLY-FREE', exceptVariantId: $someId));
}
```

- [ ] **Step 2: Run — expect fail.** (PG recipe.) FAIL.

- [ ] **Step 3: Implement** `valueIsUsable(string $tenantId, string $value, string $exceptVariantId): bool` — returns false if any of:
  - `ProductVariant::where('tenant_id',$tenantId)->where('barcode',$value)->where('id','!=',$exceptVariantId)->whereNull('deleted_at')->exists()`
  - `Product::where('tenant_id',$tenantId)->where(fn($q)=>$q->where('barcode',$value)->orWhere('sku',$value))->exists()`
  (Use the Product model from `App\Modules\Product\Domain\Product`.)

- [ ] **Step 4: Run — expect pass.** PASS. **Step 5: Commit** `feat(catalog): label barcode value collision check (product+variant scope)`.

---

### Task B2: `VariantLabelService::prepare` (scope, assign, price, ready/skipped)

**Files:**
- Modify: `VariantLabelService.php`; Create: `VariantLabelData.php`
- Test: `apps/api/tests/Feature/Catalog/PrepareLabelsTest.php` (**PG**)

`prepare(string $companyId, array $items): array` where `$items = [{variantId, quantity}]`, returns `['ready'=>VariantLabelData[], 'skipped'=>[{variant_id,reason}]]`.

- [ ] **Step 1: Failing tests**

```php
public function test_assigns_sku_as_barcode_when_empty_and_prices_via_pricing_service(): void
{
    // variant with null barcode, sku 'TS-S-BLK', product priced 12.000
    $out = app(VariantLabelService::class)->prepare($companyId, [['variantId'=>$v,'quantity'=>2]]);
    $this->assertCount(1, $out['ready']);
    $this->assertSame('TS-S-BLK', ProductVariant::find($v)->barcode); // persisted
    $this->assertSame('TS-S-BLK', $out['ready'][0]->barcode_value);
    $this->assertSame('12.000', $out['ready'][0]->effective_price); // currency-scaled string
}

public function test_skips_when_sku_collides_with_a_product_code(): void
{
    // variant null barcode, sku == an existing product's sku
    $out = app(VariantLabelService::class)->prepare($companyId, [['variantId'=>$v,'quantity'=>1]]);
    $this->assertCount(0, $out['ready']);
    $this->assertSame('barcode_conflict', $out['skipped'][0]['reason']);
    $this->assertNull(ProductVariant::find($v)->barcode); // NOT persisted
}

public function test_keeps_existing_barcode_untouched(): void { /* barcode preset -> ready, value == existing */ }
public function test_skips_cross_tenant_or_missing_variant(): void { /* reason not_found */ }
```

- [ ] **Step 2: Run — expect fail.** (PG.) FAIL.

- [ ] **Step 3: Implement `prepare`.** For each item: load the variant **tenant+company-scoped** (skip `not_found` if absent — Codex M2: scope before pricing). If `barcode` empty: if `valueIsUsable(tenantId, sku, variantId)` → set `barcode=sku` and persist via the variant service's safe path; else skip `barcode_conflict`. Resolve price:
  ```php
  $price = $this->pricingService->getPrice(
      productId: $variant->product_id, partnerId: null, quantity: '1.00',
      currency: $company->currency, date: null, variantId: $variant->id,
  );
  ```
  Build `VariantLabelData(product_name, name_suffix, effective_price: $price, barcode_value: $variant->barcode, sku, quantity)` and push to `ready`. Constructor-inject `ProductVariantService` (for `saveBarcodeSafe`), `PricingService`, repositories. **Persisting the assign goes through `ProductVariantService::saveBarcodeSafe()`** (Codex H1) — wrap so a 23505 also maps to `skipped`/`barcode_conflict` rather than 500.

- [ ] **Step 4: Run — expect pass.** PASS. **Step 5: PHPStan + Pint + Commit** `feat(catalog): VariantLabelService::prepare (assign barcode=sku safely + effective price)`.

---

### Task B3: `POST /labels/variants/prepare` endpoint + request

**Files:**
- Create: `PrepareLabelsRequest.php`; Modify: `VariantLabelController.php`, `routes.php`
- Test: `apps/api/tests/Feature/Catalog/PrepareLabelsEndpointTest.php` (**PG**)

- [ ] **Step 1: Failing test** — POST `{ items:[{variant_id,quantity}] }` → 200 `{ data:{ready:[{variant_id,quantity,barcode_value,symbology}]}, meta:{skipped:[...]} }`; empty items / quantity<1 / Σqty>1000 / items length>500 → 422; permission enforced (403 without `catalog.labels.print`).

- [ ] **Step 2: Run — expect fail.** FAIL.

- [ ] **Step 3: Implement.** `PrepareLabelsRequest`: `items` required array min:1 max:`MAX_LABEL_ITEMS`; `items.*.variant_id` uuid; `items.*.quantity` integer min:1; a `withValidator` after-hook asserting Σquantity ≤ `MAX_LABELS_PER_REQUEST` (else error key `quantity`). `authorize()` = `can('catalog.labels.print')`. Controller `prepare()`: company via `CompanyContext`, call the service, attach `symbology` per ready row (from the renderer's `select`), return `{data:{ready}, meta:{skipped}}`. Route `POST labels/variants/prepare` with `can:catalog.labels.print`.

- [ ] **Step 4: Run — expect pass.** PASS. **Step 5: Commit** `feat(catalog): POST /labels/variants/prepare endpoint`.

---

## Milestone C — PDF flow

### Task C1: `VariantLabelPdfService` + Blade template

**Files:**
- Create: `VariantLabelPdfService.php`, `apps/api/resources/views/catalog/variant-labels.blade.php`
- Test: `apps/api/tests/Feature/Catalog/VariantLabelPdfServiceTest.php`

- [ ] **Step 1: Failing test**

```php
public function test_renders_pdf_with_correct_label_count_and_start_offset(): void
{
    $labels = [ new VariantLabelData('Tee','S / Black','12.000','TS-S-BLK','code128', 3) ];
    $pdf = app(VariantLabelPdfService::class)->render('avery_l7160', $labels, startCell: 2, shopName: 'My Shop');
    $this->assertStringStartsWith('%PDF', $pdf);
}
```

- [ ] **Step 2: Run — expect fail.** FAIL.

- [ ] **Step 3: Implement.** `render(string $formatKey, array $labels, int $startCell, string $shopName): string`: resolve `LabelSheetFormat::find`, expand labels by quantity into a flat cell list, prepend `startCell` blank cells, render each barcode via `VariantLabelBarcodeRenderer`, pass to the Blade view, `Pdf::loadView('catalog.variant-labels', [...])->setPaper(...)` (A4 or the 4×6 dimensions) `->output()`. Blade: CSS grid sized to the format's label mm dims (rows×cols), each cell shows name/suffix/price/`<img src="{{ $cell.barcode_data_uri }}">`/human-readable/`$shopName`. Mirror `CertificatePDFService`'s base64-PNG `<img>` embed and `ReceiptPdfService`'s `Pdf::loadView` pattern.

- [ ] **Step 4: Run — expect pass.** PASS. **Step 5: Commit** `feat(catalog): VariantLabelPdfService + label sheet Blade template`.

---

### Task C2: `POST /labels/variants/pdf` endpoint + request

**Files:**
- Create: `GenerateLabelPdfRequest.php`; Modify: `VariantLabelController.php`, `routes.php`
- Test: `apps/api/tests/Feature/Catalog/GenerateLabelPdfEndpointTest.php` (**PG**)

- [ ] **Step 1: Failing test** — POST `{ format, items:[{variant_id,quantity}], start_cell }` → `Content-Type: application/pdf`, body starts `%PDF`. Unknown format / `start_cell` ≥ rows×cols / Σqty>cap / a variant whose barcode is still empty → 422. Permission enforced.

- [ ] **Step 2: Run — expect fail.** FAIL.

- [ ] **Step 3: Implement.** `GenerateLabelPdfRequest`: `format` required in `array_keys(LabelSheetFormat::all())`; `items` as in B3; `start_cell` integer min:0; `withValidator` asserts `start_cell < rows*cols` for the chosen format. Controller `pdf()`: scope variants (company), build `VariantLabelData` from current variant state (read-only — do NOT assign here; if a variant's barcode is empty → 422 `must prepare first`), resolve price (same `getPrice` call), call the pdf service, `return response($pdf, 200, ['Content-Type'=>'application/pdf','Content-Disposition'=>'attachment; filename=variant-labels.pdf'])`. Route `POST labels/variants/pdf` with `can:catalog.labels.print`.

- [ ] **Step 4: Run — expect pass.** PASS. **Step 5: PHPStan + Pint + Commit** `feat(catalog): POST /labels/variants/pdf endpoint (binary)`.

---

## Milestone D — Permission

### Task D1: Seed `catalog.labels.print`

**Files:**
- Modify: `apps/api/database/seeders/RolesAndPermissionsSeeder.php`
- Test: `apps/api/tests/Feature/Catalog/LabelPermissionSeededTest.php`

- [ ] **Step 1: Failing test** — after seeding `RolesAndPermissionsSeeder`, `Permission::where('name','catalog.labels.print')->exists()` is true, and the manager role has it.
- [ ] **Step 2: Run — expect fail.** FAIL.
- [ ] **Step 3: Implement** — add `'catalog.labels.print'` to the catalog permissions array (near line 68) and to the manager-role mapping (near line 448).
- [ ] **Step 4: Run — expect pass.** PASS. **Step 5: Commit** `feat(catalog): seed catalog.labels.print permission + manager role`.

---

## Milestone E — Frontend

### Task E1: API client + hooks + types

**Files:** Create `apps/web/src/features/catalog/api/labelApi.ts`, `hooks/useLabels.ts`; Test `api/__tests__/labelApi.test.ts`.

- [ ] **Step 1: Failing test** — `prepareVariantLabels('items')` posts `/labels/variants/prepare` and returns `{data:{ready},meta:{skipped}}`; `getLabelFormats()` GETs `/labels/formats`; `downloadVariantLabelsPdf({format,items,start_cell})` posts `/labels/variants/pdf` with `responseType:'blob'`.
- [ ] **Step 2–4: Implement + pass.**

```ts
import { api, apiGet } from '@/lib/api'
export interface LabelItem { variant_id: string; quantity: number }
export interface PrepareResult { data: { ready: { variant_id: string; quantity: number; barcode_value: string; symbology: string }[] }; meta: { skipped: { variant_id: string; reason: string }[] } }
export async function getLabelFormats() { return apiGet('/labels/formats') }
export async function prepareVariantLabels(items: LabelItem[]): Promise<PrepareResult> {
  return (await api.post('/labels/variants/prepare', { items })).data
}
export async function downloadVariantLabelsPdf(p: { format: string; items: LabelItem[]; start_cell?: number }): Promise<Blob> {
  return (await api.post('/labels/variants/pdf', p, { responseType: 'blob' })).data
}
```
`useLabelFormats` = `useQuery` over `getLabelFormats`. **Step 5: Commit** `feat(web): label API client + useLabelFormats`.

### Task E2: `VariantLabelDialog` (prepare → skipped → pdf download)

**Files:** Create `components/VariantLabelDialog.tsx` + test.

- [ ] **Step 1: Failing tests** — given variants + a format select + per-variant qty, confirm: (1) calls `prepareVariantLabels`, renders `meta.skipped` inline; (2) then calls `downloadVariantLabelsPdf` for the ready set and triggers a blob download (mock `URL.createObjectURL` + an anchor click); all-skipped → no pdf call + "nothing printable" message. Fixed-size dialog (per `feedback_modal_fixed_size`).
- [ ] **Step 2–4: Implement + pass.** Use the existing fixed-size dialog/modal component; design tokens; all text `t()`. Quantity inputs default 1; format select from `useLabelFormats`; optional start-cell input.
- [ ] **Step 5: Commit** `feat(web): VariantLabelDialog prepare->pdf flow`.

### Task E3: Triggers — variant editor + bulk product list

**Files:** Modify `ProductVariantMatrixEditor.tsx`, the product-list page + tests.

- [ ] **Step 1: Failing tests** — a "Print labels" button in the variant editor opens `VariantLabelDialog` seeded with that product's variants; the product-list multi-select "Print labels" opens it seeded with selected products' active variants.
- [ ] **Step 2–4: Implement + pass.** Gate the button on `catalog.labels.print` via `usePermissions`.
- [ ] **Step 5: typecheck + lint + Commit** `feat(web): Print-labels triggers (variant editor + bulk list)`.

### Task F: i18n (en + fr)

**Files:** Modify `apps/web/src/locales/{en,fr}/catalog.json`.

- [ ] **Step 1:** Add every new `t('catalog:labels.*')` key (title, printLabels, format, quantity, startCell, skippedSummary, nothingPrintable, barcodeConflict, download, cancel) in en + fr; grep the components to confirm each key exists in both. **Step 2:** `pnpm test src/features/catalog`, `pnpm typecheck`, `pnpm lint` → green. **Step 3: Commit** `feat(web): i18n for variant label printing (en/fr)`.

---

## Final verification (whole feature)
- Backend (scoped, never full suite; PG recipe for the assign/collision tests): `--filter='LabelFormats|PrepareLabels|GenerateLabelPdf|VariantLabelBarcode|VariantLabelPdf|LabelBarcodeCollision|LabelPermission'`.
- `./vendor/bin/phpstan analyse app/Modules/Catalog` (zero errors on changed code); `./vendor/bin/pint --test`.
- Frontend: `pnpm test src/features/catalog && pnpm typecheck && pnpm lint`.
- **Human E2E (the de-risk for picqer-PNG-in-dompdf):** in web-admin, Print labels for a product with 3 variants (one no-barcode → assigned=sku & printed; one sku-collides → skipped); open the PDF; **physically scan a printed Code 128 + EAN-13 label** and confirm it resolves to the right variant at the POS (Spec A scan path).

## Out of scope (do not implement)
POS-terminal/ESC-POS label printing; ZPL/thermal-label drivers; 2D/QR/GS1 Digital Link; GS1 RCN / price-embedded EAN-13; a visual template designer; per-PO/Label-Queue automation; auto-generating distinct internal barcodes (we use sku).
