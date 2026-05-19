# Codex Review - Phase 1 Task 25 (`7cccfce8b`)

**Captured by the controller** — Codex sandbox was read-only; verbatim findings transcribed from inline output.

**Verdict: REQUEST-CHANGES**

| Severity | Count |
|----------|-------|
| BLOCKER | 1 |
| P1 | 2 |
| P2 | 1 |
| P3 | 1 |

---

## BLOCKER — T25-B1: Chain recovery can persist a half-recovery event pair

**File**: `apps/pos/src/lib/fiscal/ChainRecoveryService.ts:94`

The service documents that transactional atomicity is the caller's responsibility — but there is no enforced transaction wrapper around the two `engine.append()` calls in `recordBreakAndRestart()`. If the caller omits `BEGIN`/`COMMIT`, SQLite auto-commits each append as a separate implicit transaction, leaving a half-written recovery event pair on failure.

`FiscalEventEngine.ts:10` confirms: `append()` runs inside the caller's SQLite transaction — it does not BEGIN, COMMIT, or ROLLBACK.

**Rule violated**: Fiscal recovery is a two-event integrity operation; the service is the caller responsible for transaction atomicity.

**Fix**: Make `recordBreakAndRestart()` open/commit/rollback one SQLite transaction around both appends, OR require a transaction-scoped handle and add a failure-injection rollback test.

---

## P1 — T25-P1: Recorded degraded terminal mode is missing

**File**: `apps/pos/src/lib/fiscal/ChainRecoveryService.ts:26`

The service docblock explicitly says it does NOT manage the `degraded` flag on `terminal_state`. But spec §9 (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:525`) requires: "On a local chain break, the terminal continues operating in a recorded degraded mode." The `terminal_state` table (`migrations.ts:127`) has no degraded/fiscal-chain status column, and no Task 25 caller records this state.

**Fix**: Add explicit terminal fiscal-chain status / degraded state and update it in the same transaction as the recovery events, OR wire a concrete caller and test that it records state atomically.

---

## P1 — T25-P2: TS can author payloads PHP rejects

**Files**:
- `ChainRecoveryService.ts:168`
- `FiscalEventEngine.ts:532`
- `FiscalPayloadConstraintValidator.php:168,186`

`FiscalEventEngine.ts` skips validation for `CHAIN_BREAK_DETECTED` and `CHAIN_RESTART` events with a deferred-to-Task-16 comment. But `FiscalPayloadConstraintValidator.php` already enforces: 64-char lowercase hex for `last_good_hash`, `new_genesis_reference`; non-empty assoc for `last_good_anchor`, `operator_authorization_evidence`, `provenance_link`. TypeScript emits these fields with no runtime constraint checks, so malformed payloads will pass TS but fail PHP validation at sync time.

**Fix**: Add TS runtime validation for `CHAIN_BREAK_DETECTED` and `CHAIN_RESTART`: 64-char lowercase hex for hash fields, non-empty objects for anchor/evidence/provenance. Add negative Vitest cases.

---

## P2 — T25-P3: Unknown-offender fallback is under-specified

The unknown-offender fallback only records `kind: 'unknown_offender'` and `terminal_id`. This does not identify an offending fiscal record.

**Fix**: Require structured `offending_record_reference` or validate a specific missing-row incident shape.

(Opus F3 converges on this — manufactured forensic context concern.)

---

## P3 — T25-P4: Docblock key mismatch

`ChainRecoveryService.ts` docblock says `last_good_sequence_number`, but PHP expects `last_good_sequence`.

**Fix**: Update the docblock to the canonical key.

---

## Premise Audit (6 implementer premises)

| # | Premise | Result |
|---|---------|--------|
| 1 | TS payload key contract matches PHP validator | **PARTIAL** — top-level keys confirmed; runtime constraints refuted (P1 T25-P2) |
| 2 | `TerminalFiscalConfig` is genuinely new | CONFIRMED — no prior `TerminalFiscalConfig`, `ClockAnomalyDetector`, or `ChainRecoveryService` in parent tree |
| 3 | Existing `verifyClock` logic reused correctly | CONFIRMED — code comparison + 20 passing `OutboxIngestorTest` tests |
| 4 | DST fall-back expectation is right | CONFIRMED — `2026-10-25T02:30:00Z` → 03:30 CET post-fallback; boundary 4 rolls to `2026-10-24` |
| 5 | Stash incident post-pop state matches commit | CONFIRMED AS STATE — worktree was clean at `7cccfce8b` |
| 6 | Provenance link Phase 1 choice | **PARTIAL** — `new_genesis_reference = breakResult.current_hash` is hash-valid and non-circular, but does not actually issue/reset a new genesis as the PHP DTO docs describe it |

---

## Overall Recommendation

REQUEST-CHANGES. T25-B1 is a real atomicity gap — fiscal recovery's two-event integrity contract requires a transaction wrapper. T25-P1 + T25-P2 are spec-compliance gaps (degraded mode unrecorded; TS validation lighter than PHP). Round-2 should close all three P1+B plus the P2/P3 docblocks; the convergent unknown-offender concern (Opus F3 / Codex T25-P3) needs a real fix — manufactured forensic context in immutable chain rows is a forensic-honesty issue.

## Round-2 re-review (commit a73ecdbd8)

### Closure table
| Finding | Severity | Status | Evidence (file:line) |
|---|---|---|---|
| T25-B1 atomicity | BLOCKER | CLOSED | `apps/pos/src/lib/fiscal/ChainRecoveryService.ts:180` validates the offender before any transaction, `:184` opens `BEGIN`, `:186` and `:197` append both recovery events, `:212` flips degraded status, `:219` commits, and `:223` rolls back on failure. |
| T25-P1 degraded mode | P1 | CLOSED | `apps/pos/src/lib/db/migrations.ts:1108` adds v38 and `:1114` adds `terminal_state.fiscal_chain_status TEXT NOT NULL DEFAULT 'healthy' CHECK (... 'healthy', 'degraded')`; `ChainRecoveryService.ts:212` updates it to `degraded` in the same transaction. |
| T25-P2 TS validation | P1 | PARTIAL | Hash/non-empty-object checks were added at `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:541`, `:676`, `:707`, and `:708-714`, but TS still lacks PHP's extra-key rejection from `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:107-109`. See P1 below. |
| Opus F1 IANA-strict | P1 | CLOSED | `apps/api/app/Modules/Fiscal/Domain/DTOs/TerminalFiscalConfig.php:66-72` uses strict membership in `DateTimeZone::listIdentifiers()`. Tests reject `+02:00`, `-05:30`, `CEST`, `GMT+02:00`, and `Etc/GMT+2` at `ClockAnomalyDetectorTest.php:386-431`. |
| Opus F2/F5 dedup | P2/P3 | CLOSED | `OutboxIngestor.php:520-524` delegates reason formatting to `ClockAnomalyDetector::formatTimeAnomalyReason()`, and `ClockAnomalyDetector.php:103`, `:214`, and `:238-242` keep the limit lookup in one service. The old `OutboxIngestor::clockDriftLimitSeconds()` is gone. |
| Opus F3 / T25-P3 synthetic offender | P2 | CLOSED | `apps/pos/src/lib/fiscal/ChainRecoveryService.ts:108` makes `offending_reference` required, `:180` asserts it before `BEGIN`, `:241-252` rejects null/arrays/empty objects, and `:275` writes only caller-supplied context. `rg recordBreakAndRestart(` found no production callers outside tests/docs. |
| T25-P4 docblock | P3 | CLOSED | `apps/pos/src/lib/fiscal/ChainRecoveryService.ts:75-99` documents the canonical `last_good_sequence` key; no `last_good_sequence_number` remains in the service. |
| Opus F4 stale setTestNow | P3 | CLOSED | `apps/api/tests/Unit/Fiscal/ClockAnomalyDetectorTest.php:29-32` now says tests pass explicit instants and only future `setTestNow` cases would need try/finally. No current test uses `setTestNow`. |

### New findings (if any)
P1 — TS still accepts CHAIN_BREAK_DETECTED / CHAIN_RESTART extra payload keys that PHP rejects.

`apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:107-109`:
```php
$extras = array_diff(array_keys($payload), $expected);
if (count($extras) > 0) {
    return 'payload_extra_field:'.implode(',', $extras);
}
```

`apps/pos/src/lib/fiscal/FiscalEventEngine.ts:668-687` and `:699-720` only validate object shape, hash fields, and non-empty assoc fields. There is no top-level `Object.keys(payload)` comparison against the PHP `PAYLOAD_KEYS` sets for either `CHAIN_BREAK_DETECTED` or `CHAIN_RESTART`. This violates the Task 14 cross-language drift gate requested in round-2: TS must reject the same extra top-level keys PHP rejects. Fix: add exact top-level allowed-key checks for both payloads before field validation, and add negative Vitest cases with one extra key on each event type.

P3 — stale migration comment says the recovery tests exercise v37 even though they now run through v38.

`apps/pos/src/lib/fiscal/__tests__/ChainRecoveryService.test.ts:10-12`:
```ts
 * Tests exercise the real v37 migrations via the `SqliteTestAdapter` so
 * we hit the actual CHECK constraints, triggers, and partial UNIQUE
 * indexes — not a mock surface.
```

But the setup runs `await runMigrationsUpTo(adapter, 38);` at `ChainRecoveryService.test.ts:183`. This is minor, but it is exactly the stale-comment class called out in the Task 20 lesson. Fix the docblock to say v38/current migrations.

### Premise audit
|---|---|---|
| 1 | Etc/GMT+2 rejection | CONFIRMED — `php -r` check returned `default:no` and `all_with_bc:yes`; test coverage pins rejection at `ClockAnomalyDetectorTest.php:420-431`. |
| 2 | formatTimeAnomalyReason return type narrowed to non-null string | CONFIRMED — `ClockAnomalyDetector.php:186-190` declares `: string` and all branches return strings. |
| 3 | v38 migration is autonomous | CONFIRMED — `migrations.ts:1108-1122` is an additive `ALTER TABLE ... ADD COLUMN` with duplicate-column idempotency; no schema-destructive preflight gate. |
| 4 | offending_reference breaking change has no production callers | CONFIRMED — `rg recordBreakAndRestart(` returned only tests/docs plus the service definition; no production call site needs migration in this commit. |
| 5 | assertOffendingReference() runs BEFORE BEGIN | CONFIRMED — `ChainRecoveryService.ts:180` runs before `BEGIN` at `:184`. |
| 6 | No CI PG-merge-gate filter change needed | CONFIRMED — round-2 changed no workflow files, and no new server-side PG-only table-shape PHPUnit test was added. |

### Test results
- ClockAnomalyDetectorTest: 35/35
- OutboxIngestorTest: 20/20
- Fiscal feature suite: 201/201 (37 skipped)
- TS fiscal vitest: 103/140 expected (actual command reported 103 passed across 8 files; `ChainRecoveryService` was 21/21)
- PHPStan level 8: clean
- grep clockDriftLimitSeconds: 4 hits

### Overall recommendation
REQUEST-CHANGES. Round-2 closes the atomicity, degraded-mode, IANA, synthetic-offender, docblock, and reason-format ownership issues, and the requested PHP/PHPStan/typecheck commands are clean. The remaining issue is a real cross-language drift gap: PHP rejects extra top-level payload keys for `CHAIN_BREAK_DETECTED` / `CHAIN_RESTART`, while TS still accepts them. Also note the verification mismatches: TS fiscal vitest produced 103 tests, not the expected 140, and the literal `clockDriftLimitSeconds` grep produced 4 detector-only hits, not zero.

VERDICT: REQUEST-CHANGES
