# L6 media/onboarding lane — adversarial authz + backend merge gate

- **Branch:** `fix/client-bugs-media-onboarding` (9 commits) vs `origin/dev @ fe0df479e`
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.fix-l6-media`
- **Spec:** `docs/bug-reports/2026-08-05-client-bugs.md` BUG-005 (A1/A2/B1/B2/B3), lines 20-69
- **Date:** 2026-08-06 · Reviewer: tenancy-authz-reviewer (gate only, no merge, no modification)

## VERDICT: spec ✅ + quality CHANGES-REQUESTED

All five acceptance criteria are implemented and covered by tests that assert real behaviour.
No Critical defect found: no cross-tenant leak, no auth bypass, no privesc.
Four Important items must be closed before merge; one must be ticketed.

## Suites run (by path, live DB, this worktree)

| Suite | Result |
|---|---|
| `tests/Feature/Http/` (ApiErrorEnvelope, TrustedProxy) | 4 passed |
| `tests/Feature/Modules/Catalog/Media/` | 130 passed, 1 skipped |
| `tests/Feature/Modules/Product/ProductDataMediaParityTest.php` + `tests/Feature/Tenant/` | 105 passed, 30 skipped, **1 failed** (`TenantCreationTest::test_tenant_get_database_name`) |
| `tests/Feature/Identity/UserManagement/CreateUserTest.php` | 18 passed, **1 failed** (`store_creates_null_membership_for_new_staff`) |
| `tests/Feature/Treasury/PaymentTest.php --filter=supplier` (HttpResponseException 422 paths) | 8 passed |
| `tests/Feature/Treasury/DeferredTenderGuardsTest.php` | 10 passed |
| PHPStan L8 on the 5 changed API files | OK, 0 errors |
| web vitest (5 touched files) | 19 passed |

Both failures are causally unrelated to the diff (tenant DB-name assertion; membership-role mapping) — claim 6 accepted.

## Findings

### [Important] `bootstrap/app.php:414-434` — catch-all is broader than its own comment, and neither invariant is pinned by a test
The comment claims only `HttpExceptionInterface` needs excluding. Laravel matches render callbacks
(`vendor/.../Foundation/Exceptions/Handler.php:618`) **before** the `match` that handles
`HttpResponseException` / `AuthenticationException` / `ValidationException` (`:622-627`).
- `AuthenticationException` and `ValidationException` are saved only because callbacks at
  `bootstrap/app.php:165` and `:219` are registered earlier. Fragile but currently correct.
- `HttpResponseException` (`vendor/.../Http/Exceptions/HttpResponseException.php:9` — plain `RuntimeException`,
  NOT `HttpExceptionInterface`) IS matched by the new callback. Direct handler probe:
  `$handler->render($req, new HttpResponseException(response()->json(...,403)))` → **500 `INTERNAL_ERROR`, empty message**.
- **Not a live regression**: `Illuminate\Routing\Route::run()` catches `HttpResponseException` thrown inside a
  route action (`vendor/.../Routing/Route.php:215-217`), so all 9 in-app throw sites (CreateUserRequest.php:71,
  UpdateUserRequest.php:79, StoreStockTransferRequest.php:47, PaymentAllocationService.php:617,
  PaymentController.php:812/979/1262, MultiPaymentController.php:40, ReturnNoteController.php:132,
  SupplierDeliveryNoteCommitter.php:161, SupplierInvoiceCommitter.php:271) still return their own status —
  empirically confirmed by PaymentTest (8 green) and DeferredTenderGuardsTest (10 green).
- Residual: a `HttpResponseException` thrown from **middleware** (outside `Route::run`) would silently become a 500.
- `ApiErrorEnvelopeTest.php` has **no** test that an unknown `api/*` route still 404s and **no** test that a
  controller-thrown `HttpResponseException` keeps its status — the two invariants the comment asserts.

**Fix:** add `|| $e instanceof \Illuminate\Http\Exceptions\HttpResponseException` to the skip guard, and add the two
regression tests (unknown `api/*` route → 404 with a body; a probe route throwing `HttpResponseException(403)` → 403).

### [Important] `bootstrap/app.php:59` — `trustProxies(at: '*')` also trusts `X-Forwarded-Host` and `X-Forwarded-Prefix`
`trustProxies()` with no `$headers` argument keeps the framework default
(`vendor/.../Http/Middleware/TrustProxies.php:22-27`): FOR | **HOST** | PORT | PROTO | **PREFIX** | AWS_ELB.
Their own `TrustedProxyTest.php:44` asserts `X-Forwarded-Host` is honoured — i.e. the request host becomes
attacker-controlled for anything that can reach the container.
- The "only the proxy can reach it" premise is verified only for **external** traffic: `docker-compose.staging.yml:177-200`
  publishes no `ports:` for `api`. It is NOT verified for **co-resident containers** — Dokploy places apps on a shared
  host network; anything else on that host can `curl api:80` and set `X-Forwarded-*`. Cannot verify the AX42 network
  layout from this repo (no `dokploy-network` reference in-tree).
- Blast radius today is small: reset/invite links come from `config('app.frontend_url')`
  (`ResetPasswordNotification.php:48`, `UserInvitation.php:66`), not the request host; there is no absolute `signed`
  route (`Product/routes.php:176` is `signed:relative`).
- Residual if `:80` is ever published on AX42: host poisoning of every `url()`/`route()`/`asset()`, poisoned absolute
  URLs in logs/Sentry, and `X-Forwarded-For` spoofing that defeats every per-IP `RateLimiter::by($request->ip())`
  (incl. `signed-media`, `AppServiceProvider.php:336-338`, and login throttles).

**Ruling:** `at: '*'` is acceptable ONLY with the header set narrowed to what A1 needs. Change to
`trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT)`
and adjust `TrustedProxyTest` to assert `isSecure()` only. Do NOT pin a CIDR (Dokploy bridge subnets are ephemeral) —
narrow the headers instead. Add `expose`/no-`ports` as an explicit deploy invariant in the prod-env checklist.

### [Important] Signed-URL-per-row: cache-busting + per-IP throttle, not a tenancy leak
`CatalogMediaQuery.php:66-72` now mints one `URL::temporarySignedRoute(..., absolute: false)` per product row on the
**list** endpoint (`ProductController.php:143`). Tenancy verdict first:
- **Cannot cross tenants.** The tenant UUID is inside the signed path (`MediaUrlResolver.php:69-72`), the HMAC covers
  path+query, and `SignedMediaController.php:149-165` additionally scopes `attachment.tenant_id = {tenant}` and
  `whereHas(mediaAsset.tenant_id = {tenant})`. An attachment id from tenant A under tenant B's segment cannot be signed
  by the app (the tenant segment is read off the attachment row) and would 404 even with a valid signature. Verified by
  `SignedMediaServeTest` (cross-tenant → 404, tampered/expired → 403).
- **Real exposure delta:** a bearer-token holder can now harvest N unauthenticated 60-min URLs per list page and share
  them outside the tenant. Scope is product hero images only (`primary_image_url` is minted for `role = Primary`),
  not scan/PDF documents. Same-origin `<img>` + `Referrer-Policy: strict-origin-when-cross-origin`
  (`apps/web/nginx.conf.template:27`) means no referer leak. **Accepted risk.**
- **Operational defect (this is the real problem):** the signature+`expires` change on every response, so every refetch
  produces a NEW URL → the browser image cache is invalidated wholesale. `usePOSProducts.ts:20` has `staleTime: 60000`,
  and `apps/web/src/features/pos/api/productApi.ts:42` maps `primary_image_url → image_url` rendered per card
  (`features/pos/molecules/ProductCard/ProductCard.tsx:102`); `components/molecules/line-items/ProductCell.tsx:37`
  renders one per document line. `MediaStorageAdapter.php:78` returns `Storage::disk()->response($path)` with **no**
  `Cache-Control`. Against `throttle:signed-media` = 120/min **per IP** (`AppServiceProvider.php:337`), a 60-100 product
  grid re-downloaded each minute, shared across terminals behind one NAT, will trip 429 → images break intermittently.

**Fix (pick 1+2):** (1) bucket the expiry so the URL is byte-stable within a window
(`now()->startOfHour()->addHours(2)` instead of `now()->addMinutes(60)`) → browser-cacheable, dedupable;
(2) send `Cache-Control: private, max-age=3600, immutable` from `MediaStorageAdapter::serve`;
(3) re-key `signed-media` to ip+tenant and raise the ceiling.

### [Important] A2 removes the only filter that hid assets with missing/corrupt original bytes
`MediaUploadService.php:155` now creates every asset READY and `GenerateRenditions.php:88-107` no longer calls
`markFailed`. `SignedMediaController.php:177` still requires READY, so the status filter is now inert, and
`MediaStorageAdapter::serve` (`:78`) streams `Storage::disk($disk)->response($path)` with **no `exists()` check**.
An asset whose original object never landed (partial S3/MinIO put) previously 404'd via the FAILED/UPLOADED filter;
it now produces a metadata error / truncated stream → 500 + Sentry noise instead of a clean 404.
Also: `markProcessing`/`markFailed` (`EloquentMediaAssetRepository.php:30,42`) now have zero production callers, and
operators lose the FAILED diagnostic entirely (only Horizon `failed_jobs` remains).
Readers that whitelist PROCESSING (`EnrichmentImagePolicy.php:52`, `EnrichmentImagePersister.php:288`) use
`whereIn([...Uploaded, Processing, Ready])`, so they are unaffected — verified.

**Fix:** add `if (! Storage::disk($disk)->exists($path)) abort(404);` in `MediaStorageAdapter::serve` (or a 404 fallback
in the controller), and state in the A2 comment that FAILED is now a legacy-only status.

### [Important — TICKET IN LANE] POS product images are dead independently of this lane
`apps/pos/src/lib/sync/syncService.ts:736` pulls `GET /products`; the rows are cast to `POSProduct`
(`apps/pos/src/types/product.ts:57` → `image_url?: string`) and persisted via
`productRepository.ts:182` (`p.image_url ?? null`). The API payload is `ProductData`
(`ProductController.php:143-156`), whose fields are `primary_image_url` and `media[]`
(`ProductData.php:57-58`) — **there is no `image_url` key**. So `p.image_url` is always `undefined`,
`product_images` is never populated, and `enqueueDownload` is never fed. POS grid images have been dead.
Their premise-correction is CONFIRMED, including that the POS cache key is `product_id`
(`apps/pos/src/lib/images/imageCache.ts:56`, `:190-195`), not the URL — so the URL-shape freeze is a weaker
constraint than assumed. **Requires a ticket in this lane before merge** (fix belongs in a POS release, not here).

### [Minor] `media[].url` is byte-unchanged only in the test environment
`forPosSync` uses `route(...)` = **absolute** (`MediaUrlResolver.php:130`). With `trustProxies` now honouring
`X-Forwarded-Proto`, the same payload changes `http://…` → `https://…` in staging/prod. Harmless (cache key is
product_id; no re-download storm; POS images dead anyway) but the regression test cannot see it — say so in the
commit message so nobody treats "byte-unchanged" as a prod guarantee.

### [Minor] `degraded` is emitted and typed but never surfaced
`OnboardingChecklistService.php` emits `degraded`; `onboardingApi.ts:9-14` types it as **required**. No UI renders it —
a degraded step is indistinguishable from an incomplete one, so a user can be told to redo a step they already did.
Also, a FE deploy ahead of the API would make the required field a type lie. Either render a badge or mark it optional.

### [Minor] 404/405 on `api/*` still return Laravel's default `{"message": …}`
Deliberate and documented (`bootstrap/app.php:410-412`), and `getErrorMessage` (`apps/web/src/lib/api.ts:80-95`) now
falls back to `data.message`, so the SPA copes. But B3's stated goal ("every `api/*` failure carries the same envelope")
is not literally met. Accept, or add a `Route::fallback` for `api/*`.

### [Minor] `productHeroImageSrc` is now an identity function
`apps/web/src/features/products/productHeroImage.ts:16-20` only null-normalises. Fine to keep for the doc comment,
but it earns its own test file (`productHeroImage.test.ts`) for two lines of logic. No action required.

## Verified premises (no defect)

- SPA is same-origin: `apps/web/src/lib/api.ts:148` (`baseURL: '/api/v1'`) + `apps/web/nginx.conf.template:34-47`
  (`location /api/ { proxy_pass ${BACKEND_URL}; }`). A **relative** signed URL therefore resolves correctly even though
  `VITE_API_URL` points at a different host (`docker-compose.staging.yml` web build args).
- B2 premise: `app/Http/Middleware/CompanyContextMiddleware.php:49-52` skips non-`User` principals — the controller can
  be reached with an empty context; the new typed 403 (`OnboardingController.php:36-45`) closes it.
- Route middleware (rule 12): `app/Modules/Tenant/routes.php:20` =
  `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]`, `can:settings.view` on the
  onboarding route (`:36`). No new permission introduced → no seeder sync needed for this lane.
- `messages.server_error` exists in `lang/en/messages.php:22` and `lang/fr/messages.php:22`.
  `common:actions.retry` exists in both `common.json`.
- `uuid_create()` in the new renderer is safe: `symfony/polyfill-uuid` is installed (ext-uuid is absent locally and the
  Http suite still passes).
- Claim 7 (stash): `git stash list` still shows `stash@{0}: WIP on dev: ec174e55 feat(pos+treasury): payment tolerance
  v2 …`, and `git diff fe0df479e..HEAD | grep -ciE 'tolerance|short_pay|shortpay'` = **0**. Branch is clean.

## Ruling — stuck-assets backfill (their open item 2)

**Directive: ship (a) as a self-guarding tenant migration, with the narrowed predicate (c). Do NOT auto-promote FAILED.**

1. Add `apps/api/database/migrations/tenant/<ts>_backfill_ready_image_media_assets.php` — precedent:
   `2026_07_16_100000_backfill_user_company_memberships.php`, and `media_assets` is a tenant table
   (`database/migrations/tenant/2026_06_12_100001_create_media_assets_table.php`), so `tenants:migrate` on the
   `origin/dev` staging deploy is the right vehicle.
2. Predicate — **UPLOADED and PROCESSING only**:
   `UPDATE media_assets SET status = 'ready', updated_at = now() WHERE type = 'image' AND status IN ('uploaded','processing');`
   Guard with `Schema::hasTable('media_assets')` so a tenant behind on its migration lane no-ops. `down()` = no-op
   (never demote). Idempotent, one indexed UPDATE.
3. **FAILED stays out.** `markFailed` fired when `RenditionService::generate()` threw — which includes "the original
   object is missing/unreadable". Promoting those turns a clean 404 into a streamed 500 (see the `exists()` finding).
   Handle them with a separate tenant-scoped artisan command that checks
   `Storage::disk($asset->storage_disk)->exists($asset->storage_path)` before promoting, and prints a per-tenant report.
   Run it manually after the deploy; it is not a migration.
4. Land the `exists()` guard in `MediaStorageAdapter::serve` in the SAME commit as the migration — the backfill widens
   the window where a READY asset can have absent bytes.
5. Runbook line for the deploy: after `tenants:migrate`, no `permission:cache-reset` is needed (no new permission), but
   re-verify the Horizon `images` supervisor is consuming (spec §A2 item 5) — the fix removes the hard dependency, not
   the need for renditions.

## What to fix before merge

Skip `HttpResponseException` in the catch-all + add the 404/HttpResponseException regression tests; narrow
`trustProxies` to FOR|PROTO|PORT; add the `exists()` guard in `MediaStorageAdapter::serve`; stabilise the signed-URL
expiry bucket (or raise/re-key `signed-media`); open the POS `image_url` ticket. Then land the backfill per the ruling.
