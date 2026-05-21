# Task 25 — Opus Adversarial Review

**Subject:** `7cccfce8b` on `feat/pos-fiscal-event-engine-phase1` (worktree `apps/erp.fiscal-phase1`).
**Scope:** `ClockAnomalyDetector.php` (new, 174 LOC) + `TerminalFiscalConfig.php` (new, 64 LOC) + `OutboxIngestor.php` (modified — constructor injection of detector, `verifyClock()` delegation) + `ClockAnomalyDetectorTest.php` (new, 374 LOC / 25 tests) + `ChainRecoveryService.ts` (new, 208 LOC) + `FiscalEventEngine.ts` (modified — single keyword change, `export interface SqlSurface`) + `ChainRecoveryService.test.ts` (new, 359 LOC / 8 tests).

## Verdict: APPROVE-WITH-MINOR-EDITS

Spec compliance is structurally honored on both §9 (chain-recovery) and §10 (clock / time model). `ClockAnomalyDetector::isWithinTolerance()` implements both §10 branches — rollback (`$eventTime->lessThan($priorTime)`) and excessive-drift (`abs($serverReceivedAt - $eventTime) > config('fiscal.clock_drift_limit_seconds')`) — at the documented `time_anomaly` admissibility surface, with the boundary treated as inclusive (`drift <= limit` admissible per the `test_drift_exactly_at_tolerance_is_admissible` test pin). `ClockAnomalyDetector::businessDateFor()` correctly assigns `business_date` from `(timezone, sessionBoundaryHour)` of `TerminalFiscalConfig` alone — never `server_received_at`, never raw device time — by parsing the device timestamp as UTC, rotating to the IANA zone via `CarbonImmutable::setTimezone($iana)` (DST-correct), then rolling back one local calendar day when `$local->hour < $config->sessionBoundaryHour`. The §10 last-sentence rule ("a clock anomaly never moves an event between closure periods without an explicit correction event") is structurally satisfied: the business_date function takes NO clock-anomaly-derived input — it consumes the device timestamp directly, and the spec's clock-anomaly path is the orthogonal `isWithinTolerance()` surface.

`ChainRecoveryService.recordBreakAndRestart()` appends `CHAIN_BREAK_DETECTED` then `CHAIN_RESTART` strictly in that order, both as ordinary `fiscal_events` rows through `engine.append()` — same insert path / chain head advance / `previous_hash`-`current_hash` linkage as a `SALE_RECEIPT`. The "broken segment is never deleted" invariant is verifiable: the test at `ChainRecoveryService.test.ts:210-256` primes the chain with two prior `SALE_RECEIPT` rows, captures their ids, runs recovery, then asserts both prior ids still present after recovery (line 252-255). `CHAIN_RESTART.provenance_link.chain_break_event_id` is set to the just-appended `CHAIN_BREAK_DETECTED.id` (line 204), giving the verifier a forensic anchor from each restart back to its specific break event. Cross-language drift gate: the TS payload key sets in `buildBreakPayload()` (lines 165-170) + `buildRestartPayload()` (lines 196-207) mirror `FiscalPayloadConstraintValidator::PAYLOAD_KEYS['CHAIN_BREAK_DETECTED']` / `['CHAIN_RESTART']` (lines 74-81) exactly — `reason / last_good_sequence / last_good_hash / offending_record_reference` and `new_genesis_reference / last_good_anchor / operator_authorization_evidence / provenance_link`.

The cross-task touch on `OutboxIngestor` is a clean delegation. The constructor (line 119-132) adds `ClockAnomalyDetector` as the fifth required (non-nullable, no default) parameter — CLAUDE.md rule 13 + Task 24 round-3 lesson ("never add nullable defaults to constructor-injected services") observed. `verifyClock()` (line 499-556) calls `clockAnomalyDetector->isWithinTolerance()` and, on a `false` return, locally reconstructs the structured reason string in the same format the verifier + the 20 existing `OutboxIngestorTest` cases expect (`time_anomaly:rollback,prior=...,current=...` or `time_anomaly:excessive_drift,drift_seconds=...,limit=...`). The branch ordering (rollback first, then drift fallthrough) preserves the priority semantics from the pre-extraction inline implementation. All 20 `OutboxIngestorTest` cases stayed green — including `test_clock_drift_limit_is_config_readable` (T19-P1) and the rollback-detection cases.

The `FiscalEventEngine.ts` edit is a single-keyword change (`interface SqlSurface` → `export interface SqlSurface`) — strictly additive, breaks no existing consumer.

Verification commands all green:
- 25/25 `ClockAnomalyDetectorTest` (PHP).
- 20/20 `OutboxIngestorTest` regression (cross-task, 100 assertions).
- 201/201 full `tests/Feature/Fiscal/` (37 skipped — pre-existing PG-merge-gate filter).
- 127/127 full `apps/pos/src/lib/fiscal/` vitest (12 files).
- PHPStan level 8 clean on the three implementer-listed files **and** on the full `app/Modules/Fiscal` namespace (implementer claimed "15 pre-existing namespace errors" — the namespace is actually clean, so the contract was over-cautious).
- TS `ChainRecoveryService.test.ts` 8/8 green via real v37 migrations through `SqliteTestAdapter` (not a mock surface).

However, three material findings land — one is a real contract gap (P1) on `TerminalFiscalConfig.timezone` validation accepting bare offsets (which the DTO docblock explicitly says it rejects), one is a real coupling fragility (P2) on the duplicated `clockDriftLimitSeconds()` default constant on both sides of the extraction, and one is the implementer-flagged forensic-honesty concern (P2) on the `kind: 'unknown_offender'` synthetic placeholder. None rises to BLOCKER.

Also two P3s (a stale docblock referencing `setTestNow` that no test uses; the OutboxIngestor's local `clockDriftLimitSeconds()` is now duplicate-implementation that survives only for reason-string format).

CLEAN on standing-pattern compliance for the in-scope surface: constructor injection throughout (`ClockAnomalyDetector` has zero deps; `OutboxIngestor` takes it as a `private readonly` 5th param; `ChainRecoveryService` takes the engine as a `private readonly` only param — no `app()` helper anywhere); strict typing (no `mixed` in PHP, no `any` in TS — `TerminalFiscalConfig` is a `final readonly` typed value-object; PHP DTOs round-trip via `fromArray` + `toArray`; TS payload builders return `Record<string, unknown>`); enum-backed (`IntegrityExceptionClass::TimeAnomaly` reused, no magic string introduced); DST-correctness exercised in 2 test cases (Paris forward + back); discriminated test matrix across the §10 surface (happy / no-prior / rollback-by-six-years / rollback-by-1s / equal-to-prior / future-drift / past-drift / exactly-at-tolerance / one-second-over / config-readable / malformed-device / malformed-prior); the `Carbon::setTestNow` try/finally standing pattern (Task 23 R3-F2) is documented in the test docblock but doesn't apply — no test uses `setTestNow`, so the gate is vacuous (see P3 #2); the CI PG-merge-gate filter was correctly NOT extended (the new test is pure-Unit, no DB).

## Findings summary

| # | Sev | File:Line | One-liner |
|---|---|---|---|
| F1 | P1 | `TerminalFiscalConfig.php:48-56` + `ClockAnomalyDetector.php:56-58` | `TerminalFiscalConfig.timezone` validation accepts bare UTC offsets (`+02:00`) — the DTO docblock at line 32-34 explicitly says these are rejected because they cannot resolve DST. PHP's `new DateTimeZone('+02:00')` does NOT throw. Construction passes; `businessDateFor()` runs with a DST-blind offset. Contract-vs-implementation drift on the same file. |
| F2 | P2 | `OutboxIngestor.php:548-555 + 568-573` + `ClockAnomalyDetector.php:68 + 168-173` | The drift-limit default (`24 * 60 * 60`) is now hardcoded TWICE — `OutboxIngestor::clockDriftLimitSeconds()` and `ClockAnomalyDetector::clockDriftLimitSeconds()` + `DEFAULT_CLOCK_DRIFT_LIMIT_SECONDS`. They both read the same config key with the same default, but if a future refactor changes ONE default, the OutboxIngestor's `time_anomaly:excessive_drift,...,limit=Y` reason will misreport the value the detector actually used. Single source of truth violated by the extraction. |
| F3 | P2 | `ChainRecoveryService.ts:154-163` + `ChainRecoveryService.test.ts:336-341` | The "unknown offender" path manufactures a `{kind: 'unknown_offender', terminal_id}` synthetic payload to satisfy the PHP `validateNonEmptyAssoc('offending_record_reference')` constraint. This puts forensic data into the immutable chain that did not come from the device's observation — an honesty hazard for the verifier / JET export readers who cannot distinguish "we don't know the offender" from "the offender is the terminal itself". Implementer flagged in premise audit #5; no fix shipped. |
| F4 | P3 | `ClockAnomalyDetectorTest.php:28-32` | Docblock says "All time-sensitive tests wrap `CarbonImmutable::setTestNow` in try/finally"; no test in the file calls `setTestNow`. Stale comment from the standing-pattern checklist — harmless but misleading for future implementers extending the file. |
| F5 | P3 | `OutboxIngestor.php:568-573` | `OutboxIngestor::clockDriftLimitSeconds()` survives only to build the local reason string in `verifyClock()`. After Task 25's extraction, the method is dead-code-shaped — every other consumer goes through the detector. A future cleanup could either remove the local method (have the detector return the structured reason) or expose the limit as a public getter on the detector. |

---

### F1 — P1 — `TerminalFiscalConfig.timezone` validation accepts bare UTC offsets despite docblock claim to the contrary

**Files.** `apps/api/app/Modules/Fiscal/Domain/DTOs/TerminalFiscalConfig.php:30-34, 48-56`; `apps/api/app/Modules/Fiscal/Application/Services/ClockAnomalyDetector.php:56-58, 144-145`.

**Observation.** `TerminalFiscalConfig` docblock at lines 30-37 commits to a normative contract:

```php
 * **Construction invariants:**
 *   - `$timezone` must be a non-empty string parseable as an IANA tz
 *     identifier (DST-aware). Bare offsets like `+02:00` are NOT accepted
 *     because they cannot resolve DST transitions, which §10 must handle.
```

The implementation at lines 48-56:

```php
// Validate IANA-parseable. DateTimeZone throws on an unknown id.
try {
    new \DateTimeZone($timezone);
} catch (\Throwable $e) {
    throw new InvalidArgumentException(
        'TerminalFiscalConfig.timezone is not a valid IANA timezone: '.var_export($timezone, true),
        previous: $e,
    );
}
```

Empirically:

```text
$ php -r "try { new DateTimeZone('+02:00'); echo 'offset accepted (bad!)'; } catch (Throwable $e) { echo 'rejected (good): ' . $e->getMessage(); }"
offset accepted (bad!)
```

PHP's `DateTimeZone` constructor accepts bare offsets, abbreviations (`'CEST'`), and a few sentinel forms (`'UTC'`). The DTO ships the IANA-only promise but the validation only catches truly-bogus strings (`'Not/A_Real_Zone'`). The `ClockAnomalyDetectorTest` test matrix exercises `'Africa/Tunis'`, `'Europe/Paris'`, `'UTC'`, and `'Not/A_Real_Zone'` (the rejected case) — never a `'+02:00'` bare-offset. The contract gap survives the test pass.

**Why it's a P1, not a P2.** Spec §10 normative-text last sentence: "a clock anomaly never moves an event between closure periods without an explicit correction event." DST is the routine, non-anomaly case where `business_date` MUST roll on the IANA rule, not a fixed offset. A terminal accidentally configured with `'+02:00'` (e.g. a deployment script that emitted the current local offset instead of the IANA id) would silently fail DST. The `businessDateFor()` call at line 145 (`$local = $eventTime->setTimezone($config->timezone)`) would apply a fixed UTC+02:00 year-round — quietly producing wrong `business_date` values for half the year in any DST jurisdiction. This is exactly the failure mode the docblock promises to prevent.

The defect is two-sided: (a) the contract is undefended, and (b) the test matrix exercises four positive zones and one negative bogus zone but no bare-offset case, so the gap is invisible from test coverage. Either the docblock claim must be tightened (drop the "NOT accepted" sentence and explicitly admit "any string `DateTimeZone::__construct` accepts is permitted, including bare offsets, which the operator is responsible for not configuring") or the validation must actually enforce it.

**Recommendation (round-2):** Replace the lone `new DateTimeZone($timezone)` defense with an `in_array($timezone, DateTimeZone::listIdentifiers(), strict: true)` check (or equivalent — the IANA timezone list is bounded, ~600 entries, and the comparison is O(log n) under PHP's hash-table set membership). Add a test:

```php
public function test_terminal_fiscal_config_rejects_bare_utc_offset(): void
{
    $this->expectException(InvalidArgumentException::class);
    new TerminalFiscalConfig(timezone: '+02:00', sessionBoundaryHour: 0);
}
```

Plus a partner test for `'CEST'` (an abbreviation `DateTimeZone` also accepts).

---

### F2 — P2 — Drift-limit default duplicated on both sides of the extraction, creating a silent-misreport hazard

**Files.** `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:548-555, 568-573`; `apps/api/app/Modules/Fiscal/Application/Services/ClockAnomalyDetector.php:62-68, 168-173`.

**Observation.** `ClockAnomalyDetector` declares (lines 62-68):

```php
private const DEFAULT_CLOCK_DRIFT_LIMIT_SECONDS = 24 * 60 * 60;
```

and (lines 168-173):

```php
private function clockDriftLimitSeconds(): int
{
    $configured = config('fiscal.clock_drift_limit_seconds', self::DEFAULT_CLOCK_DRIFT_LIMIT_SECONDS);

    return is_int($configured) ? $configured : (int) $configured;
}
```

`OutboxIngestor` STILL carries its own copy (lines 568-573):

```php
private function clockDriftLimitSeconds(): int
{
    $configured = config('fiscal.clock_drift_limit_seconds', 24 * 60 * 60);

    return is_int($configured) ? $configured : (int) $configured;
}
```

— used at line 548 to format the `time_anomaly:excessive_drift,...,limit=%d` reason string.

The values match TODAY because both default to 86400 and both read the same config key. The hazard is the future-state: if a maintainer changes `OutboxIngestor`'s default to (say) `60 * 60` to match a per-vertical hardening, the detector's `isWithinTolerance()` would still admit at 86400 while the reason string the ingestor logs would say `limit=3600`. The forensic record would lie about the actually-applied threshold.

**Why it's a P2, not a P1.** The two methods coincidentally agree today and the test pass confirms it. There is no current correctness bug. The fragility is the kind that doesn't break a release but breaks the FOURTH release after a maintainer touches one side. The fact that the OutboxIngestor docblock at line 564 admits this ("Task 25 — also read by `ClockAnomalyDetector` directly; kept here for the local reason-string reconstruction in `verifyClock()` (the format includes the limit value for forensic clarity)") shows the implementer was aware of the duplication and chose to leave it. The cleanest fix is to either expose a `public function clockDriftLimitSeconds(): int` on the detector (which the OutboxIngestor delegates to) OR have the detector return a structured result (`{within: bool, drift_seconds: int, limit_seconds: int, reason_kind: 'rollback' | 'drift' | null}`) and let the OutboxIngestor format the reason string from typed data. The latter also closes F5.

**Recommendation (round-2):** Promote `ClockAnomalyDetector::clockDriftLimitSeconds()` to `public` and have `OutboxIngestor::verifyClock()` read `$this->clockAnomalyDetector->clockDriftLimitSeconds()` (4 lines net). Delete `OutboxIngestor::clockDriftLimitSeconds()` and `OutboxIngestor::DEFAULT_*` if any. Single source of truth restored.

---

### F3 — P2 — `{kind: 'unknown_offender', terminal_id}` synthetic placeholder puts manufactured forensic context into the immutable chain

**Files.** `apps/pos/src/lib/fiscal/ChainRecoveryService.ts:154-163`; `apps/pos/src/lib/fiscal/__tests__/ChainRecoveryService.test.ts:323-342`.

**Observation.** `buildBreakPayload` (lines 154-163):

```ts
function buildBreakPayload(request: ChainBreakAndRestartRequest): Record<string, unknown> {
  const offending: Record<string, unknown> = request.offending_reference
    ? {
        offending_reference: request.offending_reference,
        terminal_id: request.terminal_id,
      }
    : {
        kind: 'unknown_offender',
        terminal_id: request.terminal_id,
      };
```

The PHP `FiscalPayloadConstraintValidator::validateChainBreakDetectedPayload()` at line 173 requires `offending_record_reference` to be a non-empty assoc:

```php
$this->validateNonEmptyAssoc($payload, 'offending_record_reference');
```

When `request.offending_reference` is not supplied (per the omits-offending_reference test at line 323), the service synthesizes `{kind: 'unknown_offender', terminal_id}` to satisfy the non-empty requirement. The test at lines 336-341 asserts the container is "defined" and "has > 0 keys" — passing the validator gate while making no claim about WHAT the keys are.

**Why it's a P2.** Spec §9 defines `offending_record_reference` as "the reference of the offending row". The synthetic `{kind: 'unknown_offender', terminal_id}` is forensic noise — it's the terminal-emitting-the-break referring to itself by id. Any JET export consumer or forensic auditor reading the immutable chain cannot distinguish two scenarios:

1. The break was detected by a structural check (e.g. a missing row at the expected `sequence_number + 1`) where no offending ROW exists.
2. The break was actually caused by something on this terminal — `terminal_id` is recorded as the offender.

Both produce the same canonical bytes. The implementer flagged this in premise audit #5 ("even when `offending_reference` is omitted (we emit `{kind: 'unknown_offender', terminal_id}` rather than failing)"). The choice is reasonable (recovery must not fail because the offender is unknown) but the wire format conflates two distinct realities. The CHAIN_BREAK_DETECTED row is IMMUTABLE — once written, it cannot be corrected.

A cleaner shape: emit `{kind: 'unknown_offender', detected_by: 'structural_check'}` (NO `terminal_id` re-emission — the surrounding event row already carries `terminal_id`) or `{kind: 'unknown_offender', context: 'sequence_gap_at_sync', detected_during: 'verifier_pass'}`. Either makes the "no real offender" status legible from the canonical bytes alone.

Alternatively — reject the recovery emission when `offending_reference` is omitted, forcing the caller to supply *something* identifying. The Phase 1 callers are §15 verifier output + local structural checks; both can supply at least a `kind` discriminator and a structural-context hint.

**Why it's a P2, not a P1.** The defect-shape is "ambiguous forensic record", not "wrong forensic record". A future verifier extension can pattern-match `kind === 'unknown_offender'` to surface the ambiguity in the JET export. But the data is in the immutable chain and the schema convention for these synthetic shapes will propagate to every CHAIN_BREAK_DETECTED row emitted in Phase 1 — a forensic-debt accrual that gets harder to fix per row that lands.

**Recommendation (round-2):** Either (a) drop `terminal_id` from the unknown-offender shape (it's already on the parent event row) and add an explicit `detected_by` discriminator the verifier can pattern-match; or (b) make `offending_reference` a required field on `ChainBreakAndRestartRequest` so the caller is forced to supply at least a structural-context object — pushing the ambiguity to the call site where the surrounding code knows what triggered the break.

---

### F4 — P3 — Stale docblock claim that no test exercises

**File.** `apps/api/tests/Unit/Fiscal/ClockAnomalyDetectorTest.php:28-32`.

**Observation.** Test class docblock (lines 28-32):

```php
 * All time-sensitive tests wrap `CarbonImmutable::setTestNow` in
 * try/finally so a failure mid-suite cannot leak a frozen clock into
 * neighbouring tests (Task 23 R3-F2 standing pattern).
```

Grep:

```text
$ grep -n "setTestNow" tests/Unit/Fiscal/ClockAnomalyDetectorTest.php
(no matches — only the comment itself)
```

No test in the file calls `setTestNow`. The standing-pattern reference is correct as a hygiene rule, but vacuously applied here — the detector is pure functions over caller-supplied `CarbonImmutable` instances, so frozen-clock leakage isn't even a hazard.

**Recommendation.** Drop the sentence; it's noise. If the pattern is preserved as a "future implementers should remember to..." cue, reword: "Tests pass explicit `CarbonImmutable::parse(...)` instants rather than relying on `setTestNow` — if future cases use `setTestNow`, wrap in try/finally per Task 23 R3-F2."

---

### F5 — P3 — `OutboxIngestor::clockDriftLimitSeconds()` is dead-code-shaped after extraction

**File.** `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:548-555, 568-573`.

**Observation.** After Task 25's extraction, the only remaining call site of `OutboxIngestor::clockDriftLimitSeconds()` (line 568) is line 548, inside `verifyClock()`, for the reason-string reconstruction:

```php
$limit = $this->clockDriftLimitSeconds();
$drift = abs($serverReceivedAt->getTimestamp() - $eventTime->getTimestamp());

return sprintf(
    'time_anomaly:excessive_drift,drift_seconds=%d,limit=%d',
    $drift,
    $limit,
);
```

Every other consumer of "what's the drift limit?" goes through `ClockAnomalyDetector::clockDriftLimitSeconds()`. The OutboxIngestor's local copy is dead-shape (kept alive only for the reason-string format) and duplicates the constant (F2).

**Recommendation.** Tied to F2's recommendation: promote the detector's method to `public`, have the ingestor delegate, delete the local copy. Or refactor the detector to return a structured result that includes the limit value (and the rollback/drift discriminator) so the OutboxIngestor doesn't need to RE-derive any of it.

---

## Premise audit — implementer's 6 flagged premises

| # | Implementer claim | Verification result |
|---|---|---|
| 1 | Constructor injection observed on `OutboxIngestor` (5th param, non-nullable, no default) | **VERIFIED.** `OutboxIngestor.php:119-132` — `private readonly ClockAnomalyDetector $clockAnomalyDetector`, no default. Laravel container auto-resolves (detector has zero constructor deps). |
| 2 | All 20 `OutboxIngestorTest` cases stayed green | **VERIFIED.** `./vendor/bin/phpunit tests/Feature/Fiscal/OutboxIngestorTest.php` → `OK (20 tests, 100 assertions)`. |
| 3 | The verifier owns the reason-string format; the detector only returns a bool | **VERIFIED.** `ClockAnomalyDetector::isWithinTolerance()` returns `bool`; `OutboxIngestor::verifyClock()` lines 521-555 own the structured reason. Branch ordering (rollback first, then drift fallthrough) preserves pre-extraction priority. |
| 4 | DST fall-back test correction (Paris 2026-10-25 02:30 UTC) | **VERIFIED.** Hand-compute: UTC fall-back happens at 01:00:00; 02:30 UTC is post-fallback → CET (+01:00) → local 03:30 → hour 3 < sessionBoundaryHour 4 → business_date = previous day = `'2026-10-24'`. Matches the test at line 332. The 00:30 UTC case (pre-fallback, CEST +02:00 → local 02:30 → hour 2 < 4 → `'2026-10-24'`) and 03:30 UTC case (post-fallback, CET +01:00 → local 04:30 → hour 4 ≥ 4 → `'2026-10-25'`) also hand-check. |
| 5 | Stash incident: post-pop state is what's committed | **VERIFIED.** `git stash list` shows 5 stashes, top is `On feat/pos-fiscal-event-engine-phase1: interleave-rescue-2026-05-16` — unrelated work from earlier. `git status` reports clean working tree on `feat/pos-fiscal-event-engine-phase1`. The Task 25 commit `7cccfce8b` is the head and is fully formed (7 files, 1243 insertions). |
| 6 | `new_genesis_reference` = the just-appended CHAIN_BREAK_DETECTED's `current_hash` — reasonable; no circular dependency | **VERIFIED with reservation.** `ChainRecoveryService.ts:197` — `new_genesis_reference: breakResult.current_hash`. No circular dependency: the BREAK row's `current_hash` is computed and persisted BEFORE the RESTART row is built (sequential `await` calls; the BREAK insert is final before the RESTART begins). The RESTART row's `previous_hash` is also `breakResult.current_hash` (via the chain head advance) — so `new_genesis_reference == previous_hash` for the RESTART row. This is structurally consistent (the genesis reference IS the prior anchor) and the verifier can detect tampering on either side by comparing them. The reservation: spec §9 calls `new_genesis_reference` "the new genesis reference" without prescribing derivation. The implementer's choice is sound for Phase 1 (a future signature-provider-attested anchor would replace it). Document the choice in the handoff §4 deferred-items list so the §15 verifier author knows to assert the equality. |

---

## Closure verification — spec contracts vs implementation

| Spec contract (§) | Satisfied? | Evidence |
|---|---|---|
| §10 `isWithinTolerance` returns false on rollback (deviceTime < lastServerTimeSeen) | YES | `ClockAnomalyDetector.php:98` — `$eventTime->lessThan($priorTime)` returns false; test pin `test_clock_rollback_is_flagged_time_anomaly` (line 82) + `test_rollback_one_second_behind_prior_is_still_a_rollback` (line 92) + `test_equal_to_prior_is_not_a_rollback` (line 103 — boundary). |
| §10 `isWithinTolerance` returns false on excessive drift (|deviceTime - serverReceivedAt| > config) | YES | `ClockAnomalyDetector.php:104-106` — `abs(...) > $limit` returns false; test pins `test_excessive_drift_future_is_flagged` (line 119), `test_excessive_drift_past_is_flagged` (line 133), `test_drift_exactly_at_tolerance_is_admissible` (line 145 — boundary), `test_drift_one_second_over_tolerance_is_flagged` (line 160), `test_drift_limit_is_config_readable` (line 174). |
| §10 `businessDateFor` reads ONLY `deviceTime` + `terminalConfig`, never `serverReceivedAt` | YES | `ClockAnomalyDetector.php:133-152` — signature `businessDateFor(string $deviceTime, TerminalFiscalConfig $config): string`; no `serverReceivedAt` parameter, no `Carbon::now()` call, no `config('app.timezone')` read. Body is purely a function of `(deviceTime, $config->timezone, $config->sessionBoundaryHour)`. |
| §10 clock anomaly never moves an event between closure periods | YES (by construction) | `businessDateFor()` has no `isWithinTolerance` dependency and no "anomaly correction" branch. The clock-anomaly path (`isWithinTolerance` → `time_anomaly` integrity_exception_class) is orthogonal: it flags the row but does NOT recompute `business_date`. Since `business_date` is derived from `deviceTime` alone, an anomaly cannot move it. |
| §10 `time_anomaly` is the IntegrityExceptionClass on `isWithinTolerance == false` | YES | `OutboxIngestor.php:611` — `$clockVerdict !== null => IntegrityExceptionClass::TimeAnomaly`; enum case at `IntegrityExceptionClass.php:11` — `case TimeAnomaly = 'time_anomaly';`. |
| §9 CHAIN_BREAK_DETECTED + CHAIN_RESTART are ordinary `fiscal_events` rows | YES | TS test `both events are ordinary fiscal_events rows (same schema as SALE_RECEIPT)` at `ChainRecoveryService.test.ts:185-197` queries `fiscal_events` directly and asserts the same schema fields (`sync_status='pending'`, `signature_status='not_required'`, 64-hex `previous_hash`/`current_hash`, non-empty `canonical_bytes`). |
| §9 emission order: BREAK before RESTART | YES | `ChainRecoveryService.ts:111-131` — sequential `await engine.append(BREAK)` then `await engine.append(RESTART)`. Test `on a local chain break emits CHAIN_BREAK_DETECTED then CHAIN_RESTART as ordinary fiscal_events` (line 176-183) asserts `event_type` array `['CHAIN_BREAK_DETECTED', 'CHAIN_RESTART']` and sequence numbers `[1, 2]`. |
| §9 broken segment is never deleted | YES | Test `the broken segment is never deleted — prior fiscal_events rows still present after recovery` (line 210-256) primes 2 SALE_RECEIPT rows, captures their ids, runs recovery, asserts both prior ids in the post-recovery row list. `ChainRecoveryService` writes via `engine.append()` only — no DELETE path exists in the service. |
| §9 both events emit via `FiscalEventEngine.append()` (not a separate authoring path) | YES | `ChainRecoveryService.ts:111, 122` — both calls go through `this.engine.append(tx, {...})`. The same chain-head-advance + canonical-byte encoding + hash linkage path as every other event. |
| §9 terminal continues in `degraded` mode (recorded in `terminal_state`) | NOT IN SCOPE | `ChainRecoveryService.ts:28-30` docblock explicitly defers this: "it does NOT manage the `degraded` flag on `terminal_state` (that flag is owned by the caller transaction)". The service is intentionally thin. Spec §9 mentions degraded mode but doesn't bind it to this service. Acceptable Phase-1 boundary. |
| Cross-language drift gate: TS payload DTOs mirror PHP `PAYLOAD_KEYS` | YES | TS `buildBreakPayload` emits `{reason, last_good_sequence, last_good_hash, offending_record_reference}` (4 keys); PHP `PAYLOAD_KEYS['CHAIN_BREAK_DETECTED']` is `{last_good_hash, last_good_sequence, offending_record_reference, reason}` (4 keys, same set). TS `buildRestartPayload` emits `{new_genesis_reference, last_good_anchor, operator_authorization_evidence, provenance_link}` (4 keys); PHP `PAYLOAD_KEYS['CHAIN_RESTART']` is `{last_good_anchor, new_genesis_reference, operator_authorization_evidence, provenance_link}` (4 keys, same set). |

---

## Summary

Task 25 ships a clean structural realization of spec §9 (chain recovery) and §10 (clock / time model). All 33 in-scope tests + 20 cross-task regression tests + the broader 201 fiscal-feature + 127 pos-fiscal vitest suites are green; PHPStan level 8 is clean on the whole Fiscal namespace (the implementer's caution about "15 pre-existing namespace errors" turns out to be over-conservative — the namespace passes). The §10 normative rule that "a clock anomaly never moves an event between closure periods without an explicit correction event" is satisfied by construction (`businessDateFor()` takes no `serverReceivedAt` and no anomaly-derived input). The §9 invariants — ordinary `fiscal_events` rows, emission order, broken-segment preservation, provenance-link from RESTART back to BREAK — all have direct test pins via real v37-migration `SqliteTestAdapter` (not a mock surface). The cross-task touch on `OutboxIngestor` is a clean dependency-injection delegation that preserves the reason-string format the verifier reads back. The main correctness concern is F1 (P1): `TerminalFiscalConfig.timezone` validation accepts bare UTC offsets despite the DTO docblock explicitly committing to IANA-only — a contract gap the test matrix doesn't exercise that could silently produce wrong `business_date` values for half the year in any DST jurisdiction with a misconfigured terminal. The two P2s (drift-limit default duplication on both sides of the extraction; the `kind: 'unknown_offender'` synthetic placeholder writing manufactured forensic context into the immutable chain) are forensic-honesty and single-source-of-truth concerns rather than current correctness defects. Round-2 to fix F1, optionally consolidate F2+F5 with a public detector getter, and tighten F3's synthetic shape.
