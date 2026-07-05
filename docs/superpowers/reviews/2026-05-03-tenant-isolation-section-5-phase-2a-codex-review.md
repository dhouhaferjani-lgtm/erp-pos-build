# Codex contrarian review — tenant-isolation sweep Section 5 Phase 2A

Review date: 2026-05-04
Branch tip reviewed: 8c4dc295
Reviewer: codex

## Verdict

BLOCK

## Commit reviewed

8c4dc295 (and ancestors back to 26f13696)

## Summary

The command set is well-tested around the happy paths, and the Treasury hard gate is strict once the reference cluster is already marked `fixed`. The implementation is not yet safe to land because the artisan workflow cannot itself advance a reference cluster to `fixed`, and the CI history verifier still accepts a terminal hand-edited event with `new_yaml_sha256: null`. Those two gaps break the central Phase 2A claims: complete workflow reachability and append-only hand-edit rejection.

## Findings

### Finding 1: Reference clusters cannot be marked fixed by the nine-command workflow

- **Severity**: CRITICAL
- **Location**: `apps/api/app/Console/Commands/SweepInventoryClaimCommand.php:410`; `apps/api/app/Console/Commands/SweepInventoryReviewCommand.php:255`; `apps/api/tests/Feature/Console/Sweep/SweepInventoryClaimCommandTest.php:221`
- **Issue**: `sweep:inventory:claim` refuses every non-reference claim unless each reference cluster has `status === 'fixed'` (`SweepInventoryClaimCommand.php:410-413`). But the only command that transitions to `fixed`, `sweep:inventory:review`, updates only the callsite via `withCallsiteUpdate()` and never updates the parent cluster (`SweepInventoryReviewCommand.php:255-284`). `rg` found no production command path that sets a cluster status to `fixed`. The tests avoid this by directly reseeding Treasury as fixed (`SweepInventoryClaimCommandTest.php:221-226`, and helper `fixTreasuryWithReview()` at `:540-550`), so the test suite does not exercise a real claim -> start -> submit -> review -> non-Treasury claim workflow. Result: after all Treasury callsites are reviewed to `fixed`, `api.treasury` remains `in_progress`, so the hard gate remains closed unless someone hand-edits the YAML.
- **Fix**: Add a command-owned cluster roll-up path. The least surprising implementation is inside `sweep:inventory:review` on APPROVE-family verdicts: in the same `InventoryService::mutate()` call, after updating the reviewed callsite, inspect live sibling callsites and set the cluster to `fixed` only when all callsites in that cluster are fixed. Anchor the cluster flip in the same appended history event by including the cluster id in `target_ids`, or add an explicit audited finalize command if the spec is changed. Add an end-to-end test that starts with pending Treasury, drives every Treasury callsite through the real commands, then successfully claims `api.document` without direct reseeding.

### Finding 2: verify-history accepts a terminal forged workflow event with null new_yaml_sha256

- **Severity**: CRITICAL
- **Location**: `apps/api/app/Console/Commands/SweepInventoryVerifyHistoryCommand.php:224`; `apps/api/tests/Feature/Console/Sweep/SweepInventoryVerifyHistoryCommandTest.php:340`
- **Issue**: The verifier checks `previous_yaml_sha256` for non-first events, but it never requires the current event's `new_yaml_sha256` to be non-null. A forged final event with `actor`, `command`, valid `target_ids`, `previous_yaml_sha256` equal to the prior event's `new_yaml_sha256`, and `new_yaml_sha256: null` passes because there is no successor to expose the null as a broken chain. This is especially visible in the slot relaxation: `history[1]` may have arbitrary non-null `previous_yaml_sha256` when the seed's `new_yaml_sha256` is null, and the current implementation would accept it as the last event even if its `new_yaml_sha256` is null. The splice regression test only catches the two-event variant by appending `history[2]` after the null (`SweepInventoryVerifyHistoryCommandTest.php:340-408`); it does not cover the terminal forged-event case.
- **Fix**: For every event after the seed, require `new_yaml_sha256` to be a non-null 64-char hash. If the seed relaxation must remain, only relax `eventIndex === 1` for `previous_yaml_sha256`; do not relax `new_yaml_sha256`. Add a regression test that appends a single terminal forged event with `new_yaml_sha256: null`, recomputes `metadata.yaml_sha256`, and verifies `sweep:inventory:verify-history` exits non-zero.

### Finding 3: Review verdict reconciliation is skipped for REQUEST-CHANGES and BLOCK

- **Severity**: IMPORTANT
- **Location**: `apps/api/app/Console/Commands/SweepInventoryReviewCommand.php:224`; `apps/api/app/Console/Commands/SweepInventoryReviewCommand.php:49`
- **Issue**: The review command only parses and reconciles the `Verdict: <X>` line when the CLI flag is in the APPROVE family (`if (in_array($verdict, self::APPROVE_FAMILY, true))` at `:224`). The file itself documents the bypass for REQUEST-CHANGES and BLOCK (`:49-51`). That contradicts the review contract in this prompt: `--verdict` must equal the parsed review-file verdict. It allows `--verdict=BLOCK` with a review file that says `Verdict: APPROVE`, or `--verdict=REQUEST-CHANGES` with a file that says `Verdict: BLOCK`, and records the flag value into YAML as if the artifact agreed.
- **Fix**: Always parse the first valid unquoted `Verdict:` line for all four verdicts, require it to equal `--verdict`, and fail if absent. Keep commit-linkage checks limited to APPROVE-family verdicts if that remains the desired policy. Add mismatch tests for BLOCK and REQUEST-CHANGES, not only APPROVE.

### Finding 4: Review commit linkage rejects the short SHA contract implemented by claim

- **Severity**: IMPORTANT
- **Location**: `apps/api/app/Console/Commands/SweepInventoryReviewCommand.php:347`; `apps/api/app/Console/Commands/SweepInventoryClaimCommand.php:524`
- **Issue**: The claim hard-gate path accepts a full SHA or a unique lowercase short SHA of length >= 7 (`SweepInventoryClaimCommand.php:524-544`). The review path does not share that behavior: it requires the file's `Commit reviewed:` value to exactly equal `--review-commit` (`ReviewCommand.php:347-350`) and then requires `--review-commit` to exactly equal the callsite's full `fix_commit` (`:359-362`). That means a review file using a normal 7+ char git abbreviation is accepted for the hard gate but refused by `sweep:inventory:review`, and the ambiguous-prefix refusal promised in the prompt is not implemented on the review side.
- **Fix**: Extract the claim command's short-SHA matcher into a shared helper and use it from review. For review, require file and flag to identify the same unique stored `fix_commit`; accept exact full matches and unique short-prefix matches, and reject ambiguous prefixes with a clear error.

### Finding 5: Cluster block/defer modes ignore the cluster's own state precondition

- **Severity**: IMPORTANT
- **Location**: `apps/api/app/Console/Commands/SweepInventoryBlockCommand.php:226`; `apps/api/app/Console/Commands/SweepInventoryDeferCommand.php:236`
- **Issue**: `block --cluster` filters eligible callsites by pre-fixed status but never checks the cluster's current status before setting it to `blocked` (`BlockCommand.php:226-241`, `:270-274`). `defer --cluster` similarly filters pending/claimed callsites but never requires the cluster itself to be pending or claimed before setting it to `deferred` (`DeferCommand.php:236-247`, `:273-277`). In an inconsistent but schema-valid YAML, a `fixed` cluster with one pending callsite can be moved to `blocked` or `deferred`, violating the state preconditions the prompt asks to verify.
- **Fix**: Add explicit cluster status preconditions before computing eligible callsites. `block --cluster` should require the cluster status to be in `pending|claimed|in_progress|under_review`; `defer --cluster` should require `pending|claimed`. Add tests where the cluster is `fixed`, `blocked`, `deferred`, `needs_recheck`, and `stale_orphan` while a child callsite is otherwise eligible, and assert the cluster does not move.

## What looks good

- The Treasury gate is applied in both cluster and single-callsite claim paths, which closes the obvious per-callsite bypass.
- Cross-agent review enforcement is unconditional and review actors are constrained to `claude|codex` before schema validation.
- Cluster claim/start/block/defer mutations that do run are performed in single `InventoryService::mutate()` calls, preserving the intended lock/write surface.
- The file-level canonical hash helper used by verify-history delegates to the same hashing path used by the mutator.

## Open questions

- Should cluster roll-up be automatic when the final callsite is approved, or should Phase 2A add an explicit audited `finalize` command? The current hard gate requires one of those choices; direct YAML edits should not be the operational path.
- Is `edit_applied` intentionally deferred? The schema/action enum allows it, and Section 7 describes a hard-gate check for APPROVE-WITH-MINOR-EDITS-APPLIED edits, but no Phase 2A command writes that action.

## Verification run

- `vendor/bin/phpunit tests/Feature/Console/Sweep --no-coverage`: passed, 106 tests / 353 assertions.
- `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G`: passed.
- `vendor/bin/phpunit --testsuite=Architecture --group=sweep-progress`: passed; Gate A=94, Gate B=125.
- `./vendor/bin/pint --test app/ tests/Feature/Console/Sweep`: passed.
- `git diff --stat dev..feat/tenant-isolation-sweep-execution -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher`: empty.
