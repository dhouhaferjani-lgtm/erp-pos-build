# Adversarial review — Enrichment H-A/H-B implementation plan (2026-07-03)

**Plan:** `docs/superpowers/plans/2026-07-03-enrichment-ha-hb.md`
**Reviewed against:** H-A/H-B handovers, spec §3/§4.1/§4.2, and code at `origin/dev` 275bd2573.
**Verdict:** Plan is well-researched (nearly every file:line claim verified), but it has **3 BLOCKERs** — two missing-DTO gaps that produce runtime 500s / a dead feature, and one locked design decision that is infeasible against the actual pivot schema — plus **3 MAJORs** including a polling placement that defeats the H-B fast-path requirement.

---

## BLOCKERS

### B1. Nullable `tracking_id` will 500 the enrichment review endpoints — plan misses `EnrichmentResultData`
`apps/api/app/Modules/Product/Application/DTOs/EnrichmentResultData.php:22` declares `public string $tracking_id` (non-nullable, `#[TypeScript]`). `EnrichmentReviewController::index`/`show` construct this DTO from every row (`EnrichmentReviewController.php:70`). Task 3 creates rows with `tracking_id = null`; the first catalog-accepted row makes `GET /api/v1/enrichment-results` (and `show`) throw `TypeError: Cannot assign null to property ... $tracking_id` → 500 on the whole review queue. Task 2 only touches the **model** PHPDoc (`EnrichmentResult.php:19`) — the Data DTO and its generated TS type are never updated.
**Fix direction:** Task 2 must also change `EnrichmentResultData::$tracking_id` to `?string` + regenerate types; add a listing test that includes a null-tracking row.

### B2. Task 13's enable gate reads a field the API never returns — the fast-path hook can never turn on
The hook is keyed on `product.enrichment_status === 'pending'`, but `ProductData` (`apps/api/app/Modules/Product/Application/DTOs/ProductData.php:22-58`) exposes **no `enrichment_status`** (and no `platform_product_id`); grep of `packages/shared/types/generated.d.ts` confirms no `enrichment_status` reaches the FE, and no FE Product type carries it. No plan task adds the field. As planned, `enabled` is always false and Tasks 12–13 ship dead code — which the planned tests won't catch because they mock the product prop directly.
**Fix direction:** add a task: expose `enrichment_status` (and ideally `platform_product_id`) on `ProductData::fromModel` + `typescript:transform`; add an integration test that drives the gate from a real product API response shape.

### B3. Locked decision 3's `syncWithoutDetaching` violates `product_ingredient.id` NOT NULL
`database/migrations/tenant/2026_01_08_174850_create_product_ingredient_table.php` gives the pivot a `uuid('id')->primary()` with **no default**, and `ParapharmacyProductMetadata::ingredients()` (`ParapharmacyProductMetadata.php:118-130`) is a plain `belongsToMany` with **no `->using()` pivot model** — Eloquent `attach`/`syncWithoutDetaching` inserts only `(product_id, ingredient_id, timestamps)`, so the insert fails with a NOT NULL violation on `id`. Every existing writer supplies the id manually (`database/seeders/ParapharmacySeeder.php:1011-1012` — `DB::table('product_ingredient')->insert(['id' => Str::uuid() ...])`).
**Fix direction:** either attach with an explicit pivot `id` per row (`syncWithoutDetaching([$ingredientId => ['id' => Str::uuid()->toString(), 'order' => $pos]])`), add a `->using()` pivot model with `HasUuids`, or mirror the seeder's manual insert. The TDD test would catch this, but the "locked" decision as written prescribes a broken mechanism.

---

## MAJOR

### M1. Fast-path polling is mounted where the user never lands after submit
Locked decision 7 claims "after create the form navigates to the record" so edit-mode polling "covers the post-submit landing". Post-create navigation is `nav.goToRecord(created.id)` → `/inventory/products/{id}` (`ProductForm.tsx:135-136,502`), and that route renders **`ProductDetailPage`**, not ProductForm — ProductForm only mounts on `/products/:id/edit` (`apps/web/src/routes/index.tsx:888-902`). The ~45s fast-path (H-B req 5: "after a successful submit, poll … inline card") will effectively never run unless the user separately clicks Edit. The premise of the locked decision is false against the actual routes.
**Fix direction:** mount the hook/card on ProductDetailPage (the actual landing) — or on both; keep the shared hook.

### M2. Apply-now card blanket-accepts `['name','brand','description','barcode']` — nulls can wipe real product data
`EnrichmentReviewService::accept()` maps `'barcode' => $productUpdates['barcode'] = $enrichedData->assigned_barcode` (`EnrichmentReviewService.php:99`) and `'description' => $enrichedData->description` (line 98) with no null guard. For an H-B submission whose result carries no `assigned_barcode` (product already had a barcode) or no description, the Task 13 card's hardcoded four-field accept **overwrites the product's barcode/description with NULL**. (The existing `bulkAcceptEnrichmentResults` at `apps/web/src/features/enrichment/api/enrichmentApi.ts:26-28` shares this latent bug, but Task 13 makes it a one-click mainline path.)
**Fix direction:** accept only fields that are non-null in `enriched_data` (compute the field list from the result), and pin with a test: result with `assigned_barcode=null` → product barcode unchanged.

### M3. Stale-FOUND window persists a wrong backlink; the job guard doesn't fix it — and the plan's reset claim is false
Task 5 Step 2 asserts "State resets to idle on barcode edit via handleLookupStateChange — stale-match safety is already handled." Wrong: `useCatalogBarcodeLookup.ts:53-71` only sets state toward idle when the barcode drops **below 8 chars**; editing barcode A(found)→B (≥8 chars) leaves `lookupState==='found'` and `suggestedProductRef` holding A's product through the 300 ms debounce + query in-flight window (`suggestedProductRef` is cleared only on `'idle'`, `ProductForm.tsx:239-245`). A submit in that window sends A's `platform_product_id` with B's barcode. Task 4's job mismatch-guard skips the **enrichment apply**, but Task 1 already **persisted the wrong `products.platform_product_id`** in `store()` — nothing corrects it, so a permanently wrong catalog backlink survives with no signal.
**Fix direction:** either have the job clear/write-back `platform_product_id` on mismatch, or make the FE only trust the suggestion when `suggestedProductRef.current?.barcode` equals the submitted `data.barcode`; pin with a test.

---

## MINOR

1. **Polling permission gap:** `POST /products/{id}/enrichment/refresh` sits in the `can:enrichment.view` group (`apps/api/app/Modules/Product/routes.php:125-133`). Task 13 permission-gates only the Apply button (`enrichment.review`); a user with `products.update` but not `enrichment.view` editing a pending product gets five 403s per visit. Gate the hook's `enabled` on `enrichment.view` too.
2. **Hook error path unspecified/untested:** Task 13's tests cover pending/completed/terminal/unmount but not refresh returning HTTP errors (403, 422 `no_pending_submission` — `EnrichmentRefreshController.php:35-39` — or 502). An error-blind implementation keeps polling or crashes; add an error-tick test (treat as terminal).
3. **Result selection race:** the ready-path fetch `getEnrichmentResults({ product_id })` has no `status` filter; a product with an older rejected/accepted result can surface the wrong row. Pass `status: 'pending_review'` (the endpoint already supports it, `EnrichmentReviewController.php:49-51`).
4. **Each poll tick is a platform API call:** refresh proxies to `checkStatus` (`ManualEnrichmentRefreshService.php:41`), so the 5-tick fast path costs 5 platform `lookup-status` calls per pending-product visit against partner quota. Worth a note/dedupe (e.g. skip refresh if one is in flight; the spec's original design polled the platform from the ERP server anyway).
5. **Wrong provider pointer in Task 3:** `PlatformSubmissionInterface` is bound in `app/Providers/AppServiceProvider.php:93` (with the other Shared contracts, `:95`), not in `PlatformIntegrationServiceProvider`. "Mirror the existing binding" resolves to AppServiceProvider — say so, or the worker adds a divergent binding location.
6. **Task 4 snippet contradicts its own guard:** the code passes `$company->tenant->vertical->platformVertical() ?? ''` while the prose says "skip dispatch when platformVertical() is null". A literal reading dispatches with `''` and the job then calls `lookup($barcode, '')`. Delete the `?? ''`; also add the missing negative test (manual create / null vertical → `Queue::assertNothingPushed`).
7. **Ingredient creation values unpinned:** `IngredientController::store` requires `is_allergen` + `regulatory_status` at validation (`IngredientController.php:94-96`), but the DB defaults are `false`/nullable (`2026_01_08_174603_create_ingredients_table.php`). Task 3 says "mirror the controller" without stating what the enrichment path supplies — pin it (defaults) so PHPStan/DB and the test agree.
8. **Task 2 `down()` is not runnable** once null-tracking rows exist (`nullable(false)->change()` fails on existing NULLs). Acceptable, but note it or delete-nulls-first in `down()`.
9. **`CatalogProductDTO` drops carried provenance:** `PlatformProductData` carries `confidenceScore` + `enrichmentTier` (`PlatformProductData.php:30-31`); the plan's DTO omits both and hardcodes `confidence_score: 0` "unless carried" — it IS carried. Map them so the catalog-accepted `enriched_data` isn't degraded.
10. **H-B CORS gotcha has no home:** the handover explicitly flags the presigned PUT cross-origin/MinIO check; no plan task or manual verification step mentions it. Add a step-note in Task 10 (even if e2e is out of scope).

## NIT

- Task 7's "bulk import path constructs the DTO" — `ProductSubmissionData` is constructed in exactly two places: `ProductSubmissionController.php:66` and `tests/Unit/Modules/PlatformIntegration/ProductSubmissionServiceTest.php:54`. No bulk-import construction site exists (`bulkSubmit` has zero app callers). The caution is harmless but misdirected.
- Task 10 caps 2 photos client-side while Task 7 allows `photo_ids max:5` server-side — intentional (platform ceiling) but worth one comment so nobody "fixes" the mismatch.
- Task 11's 422 `invalid_barcode` from the new submit path is never surfaced to the user (only the 409 is). Mostly shielded because an unnormalizable barcode yields lookup `error` (not `not_found`) so the branch doesn't fire — but the raw form value can still diverge (e.g. barcode edited after lookup). One onError line covers it.

---

## Verified spot-checks (claims that held)

- **Cache/tenancy stress test HOLDS:** `CacheTenancyBootstrapper` active (`config/tenancy.php:40`) tags every `Cache::` call with `tenant{id}`; `QueueTenancyBootstrapper` (`:42`) re-initializes the same tenant in the worker, so the job sees the web request's `platform:lookup:{vertical}:{barcode}` entry (`BarcodeLookupService.php:16,42,73`) — "cache hit is the normal case" is correct, and tenant tagging prevents cross-tenant `tracking_id` leakage from the cached not_found path. Test store `CACHE_STORE=array` (phpunit.xml:40) supports tags.
- **Migration feasible:** `enrichment_results.tracking_id` is NOT NULL + unique `idx_enrichment_results_tracking` (`2026_03_28_100001...php:18,31`); Laravel 12 native `->change()` on PG emits `ALTER COLUMN ... DROP NOT NULL` without touching the separate unique index; PG default NULLS DISTINCT allows many null rows. (`fetchAndStore`'s `updateOrCreate(['tracking_id' => $trackingId])` always receives a non-null string — unaffected.)
- **Category-only metadata create works:** `parapharmacy_product_metadata` NOT NULL columns are only `id`/`product_id`/`category` (`2026_01_05_105259...php:16-22`); `requires_consultation` defaults false; model uses `HasUuids`. `ParapharmacyCategory::Other` exists (enum line 18).
- **Task 1 mechanics verified:** `CreateProductRequest::rules()` (161-283) has no `platform_product_id`; `Product::$fillable` includes it (`Product.php:120`); `store()` spreads `$validated` into `Product::create` (`ProductController.php:418-421`); the unsets (365-379, 394) never touch `platform_product_id`/`barcode`, and `$validated` remains in scope after the transaction (line 512) for the Task 4 dispatch. `Vertical::platformVertical()` exists (`app/Enums/Vertical.php:153`, `'parapharmacy'` mapped). No unique constraint on `products.platform_product_id` (only `platform_submission_id`, `2026_05_08_000001`).
- **Refresh endpoint matches FE assumptions:** `can:enrichment.view` (`Product/routes.php:125,131`), response `{data:{enrichment_status}}` (`EnrichmentRefreshController.php:49-51`) = FE `EnrichmentRefreshResult` (`platformApi.ts:21-27`); `getEnrichmentResults` passes `params` through (`enrichmentApi.ts:4-11`); `acceptEnrichmentResult(id, fields)` matches.
- **Submission controller/DTO claims verified:** brand `required` (`ProductSubmissionController.php:38`), raw barcode + `photoIds: []` hardcoded (67-74), `ProductSubmissionData::$brand` non-null (`ProductSubmissionData.php:19`); widening to `?string` breaks no construction site. `requestUploadUrl`/`uploadPhoto` exist unrouted (`ProductSubmissionService.php:26-53`; `PlatformIntegration/routes.php` has no upload-url; group middleware `['api','auth:sanctum',SetPermissionsTeam,EnforceTokenTenantClaim]` at :23 satisfies rule 12).
- **Correlation claims verified:** `correlateSubmission` catches unique violation → generic `EnrichmentCorrelationConflictException` (`ProductEnrichmentCorrelationService.php:65-73`); Task 8's pre-check via a new Shared contract method is consistent with the module boundary; re-submit-same-product path safe (same-row update, no violation); Task 8's honest hedging on the already-pending 409 matches `assertSubmittable` (`:33-44`).
- **Horizon rule 20 satisfied:** `enrichment` in `config/horizon.php:209`; existing `ProcessEnrichmentWebhookJob` dispatch precedent at `EnrichmentWebhookController.php:62`.
- **FE map verified:** `handleProductData` prefills name/description/barcode only (`ProductForm.tsx:222-237`), `suggestedProductRef` (:156, typed `SuggestedProduct` with `platform_product_id`), not_found opt-in block (:695-708, outside the `<form>` at :711 — panel placement is safe), onSubmit not_found branch with the `?? ''` brand bug (:483-499, bug at :488), create mutation (:375-388).
- **Barcode normalizer extraction sound:** private logic at `BarcodeLookupService.php:98-140` matches the plan's vectors ('3017620422003' valid EAN-13; UPC-A '036000291452'→'0036000291452' keeps a valid check digit; EAN-8/alphanumeric pass through; symbols-only → null).
