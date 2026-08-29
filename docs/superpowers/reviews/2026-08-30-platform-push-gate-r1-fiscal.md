<!-- Codex read-only adversarial gate r1; fiscal/POS + integration lens; 2026-08-29. -->

# Platform push gate r1 — fiscal/POS + integration review

**Lane:** `fix/platform-push-gate-staging`  
**Reviewed commit:** `4ff7954c9debd090ad0362a8674e7673cbfadf63`  
**Base:** `cdc54f9f4cbf7a0b19d6e1611e4e4b631360cec8` (`HEAD~1`)  
**Range:** `HEAD~1..HEAD` (the requested local `dev-base` ref is absent; `git log -1` confirms the target is the single commit above)  
**Mode:** read-only review; only this requested review artifact was added.

## Verdict

CHANGES REQUIRED. The enrichment writers themselves are correctly gated, but the independent `PlatformHttpClient` caller census found an ungated outbound write in Purchase Hub. This is Critical under the gate rule and means staging can still mutate the production platform while `SYNERIVA_PLATFORM_PUSH_ENABLED=false`.

## Findings

### F1 — Critical — `PurchaseHubService::placeOrder()` bypasses the platform push gate

`PurchaseHubService::placeOrder()` is an outbound platform mutation and calls `PlatformHttpClient->post('/api/v1/purchase-hub/tenant/orders', ...)` with no `services.platform.push_enabled` check (`apps/api/app/Modules/PurchaseHub/Application/Services/PurchaseHubService.php:79-89`, write at `:84`). `PlatformHttpClient::post()` issues the underlying HTTP POST (`apps/api/app/Modules/PlatformIntegration/Infrastructure/Http/PlatformHttpClient.php:74-83`). There is no gate reference anywhere in `PurchaseHubService`.

This is observable, not merely structural: with `SYNERIVA_PLATFORM_PUSH_ENABLED=false`, the existing `PurchaseHubOrderTest::it_places_order_through_platform` still receives the fake upstream 201 response (`apps/api/tests/Feature/PurchaseHub/PurchaseHubOrderTest.php:86-115`). Fresh command:

```text
SYNERIVA_PLATFORM_PUSH_ENABLED=false php artisan test \
  tests/Feature/PurchaseHub/PurchaseHubOrderTest.php \
  --filter=it_places_order_through_platform

PASS — 1 test, 4 assertions
```

Therefore criteria 1 and 3 fail: not every outbound writer is gated, the disabled path makes an HTTP call, it returns the upstream success shape instead of `placeOrder()`'s `null` failure/no-op shape, and it emits no single informational no-op log.

Required fix: gate `PurchaseHubService::placeOrder()` before cache invalidation/HTTP, return `null`, emit exactly one info log, and add a real `Http::fake()` + `Http::assertNothingSent()` regression. Do not gate `PlatformHttpClient::post()` globally because `CatalogBrowseService::searchByCriteria()` is a POST-shaped read (`apps/api/app/Modules/PlatformIntegration/Application/Services/CatalogBrowseService.php:151-161`).

### F2 — Important — the “existing tests untouched” gate condition is not met

The commit rewrites two pre-existing default-enabled test cases rather than leaving the existing suite byte-untouched: `test_handle_does_not_throw_on_conflict` and `test_handle_does_not_throw_on_not_found` replace their prior call/assertion bodies (`apps/api/tests/Unit/Modules/Product/SendBrandMappingJobTest.php:35-57`; diff removes four prior lines and adds context assertions). The changes strengthen rather than weaken those assertions, and the default-enabled behavior is green, but the explicit “existing tests untouched” condition is still false.

Required fix: either restore those two pre-existing test bodies byte-for-byte and keep new coverage additive, or obtain an explicit gate ruling that strengthening existing assertions is allowed.

## Six-point gate audit

### 1. Outbound-write caller census — FAIL

Independent application-code census of every `PlatformHttpClient` call and the direct presigned upload PUT:

| Semantic operation | HTTP call | Gate evidence | Result |
|---|---|---|---|
| Request photo upload URL | `ProductSubmissionService.php:36` | guard `:30-34` | PASS |
| Upload photo to presigned URL | direct PUT `ProductSubmissionService.php:61-64` | guard `:55-59` | PASS |
| Submit product | `ProductSubmissionService.php:76-88` | guard `:70-74` | PASS |
| Bulk submit | `ProductSubmissionService.php:119-123` | guard `:103-107` | PASS |
| Send enrichment feedback | `ProductSubmissionService.php:179-186` | guard `:166-174` | PASS |
| Push brand mapping | `ProductSubmissionService.php:214-217` | guard `:207-211` | PASS |
| Trigger enrichment | `ProductSubmissionService.php:270` | guard `:264-268` | PASS |
| Place Purchase Hub order | `PurchaseHubService.php:79-89` | none | **FAIL — Critical** |

POST-shaped reads were classified separately: barcode lookup (`BarcodeLookupService.php:62-66`), bulk lookup (`ProductSubmissionService.php:126-137`), and catalog criteria search (`CatalogBrowseService.php:151-161`). All other `PlatformHttpClient` calls are GET/getRaw reads. No other direct `Http` write exists in PlatformIntegration/PurchaseHub besides the presigned upload PUT above.

### 2. Read paths remain ungated — PASS

- Barcode lookup calls the platform without consulting the push flag (`BarcodeLookupService.php:32-66`); the committed disabled-mode test asserts a POST is sent (`PlatformPushGateReadPathsTest.php:18-45`).
- Catalog browsing has no push-gate branch; its reads remain at `CatalogBrowseService.php:23-230`, including the POST-shaped criteria read at `:151-161`. A fresh env-false run of `OutboundHttpTenantTaggingTest --filter=catalog_browse_service_propagates_tenant_headers` passed.
- Webhook verification reads only the webhook secret and always performs timestamp/HMAC verification (`VerifySynerivaWebhookSignature.php:16-40`). A fresh env-false run of `VerifySynerivaWebhookSignatureTest --filter=valid_signature_passes` passed.
- Product status, bulk lookup, and category-attribute reads remain ungated (`ProductSubmissionService.php:132-163,284-286`); bulk lookup has an explicit disabled-mode sent-request assertion (`ProductSubmissionServiceTest.php:468-480`).

### 3. Enrichment disabled paths — PASS for implemented methods; global criterion FAIL via F1

The shared guard uses a strict false check and emits one info line (`ProductSubmissionService.php:289-297`). Each implemented writer returns its established null/failed shape before HTTP:

- `submit()` → `null`; real fake/no-send/log assertion at `ProductSubmissionServiceTest.php:77-102`.
- `sendFeedback()` → `false`; assertion at `:177-197`.
- `pushBrandMapping()` → `BrandMappingPushResult::Failed`; assertion at `:291-306`.
- `requestUploadUrl()` → `null`; assertion at `:366-381`.
- `uploadPhoto()` → void clean return; assertion at `:404-422`.
- `bulkSubmit()` → `null`; assertion at `:424-449`.
- `triggerEnrichment()` → `null`; assertion at `:451-466`.

Every test uses `Http::fake()` and `Http::assertNothingSent()`, and the captured info-message array is asserted equal to a one-element list. F1 prevents this criterion from passing for every outbound platform write.

### 4. Queue jobs complete cleanly on no-op — PASS

Both jobs retain three attempts with `[10, 60, 300]` backoff (`SendEnrichmentFeedbackJob.php:25-30`; `SendBrandMappingJob.php:24-29`). Their disabled branches do not throw: feedback suppresses the false-result exception only when disabled (`SendEnrichmentFeedbackJob.php:42-59`), and brand mapping returns before the failed-result exception (`SendBrandMappingJob.php:39-65`). Both `finally` blocks clear company context.

Real-service tests execute the jobs under disabled config, assert zero HTTP, and complete normally (`SendEnrichmentFeedbackJobTest.php:99-117`; `SendBrandMappingJobTest.php:70-82`). Thus the no-op cannot engage Laravel retry/backoff.

### 5. Default-enabled compatibility — behavioral PASS; untouched-test condition FAIL via F2

`config('services.platform.push_enabled', true) !== false` preserves the legacy path for unset/true configuration (`ProductSubmissionService.php:289-293`). Existing payload, response-shape, error, circuit-breaker, tenant-header, upload, job retry, and webhook tests all remain green. Fresh complete scoped run: **128 passed, 352 assertions, 3 pre-existing PostgreSQL-only skips, 0 failures**.

The outbound behavior is unchanged under default true, but the suite was not literally untouched because of F2.

### 6. Config and environment wiring — PASS

- Laravel config defaults enabled: `apps/api/config/services.php:104-109`.
- `.env.example` documents the variable and keeps the safe compatibility default `true`: `apps/api/.env.example:143-149`.
- Staging forces string `"false"` in the shared Laravel environment anchor: `docker-compose.staging.yml:17-53`. API, worker, scheduler, and websocket all merge that anchor (`:178-186`, `:206-214`, `:227-235`, `:242-250`).
- Fresh `config:show` resolved false/true to actual booleans for the respective environment values.
- `docker compose -f docker-compose.staging.yml config --no-interpolate` exited 0 and showed `SYNERIVA_PLATFORM_PUSH_ENABLED: "false"` in all four Laravel services.

## Verification record

```text
git diff --check HEAD~1..HEAD
PASS

php artisan test tests/Unit/Modules/PlatformIntegration \
  tests/Feature/PlatformIntegration \
  tests/Unit/Modules/Product/SendBrandMappingJobTest.php \
  tests/Unit/Modules/Product/SendEnrichmentFeedbackJobTest.php
PASS — 128 passed, 352 assertions, 3 skipped, 0 failures

./vendor/bin/pint --test <changed PHP/test files>
PASS

docker compose -f docker-compose.staging.yml config --no-interpolate
PASS
```

GATEVERDICT: CHANGES
Critical: `PurchaseHubService::placeOrder()` remains an ungated outbound platform write and still sends HTTP when the flag is false.
Important: two pre-existing `SendBrandMappingJobTest` cases were modified, violating the explicit “existing tests untouched” gate condition.
