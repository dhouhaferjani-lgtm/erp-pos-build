# Round-3 Scoped Adversarial Re-gate — Production Environment Design v3

| | |
|---|---|
| **Date** | 2026-08-05 |
| **Scope** | Round-3 scoped re-gate of the round-2 dispositions for **F9; F13/N3; F16/F17/F33/N1; F7/N2; required items F1, F14, F21, and F26; the §7.7a–c queue-drain / `channels:reconcile` folds; and the §7.4b verifier-contract fold**. This is not a re-review of settled round-1 items. |
| **Document under review** | `docs/superpowers/plans/2026-08-05-production-environment-design.md` v3, 2,463 lines |
| **Prior records** | `docs/superpowers/reviews/2026-08-05-production-env-design-review.md` (round 1, REJECT) and `docs/superpowers/reviews/2026-08-05-production-env-design-review-round2.md` (round 2, REJECT) |
| **Repository state inspected** | `f592c6a5a3e103da5b168020b6cdde4578faefab`; existing unrelated dirty/untracked files were not modified |
| **Write scope** | This round-3 record only. No plan, source, config, workflow, gate sheet, or other repository file was changed. |

Disposition standard: **closed** means the v3 design is coherent and implementable against the live repository contracts. A future Phase-0 deliverable need not exist today, but its specified mechanism and ordering must be capable of producing it. A prose assertion, pseudo-command that does not fail closed, or future step with an unsolved dependency cycle does not close a finding.

## 1. F9 — Mode-B tenant-restore fence

### 1.1 NIT (confirmation) — the claimed current code facts are substantially accurate

**Plan citation:** `docs/superpowers/plans/2026-08-05-production-environment-design.md:1005-1016`.

**Repository citations:**

- `apps/api/app/Modules/Identity/Presentation/Middleware/ResolveTenancy.php:56-82` resolves the tenant, reads its central status, and returns HTTP 403 with `ORGANIZATION_UNAVAILABLE` for `Suspended` or `Archived`.
- `apps/api/app/Observers/TenantObserver.php:51-60` synchronously calls the token revoker on a transition to `Suspended`; `apps/api/app/Services/TenantTokenRevoker.php:48-78` synchronously enumerates tenant users (with the central-identity fallback) and deletes their central PAT rows. The observer is registered at `apps/api/app/Providers/AppServiceProvider.php:130-132`.
- `apps/api/bootstrap/app.php:71-83,85-103` installs and priority-pins `ResolveTenancy`; the only non-comment occurrence of `EnsureTenantIsActive` is its class definition at `apps/api/app/Modules/Tenant/Presentation/Middleware/EnsureTenantIsActive.php:12`, so the plan is right that it is not the enforcing middleware.
- `apps/api/config/horizon.php:164-175` sets `fast_termination` to `false`. Horizon's supervisor then waits for worker processes at `apps/api/vendor/laravel/horizon/src/Supervisor.php:208-240`, while the master waits for its supervisors at `apps/api/vendor/laravel/horizon/src/MasterSupervisor.php:167-203`.
- The worker image really does healthcheck only the `running` state at `apps/api/Dockerfile:160-168`; `horizon:status` returns 1 for paused and 2 for inactive at `apps/api/vendor/laravel/horizon/src/Console/StatusCommand.php:32-50`.
- `queue:monitor --json` really emits `size`, `pending`, `delayed`, and `reserved` per queue at `apps/api/vendor/laravel/framework/src/Illuminate/Queue/Console/MonitorCommand.php:21-24,68-117`; Redis `reservedSize()` is the reserved sorted-set cardinality at `apps/api/vendor/laravel/framework/src/Illuminate/Queue/RedisQueue.php:134-143`. The six configured queues are exactly those at `apps/api/config/horizon.php:201-219`, and Redis `retry_after` defaults to 90 seconds at `apps/api/config/queue.php:67-73`.

No fix is required for those factual corrections.

### 1.2 BLOCKER — step 3b closes the healthcheck trap only after the unsafe interval

**Plan citation:** the plan itself identifies the restart trap at `docs/superpowers/plans/2026-08-05-production-environment-design.md:1013`, but Mode B pauses at `:1023`, waits for graceful termination at `:1024`, and only then stops the worker Application at `:1025`. It allows the drain to take up to minutes at `:1063-1072` and H-19 at `:2426`.

**Repository citation:** the image reports unhealthy after three 30-second checks unless `horizon:status` contains `running` (`apps/api/Dockerfile:165-166`), while paused status is an exit-1 state (`apps/api/vendor/laravel/horizon/src/Console/StatusCommand.php:40-45`). A current job can legitimately outlive that window: `ProcessImportJob` declares a 3,600-second timeout at `apps/api/app/Modules/Import/Application/Jobs/ProcessImportJob.php:50-65`, and Laravel does not honor SIGTERM until after `runJob()` returns (`apps/api/vendor/laravel/framework/src/Illuminate/Queue/Worker.php:184-202,790-798`). Docker documents that a Swarm scheduler may reschedule unhealthy service containers ([Docker Swarm services](https://docs.docker.com/engine/swarm/services/)).

The result is not the promised graceful drain. During a long job, the worker task can become unhealthy and be replaced before step 3b. The old task can be killed mid-job and the replacement can resume consumption. Later reserved/backend probes may force a safe abort, but that is a fail-stop after an unacknowledged job interruption, not the active-job drain round 2 required. The residual table does not disclose this path.

**Concrete fix:** before `horizon:pause`, establish a drain mode that the worker healthcheck treats as healthy while still proving that the same task is present—for example, a root-owned drain sentinel checked by the healthcheck, with `paused` accepted only while that sentinel exists. Record the worker task ID, pause, terminate, wait for the same task to exit, then scale the service to zero and assert no replacement task appeared. Rehearse with a job whose timeout exceeds 90 seconds and prove both no replacement consumption and no forced kill.

### 1.3 BLOCKER — Probe B is described as mandatory but its verbatim loop never fails

**Plan citation:** both probes “must pass” at `docs/superpowers/plans/2026-08-05-production-environment-design.md:1027,1034`, but Probe B ends immediately after its loop at `:1052-1059`; unlike Probe A at `:1046`, it has no final `[ "$CONNS" = "0" ] || exit 1` assertion.

The script therefore continues to `REVOKE`/terminate even after all 30 attempts see an active backend. That silently changes the policy from “drain the request/scheduled command” to “kill it and accept its rollback or partial external effects.” Round 2 expressly required either a real drain or an explicit termination/loss policy. v3 specifies neither for this fall-through.

**Concrete fix:** append a fail-closed assertion immediately after the loop, before step 6, and preserve the last query/error separately so an empty/failed probe is not mistaken for zero:

```bash
[ "${CONNS:-probe_failed}" = "0" ] || {
  echo "DRAIN FAILED — $CONNS backend(s) remain; do NOT revoke or terminate"
  exit 1
}
```

Also run `psql` with `-v ON_ERROR_STOP=1` and make a query failure fatal.

### 1.4 MAJOR — the host backup identity is outside the fence and outside the six residuals

**Plan citation:** `izipos_backup` has explicit `CONNECT` on every DB at `docs/superpowers/plans/2026-08-05-production-environment-design.md:451`, and the hourly host timer uses it at `:625-634,817`. Mode B revokes only `PUBLIC`, `izipos_app`, and `izipos_provisioner` at `:1028`. The six-row residual table at `:1063-1072` mentions an operator `izipos_admin`/superuser session, but not the automated backup role/timer.

An hourly backup can reconnect after the twice-zero sample because its explicit grant remains. It is read-only, so it is not a live writer, but it can make `DROP DATABASE` fail, take an inconsistent dump while the restore is writing, and invalidate the claim that step 8 proves a stable zero-connection fence.

**Concrete fix:** stop and verify inactive the `erp-backup.timer` and any running `erp-backup.service` before Probe B; include `izipos_backup` in `REVOKE CONNECT`; after the restore, restore grants, restart the timer, and require the next complete cycle before closing the incident.

### 1.5 MAJOR — the six residual risks are not all honestly characterized

**Plan citation:** `docs/superpowers/plans/2026-08-05-production-environment-design.md:1063-1072`.

**Repository citation:** expired Redis reserved jobs are migrated only from the queue-pop path at `apps/api/vendor/laravel/framework/src/Illuminate/Queue/RedisQueue.php:297-303,321-346`. After Horizon has terminated and the worker Application is stopped, `queue:monitor` only counts; it does not migrate an expired reserved job.

Assessment of the six rows:

1. The pre-suspension HTTP-request and pre-stop scheduler rows are not actually bounded by Probe B while finding 1.3's missing assertion remains.
2. The “crashed worker's job reappears after 95 s” explanation is wrong. With no worker popping, an orphaned reserved entry stays reserved; it does not disappear and reappear. This is a safe false-negative (the probe remains non-zero), but the stated reason for the second sample is false.
3. The admin/superuser row candidly admits a reconnect-capable role remains; that means the fence is operationally disciplined, not “provable” in the absolute sense used at `:1001`.
4. The fleet-wide queue pause is honestly disclosed.
5. “none at `--max=999999`” for `QueueBusy` is only true while total queue size is below 999,999; the monitor dispatches on `size >= max` at `apps/api/vendor/laravel/framework/src/Illuminate/Queue/Console/MonitorCommand.php:115,156-169`.

**Concrete fix:** correct the retry explanation, add the Probe-B assertion, fence all automated connection identities, and relabel the remaining admin exception as an accepted operator-discipline residual rather than proof of race freedom.

**Scope-1 disposition: F9 remains NOT CLOSED.** The final revoke → terminate → twice-zero sequence can protect the destructive restore if followed perfectly, but v3 still does not deliver the required graceful, fail-closed pre-fence drain.

## 2. F13/N3 — split media legs, L4a-prime inventory, and DR-3 verification

### 2.1 NIT (confirmation) — the core no-delete credential conflict is closed

**Plan citation:** `docs/superpowers/plans/2026-08-05-production-environment-design.md:613-615,852-870,885-916,1204-1207`.

The core split is coherent: Leg A uses `rclone copy --immutable`, which does not delete destination-only objects and fails rather than modifies a changed destination; Leg B is the mutable restic snapshot history. `rclone check --one-way` correctly ignores destination-only stale keys while still requiring every source key to match. These semantics are confirmed by the official [rclone copy](https://rclone.org/commands/rclone_copy/), [global `--immutable`](https://rclone.org/docs/#immutable), and [rclone check](https://rclone.org/commands/rclone_check/) documentation.

The inventory now contains per-object output (`media-inventory.json`) and its own SHA at plan lines `680-686,904-908,918-926`; DR-3 step 9 at `:1206` requires per-key/hash comparison, not aggregate counts. There is no remaining `sync`/`--backup-dir` versus write-without-delete contradiction.

### 2.2 MAJOR — the deletion-retention statement contradicts the actual append-only model

**Plan citation:** D-18 says a deleted image remains only until lifecycle ages the “noncurrent version” at `docs/superpowers/plans/2026-08-05-production-environment-design.md:206`; the governing lifecycle table correctly says deleted source objects remain **current**, current versions have no expiry rule, and are retained indefinitely at `:869`. The same false “noncurrent” implication appears in the F13 disposition at `:2259`.

With `rclone copy`, a source deletion performs no destination operation. The destination object therefore does not become noncurrent; bucket versioning has no event from which to create a delete marker or noncurrent version. The 14-day noncurrent-version lifecycle cannot age it. Removal requires an owner-side delete/expiry action, and governance lock may delay that further.

**Concrete fix:** change every “until noncurrent lifecycle expiry” statement to “indefinitely until an owner-authorized deletion/expiry action is performed and any lock expires,” then make the owner-side erasure runbook and its maximum response time part of D-18.

### 2.3 MAJOR — the script cannot capture the advertised per-leg verification failures under `set -e`

**Plan citation:** the script enables `set -euo pipefail` at `docs/superpowers/plans/2026-08-05-production-environment-design.md:741-746`, then runs `rclone check ...; MEDIA_RC=$?` at `:774-775`, `rclone check ...; OS_RC=$?` at `:785-786`, and `restic check ...; RESTIC_RC=$?` at `:789-790`.

Any non-zero check exits the script before the assignment and before the explicit alert/promote logic. That fails safe in the narrow sense that no `complete` promotion occurs, but it does not implement the claimed captured exit codes, direct alert, or diagnostic manifest state.

**Concrete fix:** wrap every expected-to-fail verification in an `if` or temporarily disable `errexit`, for example `if rclone check ...; then OS_RC=0; else OS_RC=$?; fi`, and test each failure branch.

### 2.4 MAJOR (cross-reference to scope 4) — restic is not a coherent complete leg

**Plan citation:** `docs/superpowers/plans/2026-08-05-production-environment-design.md:709-716,788-799`.

Leg B's phase-3 command creates a new snapshot containing only `MANIFEST.json`; it does not replace or promote the earlier full `$STAGE` snapshot. This is detailed as the F7 blocker in finding 4.1. It does not revive the old write-without-delete conflict, but it means the two-leg completion promise that F13 depends on is not yet true.

**Scope-2 disposition: the F13/N3 command/credential conflict is CLOSED, but v3 introduces adjacent MAJOR defects and depends on the still-open F7 completion blocker.**

## 3. F16/F17/F33/N1 — one-time bootstrap exception

### 3.1 BLOCKER — the pre-promotion `workflow_dispatch` cannot run the new workflow as sequenced

**Plan citation:** the repository has no production build workflow today and the exception must precede promotion (`docs/superpowers/plans/2026-08-05-production-environment-design.md:1534,1538-1542`); Phase 0 lands the workflow on `dev` at `:1929`, dispatches it at `:1936`, and only then promotes it to `main` at `:1937`.

**Repository citation:** the default remote branch is `origin/main`. `.github/workflows/` currently contains only `ci.yml`, `react-doctor.yml`, `smoke-test.yml`, and `sonarcloud.yml`; the only existing general manual CI entry point is `.github/workflows/ci.yml:1-8`, not the designed production build/push workflow.

GitHub's official [manual workflow documentation](https://docs.github.com/en/actions/how-tos/manage-workflow-runs/manually-run-a-workflow) states that a `workflow_dispatch` workflow must exist on the default branch. The new workflow exists only on `dev` at Phase 0.8i, so it has no dispatchable default-branch definition. This is a hard execution failure, not a governance nuance.

**Concrete fix:** either (a) first land a bootstrap-only dispatcher/guard on `main` in a separately reviewed control-plane commit, then dispatch the designated `dev` ref; or (b) explicitly implement the bootstrap job inside the already-default-branch `ci.yml` manual workflow and prove that dispatching `ref=dev` executes the reviewed dev definition. The design must name one path and rehearse it; “a new workflow on dev” is not runnable.

### 3.2 BLOCKER — supersession recreates the digest/commit fixed-point cycle

**Plan citation:** the bootstrap digests are committed in the promotion commit at `docs/superpowers/plans/2026-08-05-production-environment-design.md:1542`; after the first green-main build, the plan requires updating the checked-in manifest to the new main digests at `:1543-1545,1568` and updating the checked-in release-set ledger at `:1617-1622`. Yet `:1547` rejects a second commit specifically because that commit would itself be un-deployed main drift.

The exception moves the cycle; it does not break it. Build main commit M1 → obtain M1 image digests → commit those digests/ledger as M2 → provenance-bearing images for M2 have different digests. Production may run M1 while `main` is M2, exactly the state `:1547` claims to prevent. If the workflow builds M2, the manifest is again one generation behind.

**Concrete fix:** separate source release identity from mutable deployment-state metadata. One workable model is: keep a secret-free compose template in git, store the signed/digest-pinned release-set lock as a workflow artifact plus both backup legs, and have V-10/V-12 verify that attested lock against running services. If a checked-in digest lock is mandatory, adopt an explicit two-commit protocol and define “release SHA” as the source commit whose app contexts were built, while the metadata-only pin commit is exempted from rebuilding by a path-filtered, audited rule. The present claim that no two-commit protocol is needed must be removed.

### 3.3 MAJOR — “exactly one” is contradicted, and the sunset is not mechanically enforceable

**Plan citation:** the normative exception permits exactly one build, no rerun, at `docs/superpowers/plans/2026-08-05-production-environment-design.md:1538-1541`, but `:1560` permits repeated bootstrap attempts whenever Phase 0.9 is red and redefines once as “once per environment, not once per attempt.” D-15 repeats that re-bootstrap permission at `:203`.

The loosening is understandable before any tenant exists, but it turns a one-time exception into an open capability whose closure condition is “eventually green.” The proposed disable action—“`workflow_dispatch` is disabled (or the exception's approval is revoked)”—has no implementation. GitHub can disable an entire workflow through UI/API ([workflow management](https://docs.github.com/en/actions/how-tos/manage-workflow-runs)); it does not dynamically remove one trigger from a multi-trigger workflow. Removing `workflow_dispatch` in git is another post-build commit and interacts with finding 3.2. Environment approvals gate individual jobs; they are not an immutable global revocation of this exception.

**Concrete fix:** replace the prose exception with a workflow guard backed by a named state record (`bootstrap_open`, designated SHA, attempt counter, and `bootstrap_closed_at`). Require a fresh owner approval per changed SHA, cap attempts or require a new dated D-15 amendment beyond the cap, and make every bootstrap job fail before credentials are read when `bootstrap_open=false`. Record and test the closure transition.

### 3.4 MAJOR — V-10's bootstrap detection and initial pass rule are contradictory

**Plan citation:** V-10 normally requires every running service to match a specific owner-approved all-checks-green run at `docs/superpowers/plans/2026-08-05-production-environment-design.md:1723`; the first deployment says the full V-suite passes while V-10 reports bootstrap digests “expected” at `:1973`; after supersession V-10 must reject any bootstrap digest at `:1544-1545,2036`.

No V-10 command or state input is specified. A running container reports a digest, not the tag that was used to pull it, so “contains `bootstrap-`” cannot be inferred from runtime inspection. The designed verifier must compare against an attested digest set, and it needs two explicit modes: bootstrap allowed before closure, bootstrap denylisted after closure. `scripts/verify-production-release.sh` is only a future deliverable at `:1934`, so the missing contract cannot be verified in live source.

**Concrete fix:** define V-10's inputs and pass table: exact approved CI/dispatch run ID; source SHA; five expected digests; bootstrap-open/closed state; and the permanent bootstrap digest denylist. At initial deploy, pass only if `bootstrap_open=true` and every digest equals the one approved dispatch. After closure, fail if any running digest is in the bootstrap set or differs from the current green-main release set.

### 3.5 NIT (confirmation) — the TLS branch itself is now singular

**Plan citation:** `docs/superpowers/plans/2026-08-05-production-environment-design.md:1578-1592,1209-1212`.

v3 now has one normal cold-start path: DNS A/AAAA cutover, Traefik, Let's Encrypt HTTP-01, then app services. The staging-endpoint/browser-exception text is explicitly an incident fallback, not a competing normal bootstrap. No remaining round-2 TLS non-decision was found.

**Scope-3 disposition: F16/F17/F33/N1 remain NOT CLOSED.**

## 4. F7/N2 — staged, verified, complete manifests and monitoring

### 4.1 BLOCKER — the restic “complete re-upload” is a different, manifest-only snapshot

**Plan citation:** phase 2 backs up the entire staged directory and records `$SNAP` at `docs/superpowers/plans/2026-08-05-production-environment-design.md:788-790`; phase 3 rewrites the manifest and runs `restic backup "$STAGE/MANIFEST.json"` at `:793-799`. The prose claims the complete manifest is re-uploaded to both legs at `:704-716`.

Official restic behavior is that every `restic backup` creates a new snapshot ([restic backup manual](https://restic.readthedocs.io/en/stable/manual_rest.html)). The first snapshot contains the full cycle with a staged manifest. The second contains only the one manifest file. Restic does not mutate the first snapshot. Consequently there is no single restic snapshot that contains the cycle data and its complete marker:

- restoring `$SNAP` yields all data but `result=staged`;
- restoring the latest manifest-only snapshot yields no dumps;
- the complete manifest records the staged snapshot ID before the complete snapshot exists.

This is the exact false-completion class F7 was meant to eliminate, now moved from “before uploads” to “split across immutable restic snapshots.”

**Concrete fix:** define a paired-object protocol for restic. For example, keep the immutable full data snapshot ID, then create a small completion-attestation snapshot that names that data snapshot ID, hashes its tree/file inventory, records both verification results, and is explicitly restored first. Restore then fetches the named data snapshot and verifies the binding. Alternatively, take a second full-directory snapshot after rewriting complete (dedup makes it cheap) and select it by an external tag/attestation; do not attempt to embed that snapshot's own ID inside itself.

### 4.2 MAJOR — `restic check --read-data-subset=5%` does not verify “the new snapshot”

**Plan citation:** `docs/superpowers/plans/2026-08-05-production-environment-design.md:709,789-790` calls this independent verification “on the new snapshot.”

The command checks repository structure and a random 5% of repository pack data; it is not scoped to `$SNAP` and does not read every blob reachable from that snapshot. The official [restic repository-check documentation](https://restic.readthedocs.io/en/stable/045_working_with_repos.html) explicitly defines a percentage as a random subset of repository pack files.

**Concrete fix:** state the weaker evidence honestly, or perform a snapshot-scoped restore/read verification of the new cycle inventory. At minimum, use `restic ls --json "$SNAP"` to compare the complete expected file list and periodically rotate deterministic `n/t` subsets so all packs are covered; for launch rehearsal, restore the named snapshot and validate all `SHA256SUMS`.

### 4.3 MAJOR — O-3b reads only the Object Storage marker and cannot detect restic-side promotion drift

**Plan citation:** O-3 is correctly keyed to the last complete promote at `docs/superpowers/plans/2026-08-05-production-environment-design.md:717-720,1437`; O-3b reads only the newest Object Storage `MANIFEST.json` at `:1438`.

If Object Storage receives `complete` but restic's marker snapshot is missing, staged-only, or disconnected from its data snapshot, O-3b still reads an Object Storage document that claims both states are verified. It does not read the restic leg remotely. The dead-man detects a failed script before ping, but it does not independently audit the property its name claims after the fact.

**Concrete fix:** make O-3b query both legs. On Object Storage, read the remote complete marker. On restic, locate the completion attestation/full complete snapshot and verify its binding to the data snapshot. Alert if either leg is absent, staged, inconsistent, or older than 90 minutes.

### 4.4 NIT (confirmation) — the Object Storage half of the three-phase protocol is correctly ordered

**Plan citation:** `docs/superpowers/plans/2026-08-05-production-environment-design.md:700-720,779-807`.

The local marker begins staged, Object Storage data is uploaded and checked, complete is written only after both verification return codes are zero, the Object Storage marker is overwritten with `PutObject`, and the ping is after promotion. `rclone copy` does not delete destination data, and this marker overwrite is intentionally outside `--immutable`. Subject to fixing the `set -e` capture defect in finding 2.3, this half is coherent.

**Scope-4 disposition: F7/N2 remain NOT CLOSED because Leg B has no coherent complete restore candidate and O-3b does not audit it.**

## 5. Spot verification of four round-2 REQUIRED closures

### 5.1 MAJOR — F1 / V-11 is still not executable or fail-closed

**Plan citation:** `docs/superpowers/plans/2026-08-05-production-environment-design.md:1729-1775`.

**Repository citations:** Spatie's cache command checks whether the cache existed and may print `Unable to flush cache`, but its `handle()` returns no failure code at `apps/api/vendor/spatie/laravel-permission/src/Commands/CacheReset.php:14-24`. The configured logical key/store are `spatie.permission.cache`/`default` at `apps/api/config/permission.php:177-201`; the actual cache key can carry the application prefix at `apps/api/config/cache.php:108-115`.

All three probes have executable gaps:

1. V-11a says the workflow writes `expected-permissions.txt` (`plan:1735`) but the command reads an undefined `expected-permissions.txt.sqlvalues` (`:1742`). No safe quoting/transformation into `('name')` values is specified. The alternative “`SELECT id FROM tenants`” at `:1738` produces tenant UUIDs, not physical database names, yet the loop passes each value to `psql -d`.
2. V-11b is only a SQL query (`:1754-1762`). `psql` exits 0 when a query returns rows, so “EXPECTED OUTPUT: zero rows” is not converted into a failing deploy exit.
3. V-11c trusts the Artisan exit at `:1767-1768`, but the live command returns success even on its error branch. The following Redis scan at `:1769-1770` is printed but never asserted empty.

**Concrete fix:** make the commissioned `permissions:verify {--tenant=} {--expect=<file>}` command the normative Phase-0 gate now, with tests for quoting, empty input, unknown tenant, missing permission, unassigned permission, and per-tenant DB-name resolution. For V-11c, fail on any non-empty Redis scan result and test the `forgetCachedPermissions() == false && cache existed` branch; do not use the current Artisan return code as evidence.

**Disposition: F1 remains NOT CLOSED.**

### 5.2 MAJOR — F26's Stancl trap analysis is correct, but the grant listener is not implementable as shown

**Plan citation:** `docs/superpowers/plans/2026-08-05-production-environment-design.md:430-489,1933,1956`.

**Repository citations:** the trap note is correct. Stancl chooses the same template connection for runtime config at `apps/api/vendor/stancl/tenancy/src/DatabaseConfig.php:100-117`, while `manager()` injects that name into the DB manager at `:149-164`; PostgreSQL create/drop use the manager's connection at `apps/api/vendor/stancl/tenancy/src/TenantDatabaseManagers/PostgreSQLDatabaseManager.php:18-40`, and `makeConnectionConfig()` itself does not read the manager connection at `:47-51`. The current synchronous provisioning call is confirmed at `apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:106-123`.

The proposed manager subclass that ignores Stancl's supplied manager connection and pins DDL to `provisioning` is coherent. The grant listener is not: the plan's listener SQL block contains `\connect "<db>"` at plan lines `471-475`. `\connect` is a **psql client meta-command**, not SQL that a Laravel `DB::statement()` listener can send ([PostgreSQL psql documentation](https://www.postgresql.org/docs/18/app-psql.html)). A connection initially aimed at central cannot issue the schema grant inside the new tenant DB using that block.

**Concrete fix:** in the `DatabaseCreated` listener, grant database-level rights on the provisioning connection, then create/purge a temporary Laravel connection whose database is the newly created tenant DB and whose credentials are `izipos_provisioner`; issue `GRANT USAGE, CREATE ON SCHEMA public` through that connection. Test `current_user`/`current_database()` in both the DDL listener and the final tenant runtime connection.

The residual that the API container still holds the provisioning credential is honestly disclosed at plan lines `485-489` and explicitly accepted by D-19 at `:207`.

**Disposition: F26 is PARTIALLY CLOSED; the role model is sound, but the required grant mechanism needs correction.**

### 5.3 NIT (confirmation) — F14 is closed as a designed owner gate, but remains open in current execution state

**Plan citation:** `docs/superpowers/plans/2026-08-05-production-environment-design.md:930-970,2035-2036` supplies the exact E-4a row/hard-rule amendments and blocks onboarding until the named human inserts and closes them.

**Repository citation:** the current human-owned evidence sink still says only E-7 is non-waivable at `docs/handoff/OWNER-manual-launch-gates-2026-07-31.md:18-31`, and E-4 still permits owner risk acceptance at `:37-43`.

This is no longer presented as already enforced: v3 makes the sheet amendment a named owner prerequisite and leaves Phase 7.12 blocked until it exists. That is a coherent future deliverable under the review standard. No design fix is required, but the launch remains NO-GO until the current file is amended by its authorized human owner.

**Disposition: F14 CLOSED AS DESIGNED, OPEN AS AN EXECUTION GATE.**

### 5.4 NIT (confirmation) — F21's phase order and production pinning are corrected

**Plan citation:** the safe order is explicit at `docs/superpowers/plans/2026-08-05-production-environment-design.md:1347-1364` and now matches Phase 0.2 before 0.3 at `:1918-1921`; the production service table pins MinIO/mc at `:287-297`.

**Repository citation:** the existing staging-shaped compose still uses `minio/minio:latest` and `minio/mc:latest` at `docker-compose.dokploy.yml:102-127`, but v3 explicitly classifies that file as non-production at plan lines `535-545` and makes the future production manifest the digest-pinned source of truth.

**Disposition: F21 CLOSED.**

## 6. Session-fact folds — queue drain, `channels:reconcile`, and verifier contracts

### 6.1 NIT (confirmation) — QD-2 and QD-3 match the live serialization shapes

**Plan citation:** `docs/superpowers/plans/2026-08-05-production-environment-design.md:1842-1850`.

**Repository citations:**

- Marketplace jobs now use declared nullable defaults at `apps/api/app/Modules/Marketplace/Infrastructure/Jobs/SyncSellerListingsJob.php:56-88` and `apps/api/app/Modules/Marketplace/Infrastructure/Jobs/ReconcileListingsJob.php:68-101`; legacy payloads therefore restore `tenantId=null` and are discarded with a warning.
- The two import jobs still have promoted required readonly anchors at `apps/api/app/Modules/Import/Application/Jobs/ProcessImportJob.php:58-66` and `apps/api/app/Modules/Import/Application/Jobs/ProcessProductImageImport.php:50-59`.
- Laravel skips absent serialized property keys at `apps/api/vendor/laravel/framework/src/Illuminate/Queue/SerializesModels.php:73-99`, so the QD-3 uninitialized-read analysis is correct.

The permanent “name the affected classes and drain before incompatible serialized-shape changes” rule is accurate. The standing drain procedure at plan lines `1852-1868` inherits the F9 healthcheck/retry defects from scope 1 and must be fixed with them.

### 6.2 MAJOR — §7.4b is stale against today's verifier signatures

**Plan citation:** `docs/superpowers/plans/2026-08-05-production-environment-design.md:1777-1800`, especially the `fiscal:verify-chains` signature with `{--fix}` at `:1783` and the ticket saying that flag is still declared at `:1800`.

**Repository citations:**

- `fiscal:verify-chains` is now `{--tenant=}{--company=}{--type=}` with **no `--fix`** at `apps/api/app/Modules/Compliance/Commands/VerifyFiscalChainsCommand.php:59-72`. Its fleet iterator and fail-closed aggregate are at `:118-142,196-249`.
- `pos:verify-chains` remains `{--tenant=}{--company=}{--terminal=}{--type=all}` at `apps/api/app/Modules/POS/Commands/VerifyPosChainCommand.php:68-77`; filtered-nothing failure and final aggregate behavior are at `:198-259`.
- `fiscal:verify-event-chain` remains the single-tenant command with required `tenant`, `terminal`, and `actor-id` at `apps/api/app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php:89-97,124-188`.
- `fiscal:preflight-gate` remains `{--tenant=}` at `apps/api/app/Modules/Fiscal/Infrastructure/Commands/PreflightFiscalGateCommand.php:53-56,78-146`.

The plan's POS/event/preflight signatures and the rule not to wrap these commands in `tenants:run` remain correct. The fiscal-document signature and removal ticket changed again today and must be updated before the future V-suite script is authored; otherwise an operator following the table may pass an undefined option.

**Concrete fix:** remove `{--fix}` and the removal ticket from §7.4b, update its source range to `VerifyFiscalChainsCommand.php:69-72`, and generate the V-suite command list from `php artisan help <command>` in a regression test so another signature change fails CI.

### 6.3 MAJOR — `channels:reconcile` does not have the fail-closed exit contract v3 assigns it

**Plan citation:** PM-1 says a non-zero exit reliably covers a missing DB/mis-migrated tenant and must gate deployment at `docs/superpowers/plans/2026-08-05-production-environment-design.md:1870-1884`; Phase 6.6 makes the exit code deployment-critical at `:2017`.

**Repository citations:** `channels:reconcile` has no options and does extend `TenantScopedCommand` at `apps/api/app/Modules/Channel/Infrastructure/Commands/ChannelReconcileCommand.php:63-83`. It returns the base aggregate unchanged at `:83-88,164-178`. The base records a known-missing tenant DB as skipped but does **not** change the aggregate from success at `apps/api/app/Console/TenantScopedCommand.php:261-318,347-360`. In addition, directory registration returns `false` rather than throws on a per-pointer central write failure at `apps/api/app/Modules/Channel/Infrastructure/Directory/ChannelWebhookDirectoryRegistrar.php:29-45,56-81`, while the command ignores that boolean at `apps/api/app/Modules/Channel/Infrastructure/Commands/ChannelReconcileCommand.php:130-149`.

Thus the command can exit 0 after skipping a missing tenant database or failing one or more pointer writes—the exact states the plan says make its exit “trustworthy.” The separate central-count/webhook verification at plan line `1884` can catch many such cases if it is actually implemented fail-closed, but it does not make the stated command contract true.

**Concrete fix:** have `ChannelReconcileCommand` inspect `skippedTenantIds()` and return failure when non-empty; count `register() === false` and return failure after completing the fleet; retain per-tenant continuation. Add tests for a known-missing physical tenant DB and a registrar write failure. Keep the central count and 403-not-404 probes as independent deployment assertions.

### 6.4 MINOR — §7.7 duplicates the same unfiltered-iteration note

**Plan citation:** `docs/superpowers/plans/2026-08-05-production-environment-design.md:1888-1890` repeats the same `TenantScopedCommand::forEachTenant` paragraph twice with only minor wording changes.

**Concrete fix:** retain one paragraph and update it to distinguish a probe fault (non-zero aggregate) from a known absent DB (currently skipped with success, as finding 6.3 documents).

**Scope-6 disposition:** the queue-payload facts are current, but the fiscal signature fold and `channels:reconcile` exit contract are stale against live source.

## 7. Defects newly introduced by v3 or exposed by today's source

The following were not round-1/round-2 findings in their present form:

- **BLOCKER — R3-N1:** v3's healthcheck-aware Mode-B fix still leaves the unhealthy/replacement window open before step 3b (finding 1.2), and Probe B lacks its fail-closed assertion (finding 1.3).
- **BLOCKER — R3-N2:** v3's restic promotion creates a manifest-only snapshot disconnected from the staged data snapshot (finding 4.1), so a false `complete` is still possible.
- **BLOCKER — R3-N3:** v3's new bootstrap exception cannot dispatch its newly introduced workflow before that workflow exists on the default branch, and its supersession commit recreates the digest fixed-point cycle (findings 3.1-3.2).
- **MAJOR — R3-N4:** v3's new V-11 pseudo-commands do not generate valid inputs or convert their expected outputs into failing exits (finding 5.1).
- **MAJOR — R3-N5:** v3's new provisioner listener uses a psql-only `\connect` meta-command as though it were Laravel-executable SQL (finding 5.2).
- **MAJOR — R3-N6:** today's verifier source removed `--fix`, and today's `channels:reconcile` implementation does not match v3's asserted fail-closed contract (findings 6.2-6.3).
- **MAJOR — R3-N7:** v3's D-18 noncurrent-version statement is false for source deletions under append-only `rclone copy` (finding 2.2).

These are scoped new-defect findings only; they do not reopen unrelated settled round-1 items.

## Final verdict — REJECT

Remaining blockers:

- **F9:** the Mode-B drain is not fail-closed or reliably graceful before the DB fence: the worker healthcheck can replace the draining task, and Probe B falls through after timeout.
- **F16/F17/F33/N1:** the bootstrap workflow is not dispatchable in its pre-promotion location, and the first green-main supersession recreates the digest/commit cycle.
- **F7/N2:** the restic leg has no coherent snapshot containing both the cycle data and its `complete` marker; O-3b audits only the Object Storage assertion.

Required but non-blocker fixes before the next re-gate: make V-11 truly executable; correct the F26 listener's cross-database grants; update §7.4b to the current no-`--fix` signature; make `channels:reconcile` fail on skipped tenants/pointer-write failures; correct D-18's deletion lifetime; and fix the `set -e` verification-result capture.

The most dangerous remaining flaw is the restic false-completion design: operations can advertise a verified two-leg recovery point even though the restic data and its `complete` marker live in different, non-self-contained snapshots.
