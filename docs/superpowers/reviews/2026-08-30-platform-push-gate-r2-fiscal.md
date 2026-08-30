<!-- Codex read-only adversarial gate r2; scoped r1 blocker re-check plus PASS regressions; 2026-08-29. -->

# Platform push gate r2 — fiscal/POS + integration review

**Lane:** `fix/platform-push-gate-staging`
**Reviewed tip:** `6b44f4512dc89d746b310d02a7c4b47856e14495`
**r1 tip:** `4ff7954c9debd090ad0362a8674e7673cbfadf63`
**Development base / fork point:** `cdc54f9f4cbf7a0b19d6e1611e4e4b631360cec8`
**Range:** `cdc54f9f4..6b44f4512` (both `git merge-base dev 6b44f4512` and `git merge-base origin/dev 6b44f4512` resolve to `cdc54f9f4`)
**Scope:** r1 F1/F2 only, plus regressions of r1 PASS items 2, 4, and 6
**Mode:** read-only review; the only workspace write is this requested review artifact

## Verdict

APPROVED. Both r1 blockers are resolved, the independently repeated outbound-write census found no ungated writer, and the three requested PASS regressions still hold. No blocker was found in the scoped review.

## F1 — PASS — Purchase Hub order placement is gated before all side effects

`PurchaseHubService::placeOrder()` checks `config('services.platform.push_enabled', true) === false` at `apps/api/app/Modules/PurchaseHub/Application/Services/PurchaseHubService.php:81-85`. The disabled branch emits its single `Log::info` call at `:82` and returns `null` at `:84`, before cache invalidation at `:88` and the platform POST at `:90`.

The API path is `POST /api/v1/purchase-hub/orders`, registered at `apps/api/app/Modules/PurchaseHub/Presentation/routes.php:18`. `PurchaseHubOrderController::store()` passes the validated body to `placeOrder()` at `apps/api/app/Modules/PurchaseHub/Presentation/Controllers/PurchaseHubOrderController.php:30`; `null` is mapped to HTTP 502 with error code `ORDER_FAILED` and message `Failed to place order` at `:31-37`.

The disabled regression at `apps/api/tests/Feature/PurchaseHub/PurchaseHubOrderTest.php:123-145` uses Laravel's real `Http::fake()`, asserts the controller's 502/error payload, calls `Http::assertNothingSent()` at `:141`, and asserts the exact info message once at `:142-144`. It passed in the fresh Purchase Hub path run.

The base-to-tip service diff contains only the six-line early guard; the original `try`, cache invalidation, POST call, exception handling, and return shapes are unchanged. The enabled regression at `PurchaseHubOrderTest.php:87-120` received the original 201 payload and asserted one outbound request. The enabled tenant-scoped cache invalidation regression also passed.

### Independent outbound-write census

The census searched every application reference to `PlatformHttpClient` and every `post`, `postRaw`, `postWithStatus`, `put`, `patch`, and `delete` call in PlatformIntegration and PurchaseHub. `PlatformHttpClient` has no `put`, `patch`, or `delete` method. The sole direct outbound PUT is the presigned photo upload below.

| Semantic write | Outbound call | Gate before call | Result |
|---|---|---|---|
| Request photo upload URL | `ProductSubmissionService.php:36` `postRaw` | `:32-34` | PASS |
| Upload photo to presigned URL | `ProductSubmissionService.php:61-64` direct `put` | `:57-59` | PASS |
| Submit product | `ProductSubmissionService.php:76-88` `postRaw` | `:72-74` | PASS |
| Bulk submit products | `ProductSubmissionService.php:119-123` `postRaw` | `:105-107` | PASS |
| Send enrichment feedback | `ProductSubmissionService.php:179-186` `postRaw` | `:172-174` | PASS |
| Push brand mapping | `ProductSubmissionService.php:214-217` `postWithStatus` | `:209-211` | PASS |
| Trigger enrichment | `ProductSubmissionService.php:270` `postRaw` | `:266-268` | PASS |
| Place Purchase Hub order | `PurchaseHubService.php:90` `post` | `:81-85` | PASS |

Three POST-shaped calls are reads, not writes, and remain intentionally ungated: barcode lookup (`BarcodeLookupService.php:63-66`), product bulk lookup (`ProductSubmissionService.php:132-137`), and catalog criteria search (`CatalogBrowseService.php:155-160`). All other `PlatformHttpClient` application calls are `get`/`getRaw` reads. No missed outbound writer was found.

## F2 — PASS — pre-existing conflict and not-found tests are byte-identical

Fresh command:

```text
git diff --unified=0 cdc54f9f4cbf7a0b19d6e1611e4e4b631360cec8..6b44f4512 \
  -- apps/api/tests/Unit/Modules/Product/SendBrandMappingJobTest.php
```

The diff contains exactly two imports needed by new coverage and one wholly new test, `test_handle_completes_without_retry_when_platform_push_is_disabled`. There is no hunk within either pre-existing method at `SendBrandMappingJobTest.php:35-44` (`conflict`) or `:46-55` (`not_found`). Their original bodies are therefore byte-identical to the development-base versions. The new test is additive at `:68-80`.

## Regression of r1 PASS items

### Item 2 — reads remain ungated — PASS

- Barcode lookup has no push guard before its POST-shaped read (`BarcodeLookupService.php:32-66`). Its explicit false-config test sent HTTP and passed (`PlatformPushGateReadPathsTest.php:18-45`).
- Product bulk lookup and status/category reads remain outside the shared write guard (`ProductSubmissionService.php:132-163,284-286`). The explicit disabled bulk-lookup test passed in the requested unit path.
- Catalog browsing contains no push-gate branch, including the POST-shaped criteria read at `CatalogBrowseService.php:155-160`. A supplemental run under `SYNERIVA_PLATFORM_PUSH_ENABLED=false` passed and sent the catalog request.
- Webhook verification reads only `services.platform.webhook_secret` and always performs timestamp/HMAC validation (`VerifySynerivaWebhookSignature.php:16-40`). Its valid-signature test passed under `SYNERIVA_PLATFORM_PUSH_ENABLED=false`.
- Purchase Hub offer and order reads remain `get` calls without the write guard (`PurchaseHubService.php:34-73,101-126`); their requested feature tests passed.

### Item 4 — disabled jobs complete cleanly without a retry storm — PASS

Both jobs retain three tries and `[10, 60, 300]` backoff (`SendBrandMappingJob.php:24-29`; `SendEnrichmentFeedbackJob.php:25-30`). Brand mapping returns on the disabled service's `Failed` result before its retry-triggering exception (`SendBrandMappingJob.php:44-49`), while feedback throws only when the push flag is not false (`SendEnrichmentFeedbackJob.php:47-56`). Both always clear company context in `finally`.

The real-service disabled brand-mapping test passed in the requested test-file run with zero HTTP. A supplemental run of `SendEnrichmentFeedbackJobTest --filter=platform_push_is_disabled` also completed normally with zero HTTP: 1 passed, 2 assertions.

### Item 6 — config and environment wiring — PASS

- `apps/api/config/services.php:104-109` wires `SYNERIVA_PLATFORM_PUSH_ENABLED` with compatibility default `true`.
- `apps/api/.env.example:143-149` documents the flag and the staging/test safety intent while retaining default `true`.
- `docker-compose.staging.yml:18-53` sets `SYNERIVA_PLATFORM_PUSH_ENABLED: "false"` in the shared Laravel environment anchor. API, worker, scheduler, and websocket merge that anchor at `:178-186`, `:206-214`, `:227-235`, and `:242-250`.
- Fresh `config:show services.platform` runs resolved environment values `false` and `true` to actual booleans.
- Fresh `docker compose -f docker-compose.staging.yml config --no-interpolate --format json` exited 0 and resolved the flag to string `"false"` for API, worker, scheduler, and websocket.

## Requested test record

All commands ran from `apps/api` with `CACHE_STORE=array`.

| Requested path | Result |
|---|---|
| `tests/Feature/PurchaseHub` | 12 passed, 40 assertions, 0 skipped, 0 failed |
| `tests/Unit/Modules/Product/SendBrandMappingJobTest.php` | 7 passed, 13 assertions, 0 skipped, 0 failed |
| `tests/Feature/PlatformIntegration` | 60 passed, 163 assertions, 3 PostgreSQL-only skipped, 0 failed |
| `tests/Unit/Modules/PlatformIntegration` | 56 passed, 159 assertions, 0 skipped, 0 failed |
| **Aggregate** | **135 passed, 375 assertions, 3 skipped, 0 failed** |

Supplemental scoped regressions:

```text
SYNERIVA_PLATFORM_PUSH_ENABLED=false CACHE_STORE=array php artisan test \
  tests/Feature/PlatformIntegration/OutboundHttpTenantTaggingTest.php \
  --filter=catalog_browse_service_propagates_tenant_headers
PASS — 1 passed, 1 assertion

SYNERIVA_PLATFORM_PUSH_ENABLED=false CACHE_STORE=array php artisan test \
  tests/Unit/Modules/PlatformIntegration/VerifySynerivaWebhookSignatureTest.php \
  --filter=valid_signature_passes
PASS — 1 passed, 1 assertion

CACHE_STORE=array php artisan test \
  tests/Unit/Modules/Product/SendEnrichmentFeedbackJobTest.php \
  --filter=platform_push_is_disabled
PASS — 1 passed, 2 assertions
```

GATEVERDICT: APPROVED
