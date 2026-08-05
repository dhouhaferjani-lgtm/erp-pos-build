# Adversarial Review — Production Environment Design

Reviewed document: `docs/superpowers/plans/2026-08-05-production-environment-design.md`

Review basis: current working tree at `057c0c3e195e1377e75957b43b8b6be233583489`, with read-only inspection of `origin/main` and `origin/dev`. Provider, panel, live-server, DNS, pricing, and legal claims that cannot be established from this repository are explicitly marked **UNVERIFIED**.

## 1. VERIFY CLAIMS AGAINST CODE

### 1. Entrypoint failure semantics are broader than the design admits

- **Severity:** REQUIRED
- **Design-doc section attacked:** §1.3 F-2, §3.4, §7.4
- **Evidence:** Only the API target runs `apps/api/docker/entrypoint.sh`; the worker, scheduler, and websocket targets use their own entrypoints (`apps/api/Dockerfile:150-168`, `apps/api/Dockerfile:170-186`). The API entrypoint tries the database five times and then continues if it is unavailable (`apps/api/docker/entrypoint.sh:103-120`). It runs central `php artisan migrate --force`, but catches failure and continues (`apps/api/docker/entrypoint.sh:122-127`). It then runs `tenants:migrate-rolling --force`, catches failure, logs “completed with per-tenant errors,” and continues (`apps/api/docker/entrypoint.sh:129-145`). Permission reseeding runs only when `SYNC_PERMISSIONS_ON_BOOT=true` and its failure is also non-fatal (`apps/api/docker/entrypoint.sh:147-161`). `permission:cache-reset` runs unconditionally *within the database-connected branch* but is explicitly suppressed with `|| true` (`apps/api/docker/entrypoint.sh:163-167`); it does not run at all when the initial database check fails because the entire migration/seeding block is skipped (`apps/api/docker/entrypoint.sh:118-120`, `apps/api/docker/entrypoint.sh:207`). Therefore the design correctly identifies the rolling-migration trap but understates it: central migration failure, database-unavailable startup, permission reseed failure, and cache-reset failure can all still yield a serving API container.
- **Fix:** Make migration/reseed state an explicit release gate outside container boot. At minimum, fail the deploy on database unavailability, pending/failed central migrations, any tenant migration error, requested permission reseed failure, or permission-cache reset failure. Do not use container health as evidence that schemas or permissions are current.

### 2. Tenant database names are stored first and derived only as a fallback

- **Severity:** REQUIRED
- **Design-doc section attacked:** §3.2, Phase 0.5, Appendix C
- **Evidence:** The current prefix is the literal `tenant` with no underscore (`apps/api/config/tenancy.php:58-63`). Stancl resolves a name as stored internal `db_name` first, falling back to `prefix + tenant key + suffix` only when that value is absent (`apps/api/vendor/stancl/tenancy/src/DatabaseConfig.php:39-41`, `apps/api/vendor/stancl/tenancy/src/DatabaseConfig.php:66-69`). `CreateDatabase` calls `makeCredentials()` before database creation (`apps/api/vendor/stancl/tenancy/src/Jobs/CreateDatabase.php:30-43`), and `makeCredentials()` sets and saves `db_name` (`apps/api/vendor/stancl/tenancy/src/DatabaseConfig.php:81-97`). Internal keys are stored as `tenancy_*` attributes (`apps/api/vendor/stancl/tenancy/src/Database/Concerns/HasInternalKeys.php:12-30`); because `tenancy_db_name` is not a custom physical column (`apps/api/app/Modules/Tenant/Domain/Tenant.php:138-179`), the virtual-column trait serializes it into `tenants.data` (`apps/api/vendor/stancl/virtualcolumn/src/VirtualColumn.php:62-84`), whose JSONB column exists in the schema (`apps/api/database/migrations/2025_11_30_000001_create_tenants_table.php:16-27`). The application’s provisioning path dispatches that exact `CreateDatabase` job (`apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:106-123`).

  Consequently, setting `TENANCY_DB_PREFIX=izipostenant_` only in production will not redirect correctly provisioned existing staging tenants: their stored `tenancy_db_name` wins, while staging’s unset env would retain the `tenant` default anyway. It *will* change lookup for any legacy/corrupt tenant row lacking `data.tenancy_db_name`. Also, the repository default would derive `tenant<uuid>`, not `tenant_<uuid>`; if live staging databases really have an underscore, their stored names are load-bearing. The live rows are **UNVERIFIED** from this repository.
- **Fix:** Keep the env change, but precede it with a blocking central-DB audit that outputs tenant id, stored `data->>'tenancy_db_name'`, derived name, and matching `pg_database.datname`. Backfill missing stored names to the exact physical database name before deploying the code. Add regression tests proving both stored-name precedence and default-prefix fallback.

### 3. The checked-in Dokploy compose is not the proposed eight-service production topology

- **Severity:** REQUIRED
- **Design-doc section attacked:** §1.1, §3.3, §3.7, Phase 3
- **Evidence:** `docker-compose.dokploy.yml` declares eleven service entries: Postgres, Redis, Meilisearch, MinIO, the one-shot `createbuckets`, PgBouncer, API, worker, scheduler, websocket, and web (`docker-compose.dokploy.yml:51-170`, `docker-compose.dokploy.yml:195-267`). Excluding the one-shot job, that is the design’s stated ten persistent staging services. The proposed target has eight persistent services after removing Meilisearch and PgBouncer, but still needs a ninth, one-shot bucket-provisioning workload, which the plan places in Phase 2.6. More importantly, the checked-in compose still uses `DB_CONNECTION=pgsql`, `DB_DATABASE=autoerp`, includes Meilisearch variables, lacks `DB_CENTRAL_DATABASE`, `DB_DIRECT_HOST`, `TENANCY_DB_PER_TENANT`, and `DB_PGBOUNCER`, and enables `AUTO_SEED=true` (`docker-compose.dokploy.yml:14-49`). It has no shared `appstorage` volume (`docker-compose.dokploy.yml:267-271`), retains the 512 MB worker limit (`docker-compose.dokploy.yml:195-212`), and makes the API depend on Meilisearch (`docker-compose.dokploy.yml:170-191`). Its header tells operators to deploy it directly in Dokploy (`docker-compose.dokploy.yml:1-5`). The design instead creates API, worker, and scheduler as separate Dokploy Applications while assuming one shared named volume. Whether separate Application stacks can mount the exact same external Docker volume, with safe ownership and deployment behavior, is **UNVERIFIED** from the repo; reusing the label `appstorage` is not by itself proof that Dokploy will not scope three different volumes.
- **Fix:** Before production work, either update this compose to the production contract and use it as the source of truth, or mark/remove it as non-production and create a checked-in declarative manifest/runbook for the eight Applications plus bucket-init job. A manual panel configuration must not coexist with a repository file that advertises a materially incompatible production deployment. If separate Applications remain the choice, pre-create one external volume, mount that exact volume into all three services, and prove cross-container reads/writes survive independent redeploys.

### 4. The Vite build does not need a Dockerfile change, but the required build input is not wired anywhere

- **Severity:** REQUIRED
- **Design-doc section attacked:** §3.4, Phase 0.6, R-6
- **Evidence:** The web Dockerfile already declares `ARG VITE_REVERB_APP_KEY` before `RUN pnpm build` (`apps/web/Dockerfile:47-55`), so no Dockerfile fix is required. The frontend consumes that value and otherwise falls back to `local_key` (`apps/web/src/lib/echo.ts:22-31`). The production compose passes only `VITE_API_URL` (`docker-compose.dokploy.yml:251-257`), and staging passes only `VITE_API_URL` and `VITE_APP_PRODUCT` (`docker-compose.staging.yml:262-269`). No current workflow builds or pushes a production image. Thus the missing work is deployment/build configuration, not the Dockerfile.
- **Fix:** Pass `VITE_REVERB_APP_KEY` in every build path, remove the production fallback to `local_key` (fail the build when absent), and inspect the compiled bundle in CI. Phrase Phase 0.6 as a compose/Dokploy build-configuration change, not a Dockerfile fix.

### 5. The Reverb runtime contract is incomplete and will likely send server-side broadcasts to the wrong place

- **Severity:** BLOCKER
- **Design-doc section attacked:** §3.4, Phase 3.3-3.4, V-6
- **Evidence:** The design specifies `REVERB_SERVER_HOST`, but that variable controls the Reverb server bind configuration (`apps/api/config/reverb.php:29-42`) and the API nginx rewrite (`apps/api/docker/entrypoint.sh:242-246`). Server-side broadcasting uses the distinct `REVERB_HOST`, `REVERB_PORT`, and `REVERB_SCHEME` variables (`apps/api/config/broadcasting.php:31-43`). The Reverb application credential contract also requires key, secret, and id (`apps/api/config/reverb.php:70-89`). Neither checked-in Dokploy compose defines `REVERB_HOST`, `REVERB_PORT`, or `REVERB_SCHEME`; it defines only the app trio and `REVERB_SERVER_HOST/PORT` (`docker-compose.dokploy.yml:42-46`). The secret-rotation document itself lists the missing values as connection targets (`docs/security/secret-rotation-2026-05-12.md:69-73`).
- **Fix:** Define and test two explicit paths: internal publisher traffic (`REVERB_HOST=<websocket appName>`, `REVERB_PORT=8080`, `REVERB_SCHEME=http`) and browser traffic (same-origin `/app/` proxy plus baked public app key). Add a deployment test that publishes from both API and Horizon and observes the event in a browser; a successful websocket handshake alone is insufficient.

### 6. The 2 GB Horizon calculation is not a defensible hard floor

- **Severity:** REQUIRED
- **Design-doc section attacked:** §1.2 decision 11, §3.3 worker sizing
- **Evidence:** Production can start up to ten Horizon worker processes (`apps/api/config/horizon.php:201-229`), the Horizon master threshold is 64 MB (`apps/api/config/horizon.php:177-188`), and each worker’s Horizon restart threshold is 128 MB (`apps/api/config/horizon.php:212-218`). However, PHP itself permits each process to allocate up to 256 MB (`apps/api/docker/php/php.ini:5-9`). Horizon’s 128 MB value is a recycle threshold, not a cgroup reservation or a demonstrated peak. The proposed `10 × 128 + 64` arithmetic excludes supervisor processes, loaded extensions, allocator overhead, overlapping replacement workers, and jobs that transiently exceed 128 MB before Horizon recycles them. The current compose’s 512 MB is plainly inconsistent with ten production processes (`docker-compose.dokploy.yml:195-212`), but that does not prove 1.5 GB is a safe floor.
- **Fix:** Measure RSS with all ten processes running the largest import/image/fiscal jobs, including recycle overlap. Size from measured peak plus headroom, or lower `maxProcesses` to fit a proven 2 GB cgroup. Record an OOM/restart load test as a launch gate.

## 2. BACKUP/RESTORE DESIGN

### 7. The hourly dump set has no cross-database recovery point

- **Severity:** BLOCKER
- **Design-doc section attacked:** §4.1, §4.4, R-10
- **Evidence:** The outline first dumps globals, then obtains a database list, then executes one independent `pg_dump` per database (`docs/superpowers/plans/2026-08-05-production-environment-design.md:348-373`). Each database dump is internally consistent, but there is no shared snapshot across databases. The application creates the central tenant/domain rows before creating and migrating the physical tenant database (`apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:106-123`), and central auth/subscription data is deliberately separate from tenant-local operational data (`apps/api/config/database.php:105-122`). Concrete skew cases include: (a) the database list is captured before a new tenant DB exists, then the central DB is dumped after the tenant row is committed, producing a restored central row that points to a missing DB; (b) the tenant DB is included but the central dump predates its tenant row, producing an orphan DB; (c) a central status/subscription/auth change and a tenant-local user or fiscal write straddle their respective dumps. The next cycle does not repair the already-selected restore set after a disaster.
- **Fix:** Do not label a directory of independent dumps a cluster-consistent recovery point. Either add continuous physical backup/WAL archiving from launch for cluster-wide PITR, or introduce a provisioning/change freeze plus a captured manifest and reconciliation protocol for every logical cycle. Rehearse creation, suspension, and deletion during a backup and prove the restore rejects or repairs skew.

### 8. “RPO ≤ 1 hour” is a best-case schedule target, not a guaranteed RPO

- **Severity:** REQUIRED
- **Design-doc section attacked:** §4.1, DR-1 through DR-3
- **Evidence:** The design defers WAL archiving/pgBackRest until a dump cycle exceeds five minutes (`docs/superpowers/plans/2026-08-05-production-environment-design.md:303-310`, `docs/superpowers/plans/2026-08-05-production-environment-design.md:849-855`). The repository’s existing recovery guide describes PITR prerequisites but does not implement them (`docs/operations/BACKUP-RECOVERY.md:167-207`). Therefore launch RPO relies solely on the latest successful hourly dump. A missed or overlapping cycle, delayed alert response, offsite-copy failure, or a disaster immediately before the next successful completion makes actual data loss exceed one hour. Cross-DB snapshot age also spans the loop duration.
- **Fix:** State “target RPO: one hour, conditional on the last hourly cycle completing and both offsite uploads succeeding; no PITR at launch.” For an enforceable one-hour RPO, launch with WAL archiving/pgBackRest and monitor archive freshness independently of logical dumps.

### 9. The single-tenant restore races live writers and connections

- **Severity:** BLOCKER
- **Design-doc section attacked:** §4.6, DR-1
- **Evidence:** The proposed commands terminate current connections, then issue a separate `DROP DATABASE` and `CREATE DATABASE`, but do not disable API traffic, pause Horizon, stop the scheduler, revoke `CONNECT`, or drain tenant jobs (`docs/superpowers/plans/2026-08-05-production-environment-design.md:422-440`). The current application has an active API, ten-worker Horizon plan, and numerous scheduled tenant commands (`apps/api/config/horizon.php:201-229`, `apps/api/routes/console.php:15-149`). A request or job can reconnect between terminate and drop, race the restore, or write before verification. The in-app restore is similarly destructive and drops/recreates immediately (`apps/api/app/Modules/Tenant/Application/Services/TenantBackupService.php:150-186`).
- **Fix:** Add a tested tenant-maintenance state that rejects new requests/jobs, pause and drain Horizon, stop tenant scheduler activity, revoke new DB connections (or stop all application services), terminate remaining sessions, restore, validate, then resume. Use one runbook transaction/order that prevents reconnection races.

### 10. Cluster restore ordering is incomplete and globals restoration is not fail-fast

- **Severity:** REQUIRED
- **Design-doc section attacked:** §4.6 “Cluster restore order,” DR-2
- **Evidence:** L2 explicitly backs up central, every tenant, and the template/default DB (`docs/superpowers/plans/2026-08-05-production-environment-design.md:320-330`), but the cluster restore order mentions only globals, central, and tenants (`docs/superpowers/plans/2026-08-05-production-environment-design.md:451-456`). The design also says `DB_DATABASE=izipostemplate` must exist, yet the configured Stancl template connection actually defaults to the `central` connection (`apps/api/vendor/stancl/tenancy/src/DatabaseConfig.php:100-117`), while `DB_DATABASE` configures the separate `pgsql` connection (`apps/api/config/database.php:87-103`). No application call site found in `apps/api/app` explicitly uses `connection('pgsql')`. The design has not established that `izipostemplate` is needed.

  Separately, “globals via `psql -f`” is not made fail-fast with `-v ON_ERROR_STOP=1`, and a fresh Postgres service normally already contains the bootstrap application role, so an unfiltered globals file can collide with existing roles. The outline runs `pg_dumpall --globals-only` as `$PGUSER`, while the design grants that role only `CREATEDB`; whether it can capture all desired role password material is **UNVERIFIED** and must not be assumed.
- **Fix:** Remove the unused template DB or document and restore it. Define an idempotent role/bootstrap procedure using an administrative backup identity, `psql -v ON_ERROR_STOP=1`, explicit handling of pre-existing roles, and a verified credential-rotation step. Restore every artifact named in the manifest and fail on any omission.

### 11. The TimescaleDB procedure cannot determine whether the source used TimescaleDB

- **Severity:** REQUIRED
- **Design-doc section attacked:** §4.6, Phase 2.2, Phase 0.8
- **Evidence:** No migration creates `timescaledb` or a hypertable; the only extension creation found is `btree_gist` (`apps/api/database/migrations/tenant/2026_04_19_140003_create_scheduling_appointments_table.php:24-33`). More decisively, Stancl creates tenant databases `WITH TEMPLATE=template0` (`apps/api/vendor/stancl/tenancy/src/TenantDatabaseManagers/PostgreSQLDatabaseManager.php:32-35`), so the design’s inference that a `template1` extension might propagate does not apply to normal tenant provisioning. The proposed restore nevertheless always shows `CREATE EXTENSION` followed by `timescaledb_pre_restore()` (`docs/superpowers/plans/2026-08-05-production-environment-design.md:428-437`), then says these calls must be conditional on querying `pg_extension` in the *target* (`docs/superpowers/plans/2026-08-05-production-environment-design.md:451-458`). After `CREATE EXTENSION`, that target query is necessarily true; before it, a false result says nothing about the source archive. The in-app restore currently has no pre/post handling (`apps/api/app/Modules/Tenant/Application/Services/TenantBackupService.php:260-289`).
- **Fix:** Record per-database source metadata in every backup cycle: PostgreSQL version, installed extension and exact `extversion`, and Timescale hypertable/catalog inventory. Restore into `template0`; if and only if the manifest says Timescale is present, install a compatible exact extension, call pre-restore, restore serially, call post-restore, and validate hypertables/catalogs. Otherwise perform an ordinary serial restore. Test both paths.

### 12. Restic retention and WORM retention are different mechanisms with incompatible failure modes

- **Severity:** REQUIRED
- **Design-doc section attacked:** §4.3-4.5, D-3
- **Evidence:** The outline sends raw timestamped dump directories to Object Storage with `rclone copy`, then independently runs `restic backup` and mutable `forget --prune` against Storage Box (`docs/superpowers/plans/2026-08-05-production-environment-design.md:368-376`). Restic encryption/deduplication does not make the Storage Box repository immutable; a compromised host holding repository credentials can forget/prune or destroy it. Conversely, WORM can prevent deletion even when the intended operational retention expires. No checked-in lifecycle policy maps the 48-hour/14-daily/8-weekly/12-monthly tiers to the raw timestamp prefixes, and no script deletes or compacts those Object Storage copies. Provider object-lock semantics are **UNVERIFIED** from this repository.
- **Fix:** Specify separate threat models and credentials for each leg. Use write-without-delete credentials on the host where supported; define and test object-lock mode, retention dates, lifecycle behavior, legal holds, and cost growth. Treat restic as encrypted/deduplicated but mutable, and prove a compromised production credential cannot destroy the WORM leg.

### 13. Total-host recovery omits media and appstorage, so its RPO/RTO scope is false

- **Severity:** BLOCKER
- **Design-doc section attacked:** §4.1, §4.3, DR-3
- **Evidence:** Database dumps are hourly, while MinIO and appstorage are only nightly panel-managed volume backups (`docs/superpowers/plans/2026-08-05-production-environment-design.md:320-330`). The application hard-codes media writes to the S3 disk (`apps/api/app/Modules/Media/Application/Services/MediaUploadService.php:131`), and local application data/logs live under Laravel storage (`apps/api/config/filesystems.php:31-61`). DR-3 says to recreate services and “restore per DR-2,” but DR-2 restores only Postgres (`docs/superpowers/plans/2026-08-05-production-environment-design.md:464-470`). It never restores `miniodata` or `appstorage`, verifies media objects, or accounts for their transfer time. Thus the one-hour RPO applies only to independently dumped databases; total host loss can lose nearly 24 hours of media and leave broken object references.
- **Fix:** Add host-loss restore steps and explicit RPO/RTO objectives for every persistent volume. Prefer object-level replication of MinIO media off-host rather than a nightly stopped-volume archive. Time a full restore of Postgres, MinIO, and appstorage from offsite copies before accepting four hours.

### 14. The legal-retention gate is waivable and the stated “90 days” is not the configured policy

- **Severity:** REQUIRED
- **Design-doc section attacked:** D-3b, §4.5, Phase 7.8
- **Evidence:** The retention table keeps 48 hourly copies, 14 daily, 8 weekly, and 12 monthly—not a 90-day operational tier (`docs/superpowers/plans/2026-08-05-production-environment-design.md:390-400`). E-4 covers retention, but its repository gate permits a dated owner risk acceptance instead of accountant/legal approval (`docs/handoff/OWNER-manual-launch-gates-2026-07-31.md:37-43`). Therefore “all E-1…E-10 closed” does not guarantee that a Tunisian retention answer exists. A too-short lifecycle irreversibly destroys evidence; a guessed ten-year WORM lock can also create irreversible over-retention and cost/privacy exposure. Tunisian legal requirements are **UNVERIFIED** here.
- **Fix:** Make the fiscal-retention sub-gate non-waivable before the first fiscal record, with named legal/accounting approval and data-class-specific retention. Do not activate irreversible WORM defaults from a placeholder. Correct the “90 days” statement or add a real 90-day tier.

### 15. Forty-eight local full dumps do not fit the document’s own growth model

- **Severity:** REQUIRED
- **Design-doc section attacked:** §3.1 storage headroom, §4.5, §9.1
- **Evidence:** The design keeps 48 hourly dump sets locally (`docs/superpowers/plans/2026-08-05-production-environment-design.md:390-398`) on about 512 GB usable storage, while its own growth table projects 5–10 GB at 50 tenants and 20–40 GB at 200 tenants (`docs/superpowers/plans/2026-08-05-production-environment-design.md:838-845`). Before compression, 48 × 10 GB is 480 GB and 48 × 40 GB is 1.92 TB, excluding Postgres, images, build cache, media, logs, and partial failed dumps. Custom-format compression makes the exact figure workload-dependent, not safe by definition.
- **Fix:** Set a capacity-based local retention ceiling, reserve filesystem headroom, clean incomplete cycles, and alert on backup-staging bytes as well as `/` percentage. Move local staging off the root filesystem or stream validated dumps off-host before the 50-tenant point.

## 3. DR/RTO

### 16. The cloud-lifeboat depends on registry images that do not exist and are not connected to the service model

- **Severity:** BLOCKER
- **Design-doc section attacked:** DR-3, §7.2, Phase 6.1-6.2
- **Evidence:** The only workflow files are `ci.yml`, `react-doctor.yml`, `smoke-test.yml`, and `sonarcloud.yml`; `.github/workflows/deploy-production.yml` is absent. Repository search finds no GHCR login, `docker/build-push-action`, `push: true`, or package-write workflow. CI uploads only a seven-day web `dist` artifact (`.github/workflows/ci.yml:909-942`). Current Dokploy definitions build from Git/Dockerfiles (`docker-compose.dokploy.yml:170-174`, `docker-compose.dokploy.yml:195-200`, `docker-compose.dokploy.yml:216-220`, `docker-compose.dokploy.yml:231-235`, `docker-compose.dokploy.yml:251-256`); they do not consume immutable registry images. The design requires five distinct application targets, so “a tagged image” is also insufficient unless it means four API targets plus web, each tied to one release digest.
- **Fix:** Before claiming DR-3, implement and test a target matrix that builds and pushes immutable API, worker, scheduler, websocket, and web images off-host. Configure the lifeboat deployment to pull those digests without source builds or GitHub availability. Retain and periodically pull the last-known-good set. Move this prerequisite before application creation, not Phase 6 after it.

### 17. DR-3 requires the same Dokploy panel whose outage has no replacement path

- **Severity:** BLOCKER
- **Design-doc section attacked:** DR-3, DR-4, R-11
- **Evidence:** DR-3 explicitly requires registering the new cloud host on the panel and having the panel install Docker/swarm/Traefik and recreate services (`docs/superpowers/plans/2026-08-05-production-environment-design.md:464-477`). DR-4 only handles panel loss while the original remote host is still alive (`docs/superpowers/plans/2026-08-05-production-environment-design.md:468-470`). There is no checked-in Compose/Ansible/manual procedure matching the proposed production topology; the available Dokploy compose is materially stale (Finding 3). Panel availability, account-suspension behavior, and remote-server migration behavior are **UNVERIFIED** from this repository.
- **Fix:** Provide a panel-independent cold-start path using pinned images and a checked-in, secret-free deployment manifest. Rehearse total host loss with the panel intentionally unavailable. If that cannot meet four hours, downgrade the RTO claim and explicitly make panel availability a dependency.

### 18. The four-hour RTO has neither a full-data transfer budget nor a full-host rehearsal

- **Severity:** REQUIRED
- **Design-doc section attacked:** §4.1, §4.7-4.8
- **Evidence:** The only required rehearsal restores one throwaway tenant (`docs/superpowers/plans/2026-08-05-production-environment-design.md:479-497`), not all databases and volumes on a cold host. The asserted current 272 MB size is not evidenced anywhere outside the design and is **UNVERIFIED** from this repository. Even accepting it, it says nothing about launch-day media or growth. Using the design’s 40 GB database projection as a scenario, transfer alone is about 8.9 hours at 10 Mbit/s, 53 minutes at 100 Mbit/s, or 5.3 minutes at an ideal 1 Gbit/s, before serial restore, volume recovery, verification, DNS, and operator time. Actual offsite-to-cloud bandwidth and serial `pg_restore` throughput are **UNVERIFIED**.
- **Fix:** Build a timed RTO budget with measured p50/p95 durations for provisioning, image pulls, all backup downloads, serial restores, media/appstorage restore, verification, and DNS cutover. Rehearse full-host loss quarterly at representative data size; make the measured result, not the one-tenant restore, the RTO gate.

### 19. TTL 300 does not solve DNS cutover access or dual-stack failure

- **Severity:** REQUIRED
- **Design-doc section attacked:** D-2, DR-3 supporting requirements, Appendix A
- **Evidence:** The repository confirms only that the intended application URLs are `riserpos.app` and `api.riserpos.app` (`docker-compose.dokploy.yml:19-20`, `docker-compose.dokploy.yml:256-259`). It cannot verify ownership, current TTLs, registrar/provider availability, or propagation; all are **UNVERIFIED**. The DR secret inventory includes a Hetzner token and SSH key but no DNS-provider credential, recovery code, or delegated second operator (`docs/superpowers/plans/2026-08-05-production-environment-design.md:521-538`). Publishing both A and AAAA also means both must be changed; a stale AAAA can keep IPv6 clients on the dead host.
- **Fix:** Add DNS provider credentials/recovery to the break-glass inventory, pre-set and continuously verify TTLs, automate an atomic A+AAAA update, and rehearse it. Measure resolver behavior rather than treating 300 seconds as a propagation guarantee.

### 20. The one-human 1Password chain is not a break-glass plan

- **Severity:** REQUIRED
- **Design-doc section attacked:** D-4, §5.1-5.3, DR-3
- **Evidence:** The repository gate names one owner for all rows and requires a second human only for selected launch gates (`docs/handoff/OWNER-manual-launch-gates-2026-07-31.md:18-25`, `docs/handoff/OWNER-manual-launch-gates-2026-07-31.md:37-48`). The new secret list omits 1Password recovery material, Dokploy account recovery, GitHub org/package recovery, DNS access, alerting credentials, and the `DOKPLOY_API_KEY` later required by Phase 6.4. 1Password availability, recovery behavior, and CLI characteristics are **UNVERIFIED** from this repository.
- **Fix:** Create an offline break-glass package and second-custodian procedure covering 1Password recovery, GitHub/GHCR, Dokploy, DNS, Hetzner, SSH, backup repositories, alerting, and mail. Rehearse recovery from a clean machine with the production host and primary operator laptop unavailable.

## 4. SECURITY

### 21. The panel encryption key is backed up after, not before, the risky panel upgrade

- **Severity:** REQUIRED
- **Design-doc section attacked:** D-8, L6, Phase 0.2, Phase 4.6
- **Evidence:** The plan upgrades Dokploy in Phase 0.2 (`docs/superpowers/plans/2026-08-05-production-environment-design.md:736-746`) but does not export/vault the environment-encryption keyring until Phase 4.6 (`docs/superpowers/plans/2026-08-05-production-environment-design.md:783-793`). It simultaneously describes loss of that keyring as fatal (`docs/superpowers/plans/2026-08-05-production-environment-design.md:320-333`). Panel version, upgrade behavior, CVEs, rollback support, and existing key custody are **UNVERIFIED** from the repo.
- **Fix:** Establish the secret vault first; export and verify the current keyring and panel backup before upgrading; document rollback; upgrade; validate both existing remote servers and secret decryption; only then register production. Add a regular OS/container/base-image patch policy. Pin mutable `minio/minio:latest` and `minio/mc:latest` references (`docker-compose.dokploy.yml:105-127`) to tested versions or digests.

### 22. One panel compromise has three-server SSH blast radius

- **Severity:** REQUIRED
- **Design-doc section attacked:** D-8, §3.1, Phase 1.3-1.5
- **Evidence:** The design says the panel holds keys to two existing servers and will add production, but no server key material or live panel configuration is in the repository, so this is **UNVERIFIED**. The only repository-level host control proposed is key-only SSH and ports 80/443/22. Key-only authentication does not reduce blast radius if the panel holds a reusable root-capable key for all hosts.
- **Fix:** Require a unique key and restricted automation account per host, no cross-host private-key reuse, explicit sudo command policy where Dokploy permits it, source-IP restrictions, host-key verification, rotation/revocation procedures, and an inventory proving which principal can reach which server. Treat panel compromise as a three-host incident in the runbook.

### 23. Production Postgres does not need external exposure, but live acceptance evidence is still required

- **Severity:** MINOR
- **Design-doc section attacked:** §3.1 firewall, §3.7, Phase 2.1
- **Evidence:** The relevant Dokploy compose defines Postgres without `ports:` (`docker-compose.dokploy.yml:55-68`), and every proposed backup command uses host-side `docker exec`, so no design element requires public PostgreSQL. The local-development compose does publish Postgres (`docker-compose.yml:1-14`), which makes selecting the correct manifest important. The actual production firewall and Dokploy port publishing are **UNVERIFIED** until provisioned.
- **Fix:** Keep Postgres un-routed and unpublished; add `ss`, firewall, Docker service inspection, and an external negative connection test to the launch evidence. Never reuse the local compose on production.

### 24. The application is given MinIO root credentials

- **Severity:** BLOCKER
- **Design-doc section attacked:** §3.4 AWS variables, §5.2, Phase 2.5-2.6
- **Evidence:** In the checked-in deployment, the same `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` are supplied to the application (`docker-compose.dokploy.yml:36-41`) and used as `MINIO_ROOT_USER`/`MINIO_ROOT_PASSWORD` (`docker-compose.dokploy.yml:105-113`). The S3 disk consumes those credentials for ordinary media operations (`apps/api/config/filesystems.php:50-60`), and media upload explicitly selects that disk (`apps/api/app/Modules/Media/Application/Services/MediaUploadService.php:131`). Compromise of any API/worker environment therefore yields administrative credentials capable of destroying all media, not a bucket-scoped application identity. The relevant compose publishes no MinIO ports, so repository intent is internal-only; however, MinIO still starts its administrative console on port 9001 (`docker-compose.dokploy.yml:102-118`), and whether Dokploy assigns a domain or externally publishes either port is **UNVERIFIED** until live configuration is inspected.
- **Fix:** Keep both MinIO API and console internal with no domain/public port. Create a separate MinIO root credential held only in the vault, a least-privilege application access key scoped to the required bucket/actions, and a distinct backup identity. Do not place root credentials in project-wide container env. Test externally that 9000/9001 are closed and that the application key cannot alter users, policies, other buckets, object-lock settings, or backup data.

### 25. E-1 rotates secrets but cannot eliminate plaintext runtime exposure

- **Severity:** REQUIRED
- **Design-doc section attacked:** §5.2-5.3
- **Evidence:** The plan’s injection diagram ends in container environment variables (`docs/superpowers/plans/2026-08-05-production-environment-design.md:540-550`), and the checked-in compose passes DB, Redis, S3, and Reverb credentials through environment variables (`docker-compose.dokploy.yml:15-46`). Encryption at rest in a panel does not prevent the panel, its API, Docker inspection, a privileged host user, or a compromised application process from reading deployed plaintext. E-1’s repository ledger validates revocation of old credentials (`docs/security/secret-rotation-2026-05-12.md:53-67`, `docs/security/secret-rotation-2026-05-12.md:248-274`); it does not change runtime custody.
- **Fix:** Reframe E-1 as rotation and custody improvement, not removal of plaintext risk. Minimize project-wide inheritance, use least-privilege per-service identities, redact panel/API/log output, restrict panel roles and Docker access, and define rapid revocation after panel/host compromise.

### 26. The shared runtime DB role is a cluster-wide destructive credential

- **Severity:** REQUIRED
- **Design-doc section attacked:** §1.2 decision 4, §3.4 DB role, §5.2
- **Evidence:** Stancl creates every tenant database over the central/template connection (`apps/api/vendor/stancl/tenancy/src/DatabaseConfig.php:100-117`, `apps/api/vendor/stancl/tenancy/src/TenantDatabaseManagers/PostgreSQLDatabaseManager.php:27-40`). The design therefore grants the application role `CREATEDB` and uses one `DB_PASSWORD` across API, worker, scheduler, and websocket. That role also owns the databases it creates and is the credential used by backup/restore paths. Database-per-tenant prevents accidental connection crossover; it does not contain compromise of this shared owner credential.
- **Fix:** Separate runtime access from provisioning/DDL/backup identities. Put database creation/deletion behind a narrowly controlled provisioning path, use per-product or per-tenant roles where practical, and ensure routine API/worker containers cannot create/drop arbitrary databases or read unrelated products. Document the unavoidable residual privileges if Stancl integration prevents full separation.

### 27. The second-vertical path lacks Redis and credential isolation

- **Severity:** MINOR
- **Design-doc section attacked:** §9.4
- **Evidence:** The design proposes a second service set on the same cluster but only names separate databases. Laravel’s Redis prefix defaults from `APP_NAME` (`apps/api/config/database.php:190-207`), cache prefix also defaults from `APP_NAME` (`apps/api/config/cache.php:104-116`), queues use the same Redis connection and queue names (`apps/api/config/queue.php:67-74`), and Horizon’s prefix isolates only Horizon metadata (`apps/api/config/horizon.php:59-75`). Merely changing `HORIZON_PREFIX` will not isolate jobs, cache, sessions, or Reverb scaling traffic between IziPOS and Otospex.
- **Fix:** Before adding Otospex, specify distinct Redis databases/prefixes/queue namespaces and credentials—or separate Redis services—and distinct DB roles, buckets, Reverb credentials, and alerting identities. Add cross-product isolation tests.

## 5. OPERATIONAL GAPS

### 28. Single-human operations has detection but no escalation or bounded response

- **Severity:** REQUIRED
- **Design-doc section attacked:** D-6, §6, Phase 4.7
- **Evidence:** The launch-gate document names one owner (`docs/handoff/OWNER-manual-launch-gates-2026-07-31.md:20-25`). The backup outline uses a nonblocking `flock` exit, leaves partial cycle directories, and relies on a final success ping (`docs/superpowers/plans/2026-08-05-production-environment-design.md:348-380`). As written, it also enables `set -u` but never assigns `PGUSER`, lists `/root/.restic-password` without passing it or defining `RESTIC_PASSWORD_FILE`, gives no executable shebang/PATH contract for cron, and uses `find` without `-mindepth 1`. The document labels this an outline, so it is not expected to run yet—but none of these details may remain implicit in the final script. It further specifies neither a cron `MAILTO`, journald/systemd policy, stale-lock/hung-process kill, low-space preflight, partial-file quarantine, nor escalation if Telegram/email goes unanswered. Disk-full can prevent local logs and dumps at the same time. Cron and host behavior are **UNVERIFIED** until implemented.
- **Fix:** Use a systemd timer/service or a fully specified cron script with a declared interpreter/environment, bounded runtime, explicit credential injection, explicit logs/rotation, free-space preflight, atomic `.partial` to complete rename, `find -mindepth 1`, stale-run detection, cleanup, and direct failure notification in addition to dead-man silence. Add a second human/escalation timer and a runbook for alerts received while the owner is unavailable.

### 29. The monitoring design depends on code and alert paths that do not exist

- **Severity:** REQUIRED
- **Design-doc section attacked:** O-3 through O-11, Phase 5
- **Evidence:** `routes/console.php` contains the application schedules but no healthchecks.io heartbeat (`apps/api/routes/console.php:15-149`). No repository file contains `hc-ping.com` or `healthchecks.io`. The proposed O-7 Redis `LLEN` check is also absent; the existing admin monitoring counts database `jobs` rows (`apps/api/app/Modules/Admin/Application/Services/MonitoringService.php:242-252`, `apps/api/app/Modules/Admin/Application/Services/MonitoringService.php:604-625`) even though production uses the Redis queue. `.github/workflows/deploy-production.yml` is absent. Panel backup notifications cannot report a total panel outage, and the only off-panel backup dead-man covers the proposed database cron—not nightly MinIO/appstorage volume backups.
- **Fix:** Move all required monitor code into Phase 0 before promotion. Add independent dead-men for database backups, media/appstorage backups, scheduler, disk, and alert-delivery canaries. Send periodic synthetic alerts through both channels and require acknowledgment; alert if the Telegram/email integration itself has not passed a canary.

### 30. Log and disk-retention claims are not bounded by the stated math

- **Severity:** REQUIRED
- **Design-doc section attacked:** §3.1 storage headroom, O-12
- **Evidence:** Docker rotation caps each configured container at 5 × 50 MB, so eight persistent application/data containers alone can consume about 2 GB, excluding Traefik, Dokploy agents, build containers, and old images. Laravel daily logs retain 14 files but have no per-file size cap (`apps/api/config/logging.php:53-74`). The shared appstorage also holds tenant-local files and any in-app tenant backups; that backup service retains 14 successful dumps per tenant (`apps/api/config/tenant_backups.php:18-30`, `apps/api/app/Modules/Tenant/Application/Services/TenantBackupService.php:291-313`). The asserted total below 150 GB is **UNVERIFIED** and omits Docker layers/build cache plus the 48 local full-cluster dump sets.
- **Fix:** Produce a line-item capacity budget with growth rates and hard ceilings for PGDATA, local dumps, MinIO, appstorage, Laravel logs, Docker logs, images/build cache, and failed partials. Add log size limits or shipping, Docker/image pruning policy, and alerts on both bytes and inode use.

### 31. One concurrent build serializes five deploys and builds on the database host

- **Severity:** REQUIRED
- **Design-doc section attacked:** §7.2, R-12
- **Evidence:** The design calls Dokploy deploy on five Application services (`docs/superpowers/plans/2026-08-05-production-environment-design.md:630-646`). Four separately target the same API Dockerfile and one builds the web image (`apps/api/Dockerfile:145-188`, `apps/web/Dockerfile:16-83`). No registry workflow exists, so current operation implies five source builds. The one-concurrent-build limit and any maximum queue behavior are **UNVERIFIED** provider/panel facts. Even if true, 64 GB RAM does not prevent build CPU, disk-I/O, image-layer, or root-filesystem contention with Postgres.
- **Fix:** Build all target images once in CI, push immutable digests, and make production pull them. Serialize rollout intentionally with health gates and a measured maximum deploy duration. Prohibit unbounded source builds on the database host during peak operation.

### 32. Panel outage silently stops the non-database backup path and deploy control

- **Severity:** REQUIRED
- **Design-doc section attacked:** §4.2, DR-4, R-11
- **Evidence:** The design correctly moves logical DB dumps to host cron, but leaves MinIO/appstorage/Redis volume backups and their notifications in Dokploy (`docs/superpowers/plans/2026-08-05-production-environment-design.md:311-330`). During a panel outage, DR-4 explicitly concedes loss of deploys, logs, restore UI, volume backups, and panel notifications (`docs/superpowers/plans/2026-08-05-production-environment-design.md:468-470`). No off-panel monitor watches the age of those volume backups (Finding 29). The exact Cloud SaaS outage behavior is **UNVERIFIED**.
- **Fix:** Make critical media/appstorage backups host- or storage-driven and externally age-monitored, or state their panel dependency and RPO explicitly. Keep a panel-independent deploy/rollback procedure for security hotfixes during a panel outage.

## 6. SEQUENCING

### 33. The phase order asks for verification before its prerequisites exist

- **Severity:** BLOCKER
- **Design-doc section attacked:** §8 Phases 0-6
- **Evidence:** Phase 3.6 requires all V-1…V-8 checks (`docs/superpowers/plans/2026-08-05-production-environment-design.md:772-781`), but V-5 depends on the scheduler heartbeat that is not added until Phase 5.3 (`docs/superpowers/plans/2026-08-05-production-environment-design.md:797-806`), and the deploy workflow/registry are not built until Phase 6 (`docs/superpowers/plans/2026-08-05-production-environment-design.md:808-817`). Phase 0.9 promotes dev to main before those Phase 5 code changes and the Phase 6 workflow exist (`docs/superpowers/plans/2026-08-05-production-environment-design.md:736-748`), yet no later dev→main PR/owner merge step is specified. Current code confirms the heartbeat, queue check, GHCR publishing, and production deploy workflow are absent (Findings 16 and 29).
- **Fix:** Move every repository prerequisite—tenancy env support, Vite arg wiring, Reverb runtime contract, restore changes/tests, scheduler/backup/queue monitors, image workflow, production deploy workflow, smoke parameterization, and stale-doc corrections—before the single final dev→main promotion. Create registry images and a panel-independent manifest before Phase 3. Only then provision/deploy and run V-1…V-8.

### 34. The dev→main promotion is larger than stated in operational terms, and CI history is not repository-verifiable

- **Severity:** REQUIRED
- **Design-doc section attacked:** F-1, §7.3, Phase 0.9
- **Evidence:** Current refs still show `origin/main...origin/dev` as 3 commits only on main and 4,303 only on dev. The merge-base diff spans 8,455 files, about 1,349,569 insertions and 53,305 deletions. Main does contain Dockerfiles, lockfiles, compose files, and a CI workflow, so “there is no artifact on main” is too absolute; the defensible claim is that main lacks the intended current release. The current workflow runs heavy jobs on PRs to main and pushes to main (`.github/workflows/ci.yml:3-8`, `.github/workflows/ci.yml:180-185`, `.github/workflows/ci.yml:850-935`) and aggregates twelve dependencies (`.github/workflows/ci.yml:1022-1039`). Whether those jobs have ever run successfully on GitHub is **UNVERIFIED** from repository files. There is no `.gitmodules` file and no current gitlink, so a present-day submodule risk was not found; promotion can still expose generated/build drift across the huge diff.
- **Fix:** Treat promotion as a release project with an owner-approved merge window, merge-conflict inventory for the three main-only commits, full CI evidence from GitHub, generated-artifact checks, container builds for every target, SBOM/vulnerability scan, and a rollback tag. Keep the existing owner-only merge gate; do not infer CI history from YAML.

### 35. Multiple stated prerequisites are unimplemented today

- **Severity:** REQUIRED
- **Design-doc section attacked:** Phase 0, Phase 5, Phase 6
- **Evidence:** Current state is:

  - `TENANCY_DB_PREFIX`: **unimplemented** in the production tenancy config; prefix remains hard-coded (`apps/api/config/tenancy.php:58-63`).
  - `VITE_REVERB_APP_KEY`: Dockerfile support **already implemented**, but compose/Dokploy wiring **unimplemented** (`apps/web/Dockerfile:47-55`, `docker-compose.dokploy.yml:251-257`).
  - Outgoing Reverb host/port/scheme: **unimplemented** in the deployment contract (`apps/api/config/broadcasting.php:31-43`, `docker-compose.dokploy.yml:42-46`).
  - Timescale-safe in-app restore: **unimplemented** (`apps/api/app/Modules/Tenant/Application/Services/TenantBackupService.php:260-289`).
  - Corrected operations recovery document: **unimplemented**; it still uses `-j 4` and `-j 2` (`docs/operations/BACKUP-RECOVERY.md:141-154`, `docs/operations/BACKUP-RECOVERY.md:215-233`).
  - Scheduler heartbeat, disk dead-man, and Redis queue-depth alert: **unimplemented** (`apps/api/routes/console.php:15-149`; no healthchecks.io references in the repo).
  - GHCR image publishing and `.github/workflows/deploy-production.yml`: **unimplemented**; CI only uploads web dist (`.github/workflows/ci.yml:909-942`).
  - Production-aware smoke workflow: partially implemented; the manual input can already accept any URL despite being named `staging_url`, but it defaults to staging and is not called by another workflow (`.github/workflows/smoke-test.yml:1-10`, `.github/workflows/smoke-test.yml:52-57`).
  - Eight-service declarative production manifest with appstorage: **unimplemented** (Finding 3).
- **Fix:** Convert this inventory into explicit, testable Phase 0 exit criteria, land it before the final promotion, and do not provision production Applications until the release commit contains every required artifact.

### 36. The initial production deploy is only accidentally CI-gated

- **Severity:** REQUIRED
- **Design-doc section attacked:** §7.2, Phase 3, Phase 6
- **Evidence:** The intended deploy workflow does not exist until Phase 6, while Phase 3 creates and builds five production Applications (`docs/superpowers/plans/2026-08-05-production-environment-design.md:772-781`). A green Phase 0.9 promotion reduces risk, and `autoDeploy=false` prevents later watcher deploys, but the initial panel-side builds are not triggered or attested by the promised workflow. No current repository mechanism ties the running containers to the exact all-checks-pass SHA or immutable image digest.
- **Fix:** Build the registry/deploy gate before creating production apps. Require each running service to expose/record the release SHA and image digest, and verify that exact SHA is the successful, owner-approved CI run.

## 7. COST/CLAIM AUDIT

### 37. Owner-decision numbers are mostly externally unverifiable; two internal statements are wrong or misleading

- **Severity:** REQUIRED
- **Design-doc section attacked:** §2 D-1…D-8, Appendix B
- **Evidence:** Audit of every owner-decision row:

  | Decision | Audit |
  |---|---|
  | D-1 server | **UNVERIFIED:** SKU availability, CPU/RAM/disk/ECC/network specifications, €97.30, €49 setup, €1.70 IPv4, €57.30 and €138.49 alternatives, June 2026 price rise, provisioning time, and location are not in the repo. Arithmetic is sound: €97.30 + €1.70 = €99.00; €138.49 / €99.00 ≈ 1.40; €99 is about 28.5% below €138.49, so “30%” is rounded. |
  | D-2 DNS | Domains match current compose (`docker-compose.dokploy.yml:19-20`, `docker-compose.dokploy.yml:256-259`). **UNVERIFIED:** domain ownership/renewal cost and actual TTL. Cost “0” is valid only if the domains and DNS service are already paid. |
  | D-3 backups | Arithmetic is sound: €4.99 + ~€3.20 = €8.19. **UNVERIFIED:** both prices, included storage/egress, traffic, object lock, locations, and current order-form availability. “Two independent mechanisms” is true technically, not as provider/account failure domains. “Operational retention is 90 days” is false against §4.5 (Finding 14). |
  | D-4 1Password | **UNVERIFIED:** plan name/features and ~$8/~€7.40 price/FX. The total also assumes one paid user and does not price a second custodian. |
  | D-5 mail | Repository verifies the unsafe `MAIL_MAILER=log` default (`apps/api/.env.example:117-125`) and real mail call sites, e.g. verification (`apps/api/app/Modules/Identity/Application/Services/EmailVerificationService.php:13-37`) and documents (`apps/api/app/Modules/Communication/Application/Services/DocumentEmailService.php:13-60`). **UNVERIFIED:** Brevo/Postmark pricing, quotas, deliverability, and “ample” volume. DNS/domain work is not zero operational effort. |
  | D-6 alerts | **UNVERIFIED:** Dokploy Telegram event support, event coverage, and zero price. Repository contains none of the proposed alert configuration. |
  | D-7 panel | Arithmetic is sound: $4.50 + 2×$4.50 = $13.50, $1.50 below $15. **UNVERIFIED:** current tiers, server counting, limits, remote monitoring, backup limits, one-build limit, and USD/EUR conversion. |
  | D-8 upgrade | **UNVERIFIED:** current panel version, release date, CVE count/details, upgrade entitlement, and downtime. Monetary price may be zero, but operational risk/time is not. |

  Appendix B arithmetic is internally consistent: €99.00 + €8.19 + €13.90 = €121.09; adding €7.40 gives €128.49, reasonably rounded to €121/€128 (`docs/superpowers/plans/2026-08-05-production-environment-design.md:930-945`). It excludes VAT, domain renewal, paid mail growth, Sentry growth, off-provider backups, DR cloud runtime, restore egress beyond allowances, second-custodian licensing, and incident labor.
- **Fix:** Requote every external item from current provider consoles at decision time and attach dated evidence. Add low/base/high monthly cost, growth/egress assumptions, DR incident cost, and excluded items. Correct the retention wording and do not call same-provider copies independent failure domains.

### 38. Service-count, RPO/RTO, sizing, and timing numbers are targets or assumptions, not verified facts

- **Severity:** REQUIRED
- **Design-doc section attacked:** §1, §3, §4, §6, §9, §10
- **Evidence:** Numeric claim audit:

  - **8 services:** internally coherent only as eight persistent target services; the repo currently declares ten persistent services plus one one-shot job, and the target still requires a bucket-init job (Finding 3).
  - **5 application deploys:** verified by four API Dockerfile targets plus web (`apps/api/Dockerfile:145-188`, `apps/web/Dockerfile:16-83`), but no workflow deploys them.
  - **6 Horizon queues / 10 production processes / 128 MB threshold / 64 MB master:** verified (`apps/api/config/horizon.php:177-229`); the derived 1.35 GB steady figure and 1.5 GB floor are not verified peaks (Finding 6).
  - **RPO 1 hour:** a conditional logical-dump target, not guaranteed; there is no launch WAL/PITR (Finding 8). Media/appstorage RPO is nightly, not one hour (Finding 13).
  - **RTO 1/2/4 hours:** **UNVERIFIED** until timed single-tenant, full-cluster, and full-host rehearsals. Only a one-tenant rehearsal is planned.
  - **Cloud ~60 seconds / Dokploy setup ~15 minutes / Meilisearch re-add ~10 minutes / 5-minute dump threshold / 10–25 and 320 req/s / 35–55 or 120 ms RTT:** **UNVERIFIED** from the repo.
  - **TTL 300:** a desired DNS setting, not verified live and not a propagation guarantee (Finding 19).
  - **272 MB across 8 tenants / 24–25 MB empty tenant / 236 tables / “dumps in seconds”:** **UNVERIFIED**; no measurement artifact exists outside the design document.
  - **<150 GB and ~70% free:** arithmetic against ~512 GB is plausible, but the input is **UNVERIFIED** and omits material storage classes (Finding 30).
  - **48 hourly, 14 daily, 8 weekly, 12 monthly:** clearly specified, but not implemented in any checked-in script and inconsistent with the “90 days” description.
  - **4,303-commit divergence:** verified against current refs; the actual merge-base diff is 8,455 files and about 1.35 million insertions (Finding 34).
  - **12 all-checks dependencies:** verified in current CI (`.github/workflows/ci.yml:1022-1039`); historical GitHub run status is **UNVERIFIED**.
  - **1 concurrent build / two-day Dokploy metrics retention / panel provisioning and outage behavior:** **UNVERIFIED** external panel claims.
- **Fix:** Relabel unmeasured figures as hypotheses, name the measurement owner and acceptance threshold, and prevent launch/RTO sign-off until measured evidence exists. Keep verified static configuration values separate from provider claims and performance estimates.

## Verdict

**REJECT**

The single most dangerous flaw is that DR-3 is presented as a four-hour executable recovery path even though it currently has no registry images, no deployment model that consumes immutable images, no panel-independent fallback, and no restoration of MinIO/appstorage. In a real total-host loss, the advertised lifeboat can neither recreate the application independently nor recover all persistent customer data within the claimed scope.
