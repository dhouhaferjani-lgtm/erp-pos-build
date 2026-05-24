# Task 20 — Opus Adversarial Review

**Subject:** `841c3d781` on `feat/pos-fiscal-event-engine-phase1` (worktree `apps/erp.fiscal-phase1`).
**Scope:** `POST /api/v1/pos/sync/fiscal-events` — controller + FormRequest + route file + provider boot wiring + Feature test + CI PG-gate filter extension.

## Verdict: APPROVE-WITH-MINOR-EDITS

Plan §1484 contract met. Middleware tuple verbatim-matches `apps/api/app/Modules/POS/routes.php:30`. Two-stage pre-flight (typed-DTO construction → tenant boundary → per-envelope ingest) executes in the correct order; the controller iterates `ALL envelopes through fromArray() first`, `THEN ALL tenant checks`, `THEN ingest`. Cross-tenant in a mixed batch yields zero rows in both `fiscal_events` AND `fiscal_event_quarantine` (verified). Verify-stack lights: phpunit `7/7`, phpstan level-8 clean, pint clean. No standing code-smell pattern triggered.

The findings below are coverage gaps, not defects. No BLOCKER. No P1.

## Findings

| # | Sev | File:Line | One-liner |
|---|---|---|---|
| F1 | P2 | tests/Feature/Fiscal/FiscalEventIngestionEndpointTest.php | Idempotent re-delivery wire-response is not test-pinned at the endpoint. |
| F2 | P2 | tests/Feature/Fiscal/FiscalEventIngestionEndpointTest.php | Quarantined-in-table wire-response (`IngestionResult::quarantined`) is not test-pinned at the endpoint. |
| F3 | P3 | tests/Feature/Fiscal/FiscalEventIngestionEndpointTest.php | Combined cross-tenant + malformed-UUID ordering is not pinned (which stage wins). |
| F4 | P3 | apps/api/app/Modules/Fiscal/Presentation/Controllers/FiscalEventIngestionController.php:107-118 | Class-level docstring promises an InvalidArgumentException catch around `ingest()` that does not exist in the method body — wording leftover from a removed branch. |
| F5 | P3 | apps/api/app/Modules/Fiscal/Presentation/Requests/IngestFiscalEventsRequest.php:45 | `envelopes.*.max:100` is undocumented in the spec — fine as a safety cap but worth tracing back to a source. |

---

### F1 — P2 — Idempotent re-delivery is not test-pinned

**File:** `apps/api/tests/Feature/Fiscal/FiscalEventIngestionEndpointTest.php`

**Observation.** The endpoint surface exposes four `IngestionResult` discriminations:
`stored` (lines 47-55 of `IngestionResult.php`), `quarantined` (61-70), `idempotent` (75-83), `sequenceConflict` (88-96). The Task 20 test pins `stored=true` (test_endpoint_ingests_a_valid_fiscal_event_envelope) and `sequence_conflict=true` (test_endpoint_returns_per_envelope_sequence_conflict). It does **not** pin the third public outcome: identical-envelope re-delivery, where the wire response is `{ stored: false, fiscal_event_id: <existing-id>, sequence_conflict: false, exception_class: null }`. This is the §7.2 Step 4 happy idempotency contract — the device-retry-after-network-hiccup scenario. If a regression flipped `idempotent()` to `sequenceConflict()` (or vice versa), no Task 20 test would catch it; the call would still reach `OutboxIngestor` and the OutboxIngestor tests would still pass.

**Remediation.** Add one test that POSTs the same envelope twice (same `id`, same `current_hash`, same `canonical_bytes`) and asserts `results.0.stored=false, results.0.fiscal_event_id=<the first call's id>, results.0.sequence_conflict=false, results.0.exception_class=null`. Helper exists (`validEnvelopeWire()` already produces consistent canonical bytes for identical inputs).

**Reference.** Spec v7 §7.2 line 367 ("duplicate success; do NOT re-dispatch projection"); `IngestionResult::idempotent()` is the documented public factory.

---

### F2 — P2 — Quarantined-in-table response is not test-pinned

**File:** `apps/api/tests/Feature/Fiscal/FiscalEventIngestionEndpointTest.php`

**Observation.** When `OutboxIngestor` admits a row to `fiscal_events` but flags it (e.g. `canonical_hash_mismatch`, `time_anomaly`, `sequence_gap`, `canonical_parse_failure`), `IngestionResult::quarantined()` returns `stored=true, fiscal_event_id=non-null, exception_class=<class>`. The controller serialises this through `resultToWire()`. The Task 20 test does not exercise this combination — none of the 7 tests produce a `stored=true AND exception_class!=null` row. The `exception_class` field's enum-to-string conversion (`$result->exceptionClass?->value`) is therefore covered only on the `sequence_conflict` path where `stored=false`.

**Remediation.** Add one test that submits an envelope with `current_hash` tampered (set to something other than `hash('sha256', canonical_bytes)`) and asserts `results.0.stored=true, results.0.fiscal_event_id != null, results.0.exception_class='canonical_hash_mismatch'`. The fixture already passes the controller's `fromArray()` shape checks (the tampered hash is still 64-hex); the OutboxIngestor admits the row with `integrity_status=quarantined`.

**Reference.** `IngestionResult::quarantined()` (`apps/api/app/Modules/Fiscal/Application/DTOs/IngestionResult.php:62-70`); spec v7 §7.2 derive-integrity Step 1; the Task 19 OutboxIngestor test covers the ingestor's behaviour but not the wire serialisation.

---

### F3 — P3 — Combined cross-tenant + malformed-UUID ordering is not pinned

**File:** `apps/api/tests/Feature/Fiscal/FiscalEventIngestionEndpointTest.php`

**Observation.** The controller's docblock and code (`apps/api/app/Modules/Fiscal/Presentation/Controllers/FiscalEventIngestionController.php:70-105`) document Stage 1 (422 on malformed) → Stage 2 (403 on cross-tenant). If both fire on the same envelope (a malformed-UUID `tenant_id` that is also semantically cross-tenant), Stage 1 wins because `assertWireShape()` runs first inside `fromArray()`. The test suite has independent coverage of each stage but no combined case pinning the ordering. A future refactor that hoists tenant comparison into FormRequest validation (or `authorize()`) could flip the 422/403 priority unnoticed.

**Remediation.** Either add one test that submits an envelope with `tenant_id='not-a-uuid'` and asserts `422` (not 403), or document the ordering as deliberately unstable. The current behaviour is the more secure default (don't leak tenant-existence via a 403-vs-422 oracle), so pinning it is the right call.

**Reference.** Controller docblock §"Two-stage rejection model" (lines 25-34).

---

### F4 — P3 — Class-level docstring describes an exception catch that does not exist

**File:** `apps/api/app/Modules/Fiscal/Presentation/Controllers/FiscalEventIngestionController.php:107-118`

**Observation.** The comment block before the ingest loop ends with: "*The only InvalidArgumentException path here is defense-in-depth for envelopes whose malformed fields bypass the FormRequest + `fromArray()` regex (which they cannot, since `fromArray()` runs `assertWireShape()`).*" — but the actual `foreach` body has no `try/catch`. There is no defense-in-depth catch around `$this->ingestor->ingest($envelope)`; if `OutboxIngestor::ingest()` rethrows (T19-B2 source-event-id `QueryException`, or the `Throwable` fall-through at line 246-257 of `OutboxIngestor.php`) the exception propagates to Laravel's default handler as a 500.

This is functionally correct (the design IS to surface a 500 on a programming bug), but the comment misleads a future maintainer into thinking there's a guard. Either the comment should be re-worded to say "no catch is needed; the only `InvalidArgumentException` source was already drained by the Stage 1 `fromArray()` loop", or a true defense-in-depth `try/catch (\Throwable)` should wrap each ingest call. I prefer the comment rewrite — the current "let it 500" behaviour is the documented contract for source-event-id violations (`OutboxIngestor.php:222-240`).

**Remediation.** Trim the comment to: "Per-envelope ingest. The OutboxIngestor's verify-then-insert core (Task 19) reports every anomaly via `IngestionResult` without throwing under the controlled path. The only re-throw paths are (a) a `(source_event_class, source_event_id)` UNIQUE violation [T19-B2], and (b) the fall-through `Throwable` guard at the registry singleton — both surface as 500s and are device authoring bugs by construction, not chain anomalies. We deliberately do NOT catch them — pre-flight gate is the only all-or-nothing seam."

**Reference.** `OutboxIngestor.php` lines 222-240 (source-event-id rethrow) and 246-257 (Throwable rethrow).

---

### F5 — P3 — `envelopes.*.max:100` is undocumented

**File:** `apps/api/app/Modules/Fiscal/Presentation/Requests/IngestFiscalEventsRequest.php:45`

**Observation.** The FormRequest caps batch size at 100 envelopes (`'envelopes' => ['required', 'array', 'min:1', 'max:100']`). Neither spec v7 §7.1 nor plan §1484 names a batch ceiling. A device that batched offline for several days could hit this cap and start losing envelopes silently per request (no contract told the device 100 is the ceiling). The cap is reasonable for Phase 1 — it just isn't traceable to any source-of-truth document.

**Remediation.** Add a one-line comment in the FormRequest tying the `max:100` to a deliberate operator decision (or open a follow-up to document the device-side chunking contract). Phase 1 device batch size is small in practice, so this is forward-looking. No code change required if the limit is socialised in the next handoff refresh.

**Reference.** Spec v7 §7.1 (no batch-size statement); plan §1484 (no batch-size statement); standing pattern 4 ("wider-than-canonical accept grammar") cuts both ways — a `max` that's not in the contract is the inverse of widening but still drifts from the spec.

---

## Verification commands run

```
cd apps/erp.fiscal-phase1/apps/api
./vendor/bin/phpunit --filter=FiscalEventIngestionEndpointTest       # 7/7 OK, 30 assertions
./vendor/bin/phpstan analyse --level=8 <5 changed files>             # No errors
./vendor/bin/pint --test <changed files>                             # {"result":"pass"}
```

## Standing-pattern audit (handoff §4.2)

| Pattern | Status |
|---|---|
| 1. `(type) $array['key']` PHP casts | Clean. Reads go through `FiscalPayloadArrayGuards::require*`. |
| 2. `payload: unknown` TS seams | N/A (server-side task). |
| 3. Free-form string fields without regex | Clean. `fromArray()` invokes `assertWireShape()` which regex-validates hashes/UUIDs/timestamps. |
| 4. Wider-than-canonical parse grammar | Clean. FormRequest is narrow; `in:FISCAL_EVENT` is case-sensitive. |
| 5. `DTO::fromArray` as complete schema check | N/A (fromArray is correctly used as input boundary, parser handles canonical-bytes shape). |
| 6. Fail-closed on downstream-service exception | Partially exposed — see F4. The 500 on `OutboxIngestor::ingest()` rethrow IS the documented fail-closed for programming-bug paths. |
| 7. Boot-time invariants must be constructor-asserted | N/A — `FiscalServiceProvider::boot()` only adds `loadRoutesFrom`; no new invariants. |
| 8. DB primitives the spec names | N/A — Task 20 doesn't touch DB primitives. |
| 9. Multi-constraint ON CONFLICT targeting | N/A — Task 20 doesn't insert. |
| 10. Verify the premise of every deferral | Clean. No deferrals in this task. |
| 11. `Eloquent::find($uuid)` defensive wrap | N/A — controller uses typed DTOs. |

## Convergent checks

- Middleware tuple verbatim vs `apps/api/app/Modules/POS/routes.php:30` — verified char-for-char identical, same import paths.
- `FiscalServiceProvider::boot()` `loadRoutesFrom` mirrors `POSServiceProvider::boot():49` pattern.
- CI PG-merge-gate filter at `.github/workflows/ci.yml:366` correctly appends `FiscalEventIngestionEndpointTest` to the `|`-alternation regex; comment block extended with the rationale.
- `Sanctum::actingAs($this->user)` in tests bypasses `EnforceTokenTenantClaim` (it issues a `TransientToken`, not a `PersonalAccessToken`), so the cross-tenant tests prove the controller-layer envelope-vs-user check is the active gate — exactly what was intended.
- `mergeOuterIntoPayload()` is null-safe; missing outer keys flow through as `null`, are rejected by `FiscalPayloadArrayGuards::requireString`, and surface as 422 with envelope_index. Cannot bypass the typed boundary.
- Test fixture's `current_hash = hash('sha256', $canonicalBytes)` is round-trip identical to `HashChainIntegrityProvider::verify()` (`hash_equals(hash('sha256', $bytes), $hash)`). Test green confirms.
- `UserCompanyMembership::create` + `RolesAndPermissionsSeeder` + `Sanctum::actingAs` setup matches the OfflineV3CutoverSyncTest:559 precedent.

No regression of any prior-task BLOCKER detected. F1 and F2 should be addressed before merge (P2 — they widen the surface contract that isn't yet pinned anywhere). F3-F5 are nits.
