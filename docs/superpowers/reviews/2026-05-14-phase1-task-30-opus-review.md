# Task 30 — Opus spec-compliance review

**Commit reviewed:** `cb1ab6eda` (11 files, +1318/-41)
**Spec authority:** v7 §14.3 (two-chokepoint completeness rule, lines 675-700) + §5.0 D1 (server never recomputes — re-hash stored canonical_bytes) + §8 (non-admissible quarantine NF525 audit-trail visibility)
**Plan reference:** `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md` Task 30 (line 2519+)
**Reviewer role:** Opus first-stage spec-compliance reviewer (Codex adversarial review running in parallel)

---

## Summary

Task 30 materializes the §14.3 completeness rule as a **persistent CI gate** (not a one-time check) and rebuilds `ReceiptHashService::verifyTerminalChain` / `verifyHash` + `Nf525DataProvider::buildExport` from "recompute-from-models" → "re-hash stored `canonical_bytes`" per spec §5.0 D1. Three artifacts close the v5/v6 BLOCKER class: (1) a checked-in disposition manifest at `apps/api/scripts/saleReceipt-chokepoint-manifest.json` with 8 entries reconciling 9 `rg` hits; (2) a `bash` gate (`check-saleReceipt-chokepoints.sh`) wired into `scripts/preflight.sh` + a dedicated `chokepoint-gate` CI job; (3) `ChokepointCompletenessTest` (3 methods / 84 assertions) mirrors the shell gate at the PHPUnit layer per the Task 20 defense-in-depth standing pattern. The chain rebuild is two-arm — authoritative `fiscal_events` walk plus legacy `pos_receipts` (`fiscal_event_id IS NULL`) preserved per the Task 21 R2 carve-out pattern. `Nf525DataProvider` gains a new `buildExport(tenantId, companyId)` that reads `canonical_bytes` directly + exposes `quarantine_section` per spec §8 NF525 audit-trail visibility; the strict-typed `buildExportSnapshot` contract is preserved unchanged.

**Verdict:** APPROVE-WITH-MINOR-EDITS. Gate is correct + complete; both verification commands exit 0 (9 callsites reconciled, `--phase1-complete` also clean); all 5 new tests pass with 104 assertions; full Fiscal feature suite (249/249) green; 22 Nf525-related tests green; no `app()` helper; constructor injection throughout; CI PG-merge-gate filter extended in the same commit per the standing pattern. Two P2 concerns and four P3 polish items round it out. No BLOCKERs, no P1s.

---

## Findings

### CLEAN — what passes spec compliance

- **C1. §14.3 grep gate is complete.** Re-ran independently: `rg '\->createReceipt\(' apps/api/app apps/api/routes` returns 3 hits (`ReceiptController.php:308`, `ExchangeService.php:233`, `OrderToReceiptService.php:68`); `rg '\->finalize\(' apps/api/app apps/api/routes` returns 6 hits (`InventoryCountingController.php:282`, `ExchangeService.php:299`, `ExchangeService.php:302`, `ReceiptReturnService.php:351`, `ReceiptSyncService.php:641`, `ReceiptPaymentService.php:437`). All 9 are reconciled to manifest entries via the file + `line_anchor` substring match; the two ExchangeService finalize sites at `:299` + `:302` legitimately share one entry because the source text is identical (`$saleDraft = $this->finalizationService->finalize($saleDraft);`). 8 manifest entries cover 9 grep hits — correct.
- **C2. Both gate invocations pass.** Verified independently: `bash apps/api/scripts/check-saleReceipt-chokepoints.sh` → `PASS — 9 call site(s) reconciled` (exit 0). `bash apps/api/scripts/check-saleReceipt-chokepoints.sh --phase1-complete` → same PASS with `(--phase1-complete)` tag (exit 0). The `--phase1-complete` flag enforces the stricter post-Phase-1 invariant; both clean here.
- **C3. `live: false` claim for ExchangeService verified.** Independently grepped `processExchange` / `ExchangeService` / `exchange` across `apps/api/app`, `apps/api/routes`, `apps/api/app/Modules/POS/routes*.php`, `apps/api/app/Modules/POS/Presentation`. Zero live controller, route, or DI-binding hits — only the service file itself plus DTOs/docblock references. The `live: false` markers on both ExchangeService manifest entries are correct; no security-relevant unmarked server-authoring path exists.
- **C4. ReceiptHashService rebuild matches spec §5.0 D1.** `verifyTerminalChain` (line 179) has two arms: (a) authoritative `fiscal_events` arm walks `sequence_number`-ordered rows, calls `integrityProvider->computeHash($canonicalBytes)`, asserts `hash_equals` against stored `current_hash`, then asserts `previous_hash` linkage (genesis_seed on row 1; prior `current_hash` thereafter) — exactly the spec §13 verifier walk pattern shared with `VerifyEventChainCommand` (Task 31); (b) legacy arm filters `whereNull('fiscal_event_id')` per the Task 21 R2 carve-out pattern, runs the original NF525 pipe-separated recomputation. `verifyHash` (line 315) similarly branches on `fiscal_event_id IS NOT NULL`. Constructor injection throughout (`FiscalHashService`, `FiscalIntegrityProvider`, `ConnectionInterface`); no `app()`.
- **C5. `Nf525DataProvider::buildExport` matches spec §8 + §14.3.** Each receipt entry (lines 832-845) reads `canonical_bytes` directly from `fiscal_events`, computes `canonical_bytes_sha256` via `integrityProvider->computeHash`, and stamps `canonical_bytes_source = 'fiscal_events.canonical_bytes'` + `integrity_verified_at_export` (defensive — the immutability triggers are the primary defense). `quarantine_section` (lines 865-881) pulls `fiscal_event_quarantine` rows with `envelope_id`, `claimed_sequence_number`, `integrity_exception_class`, `integrity_reason`, `server_received_at`, `raw_envelope` — matches the spec §8 reader contract. Existing `Nf525DataProviderContract` (`buildExportSnapshot`) is preserved untouched; the new method cohabits per the docblock at line 791. Missing `canonical_bytes` is logged + skipped (line 814) — defensive.
- **C6. ChokepointCompletenessTest implementation is sound.** `callSites()` (line 129) actually runs `rg --fixed-strings -- <needle> apps/api/app apps/api/routes` via `Symfony\Component\Process\Process` and parses `<file>:<lineno>:<text>` — not a fixture read. `manifestEntryFor()` (line 216) does the documented file + line_anchor substring match. The 3 methods cover (1) every createReceipt site reconciled + dispositioned, (2) every finalize site reconciled + receiver_type present, (3) every live non-unrelated entry has disposition in {b, c}. The PHPUnit `Group('chokepoint-gate')` attribute makes them filterable. Test ran clean: `OK (5 tests, 104 assertions)` across both test files.
- **C7. ReceiptChainRebuildTest pins the right contracts.** Test 1 (line 99) seeds a 3-row valid chain via `seedValidChain(3)`, asserts `verifyTerminalChain($terminal)` returns true, then tampers `canonical_bytes` (SQLite path) OR inserts a deliberate `current_hash` mismatch (PG path — because the Task 8 trigger blocks canonical_bytes UPDATE), and asserts detection. Test 2 (line 160) seeds a valid chain + a `fiscal_event_quarantine` row, calls `buildExport`, asserts `quarantine_section` non-empty + receipts non-empty + `canonical_bytes_source = 'fiscal_events.canonical_bytes'` on the first receipt, asserts the full §8 reader contract on the quarantine entry (`envelope_id`, `claimed_sequence_number`, `integrity_exception_class`, `integrity_reason`, `server_received_at`, `raw_envelope`). PG/SQLite-conditional tampering branch is necessary because of the Task 8 immutability trigger (correct insight). Constructor injection via `$this->app->make()` in test scaffold (acceptable per project conventions).
- **C8. Docblock annotations on the two chokepoints are substantive.** `ReceiptFinalizationService::finalize` (commit diff lines 37-65) cites all four chokepoint callers by name with their dispositions; `ReceiptCreationService::createReceipt` (lines 103-109) cites all three createReceipt callers; `ExchangeService` class-level docblock (lines 57-67) explicitly notes "no live controller / route reaches `ExchangeService::processExchange`" + warns "Do NOT wire a new controller into this service without updating the manifest first". The Task 20 standing pattern is satisfied — these docblocks don't drift silently from the manifest because they cite the manifest path and CI gate explicitly.
- **C9. CI wiring is correct.** Two CI surfaces touch the gate: (a) a dedicated `chokepoint-gate` job (`.github/workflows/ci.yml:60-80`) installs `ripgrep` + `jq` and runs `apps/api/scripts/check-saleReceipt-chokepoints.sh` on every PR + push — no PHP toolchain needed; (b) the PG-merge-gate `--filter` regex at `:458` is extended with `|ChokepointCompletenessTest|ReceiptChainRebuildTest` per the Tasks 10/11/19/21/22 standing pattern. `scripts/preflight.sh` gains the gate at line 97-108. Wired in the same commit (anti-pattern checklist item passes).
- **C10. No `app()` helper introduced.** Grep across `ReceiptHashService.php`, `Nf525DataProvider.php`, `check-saleReceipt-chokepoints.sh`, `ChokepointCompletenessTest.php`, `ReceiptChainRebuildTest.php` returns zero `app(` matches. Constructor injection throughout. CLAUDE.md rule 13 satisfied.
- **C11. No magic strings.** The shell gate compares chokepoint values against the literal `unrelated` and disposition against `b`/`c` — fine for a shell script. The PHP code uses `FiscalEventType::SALE_RECEIPT->value` in `Nf525DataProvider::buildExport` line 796 (actually a literal string `'SALE_RECEIPT'` — see F2 below; minor). The test scaffold uses `FiscalEventType::SALE_RECEIPT->value` (`ReceiptChainRebuildTest.php:253`) + `IntegrityExceptionClass::SequenceConflict->value` (`:209`) + `PayloadParseStatus::Pending->value` — enums, correctly.
- **C12. Test scaffolding follows standing patterns.** `RolesAndPermissionsSeeder` seeded; real Eloquent factories (`Tenant`, `Company`, `Location`, `Terminal`) instead of mocks; raw `DB::table` inserts for `fiscal_events` rows because the model construction would bind to mutable model state. No `mockery`, no `git add -A` (commit lists 11 specific files).
- **C13. Test execution.** Re-ran `./vendor/bin/phpunit tests/Feature/Fiscal/ChokepointCompletenessTest.php tests/Feature/Fiscal/ReceiptChainRebuildTest.php` → `OK (5 tests, 104 assertions)`. Re-ran full `tests/Feature/Fiscal/` → `Tests: 249, Assertions: 856, Skipped: 37` (37 skipped are pre-existing PG-only tests on SQLite — not regressions). Re-ran all 6 Nf525-related test files (Unit + Feature) → `OK (22 tests, 166 assertions)`.
- **C14. `verifyTerminalChain` signature.** Verified all production callers (`ReportController.php:399`, `VerifyPosChainCommand.php:194`, `PosCoreReceiptProjectionTest.php:810`) pass a `Terminal` model — the implementer's choice to keep the existing `Terminal $terminal` typed parameter (rather than accepting a string ID per the plan's pseudo-code) is compatible with every existing caller and preserves type-safety. No regression.

### P2 — Concerns

**P2-1. (T30-F1) Third ChokepointCompletenessTest method accepts disposition `b` OR `c`, but spec §14.3 + plan-quoted test asserted `c` only.**

- **File:** `apps/api/tests/Feature/Fiscal/ChokepointCompletenessTest.php:91-120` (`test_after_phase1_only_void_return_carveout_chokepoint_callers_remain_live`)
- **Description:** Spec v7 §14.3 line 698 reads: *"After Phase 1, the only remaining production callers of either chokepoint are the **(c)** void/return carve-out."* The plan-quoted assertion (line 2565) is `assertSame('c', $entry['disposition'])` + `assertStringContainsString('ReceiptReturnService', $entry['calling_class'])`. The implementer relaxed to `assertContains($entry['disposition'], ['b', 'c'])` to accommodate the in-flight state where Tasks 28+29 have closed the routes (HTTP 410) but the service bodies are still live (`ReceiptSyncService::sync`, `ReceiptPaymentService::processReceiptPayments`, `OrderToReceiptService::convertToReceipt`). The shell gate's `--phase1-complete` flag uses the same `b`/`c` relaxation. This is defensible **as a transitional state** but two consequences:
  1. The test name says "only void/return carveout" but the assertion no longer enforces that — a live `(b)` entry can survive indefinitely without anyone noticing. Without a per-entry expiration date or a follow-up task linking each `(b)` entry to its retirement task, the gate could silently sit at this looser bar forever.
  2. The spec-implementation gap means a strict reader of §14.3 would expect the test as-written to be the eventual final state. The current shape doesn't enforce the *spec's* final state.
- **Recommended resolution:** Either (a) add a per-entry `retired_in_task` field (e.g. `"retired_in_task": "Task 28"` on the `ReceiptSyncService` entry, `"Task 29-followup"` on `ReceiptPaymentService` + `OrderToReceiptService` + `ReceiptController`) plus a new test method `test_disposition_b_entries_have_retirement_task` so each `(b)` entry has a tracked exit; OR (b) add a separate strict method (e.g. `test_phase1_strict_only_c_entries_live` marked `@group phase1-strict-gate`) that Task 33 (full-flow verification) flips on once Tasks 28+29 finish body-removal. The current behavior is correct for *today*; the gap is in the spec-final-state enforcement, not in today's correctness.

**P2-2. (T30-F2) `verifyTerminalChain` test doesn't actually verify the "mirror-tamper does not break" guarantee — it only verifies the canonical_bytes-tamper-IS-detected direction.**

- **File:** `apps/api/tests/Feature/Fiscal/ReceiptChainRebuildTest.php:99-158` (test 1)
- **Description:** The commit message claims: *"Tampering with `pos_receipts.total` (or any other mirror column) MUST NOT cause a false chain break, while tampering with `canonical_bytes` MUST be caught on rehash."* The test asserts the SECOND half (positive — tampering canonical_bytes IS detected) but never seeds a `pos_receipts` row at all, let alone tampers it. The test only exercises the new authoritative `fiscal_events` arm; the "mirror tamper doesn't break" claim — which is the load-bearing §5.0 D1 contract — is not actually covered by Phase 1 tests in this commit. The legacy arm (line 241) is also untested. The 104 assertion count is high but they all line up on a single semantic axis.
- **Recommended resolution:** Add a third test case `test_pos_receipts_mirror_tamper_does_not_break_fiscal_events_chain`: seed `fiscal_events` row + matching `pos_receipts` row (with `fiscal_event_id` set, `total = '100.00'`); call `verifyTerminalChain($terminal)` → assert true; tamper `pos_receipts.total` to `'999.99'`; call `verifyTerminalChain($terminal)` → assert STILL true (because the verifier ignores projection mirror fields). This is the contract the spec §5.0 D1 + the commit message claim; without it the rebuild is half-tested.

### P3 — Polish

- **P3-1. (T30-F3) `Nf525DataProvider::buildExport` uses literal `'SALE_RECEIPT'` instead of `FiscalEventType::SALE_RECEIPT->value`.**
  - **File:** `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:796`
  - **Description:** `->where('event_type', 'SALE_RECEIPT')` — a magic string. The codebase has `App\Modules\Fiscal\Domain\Enums\FiscalEventType::SALE_RECEIPT`. CLAUDE.md rule 9 (enums for all status/type columns). Minor because the test seeds via the same enum so a value drift would surface; but rule consistency matters.
  - **Recommended resolution:** `->where('event_type', FiscalEventType::SALE_RECEIPT->value)` with a `use` statement.

- **P3-2. (T30-F4) `verifyFiscalEventsArm` empty-genesis-seed guard is implicit, not asserted.**
  - **File:** `apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php:205-228`
  - **Description:** The comment at line 220-224 explains: *"A missing genesis_seed surfaces as a length-zero string and fails hash_equals against the row's 64-hex previous_hash, which is the failure shape we want anyway."* True UNLESS the stored `previous_hash` on row 1 also happens to be the empty string — then the chain would falsely verify. PG CHECK constraint on `pos_terminals.genesis_seed` (`length(genesis_seed) = 64` — migration `2026_01_08_190429`) prevents empty seeds at the schema layer, so the gap is structurally closed. But defense-in-depth would assert `strlen($expectedPrevious) === 64` once before the loop. P3 because the schema constraint already covers it.
  - **Recommended resolution:** Add a single guard above line 207: `if (strlen((string) $expectedPrevious) !== 64) { return false; }` — fail-closed defense.

- **P3-3. (T30-F5) Shell gate's `receiver_type` claim is informational, not enforced.**
  - **File:** `apps/api/scripts/check-saleReceipt-chokepoints.sh:140-158`
  - **Description:** The plan's matching-strategy description (line 2533) says *"`receiver_type` resolves the ambiguity — a `->finalize(` on an `InventoryCountingService` receiver is `"unrelated"` and explicitly allowlisted"*. The implementation does check `chokepoint == 'unrelated'` but NEVER verifies the manifest's `receiver_type` actually matches the source-file context. If someone marked `InventoryCountingController:282` as `receiver_type: 'ReceiptFinalizationService'` (a typo / copy-paste error), the gate would not catch it. The PHPUnit method 2 also only does `assertArrayHasKey('receiver_type')` — not validate the value. Acceptable per the plan's "enough type-awareness for this codebase" framing, but worth a TODO for Phase 2.
  - **Recommended resolution:** Add a TODO comment to the manifest's `notes` field explaining receiver_type is informational + a P3 follow-up to wire a `php -r` snippet that reflects the constructor type for the manifest's `calling_class` and asserts the receiver_type matches.

- **P3-4. (T30-F6) Manifest `last_reconciled: "2026-05-20"` will go stale.**
  - **File:** `apps/api/scripts/saleReceipt-chokepoint-manifest.json:4`
  - **Description:** A wall-clock timestamp will drift; no gate enforces freshness. Tasks 28+29 follow-up should bump this. Cosmetic.
  - **Recommended resolution:** Either remove it (the git log is the source of truth) or add a freshness check (e.g. "if `last_reconciled` is older than 30 days, warn"). Recommend removal.

---

## Anti-pattern checklist

- [x] No `app()` helper introduced (rule 13).
- [x] No magic strings — except P3-1 (literal `'SALE_RECEIPT'` at `Nf525DataProvider:796`). Minor.
- [x] Test scaffold uses `RolesAndPermissionsSeeder` + real Eloquent models (per project memory).
- [x] No `git add -A` / `.` — commit lists 11 specific files.
- [x] CI PG-merge-gate filter extended in the same commit (Tasks 10/11/19/21/22 standing pattern).
- [x] Stale-comment hazard (Task 20) — docblocks on `finalize()` + `createReceipt()` cite the manifest accurately. Also added on `ExchangeService` class.
- [x] Constructor injection only — no `app()` helper anywhere in the rebuilt services or tests.

---

## Verification evidence

```
$ bash apps/api/scripts/check-saleReceipt-chokepoints.sh
§14.3 chokepoint gate: PASS — 9 call site(s) reconciled
EXIT=0

$ bash apps/api/scripts/check-saleReceipt-chokepoints.sh --phase1-complete
§14.3 chokepoint gate: PASS — 9 call site(s) reconciled (--phase1-complete)
EXIT=0

$ rg '\->createReceipt\(' apps/api/app apps/api/routes
ReceiptController.php:308
ExchangeService.php:233
OrderToReceiptService.php:68

$ rg '\->finalize\(' apps/api/app apps/api/routes
InventoryCountingController.php:282
ExchangeService.php:299
ExchangeService.php:302
ReceiptReturnService.php:351
ReceiptSyncService.php:641
ReceiptPaymentService.php:437

$ ./vendor/bin/phpunit tests/Feature/Fiscal/ChokepointCompletenessTest.php tests/Feature/Fiscal/ReceiptChainRebuildTest.php
OK (5 tests, 104 assertions)

$ ./vendor/bin/phpunit tests/Feature/Fiscal/
Tests: 249, Assertions: 856, Skipped: 37 (PG-only on SQLite)

$ ./vendor/bin/phpunit tests/Unit/POS/Nf525DataProviderContractTest.php tests/Feature/POS/Nf525DataProviderRefundExtensionTest.php tests/Feature/Compliance/Nf525ExportSnapshotTest.php tests/Feature/Compliance/Nf525JetExportTest.php tests/Unit/Compliance/Nf525ContractIsolationTest.php tests/Unit/Compliance/Nf525ExportServiceWithStubProviderTest.php
OK (22 tests, 166 assertions)

$ rg 'processExchange|ExchangeService' apps/api/routes apps/api/app/Modules/POS/Presentation apps/api/app/Modules/POS/routes*.php
(no live route or controller hit)
```

---

## Recommendation

**APPROVE-WITH-MINOR-EDITS.**

Two P2 items to address before Task 33 (full-flow verification):

1. (T30-F1) Either tighten `test_after_phase1_only_void_return_carveout_chokepoint_callers_remain_live` to the spec's strict `c`-only shape (with a parallel `--phase1-complete-strict` shell flag), OR add per-entry `retired_in_task` fields + a freshness test so `(b)` entries can't sit indefinitely.
2. (T30-F2) Add the missing `pos_receipts` mirror-tamper test — the §5.0 D1 "tampering with mirror doesn't break chain" contract is the half of the spec the test suite doesn't actually exercise. Without it, the rebuild's load-bearing claim is implementation-only.

The P3 polish items (magic-string `SALE_RECEIPT`, defensive genesis-seed length guard, receiver_type informational gap, last_reconciled freshness) are cleanup nits — fine to absorb in a follow-up.

The gate itself is sound, both invocations exit 0, all tests pass, no `app()` helper, no magic strings beyond the one literal, no `git add -A`, CI + PG-merge-gate wired in-commit. The `ExchangeService live: false` claim is independently verified (no live route or controller anywhere). The `ReceiptHashService` two-arm rebuild and `Nf525DataProvider::buildExport` canonical_bytes+quarantine surface are the spec §5.0 D1 + §8 contracts as written.

---

VERDICT: APPROVE-WITH-MINOR-EDITS
