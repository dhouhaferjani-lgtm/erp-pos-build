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

---

# Round 2 — narrow re-verification (6 commits, `6aaaa0fa3..02893a626`)

## VERDICT: CLEAR TO MERGE (authz half). No blockers.

All four Important items and both Minors from Round 1 are closed. No new defect found.

## Correction to MY ruling — accepted, and it was a real one

`MediaStatus` and `MediaAssetType` are UPPERCASE-backed enums
(`MediaStatus.php:9-12` → `'UPLOADED'/'PROCESSING'/'READY'/'FAILED'`;
`MediaAssetType.php:9-13` → `'IMAGE'/…`). The literal SQL in my Round-1 directive
(`status='ready' … type='image'`) would have matched **zero rows** and silently no-op'd on every tenant —
a backfill that reports success and fixes nothing. Their enum-constant version
(`2026_08_06_100000_backfill_ready_image_media_assets.php:52-62`) is correct, and
`BackfillReadyImageAssetsTest::test_migration_promotes_uploaded_and_processing_images_but_never_failed`
pins it. My error; their catch. Reviewers writing literal SQL for enum columns must read the enum first.

### Other silent-no-op paths in the migration — checked, none blocking
- `Schema::hasTable('media_assets')` (`:47`) is the right guard; the table is a tenant migration
  (`database/migrations/tenant/2026_06_12_100001_create_media_assets_table.php`), so central no-ops correctly.
- `updated_at` exists (`timestamps()` in the create migration) — a missing column would have thrown, not no-op'd.
- Timestamp `2026_08_06_100000` sorts after every create-table migration ✓.
- No `tenant_id` predicate: correct under db-per-tenant (physical isolation). Under a legacy single-schema
  deployment it would promote all tenants in one statement — same intent, no cross-tenant data movement.
- ExternalUrl assets stuck at UPLOADED are also promoted; harmless, `MediaStorageAdapter::serve` redirects on
  `source === ExternalUrl` before any `exists()` check.
- **[Minor, non-blocking]** `DB::table()` bypasses SoftDeletes, so soft-deleted assets are promoted too and the
  logged row count is inflated. No read path surfaces them. The command does it right (`MediaAsset::query()`).

## Item-by-item

**1. Catch-all `HttpResponseException` skip — closed.** `bootstrap/app.php:443` now skips both families; the comment
documents *why* (`Route::run()` cannot catch a middleware-thrown one). Three tests green, including the
handler-direct probe (`test_http_response_exception_is_not_rewritten_by_the_exception_handler`) — exactly the probe
that was red at 500 in Round 1 — plus the unknown-route 404 guard.

**2. trustProxies narrowing — closed, and the one path that could have been Critical is disproved.**
`bootstrap/app.php:68-70` = `FOR | PROTO | PORT`; `test_forwarded_host_is_not_trusted` green
(`attacker.example.com` no longer becomes the request host). I then chased whether keeping
`HEADER_X_FORWARDED_FOR` under `at: '*'` makes `$request->ip()` client-controlled — which would have defeated
every per-IP limiter including `login:ip:` (`AppServiceProvider.php:258-268`) and been a brute-force bypass.
**It does not:** Laravel maps `'*'` to "trust the calling IP only" (`vendor/.../TrustProxies.php:83-85, 120-123`),
and with a single trusted hop Symfony returns the entry the trusted proxy appended. Probe with
`REMOTE_ADDR=10.0.0.5`, `X-Forwarded-For: 203.0.113.9, 198.51.100.7` → `ip() = 198.51.100.7` (proxy-appended);
the client-supplied leftmost value is ignored. Per-IP rate limiting is sound. Keeping FOR is correct.

**3. Signed-URL stability + serve hardening — closed.**
- Bucketing (`MediaUrlResolver.php:56, 100`): `now()->startOfHour()->addHours(2)` → byte-identical URL within an
  hour, remaining life always in (1h, 2h], so a `max-age=3600` cached response can never outlive the signature.
  Arithmetic re-derived at the worst case (request at HH:59:59 → 1h 0m 1s). Both new resolver tests green.
- `Cache-Control: private, max-age=3600, immutable` (`MediaStorageAdapter.php:96-98`) — `private` is right:
  signed URLs must never enter a shared cache.
- `exists()` guard (`:88-90`) → clean 404, test `ready asset with missing original bytes returns 404 not 500` green.
- **Rendition-row-with-missing-object → fall back to original (`:70-74`): sound.** It matches the pre-existing
  "rendition row absent → original" semantics; the controller's no-silent-downgrade rule targets *unknown variant
  keys*, not a known variant whose derived file vanished. Test `missing rendition object falls back to the original`
  green. **[Minor]** cost is up to 2 extra object-store HEADs per serve, offset by the response now being cacheable.

**4. `signed-media` re-key to `ip|tenant` at 600/min — their argument is correct; no abuse vector reopened.**
- The tenant segment is part of the SIGNED path (`MediaUrlResolver.php:69-72`); altering it invalidates the HMAC →
  403 at `signed:relative` (`Product/routes.php:176`), which is listed before `throttle:signed-media`.
  So the tenant half of the key is HMAC-authenticated and cannot be forged to widen a budget.
- Nor can it be used to exhaust a victim's budget: the other half of the key is the attacker's own (non-spoofable,
  see item 2) IP. Worst case an attacker throttles themselves.
- **[Minor, accepted]** the raise does widen replay amplification: one leaked signed URL can now be replayed
  600×/min for up to 2h against MinIO. That is bandwidth, not data exposure — the URL already discloses that one
  image. Acceptable at this stage; revisit if object-store egress is metered.

**5. Backfill migration + `media:promote-failed-images` — matches the ruling.**
`PromoteFailedImageAssetsCommand` extends `TenantScopedCommand`, dry-run is the default (`--apply` to write),
`Schema::hasTable` guard per tenant, per-asset `Storage::disk()->exists()` before promotion, missing-bytes rows left
FAILED with an operator warning. Registered console-only (`MediaServiceProvider.php:41-47`), **not** scheduled
(no scheduler reference found). 5 tests green including "apply promotes only assets whose original bytes exist".

**6. `markProcessing`/`markFailed` now have zero production callers — RULING: acceptable, ticket not a blocker.**
Verified the only remaining references are the repository writers themselves
(`EloquentMediaAssetRepository.php:30,44`) and the new command's *read*
(`PromoteFailedImageAssetsCommand.php:75`). Nothing in any UI, report or query ever surfaced FAILED, so no operator
surface is actually being lost — the diagnostic was already invisible. Signal is preserved by Horizon `failed_jobs`,
the `GenerateRenditions failed — asset keeps serving its original bytes` log line, and the new command's report.
**Follow-up ticket (post-merge, not a gate):** a small asset-health counter (assets with zero renditions / missing
bytes) or a Sentry alert rule on that log line, so "renditions have been failing for a week" is noticed without
someone running the command.

**7. `ConsoleCommandTenantContextTest` — pre-existing red confirmed unchanged.** Exactly 8 unclassified commands,
and `grep -i PromoteFailedImage` over the failure output returns nothing. The new command does not add to the ratchet.

**8. Round-1 Minors closed.** `degraded` is now rendered as a distinct UNKNOWN state with its own icon and badge
(`SetupChecklist.tsx:32-35,124-167`) instead of reading as "incomplete"; the POS ticket exists at
`docs/superpowers/tickets/2026-08-06-pos-product-images-never-populated.md`.

**9. 419 retry hoist (`api.ts:189-215`) — reviewed in passing (web gate owns it).** Correct: keyed on status alone
above the `isApiError` gate (419 is an untyped `HttpException`, so it never reached the old branch), and the
`_csrfRetried` flag on the request config bounds it to a single replay — no loop. 4 tests green.

## Suites run (Round 2, by path)

| Suite | Result |
|---|---|
| `tests/Feature/Http/` | 8 passed (incl. all 3 new catch-all tests + host-not-trusted) |
| `tests/Feature/Modules/Catalog/Media/` | 133 passed, 1 skipped (incl. 3 new serve tests) |
| `tests/Feature/Modules/Media/` + `tests/Unit/.../MediaUrlResolverTest.php` | 78 passed |
| `tests/Feature/Modules/Media/BackfillReadyImageAssetsTest.php` + `PromoteFailedImageAssetsCommandTest.php` | 5 passed |
| `tests/Architecture/ConsoleCommandTenantContextTest.php` | 1 failed — **pre-existing**, 8 commands, new one absent |
| PHPStan L8 on the 6 round-2 files (incl. the migration) | OK, 0 errors |
| web vitest: `api.csrfRetry`, `api`, `SetupChecklist` | 18 passed |

## Deploy notes (unchanged from Round 1, restated)

- The migration auto-runs on the `origin/dev` staging deploy via `tenants:migrate`. Idempotent, no prerequisite.
- `media:promote-failed-images` is manual: run **dry** first, read the per-tenant report, then `--apply`.
- No new permission → no seeder re-sync, no `permission:cache-reset` needed for this lane.
- Deploy invariant to keep: the `api` service must never publish `ports:` (now stated in `bootstrap/app.php:66`).
