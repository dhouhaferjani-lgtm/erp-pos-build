# Codex contrarian re-review - tenant-isolation sweep Section 5 Phase 2A (round 2)

Review date: 2026-05-04
Branch tip reviewed: e11aaf3a
Round-1 verdict: BLOCK (5 findings)
Reviewer: codex

## Verdict

BLOCK

## Round-1 findings status

### Finding 1: cluster roll-up
- Status: CLOSED
- Notes: `SweepInventoryReviewCommand` now performs the callsite update and the conditional cluster roll-up inside the same `InventoryService::mutate()` closure. The sibling scan runs after `withCallsiteUpdate()`, so the just-approved callsite is included. Roll-up is gated to APPROVE-family verdicts (`APPROVE` / `APPROVE-WITH-MINOR-EDITS-APPLIED`) and any non-`fixed` sibling, including `deferred` or `blocked`, prevents cluster promotion. The end-to-end Treasury test drives claim -> start -> submit -> review -> non-Treasury claim through artisan commands; its only pre-work is changing the static `review_gate.review_file` path so the test-controlled review artifact exists. The test suite does not pin the two-callsite "first approval does not roll up" edge case, but the implementation handles it by requiring every sibling to be `fixed`.

### Finding 2: terminal forged event
- Status: PARTIALLY-CLOSED
- Notes: The exact `new_yaml_sha256: null` terminal-event bypass is closed: every non-seed event now rejects a null `new_yaml_sha256`, and the new regression appends one terminal forged event with `new_yaml_sha256: null`. The broader verifier gap remains: a forged terminal event can set any non-null fake `new_yaml_sha256` and still pass. `verify-history` does not schema-validate on load, does not check `new_yaml_sha256` format, and does not verify the terminal event's `new_yaml_sha256` against `metadata.yaml_sha256`. Because `InventoryService::canonicalForHashing()` zeroes all history hash fields before computing the file-level hash, changing a terminal event from null to a fake non-null hash does not disturb `metadata.yaml_sha256`. With no successor event, the fake value is never consumed by the chain check.

### Finding 3: verdict reconciliation
- Status: CLOSED
- Notes: Verdict reconciliation now runs unconditionally before approve-family linkage. It requires strict equality between the parsed `Verdict:` line and `--verdict`, with no case folding or normalization. Missing `Verdict:` lines refuse. The REQUEST-CHANGES happy path writes a matching `Verdict: REQUEST-CHANGES` file and succeeds for the intended reason, while the REQUEST-CHANGES and BLOCK mismatch tests use valid files and assert the callsite remains `under_review`.

### Finding 4: short-SHA review linkage
- Status: CLOSED
- Notes: `commitIdentifierMatchesStored()` was extracted into `AbstractSweepInventoryCommand` and claim now uses it instead of the removed duplicate private matcher. Exact full SHAs match; lowercase hex short SHAs of length 7-39 match only when they prefix exactly one stored full SHA; ambiguous prefixes, non-hex candidates, uppercase candidates, and candidates shorter than 7 chars fail. Review validates both `--review-commit` and the file's `Commit reviewed:` line independently against the callsite's stored `fix_commit`, so full/short combinations work as long as each identifies the same stored fix commit.

### Finding 5: cluster state precondition
- Status: CLOSED
- Notes: `block --cluster` now checks the cluster's own status before building eligible callsites and uses the same `PRE_FIXED_STATUSES` constant as per-callsite block. `defer --cluster` now checks the cluster is `pending` or `claimed` before eligibility filtering. The regression tests iterate the full schema enum minus the allowed states: block refuses `fixed`, `blocked`, `deferred`, `needs_recheck`, and `stale_orphan`; defer refuses `in_progress`, `under_review`, `fixed`, `blocked`, `deferred`, `needs_recheck`, and `stale_orphan`. Per-callsite block/defer still leave cluster status untouched, so a cluster precondition is not needed there.

## New findings

### Finding 6: verify-history accepts terminal forged events with fake non-null new_yaml_sha256

- **Severity**: CRITICAL
- **Location**: `apps/api/app/Console/Commands/SweepInventoryVerifyHistoryCommand.php:224-268`; `apps/api/app/Application/Sweep/InventoryService.php:430-445`
- **Issue**: The remediation for finding 2 only checks `new_yaml_sha256 !== null` for non-seed events. A forged terminal workflow event can still survive by using any non-null string, including a malformed value or a fake 64-char lowercase hash. For `history[1]` after the seed event, `previous_yaml_sha256` only has to be non-null because the seed's `new_yaml_sha256` is null and the slot relaxation applies. The terminal fake `new_yaml_sha256` has no successor, so the chain walk never compares it. The file-level hash check also does not catch it because `canonicalForHashing()` explicitly zeroes every `previous_yaml_sha256` and `new_yaml_sha256` before hashing.
- **Fix**: Validate each non-seed `new_yaml_sha256` as a 64-char lowercase hex hash, and for the terminal event in each callsite history require it to equal the document's current `metadata.yaml_sha256`. Keep the `eventIndex === 1` relaxation limited to `previous_yaml_sha256`. Add a regression test that appends a single terminal forged event with a non-null fake hash, recomputes/keeps the canonical metadata hash, and asserts `sweep:inventory:verify-history` exits non-zero.

## What looks good

- Review's roll-up uses the same mutation cycle as the callsite review event, preserving the optimistic-lock/write surface.
- Verdict reconciliation is now separate from approve-family commit linkage and runs before the more expensive linkage parsing.
- The short-SHA matcher is shared by claim and review, removing the inconsistent duplicate logic.
- Cluster block/defer state preconditions now fail before eligibility filtering and leave the YAML unchanged on refusal.

## Verification run

- `vendor/bin/phpunit tests/Feature/Console/Sweep --no-coverage`: passed, 114 tests / 397 assertions.
- `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G`: passed, no errors.
- `./vendor/bin/pint --test app/ tests/Feature/Console/Sweep`: passed.
- `vendor/bin/phpunit --testsuite=Architecture --group=sweep-progress`: passed, Gate A=94 and Gate B=125.
- `vendor/bin/phpunit tests/Unit/Application/Sweep --no-coverage`: passed, 45 tests / 160 assertions.
- `git diff --stat dev..feat/tenant-isolation-sweep-execution -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher`: empty.
