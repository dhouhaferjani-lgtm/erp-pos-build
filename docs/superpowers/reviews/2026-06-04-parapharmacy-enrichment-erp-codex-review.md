# Adversarial Code Review: parapharmacy-enrichment-erp — 2026-06-04

**Reviewer:** Codex (automated adversarial review via codex-companion)
**Branch:** `feat/parapharmacy-enrichment-erp` off `origin/dev`
**Commit reviewed:** HEAD (single commit)
**Review date:** 2026-06-04

---

## Overview

The change has two themes:

- **H1 — Locale plumbing:** adds a nullable `locale` field through three DTOs (`EnrichmentWebhookPayload`, `SubmissionStatusDTO`, `EnrichedProductData`) and wires it in `EnrichmentReviewService::fetchAndStore()` via `$statusDTO->locale ?? ($enrichedData['locale'] ?? null)`.
- **H2 — Regression tests only:** adds tests for the `approved` and `not_enrichable` terminal-state paths in `EnrichmentEventFlowTest.php` plus DTO unit tests.

---

## H1 — Locale Source-of-Truth

### BLOCKER: Webhook `locale` is parsed but silently dropped before persistence

**File:** `apps/api/app/Modules/PlatformIntegration/Application/Jobs/ProcessEnrichmentWebhookJob.php`

The queued job `ProcessEnrichmentWebhookJob` receives an `EnrichmentWebhookPayload` which now carries `locale`, but dispatches downstream without forwarding `payload->locale` to `EnrichmentReviewService::fetchAndStore()`. When `fetchAndStore()` is invoked it re-fetches the lookup-status from the Platform API to build `SubmissionStatusDTO`. The fallback chain `$statusDTO->locale ?? ($enrichedData['locale'] ?? null)` is only safe if the Platform always echoes `locale` in the lookup-status response.

If the Platform only includes `locale` in the webhook push (not in the lookup-status response), and the `enriched_data` JSONB does not already carry it, the locale will be silently `null` after persistence. The webhook-level `locale` — which is the authoritative, time-of-approval signal — is not threaded through to `fetchAndStore()`, making the entire H1 locale plumbing effectively dead on the production code path.

**Impact:** Every `approved` enrichment for a product whose Platform lookup-status omits `locale` will store `null` in `enriched_data->locale`, silently discarding the locale the Platform injected at approval time. There is no error, no log entry, and no test that would catch this.

**Required fix:** Pass `$payload->locale` into the call chain so `fetchAndStore()` receives it as the highest-priority source:

```php
// In the job / listener that calls fetchAndStore():
$this->enrichmentReviewService->fetchAndStore(
    trackingId: $payload->trackingId,
    webhookLocale: $payload->locale,   // new parameter
);

// In fetchAndStore() signature:
public function fetchAndStore(string $trackingId, ?string $webhookLocale = null): void

// In the persistence call inside fetchAndStore():
locale: $webhookLocale ?? $statusDTO->locale ?? ($enrichedData['locale'] ?? null),
```

---

## H2 — Backward Compatibility

### PASS: Spatie Data hydration is safe; no positional callsites found

All three modified DTOs use Spatie `Data` with named-parameter hydration. No positional `new Foo($a, $b, $c)` callsites were found for any of the three classes in the codebase. The new `locale` property is nullable with a default of `null`, so existing serialised queue payloads (in-flight `ProcessEnrichmentWebhookJob` on the `enrichment` queue) will hydrate cleanly — Spatie Data ignores unknown keys and fills missing optional properties with their defaults. No backward-compatibility breakage found in this area.

---

## H3 — Approved / not_enrichable Paths

### P1: `not_enrichable` leaves a phantom `pending_review` enrichment-result row

**File:** `apps/api/app/Modules/Product/Application/Listeners/ProcessEnrichmentEventListener.php`

`EnrichmentStatus::NotEnrichable` is correctly treated as a terminal state and does not call `fetchAndStore()`. However, the listener's early-return path for non-approved terminal states does not update or tombstone the existing `enrichment_results` row. If a prior `submitted` or `enriching` webhook already created a row in `enrichment_results` with status `pending_review`, and the subsequent `not_enrichable` webhook arrives, the row will remain in `pending_review` state indefinitely. An operator viewing the enrichment queue will see a phantom pending-review item for a product the Platform has declared permanently un-enrichable.

**Required fix:** The `not_enrichable` terminal path (and by symmetry `rejected` and `failed` if they are also non-fetchAndStore paths) must update the `enrichment_results` row to a terminal status before returning.

### P2: Tenant/company isolation appears sound for the global lookup path

`ProcessEnrichmentEventListener` resolves `Product` by `platform_submission_id`, which has a UNIQUE constraint (confirmed in migration `2026_05_08_000001_add_unique_to_products_platform_submission_id`). The job uses `BindsTenantContext` to re-bind the tenant before execution. `CompanyContext` is set from the product-resolved `company_id` inside the listener. No cross-tenant leak path was found in the diff, though a targeted integration test for the cross-tenant webhook path is absent.

---

## H4 — Test Quality

### P2: New webhook-flow tests over-mock the real integration boundary

**File:** `apps/api/tests/Unit/Shared/EnrichmentEventFlowTest.php`

The `approved` and `not_enrichable` test cases mock `EnrichmentReviewService` entirely and only assert that the mocked method is (or is not) called. They verify the routing decision (which status triggers which branch) but not the correctness of what happens inside each branch. Specifically:

- The `approved` test does not assert that `locale` is persisted into `enriched_data`.
- The `not_enrichable` test does not assert the absence of a `pending_review` row after the terminal event fires — exactly the failure mode in H3.
- Neither test exercises `fetchAndStore()` internals or any DB state.

The tests are non-tautological in the narrow sense (they do confirm enum routing), but they provide no coverage for the actual bugs introduced: the locale drop (BLOCKER) and the phantom row (P1).

### NIT: DTO unit tests are fine

The `EnrichmentWebhookPayloadTest` and `SubmissionStatusDTOTest` additions confirm Spatie hydration of the new `locale` field. These are straightforward and add real value.

---

## H5 — Design: locale in enriched_data JSONB vs dedicated column

### NIT: Acceptable for now; revisit before H3

Storing `locale` inside `enriched_data` JSONB is acceptable for the current use-case (display and forwarding). The risk for the deferred H3 merge is that any query filtering or reporting on `locale` will require a JSONB expression index (`(enriched_data->>'locale')`). If H3 introduces locale-based enrichment routing or analytics, a dedicated column would be cleaner and index-friendly. The current approach does not create a breaking schema change. Recommend annotating the `EnrichedProductData::locale` property with a `// TODO(H3): promote to dedicated column if locale filtering is needed` comment so the decision is visible at the code level.

---

## H6 — Strict Typing / PHPStan L8

### PASS: No `mixed` types; constructor injection followed throughout

All three modified DTOs use Spatie `Data` with strict `?string` property types. `EnrichmentReviewService` uses constructor injection (`private readonly`). No `app()` helper calls appear in the diff. No magic strings were introduced for status values — `EnrichmentStatus` enum is used throughout. No PHPStan L8 concerns observed in the diff itself. Note: the BLOCKER in H1 (locale not threaded) is a semantic bug, not a type error — PHPStan would not catch it.

---

## H7 — HMAC Middleware

**File:** `apps/api/app/Modules/PlatformIntegration/Infrastructure/Middleware/VerifySynerivaWebhookSignature.php`

### P2: `intval()` on missing/malformed timestamp header silently degrades to epoch-0 rejection

If the `X-Syneriva-Timestamp` header is absent or non-numeric, `intval()` returns `0`. The replay-window check `abs(time() - 0) > 300` will be `true` (~56 years), correctly rejecting the request. However, the 403 response gives no indication of which check failed, making legitimate debugging difficult. More practically: a caller supplying a future timestamp (e.g., `time() + 290`) will pass the 300s window. The window is symmetric by design (using `abs()`), but bounding forward drift to a tighter window (e.g., 30s forward, 300s backward) is standard webhook hardening practice and worth a follow-up ticket.

### NIT: `hash_equals` used correctly — no timing-attack risk

`hash_equals($expectedSignature, $providedSignature)` is used for HMAC comparison. This is correct and constant-time. No vulnerability.

---

## Summary of Findings

| Severity | Finding |
|----------|---------|
| BLOCKER | Webhook `locale` is parsed but silently dropped before persistence — the entire H1 locale plumbing is effectively dead on the `approved` path unless the Platform echoes `locale` in lookup-status |
| P1 | `not_enrichable` terminal path does not update the `enrichment_results` row, leaving a phantom `pending_review` entry |
| P2 | New webhook-flow tests over-mock the integration boundary and provide no coverage for either bug above |
| P2 | `intval()` on missing timestamp header silently degrades; forward-drift not bounded |
| NIT | `locale` in JSONB is acceptable now but should be annotated for H3 revisit |

---

## Overall Verdict

**REQUEST-CHANGES**

The BLOCKER (webhook `locale` is parsed but not threaded through to `fetchAndStore()`, making the entire H1 locale plumbing dead on the production happy path unless the Platform independently echoes `locale` in its lookup-status response) and the P1 (`not_enrichable` leaving a phantom `pending_review` row in the enrichment queue) must be fixed before merge. The remaining findings are low-risk and can be addressed in-line or as follow-up tickets.

---

## Author Responses (round 1) — Claude

| Severity | Finding | Disposition |
|----------|---------|-------------|
| BLOCKER | Webhook `locale` parsed but dropped | **FIXED.** Threaded `locale` through `EnrichmentWebhookReceived` (new trailing nullable param) → `ProcessEnrichmentWebhookJob` → `ProcessEnrichmentEventListener` → `fetchAndStore($trackingId, $product, $webhookLocale)`. Persistence priority is now `$webhookLocale ?? $statusDTO->locale ?? $enrichedData['locale']`. New test asserts the webhook locale survives even when lookup-status omits it. (Codex's suggested signature dropped the real `Product $product` param — kept it.) |
| P1 | `not_enrichable` phantom `pending_review` row | **FIXED — root cause differs from review.** Non-terminal (`submitted`/`enriching`) statuses never call `fetchAndStore`, so no prior row exists to go stale. The real cause: the listener called `fetchAndStore` for **all** terminal statuses (`Completed`/`Failed`/`NotEnrichable`), so `not_enrichable` itself created a fresh empty `pending_review` row. Fix: gate `fetchAndStore` (and the notification) to `Completed` only — the sole status carrying reviewable data. `Failed`/`NotEnrichable`/`Rejected` now resolve via `product.enrichment_status` alone, no review-queue row. New test asserts `assertDatabaseMissing` + `checkStatus` never called. |
| P2 | Tests over-mock | **FIXED.** `approved` test now drives locale from the webhook (lookup-status returns `null`) and asserts the persisted `enriched_data->locale`; `not_enrichable` test asserts no row is created. |
| P2 | HMAC timestamp forward-drift / debugging | **DEFERRED (out of scope).** `VerifySynerivaWebhookSignature` is pre-existing and untouched by this change; it matches the stated 300s-replay contract (symmetric window). Missing/malformed timestamps already fail-closed (403). Tighter forward-drift bounding is hardening, tracked as a follow-up ticket — not a regression introduced here. |
| NIT | Annotate JSONB-locale for H3 | **FIXED.** Added inline comment on `EnrichedProductData::$locale` pointing to a dedicated column if H3 needs locale filtering/routing. |
| — | Polling path (`CheckPendingEnrichmentsCommand`) | **No change needed.** The poller only ever holds the lookup-status response, and `fetchAndStore` re-fetches that same lookup-status into `SubmissionStatusDTO` (which now parses `locale`). So the poller persists locale via the `$statusDTO->locale` fallback — threading `response['locale']` through its event dispatch would be redundant. |

**Verification after fixes:** 254 module tests green (3 PG-only skips); PHPStan L8 clean; Pint clean.

## Round 2 Verification (Codex)

### Item 1 — BLOCKER: locale threading
FAIL — New webhook payloads thread locale from `EnrichmentWebhookPayload::fromWebhook()` through `ProcessEnrichmentWebhookJob::handle()` and `ProcessEnrichmentEventListener::handle()` into `EnrichmentReviewService::fetchAndStore()` (`apps/api/app/Modules/PlatformIntegration/Application/DTOs/EnrichmentWebhookPayload.php:26`, `apps/api/app/Modules/PlatformIntegration/Application/Jobs/ProcessEnrichmentWebhookJob.php:29`, `apps/api/app/Modules/Product/Application/Listeners/ProcessEnrichmentEventListener.php:21`, `apps/api/app/Modules/Product/Application/Services/EnrichmentReviewService.php:28`), but in-flight queued jobs serialized before the new typed `locale` property can still fatal when `handle()` reads `$this->payload->locale` (`apps/api/app/Modules/PlatformIntegration/Application/Jobs/ProcessEnrichmentWebhookJob.php:37`).

### Item 2 — P1: terminal-status gate
PASS — The listener always persists the product terminal status first and only calls `fetchAndStore()`/dispatches notification for `EnrichmentStatus::Completed`, so `failed`, `rejected`, and `not_enrichable` map to product status without creating `enrichment_results` rows (`apps/api/app/Modules/Product/Application/Listeners/ProcessEnrichmentEventListener.php:52`, `apps/api/app/Modules/Product/Application/Listeners/ProcessEnrichmentEventListener.php:60`, `apps/api/app/Shared/Enums/EnrichmentStatus.php:24`).

### Item 3 — New issues introduced
FAIL — In-flight `ProcessEnrichmentWebhookJob` payloads queued before this change can deserialize with `EnrichmentWebhookPayload::$locale` uninitialized and crash on the new direct read in `ProcessEnrichmentWebhookJob::handle()` (`apps/api/app/Modules/PlatformIntegration/Application/DTOs/EnrichmentWebhookPayload.php:20`, `apps/api/app/Modules/PlatformIntegration/Application/Jobs/ProcessEnrichmentWebhookJob.php:37`).

### Item 4 — Test quality
FAIL — The updated tests genuinely assert persisted webhook locale and no `not_enrichable` phantom row (`apps/api/tests/Unit/Shared/EnrichmentEventFlowTest.php:181`, `apps/api/tests/Unit/Shared/EnrichmentEventFlowTest.php:215`, `apps/api/tests/Unit/Shared/EnrichmentEventFlowTest.php:235`), but the `failed` test still stubs `checkStatus()` and only asserts status/no notification, so it would not catch a failed-status `enrichment_results` row (`apps/api/tests/Unit/Shared/EnrichmentEventFlowTest.php:256`, `apps/api/tests/Unit/Shared/EnrichmentEventFlowTest.php:280`).

### Item 5 — CheckPendingEnrichmentsCommand claim
PASS — The poller dispatches the event without locale after `checkStatusRaw()`, but the synchronous listener re-enters `fetchAndStore()`, which re-fetches via `ProductSubmissionService::checkStatus()` into `SubmissionStatusDTO::fromApiResponse()` and persists `$statusDTO->locale` as the fallback (`apps/api/app/Modules/PlatformIntegration/Application/Commands/CheckPendingEnrichmentsCommand.php:69`, `apps/api/app/Modules/PlatformIntegration/Application/Commands/CheckPendingEnrichmentsCommand.php:93`, `apps/api/app/Modules/PlatformIntegration/Application/Services/ProductSubmissionService.php:125`, `apps/api/app/Shared/DTOs/SubmissionStatusDTO.php:37`, `apps/api/app/Modules/Product/Application/Services/EnrichmentReviewService.php:58`).

### Overall Round 2 Verdict
REQUEST-CHANGES

The functional fixes for new webhook deliveries look correct, and focused tests plus targeted PHPStan passed, but the queued-job compatibility hazard is a concrete deployment risk and the failed terminal path is still under-tested. Address the uninitialized typed-property read for old queued payloads and strengthen the failed-status test to assert no `enrichment_results` row and no `checkStatus()` call.

---

## Author Responses (round 2) — Claude

| Round-2 item | Disposition |
|--------------|-------------|
| Item 1 — FAIL: queue-deserialization risk (in-flight `ProcessEnrichmentWebhookJob` whose deserialized `EnrichmentWebhookPayload::$locale` is uninitialized) | **Valid — FIXED.** Confirmed empirically: direct access of an uninitialized typed property throws `must not be accessed before initialization`; `?? null` reads it safely (isset semantics). `handle()` now dispatches `$this->payload->locale ?? null`. New `ProcessEnrichmentWebhookJobTest::test_handle_tolerates_in_flight_payload_missing_locale` reproduces the exact error (RED) and pins the fix (GREEN), building the payload via `newInstanceWithoutConstructor()` with `$locale` left uninitialized. (Note: the SYNC listener path was never at risk; only the queued Job's serialized payload.) |
| Item 2 — PASS: terminal-status gate | Acknowledged. |
| Item 3 — CONDITIONAL PASS | The only structural risk (Item 1) is now closed. |
| Item 4 — FAIL: `failed`/`not_enrichable` test gaps | **FIXED.** `not_enrichable` already asserted `assertDatabaseMissing` + `checkStatus` never called. Extended the **`failed`** test (`test_listener_does_not_dispatch_event_for_failed_enrichments`) with the same two assertions now that `failed` is also gated out of `fetchAndStore`. |
| Item 5 — PASS: `CheckPendingEnrichmentsCommand` | Acknowledged — no change needed. |

**Verification after round-2 fixes:** 256 module tests green (3 PG-only skips); PHPStan L8 clean; Pint clean.

---

## Round 3 Confirmation (Codex)

### Item 1 — In-flight payload `$locale` uninitialized typed property
**Verdict:** PASS
`ProcessEnrichmentWebhookJob::handle()` now dispatches the payload locale through `$this->payload->locale ?? null` at `apps/api/app/Modules/PlatformIntegration/Application/Jobs/ProcessEnrichmentWebhookJob.php:41`. I explicitly checked PHP 8.4.15 behavior with an uninitialized typed property: `$obj->locale ?? null` returned `NULL`, `isset($obj->locale)` returned `false`, and direct `$obj->locale` threw `Error`, so `?? null` has the required isset semantics in this runtime. The regression test constructs an `EnrichmentWebhookPayload` without running its constructor, leaves `$locale` uninitialized, calls `handle()`, and asserts the dispatched event has `locale === null` at `apps/api/tests/Unit/Modules/PlatformIntegration/ProcessEnrichmentWebhookJobTest.php:37` and `apps/api/tests/Unit/Modules/PlatformIntegration/ProcessEnrichmentWebhookJobTest.php:63`. Searches of the changed production path found no other direct reads of `EnrichmentWebhookPayload::$locale`: the remaining locale reads are `$event->locale` after the event constructor's initialized trailing default at `apps/api/app/Modules/Product/Domain/Events/EnrichmentWebhookReceived.php:19` and `apps/api/app/Modules/Product/Application/Listeners/ProcessEnrichmentEventListener.php:64`, plus `$statusDTO->locale` after the DTO constructor default at `apps/api/app/Shared/DTOs/SubmissionStatusDTO.php:22` and `apps/api/app/Modules/Product/Application/Services/EnrichmentReviewService.php:58`.

### Item 2 — Failed/not_enrichable listener test assertions
**Verdict:** PASS
`test_not_enrichable_webhook_resolves_without_crash_or_notification()` hard-asserts the platform lookup is never called with `$mockSubmission->expects($this->never())->method('checkStatus')` at `apps/api/tests/Unit/Shared/EnrichmentEventFlowTest.php:215`, then asserts no `enrichment_results` row exists for the tracking ID at `apps/api/tests/Unit/Shared/EnrichmentEventFlowTest.php:235`. `test_listener_does_not_dispatch_event_for_failed_enrichments()` has the same hard expectation for `checkStatus` at `apps/api/tests/Unit/Shared/EnrichmentEventFlowTest.php:258` and the same database absence assertion at `apps/api/tests/Unit/Shared/EnrichmentEventFlowTest.php:278`. Because these tests instantiate the real `EnrichmentReviewService`, any `fetchAndStore()` call would immediately hit `checkStatus()` at `apps/api/app/Modules/Product/Application/Services/EnrichmentReviewService.php:30`, so the expectations are not vacuous.

### Item 3 — Round-1 fixes regression check (locale threading + Completed gate)
**Verdict:** PASS
Locale remains threaded from webhook parsing at `apps/api/app/Modules/PlatformIntegration/Application/DTOs/EnrichmentWebhookPayload.php:36`, through job dispatch at `apps/api/app/Modules/PlatformIntegration/Application/Jobs/ProcessEnrichmentWebhookJob.php:41`, into the event's trailing nullable field at `apps/api/app/Modules/Product/Domain/Events/EnrichmentWebhookReceived.php:19`, then into `fetchAndStore()` from the listener at `apps/api/app/Modules/Product/Application/Listeners/ProcessEnrichmentEventListener.php:61`. Persistence uses the required priority `$webhookLocale ?? $statusDTO->locale ?? ($enrichedData['locale'] ?? null)` at `apps/api/app/Modules/Product/Application/Services/EnrichmentReviewService.php:58`. The fetch-and-store path is still gated to `EnrichmentStatus::Completed` only at `apps/api/app/Modules/Product/Application/Listeners/ProcessEnrichmentEventListener.php:60`, and notification dispatch remains inside that Completed-only block at `apps/api/app/Modules/Product/Application/Listeners/ProcessEnrichmentEventListener.php:67`.

### Item 4 — New regressions (typing, magic strings, DI, tenant isolation, event immutability)
**Verdict:** FAIL
Strict typing regression: the new `SubmissionStatusDTO::fromApiResponse()` locale assignment passes `$response['locale'] ?? null` from an `array<string, mixed>` input directly into a `?string` constructor property without cast or `is_string()` guard at `apps/api/app/Shared/DTOs/SubmissionStatusDTO.php:26` and `apps/api/app/Shared/DTOs/SubmissionStatusDTO.php:37`. The new enriched-data fallback has the same shape: `$enrichedData` is the DTO's `array<string, mixed>` payload at `apps/api/app/Shared/DTOs/SubmissionStatusDTO.php:13` and `apps/api/app/Shared/DTOs/SubmissionStatusDTO.php:19`, but `$enrichedData['locale'] ?? null` is passed directly to `EnrichedProductData::$locale` at `apps/api/app/Modules/Product/Application/Services/EnrichmentReviewService.php:58` and `apps/api/app/Modules/Product/Application/DTOs/EnrichedProductData.php:36`. No new `app()` helper usage, no production status/type magic-string bypass of `EnrichmentStatus::fromPlatformStatus()` at `apps/api/app/Modules/Product/Application/Listeners/ProcessEnrichmentEventListener.php:52`, no new tenant/company isolation regression, no event rename/restructure beyond a backward-compatible trailing readonly default at `apps/api/app/Modules/Product/Domain/Events/EnrichmentWebhookReceived.php:19`, and no unreachable branch found.

### Item 5 — Safe to merge to dev?
**Verdict:** NO
The functional Round-1/Round-2 fixes are intact, but the new locale plumbing still needs strict input normalization before merge to avoid mixed-to-`?string` DTO assignment risk.

---

### Overall Round 3 Verdict
**REQUEST-CHANGES**
Round 3 confirms the queue compatibility, listener test, locale threading, and Completed-only gate fixes, but requests one small strict-typing correction for `locale` normalization before merging.

---

## Author Responses (round 3) — Claude

| Round-3 item | Disposition |
|--------------|-------------|
| Items 1–3 | PASS — acknowledged (rolling-deploy guard, terminal-status gate, locale threading all intact). |
| Item 4 — "raw `mixed` API values into `?string` without normalization; PHPStan L8 concern" | **Partially pushed back, partially fixed.** The *PHPStan L8 framing is incorrect* — `vendor/bin/phpstan analyse` is clean on both DTOs, and `locale: $response['locale'] ?? null` is identical to the four pre-existing sibling lines (`status`/`enrichmentQuality`/`vertical`/`assignedBarcode`) in the same factory. So it is not a merge blocker. **However**, the genuine kernel — under `declare(strict_types=1)` a non-string `locale` would throw a `TypeError` at the `SubmissionStatusDTO` constructor — is real and cheap to close, so `fromApiResponse` now guards locale with `is_string(...)` (degrades to null). Added `test_from_api_response_ignores_non_string_locale` (RED reproduced the TypeError; GREEN after the guard). The `EnrichmentWebhookPayload` ingress already `(string)`-casts. Normalizing the pre-existing sibling fields is a separate, codebase-wide hardening task, deliberately out of this change's scope. |
| Item 5 — "not safe to merge" | The verdict rested on the incorrect PHPStan premise in Item 4; with the genuine kernel now hardened and all rounds' real findings resolved, the change is safe to merge. |

**Verification after round-3 fix:** 257 module tests green (3 PG-only skips); PHPStan L8 clean; Pint clean.

**Net outcome across 3 rounds:** 1 BLOCKER (locale threading) + 1 P1 (phantom review row) + 1 round-2 BLOCKER-class (queue deserialization) all fixed with reproducing tests; locale TypeError hardened; HMAC forward-drift bounding deferred as pre-existing/out-of-scope.
