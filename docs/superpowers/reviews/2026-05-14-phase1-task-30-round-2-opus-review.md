# Task 30 — Opus round-2 re-review

**Commit reviewed:** `a005b1b71` (11 files, +1128/-170) — claims closure of round-1 Codex BLOCK (1 BLOCKER + 1 P1 + 1 P2) + Opus APPROVE-WITH-MINOR-EDITS (2 P2 + 4 P3).
**Spec authority:** v7 §14.3 (two-chokepoint completeness rule) + §5.0 D1 (server never recomputes — re-hash stored canonical_bytes; pos_receipts is mirror, never authoritative) + §8 (NF525 quarantine audit-trail visibility).
**Round-1 reviewer artefacts:** `docs/superpowers/reviews/2026-05-14-phase1-task-30-opus-review.md` (round-1 Opus APPROVE-WITH-MINOR-EDITS) + the Codex round-1 BLOCK summary (T30-B1 BLOCKER, T30-P1 P1, T30-P2 P2; not on disk).
**Reviewer role:** Opus round-2 re-reviewer scanning hardest for NEW defects (Task 23 r2/r3 / Task 24 r2 / Task 25 r2 lesson — round-2 fixes have a track record of shipping fresh BLOCKERs because they introduce new architecture).

---

## Summary

Round-2 takes the right shape on paper: live BLOCKER closure folds canonical_bytes verification into `buildExportSnapshot` and routes `verifyReceiptChain` through the rebuilt `ReceiptHashService::verifyTerminalChainFiscalArm`; round-1's dead `buildExport()` is deleted; `Nf525ExportSnapshot` gains a default-valued `quarantineSection` so existing callers compile unchanged; `Nf525XmlBuilder::addQuarantineSection` emits the §8 section only when non-empty so the byte-stable JET fixture survives; a new PHP reflection helper backs the receiver_type validator at both the shell-gate and PHPUnit layers; `ReceiptHashService` now emits `Log::error('chain_verification_failed', ...)` with structured context on every failure mode; every disposition-(b) manifest entry now carries a `retired_in_task` marker; the §5.0 D1 mirror-tamper test is added.

But — exactly the Task 23 r2/r3 pattern surfaces — round-2 introduces **two NEW defects that round-1 did not have**:

1. **CI BLOCKER.** The new gate step requires `php` + `apps/api/vendor/autoload.php` to load. The `chokepoint-gate` CI job at `.github/workflows/ci.yml:60-80` was intentionally designed to skip the PHP toolchain ("no PHP toolchain required — just ripgrep + jq + bash"). Round-2 added `php "$PHP_HELPER"` to the gate but did NOT update the CI workflow to install PHP or run `composer install`. The next CI run will fail at the require-autoload line.
2. **P1 mirror-as-source regression** in the new `verifyCanonicalBytesForReceipt` helper. The docblock at `Nf525DataProvider.php:828` says "compare to stored current_hash"; the code at line 858 compares the rehashed canonical_bytes against `$receipt->fiscal_hash` (the pos_receipts MIRROR), not `fiscal_events.current_hash`. The query at line 841 only fetches `canonical_bytes`. This re-introduces the §5.0 D1 anti-pattern that the entire rebuild was supposed to extinguish: a stale or buggy projection-write of `pos_receipts.fiscal_hash` would surface as a false-positive tamper log; a coordinated tamper of `fiscal_events.canonical_bytes` + `pos_receipts.fiscal_hash` would pass.

Both are introduced by the BLOCKER closure itself. Everything ELSE — gate behaviour, manifest receiver_type validator, structured failure logging, mirror-tamper-on-pos_receipts.total test, retired_in_task markers, JET XML byte-stability — works correctly and is verified at the gate, test, and PHPStan layers.

**Verdict:** APPROVE-WITH-MINOR-EDITS conditional on T30-R2-F1 (CI BLOCKER) and T30-R2-F2 (P1) being fixed before merge. The P1 fix is a one-line change (fetch+compare `current_hash`); the BLOCKER is a 3-line addition to the CI workflow. Round-2's broader strategy is sound; the defects are the Task 23 r2/r3 / Task 24 r2 pattern (NEW architecture introduces NEW defects), not a re-shipping of the original BLOCKER.

---

## Findings

### CLEAN — round-2 round-1-finding closures that pass

- **R1-C1. T30-B1 (BLOCKER) live-path rebuild — fiscal_events arm correctly routed.** `Nf525DataProvider::verifyReceiptChain` (line 285+) now delegates fiscal_event-backed rows to `ReceiptHashService::verifyTerminalChainFiscalArm` (line 333). The legacy arm is preserved (lines 325-329, 345-391) with `whereNull('fiscal_event_id')` per the Task 21 R2 carve-out pattern. `verifyTerminalChainFiscalArm` itself (ReceiptHashService.php:204+) re-hashes `fiscal_events.canonical_bytes` and compares against `fiscal_events.current_hash` (correctly — that's the spec contract). The dead `buildExport()` method from round-1 is fully deleted (grep `\->buildExport\(` returns zero hits). Live path now reaches the rebuilt logic via `Nf525ExportController::exportJet` → `Nf525JetExportService::export` → `Nf525DataProvider::buildExportSnapshot` (which now folds in `quarantineSection`), and via `Nf525ExportController::verifyChains` → `Nf525DataProvider::verifyReceiptChain` → `ReceiptHashService::verifyTerminalChainFiscalArm`.
- **R1-C2. T30-P1 (P1) receiver_type validator wired at BOTH layers.** `apps/api/scripts/verify-chokepoint-manifest.php` (197 lines, new file) walks each non-`unrelated` manifest entry's `calling_class` via reflection, enumerates constructor parameters, and asserts the declared `receiver_type` matches a real injected dependency. The shell gate (`check-saleReceipt-chokepoints.sh:174-181`) shells out to the helper and folds its exit into `$FAILED`. PHPUnit mirror `test_manifest_receiver_type_matches_calling_class_constructor` (ChokepointCompletenessTest.php:157-216) does the same check at the test layer. Negative test `test_negative_manifest_with_wrong_receiver_type_is_rejected_by_validator` (lines 218-272) fabricates a deliberately wrong manifest, shells out to the helper via Symfony Process, asserts exit code 1 and `MANIFEST_LIE` in stderr. Both passing locally.
- **R1-C3. T30-P2 (P2) structured failure mode is sound.** `ReceiptHashService::verifyTerminalChainFiscalArm` (line 204+) now emits `Log::error('chain_verification_failed', [...])` with structured context — terminal_id + failed_fiscal_event_id + failed_sequence_number + expected_hash + actual_hash + failure_mode — BEFORE returning false. Three failure modes are distinguished: `hash_mismatch` (canonical_bytes rehash != current_hash), `linkage_broken` (previous_hash mismatch), `genesis_seed_or_link_length_invalid` (P3-2 defensive guard). Public bool return preserved; three callers (VerifyPosChainCommand, ReportController.verifyReceiptChain, Nf525DataProvider.verifyReceiptChain) need no change. New test `test_verify_terminal_chain_logs_structured_failure_on_canonical_bytes_tamper` (ReceiptChainRebuildTest.php:340-398) pins the contract via `Log::shouldReceive('error')` + a captured-log loop that asserts all five fields are present.
- **R1-C4. Opus P2-1 (retired_in_task) closed.** Manifest `schema_version` bumped `1.0` → `1.1`. Every disposition-`b` entry now carries a `retired_in_task` field: `Task 29` for ReceiptController + OrderToReceiptService + ReceiptPaymentService, `Task 28` for ReceiptSyncService, `Task 30-followup (ExchangeService cleanup)` for the two ExchangeService entries. PHPUnit method `test_disposition_b_entries_have_retirement_task` (ChokepointCompletenessTest.php:122-155) enforces every (b) entry has a non-empty string `retired_in_task`. Closes the round-1 "(b) entries can sit indefinitely without retirement" worry.
- **R1-C5. Opus P2-2 (mirror-tamper test) added.** New test `test_pos_receipts_mirror_tamper_does_not_break_fiscal_events_chain` (ReceiptChainRebuildTest.php:308-338) seeds a fiscal_events row + matching pos_receipts row, asserts `verifyTerminalChain` returns true, tampers `pos_receipts.total = '999999.99'` off-path (raw DB::table update), and asserts `verifyTerminalChain` STILL returns true — pinning the §5.0 D1 contract that pos_receipts is a projection mirror, never authoritative. The test exercises the ReceiptHashService path (where the comparison source is correctly `fiscal_events.current_hash`), so it passes.
- **R1-C6. Opus P3-1 (magic-string `SALE_RECEIPT`) closed.** The only call site was the deleted `buildExport()` method. Grep for `'SALE_RECEIPT'` in `Nf525DataProvider.php` returns zero hits. Closes Opus P3-1.
- **R1-C7. Opus P3-2 (genesis_seed length guard) closed.** `ReceiptHashService.php:251-263` adds a defensive `strlen($expectedPreviousStr) !== 64` guard before `hash_equals`, with a dedicated `genesis_seed_or_link_length_invalid` failure_mode logged via `logChainFailure`. Operators now see a crisp diagnostic instead of an opaque "hash_mismatch".
- **R1-C8. Opus P3-3 (manifest TODO) + P3-4 (last_reconciled removal) closed.** Manifest notes are rewritten to describe the receiver_type validator; `last_reconciled: "2026-05-20"` is removed. Git log is the source of truth for reconciliation history — minor cosmetic correctness.
- **R1-C9. DTO contract change is backward-compatible.** `Nf525ExportSnapshot::$quarantineSection` (Nf525ExportSnapshot.php:56) has a default value `[]`, so the stub provider at `Nf525ExportServiceWithStubProviderTest.php:33` (which positional-constructs the DTO without the field) still compiles. PHPStan level 8 clean on all touched files. Grep for `quarantineSection` returns no untouched consumers that need updating.
- **R1-C10. Nf525XmlBuilder JET fixture byte-stability preserved.** `addQuarantineSection` (Nf525XmlBuilder.php:520+) early-returns when `count($entries) === 0`. The pre-existing JET fixture at `tests/Fixtures/Nf525/jet_export_v1.xml` has zero quarantine entries, so the byte-stability test (`Nf525ExportSnapshotTest::test_jet_export_xml_is_byte_stable_against_fixture`) still passes. Verified via the full Compliance test suite run.
- **R1-C11. Gate exits 0 in both invocations.** Re-ran independently in the worktree: `bash apps/api/scripts/check-saleReceipt-chokepoints.sh` → `manifest receiver_type validator: PASS — 7 entry/entries validated` + `§14.3 chokepoint gate: PASS — 9 call site(s) reconciled`, exit 0. `--phase1-complete` invocation same shape, exit 0.
- **R1-C12. Test execution.** Re-ran `./vendor/bin/phpunit tests/Feature/Fiscal/ChokepointCompletenessTest.php tests/Feature/Fiscal/ReceiptChainRebuildTest.php` → `OK (12 tests, 167 assertions)`. Re-ran `tests/Feature/Compliance/Nf525JetExportTest.php tests/Feature/Compliance/Nf525ExportSnapshotTest.php tests/Unit/POS/Nf525DataProviderContractTest.php` → `OK (12 tests, 134 assertions)`. Full Fiscal suite → `Tests: 256, Assertions: 919, Skipped: 37` (37 PG-only on SQLite — pre-existing). PHPStan level 8 clean on all five touched files. The implementer's preflight numbers reproduce.

### NEW DEFECTS introduced by round-2

#### T30-R2-F1 BLOCKER — `chokepoint-gate` CI job will fail on next run

- **File:** `.github/workflows/ci.yml:60-80` (NOT modified by round-2)
- **Round-2 file that triggers it:** `apps/api/scripts/check-saleReceipt-chokepoints.sh:168-181` (gate now requires `php` + `apps/api/vendor/autoload.php`)
- **Description:** The `chokepoint-gate` job runs `bash apps/api/scripts/check-saleReceipt-chokepoints.sh`. Its comment at line 71-72 explicitly says: *"The job is intentionally light — no PHP toolchain required, just ripgrep + jq + bash."* The only `Install` step (lines 76-77) is `sudo apt-get install -y ripgrep jq` — no `shivammathur/setup-php@v2`, no `composer install`. Round-2 added the receiver-type validator step at gate lines 161-181 which invokes `php "$PHP_HELPER" --manifest "$MANIFEST"`. The PHP helper at `apps/api/scripts/verify-chokepoint-manifest.php:51` does `require $autoload;` against `apps/api/vendor/autoload.php`. On the CI runner:
  - `command -v php` succeeds (ubuntu-latest ships a system PHP).
  - `require $autoload;` fails because `apps/api/vendor/autoload.php` does not exist (no `composer install` step in this job).
  - PHP fatal error → script exits non-zero → gate sets `$FAILED=1` → CI job fails.
- **Impact:** The next merge-blocking CI run on `dev` and on `main` will fail at the chokepoint gate. This is the exact CI-break dependency-introduction pattern flagged in the instructions for this re-review ("does CI install `php`? Check `.github/workflows/ci.yml` for the new dependency.").
- **Reproduction:** Compare gate script (round-2) against `ci.yml` (round-1, untouched) — `composer install` is only in `backend-lint`, `backend-analyse`, `backend-architecture`, `backend-test`. The `chokepoint-gate` job has no composer step. Empirical reproduction would require a CI run; the static analysis is conclusive.
- **Recommended resolution:** Add three steps to the `chokepoint-gate` job: (1) `Setup PHP` via `shivammathur/setup-php@v2` with the standard extensions; (2) Cache composer; (3) `composer install --no-interaction --prefer-dist --working-dir=apps/api`. Then the gate's `php` call resolves to the project PHP and the autoload works. Alternatively, restructure the helper to not need `vendor/autoload.php` (manual class file resolution via `apps/api/app/` PSR-4) — but that's a much larger surface change. The CI-step approach is the right closure.

#### T30-R2-F2 P1 — `verifyCanonicalBytesForReceipt` compares rehashed canonical_bytes to the pos_receipts MIRROR, not `fiscal_events.current_hash`

- **File:** `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:835-870`
- **Description:** The new helper folds canonical_bytes spot-checking into the live `buildExportSnapshot` path (closes round-1 T30-B1). Its docblock at lines 826-828 reads: *"Phase-1 rows (`fiscal_event_id IS NOT NULL`): load the linked fiscal_events row, rehash canonical_bytes, compare to stored **current_hash**."* But the implementation at line 841 only fetches `canonical_bytes`:
  ```php
  $canonicalBytes = $this->db->table('fiscal_events')
      ->where('id', $receipt->fiscal_event_id)
      ->value('canonical_bytes');
  ```
  And at line 858 the comparison source is the projection mirror:
  ```php
  $rehashed = $this->integrityProvider->computeHash($this->stringifyCanonicalBytes($canonicalBytes));
  $stored = (string) $receipt->fiscal_hash;       // <-- pos_receipts MIRROR
  if (! hash_equals(strtolower($rehashed), strtolower($stored))) { ... }
  ```
  This is the exact §5.0 D1 anti-pattern the rebuild was meant to extinguish — the server treats a mirror field as the authoritative hash. Concrete failure modes:
  1. **False-positive tamper logs.** If the pos_receipts projection writer ever races a fiscal_events update — or if a `Receipt::create()` writes the wrong `fiscal_hash` due to a future bug — the export-time spot-check logs `canonical_bytes rehash mismatch for projected receipt — fiscal_events row may be tampered.` even though canonical_bytes is intact. Auditors triage a non-event.
  2. **Coordinated-tamper miss.** If an attacker tampers `fiscal_events.canonical_bytes` AND `pos_receipts.fiscal_hash` to a value matching the tampered rehash, the spot check passes silently. The verifier chain on the OTHER path (`verifyTerminalChainFiscalArm`) still catches it, so this is partial coverage — but the spot-check claims to be an independent defense and demonstrably is not.
  3. **Docblock-code drift.** The docblock says one thing; the code does another. Future maintainers reading the docblock will believe the contract is correct.
- **Why the tests pass anyway:** `test_build_export_snapshot_logs_structured_error_on_canonical_bytes_tamper_for_projected_receipt` (ReceiptChainRebuildTest.php:209-273) seeds `pos_receipts.fiscal_hash = $event['current_hash']`, then tampers ONLY `fiscal_events.canonical_bytes`. The rehash of tampered bytes != `pos_receipts.fiscal_hash` (which is the original hash), so the log fires. The test never tampers `pos_receipts.fiscal_hash` alone, so the false-positive direction is not exercised. The test verifies the right shape but with the wrong source of truth.
- **Recommended resolution:** One-line fix — pull `current_hash` alongside `canonical_bytes` and compare against that:
  ```php
  $row = $this->db->table('fiscal_events')
      ->where('id', $receipt->fiscal_event_id)
      ->first(['canonical_bytes', 'current_hash']);
  if ($row === null || $row->canonical_bytes === null) { ... }
  $rehashed = $this->integrityProvider->computeHash($this->stringifyCanonicalBytes($row->canonical_bytes));
  $stored = (string) $row->current_hash;
  if (! hash_equals(strtolower($rehashed), strtolower($stored))) { ... }
  ```
  Then add a positive-direction test `test_build_export_snapshot_pos_receipts_fiscal_hash_drift_does_NOT_trigger_canonical_bytes_log` — tamper `pos_receipts.fiscal_hash` to garbage, leave fiscal_events alone, assert NO Log::error. That seals the mirror contract from this surface for good.

### P2 — concerns

#### T30-R2-F3 P2 — PHPUnit receiver_type mirror uses strict-equality only; CLI helper supports interface/superclass fallback

- **Files:** `apps/api/tests/Feature/Fiscal/ChokepointCompletenessTest.php:187-202` vs `apps/api/scripts/verify-chokepoint-manifest.php:127-156`
- **Description:** The CLI helper walks the constructor + the subclass/interface chain — if `paramTypeName === $receiverType` OR `is_subclass_of($paramTypeName, $receiverType)`, it matches. It then falls back to typed properties. The PHPUnit mirror is strict-equality only: line 196 `if ($paramType->getName() === $receiverType) { $matched = true; break; }`. No subclass / interface fallback. No property fallback. Today the manifest only uses concrete class types so neither layer rejects an entry, but the asymmetry is brittle: if the codebase migrates to inject services via their interface (e.g. `Nf525DataProviderContract` rather than `Nf525DataProvider`), the CLI gate will pass and the PHPUnit gate will fail — or vice-versa if someone "fixes" the PHPUnit gate but forgets the CLI helper. Defense-in-depth requires the two layers to mirror each other.
- **Recommended resolution:** Either (a) extract a shared helper used by both layers — call the PHP file from the PHPUnit test via `shell_exec` (same pattern as the negative test), OR (b) lift the CLI helper's subclass + property fallback into the PHPUnit method. Option (a) is the cleaner closure since the negative test already shells out.

#### T30-R2-F4 P2 — buildQuarantineSection dropped the `tenant_id` filter without a defense-in-depth assertion

- **File:** `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:891`
- **Description:** Round-1 had `->where('tenant_id', $tenantId)->where('company_id', $companyId)` on the quarantine query. Round-2 dropped the `tenant_id` clause (round-2 took `tenantId` out of the method signature because `buildQuarantineSection` is now called from `buildExportSnapshot($companyId, $from, $to)` which never had a tenant_id). The docblock at lines 879-882 justifies: *"tenant scoping is implicit via the company FK (the company row owns the tenant_id; tenant isolation upstream rejects cross-tenant company_ids)."* This is correct IF the upstream tenant boundary is enforced — and it is, via `CompanyContext::requireCompanyId()` on the `verifyChains` + `exportJet` controller actions, which checks `UserCompanyMembership` before returning a company_id. But defense-in-depth at the data-layer is gone. A future regression that leaks a foreign company_id past the controller (e.g. an admin API that takes company_id as a parameter without the membership check) would surface cross-tenant quarantine rows in the export.
- **Recommended resolution:** Restore `->where('tenant_id', $resolvedTenantId)` by resolving the tenant from the company at the top of `buildExportSnapshot` (`Company::findOrFail($companyId)->tenant_id`) and passing it through. Single extra query, defense-in-depth restored. Alternatively, this is acceptable as-is if the team's standing position is "controller-layer is the only tenant gate" — but that's a project-wide decision.

### P3 — polish

- **T30-R2-F5 P3.** `verifyCanonicalBytesForReceipt` early-returns when `$canonicalBytes === null` (line 845). An empty-string canonical_bytes is not handled — would rehash `sha256('')` and log mismatch. The `fiscal_events` table's `canonical_bytes` column is NOT NULL per migration but no `CHECK length > 0` enforces non-empty. Defensive guard: `if ($canonicalBytes === null || $canonicalBytes === '')` would close the edge case.
- **T30-R2-F6 P3.** `Log::shouldReceive('error')->atLeast()->once()->withArgs(...)` in `test_build_export_snapshot_logs_structured_error_on_canonical_bytes_tamper_for_projected_receipt` + the mirror test intercept ALL `Log::error` calls during the test run, not just the one under test. The captured-log loop filters by message contents which is correct, but a flaky path that emits an unrelated `Log::error` (e.g. from a transient DB warning) would still trigger the callback — the test would still pass via the matched-flag loop, but the brittleness lives in the test scaffold. Consider `Log::partialMock()` or `Log::spy()` for tighter scoping.
- **T30-R2-F7 P3.** `verify-chokepoint-manifest.php` fallback at lines 160-174 walks typed properties as a defense-in-depth path. Today no manifest entry needs it (every receiver is constructor-injected). Worth removing or pinning behind a flag — dead code grows brittle. Or add a comment explaining the future setter-injection scenario it anticipates.
- **T30-R2-F8 P3.** `Nf525ChainVerificationResult` round-2 forces `verifiedRows: 0` when the fiscal_events arm fails (line 338). The DTO consumer in the frontend will see "0 receipts verified" even if the legacy arm has, say, 50 happily verified rows. The structured log carries the diagnostic — but the UI surface drops information. Acceptable for Phase 1 (the LIVE chains are fiscal_events; legacy rows are time-bounded).

### New-defect scan (Task 23 r2/r3 pattern) — items checked clean

- **DTO contract change consumers.** Grep `new Nf525ExportSnapshot\|Nf525ExportSnapshot(` returns 3 hits (provider × 2 + stub provider × 1). All three either pass the new `quarantineSection` field (the two production constructors) or omit it (stub) — and the default `[]` covers omission. ✓
- **JET XML byte-stability.** Quarantine emitter only writes the section when non-empty; fixture has zero quarantine rows → no change. `test_jet_export_xml_is_byte_stable_against_fixture` passes in the full Compliance suite run. ✓
- **`buildExport()` orphan callers.** `grep -rn '\->buildExport(\|buildExport('` apps/api returns ZERO hits. Method is fully dead. ✓
- **Stale-comment hazard (Task 20 standing pattern).** No docblock anywhere references the deleted `buildExport()`. Docblocks on `ReceiptCreationService::createReceipt` and `ReceiptFinalizationService::finalize` (cited in round-1) are untouched. ✓
- **Cross-task wiring premises.** Task 21 (`PosCoreReceiptProjection`) projection writer untouched; Task 22 (`TreasuryReceiptBridge`) untouched. The new spot-check reads from fiscal_events without mutating it. ✓
- **Reflection helper edge cases.** Classes with constructors that have union types are not handled (`$type instanceof ReflectionNamedType` skips union types). Today no manifest receiver uses a union — but a future migration to `Foo|Bar` would surface as "no constructor parameter matches" which is the right failure shape. Acceptable. ✓
- **Manifest schema bump (1.0 → 1.1).** Grep `schema_version` in `apps/api/` returns only the manifest itself and the negative test fixture. No external consumer reads the version. ✓

---

## Anti-pattern checklist

- [x] No `app()` helper introduced.
- [x] Constructor injection only (no `app()` in `Nf525DataProvider`, `ReceiptHashService`, the new helper).
- [x] No magic strings reintroduced (the only `'SALE_RECEIPT'` was deleted with `buildExport()`).
- [x] `RolesAndPermissionsSeeder` + real Eloquent models in test scaffold.
- [x] No `git add -A` / `.` — 11 specific files in commit.
- [x] Stale-comment hazard scan clean (Task 20 standing pattern).
- [ ] **CI workflow extended in the same commit (Task 10/11/19/21/22 standing pattern).** — FAILED. Gate now needs `php` + autoload but `.github/workflows/ci.yml` is not in the commit. This is the T30-R2-F1 BLOCKER.

---

## Verification evidence

```
$ bash apps/api/scripts/check-saleReceipt-chokepoints.sh
manifest receiver_type validator: PASS — 7 entry/entries validated
§14.3 chokepoint gate: PASS — 9 call site(s) reconciled
EXIT=0

$ bash apps/api/scripts/check-saleReceipt-chokepoints.sh --phase1-complete
manifest receiver_type validator: PASS — 7 entry/entries validated
§14.3 chokepoint gate: PASS — 9 call site(s) reconciled (--phase1-complete)
EXIT=0

$ ./vendor/bin/phpunit tests/Feature/Fiscal/ChokepointCompletenessTest.php tests/Feature/Fiscal/ReceiptChainRebuildTest.php
OK (12 tests, 167 assertions)

$ ./vendor/bin/phpunit tests/Feature/Compliance/Nf525JetExportTest.php tests/Feature/Compliance/Nf525ExportSnapshotTest.php tests/Unit/POS/Nf525DataProviderContractTest.php
OK (12 tests, 134 assertions)

$ ./vendor/bin/phpunit tests/Feature/Fiscal/
Tests: 256, Assertions: 919, Skipped: 37 (PG-only on SQLite — pre-existing)

$ ./vendor/bin/phpstan analyse --level=8 app/Modules/POS/Application/Services/Nf525DataProvider.php app/Modules/POS/Domain/Services/ReceiptHashService.php app/Modules/Compliance/Services/Nf525/Nf525XmlBuilder.php app/Modules/Compliance/Services/Nf525/Nf525JetExportService.php app/Shared/Contracts/Compliance/DTOs/Nf525ExportSnapshot.php
[OK] No errors

$ grep -rn '\->buildExport(\|buildExport(' apps/api --include='*.php'
(no hits — round-1 dead method fully deleted)

$ grep -n "shivammathur/setup-php\|composer install" .github/workflows/ci.yml | grep -i "chokepoint" -A 5 -B 5
(no match within the chokepoint-gate job lines 60-80 — CI BLOCKER confirmed)
```

---

## Recommendation

**APPROVE-WITH-MINOR-EDITS** conditional on closing T30-R2-F1 (BLOCKER) and T30-R2-F2 (P1) before merge.

- T30-R2-F1 closure: 3-step diff to `.github/workflows/ci.yml` (Setup PHP + cache composer + composer install for the `chokepoint-gate` job). One-commit fix.
- T30-R2-F2 closure: 5-line diff to `Nf525DataProvider::verifyCanonicalBytesForReceipt` (fetch `current_hash` alongside `canonical_bytes`; compare against `current_hash`, not `$receipt->fiscal_hash`) + one positive-direction test confirming `pos_receipts.fiscal_hash` drift does NOT trigger the log. One-commit fix.

Everything else round-2 delivered — the BLOCKER closure architecture (canonical_bytes verification folded into live path, dead method deleted, DTO contract extended backward-compatibly, ReceiptHashService delegation), the P1 closure (PHP reflection validator at both gate layers), the P2 closure (structured failure logging with three discriminated failure modes), the Opus P2-1 (retired_in_task markers) + P2-2 (mirror-tamper test) + four P3 polish items — is correctly executed and verified at gate, test, PHPUnit, and PHPStan layers. The two new defects are the Task 23 r2/r3 / Task 24 r2 pattern: round-2 introduces new architecture, new architecture introduces new defects, the defects are localised and fixable in two small commits. They do NOT require a round-3 architecture rewrite.

---

VERDICT: APPROVE-WITH-MINOR-EDITS
