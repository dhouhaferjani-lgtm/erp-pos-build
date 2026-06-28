# Handover — Parapharmacy Merchandising (data + UI, end-to-end)

> Status: **PLAN for review** (build after sign-off). Branch context: `feat/pos-caisse-redesign` (worktree `../erp.pos-caisse`). Pairs with the POS redesign spec (`2026-06-27-pos-caisse-redesign-design.md`, phases P5/P8/P9) and tracker.
> Goal (owner, 2026-06-28): build the parapharmacy merchandising features **full-stack with seeded demo data** so they work in the POS now and are **user-populatable in the ERP**, with **Synerivia-platform population later**. No fabricated runtime data — real model + seeders.

## 0. Scope

Five capabilities, each end-to-end (backend model → seed → offline sync → POS UI):
1. **Brand** on products (surface + filter).
2. **Skin type** — a `SkinType` enum used two ways: (a) **customer** skin type, (b) **product suitability** (which skin types a product suits).
3. **Routine membership** — a routine (e.g. "Routine visage – peau sèche") is an ordered set of products; products know which routines they belong to.
4. **Equivalents / Complements** — product↔product relations (substitute vs cross-sell).
5. Wire all of the above into the redesigned POS: **Filtres drawer**, **skin-advice bar**, product-detail **Équivalents/Compléments/Routine** tabs, and customer skin-type capture.

## 1. Backend data model (`apps/api`, hexagonal — Product + Partner modules)

All tables are **tenant-scoped** (db-per-tenant). DTOs carry `#[TypeScript]` → `php artisan typescript:transform` → `packages/shared/types/generated.d.ts`. Enums for all type columns (rule 9). Strict typing, constructor injection, PHPStan 8.

### 1.1 Enums (`Product/Domain/Enums/`, `Partner/Domain/Enums/`)
- `SkinType` (shared concept; put in `Partner/Domain/Enums/SkinType.php`, referenced by Product suitability): `oily, dry, combination, normal, sensitive, acne_prone, mature, unknown`.
- `EquivalenceType` (`Product/Domain/Enums/`): `generic, therapeutic, brand_alt` (why two products are equivalent).

### 1.2 Brand
Brand today is **enrichment-only** (`EnrichedProductData.brand` from `enrichment_results` JSONB) — not a queryable column. For filters we need it queryable.
- **Decision:** add nullable `brand` (string, indexed) to the `parapharmacy_product_metadata` table (vertical-scoped — avoids touching the core `products` table for a parapharmacy concern). Populate from enrichment when accepted, and user-editable. (If a first-class `Brand` entity is wanted later, migrate then; a string is right for the demo + filters now.)
- Migration: `*_add_brand_to_parapharmacy_product_metadata.php`. Add to `ParapharmacyProductMetadata` model + `ParapharmacyProductMetadataData` DTO.

### 1.3 Customer skin type (`Partner` module)
- Add nullable `skin_type` (string, `SkinType` enum cast) + `skin_advice_note` (text) to `partners` table. Migration `*_add_skin_type_to_partners.php`. Add to `Partner` model + customer DTO.
- (Approach A — direct columns. A `partner_parapharmacy_metadata` table is overkill for two fields; revisit if customer attributes grow.)

### 1.4 Product skin suitability (pivot)
- Table `product_skin_suitability` (`product_id`, `skin_type`) — a product suits N skin types. Migration + `Product::suitableSkinTypes()` (could be a simple table or `withPivot` strength later).

### 1.5 Routines
- `routines` table (`id, name, description, season/period nullable`) + pivot `product_routine` (`routine_id, product_id, step_order, step_label` e.g. "Nettoyage"/"Hydratation"/"Protection"). `Routine` model + `Product::routines()` belongsToMany withPivot(step_order, step_label).

### 1.6 Equivalents / Complements (pivot, directional)
- `product_equivalents` (`product_id, equivalent_product_id`, `equivalence_type`, `confidence` nullable, `notes` nullable). `product_complements` (`product_id, complement_product_id`, `reason` nullable). Follow the ingredient `belongsToMany withPivot` pattern.
- `Product::equivalentProducts()` / `complementProducts()`. **Store directionally; seed both directions** for equivalents (A↔B) so resolution is symmetric without a union query.

### 1.7 DTOs & API
- Extend `ParapharmacyProductMetadataData` with `brand`, `suitable_skin_types: SkinType[]`, `equivalent_product_ids: string[]`, `complement_product_ids: string[]`, `routine_refs: {routine_id, step_order, step_label}[]`. The POS product payload (`/products`) must include these **resolved as ID arrays** (the device already holds all products → resolves IDs locally; avoids N+1 on device).
- Customer DTO (`/pos/customers/sync`) gains `skin_type`, `skin_advice_note`.

## 2. Seeding (`database/seeders/ParapharmacySeeder.php`, 1495L, scalable via `PARAPHARMACY_SEEDER_SCALE`)
Add methods mirroring `assignIngredients()`:
- `seedProductSkinSuitability()` — map each cosmetic/visage product to 1–3 skin types by category heuristics.
- `seedProductEquivalents()` — within a category+form, link a few products as `generic`/`brand_alt` equivalents (both directions).
- `seedProductComplements()` — cross-category bundles (e.g. cleanser → moisturiser → SPF).
- `seedRoutines()` + membership — a handful of named routines (visage peau sèche/grasse/sensible) with 3–4 ordered steps.
- `seedCustomerSkinTypes()` — assign skin types to the 150 individual demo partners.
Use `DB::table()->insert()` batches (existing pattern, ~line 717). Realistic French names. Gate the parapharmacy-specific seeding to the parapharmacy vertical.

## 3. Offline sync (POS device SQLite — see offline-sync map)
Adding fields offline = migration + type + repository upsert (+ server payload). To avoid per-field churn, **carry all parapharmacy fields in one JSON column**:
- **Products:** add device migration (next version after current max in `lib/db/migrations.ts`) `ALTER TABLE products ADD COLUMN parapharmacy_metadata TEXT` (JSON: `{brand, suitable_skin_types[], equivalent_product_ids[], complement_product_ids[], routine_refs[]}`). New fields ride inside the JSON with no further migrations. Update `productRepository` (`ProductRow`, `rowToProduct` JSON.parse, `upsertProducts` JSON.stringify, bump `PARAMS_PER_ROW`) + `POSProduct` type (`parapharmacy_metadata?: ParapharmacyMeta`). Server `/products` includes the JSON.
- **Customers:** `ALTER TABLE customers ADD COLUMN skin_type TEXT` (+ `skin_advice_note`). Update `CustomerMirrorRow`, `customerRepository.upsertCustomer`, and **wire `pullCustomers()` into `runFullSync()`** (it is currently orphaned — must integrate, else customer sync never runs). `/pos/customers/sync` includes the fields.
- Equivalents/complements resolve **locally**: the product JSON holds related product IDs; the POS looks them up in its in-memory `productStore`. No extra device tables.

## 4. POS UI (redesign phases)
- **P9 Filtres drawer** (`HomePage`): brand / category / routine / skin-type multi-select sourced from the local product set; active **filter chips** (removable, `Pill` atom) + live result count.
- **P9 Skin-advice bar** (`ProductGrid` header): skin-type pills (`Pill`) filter the grid by `suitable_skin_types`; "Conseil · Type de peau" + count.
- **P9 Product-detail tabs** (`ProductDetailDrawer`): existing Description/Ingrédients/Indications + new **Équivalents / Compléments / Routine** (`Tab` atom) — resolve IDs → local products → `ProductThumb` rows with one-tap **add to cart** (cosmetic upsell).
- **P5/P8 Customer:** add-customer + detail capture `skin_type` (+ advice note); detail page shows it and drives the advice bar default.
All French via i18n; tokens only; both themes.

## 5. Platform-population seam (later)
ERP is the source of truth for tenant data now (users populate via editors/POS). The **Synerivia platform** later pushes enrichment (brand, equivalents, skin suitability, routines) through the **existing `enrichment_results` pipeline** (`EnrichmentResult` model, `accepted_fields`) → accepted into the parapharmacy metadata/pivots. Keep the write paths (user edit vs platform enrichment) converging on the same model so platform sync is additive, not a rewrite. Document the field provenance (user vs enriched) when wiring acceptance.

## 6. Build order (each: TDD → typecheck/PHPStan/lint → seed-verify → Codex code review → commit)
1. Enums + migrations (brand, customer skin_type, suitability, routines, equivalents, complements).
2. Models + relations + DTOs (+ `typescript:transform`).
3. Seeder methods + run `ParapharmacySeeder` (verify rows).
4. Server payloads (`/products`, `/pos/customers/sync`) include the new data.
5. POS offline plumbing (device migrations + repos + types + wire `pullCustomers`).
6. POS UI (Filtres, skin-advice, detail tabs, customer capture) — redesign phases P9/P5/P8.
7. (Optional now / required for self-serve) editor surfaces (izipos product editor / web admin) for user population.

## 7. Open questions for owner
- **Brand**: string on metadata now vs first-class `Brand` entity? (Plan assumes string for demo+filters.)
- **Routine** authoring: seeded only for the demo, or do users build routines in-app at launch? (Plan: seeded demo; editor later.)
- **Equivalents source**: demo-seeded + user-edited now; platform-enriched later — confirm the enrichment field mapping when platform work starts.
- Skin-type list (8 values above) — confirm the canonical set for Tunisia parapharmacy.
