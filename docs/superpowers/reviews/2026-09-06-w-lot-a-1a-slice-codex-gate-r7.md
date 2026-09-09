# Codex slice-plan gate r7 — W-LOT-A-1a rev 7 (gpt-5.6-sol, high, read-only, 2026-09-09)

Input: rev 7 at dfb5ff2af. Verbatim.

---
# Adversarial gate result

Audited read-only at HEAD `dfb5ff2af211dd37bba61d51a9c576d62d4c0436`. No edits, tests, or git writes were performed.

Resolved all 184 absolute `path:line` citation occurrences in rev 7, representing 135 unique targets. Zero were missing or out of range, and zero failed to support their stated claim.

The named round-5 scratchpad prompt is absent at HEAD; the minimum corrections enumerated in the audit request and gate r6 were used.

## BLOCKER

### B-C1 — Snapshot and backup commands construct the wrong tenant database name

**Plan:** `capture_permission_snapshot()` connects to `tenant_${tenant_id}` at [rev-7.md:2509](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-7.md:2509), and `backup-phase.sh` repeats that construction at [rev-7.md:2742](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-7.md:2742).

**Source:** Current configuration defines the database prefix as `tenant`, without an underscore, at [tenancy.php:59](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/tenancy.php:59) through line 63. Stancl constructs the default name by directly concatenating prefix, tenant key, and suffix at [DatabaseConfig.php:39](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/DatabaseConfig.php:39), while existing tenants may instead carry a persisted internal database-name override at [DatabaseConfig.php:66](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/DatabaseConfig.php:66). The application’s canonical resolver is `Tenant::getDatabaseName()` at [Tenant.php:264](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Domain/Tenant.php:264).

**Failure scenario:** A disposable tenant registered from the current image receives the current resolver’s database name, but Push-4 backup and every permission snapshot address `tenant_<uuid>`. The first `psql` or `pg_dump` fails with “database does not exist,” blocking INITIAL-OFF, Pushes 2/4/5, registration-pause verification, and rollback. Existing tenants are also unsafe to construct manually because their persisted `tenancy_db_name` may differ.

**Minimum correction:** Produce and persist an immutable `tenant UUID → Tenant::getDatabaseName()` mapping from the current API for every phase manifest, explicitly copy it to `/root/wlota1a/`, validate it one-to-one against the UUID manifest, and make every `psql`/`pg_dump` loop consume the resolved database name. Do not synthesize database names from UUIDs.

### B-B3a — Raw markers are still validated before aggregation, and several §11 gates lack committed comparisons

**Plan:** `run_delta()` invokes `reject_bad_output` inside the per-tenant loop before creating the aggregate at [rev-7.md:2648](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-7.md:2648) through line 2657. `run_reseed()` has the same ordering at [rev-7.md:2659](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-7.md:2659) through line 2668. The timestamp gate at [rev-7.md:3513](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-7.md:3513), initial registration-resume gate at [rev-7.md:3672](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-7.md:3672), and maintenance-environment restoration gate at [rev-7.md:3686](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-7.md:3686) have no committed-heredoc `cmp`.

**Source:** The governing correction requires raw command markers to be aggregated before validation and every affected gate to compare against a committed literal template at [gate-r5.md:40](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-a-1a-slice-codex-gate-r5.md:40). Gate r6 strengthens this to every §11 validation at [gate-r6.md:38](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-a-1a-slice-codex-gate-r6.md:38).

**Failure scenario:** A `FAILED` or `SKIPPED` marker triggers `fail_gate` before `markers.txt` is populated. A non-zero pipeline similarly exits under `pipefail` before aggregation and copy-back. Promotion stops, but the required complete raw evidence is lost rather than durably aggregated and then rejected. Separately, the resume/restoration blocks can succeed using only ad hoc `test` assertions, contrary to the mandatory committed-expectation contract and the plan’s own claim at line 4232.

**Minimum correction:** Capture every command status without exiting immediately, finish collecting every raw prefixed marker across all tenant logs, copy the raw aggregate to the host, and only then run bad-output/status and committed-template validation. Add literal expected records and `cmp -s ... || fail_gate '<name>'` to every remaining test-only §11 gate, including timestamp capture, registration response/resume, maintenance restoration, and served asset/fingerprint evidence.

## MAJOR

### M-E — Deployment correlation discards subsecond ordering and can reject the correct automatic deployment

**Plan:** Push 5 removes fractional seconds from `createdAt`, converts both timestamps to whole-second epochs, and requires strict `>` at [rev-7.md:3156](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-7.md:3156) through line 3161. Both fallback correlation checks repeat the same lossy comparison at [rev-7.md:3230](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-7.md:3230) and [rev-7.md:3252](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-7.md:3252).

**Source:** The observed record contract requires correlation by `description == "Commit: <sha>"` and `createdAt` after the push time, followed by polling that deployment ID, at [U-1-resolution.md:38](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-09-parapharmacy-staging-topology-U1-resolution.md:38).

**Failure scenario:** The push timestamp is recorded as `12:00:00Z`, and Dokploy creates the correct deployment at `12:00:00.600Z`. The plan strips `.600`, making both epochs equal; strict `>` rejects the valid record for ten minutes. The fallback has the same same-second false timeout.

**Minimum correction:** Preserve subsecond ordering, or use an inclusive whole-second lower bound together with a pre-push deployment-ID baseline that excludes pre-existing records. Apply the identical corrected correlation to the incident fallback and retain the committed record comparison.

## MINOR

None.

## Rev-6 closure table

| Finding | Status | Evidence line |
|---|---|---|
| B-B2 | NOT CLOSED | The non-destructive marked-tenant seeder branch and named PostgreSQL tests are specified at [rev-7.md:1466](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-7.md:1466) and [rev-7.md:1742](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-7.md:1742), but its mandatory Push-5/rollback snapshot proof cannot execute because the database name is synthesized incorrectly at line 2509. |
| B-B3a | NOT CLOSED | Registration/disposable producers and explicit `cmp` failure branches were added, but delta/reseed validation still precedes raw aggregation at lines 2648–2668, and multiple §11 gates still lack committed-heredoc comparisons. |
| M-E | NOT CLOSED | The observed `Commit: <sha>` shape, unordered search, and pinned deployment ID are present, but lines 3156–3161 lose subsecond ordering and can reject the correct record. |
| M-F | CLOSED | `RoleIndexResponseContractTest.php` appears in §8.1 at [rev-7.md:1132](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-7.md:1132) and the Push-3 inventory at [rev-7.md:2290](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-7.md:2290). |

## Rejected false positives

- A-1a correctly contains no hold schema, request/hold behavior, eligibility change, release/reject transition, or hold UI. Those remain A-1b/A-1c or later-lane work.
- A-1b’s four-state enum/CHECK is forward-compatible schema; it does not improperly implement release/reject behavior.
- There is no operative compose branch, compose command, compose-file edit, or Push 6. The remaining word “compose” appears only in assertions that the branch is absent.
- The Push-5 description format correctly follows the observed `Commit: <sha>` record. No `Hash: <sha>` correction is required.
- `SYNC_PERMISSIONS_ON_BOOT=true` is explicitly asserted; no gate claims boot synchronization is off.
- Protected-role backend rejection and Roles-page suppression/modal backstop have named red-first tests.
- `tenants:run` truthy normalization includes named PostgreSQL apply and verify integration tests.
- The Task-2 and Push-3 inventories include `RoleIndexResponseContractTest.php`.
- The A-1b ownership bullet lists at lines 181–190 and 4056–4065 are byte-identical.
- The manifest §4 checklist is byte-identical to the canonical checklist.

## Preserve

No additional regression was found in round-1 B1–B5, M1–M10, N1–N2; round-2 B-A, M-A, M-B; round-3 B-B1, M-D; round-4 M-C, N1; or round-5 B-B3b, N-B, N-C.

Preserve the gate-r3 list: Tasks 1/2/6-only scope; exact Q10 A-1b/A-1c split and four-state forward-compatible schema; location/null/empty-list semantics; scoped four-decimal totals; complete trace DTOs and owning-module adapters; middleware matrix; non-null team marker constraints, triggers, index, guarded rollback, and role-ID preservation; collision-safe transactional delta and custom-grant preservation; convention-09 evidence; generated map/type provenance; worker → API → scheduler ordering; required reviewers; verbatim manifest checklist; and both unrelated untracked files.

## Owner decisions required

None. All required corrections are mechanical consequences of current source, the accepted rulings, the resolved U-1 facts, and the established gate contract.

VERDICT: CHANGES-REQUIRED