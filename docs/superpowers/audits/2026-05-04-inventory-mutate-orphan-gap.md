# Tenant-isolation sweep — InventoryService::mutate() chain-orphan gap

Audit date: 2026-05-04
Reporter: Claude Opus 4.7 (orchestrator) and Codex (api.contact round-1 review)
Status: KNOWN GAP — repaired forward; followup hardening pending

## Symptom

`php artisan sweep:inventory:verify-history` reports a `bootstrap-orphan` problem when more than one orphan `previous_yaml_sha256` is present. A legitimate inventory has exactly ONE orphan (the bootstrap hash before the first mutate). Multiple orphans indicate either forgery OR a workflow gap in `InventoryService::mutate()`.

## Root cause

`InventoryService::mutate()` updates `metadata.yaml_sha256` on every successful mutation, but the chain-link metadata (`previous_yaml_sha256` / `new_yaml_sha256`) is recorded ONLY on history events appended within that mutation cycle. Specifically:

- `stampNewHistoryEvents()` only iterates events appended by the caller (afterLen > beforeLen).
- `fillChainHashesOnNewEvents()` only fills chain hashes on those new events.

If a `mutate()` cycle changes data (e.g., a cluster's `review_gate.review_file` field) WITHOUT appending any history event:
- The metadata.yaml_sha256 transitions from `oldHash` to `newHash` at the file level.
- No event records `previous_yaml_sha256: oldHash, new_yaml_sha256: newHash`.
- Subsequent mutations read `newHash` as `storedHash` and append events with `previous_yaml_sha256: newHash`.
- The verifier sees `newHash` as a `previous_yaml_sha256` reference but no event has it as `new_yaml_sha256` → orphan.

## Real-world incident

The `repoint_treasury_review_file.php` one-shot script (run 2026-05-04 to update `cluster.review_gate.review_file` after the Treasury workflow closure) used `withClusterUpdate` only and appended no history events. This produced an orphan `c5739ab5…` between the Treasury flip script's `874f7bf6…` and the api.contact claim's reads. Codex round-1 api.contact review correctly REQUEST-CHANGES'd on this gate failure.

## Forward repair (this audit doc)

The orphan was repaired by `/tmp/repair_chain_orphan.php`:
- Rewrote all `previous_yaml_sha256: c5739ab5…` event references to the prior chain state `874f7bf6…`. The chain "skips" the no-event mutation for chain-tracking purposes; the content change (review_gate.review_file value) is preserved.
- Appended a meta-event to `api.treasury.001` documenting the repair operation, which the service chain-stamped with the current cycle's prev/new hashes (a legitimate audit-trail anchor).

Why this is safe (NOT forgery):
- Chain hashes are excluded from canonical content hashing (`canonicalForHashing` zeros `previous_yaml_sha256` and `new_yaml_sha256` on every event before SHA256). So edits to those fields do not change the metadata.yaml_sha256 and do not break the optimistic-lock check.
- The repair only erases the audit-trail GAP that the InventoryService mutate-without-event path created; no per-event content was forged.

After repair: `verify-history` reports `verified 577 event(s) across 262 callsite(s); 0 problem(s)`.

## Pending followup (hardening)

To prevent recurrence, ONE of:

1. `InventoryService::mutate()` should refuse to write if `eventsAppended === 0` AND the canonical content hash changed (the short-circuit at line 157 already covers no-op; this would extend it to reject "data change without event"). Callers must always append at least one history event.

2. `InventoryService::mutate()` should auto-append a synthetic meta-event whenever the data changes but the caller appended no events. The auto-event would carry `action: edit_applied` and a generic note indicating a non-event-driven mutation occurred.

Option 1 forces callers to be explicit (preferred for audit clarity). Option 2 is more lenient but ensures chain integrity even when callers forget.

Either fix should land on `feat/tenant-isolation-sweep-execution` or `dev` BEFORE further one-shot mutate scripts run, to prevent re-introducing chain orphans.

## References

- `apps/api/app/Application/Sweep/InventoryService.php::mutate` (line 82)
- `apps/api/app/Console/Commands/SweepInventoryVerifyHistoryCommand.php::handle` (bootstrap-orphan check, line ~190)
- Codex round-1 verdict: `docs/superpowers/reviews/2026-05-04-api-contact-cluster-codex-review.md` Finding 1
- Repair script: `/tmp/repair_chain_orphan.php` (one-shot; not committed)
