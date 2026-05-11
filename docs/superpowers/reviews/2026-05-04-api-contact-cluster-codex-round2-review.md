# Codex round-2 second-layer review — api.contact cluster

Review date: 2026-05-04
Branch tip reviewed: 2d7d81d51b410a8d1440b4a842020af41f46c3fd
Reviewer: codex (round-2 second-layer review post chain-orphan repair)

Verdict: APPROVE
Commit reviewed: a31f4c7c

## Round-1 finding closure
- Finding 1 (chain orphan): CLOSED. `sweep:inventory:verify-history` now reports `verified 577 event(s) across 262 callsite(s); 0 problem(s).` The repair commit rewrites the two api.contact claim events' `previous_yaml_sha256` from `c5739ab558edd2d367d687d56b595e01162e2774730fa7516b9c765e94a6ffd1` to `874f7bf66e6c6978ea3699565873d805ddb5262cc7a9705814988ecaaf034053`, and appends an `api.treasury.001` meta-event at `2026-05-04T19:35:41Z` documenting the one-shot repair.
- Finding 2 (unlinkParty NICE-TO-HAVE): unchanged from round 1.
- Finding 3 (POS Contact::find OUT-OF-CLUSTER): unchanged from round 1.

## New findings (round 2)
None blocking.

The chain rewrite is reversible from committed Git history: `git show a31f4c7c -- docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` preserves the exact removed `c5739ab558edd2d367d687d56b595e01162e2774730fa7516b9c765e94a6ffd1` values and the replacement `874f7bf66e6c6978ea3699565873d805ddb5262cc7a9705814988ecaaf034053` values. It is not fully reversible from the final YAML alone because the meta-event note abbreviates the orphan hash, but the repair is honestly documented in the audit doc and commit diff.

The timestamps are consistent with the repair story: the rewritten api.contact claim events retain their original claim timestamps, while the new `api.treasury.001` meta-event records the repair at `2026-05-04T19:35:41Z` with the repair cycle hashes. Runtime Contact code is unchanged by the chain repair: `git diff a2436417..a31f4c7c -- apps/api/app/Modules/Contact apps/api/tests/Feature/Contact` is empty.

Recommended follow-up remains the documented hardening: prevent data-changing `InventoryService::mutate()` calls with zero appended events, or auto-append a meta-event. A separate chain-link fingerprint would also make future edits to `previous_yaml_sha256` / `new_yaml_sha256` detectable by the inventory verifier instead of relying on Git review. This is a defense-in-depth follow-up, not a blocker for this repair.

## Audit exhaustiveness
- verify-history: PASS — `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` returned `verified 577 event(s) across 262 callsite(s); 0 problem(s).`
- Contact regression: PASS — `vendor/bin/phpunit tests/Feature/Contact/ContactTenantIsolationTest.php` returned `OK (9 tests, 35 assertions)`.
- Inventory spot-check: PASS — at `a31f4c7c`, `api.contact.001` and `api.contact.002` remain `under_review`, both have `fix_commit: a2436417`, and their regression tests are `test_store_rejects_cross_tenant_party_id` / `test_link_party_rejects_cross_tenant_party_id`.

## Confidence
High. I read the audit doc, reviewed `git show a31f4c7c`, verified the chain command and Contact regression suite, confirmed the Contact runtime diff is empty, and spot-checked the repaired inventory state. The only residual risk is procedural: until InventoryService is hardened, another one-shot no-event mutation can recreate this class of orphan.
