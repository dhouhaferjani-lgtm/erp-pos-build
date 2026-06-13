# Media Subsystem Spec Review R3

- Round: 3 adversarial verification of revised specs
- Date: 2026-06-12
- Reviewer: Codex
- Prior reviews read in full:
  - `docs/superpowers/reviews/2026-06-12-media-subsystem-spec-codex-review.md`
  - `docs/superpowers/reviews/2026-06-12-media-subsystem-spec-codex-review-r2.md`
- Revised specs read in full:
  - `docs/superpowers/specs/2026-06-12-media-subsystem-architecture-design.md`
  - `docs/superpowers/specs/2026-06-12-catalog-media-backend-foundation-design.md`

Note: the prompt lists five numbered round-2 fixes but asks for 11 verification lines total. I split round-2 item 2 into two independently verifiable sub-items: architecture rendition uniqueness and staged-delivery module wording.

## Verification Items

1. **RESOLVED** - Stage 1 §2 now defines the single-primary index as `ON media_attachments (owner_type, owner_id, COALESCE(channel,''), COALESCE(locale,'')) WHERE role = 'PRIMARY'` and explicitly says "`media_attachments` has NO `deleted_at`" and "links are **hard-deleted**"; this removes the round-2 missing-column blocker.

2a. **RESOLVED** - Architecture §3.1 now says media renditions have `format` and are `Unique (`asset`,`name`,`format`)`, with the quote "format in the key so a future JPEG fallback can coexist with WebP per name."

2b. **RESOLVED** - Architecture §5 now names Stage 1 as "Catalog media backend foundation" and says "3 tables + enums (hexagonal, **Catalog module**)," so the stale "Media module" wording is gone.

3. **RESOLVED** - Stage 1 §5 now lists "**Demo seeder** | `apps/api/database/seeders/ProductImagePlaceholderSeeder.php` ... Rewrite to create `media_assets` ... + PRIMARY `media_attachments`" and "**Existing tests** | `ProductImageControllerTest.php`, `ProductImageTenantIsolationTest.php`, unit tests ... Migrate/replace"; the referenced seeder and tests still exist in the codebase.

4. **RESOLVED** - Stage 1 §7 now says to "**preserve the existing route group exactly**" and quotes the full inherited middleware as `['api','auth:sanctum',SetPermissionsTeam, EnforceTokenTenantClaim,'module:Inventory']`; this matches `apps/api/app/Modules/Product/routes.php` group middleware.

5. **RESOLVED** - Stage 1 §8 now says "Stage 1 creates, serves, seeds, and tests only `PRODUCT` attachments" and explicitly defers category `image_path`, variant `image_url`, composite-item `image_url`, and product-attribute-value `image_url` to Stage 3.

6a. **RESOLVED** - No-backfill provenance is still present: architecture §2 says "No backfill -- owner-confirmed (2026-06-12) there are no customers and no production data, only demo seed data," and Stage 1 intro repeats "no backfill -- owner-confirmed there is no production data."

6b. **RESOLVED** - The facade ID contract is still present: Stage 1 §7 says "the legacy `{image}` path segment maps to a **`media_attachments.id`** ... **not** `media_assets.id`."

6c. **RESOLVED** - GenerateRenditions tenancy is still addressed: Stage 1 §6 says "`GenerateRenditions` is tenant-aware," "carries `tenantId`, uses `App\Jobs\Concerns\BindsTenantContext`, and tenant-scopes every query"; §9 also requires a tenancy test.

6d. **RESOLVED** - Storage-tenancy path rules are still present: Stage 1 §6 says uploaded assets use `s3` path `products/{tenant_id}/{product_id}/{asset_uuid}/{original|rendition}.{ext}`, `public` honors Stancl suffixing, and `url` stores no bytes; this matches `apps/api/config/tenancy.php`, where `local` and `public` are suffixed and `s3` is not.

6e. **RESOLVED** - The image-only ingestion allow-list is still present: Stage 1 §6 says "image-only allow-list this stage -- request + service MIME validation, parity with current jpeg/png/webp/gif" and "non-image types are model-supported but **rejected at ingestion** until later stages"; this matches the current `ProductImageService::ALLOWED_MIME_TYPES`.

## Codebase Cross-Checks

- Current product image routes inherit `api`, `auth:sanctum`, `SetPermissionsTeam`, `EnforceTokenTenantClaim`, and `module:Inventory`, with per-route `can:products.*` and `throttle:image-upload` on store (`apps/api/app/Modules/Product/routes.php:42`, `119-142`).
- Existing consumers remain real code paths: authenticated/product image APIs, public storefront images, POS sync image URLs, ZIP product-image import, generated shared TS types, POS SQLite image cache, demo seeder, and ProductImage tests.
- Current image variants are WebP-only `sm` and `md` via `ImageVariantService::VARIANTS` and `imagewebp()`, so any JPEG fallback wording must be treated as future work rather than parity with current behavior.

## NEW FINDINGS

1. **P3 - Architecture §3.2 still says "WebP + JPEG fallback" and "exactly as the current `GenerateImageVariants` job does," but Stage 1 §2 explicitly says "JPEG fallback is explicitly out of scope this stage," and the current code is WebP-only (`ImageVariantService::VARIANTS` = `sm`/`md`, `resizeAndEncode()` calls `imagewebp()`, and `GenerateImageVariants` writes those WebP variants). Recommendation: change architecture §3.2 to "WebP renditions; JPEG fallback deferred" or remove "exactly as current" from that sentence.

2. **P3 - Architecture §3.3 says the Catalog module owns "categories," but the actual category model, DTO, and controller are in the Product module (`App\Modules\Product\Domain\Category`, `CategoryData`, `CategoryController`); only variants, attributes, and composite items are Catalog-owned. Because category media is deferred to Stage 3, this is not a Stage 1 blocker, but the architecture rationale should say category media will cross the Product/Catalog boundary or move categories deliberately.**

## FINAL VERDICT

**ACCEPT-WITH-MINOR-EDITS** with 92% confidence.

Rationale: all requested round-2 fixes are now present in the specs, including the hard-delete single-primary index, Catalog module wording, consumer table updates, exact route middleware, and explicit owner-type deferrals. The preserved round-1 safeguards also remain intact: no-backfill provenance, facade ID mapping, tenant-aware rendition jobs, storage tenancy rules, and image-only ingestion gates. The remaining issues are specification consistency problems, not blockers to Stage 1 architecture: JPEG fallback wording in the architecture spec contradicts Stage 1 and current code, and the category ownership rationale is inaccurate against the existing module layout. Clean those before converting the design into an implementation plan, but another rejection round is not warranted.
