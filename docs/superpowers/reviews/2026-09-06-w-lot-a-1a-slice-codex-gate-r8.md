# Adversarial gate result

Audited read-only at HEAD `a67a68c3f98d97c508428bb3aaab29c079b47a4a`. No edits, tests, or git writes were performed.

Checked all 199 absolute `path:line` citation occurrences in rev 8, representing 140 unique targets. Zero are missing or out of range, and zero fail to support their stated claims.

## BLOCKER

None.

## MAJOR

None.

## MINOR

None.

## Rev-7 closure table

| Finding | Status | Evidence line |
|---|---|---|
| B-C1 | CLOSED | The canonical in-container producer is specified at `rev-8.md:2439` and `rev-8.md:2767`; host and container one-to-one validation is at `rev-8.md:2466` and `rev-8.md:2734`. All operative `psql` and `pg_dump` consumers read the mapped database column at `rev-8.md:2540-2597` and `rev-8.md:2859-2890`. No §11 command synthesizes a database name from a UUID. |
| B-B3a | CLOSED | Delta, reseed, and census commands capture statuses and complete outputs before raw marker aggregation at `rev-8.md:2774-2832`; their directories are copied to the host before validation at `rev-8.md:2683-2719`. Migration follows the same order at `rev-8.md:3061-3080`. Snapshot and backup loops finish capture before validation at `rev-8.md:2540-2597` and `rev-8.md:2865-2890`. Explicit heredoc comparisons now cover registration response, pause, timestamp, capture, resume, maintenance restoration, and normal/fallback served evidence at `rev-8.md:3187`, `rev-8.md:3594`, `rev-8.md:3742`, `rev-8.md:3797`, `rev-8.md:3924`, `rev-8.md:3972`, `rev-8.md:3394`, and `rev-8.md:3507`. |
| M-E | CLOSED | The normal Push-5 path persists the pre-push deployment-ID baseline at `rev-8.md:3313-3320`, then requires exact `Commit: ${PUSH5_SHA}`, baseline exclusion, and an inclusive secondary timestamp bound at `rev-8.md:3329-3342`; it pins and polls one deployment ID to `done` and compares the committed template at `rev-8.md:3349-3370`. The incident fallback repeats those mechanics at `rev-8.md:3440-3492`. |

## Rejected false positives

- A-1a correctly excludes branch hold, eligibility, release, reject, sale/transfer refusal, and hold UI behavior. Those remain assigned to A-1b/A-1c.
- The remaining word “compose” appears only in statements proving that no compose branch, compose edit, compose service, or Push 6 exists. There is no operative compose reference.
- Push 5 correctly follows the observed Dokploy format `description == "Commit: <sha>"`; replacing it with canary-source `Hash: <sha>` would be incorrect.
- `SYNC_PERMISSIONS_ON_BOOT=true` is treated as the staging fact. The plan proves flag-off seeding is inert instead of claiming boot synchronization is disabled.
- The database mapping is produced through `Tenant::getDatabaseName()` inside the current API container. The configured default prefix and persisted override are not reimplemented in shell.
- The generic committed marker template remains valid: every invocation supplies an exact prefix and tail, expands it against the immutable manifest, and has an explicit `cmp -s … || fail_gate` branch.
- No finding is raised for the planning pin differing from audit HEAD: changes after `c76435df4a98193dfacb80ad9c25839169179509` affect documentation only, and every citation was re-resolved at the audited HEAD.

## Preserve

No regression was found in round-1 B1–B5, M1–M10, N1–N2; round-2 B-A, M-A, M-B; round-3 B-B1 and M-D; round-4 M-C and N1; round-5 B-B3b, N-B, N-C; or round-6 B-B2 and M-F.

Sections §6, §7, and §8, including §8.9, are byte-identical to rev 7. Their citations were nevertheless re-resolved at HEAD.

Preserved from gate r3: Tasks 1/2/6-only scope; the Q10 A-1b/A-1c boundary and forward-compatible four-state schema; location/null/empty-list semantics; four-decimal scoped totals; complete trace DTOs and owning-module adapters; middleware matrix; non-null team marker constraints, triggers, index, guarded rollback, and role-ID preservation; collision-safe transactional delta and custom-grant preservation; convention-09 evidence; generated map/type provenance; worker → API → scheduler activation order; required reviewers; and unrelated-file preservation.

Dispatchability checks pass: Tasks 1, 2, and 6 identify exact files and symbols; provide red-first tests with the first failing assertion, exact command, and lane; include convention-09 evidence; require both designated backend reviewers per task and the additional frontend reviewer for Task 6; retain the verbatim manifest checklist; and introduce no design outside named gate corrections.

The manifest §4 checklist is byte-identical to the canonical checklist.

## Split assessment

Tasks 1, 2, and 6 can be dispatched today with §11 carved out as a separately gated staging runbook.

Findings that would remain in that runbook: none. B-C1, B-B3a, and M-E are all closed in the §11 execution mechanics.

## Owner decisions required

None.

VERDICT: DISPATCH-READY