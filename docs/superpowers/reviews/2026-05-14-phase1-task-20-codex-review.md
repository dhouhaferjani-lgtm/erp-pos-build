# Codex Review — Phase 1 Task 20 (`841c3d781`)

**Verdict: APPROVE-WITH-MINOR-EDITS**

| Severity | File:line | Summary |
|---|---|---|
| P2 | `apps/api/tests/Feature/Fiscal/FiscalEventIngestionEndpointTest.php:186` | No endpoint test for idempotent re-delivery result mapping |
| P2 | `apps/api/tests/Feature/Fiscal/FiscalEventIngestionEndpointTest.php:119` | No endpoint test for quarantined-in-table `canonical_hash_mismatch` result mapping |

---

## Findings

### P2 — Missing endpoint coverage for idempotent re-delivery

**File:line:** `apps/api/tests/Feature/Fiscal/FiscalEventIngestionEndpointTest.php:186`

**Observation:** The only duplicate-slot endpoint test posts a different second envelope and asserts the `sequence_conflict` path at lines 186–205. There is no endpoint test that posts the exact same envelope twice and asserts the idempotent branch. `IngestionResult::idempotent()` returns `stored=false`, `fiscalEventId=<existing id>`, `sequenceConflict=false`, and `exceptionClass=null` at `apps/api/app/Modules/Fiscal/Application/DTOs/IngestionResult.php:72-82`, and the controller maps those fields at `apps/api/app/Modules/Fiscal/Presentation/Controllers/FiscalEventIngestionController.php:154-160`.

**Remediation:** Add an endpoint test that stores `$first = $this->validEnvelopeWire()`, posts the identical `$first` again, and asserts the second response has `stored=false`, `fiscal_event_id` non-null, `sequence_conflict=false`, `exception_class=null`, with `fiscal_events` count still `1` and `fiscal_event_quarantine` count still `0`.

**Spec/handoff reference:** Spec v7 §7.2 Step 4 requires genuine idempotent re-delivery to return the existing event and not re-dispatch projection (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:357-367`). Scrutiny point 9 in the review brief explicitly requires this endpoint path coverage.

---

### P2 — Missing endpoint coverage for quarantined-in-table `canonical_hash_mismatch`

**File:line:** `apps/api/tests/Feature/Fiscal/FiscalEventIngestionEndpointTest.php:119`

**Observation:** The endpoint happy-path test asserts `exception_class=null` for a verified row at lines 119–138, and the duplicate-slot test asserts only `exception_class=sequence_conflict` at lines 186–205. No test drives a hash mismatch that is admitted to `fiscal_events` as quarantined-in-table. This leaves `FiscalEventIngestionController::resultToWire()` untested for the non-conflict quarantine case (`FiscalEventIngestionController.php:154-160`). The ingestor derives `canonical_hash_mismatch` at `OutboxIngestor.php:553-572`, then returns `IngestionResult::quarantined()` at `OutboxIngestor.php:211-212`; that DTO path sets `stored=true`, non-null `fiscalEventId`, `sequenceConflict=false`, and the exception class at `IngestionResult.php:57-69`.

**Remediation:** Add an endpoint test that mutates a valid envelope's `payload.current_hash` to a different valid 64-char lowercase hex string, posts it, and asserts `stored=true`, `fiscal_event_id` non-null, `sequence_conflict=false`, and `exception_class=canonical_hash_mismatch`. Also assert the inserted `fiscal_events` row is quarantined with `integrity_exception_class='canonical_hash_mismatch'`.

**Spec/handoff reference:** Spec v7 §7.2 validates hash before insert and classifies a hash failure as `canonical_hash_mismatch` while still persisting the row (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:334-341`, `:380`). Scrutiny point 10 in the review brief explicitly requires this endpoint path coverage.

---

## Verified Clean Checks

- **Two-stage rejection model:** Clean. The controller builds every DTO before any ingest call (`FiscalEventIngestionController.php:70-89`), then checks every envelope tenant (`:91-105`), and only then ingests (`:115-118`). If the first envelope is malformed, pre-flight 422 fires and zero rows are persisted.
- **Middleware tuple:** Clean. Fiscal route middleware at `apps/api/app/Modules/Fiscal/routes.php:24-27` byte-matches the POS route tuple at `apps/api/app/Modules/POS/routes.php:30` and `apps/api/app/Modules/POS/routes_orders.php:15`.
- **`mergeOuterIntoPayload`:** Clean. The FormRequest rejects null/scalar/empty payload before the controller (`IngestFiscalEventsRequest.php:45-51`); outer wrapper keys overwrite conflicting inner keys at `FiscalEventIngestionController.php:134-143`.
- **`$request->user()` under `auth:sanctum`:** Clean. `sanctum` resolves `App\Modules\Identity\Domain\User` via the `users` provider (`config/auth.php:41-49`, `:73-77`). The defensive 401 fallback is PHPStan-required but structurally correct.
- **CI filter:** Clean. `FiscalEventIngestionEndpointTest` is correctly placed in the PG-merge-gate regex at `.github/workflows/ci.yml:349-366`.
- **Membership role enum:** Clean. `role` is fillable and cast to `MembershipRole` enum (`UserCompanyMembership.php:51-77`); `MembershipRole::Admin` backs to the string `'admin'` (`MembershipRole.php:7-15`). The test `setUp` call is safe.
- **`InvalidArgumentException` catch reachability:** Clean. `OutboxIngestor::ingest()` throws `InvalidArgumentException` only from `FiscalEventEnvelope::fromArray()` / `assertWireShape()`, which runs inside the pre-flight DTO-build loop, not in the ingest loop. The catch in the ingest loop is unreachable dead code — but this is the same pattern as Task 19 F3 and has been accepted as belt-and-suspenders defensive wrapping in prior reviews. Not re-raising as a BLOCKER.
- **FormRequest `array` rule:** Clean. Laravel's `validateArray` returns false for `null` and scalars; combined with `required`, `null` is rejected before array validation runs.
