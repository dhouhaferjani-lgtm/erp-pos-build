# Production Environment Design v4 — Round-4 Decider Review

Reviewed the working-tree document at SHA-256 `f595fa92b1fa6ff6002183064081d49fc9e65a94c2d6a54b9dca36a6883c28f4`. The file changed externally during the review, so all citations below refer to that final hash.

The sandbox is read-only; no review file or other repository file was written.

## 1. R3-N1 — Mode-B fence

**Verdict: NOT CLOSED**

### Evidence

The requested textual changes are present:

- Normative use of `horizon:pause` is removed, and the document orders terminate immediately followed by Application stop: [design §4.6a](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1161).
- It records the worker task/container, requires zero running tasks, and checks exit code `0`, not `137`: [design steps 2–5](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1172).
- Probe B now has `psql -v ON_ERROR_STOP=1`, a sentinel, and the required assertion:

  > `[ "${CONNS:-probe_failed}" = "0" ] || … exit 1`

  at [design lines 1201–1220](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1201).

- `izipos_backup` is included in the revoke at [design line 1179](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1179).
- §7.7b uses the same ordering at [design lines 2091–2103](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:2091).
- The Redis reappearance correction is accurate. `RedisQueue::pop()` invokes `migrate()`, and only `migrate()` moves expired `:reserved` jobs: [RedisQueue.php:297](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/laravel/framework/src/Illuminate/Queue/RedisQueue.php:297), [RedisQueue.php:327](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/laravel/framework/src/Illuminate/Queue/RedisQueue.php:327). With no worker popping, the reserved job does not reappear on its own.

The decisive code contradiction is:

- Horizon supervisor timeout is still:

  > `'timeout' => 60`

  at [config/horizon.php:201](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/horizon.php:201).

- `ProcessImportJob` declares:

  > `public int $timeout = 3600;`

  at [ProcessImportJob.php:48](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Import/Application/Jobs/ProcessImportJob.php:48).

- Horizon’s master does not derive its graceful wait ceiling from the active job’s `$timeout`. It calls `longestActiveTimeout()`, waits only that long, then exits with the supplied status—default `0`: [MasterSupervisor.php:167](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/laravel/horizon/src/MasterSupervisor.php:167).
- `longestActiveTimeout()` is explicitly the maximum supervisor option:

  > `max(fn ($supervisor) => $supervisor->options['timeout'])`

  at [RedisSupervisorRepository.php:97](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/laravel/horizon/src/Repositories/RedisSupervisorRepository.php:97).
- The worker entrypoint uses `exec ... php artisan horizon`, making Horizon the container’s main process: [entrypoint-worker.sh:48](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint-worker.sh:48).

### Reasoning

D-21 is a real dependency: Docker’s grace period must exceed the longest job if service stop is expected to drain rather than SIGKILL it. But it is not sufficient.

With the current code, Horizon’s own master wait ceiling is 60 seconds. A legitimate import can run for 3,600 seconds. The master can therefore leave its wait loop and exit `0` while a long worker is still terminating. Docker treats the main process as the container lifecycle, so a long job can be lost without producing the design’s expected `137` signal. The post-stop exit-code assertion can consequently report `0` even though the job did not complete.

Probe A should subsequently remain non-zero and prevent the restore, which is fail-closed for database recovery, but it does not undo the already interrupted job or any partial external side effect. That fails the round-3 requirement that the drain itself be graceful.

There is also still a smaller replacement race: `horizon:terminate` and `docker service scale …=0` are two separate commands. Until the second reaches Swarm, desired replicas remain one. A fast Horizon exit can generate a replacement task in that interval. Step 5 checks the old task and current running count but does not mechanically compare the full task-ID set against the pre-stop baseline.

**Required fix:** align Horizon’s supervisor timeout with the maximum supported job timeout, validate the associated queue `retry_after` relationship, and make desired replica count zero before the original task can exit/restart—or replace the two-command protocol with a genuinely atomic service-stop primitive. The rehearsal must use a job longer than 60 seconds and reject any new task ID, not merely a non-zero final running count.

## 2. R3-N2 — paired restic completion

**Verdict: NOT CLOSED**

### Evidence

Several requested parts are correctly added:

- B1–B5 now create `role=data`, verify it, rewrite the manifest, take a full `role=complete` snapshot, and create a separate attestation: [design §4.4b2 lines 775–792](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:775).
- `restic restore latest` is explicitly forbidden at [design line 796](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:796).
- Restore requires an attestation naming the complete snapshot and verifies its tag and manifest hash: [design lines 797 and 813](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:797).
- `--read-data-subset` is honestly described as a repository sample; snapshot inventory is a separate check at [design line 789](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:789).
- The newly added §4.4b0 outside-in checksum chain correctly prevents restore from trusting an altered manifest or bad data: [design lines 743–751](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:743).

Two defects remain.

First, only B1—the `role=data` snapshot—is inventory-verified. B4 creates the actual restore candidate later:

> `restic backup … --tag "role=complete" "$STAGE"`

at [design lines 895–908](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:895), but there is no `restic ls`/inventory comparison for `$COMPLETE_SNAP` before B5 publishes the attestation and before the success ping.

The attestation records `inventory_sha256` at [design line 794](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:794), yet the pairing rule and restore table validate only `manifest_sha256`, not that inventory hash: [design lines 797 and 815](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:797).

Thus a complete/attestation pair can exist and O-3/O-3b can report success even if B4’s data generation differs from the B1 generation that was actually inventory-verified. The new §4.4b0 chain makes the eventual restore refuse bad data, but that is later than the “complete” marker and dead-man ping. The RPO monitor can still report a false complete cycle.

Second, retention is not pair-aware as claimed:

> `restic forget --group-by tags …`

at [design lines 909–914](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:909).

Every snapshot carries a unique `cycle=$TS` tag plus a role tag. Restic applies retention independently to each group; grouping on the unique cycle tag therefore gives each cycle/role its own tiny group, so the hourly/daily policy cannot age cycles globally. This does not make three snapshots age together—it prevents the intended cross-cycle retention calculation. Restic documents that retention is applied separately to each group in its [official forget documentation](https://restic.readthedocs.io/en/stable/060_forget.html).

The post-prune assertion is also incomplete:

> “no cycle may keep `data` without `complete`, or vice versa”

It omits `role=attestation`, even though restore requires it.

### Reasoning

No documented restic restore path uses `latest` or accepts a completely unpaired marker; that portion is closed. But the protocol still allows a marker pair to lead the generation that was actually verified, and its retention command does not implement its stated behavior.

**Required fix:**

- Inventory-verify `$COMPLETE_SNAP` before writing B5, compare it to the attested inventory hash, and make O-3b perform the same binding check.
- Replace `--group-by tags` with explicit cycle-level retention selection that forgets all three snapshot IDs for an expired cycle as one unit.
- Assert exactly one valid `data`, `complete`, and `attestation` snapshot per retained cycle, with all three mutually bound.

## 3. R3-N3 — bootstrap fixed point and enforcement

**Verdict: PARTIALLY CLOSED**

### Evidence

The fixed-point architecture is genuinely corrected:

- The dispatcher is required on `main` first at [design lines 1713–1717](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1713).
- Digests are moved out of git into an attested lock, while compose uses variables without defaults: [design lines 1719 and 1755–1764](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1719).
- Supersession is a lock swap, not a digest commit: [design lines 1730–1733](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1730).
- `RELEASE-SETS.md` is explicitly non-authoritative and never read by deploys: [design line 1760](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1760).
- V-10 correctly operates on runtime digests and uses a permanent bootstrap digest denylist after closure: [design §7.4c lines 2016–2039](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:2016).

There is no longer a commit → build → digest commit → rebuild cycle. That portion is closed.

The remaining defects are:

1. **The attempt cap is not concurrency-safe.** `BOOTSTRAP_STATE` is read, checked, and then incremented at [design line 1728](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1728), but no fixed bootstrap `concurrency` group, lock, or compare-and-swap operation is specified. Two concurrent dispatches can read the same `attempts_used`, both pass, and both build. GitHub documents that a `concurrency` key is the mechanism that ensures only one run with a given group executes at a time in its [official Actions concurrency documentation](https://docs.github.com/en/actions/how-tos/write-workflows/choose-when-workflows-run/control-workflow-concurrency).

   The document therefore enforces “up to three attempts” only under serial operator behavior; it does not mechanically enforce it under adversarial dispatch. It also no longer means literal “exactly once”—the intended contract is one environment bootstrap with up to three approved attempts.

2. **Offsite lock placement is sequenced before the offsite legs exist.** Phase 0.8b says the workflow writes the lock to both offsite legs, and 0.8c requires a cold-start dry run materializing it from an offsite leg: [design lines 2176–2178](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:2176). The Object Storage bucket and Storage Box are not created until Phase 4.1/4.2: [design Phase 4](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:2224).

   The initial artifact-store path can bootstrap if GitHub is available, so this is not the original fixed point. It is a new execution gap in the promised GitHub-independent cold-start fallback.

3. **Current source confirms these are future prerequisites.** `origin/main` currently contains only `ci.yml`, `smoke-test.yml`, and `sonarcloud.yml`; no dispatcher exists. The design’s claim that `react-doctor.yml` is also on `origin/main` at [line 1713](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1713) is stale.

**Required fix:** give the bootstrap workflow a fixed, non-cancelling concurrency group; increment and re-read `BOOTSTRAP_STATE` before any build credential is used; and either create both offsite legs before 0.8b/0.8c or defer the offsite-copy and offsite-only rehearsal evidence to Phase 4 while explicitly marking Phase 0 artifact-only.

## 4. Phase-0 code blockers

**Verdict: PARTIALLY CLOSED**

### 0.8b1 — dispatcher on main

This is a real prerequisite and correctly gates 0.8i at [design lines 2177 and 2186](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:2177). Current `origin/main` has no dispatcher, confirming it has not accidentally been assumed present.

Its internal concurrency contract still needs the R3-N3 fix above.

### 0.8h1 — `permissions:verify`

This is a real prerequisite. No `permissions:verify` implementation currently exists. The replacement is necessary because Spatie’s current command logs an error without returning failure:

> `elseif ($cacheExists) { $this->error('Unable to flush cache.'); }`

at [CacheReset.php:14](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/spatie/laravel-permission/src/Commands/CacheReset.php:14).

The v4 prose now consistently names Phase 0.8h1 at [design lines 1952 and 1987](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1952).

However, it is still sequenced after 0.8g even though 0.8g must call and dry-run `permissions:verify`: [design lines 2182–2184](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:2182). Move 0.8h1 before 0.8g or make the dependency explicit.

### 0.8j — `channels:reconcile` fail-closed

This is a real prerequisite and its requested change matches live source:

- A known-missing database is appended to `skippedTenantIds` and then `continue`s without changing the aggregate: [TenantScopedCommand.php:261](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Console/TenantScopedCommand.php:261).
- `ChannelReconcileCommand` ignores the boolean returned by `register()`: [ChannelReconcileCommand.php:130](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Channel/Infrastructure/Commands/ChannelReconcileCommand.php:130).
- `register()` returns `false` on missing ownership or central-write failure: [ChannelWebhookDirectoryRegistrar.php:45](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Channel/Infrastructure/Directory/ChannelWebhookDirectoryRegistrar.php:45).

Phase 0.8j’s contract at [design line 2185](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:2185) is correct and precedes later pipeline activation.

## 5. v4 new-defect scan

**Verdict: NOT CLOSED**

v4 introduced or newly exposed these defects:

1. **BLOCKER — Horizon’s 60-second master timeout defeats the new 3,600-second D-21 drain.** This can yield a container exit code of `0` while the long job did not finish.
2. **BLOCKER — `restic forget --group-by tags` groups on the unique cycle tag, so the advertised cycle retention is not implemented.**
3. **BLOCKER — `BOOTSTRAP_STATE` check/increment is not serialized, so concurrent dispatches can exceed the mechanical attempt contract.**
4. **MAJOR — Phase 0 requires offsite-lock operations before Phase 4 creates the offsite legs.**
5. **MAJOR — 0.8g consumes `permissions:verify` before 0.8h1 creates it.**
6. **MINOR — the `origin/main` workflow inventory is stale, and Probe A’s message still says “job reappeared” even though the corrected explanation says it remains reserved.**

The single most dangerous flaw is the Horizon timeout mismatch: it can make a long job interruption look like a clean exit, defeating the exact evidence v4 added to distinguish a drain from a kill.

# Final verdict — REJECT

R3-N1 and R3-N2 remain **NOT CLOSED**. R3-N3 is **PARTIALLY CLOSED**: the fixed point is genuinely broken, but its enforcement and cold-start sequencing are incomplete.

These remaining blocker items are **not safe to defer to buildout as implementation-time verification alone**. The drain lifetime, paired retention algorithm, bootstrap serialization, and offsite phase ordering must be corrected in the design first, then proven during implementation. The smaller numbering, stale-inventory, and message fixes are mechanical and can be handled without expanding scope, but they do not change the rejection.

A subsequent review can remain narrowly scoped to these exact residuals; already-confirmed round-1/2 matters do not need reopening.

