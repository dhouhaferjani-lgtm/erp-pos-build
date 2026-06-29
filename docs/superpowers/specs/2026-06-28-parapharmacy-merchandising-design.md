# Design — Parapharmacy Merchandising + First-Class Brand (data + UI, end-to-end)

> Status: **DESIGN for review** (build after sign-off). Date: 2026-06-28.
> Branch: `feat/parapharmacy-merchandising` (worktree `../erp.parapharm`, off `origin/dev` @ `74c1c8668`).
> Supersedes the merchandising scope in `2026-06-28-parapharmacy-merchandising-handover.md` (on `feat/pos-caisse-redesign`). Pairs with the POS redesign (`2026-06-27-pos-caisse-redesign-design.md`, phases P5/P8/P9).
> **Adversarial reviews folded** (both grounded in file:line, both no-go-until-amended → now amended): `docs/superpowers/audits/2026-06-28-parapharmacy-merchandising-design-codex-review.md` and `…-claude-review.md`. See §0.4.

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
- **Merchandising UX:** built **gateable** behind a `Merchandising` module key, **granted by default (bundled)** to parapharmacy now as a USP. Unbundling later hides the **UX**; the data stays vertical-gated (withholding data from a non-entitled tenant is a future server-side code change, not config-only — §2c).

### 0.2 Three scope tiers
- **Built now:** universal `Brand` core + full parapharmacy merchandising **data** + POS **UX**, with `Merchandising` gated-but-bundled; enrichment-accept fix for brand.
- **Designed-as-seam now, built later:** platform-canonical brand registry (`canonical_brand_id`), enrichment field-mapping contract.
- **Out of scope (separate design):** automotive brand/manufacturer (catalogue-search/OE — TecDoc/AAIA); a `Manufacturer` entity; routine authoring UI; brand logo upload; brand `external_refs`/GS1; equivalence `confidence` score; a dedicated `merchandising.*` permission set.

### 0.3 Why first-class Brand (research)
Industry standard (GS1, Akeneo, SAP, Odoo, Auto Care/TecDoc) separates **Brand** (commercial label / spec-controlling brand owner), **Manufacturer** (producer; 1→N brands), **Supplier/Vendor** (tenant-local sourcing — stays on purchasing). Brand is a slow-changing **catalog reference entity**. Storage = typed columns for a universal core + (later, per vertical) a gated extension table — never global EAV, never automotive columns on a shared retail table. In our codebase, brand is currently **enrichment-only and silently dropped** (`EnrichmentReviewService` sets `'brand' => null` on accept), so first-classing it also fixes a real latent gap.

### 0.4 Review resolution (what changed after the adversarial pass)
- **[Codex CRITICAL] v58 cursor reset** → migrations now delete the relevant `sync_metadata` cursors so upgraded devices backfill (§4). v57 is the precedent.
- **[Both CRITICAL/IMPORTANT] `brands` tenant scoping** → `brands` carries `tenant_id`; `unique(tenant_id, slug)`; brand is tenant-scoped (shared across companies in a tenant) — `company_id` deliberately omitted, justified (§1.3).
- **[Both IMPORTANT] suitability relation** → `Product::skinSuitabilities()` is `HasMany(ProductSkinSuitability)`, not `belongsToMany` (no `skin_types` table). DTO plucks the enum (§1.5).
- **[Both IMPORTANT] brand wire-shape + read paths** → nested `BrandData` on `ProductData`; **all** `ProductController` read paths load `brand`; POS payload is **flat** `brand_id`+`brand_name`; device flattens on upsert (§1.8, §4).
- **[Both IMPORTANT] enrichment upsert** → `firstOrCreate` on a single normalized `(tenant_id, slug)` key inside a transaction; provenance stored as `products.brand_source` enum (§1.9).
- **[Both IMPORTANT] device entitlement** → it's the cached `/company/config` `all_enabled_modules` read by `hasModule()` — **no new device sync**. Spec rewritten to state the verified mechanism; the only backend check is that `/company/config` projects `default_modules` (§2).
- **[Both IMPORTANT] SmartPrompts enum** → migrate both consumers, delete the old enum, derive validation from the shared enum (no hardcoded `in:` string), regenerate types (§1.1).
- **[Both IMPORTANT] `pullCustomers` wiring** → extend `SyncResult` (`customersPulled`/`customersFailed`); IDs from `authStore`; explicit catch-log-degrade-continue failure policy; never advance the cursor on failure (§4).
- **[Claude IMPORTANT] migration version collision** → split product-v58 / customer-v59, idempotent ALTERs, **coordinate version numbers with the loyalty session before pushing** (§4).
- **[Owner 2026-06-28] vertical-gate the data** → parapharmacy-specific merchandising data is **gated to the parapharmacy vertical** (established pattern, not a rule-12 violation — see §2c). Brand stays universal. Product side rides the `parapharmacyMetadata` gate automatically (relations anchored on `ParapharmacyProductMetadata`, §1.5); customer side needs an **explicit guard** (§2a). Unbundling the `Merchandising` module hides UX, not data (§2c).
- **[r2 reviews] structural + leak fixes** → (D1-C1) customer-sync guard mandated (§2a); (D1-I1) merchandising relations anchored on `ParapharmacyProductMetadata` so they're DTO-reachable AND gated (§1.5–1.8); (D1-I2) dropped the "unbundle = config-only" overclaim (§2c/§0.1); (§5) redesign-branch artifacts marked as rebase prerequisites (§5).
- **[Claude IMPORTANT] equivalents symmetry / self-reference** → seeder writes identical `equivalence_type` both directions + a symmetry test; DB CHECK prevents self-reference (§1.7).
- **[Nits] dropped** `external_refs`, `confidence`, `merchandising.*` permission set (§0.2 out-of-scope).

---

## 1. Backend data model (`apps/api`, hexagonal). All tenant-scoped tables under `database/migrations/tenant/`.

### 1.1 Canonical SkinType enum (shared)
- New `app/Shared/Domain/Enums/SkinType.php` — string-backed, `#[TypeScript]`, 5 cases (`Normal=normal, Oily=oily, Dry=dry, Combination=combination, Sensitive=sensitive`) + `label()` (French).
- **Migrate every consumer in the same commit:** `SmartPrompts\Application\DTOs\RecommendationRequestData` and `SmartPrompts\Presentation\Controllers\SmartPromptsController` import the shared enum; delete `SmartPrompts\Domain\Enums\SkinType`. Replace the controller's hardcoded `in:normal,oily,dry,combination,sensitive` with a rule **derived from the enum** (`Rule::enum(SkinType::class)` / `SkinType::cases()`) so values can't drift. Regenerate TS types.
- Legal under rule 6 (shared kernel; Partner + Product + SmartPrompts reference `Shared`, no cross-module model import). Risk is low (identical values, 2 consumers, never persisted to a column) but keep it an isolated commit and run SmartPrompts tests by path.

### 1.2 EquivalenceType enum
- New `app/Modules/Product/Domain/Enums/EquivalenceType.php` — string-backed, `#[TypeScript]`: `Generic=generic, Therapeutic=therapeutic, BrandAlt=brand_alt`.

### 1.3 Brand entity (universal core) — `Product` module
**Home:** the `Product` module (owns the `Product` aggregate, per-vertical metadata, enrichment pipeline). `Catalog` is structural-only — Brand does not go there.

**Scoping decision:** brands are **tenant-scoped reference data shared across all companies/branches in a tenant** (a brand like "Avène" is identical for every branch). So `brands` carries `tenant_id` but **deliberately omits `company_id`** (unlike `products`), justified by brand being cross-company reference data. The enrichment upsert and slug uniqueness use `tenant_id`.

- Model `app/Modules/Product/Domain/Brand.php` (fillable incl. `tenant_id`; tenant scope helper mirroring `Product`).
- Migration `*_create_brands_table.php`:

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `tenant_id` | uuid, indexed | convention; scope key |
| `name` | string | |
| `slug` | string | `unique(['tenant_id','slug'])` |
| `canonical_brand_id` | uuid **nullable** | **reserved seam** → future platform-global registry. No FK yet. |
| `logo_media_id` | uuid nullable | reserved for MediaAsset unification; **no upload UI now** |
| `website_url` | string nullable | ecommerce-ready |
| `country_of_origin` | string(2) nullable | ISO-3166-1 alpha-2 |
| `description` | text nullable | |
| `is_active` | boolean, default true | |
| timestamps | | |

- `products.brand_id` — uuid **nullable**, FK → `brands.id` (`nullOnDelete`), indexed `['tenant_id','brand_id']`. **All verticals**, not gated, eager-loaded on every product read path (§1.8). Migration `*_add_brand_id_and_source_to_products.php` (also adds `brand_source`, §1.9).
- `Brand::products()` HasMany; `Product::brand()` BelongsTo.
- **Automotive (out of scope):** universal `Brand` does not constrain a future automotive model; `AutomotiveProductMetadata.supplier_brand`/`BrandQualityTier` untouched.

### 1.4 Customer skin type (`Partner` module)
- Migration `*_add_skin_type_to_partners.php`: nullable `skin_type` (cast to `Shared\Domain\Enums\SkinType`) + nullable `skin_advice_note` (text). Add to `Partner` `$fillable`/`$casts` + customer DTO. Direct columns (a metadata table is overkill for two fields). **Serialized/synced only for the parapharmacy vertical** (§2a).

### 1.5 Product skin suitability (HasMany — not belongsToMany)
- Migration `*_create_product_skin_suitability_table.php`: `id (uuid)`, `tenant_id (uuid)`, `product_id (uuid, FK cascade)`, `skin_type (string, SkinType cast)`, timestamps. Unique (`product_id`, `skin_type`).
- New `ProductSkinSuitability` model. **Declare the relation on `ParapharmacyProductMetadata`** (anchored on `product_id`, mirroring the existing `ParapharmacyProductMetadata::ingredients()` precedent) — **NOT on `Product`** — so it is reachable by `ParapharmacyProductMetadataData::fromModel` (which only holds the metadata model) AND loads only under the vertical-gated `parapharmacyMetadata.*` path (§2a): `ParapharmacyProductMetadata::skinSuitabilities(): HasMany(ProductSkinSuitability::class, 'product_id', 'product_id')`. The DTO exposes `suitable_skin_types: SkinType[]` by **plucking** `skin_type` (cast to the shared enum). **Not** `belongsToMany` — there is no `skin_types` lookup table.

> **Relation-home rule (resolves r2 D1-I1):** ALL four merchandising relations (suitability, routines, equivalents, complements) live on **`ParapharmacyProductMetadata`** (parented via `product_id`), never on `Product`. `ParapharmacyProductMetadataData` is built only inside the `if vertical === Parapharmacy` branch (`ProductData::fromModel`), so anchoring here makes the gate "ride automatically" AND keeps the arrays DTO-reachable.

### 1.6 Routines
- Migration `*_create_routines_table.php`: `id, tenant_id, name, description nullable, period nullable, is_active`, timestamps.
- Pivot `*_create_product_routine_table.php`: `id, routine_id (FK cascade), product_id (FK cascade), step_order (int), step_label (string)`, timestamps. Unique (`routine_id`, `product_id`).
- `Routine` model; **`ParapharmacyProductMetadata::routines()`** = `belongsToMany(Routine::class, 'product_routine', 'product_id', 'routine_id', 'product_id')` withPivot(`step_order`,`step_label`)->orderByPivot(`step_order`) — anchored on `product_id` per the `ingredients()` precedent (§1.5 relation-home rule).

### 1.7 Equivalents / Complements (directional pivots)
- Migration `*_create_product_equivalents_table.php`: `id, tenant_id, product_id (FK cascade), equivalent_product_id (FK cascade), equivalence_type (string, EquivalenceType cast), notes (text nullable)`, timestamps. Unique (`product_id`, `equivalent_product_id`). **DB CHECK `product_id <> equivalent_product_id`** (no self-equivalence).
- Migration `*_create_product_complements_table.php`: `id, tenant_id, product_id (FK cascade), complement_product_id (FK cascade), reason (string nullable)`, timestamps. Unique (`product_id`, `complement_product_id`). **CHECK `product_id <> complement_product_id`**.
- **`ParapharmacyProductMetadata::equivalentProducts()` / `complementProducts()`** = `belongsToMany(Product::class, 'product_equivalents'|'product_complements', 'product_id', 'equivalent_product_id'|'complement_product_id', 'product_id')` (anchored on `product_id` per `ingredients()` — §1.5 relation-home rule), so they ride the gated metadata load and are DTO-reachable.
- **Store directionally; seed BOTH directions for equivalents** (A↔B) with **identical `equivalence_type`** so symmetric resolution needs no union query; a test asserts symmetry (same type both ways). (`confidence` dropped — §0.2.)

### 1.8 DTOs & API
- Extend `ParapharmacyProductMetadataData` (`#[TypeScript]`): `suitable_skin_types: SkinType[]`, `equivalent_product_ids: string[]`, `complement_product_ids: string[]`, `routine_refs: {routine_id, step_order, step_label}[]`. `fromModel` maps these from the **metadata-model relations** declared in §1.5–1.7 (`$metadata->skinSuitabilities`/`routines`/`equivalentProducts`/`complementProducts`) — reachable precisely because they live on `ParapharmacyProductMetadata`. Eager-load them inside the existing parapharmacy-only `$with` (`parapharmacyMetadata.skinSuitabilities`, etc.), never the base `$with`.
- New `BrandData` (`#[TypeScript]`): `id, name, slug, country_of_origin, website_url, is_active`.
- `ProductData` gains `public ?BrandData $brand`; `ProductData::fromModel()` maps it **only when the relation is loaded**.
- **Eager-load brand on EVERY read path** (it's universal, not vertical-gated): `ProductController::index` (`$with`), `show`, `store`, `update` response loads. Add tests for list/detail/create/update response shape.
- **POS wire shape (decided): flat.** The POS product payload exposes flat `brand_id` + `brand_name` (denormalized). If the POS shares `ProductData` (nested `brand`), the device flattens nested→flat in `upsertProducts` mapping (§4). The plan verifies which endpoint the POS catalog pull uses and pins the flatten point.
- Customer DTO (`/pos/customers/sync`) gains `skin_type`, `skin_advice_note`.
- Run `php artisan typescript:transform` → `packages/shared/types/generated.d.ts` after DTO/enum changes.

### 1.9 Enrichment-accept fix (brand convergence + provenance)
- Today `EnrichmentReviewService` (~line 80) does `'brand' => null` — enriched brand is dropped.
- Change: on accept, if `enriched_data.brand` is present and accepted, **`firstOrCreate` a `Brand`** keyed on a **single normalized identity**: normalize the name → derive the slug deterministically from that same normalized form → match/insert on `unique(tenant_id, slug)`, **inside a DB transaction with retry-on-unique-violation** (handles concurrent accepts). Set `product.brand_id`.
- **Provenance:** add `products.brand_source` (enum `BrandSource: user|enriched`, nullable) set to `enriched` here and `user` on manual edit. This is the concrete store the "field provenance" deliverable refers to (no separate provenance map).

---

## 2. Gating — vertical (data) + module (experience). Both layers, no rule-12 exception.

### 2a. Parapharmacy-specific DATA is vertical-gated
Skin type, product suitability, equivalents, complements, routines, and customer skin advice are parapharmacy business logic, emitted **only for the parapharmacy vertical**.

**Product side (automatic):** `ProductController` already loads `parapharmacyMetadata.*` only `if ($tenant->vertical === Vertical::Parapharmacy)` (index/show/store/update). The merchandising relations live on `ParapharmacyProductMetadata` (§1.5 relation-home rule) and the arrays are on `ParapharmacyProductMetadataData`, which `ProductData::fromModel` builds only in that vertical branch — so they ride the gate automatically (eager-load inside the parapharmacy-only `$with`).

**Customer side (needs an EXPLICIT guard — the sync path is NOT auto-gated):** `/pos/customers/sync` (`PosCustomerSyncController` → `PosCustomerMirrorResource`) is not vertical-aware — the resource `toArray` is a flat unconditional array and the controller resolves only tenant/company IDs. **Mandatory:** (1) `PosCustomerSyncController::index` resolves `$isParapharmacy = $companyContext->requireCompany()->tenant->vertical === Vertical::Parapharmacy` (vertical is reachable — `CompanyContext::requireCompany()` eager-loads `tenant`) and threads it into the resource (constructor arg or `->additional([...])`); (2) `PosCustomerMirrorResource` wraps the two keys in `mergeWhen($isParapharmacy, ['skin_type'=>…, 'skin_advice_note'=>…])` — **omit the keys entirely** when false (a bare `when()` can't reach the vertical alone). Feature-test both verticals (parapharmacy includes; non-parapharmacy excludes). The server payload is the authority for non-leakage; the POS SQLite columns stay nullable.

### 2b. Brand is universal (intentionally NOT gated)
Per the locked decision, `Brand`/`brand_id`/`brand_name` is cross-vertical and ecommerce-ready — emitted for all verticals, eager-loaded on every product read path (§1.8). It is the one merchandising-adjacent field that is deliberately not vertical-scoped.

### 2c. The merchandising EXPERIENCE is module-gated within parapharmacy
A `Merchandising` module key gates the UX (Filtres drawer, skin-advice bar, Équivalents/Compléments/Routine tabs) so it can be unbundled to a paid extra later even though the vertical already has the data.
- Add `case Merchandising` to `app/Enums/ModuleName.php` **and** `parapharmacy.default_modules` in `config/verticals.php` **in the same commit** — `ModuleNameTest` enforces enum value-set == config union, bidirectionally; either alone fails CI.
- **Entitlement mechanism (verified, no new device plumbing):** FE `hasModule(config,'Merchandising')` reads `companyConfig.all_enabled_modules` (`productStore.ts`), fetched from `/company/config` (`productApi.ts`), cached (`companyConfigCache`), refreshed each `runFullSync` (`authStore.refreshCompanyConfig`). Backend `CompanyConfigService` merges `default_modules` + enabled extras. **Only backend check:** a test that `/company/config` includes `Merchandising` for parapharmacy tenants. Offline correctness depends on the cache being hydrated after the module is added — covered by an offline-startup hydration test.
- **No dedicated `merchandising.*` permission set now** — vertical + module gating suffice.

**Gating model (stated precisely — no overclaim):** merchandising **data** is **vertical-gated** — the established pattern for vertical-exclusive metadata that has no single owning module (see `docs/architecture/vertical-module-gating.md`; automotive metadata is likewise authorized by vertical, not module). The `Merchandising` **module gates only the experience (UX)**.

**Unbundle-to-paid consequence (honest):** moving `Merchandising` from `default_modules` to a priced `compatible_extras` later **hides the POS UX but does NOT withhold the data** — the tenant is still the parapharmacy vertical, so the server still emits it and the device still caches it. The monetized lever is the merchandising *experience*, not the raw fields; a non-entitled parapharmacy terminal still holds the dataset in SQLite. **This is fine for a bundled-by-default demo.** If real confidentiality on unbundle is ever required, the payload emission must **also** become module-gated server-side (a future code change — gate the `/products` arrays + `/pos/customers/sync` skin fields on the `Merchandising` entitlement, not just the vertical). That is explicitly out of scope now; "unbundle = config-only" is therefore **not** claimed.

---

## 3. Seeding (`database/seeders/ParapharmacySeeder.php`, 1495L)

Self-provisioning (creates its own tenant with `Vertical::Parapharmacy`; not called from `DatabaseSeeder`/`DemoTenantSeeder`). Add methods mirroring `assignIngredients()` (`DB::table()->insert()` batches, ~line 717), called from `run()`; subclasses `DemoPharmacySeeder` (Tunisia) / `ParapharmacyMultiBranchSeeder` inherit. Scale via `PARAPHARMACY_SEEDER_SCALE` (default 1). All inserts carry `tenant_id`.

- `seedBrands()` — ~15–25 **real** French parapharmacy brands (Avène, La Roche-Posay, Bioderma, Vichy, CeraVe, Nuxe, Mustela, Caudalie, Uriage, Ducray, A-Derma, Klorane, SVR, Embryolisse…). Create `brands` rows (tenant-scoped, deterministic slug); assign `products.brand_id` by category heuristics; set `brand_source = 'user'`.
- `seedProductSkinSuitability()` — map cosmetic/visage products to 1–3 skin types by heuristics.
- `seedProductEquivalents()` — within category+form, link `generic`/`brand_alt` equivalents, **both directions, identical type**.
- `seedProductComplements()` — cross-category bundles (cleanser → moisturiser → SPF).
- `seedRoutines()` + membership — named routines (visage peau sèche/grasse/sensible) with 3–4 ordered steps.
- `seedCustomerSkinTypes()` — assign skin types to demo individual partners.

Verify row counts after `db:seed --class=ParapharmacySeeder`.

---

## 4. POS offline sync (device SQLite, `apps/pos`)

Current max device migration = **v57**. **Split into two versions; coordinate the exact numbers with the loyalty session (`feat/loyalty-earn-per-product`) before either pushes** — both touch device migrations and must not both claim the same version. The server emits the parapharmacy merchandising JSON and customer skin fields **only for the parapharmacy vertical** (§2a); `brand_id`/`brand_name` are universal.

- **v58 — products.** `ALTER TABLE products ADD COLUMN brand_id TEXT; ADD COLUMN brand_name TEXT; ADD COLUMN parapharmacy_metadata TEXT` (JSON `{suitable_skin_types[], equivalent_product_ids[], complement_product_ids[], routine_refs[]}`). **Then `DELETE FROM sync_metadata WHERE key = 'products_last_sync'`** so the next pull is a full re-fetch and backfills the new columns (v57 precedent at `migrations.ts:1773-1788`). Guard ALTERs idempotently.
- **v59 — customers.** `ALTER TABLE customers ADD COLUMN skin_type TEXT; ADD COLUMN skin_advice_note TEXT`. **Then clear the customer cursor** (`customers.updated_since` / whatever key `pullCustomers` persists) so customers re-baseline.
- **Migration tests** mirroring `migrations.v57.test.ts`: seed the cursor, apply v58/v59, assert the cursor is gone and the next pull omits `updated_since`.
- **`productRepository.ts`:** extend `ProductRow` (+`brand_id`,`brand_name`,`parapharmacy_metadata`), `rowToProduct` (JSON.parse metadata; flatten nested `brand`→`brand_id`/`brand_name` if the payload is nested), `POSProduct` (`brand_id?`,`brand_name?`,`parapharmacy_metadata?`). **`upsertProducts` is a full rewrite, not a constant bump:** the `INSERT (…columns…)`, the `($n … , datetime('now'), datetime('now'))` value template, the `ON CONFLICT … SET`, and `PARAMS_PER_ROW` (15→18) all change together.
- **Customers + `pullCustomers` wiring:** `CustomerMirrorRow` (`customerTypes.ts`) + `customerRepository.upsertCustomer` gain the two fields; `/pos/customers/sync` includes them. **Wire `pullCustomers(db, tenantId, companyId)` into `runFullSync()`** (currently orphaned — zero callers). IDs come from `authStore`. **Extend `SyncResult`** with `customersPulled` (+ `customersFailed`/error signal). **Failure policy:** catch, log, mark degraded, continue selling (like variants/location-stock); **never advance the customer cursor on failure.** Parapharmacy owns this wiring; loyalty rebases. Add tests: successful call + arg source, result accounting, swallow-vs-propagate per error class, and a regression test that `runFullSync` calls `pullCustomers` (guards the orphan).
- Equivalents/complements/routines resolve **locally** from the product JSON IDs against the in-memory product store. No extra device tables.

---

## 5. POS UI (redesign phases P9/P5/P8)

> **Sequencing:** reuses redesign atoms + token system, which live on `feat/pos-caisse-redesign` (**not yet on `origin/dev`**, diverged 3/10). Data/sync layers (§1–4) proceed now; **this UI rebases onto the redesign branch** so the token system resolves. Escalate if still unmerged when UI work starts. ESLint color guard is **ERROR** on these files — tokens only; both themes.
>
> **REBASE PREREQUISITES (verify these exist on the rebased branch BEFORE starting §5; r2 confirmed they are NOT on `origin/dev`):** atoms `ProductThumb`/`StockBadge`/`Pill`/`Tabs` (current `components/ui/index.ts` exports only `Button`/`IconButton`/`Badge`/`StatusPill`/`SegmentedControl`); tokens `stock-*`/`accent`/`rounded-tile`/`rounded-card`; the `ezTap` keyframe (spec already notes it must be added); `settingsStore.density` (current store has `displayMode` only); the `/customers` route + `NavRail` (current routes: `/`, `/settings`, `/sales`, `/reports/z`). If any are absent after rebase, coordinate with the redesign owner — do NOT create them in pre-rebase data/sync work. **Only the `displayMode` dual-source reconcile (§5.2) is buildable pre-rebase.** Active in-place targets are `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx` and `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx` — **NOT** the legacy `components/pos/*` copies.

### 5.0 Ownership split (coordination handoff from the redesign session, 2026-06-28)
Because this session owns product/customer **data rendering** (brand, skin-type, equivalents/complements on the card/grid), it also does the **visual restyle of `ProductCard` + `ProductGrid`** — restyling once together with the data wiring avoids a guaranteed merge conflict between the two branches.
- **This session owns:** `ProductCard`, `ProductGrid`, Filtres drawer, skin-advice bar, product-detail Équivalents/Compléments/Routine tabs, the **`/customers` page** (a placeholder + route + nav-rail item already exist on the redesign branch — **build it out in place, do not create a parallel page**), and all product/customer data + sync.
- **Redesign session owns (ping before touching):** theme tokens + atoms (`components/ui/*`), `NavRail`, `Header`, `AppShell` layout, the cart (`TransactionCart`, `CartLineItem`), payment/modal/report shells.
- **Reuse from `@/components/ui`** (do not duplicate): `ProductThumb` (88px tinted-initials tile + category tint + image fallback), `StockBadge` (`status="ok"|"low"|"out"` using dedicated `stock-*` tokens — **NOT** success/warning/danger; see `apps/pos/docs/design-language.md` stock exception; map from `isOutOfStock`/`isLowStock`), `Pill` (category toggle + removable filter chips), `Tabs` (detail tabs w/ counts), `Badge`, `SegmentedControl` (view toggle), `Button`/`IconButton`. Tokens: surfaces/ink/border; **accent** = selected/highlight (in-cart, active pill); **action** (blue) = primary CTA only; prices `font-mono` + `tabular-nums`.

### 5.1 `ProductCard` restyle — IN PLACE (mock §5.1/§5.7)
Preserve **all** logic + data-testids (`in-cart-badge`, `view-details-button`, `price-row`, `stock-row`, `incoming-badge`), the `locationStock` three-path stock logic, modifiers, activation-block, keyboard handling, and `memo`.
- **Visual:** `ProductThumb` on top; info-eye top-left (must work out-of-stock); **brand in caps above the name** (universal, §2b); price mono; `StockBadge`.
- **In-cart state (§5.7):** full **accent** outline + accent-tint bg + 3px top accent bar + ✓ in the qty badge. Use **accent**, not action (owner decision).
- **Tap pulse:** `ezTap` ~420ms on add — add the keyframe to `index.css` (not yet present), named `ezTap`, using `var(--accent-ring)`.
- **Compact card:** no thumb. **Out-of-stock:** dimmed (`surface-sunken`/`ink-faint`) + `StockBadge status="out"`, fiche still openable.

### 5.2 `ProductGrid` restyle + required dual-source reconcile
`ProductGrid` (`components/organisms/ProductGrid/ProductGrid.tsx`) currently keeps its own `useState` from `localStorage['pos-display-mode']` and **ignores `settingsStore.displayMode`**. **Buildable now:** make it read `displayMode` from `settingsStore` (resolves the dual-source). **Density is a rebase prerequisite** — `settingsStore.density` does NOT exist on `origin/dev` (the store has only `displayMode`); it's expected from the redesign branch. Once present, thread it (JS-driven) into `getColumns(displayMode, density, width)` (currently takes `displayMode` only) + `CARD_MIN_H_*` (visual+comfortable=5, visual+dense=6, compact+comfortable=4, compact+dense=5; current `cardSizing.ts` has only `CARD_MIN_H_GRID`/`CARD_MIN_H_VISUAL`). If density is absent after rebase, escalate to the redesign owner — do not invent it here.
- **Toolbar:** search · Filtres (badge) · Top ventes · view toggle (`SegmentedControl`). Category `Pill` row; active filter-chip row + count.

### 5.3 Merchandising overlays (module-gated `hasModule('Merchandising')`, §2c)
- **P9 Filtres drawer** (`HomePage`): brand / category / routine / skin-type multi-select from the local product set; removable chips (`Pill`) + live count.
- **P9 Skin-advice bar** (`ProductGrid` header): skin-type pills filter by `suitable_skin_types`; defaults from the selected customer's `skin_type`.
- **P9 Product-detail tabs** (`ProductDetailDrawer`): **Équivalents / Compléments / Routine** (`Tab` w/ counts) — resolve IDs → local products → `ProductThumb` rows with one-tap add-to-cart.

### 5.4 Customers page + capture (P5/P8) — build out `/customers` in place
Capture/show `skin_type` (+ advice note); the customer detail drives the skin-advice-bar default.

> Gating: the base `ProductCard`/`ProductGrid` restyle and universal brand caps are unconditional; the **§5.3 merchandising overlays** are module-gated. French i18n; tokens only; both themes.

---

## 6. Platform-population seam (later, additive)
ERP is the source of truth now. The Synerivia platform later pushes enrichment (brand, equivalents, skin suitability, routines) through the existing `enrichment_results` pipeline → accepted into the brand/metadata/pivots (§1.9 keeps user-edit and platform write paths converging). **Deliverable now:** document the field-provenance + enrichment field-mapping in `docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md`.

---

## 7. Build order (each: TDD → PHPStan L8 + Pint → typecheck/lint → seed-verify → Codex code review → commit)
1. **Enums** — shared `SkinType` (+ migrate both SmartPrompts consumers, enum-derived validation, delete old) ; `EquivalenceType`. `typescript:transform`.
2. **Brand** — `brands` table (tenant-scoped) + model + `products.brand_id` + `brand_source` + `BrandData` + eager-load on all read paths. **Enrichment-accept fix** (§1.9).
3. **Merchandising data** — `partners.skin_type`/`skin_advice_note`; `product_skin_suitability` (HasMany); `routines`+`product_routine`; `product_equivalents` (CHECK); `product_complements` (CHECK); models/relations; extend `ParapharmacyProductMetadataData`. `typescript:transform`.
4. **Module gating** — `Merchandising` in `ModuleName` enum + `parapharmacy.default_modules` (same commit); `/company/config` projection test.
5. **Seeder** — brands/suitability/equivalents(both dirs)/complements/routines/customer skin types; run + verify counts.
6. **Server payloads** — `/products` (flat `brand_id`/`brand_name` + merchandising arrays inside the parapharmacy-only `$with`) and `/pos/customers/sync` (skin fields **with the §2a vertical guard**: controller resolves `$isParapharmacy`, resource `mergeWhen`).
7. **POS offline** — v58 (products + cursor reset) + v59 (customers + cursor reset) + repos + types + `upsertProducts` rewrite + **wire `pullCustomers`** + `SyncResult` contract.
8. **POS UI** (rebase onto redesign branch) — `ProductCard` + `ProductGrid` restyle in place + `settingsStore` dual-source reconcile (§5.1–5.2); build out `/customers` in place (§5.4); module-gated Filtres / skin-advice / detail tabs (§5.3).
9. **Docs** — REALIGNMENT-LOG enrichment field-mapping; memory update.

---

## 8. Testing strategy
- **Backend (PHPUnit, by path — never the full suite):** enum cases + `Rule::enum` SmartPrompts validation accepts all 5 / rejects invalid; Brand slug `unique(tenant_id,slug)`; `brand_id` nullable + nullOnDelete; brand present on **all** product read paths (list/detail/create/update); enrichment-accept `firstOrCreate` upserts + links Brand + sets `brand_source` (incl. concurrent-accept dedup); `skinSuitabilities` HasMany pluck → `suitable_skin_types`; routines/equivalents/complements withPivot ordering; equivalents seeded both directions identical type (symmetry); self-reference CHECK rejects; **`/pos/customers/sync` includes skin fields for parapharmacy and EXCLUDES them (keys absent) for a non-parapharmacy tenant with populated columns (D1-C1 guard)**; product merch arrays absent for non-parapharmacy product reads; `/company/config` includes `Merchandising` for parapharmacy; seeder row counts at scale=1.
- **POS (Vitest):** v58 & v59 apply + **cursor-reset tests** (seed cursor → migrate → assert gone, next pull omits `updated_since`); `productRepository` round-trips brand columns + parapharmacy JSON + nested→flat flatten; `customerRepository` round-trips skin fields; `runFullSync` calls `pullCustomers` (orphan regression) with `authStore` IDs; `SyncResult.customersPulled`/failure accounting; failure policy (swallow vs propagate); local resolution of equivalent/complement/routine IDs; `hasModule('Merchandising')` gating + offline-startup hydration from `companyConfigCache`.
- Constructor injection only; enums for type columns; i18n `t()` for POS strings; tokens only; no float casts.

---

## 9. Risks & open items
- **Gating (owner 2026-06-28):** merchandising data is **vertical-gated** (established pattern — see §2c + `vertical-module-gating.md`); the `Merchandising` module gates the **experience only**; unbundling hides UX, not data. Customer-sync needs the explicit guard in §2a (the path is not auto-gated).
- **Redesign-branch dependency** for §5 UI (atoms not on `origin/dev`). Mitigation: build §1–4 first; rebase UI later.
- **Device migration version coordination** with the loyalty session — claim distinct v58/v59 numbers (or land the shared customer ALTER once and have loyalty rebase) before either pushes.
- **POS catalog endpoint** — verify whether the POS pulls the same `/products` (nested `ProductData`) or a POS-specific serializer, to pin the brand flatten point (§1.8/§4).

---

## 10. Out of scope (explicit)
Automotive brand/manufacturer (catalogue-search/OE — TecDoc/AAIA); a `Manufacturer` entity; routine authoring UI; brand logo upload; brand `external_refs`/GS1; equivalence `confidence`; dedicated `merchandising.*` permissions; the platform-side canonical brand registry (only the `canonical_brand_id` nullable seam is added now).
