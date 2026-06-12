# Media Subsystem Spec Review R2

- Round: 2 adversarial verification of revised specs
- Date: 2026-06-12
- Reviewer: Codex
- Verdict: REJECT

## RC-1

**[RESOLVED]** The no-backfill premise is now framed as owner-confirmed discard approval, with a pre-drop row-count guard/log as cheap insurance; I cannot independently verify the owner conversation from the repo.
Citation: architecture spec §2 lines 54-58; Stage 1 spec §2 lines 81-84.

## RC-2

**[PARTIALLY RESOLVED]** The major runtime consumers are enumerated, but the round-1 requirement explicitly included tests and seeders; the §5 table omits existing `ProductImage*Test` files and `ProductImagePlaceholderSeeder`.
Citation: Stage 1 spec §5 lines 143-154; grep result `apps/api/database/seeders/ProductImagePlaceholderSeeder.php:18-26`, `apps/api/tests/Feature/Product/ProductImageControllerTest.php:27`, `apps/api/tests/Feature/Product/ProductImageTenantIsolationTest.php:44`, unit tests under `apps/api/tests/Unit/Modules/Product/Application`.

## RC-3

**[RESOLVED]** The façade `{image}` segment is explicitly defined as `media_attachments.id`, with rationale and a retirement criterion tied to all callers moving to `/media`.
Citation: Stage 1 spec §7 lines 197-205.

## RC-4

**[PARTIALLY RESOLVED]** The spec adds a PG partial unique index with `COALESCE(channel,'')` / `COALESCE(locale,'')` and a PG-only test, but the index filters on `deleted_at` while `media_attachments` does not define `deleted_at`.
Citation: Stage 1 spec §2 lines 62-79 and §9 lines 222-225.

## RC-5

**[RESOLVED]** `GenerateRenditions` is specified to carry `tenantId`, use `App\Jobs\Concerns\BindsTenantContext`, tenant-scope every query, and follow the existing import-job pattern.
Citation: Stage 1 spec §6 lines 166-169; code pattern in `apps/api/app/Jobs/Concerns/BindsTenantContext.php:51-75` and `apps/api/app/Modules/Import/Application/Jobs/ProcessProductImageImport.php:53-69`.

## RC-6

**[PARTIALLY RESOLVED]** The main module-placement and Product→Catalog seam text is resolved, including controller-layer media composition, but the program staging list still says Stage 1 is "hexagonal, Media module."
Citation: architecture spec §3.3 lines 110-136 and §5 lines 174-179; Stage 1 spec §3-§4 lines 86-136; grep result `apps/api/src/Modules exists: no`, `apps/api/app/Modules/Catalog` exists.

## RC-7

**[PARTIALLY RESOLVED]** The Stage 1 spec fixes rendition uniqueness to include `format` and scopes JPEG fallback out, but the architecture spec still says `media_renditions` is unique on only `(asset,name)`.
Citation: Stage 1 spec §2 lines 49-60; architecture spec §3.1 lines 83-85.

## RC-8

**[PARTIALLY RESOLVED]** Stage 1 now says only `PRODUCT` attachments are created/served/tested and category/variant strings stay as-is, but the prior requirement also called out composite/attribute image strings; those existing strings are not explicitly scoped.
Citation: Stage 1 spec §1 lines 29-32 and §8 lines 207-214; grep result `apps/api/app/Modules/Catalog/Domain/Entities/CompositeItem.php` and `ProductAttributeValue.php` both expose `image_url`, while `CategoryData` exposes `image_url` from `image_path`.

## RC-9

**[RESOLVED]** Storage tenancy is now defined per disk: `s3` paths include tenant id explicitly, `public` honors Stancl suffixing, and `url` stores no bytes.
Citation: Stage 1 spec §6 lines 175-180; `apps/api/config/tenancy.php:109-116` confirms `local`/`public` are suffixed and `s3` is not.

## RC-10

**[RESOLVED]** Stage 1 adds image-only request/service MIME validation, rejects non-image types until later stages, validates external URLs, and keeps document preview behind a separate safe-serving allow-list.
Citation: Stage 1 spec §6 lines 162-171; architecture spec §3.5 lines 157-161 and §7 lines 218-220.

## NEW FINDINGS

1. **P1 - `media_attachments` index references a missing `deleted_at` column.** The data-model preamble says only `media_assets` have soft deletes, and the `media_attachments` column list has timestamps but no `deleted_at`; however the single-primary index uses `WHERE role = 'PRIMARY' AND deleted_at IS NULL`. A migration written from this spec will fail or force an undocumented schema change, and the delete-link policy is ambiguous about hard vs soft deletion of attachment rows. Recommendation: add soft deletes to `media_attachments` and state link deletion is soft, or remove `deleted_at` from the index and explicitly hard-delete links. Citation: Stage 1 spec §2 lines 37-38, 62-79, and §6 lines 181-186.

2. **P2 - Program spec still contradicts the Catalog ownership decision.** The revised module-placement section says the asset library is owned by `Catalog`, but the staged-delivery bullet still calls Stage 1 "hexagonal, Media module." Recommendation: change that bullet to `Catalog module` so the program and Stage 1 spec cannot drive different implementation plans. Citation: architecture spec §3.3 lines 110-136 and §5 lines 174-179.

3. **P2 - Program spec still contradicts the rendition uniqueness decision.** Stage 1 correctly defines uniqueness as `(media_asset_id,name,format)`, but the architecture spec still says unique `(asset,name)`. Recommendation: update the architecture spec to include `format` or explicitly defer the architecture statement to the Stage 1 schema. Citation: architecture spec §3.1 lines 83-85; Stage 1 spec §2 lines 49-60.

4. **P1 - Preserved authenticated route middleware is incomplete.** The Stage 1 spec lists the preserved image routes as using `['api','auth:sanctum',SetPermissionsTeam]`, `can:products.*`, and `throttle:image-upload`; the actual route group also inherits `EnforceTokenTenantClaim` and `module:Inventory`. Implementing only the listed middleware would weaken route tenancy/module gating. Recommendation: cite the full inherited route group middleware in §7 or say "preserve the existing route group exactly." Citation: Stage 1 spec §7 lines 190-195; actual routes `apps/api/app/Modules/Product/routes.php:42` and `119-142`.

## FINAL VERDICT

**REJECT** with 86% confidence.

Rationale: the revisions resolved several core design gaps, especially the façade ID, tenant-aware rendition job, storage tenancy rules, and security gates. However, 5 of the 10 required changes remain partially open, including one migration-blocking schema contradiction (`deleted_at`), stale module/rendition statements in the overarching spec, incomplete explicit consumer migration for tests/seeders, and incomplete scoping for existing composite/attribute image strings. These are spec edits, not implementation rewrites, but they should be corrected before converting the specs into a plan.
