# IZI POS Product Editor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the IZI POS product editor (`/inventory/products/new` and `/edit`) into the Direction-A "Crisp / Operational" layout — barcode-first hero, sticky section nav with completeness meter, opening-balance inventory, deep-link right rail — adding the missing normalized fields (brand/manufacturer/country-of-origin) and inline Synerivia enrichment, built entirely on the existing atom layer.

**Architecture:** The editor is one long scrolling react-hook-form form (Variation B) rendered inside the existing `DashboardLayout`. It reuses the shared field atoms (`Input`/`Select`/`Textarea`/`FormField`/`MoneyInput`/`QuantityInput`) so it inherits the navy theme + Direction-A structural tokens automatically. New normalized lookups (`brands`, `manufacturers`) follow the existing `categories` table pattern (tenant/company-scoped, nullable FK, optional). Opening stock writes one `MovementType::Opening` `StockMovement` at creation and locks after the first movement. Backend stays hexagonal (Domain/Application/Infrastructure/Presentation), DTOs drive TS types via `typescript:transform`.

**Tech Stack:** Laravel 12 / PHP 8.4 (strict types, PHPStan L8) · React 19 / Vite 7 / TS strict · TanStack Query 5 · react-hook-form + zod · Tailwind 4 (design tokens) · PHPUnit · Vitest.

**Branch / worktree:** `feat/izipos-theme-product-editor` at `/Users/houssamr/Projects/syneriva/apps/erp/.claude/worktrees/izipos-product-editor` (off `origin/dev`). Theme (Layer 1) is already landed and verified.

---

## Global Constraints

Every task implicitly includes these (from `apps/erp/CLAUDE.md`):

- **TDD (rule 2):** failing test first (red) → minimal code (green) → refactor. PHPUnit backend, Vitest frontend.
- **Strict typing (rule 3):** no `mixed` / no `any`. JSONB columns get a PHP DTO.
- **Types flow from backend (rule 7):** never hand-edit domain TS interfaces; run `php artisan typescript:transform` after DTO changes. Generated types in `packages/shared/types/`.
- **Enums (rule 9):** every status/type/code column uses a PHP enum.
- **No hardcoded strings (rule 11):** all user-facing text via `t()` (react-i18next).
- **Route middleware (rule 12):** every `routes.php` group uses `['api','auth:sanctum',SetPermissionsTeam::class]`. Vertical-exclusive routes/fields gated BOTH layers.
- **Constructor injection only (rule 13):** `private readonly`; never `app()`.
- **API responses (rule 14):** `apiGet/apiPost` already unwrap `response.data.data`; paginated `{data,meta}` endpoints use `api.get` + return `response.data`.
- **Precision contract (rule 19):** money = decimal strings via `<MoneyInput>`/`CurrencyScale::bcformatStrict`; quantity via `<QuantityInput>`/`QuantityScale`; FormRequests keep `numeric` + add the regex ceiling (money `/^-?\d+(\.\d{1,3})?$/`, quantity `…{1,4}`). Never `parseFloat` money/qty. `sale_price` here is **tax-inclusive (TTC)** for B2C POS.
- **Atom layer (this effort's rule):** every field uses `Input`/`Select`/`Textarea`/`FormField`/`MoneyInput`/`QuantityInput` (or a wrapper built on them). NO hardcoded `rounded-lg border-gray-300 px-3 py-2 shadow-sm` input classes. Tokens only (rule 18).
- **Pre-flight (rule 10):** `./scripts/preflight.sh` green before each commit. (Do NOT run the full PHPUnit suite unprompted — run targeted `--filter` tests during TDD.)

---

## Grounded findings (verified in code, 2026-06-24)

| Concern | Reality |
|---|---|
| Editor component | `apps/web/src/features/inventory/ProductForm.tsx` — already uses atoms (6px flat fields). Rebuild restructures it into the Direction-A layout. |
| Atom layer | `components/atoms/{Input,Select,Textarea,FormField,MoneyInput,QuantityInput,TaxConfigurationSelect}` — canonical. `components/molecules/TaxConfigurationField`. |
| **Atomic-design violation** | `features/products/components/ParapharmacyMetadataFields.tsx` (lines 39, 67, 96+) hardcodes `rounded-lg`+`shadow-sm` → must refactor to atoms. Same pattern in `parts-catalog` + `AddToInventoryModal` (out of scope here). |
| Base product fields | All exist (name, sku, type, is_physical, description, sale_price, purchase_price, tax_rate, unit, barcode, is_active, is_active_for_ecommerce, oem_numbers, cross_references, images, category_id). |
| `brand_id` / `manufacturer_id` | **MISSING** in ERP. Today brand/mfr is free-text + vertical-scattered: `automotive_product_metadata.supplier_brand`, `automotive_product_cross_references.manufacturer_name`, `products.cross_references[].brand`. |
| `country_of_origin` | **MISSING** on ERP products. (Platform `brands` carry it; ERP will store it product-level per handoff.) |
| Synerivia upstream | Platform HAS normalized `brands`/`manufacturers` (UUID), but **enrichment payload returns brand as a string only** (`EnrichedProductData.brand: string`), and ERP enrichment-accept **skips brand** (`EnrichmentReviewService:80`). No `synerivia_*_id` exists yet. |
| `synerivia_ref` | Already exists as `products.platform_product_id` (+ `platform_submission_id`, `enrichment_status`, `enrichment_results` table). Reuse — no new field. |
| Media roles | `Catalog/Domain/Enums/MediaRole` has Primary/Gallery/Datasheet/Manual/VideoPoster/Spin/Swatch. **Media section DEFERRED** (parallel media-unification in flight). |
| Opening balance | `MovementType::Opening` exists; today via a separate `OpeningBalanceWizardPage` batch flow. |
| Deep-link `?productId=` | **NOT supported** on stock-adjustment / purchase / sale create or movements list. |
| Lookup pattern | `categories` table (`2025_12_26_194624`) + `Category` model + `CategoryData` DTO + `ScopedExists::company()` validation + `PartnerSearchSelect` (searchable + inline create) are the canonical analogs. |

## Decisions (locked with product owner)

1. **Opening stock = inline fields on create + keep the batch wizard — but BUILT LAST.** Inline `opening_qty` / `opening_unit_cost` / `opening_as_of_date` in the Inventory section post ONE `Opening` `StockMovement` at creation; lock once the item has ≥1 movement. Bulk stays in the existing wizard. **Sequencing constraint:** `StockMovement` is being reworked in a parallel session to link every movement to its **reason** — so this stage is deferred to LAST and must consume that reason-linking once it lands (the Opening movement carries its reason). An opening balance is a **once-only** event per product (idempotent guard + lock-after-movement).
2. ~~**Media & Files section = DEFERRED entirely**~~ **SUPERSEDED 2026-06-25:** the media-subsystem unification landed (`4722aafe9`), so Media is now a **first-class build** — reuse the existing `ProductImageSection`/`productImages.ts` (stable façade), read `ProductData.media[]`/`primary_image_url`, restyle to the mock gallery. See `2026-06-25-product-editor-field-model-and-identity.md` §5.
3. **Brand / Manufacturer = optional, normalized, local.** New local `brands` + `manufacturers` lookup tables with nullable `brand_id`/`manufacturer_id` on products, **plus nullable `synerivia_brand_id`/`synerivia_manufacturer_id`** for future re-sync. Searchable select + inline "+ create". NOT obligatory. Full management admin UI deferred (inline-create only for now).
4. **country_of_origin** = nullable ISO-3166 alpha-2 string on products, fixed enum list, enrichable.
5. **Enrichment** runs inline: debounce ~300ms on barcode (fallback product name), auto-fetch (refresh icon optional), status pill. Enrichment-accept now maps the brand string → match-or-create a local `Brand` instead of dropping it.

## Out of scope (explicit)

- Media & Files section + PDF/video viewers (deferred — decision 2).
- Full Brand/Manufacturer management admin pages (only inline-create + select now).
- Backfilling existing `supplier_brand`/`manufacturer_name`/`cross_references[].brand` free-text into the new FKs (note as a follow-up migration).
- Refactoring `parts-catalog` / `AddToInventoryModal` hardcoded inputs (separate token-drift cleanup).

---

## File structure

**Backend (`apps/api`) — new for brands/manufacturers (Stage 2):**
- `database/migrations/tenant/<ts>_create_brands_table.php`
- `database/migrations/tenant/<ts>_create_manufacturers_table.php`
- `database/migrations/tenant/<ts>_add_brand_manufacturer_origin_to_products.php` (`brand_id`, `manufacturer_id` nullable FK + `country_of_origin` string(2))
- `app/Modules/Product/Domain/{Brand,Manufacturer}.php`
- `app/Modules/Product/Application/DTOs/{BrandData,ManufacturerData}.php`
- `app/Modules/Product/Application/Contracts/{BrandRepositoryInterface,ManufacturerRepositoryInterface}.php`
- `app/Modules/Product/Infrastructure/Repositories/{BrandRepository,ManufacturerRepository}.php`
- `app/Modules/Product/Presentation/Controllers/{BrandController,ManufacturerController}.php`
- `app/Modules/Product/Presentation/Requests/{Create,Update}{Brand,Manufacturer}Request.php`
- Modify: `app/Modules/Product/Domain/Product.php` (fillable + relations), `routes.php`, `CreateProductRequest.php`/`UpdateProductRequest.php`, `ProductData` DTO, `RolesAndPermissionsSeeder.php`, `ProductServiceProvider.php`, `database/factories/{Brand,Manufacturer}Factory.php`.

**Frontend (`apps/web/src`):**
- `features/products/editor/ProductEditorPage.tsx` (new shell — Stage 1)
- `features/products/editor/sections/{GeneralSection,PricingTaxSection,InventoryUnitsSection,PharmacySection,SuppliersSection}.tsx` (Stage 1)
- `features/products/editor/components/{BarcodeHero,SectionNav,CompletenessMeter,RelatedOperationsRail,BeforePublishChecklist}.tsx` (Stage 1)
- `components/catalog/{BrandSelect,ManufacturerSelect}.tsx` + `CountryOfOriginSelect.tsx` (Stage 2 — built on atoms)
- `features/catalog/api/{brands,manufacturers}.ts` + query hooks (Stage 2)
- `features/products/editor/useInlineEnrichment.ts` (Stage 4)
- Modify: `features/products/components/ParapharmacyMetadataFields.tsx` (atom refactor — Stage 1), `features/products/types.ts`, route registration, i18n `locales/*/catalog.json`.

---

## Stage sequencing & dependencies

```
Stage 1 (Editor shell, existing fields, atom refactor)  ──┐
Stage 2 (Brands/Manufacturers/country_of_origin)  ────────┼─> General section gains the new selects
Stage 5 (Deep-link ?productId= routes)  ── wires Stage 1 right-rail shortcuts
Stage 4 (Inline enrichment; maps brand→local Brand)  ── depends on Stage 2
Stage 3 (Opening balance inline)  ── LAST; depends on the in-flight StockMovement "reason" rework
```
Recommended build order: **1 → 2 → 5 → 4 → 3**. Stage 3 (opening balance) is **deliberately last** because it depends on the parallel `StockMovement` reason-linking work — building it earlier risks rework and conflicts. Each stage ships independently. Stages 1, 4, 5 are expanded into bite-sized tasks just-in-time at execution (UI is verified live via the running stack on :8089); **Stage 3 is expanded only once the reason-linking lands**. **Stage 2 is fully detailed below** as the deterministic foundation.

---

## Stage 1 — Editor shell (Direction A layout)  *(task outline; expand at execution)*

**Objective:** Replace the flat `ProductForm` render with the scrolling editor: `BarcodeHero` (≤64px, barcode-first then name) → two-column body with sticky `SectionNav` + `CompletenessMeter` (scrollspy) on the left and stacked section cards (`01 General`, `02 Pricing & Tax`, `03 Inventory & Units`, `Pharmacy`, `Suppliers`) on the right → `RelatedOperationsRail` + `BeforePublishChecklist` right rail. All fields are EXISTING ones; no schema change. Build with `tokens.*` + atoms; section cards use `tokens.card` (flat hairline) with `01/02/03` markers + `font-[family-name:var(--font-display)]` titles.

- **Task 1.1 — Refactor `ParapharmacyMetadataFields` to atoms.** Replace hardcoded `<select>`/`<input>` (lines 39/67/96+) with `<Select>`/`<Input>`/`<Textarea>` + `<FormField>`. Test: Vitest renders the component and asserts the inputs carry `tokens.input.base` (no `rounded-lg`/`shadow-sm`). This is the atomic-design-consistency fix.
- **Task 1.2 — `SectionNav` + scrollspy + `CompletenessMeter`.** Component with IntersectionObserver active-section tracking; completeness = % of required fields filled (derive from RHF state). Vitest: clicking a nav item scrolls; meter reflects filled count.
- **Task 1.3 — `BarcodeHero`.** Compact bar: barcode input first, then name; "Synerivia · N fields" status slot (wired in Stage 4). Vitest render + a11y.
- **Task 1.4 — Section components** (`GeneralSection` etc.) wrapping the existing fields, each a `tokens.card` with `01/02/03` marker. Pharmacy section embeds the refactored `ParapharmacyMetadataFields` (gated to parapharmacy vertical, rule 12).
- **Task 1.5 — `ProductEditorPage`** composes hero + nav + sections + rail; wires RHF + the existing create/update mutations; replaces the route target for `/inventory/products/new` and `/edit`.
- **Task 1.6 — `RelatedOperationsRail` + `BeforePublishChecklist`** (shortcuts navigate to existing pages now; `?productId=` preselect lands in Stage 5).
- **Verification:** live screenshot on :8089 (new + edit) vs the Direction-A reference; computed-style spot check (6px flat, IBM Plex Mono numerics); `pnpm typecheck`/`pnpm lint`/`vitest`.

---

## Stage 2 — Brands, Manufacturers & country_of_origin  *(fully detailed, TDD)*

**Objective:** Add optional normalized `brand_id`/`manufacturer_id` (local lookup tables, with `synerivia_*_id` for future re-sync) and `country_of_origin` to products; expose them as searchable selects in the General section. Pattern source: `categories`. All selects built on atoms.

**Interfaces produced (later stages/tasks rely on these):**
- DB: `brands(id uuid, tenant_id uuid, company_id uuid, name, slug, logo_path?, synerivia_brand_id uuid?, synerivia_synced_at?, is_active, timestamps, softDeletes)`, same shape `manufacturers` (`synerivia_manufacturer_id`). `products.brand_id uuid? FK→brands nullOnDelete`, `products.manufacturer_id uuid? FK→manufacturers nullOnDelete`, `products.country_of_origin char(2)?`.
- PHP: `App\Modules\Product\Domain\{Brand,Manufacturer}`; `BrandData{id,name,slug,logo_url,is_active}` (+ Manufacturer); `BrandRepositoryInterface::{findForCompany(string $companyId, ?string $search): array, findByIdForCompany(string $id, string $companyId): ?Brand, firstOrCreateForCompany(string $companyId, string $name): Brand}` (+ Manufacturer).
- HTTP: `GET/POST /api/v1/brands`, `GET /api/v1/brands/{id}` (+ `manufacturers`). `ProductData` gains `brand_id`, `manufacturer_id`, `country_of_origin`.
- TS: `BrandSelect`/`ManufacturerSelect` props `{ value: string|null; onChange: (id: string|null)=>void; error?: boolean; disabled?: boolean }` with built-in inline create. `CountryOfOriginSelect` `{ value: string|null; onChange }`.

### Task 2.1: `brands` table + `Brand` model + factory

**Files:**
- Create: `apps/api/database/migrations/tenant/<ts>_create_brands_table.php`
- Create: `apps/api/app/Modules/Product/Domain/Brand.php`
- Create: `apps/api/database/factories/BrandFactory.php`
- Test: `apps/api/tests/Unit/Modules/Product/BrandModelTest.php`

**Interfaces — Produces:** `Brand` (uuid PK, company-scoped, `scopeActive`, auto-slug on create, `synerivia_brand_id` nullable).

- [ ] **Step 1: Write the failing test**
```php
// tests/Unit/Modules/Product/BrandModelTest.php
public function test_brand_autogenerates_slug_and_defaults_active(): void
{
    $brand = Brand::factory()->create(['name' => 'Panadol', 'slug' => null]);
    $this->assertSame('panadol', $brand->slug);
    $this->assertTrue($brand->is_active);
    $this->assertNull($brand->synerivia_brand_id);
}
```
- [ ] **Step 2: Run to verify it fails** — `cd apps/api && php artisan test --filter=BrandModelTest` → FAIL (no `brands` table / class).
- [ ] **Step 3: Migration** — uuid `id`, `tenant_id`, `company_id` (FK companies cascadeOnDelete), `name` string(200), `slug` string index, `logo_path` nullable, `synerivia_brand_id` uuid nullable, `synerivia_synced_at` timestamp nullable, `is_active` bool default true, timestamps, softDeletes, `unique(['company_id','slug'])`, `index(['company_id','is_active'])`. Model: `Brand` with `HasUuids`, `HasFactory`, `SoftDeletes`, fillable, `casts(is_active=>bool)`, `booted` slug-from-name, `company()`/`products()` relations, `scopeActive`. Factory.
- [ ] **Step 4: Run to verify it passes** — same command → PASS.
- [ ] **Step 5: Commit** — `feat(product): add brands lookup table + model`.

### Task 2.2: `manufacturers` table + `Manufacturer` model + factory
Mirror Task 2.1 exactly (`synerivia_manufacturer_id`). Test `ManufacturerModelTest::test_manufacturer_autogenerates_slug_and_defaults_active`. Commit `feat(product): add manufacturers lookup table + model`.

### Task 2.3: `brand_id` / `manufacturer_id` / `country_of_origin` on products
**Files:** migration `<ts>_add_brand_manufacturer_origin_to_products.php`; modify `Product.php`. Test: `tests/Unit/Modules/Product/ProductBrandRelationTest.php`.
- [ ] **Step 1: Failing test** — create product with a brand + manufacturer + `country_of_origin='TN'`; assert `$product->brand->is(...)`, `$product->manufacturer->is(...)`, `country_of_origin==='TN'`.
- [ ] **Step 2: Fails** (`brand_id` column missing).
- [ ] **Step 3:** migration adds `brand_id` uuid nullable `constrained('brands')->nullOnDelete()` after `category_id`, `manufacturer_id` likewise, `country_of_origin` char(2) nullable. `Product`: add to `$fillable`, `@property` docblock, `brand()`/`manufacturer()` `BelongsTo`.
- [ ] **Step 4: Passes.**  **Step 5: Commit** `feat(product): add brand/manufacturer/country_of_origin to products`.

### Task 2.4: `BrandData` / `ManufacturerData` DTOs + `typescript:transform`
- [ ] **Step 1: Failing test** `tests/Unit/Modules/Product/BrandDataTest.php` — `BrandData::fromModel($brand)` maps id/name/slug/`logo_url` (asset path)/is_active.
- [ ] **Step 2: Fails.**  **Step 3:** create `BrandData`/`ManufacturerData` (`#[TypeScript]`, Spatie `Data`, `fromModel`). Run `php artisan typescript:transform`.
- [ ] **Step 4: Passes** + `packages/shared/types/generated.d.ts` now has `BrandData`/`ManufacturerData`.  **Step 5: Commit** `feat(product): brand/manufacturer DTOs + generated types`.

### Task 2.5: Repositories + contracts + provider binding
- [ ] **Step 1: Failing test** `tests/Feature/Modules/Product/BrandRepositoryTest.php` — `findForCompany` returns only active, company-scoped, name-ordered; `firstOrCreateForCompany` is idempotent by (company, slug); cross-company isolation holds.
- [ ] **Step 2: Fails.**  **Step 3:** `BrandRepositoryInterface`+`BrandRepository` (and Manufacturer); bind in `ProductServiceProvider`. Constructor injection only.
- [ ] **Step 4: Passes.**  **Step 5: Commit** `feat(product): brand/manufacturer repositories`.

### Task 2.6: Controllers + FormRequests + routes + permissions
**Files:** `BrandController`/`ManufacturerController`; `Create*/Update*Request`; modify `routes.php`, `RolesAndPermissionsSeeder`. Test: `tests/Feature/Modules/Product/BrandControllerTest.php`.
- [ ] **Step 1: Failing tests** — `index` returns company brands (paginated, `?search=` filters, ilike); `store` validates `name` required (CreateBrandRequest) and creates company-scoped; unauthenticated → 401; cross-company id → 404/not listed; permission `brands.view`/`brands.create` enforced.
- [ ] **Step 2: Fails.**  **Step 3:** controllers (constructor-inject `CompanyContext`, `requireCompanyId()`, `cursorPaginate`, return `BrandData`); FormRequests; routes under `['api','auth:sanctum',SetPermissionsTeam::class]` with `can:brands.view|brands.create` — **ungated by vertical** (optional, all verticals); add `brands.*`/`manufacturers.*` permissions to seeder + roles.
- [ ] **Step 4: Passes.**  **Step 5: Commit** `feat(product): brand/manufacturer endpoints + permissions`.

### Task 2.7: Product create/update accept the new fields
**Files:** modify `CreateProductRequest`/`UpdateProductRequest`, the product create/update service + `ProductData`. Test: extend `tests/Feature/Modules/Product/ProductController*Test.php`.
- [ ] **Step 1: Failing test** — POST product with `brand_id`/`manufacturer_id`/`country_of_origin='FR'` persists them; invalid (cross-company) `brand_id` → 422; all three remain optional (omitted → null).
- [ ] **Step 2: Fails.**  **Step 3:** add rules — `brand_id`/`manufacturer_id`: `['nullable','uuid', ScopedExists::company('brands',$company->id)]`; `country_of_origin`: `['nullable','string','size:2', Rule::in($iso3166Alpha2)]`. Thread through the create/update service + `ProductData`. `typescript:transform`.
- [ ] **Step 4: Passes.**  **Step 5: Commit** `feat(product): persist brand/manufacturer/country on products`.

### Task 2.8: `BrandSelect` / `ManufacturerSelect` (atoms) + `CountryOfOriginSelect`
**Files:** `apps/web/src/components/catalog/{BrandSelect,ManufacturerSelect,CountryOfOriginSelect}.tsx`; `features/catalog/api/{brands,manufacturers}.ts` + query hooks; i18n keys. Test: `BrandSelect.test.tsx`.
- [ ] **Step 1: Failing test** (Vitest, mock the query hook) — renders options; selecting calls `onChange(id)`; typing filters; the "+ create new" row calls `createBrand` then selects the new id; the trigger/menu use `tokens`/atoms (assert NO `rounded-lg`/`shadow-sm` literal — built on `<Input>`/`FormField` + `tokens.button.ghost`).
- [ ] **Step 2: Fails.**  **Step 3:** build the combobox on the atom layer (model behavior on `PartnerSearchSelect`, but styling via `tokens` — search uses `<Input>`, rows use `tokens.table.rowHover`, add-new uses `tokens.button.ghost`). `CountryOfOriginSelect` = `<Select>` atom over a static ISO-3166 list (i18n labels). Query hooks per rule 14.
- [ ] **Step 4: Passes** (`pnpm vitest BrandSelect`).  **Step 5: Commit** `feat(catalog): brand/manufacturer/country selects on atom layer`.

### Task 2.9: Wire selects into the General section
- [ ] **Step 1: Failing test** — `GeneralSection` renders Brand, Manufacturer, Country selects bound to RHF `brand_id`/`manufacturer_id`/`country_of_origin`; optional (no required marker); submit includes them.
- [ ] **Step 2: Fails.**  **Step 3:** add the three fields to `GeneralSection` (Stage 1) wrapped in `<FormField>`; extend `features/products/types.ts` `Product` + the form schema (zod: all nullable/optional).
- [ ] **Step 4: Passes.**  **Step 5: Commit** `feat(product): brand/manufacturer/country in editor General section`.
- [ ] **Verification:** live on :8089 — create a product, "+ create" a brand inline, save, reload, confirm persisted; `./scripts/preflight.sh` green.

---

## Stage 3 — Opening balance inline  *(BUILD LAST — gated on in-flight StockMovement reason-linking; expand once that lands)*

> **Blocked-by:** the parallel `StockMovement` rework that links every movement to its **reason**. Do not start until it merges into `dev`; the Opening movement created here must populate that reason field. Re-base this stage on the merged shape before expanding tasks.

**Objective:** In the Inventory & Units section (create mode only), add `opening_qty` (`<QuantityInput>`), `opening_unit_cost` (`<MoneyInput>`), `opening_as_of_date` (`<Input type=date>`). On create, if `opening_qty > 0`, post ONE `MovementType::Opening` `StockMovement` (qty/unit_cost/as-of, `is_historical` per date, **+ its reason** per the new contract). An opening balance is **once-only**: guard against a second Opening movement (idempotent), and lock these inputs (read-only) when the product has ≥1 movement (edit mode). Bulk wizard untouched.

- **Task 3.1 (backend):** product-create service optionally creates the opening `StockMovement` in the same transaction; FormRequest adds `opening_qty` (`numeric` + qty regex `…{1,4}`), `opening_unit_cost` (money regex `…{1,3}`), `opening_as_of_date` (`nullable,date`). Test: creating with opening fields yields one Opening movement + correct `quantity_after`/`avg_cost_after`; omitting them yields none.
- **Task 3.2 (lock rule):** expose `has_movements` on `ProductData` (derived); editor disables the opening inputs when true with a helper note linking to Stock Movements. Test (backend flag + Vitest disabled-state).
- **Task 3.3 (frontend):** inventory section fields + zod (create-only); QuantityInput/MoneyInput emit strings (rule 19).
- **Verification:** create product w/ opening stock → check Stock Movements shows the Opening row; edit after → inputs locked.

---

## Stage 4 — Inline Synerivia enrichment  *(task outline; expand at execution; depends on Stage 2)*

**Objective:** Debounce ~300ms on barcode (fallback product name) → auto-call the existing platform lookup/submission flow → patch empty fields + show "Synerivia · N fields" pill in `BarcodeHero`; refresh icon = manual re-run. On enrichment accept, map the returned brand **string** → `BrandRepository::firstOrCreateForCompany` and set `brand_id` (closes the `EnrichmentReviewService:80` "skip brand" gap).

- **Task 4.1 (frontend hook):** `useInlineEnrichment` — debounced query keyed on barcode||name, populates only empty fields, exposes status. Vitest with fake timers.
- **Task 4.2 (hero status):** wire the pill + manual refresh.
- **Task 4.3 (backend brand mapping):** in `EnrichmentReviewService::accept`, when `enriched_data.brand` is present, `firstOrCreateForCompany` + set `brand_id` (instead of skip). Test: accepting enrichment with a brand string links/creates a local Brand. Note in `REALIGNMENT-LOG.md` (rule 9 of root) since enrichment-accept behavior changes.
- **Verification:** type a known barcode on :8089 → fields auto-fill, pill shows count; accept → brand linked.

---

## Stage 5 — Deep-link `?productId=` routes  *(task outline; expand at execution)*

**Objective:** Make the right-rail shortcuts open the target flow with this product preselected: New stock adjustment, Start a purchase, Start a sale/quote, View stock movements (filtered).

- **Task 5.1:** each target page reads `?productId=` (or `product_id`) from `useSearchParams` and preselects/locks the product. Vitest per page.
- **Task 5.2:** `RelatedOperationsRail` builds the `?productId=` links; enable once routes honor them.
- **Task 5.3 (backend, if needed):** confirm the movements list endpoint filters by `product_id` (it largely does via query) — add/verify the filter + test.
- **Verification:** from a saved product, each rail shortcut lands on the target with the product preselected.

---

## Self-review

- **Spec coverage:** handoff §3 sections → Stage 1 (Media deferred per decision 2); §4 new fields brand/mfr/country → Stage 2; media role/synerivia_ref → reused/deferred (decisions); opening balance → Stage 3; enrichment → Stage 4; deep-links → Stage 5; atomic-design fix → Task 1.1. Covered.
- **Placeholder scan:** Stage 2 has full TDD code/commands; Stages 1/3/4/5 are intentionally task-level roadmaps to be expanded just-in-time at execution (UI verified live) — flagged as such, not hidden placeholders.
- **Type consistency:** `firstOrCreateForCompany`, `findForCompany`, `BrandData{id,name,slug,logo_url,is_active}`, `brand_id`/`manufacturer_id` (uuid), `country_of_origin` (char(2)) used consistently across tasks 2.1–2.9 and Stage 4.
- **Risks:** media-unification parallel work (Media deferred avoids it); brand free-text backfill deferred (documented); enrichment-accept change is behavioral → log in REALIGNMENT-LOG.
