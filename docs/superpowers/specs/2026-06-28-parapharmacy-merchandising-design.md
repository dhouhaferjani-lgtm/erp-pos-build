# Design — Parapharmacy Merchandising + First-Class Brand (data + UI, end-to-end)

> Status: **DESIGN for review** (build after sign-off). Date: 2026-06-28.
> Branch: `feat/parapharmacy-merchandising` (worktree `../erp.parapharm`, off `origin/dev` @ `74c1c8668`).
> Supersedes the merchandising scope in `2026-06-28-parapharmacy-merchandising-handover.md` (which lives on `feat/pos-caisse-redesign`). Pairs with the POS redesign (`2026-06-27-pos-caisse-redesign-design.md`, phases P5/P8/P9).
> Owner decisions captured 2026-06-28 (see §0.1).

---

## 0. Goal & scope

Build five parapharmacy merchandising capabilities **full-stack with seeded real demo data** so they work in the IziPOS parapharmacy POS now and are user-populatable in the ERP, with Synerivia-platform enrichment as a later additive push:

1. **Brand** — promoted to a **first-class, cross-vertical entity** (not a per-vertical string).
2. **Skin type** — one canonical enum used two ways: customer skin type + product suitability.
3. **Routine membership** — ordered sets of products (seeded demo only).
4. **Equivalents / Complements** — directional product↔product relations.
5. **POS surfacing** — Filtres drawer, skin-advice bar, product-detail Équivalents/Compléments/Routine tabs, customer skin-type capture — gated behind a `Merchandising` module key (bundled now).

### 0.1 Locked owner decisions (2026-06-28)
- **Scope/repo:** build entirely in `apps/erp`. The Synerivia platform pushes enrichment later through the existing `enrichment_results` pipeline (additive). Cross-repo artifact now = a documented enrichment field-mapping contract in `REALIGNMENT-LOG.md`.
- **SkinType:** canonical **shared** enum reusing the existing **5 values** (`normal, oily, dry, combination, sensitive`). Migrate the existing `SmartPrompts` enum onto it.
- **Brand:** first-class cross-vertical entity. Build the **universal core now**; design the platform-canonical registry as a seam; **automotive brand/manufacturer is out of scope** (separate catalogue-search-driven design).
- **Routines:** seeded demo only; authoring editor later.
- **Merchandising UX:** built **gateable** behind a `Merchandising` module key, **granted by default (bundled)** to parapharmacy now as a USP, structured so it can be unbundled to a paid extra via config later.

### 0.2 Three scope tiers (explicit)
- **Built now:** universal `Brand` core + full parapharmacy merchandising **data** (skin type, suitability, equivalents, complements, routines) + POS **UX**, with `Merchandising` gated-but-bundled; enrichment-accept fix for brand.
- **Designed-as-seam now, built later:** platform-canonical brand registry (`canonical_brand_id`), enrichment field-mapping contract.
- **Out of scope (separate design):** automotive brand/manufacturer (catalogue-search/OE — TecDoc/AAIA), a `Manufacturer` entity, routine authoring UI, brand logo upload.

### 0.3 Why first-class Brand (research summary)
Industry standard (GS1, Akeneo, SAP, Odoo, Auto Care/TecDoc) keeps three concepts separate: **Brand** (commercial label / spec-controlling brand owner), **Manufacturer** (producer; 1→N brands), **Supplier/Vendor** (tenant-local sourcing relationship — stays on the purchasing side). Brand is a slow-changing **catalog reference entity**, enrichable and shareable. Recommended storage = typed columns for a universal core + (later, per vertical) a gated extension table + a thin JSONB tail for sparse external IDs — never global EAV, never automotive columns on a shared retail table. In our codebase, brand is currently **enrichment-only and silently dropped** (`EnrichmentReviewService` sets `'brand' => null` on accept), so first-classing it also fixes a real latent gap.

---

## 1. Backend data model (`apps/api`, hexagonal). All tables tenant-scoped (db-per-tenant), under `database/migrations/tenant/`.

### 1.1 Canonical SkinType enum (shared)
- New `app/Shared/Domain/Enums/SkinType.php` — string-backed, `#[TypeScript]`, 5 cases: `Normal=normal, Oily=oily, Dry=dry, Combination=combination, Sensitive=sensitive`. Add a `label()` helper (French labels for POS/editor).
- **Migrate** `App\Modules\SmartPrompts\Domain\Enums\SkinType` consumers (`RecommendationRequestData`, `SmartPromptsController`) to the shared enum, then delete the SmartPrompts copy. This is a same-values move (no behavior change) — keep it in its own commit so it's reviewable in isolation.
- Rationale: product suitability and AI recommendations are the same concept; one enum prevents drift. Shared placement avoids the cross-module-import rule violation (Partner + Product + SmartPrompts all reference `Shared`).

### 1.2 EquivalenceType enum
- New `app/Modules/Product/Domain/Enums/EquivalenceType.php` — string-backed, `#[TypeScript]`: `Generic=generic, Therapeutic=therapeutic, BrandAlt=brand_alt`.

### 1.3 Brand entity (universal core) — `Product` module
**Home:** the `Product` module (it owns the `Product` aggregate, the per-vertical metadata tables, and the enrichment pipeline). Our `Catalog` module is structural-only (variants/modifiers/recipes), so Brand does **not** go there.

- Model `app/Modules/Product/Domain/Brand.php`.
- Migration `*_create_brands_table.php`:

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `name` | string | |
| `slug` | string, unique per tenant | derived from name; used by ecommerce/SEO |
| `canonical_brand_id` | uuid **nullable** | **reserved seam** → future platform-global brand registry. No FK constraint yet. |
| `logo_media_id` | uuid nullable | reserved for the in-flight MediaAsset unification; **no upload UI now** |
| `website_url` | string nullable | ecommerce-ready |
| `country_of_origin` | string(2) nullable | ISO-3166-1 alpha-2 |
| `description` | text nullable | enrichment/marketing |
| `is_active` | boolean, default true | |
| `external_refs` | jsonb nullable | GS1 GLN etc.; DTO-backed (`BrandExternalRefsData`) |
| `created_at`/`updated_at` | timestamps | |

- `products.brand_id` — uuid **nullable**, FK → `brands.id` (`nullOnDelete`), indexed. **Available to ALL verticals** (not vertical-gated), eager-loaded always. Migration `*_add_brand_id_to_products.php`.
- `Brand::products()` HasMany; `Product::brand()` BelongsTo.
- **Automotive note (out of scope):** the universal `Brand` does not constrain a future automotive model. A product may later carry both `brand_id` *and* automotive catalogue refs. `AutomotiveProductMetadata.supplier_brand` / `BrandQualityTier` stay untouched.

### 1.4 Customer skin type (`Partner` module)
- Migration `*_add_skin_type_to_partners.php`: nullable `skin_type` (string, cast to `Shared\Domain\Enums\SkinType`) + nullable `skin_advice_note` (text) on `partners`.
- Add to `Partner` model `$fillable`/`$casts` + the customer DTO.
- Approach: direct columns (a `partner_parapharmacy_metadata` table is overkill for two fields).

### 1.5 Product skin suitability (pivot)
- Migration `*_create_product_skin_suitability_table.php`: `id (uuid)`, `product_id (uuid, FK cascade)`, `skin_type (string, SkinType cast)`, timestamps. Unique (`product_id`, `skin_type`).
- `Product::suitableSkinTypes()` — modeled on the `product_ingredient` `belongsToMany withPivot` precedent (`app/Modules/Product/Domain/ParapharmacyProductMetadata.php`). (A product suits N skin types; pivot, no strength column for now.)

### 1.6 Routines
- Migration `*_create_routines_table.php`: `id, name, description nullable, period nullable (string), is_active`, timestamps.
- Pivot `*_create_product_routine_table.php`: `id, routine_id (FK cascade), product_id (FK cascade), step_order (int), step_label (string, e.g. "Nettoyage"/"Hydratation"/"Protection")`, timestamps. Unique (`routine_id`, `product_id`).
- `Routine` model; `Product::routines()` belongsToMany withPivot(`step_order`, `step_label`)->orderByPivot(`step_order`).

### 1.7 Equivalents / Complements (directional pivots)
- Migration `*_create_product_equivalents_table.php`: `id, product_id (FK cascade), equivalent_product_id (FK cascade), equivalence_type (string, EquivalenceType cast), confidence (decimal nullable), notes (text nullable)`, timestamps. Unique (`product_id`, `equivalent_product_id`).
- Migration `*_create_product_complements_table.php`: `id, product_id (FK cascade), complement_product_id (FK cascade), reason (string nullable)`, timestamps. Unique (`product_id`, `complement_product_id`).
- `Product::equivalentProducts()` / `Product::complementProducts()` — `belongsToMany withPivot`.
- **Store directionally; seed BOTH directions for equivalents** (A↔B) so resolution is symmetric without a union query. Complements are directional by intent (cleanser → moisturiser → SPF) but seed reciprocally where the bundle is mutual.

### 1.8 DTOs & API
- Extend `ParapharmacyProductMetadataData` (`#[TypeScript]`) with: `suitable_skin_types: SkinType[]`, `equivalent_product_ids: string[]`, `complement_product_ids: string[]`, `routine_refs: {routine_id, step_order, step_label}[]`.
- New `BrandData` (`#[TypeScript]`): `id, name, slug, country_of_origin, website_url, is_active` (logo/canonical/external omitted from the POS-facing payload for now).
- `ProductData` gains `brand: BrandData|null` (resolved, all verticals).
- The `/products` payload includes `brand` and the parapharmacy merchandising arrays **resolved as ID arrays** (the device holds all products → resolves IDs locally; avoids N+1 on device).
- Customer DTO (`/pos/customers/sync`) gains `skin_type`, `skin_advice_note`.
- Run `php artisan typescript:transform` → `packages/shared/types/generated.d.ts` after DTO changes.

### 1.9 Enrichment-accept fix (brand convergence)
- Today `EnrichmentReviewService` (~line 80) does `'brand' => null` — platform-enriched brand is silently dropped.
- Change: on accept, if `enriched_data.brand` is present and `brand` is an accepted field, **upsert a `Brand`** (match by tenant-normalized name; create if absent) and set `product.brand_id`. User-edited brand and platform-enriched brand thus converge on one `Brand` entity (handover §5 requirement).
- Record field provenance (user vs enriched) when accepting. Keep this in its own commit/task.

---

## 2. Merchandising module gating (productization seam)

**Separate data from experience.**
- **Data is unconditional:** `brand_id`, `skin_type`, suitability/equivalents/complements/routines are plain columns/pivots, always present, always in the `/products` + customer payloads. Cheap, harmless if unused, reusable by other verticals.
- **The POS merchandising *experience* is gated behind a new `Merchandising` module key:** the Filtres drawer, skin-advice bar, and Équivalents/Compléments/Routine upsell tabs.
  - Add `Merchandising` to the `ModuleName` enum (backend) + `config/verticals.php`.
  - **Grant by default** in `parapharmacy.default_modules` (bundled USP now).
  - Both-layer gating (rule 12): backend `module:Merchandising` on any dedicated merchandising endpoints; FE `hasModule('Merchandising')` / `RequirePermission` to render the surfaces. The offline POS reads its synced module entitlements (confirm the device entitlement-sync mechanism in the plan).
  - **Unbundling later = config-only:** move `Merchandising` from `default_modules` to a priced `compatible_extras` entry; no re-architecting.
- Permissions: add a `merchandising.*` permission set via the project's permission scaffolding; register in `RolesAndPermissionsSeeder`.

---

## 3. Seeding (`database/seeders/ParapharmacySeeder.php`, 1495L)

`ParapharmacySeeder` is self-provisioning (creates its own tenant with `Vertical::Parapharmacy`; not called from `DatabaseSeeder`/`DemoTenantSeeder`). Add methods mirroring `assignIngredients()` (`DB::table()->insert()` batches, ~line 717) and call them from `run()`. Subclasses `DemoPharmacySeeder` (Tunisia) and `ParapharmacyMultiBranchSeeder` inherit via the base. Scale honored via `PARAPHARMACY_SEEDER_SCALE` (default 1).

- `seedBrands()` — ~15–25 **real** French parapharmacy brands (Avène, La Roche-Posay, Bioderma, Vichy, CeraVe, Nuxe, Mustela, Caudalie, Uriage, Ducray, A-Derma, Klorane, Bioten, SVR, Embryolisse…). Create `brands` rows; assign `products.brand_id` by category heuristics.
- `seedProductSkinSuitability()` — map each cosmetic/visage product to 1–3 skin types by category heuristics.
- `seedProductEquivalents()` — within a category+form, link a few products as `generic`/`brand_alt` equivalents (**both directions**).
- `seedProductComplements()` — cross-category bundles (cleanser → moisturiser → SPF).
- `seedRoutines()` + membership — a handful of named routines (visage peau sèche/grasse/sensible) with 3–4 ordered steps.
- `seedCustomerSkinTypes()` — assign skin types to the demo individual partners.

Verify row counts after `db:seed --class=ParapharmacySeeder`.

---

## 4. POS offline sync (device SQLite, `apps/pos`)

Current max device migration = **v57**; new migration = **v58**.

- **Products (universal brand as first-class columns, NOT in the parapharmacy JSON):**
  - v58 `ALTER TABLE products ADD COLUMN brand_id TEXT; ALTER TABLE products ADD COLUMN brand_name TEXT;` (denormalized name for offline filter/display) + `ALTER TABLE products ADD COLUMN parapharmacy_metadata TEXT` (JSON: `{suitable_skin_types[], equivalent_product_ids[], complement_product_ids[], routine_refs[]}`). New parapharmacy fields ride inside the JSON with no further migrations.
  - `productRepository.ts`: extend `ProductRow` (+`brand_id`, `brand_name`, `parapharmacy_metadata`), `rowToProduct` (JSON.parse metadata), `upsertProducts` (INSERT columns + `ON CONFLICT … SET`, bump `PARAMS_PER_ROW` 15→18), `POSProduct` (`brand_id?`, `brand_name?`, `parapharmacy_metadata?: ParapharmacyMeta`). Server `/products` includes these.
- **Customers:**
  - v58 `ALTER TABLE customers ADD COLUMN skin_type TEXT; ALTER TABLE customers ADD COLUMN skin_advice_note TEXT;`. Update `CustomerMirrorRow` (`customerTypes.ts`) + `customerRepository.upsertCustomer`. `/pos/customers/sync` includes the fields.
  - **Wire `pullCustomers()` into `runFullSync()`** — it is currently orphaned (defined in `customerSyncService.ts`, zero production callers; absent from `syncService.ts`). Without this, customer skin-type never syncs. **Parapharmacy owns this wiring; the loyalty session (`feat/loyalty-earn-per-product`) rebases onto it.** Keep the change minimal/isolated so the rebase is trivial.
- Equivalents/complements/routines resolve **locally**: the product JSON holds related product IDs; the POS looks them up in its in-memory product store. No extra device tables.

---

## 5. POS UI (redesign phases P9/P5/P8) — gated `module:Merchandising`

> **Sequencing:** reuses the redesign atoms (`Pill`, `Tab`, `ProductThumb`, `StockBadge`) + token system, which live on `feat/pos-caisse-redesign` (**not yet on `origin/dev`**, diverged 3/10). The data/sync layers (§1–4) proceed now; this UI rebases onto the redesign branch once it lands. If still unmerged when UI work starts, escalate.

- **P9 Filtres drawer** (`HomePage`): brand / category / routine / skin-type multi-select from the local product set; removable filter chips (`Pill`) + live result count.
- **P9 Skin-advice bar** (`ProductGrid` header): skin-type pills filter by `suitable_skin_types`; "Conseil · Type de peau" + count; defaults from the selected customer's `skin_type`.
- **P9 Product-detail tabs** (`ProductDetailDrawer`): new **Équivalents / Compléments / Routine** (`Tab`) — resolve IDs → local products → `ProductThumb` rows with one-tap add-to-cart (cosmetic upsell).
- **P5/P8 Customer:** add-customer + detail capture `skin_type` (+ advice note); detail shows it and drives the advice-bar default.
- All surfaces conditional on `hasModule('Merchandising')`. French via i18n; design tokens only; both themes.

---

## 6. Platform-population seam (later, additive)

ERP is the source of truth for tenant data now. The Synerivia platform later pushes enrichment (brand, equivalents, skin suitability, routines) through the existing `enrichment_results` pipeline (`EnrichmentResult`, `accepted_fields`) → accepted into the brand/metadata/pivots. Keep user-edit and platform-enrichment write paths converging on the same models (§1.9). **Deliverable now:** document the field-provenance + enrichment field-mapping (which `accepted_fields` keys map to brand / skin-suitability / equivalents / routines) in `docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md`.

---

## 7. Build order (each step: TDD → PHPStan L8 + Pint → typecheck/lint → seed-verify → Codex code review → commit)

1. **Enums** — shared `SkinType` (+ migrate SmartPrompts, delete old) ; `EquivalenceType`.
2. **Brand** — `brands` table + model + `products.brand_id` + `BrandData` DTO + eager-load. **Enrichment-accept fix** (§1.9).
3. **Merchandising data** — `partners.skin_type`/`skin_advice_note`; `product_skin_suitability`; `routines` + `product_routine`; `product_equivalents`; `product_complements`; models/relations; extend `ParapharmacyProductMetadataData`. `typescript:transform`.
4. **Module gating** — `Merchandising` module key + verticals config (granted to parapharmacy) + permissions.
5. **Seeder** — `seedBrands`, `seedProductSkinSuitability`, `seedProductEquivalents`, `seedProductComplements`, `seedRoutines`, `seedCustomerSkinTypes`; run + verify.
6. **Server payloads** — `/products` (brand + merchandising arrays) and `/pos/customers/sync` (skin fields).
7. **POS offline** — v58 device migration + repos + types + **wire `pullCustomers`**.
8. **POS UI** — Filtres, skin-advice, detail tabs, customer capture (gated; behind redesign-branch atoms).
9. **Docs** — REALIGNMENT-LOG enrichment field-mapping; memory update.

---

## 8. Testing strategy
- **Backend (PHPUnit, by path — never the full suite):** enum cases; Brand model + slug uniqueness; `brand_id` FK nullable + nullOnDelete; pivot relations (suitability/equivalents/complements/routines withPivot ordering); equivalents seeded both directions; DTO shapes; `/products` includes brand + merchandising arrays; `/pos/customers/sync` includes skin fields; enrichment-accept upserts + links Brand; `module:Merchandising` gating returns 403 when ungranted; seeder produces expected row counts at scale=1.
- **POS (Vitest):** v58 migration applies; `productRepository` round-trips brand columns + parapharmacy JSON; `customerRepository` round-trips skin fields; `runFullSync` calls `pullCustomers` (regression-guards the orphan); local resolution of equivalent/complement/routine IDs; `hasModule('Merchandising')` gating of surfaces.
- Constructor injection only; enums for all type columns; money/qty via the precision contract (n/a here beyond `confidence` decimal — use bcmath-safe handling, no float casts); i18n `t()` for all POS strings; tokens only.

---

## 9. Risks & open items
- **Redesign-branch dependency** for §5 UI (atoms not on `origin/dev`). Mitigation: build §1–4 first; rebase UI later.
- **`pullCustomers` wiring co-owned with loyalty** — parapharmacy owns; loyalty rebases. Coordinate before either pushes the sync change to dev.
- **Device module-entitlement sync** for `Merchandising` gating — confirm the existing mechanism (operator/terminal-state pull) carries module flags; if not, that's a small added pull. Resolve in the plan.
- **SmartPrompts enum migration** touches an AI feature — isolated commit, run its tests by path.
- **Slug collisions** in `seedBrands` / enrichment upsert — normalize + de-dupe; unique index enforces.

---

## 10. Out of scope (explicit)
Automotive brand/manufacturer (catalogue-search/OE — TecDoc/AAIA) and a `Manufacturer` entity; routine authoring UI; brand logo upload/management; the platform-side canonical brand registry (only the `canonical_brand_id` nullable seam is added now).
