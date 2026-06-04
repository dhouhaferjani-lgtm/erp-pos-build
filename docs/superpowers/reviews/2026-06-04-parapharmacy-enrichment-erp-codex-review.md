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
