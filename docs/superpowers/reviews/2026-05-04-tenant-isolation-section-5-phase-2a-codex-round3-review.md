# Codex contrarian round-3 review - tenant-isolation sweep Section 5 Phase 2A

Review date: 2026-05-04
Branch tip reviewed: 3764b7fb
Round-1 verdict: BLOCK (5 findings)
Round-2 verdict: BLOCK (1 NEW critical, finding #6)
Reviewer: codex

## Verdict

APPROVE

## Round-2 Finding 6 status

- Status: CLOSED
- Notes: The remediation closes the round-2 terminal-fake-hash bypass. Non-seed `previous_yaml_sha256` and `new_yaml_sha256` values are now validated as canonical lowercase 64-character hex hashes, and a valid-looking terminal `new_yaml_sha256` must anchor to the document's current `metadata.yaml_sha256` somewhere in history. The orchestrator's deviation from my literal round-2 wording is sound: `InventoryService::mutate()` only stamps newly appended events on callsites whose history length increased (`stampNewHistoryEvents()` and `fillChainHashesOnNewEvents()` both skip `afterLen <= beforeLen`), so requiring every callsite terminal event to equal the current document hash would reject legitimate multi-mutation documents unless mutate rewrote old terminal chain fields on untouched callsites. That rewrite would be a semantic change to the append-only history model, not a necessary verifier fix.

## Deviation assessment

- **SOUND** - the orchestrator's pragmatic variant provides equivalent defensive surface for the cheap forgery that finding #6 identified; the literal guidance was over-strict.
- The literal per-callsite-terminal invariant is technically achievable only by changing `mutate()` to refresh older terminal `new_yaml_sha256` fields across untouched callsites on every write. Because chain fields are excluded from `canonicalForHashing()`, that would not improve resistance to a determined self-consistent forgery: an attacker able to recompute the canonical hash, update `metadata.yaml_sha256`, and set the forged event's chain hashes could also update all terminal hash fields. The real closure for that class remains the planned CI git-diff layer, not verify-history alone.
- A stricter nice-to-have would be a "latest event by timestamp has `new_yaml_sha256 == metadata.yaml_sha256`" check, but that depends on trusting timestamp ordering and is not required to close round-2 finding #6.

## New findings

None.

## What looks good

- `InventoryService::mutate()` behavior supports the rationale: both stamping passes operate only on newly appended history slots, so untouched callsites intentionally retain older terminal hashes.
- `isCanonicalHashFormat()` mirrors the schema regex for `previous_yaml_sha256` and `new_yaml_sha256` (`^[0-9a-f]{64}$`) and is applied to every non-seed non-null previous hash and every non-seed new hash.
- The anchor check is gated on `$observedNewHashes !== []`, compares against `$storedFileHash` from `metadata.yaml_sha256`, and is independent from the file-level canonical hash recomputation.
- The regression test for finding #6 uses `str_repeat('f', 64)`, so it proves format validation alone is insufficient and the anchor check rejects the forged terminal event. The negative control drives a real `sweep:inventory:claim` command and still passes verify-history.

## Verification run

- `vendor/bin/phpunit tests/Feature/Console/Sweep --no-coverage`: passed, 117 tests / 404 assertions.
- `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G`: passed, no errors.
- `./vendor/bin/pint --test app/ tests/Feature/Console/Sweep`: passed.
- `vendor/bin/phpunit --testsuite=Architecture --group=sweep-progress`: passed, Gate A=94 and Gate B=125.
- `vendor/bin/phpunit tests/Unit/Application/Sweep --no-coverage`: passed, 45 tests / 160 assertions.
- `git diff --stat dev..feat/tenant-isolation-sweep-execution -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher`: empty.
- `git diff --name-only e11aaf3a..3764b7fb`: only `apps/api/app/Console/Commands/SweepInventoryVerifyHistoryCommand.php` and `apps/api/tests/Feature/Console/Sweep/SweepInventoryVerifyHistoryCommandTest.php`.
