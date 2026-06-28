# Adversarial Design Review — Parapharmacy Merchandising + First-Class Brand

> Reviewer: skeptical senior architect (Claude). Date: 2026-06-28.
> Spec under review: `docs/superpowers/specs/2026-06-28-parapharmacy-merchandising-design.md`
> Worktree: `/Users/houssamr/Projects/syneriva/apps/erp.parapharm` (branch `feat/parapharmacy-merchandising`).
> Grounding read in full: spec; `ModuleName.php`; `config/verticals.php`; `ParapharmacyProductMetadata.php`; `EnrichmentReviewService.php`; `syncService.ts`; `customerSyncService.ts`; `productRepository.ts`; `SmartPrompts/Domain/Enums/SkinType.php`; `ModuleNameTest.php`; `create_products_table` migration; `companyConfig.ts` + `hasModule()`.

## Verdict
The design is sound in its big bets (Shared `SkinType`, Brand in the `Product` module, data-unconditional/experience-gated split, fixing the `'brand' => null` drop). Most of the risk is in **data-model precision** and a few **assertions the spec hedges that are actually settled by the code** (and one it states confidently that is actually under-specified). Counts: **1 Critical, 6 Important, 4 Nit/Scope.**

---

## Adjudication of the three flagged assertions (requested)

### A. Device module-entitlement sync — **THE ASSUMPTION HOLDS.** Spec is over-cautious; downgrade it.
The spec hedges three times ("confirm the device entitlement-sync mechanism in the plan", §2; "Device module-entitlement sync … confirm the existing mechanism … if not, that's a small added pull", §9 risk). This is already a **proven, in-production mechanism**, not an assumption:
- `apps/pos/src/stores/productStore.ts:110` `hasModule(config, moduleName)` reads `CompanyConfig.all_enabled_modules` (`apps/pos/src/types/companyConfig.ts`).
- `config` is fetched from `/company/config` (`apps/pos/src/api/productApi.ts:42-43`), persisted via `companyConfigCache`, and **refreshed every sync tick** — `runFullSync` calls `useAuthStore.getState().refreshCompanyConfig()` (`syncService.ts` ~line 2040).
- `hasModule(config, 'Menu')` is the live precedent driving catalog routing (`syncService.ts:702`, `resolveCatalogTenantGate`).
`hasModule(config, 'Merchandising')` will work **identically** with zero new device plumbing. The *only* real verification left is backend-side: confirm `/company/config` builds `all_enabled_modules` from the vertical's `default_modules` so `Merchandising` appears once it's added there. **Net: the device side needs no "small added pull"; rewrite §9's risk bullet from "confirm the mechanism" to "confirm `/company/config` projects `default_modules` into `all_enabled_modules`."**

### B. ModuleName enum step — **REAL and bidirectionally enforced.** Spec correctly includes it.
`apps/api/app/Enums/ModuleName.php` is a closed enum; `tests/Unit/Enums/ModuleNameTest.php` has **two** guards: every config name must have a case AND `test_enum_value_set_equals_union_of_names_in_verticals_config` asserts the enum value-set **equals** the union of `default_modules`+`compatible_extras`. Consequences the plan must respect:
- Adding `'Merchandising'` to `parapharmacy.default_modules` **without** a `case Merchandising` fails the test.
- Adding `case Merchandising` **without** a config reference **also** fails (equality). So the enum case and the config entry must land in the **same commit**. The spec's Build-order step 4 bundles them — good. (`Merchandising` is not yet a case — verticals.php currently ends at `Ecommerce`.)

### C. SmartPrompts `SkinType` migration — **risk is OVERSTATED.** Clean, value-safe move.
`apps/api/app/Modules/SmartPrompts/Domain/Enums/SkinType.php` has **exactly** the 5 values the shared enum will reuse (`normal, oily, dry, combination, sensitive`) — no extra cases to drop. Only **two** consumers (`RecommendationRequestData`, `SmartPromptsController`). Critically, it is **not persisted in any DB column**: it appears only as a request DTO, an outbound HTTP field `->value` (`RecommendationEngineHttpClient.php:49`), and an inline validation `in:normal,oily,dry,combination,sensitive` (`SmartPromptsController.php:32`) — that string is unchanged because the values are identical. So there is no stored-data deserialization break. **Downgrade §9's "touches an AI feature" framing; the isolated-commit + run-tests-by-path hygiene is still correct, but the risk is low.**

---

## CRITICAL

### C1. `brands` table omits `tenant_id` (and `company_id`) — breaks schema convention, makes "unique per tenant" unimplementable, and undermines the enrichment tenant-match
Spec §1.3 defines the `brands` columns with **no `tenant_id`/`company_id`**, yet calls `slug` "unique per tenant" and §1.9 matches enrichment brand "by tenant-normalized name". There is no column to scope either by.
- Every tenant-scoped table in this codebase carries `tenant_id` despite db-per-tenant: `create_products_table` (`2025_11_30_052910`) has `$table->uuid('tenant_id')`, `unique(['tenant_id','sku'])`, and four `tenant_id` indexes; `EnrichmentReviewService` writes both `tenant_id` and `company_id` (lines 41-43). Brand would be the lone exception.
- Risk is not cosmetic: if any shared model trait/global scope auto-applies `where('tenant_id', …)` (as the manual filters in `EnrichmentReviewService::listForReview` suggest is the norm), every `Brand` query 500s. At minimum `products.brand_id` FK joins a tenant-scoped `products` row to a non-tenant-scoped `brands` row.
**Fix:** add `tenant_id` (and `company_id` if products carry it) to `brands`; make the slug index `unique(['tenant_id','slug'])`; make the enrichment match `where('tenant_id', …)->where(normalized name)`. If the deliberate intent is "brand is cross-company within a tenant DB", say so explicitly and justify dropping `company_id` against the convention — but `tenant_id` is non-negotiable given the unique/match claims.

---

## IMPORTANT

### I1. `Product::suitableSkinTypes()` cannot be `belongsToMany … withPivot` — there is no `skin_types` table to belong to
Spec §1.5 models suitability "on the `product_ingredient` belongsToMany withPivot precedent." But `product_ingredient` belongs to a real `ingredients` table; `product_skin_suitability` stores an **enum string** (`skin_type`) with **no target table**. `belongsToMany` requires a related model/table. As written this relation won't build.
**Fix:** model it as `hasMany(ProductSkinSuitability::class)` and expose `suitable_skin_types: SkinType[]` by plucking the `skin_type` column (cast to the Shared enum), or document a tiny `ProductSkinSuitability` pivot-model the DTO reads. The `withPivot` precedent applies to routines/equivalents/complements (real product↔product), **not** to suitability.

### I2. Brand wire-shape contradiction between §1.8 and §4
§1.8: `ProductData` gains nested `brand: BrandData|null` and `/products` "includes `brand`." §4: the device stores **flat** `brand_id` + denormalized `brand_name` columns and says "Server `/products` includes these." These are two different wire shapes and no mapping is specified. `pullProductsCore` → `upsertProducts` maps `POSProduct` fields verbatim (`productRepository.ts:121-146`); if the server sends nested `brand`, `POSProduct.brand_id`/`brand_name` stay `undefined` and the device columns never populate.
**Fix:** pick one. Either (a) `/products` emits flat `brand_id`/`brand_name` for the POS payload (cleanest for the device), or (b) keep nested `brand` and add an explicit flatten step in `rowToProduct`/`upsertProducts`. State the chosen POS wire shape in §4.

### I3. Enrichment-accept brand upsert: name-match vs slug-dedup mismatch, race on concurrent accepts, and **provenance has no storage**
§1.9 matches by "tenant-normalized name" but §9 leans on the **slug** unique index to dedup. These are different keys:
- Two distinct brands whose names normalize to the same slug ("L'Oréal" / "L Oréal") collide on insert; two spellings of one brand ("La Roche Posay" / "La Roche-Posay") produce different slugs → **two** Brand rows, breaking the "user-edit and platform-enrichment converge on one Brand" guarantee that §1.9/§6 promise.
- Concurrent accepts of two products carrying the same new brand race to create duplicate rows unless wrapped in `firstOrCreate` on a **normalized-name unique key** with retry-on-unique-violation. `EnrichmentReviewService::accept` is plain `$product->update($updates)` today — no transaction around a brand upsert.
- **Provenance is named as a deliverable three times** (§1.9 "Record field provenance", §6 "field-provenance", §0.2) but **no provenance column exists anywhere** — not on `brands` (§1.3), not on `products`, and `enrichment_results.accepted_fields` is only a `{field: true}` bool map (`EnrichmentReviewService.php:100-103`). Either add a real provenance store (e.g. `products.brand_source` enum user|enriched, or a provenance map column) or drop the claim.
**Fix:** match+dedup on a single normalized key (normalize name → derive slug deterministically from the same normalized form; unique on `(tenant_id, slug)`); use `firstOrCreate` in a transaction; specify where provenance is written.

### I4. Single device migration v58 bundles product + customer ALTERs **and collides with the loyalty session**
§4 puts brand columns, `parapharmacy_metadata`, and customer `skin_type`/`skin_advice_note` all in **one** v58 migration. Two problems:
- The loyalty session (`feat/loyalty-earn-per-product`) is also device-side and, per the memory index, also adds POS work — if it also claims the next version, **two sessions both write v58** and one silently wins / the migration runner double-applies. The spec coordinates `pullCustomers` co-ownership but **not** the migration version.
- A multi-ALTER v58 that half-applies (e.g. an ALTER re-run after a partial failure hits "duplicate column") leaves v58 marked incomplete and re-runs, failing on the already-added column unless each ALTER is guarded.
**Fix:** coordinate the version number with the loyalty session before either pushes (claim distinct versions, or land the shared customer ALTER once and have loyalty rebase). Make each ALTER idempotent or split product-v58 / customer-v59.

### I5. Rule-12 both-layer gating is only half-satisfied — the data side ships ungated by design; make it an explicit signed-off exception
§2 ships all merchandising **data** (`skin_type`, suitability/equivalents/complements/routines, `brand_id`) unconditionally inside the existing `/products` and `/pos/customers/sync` payloads, with gating **only** on the FE (`hasModule('Merchandising')`). There are effectively **no dedicated merchandising endpoints to gate** server-side, so rule 12's "vertical-exclusive routes AND fields must be module-gated on both layers" is met on one layer only. The spec's "data is universal/cheap/harmless" rationale is defensible, but a reviewer applying rule 12 mechanically will flag the ungated parapharmacy-shaped fields in a shared payload.
**Fix:** call this out as a **conscious rule-12 exception** ("data is cross-vertical and unconditional; only the experience is gated") with owner sign-off recorded, rather than leaving it implicit. If any endpoint does become merchandising-only later, gate it `module:Merchandising`.

### I6. Equivalents "seed both directions" with no symmetry guarantee, and no self-reference guard on any relation pivot
§1.7/§3 store equivalents directionally and seed A→B **and** B→A. `unique(product_id, equivalent_product_id)` permits both rows but **nothing keeps `equivalence_type`/`confidence` consistent** between them — they can silently diverge. None of the three pivots (equivalents, complements, suitability via product) has a `product_id != related_id` CHECK, so a product can be its own equivalent/complement.
**Fix:** for a demo, either store one canonical direction and resolve with a UNION (avoids divergence) or assert symmetry in the seeder + a test. Add a CHECK (or seeder/validation guard) preventing self-reference on equivalents/complements.

---

## NIT / SCOPE (YAGNI for a demo)

### N1. Over-built surfaces for a demo
- `external_refs` JSONB + `BrandExternalRefsData` DTO (GS1 GLN): zero demo value; defer to the canonical-registry seam.
- `confidence` decimal on equivalents: seeded demo links need no confidence score, and a decimal column needlessly pulls Brand/equivalents into the `ForbidFloatCastOnDecimalProperty` precision-guard surface (§8 even concedes bcmath handling for it) — drop it.
- `merchandising.*` permission set + `RolesAndPermissionsSeeder` registration: for a **bundled-by-default** USP, module gating alone likely suffices; a full permission set is premature. Borderline — keep only if the unbundling-to-paid path genuinely needs per-action perms.
`canonical_brand_id`, `logo_media_id`, `country_of_origin`, `website_url` are cheap nullable seams and fine to keep.

### N2. `PARAMS_PER_ROW 15→18` is more than a constant bump
`upsertProducts` hardcodes the value-clause template `($${offset+1} … $${offset+15}, datetime('now'), datetime('now'))` and the explicit `INSERT (…columns…)` + `ON CONFLICT … SET` lists (`productRepository.ts:118-169`). Adding 3 columns means rewriting all three, not just the constant. Flag so the implementer doesn't bump only the number and ship a placeholder/column mismatch.

### N3. Relation anchor differs from the cited precedent
`ParapharmacyProductMetadata::ingredients()` anchors on `metadata.product_id` (parentKey `product_id`); the new relations live on `Product` and anchor on `products.id`. Mechanics of `withPivot` transfer, but don't copy the precedent's `parentKey`/`relatedKey` wiring verbatim.

### N4. `pullCustomers` wiring needs tenant/company in `runFullSync` scope
`pullCustomers(db, tenantId, companyId)` requires both ids (`customerSyncService.ts:133`); `runFullSync` currently threads only `terminalId` and dynamically imports `authStore`. The "keep it minimal" note in §4 glosses the source of `tenantId`/`companyId` — specify it (read from `authStore`/`terminalStore`) so the orphan-fix regression test (§8) asserts the call with real args.

---

## Things the spec got right (no action)
- Shared `SkinType` placement is **legal** under rule 6 (shared kernel; Partner+Product+SmartPrompts all reference `Shared`, no cross-module model import). Same-values reuse is verified.
- Brand in `Product` (not `Catalog`) is correct given Catalog is structural-only and Product owns the aggregate + enrichment pipeline.
- The data-unconditional / experience-gated productization seam is a clean way to keep the demo simple while preserving the unbundle-via-config path.
- Fixing `'brand' => null` (`EnrichmentReviewService.php:80`) is a genuine latent-gap fix, not invented.
- `pullCustomers` orphan is real: only the definition exists, zero production callers, absent from `runFullSync`'s pull phase.
