# Production Environment Design — AutoERP / IziPOS, First Production Server

| | |
|---|---|
| **Version** | **v5 — 2026-08-05** (revised after the round-4 scoped re-gate; v1, v2, v3 and v4 verdicts were all **REJECT**) |
| **Date** | 2026-08-05 |
| **Status** | **REVISED — pending round-5 re-review, then owner decisions D-1…D-21** |
| **Scope** | The first dedicated **production** deployment of the ERP (`apps/api`, `apps/web`), separate from staging (`157.180.71.252`) and from the Synerivia platform box (AX42, `176.9.139.218`) |
| **Primary input** | Part C production-environment research, 2026-08-05 (FACTS §A–D, RECOMMENDATION INPUTS §R1–R7) |
| **Review input** | Round 1: `docs/superpowers/reviews/2026-08-05-production-env-design-review.md` — 38 findings, 8 BLOCKERs. Round 2: `docs/superpowers/reviews/2026-08-05-production-env-design-review-round2.md` — re-gate of all 38 plus **N1–N6**, verdict **REJECT** on 4 blocker clusters. Round 3: `docs/superpowers/reviews/2026-08-05-production-env-design-review-round3.md` — **scoped** re-gate of the round-2 dispositions only; 6 BLOCKERs collapsing to **three root causes R3-N1/N2/N3**, plus ~10 MAJORs (**R3-N4…R3-N7**) and four confirmations. Round 4: `docs/superpowers/reviews/2026-08-05-production-env-design-review-round4.md` — **scoped** re-gate of R3-N1/N2/N3 and the Phase-0 code blockers; verdict **REJECT** on **3 BLOCKERs, 2 MAJORs and 1 MINOR** (Horizon master-timeout drain defeat; restic pairing/cycle-retention; bootstrap concurrency; offsite-lock ordering; `permissions:verify` ordering; stale workflow inventory). **Every finding from all four rounds has an explicit disposition in §11 (round 1), §11.3 (round 2), §11.4 (round 3) and §11.5 (round 4).** |
| **Binding constraints** | `claude/deploy-runbook.md`, `claude/database-topology.md`, `apps/erp/CLAUDE.md` rules 12/13/19/20/21 |
| **Feeds launch gates** | **E-1** (secrets — v3 adds four rows, §5.2), **E-3** (migration rehearsal), **E-4** (TN fiscal/legal) **+ a NEW `E-4a` row the owner must insert, non-waivable, verbatim text in §4.5b1** (D-16), **E-9** (staging runbook — owes the `channels:reconcile` post-migrate step, §7.7c), **E-10** (production release/cutover env decision) — `docs/handoff/OWNER-manual-launch-gates-2026-07-31.md` |
| **Authority** | This document **designs**. It executes nothing. Every infrastructure action is listed in §8 with an explicit owner-vs-orchestrator marker. |

> **Citation convention.** `file:line` = verified in this repo this session. `[R#]`/`[A#]`/`[B#]`/`[C#]`/`[D#]` = Part C research section. `⚠️ UNVERIFIED` = a claim this repository cannot establish; it stays UNVERIFIED until externally measured. `🔬 HYPOTHESIS` = a number this document asserts but has not measured; every one carries a **measurement owner** and an **acceptance threshold** and is registered in **Appendix D**. Nothing in this document is asserted from memory.

### Revision history

| Version | Date | Change |
|---|---|---|
| v1 | 2026-08-05 | Initial design. **Adversarially reviewed → REJECT.** Headline defect: DR-3 was presented as a four-hour executable recovery path with no registry images, no image-consuming deployment model, no panel-independent fallback, and no restore of MinIO/appstorage. |
| **v5** | **2026-08-05** | **This revision.** Round-4 scoped re-gate closure — dispositions in **§11.5**. Six residuals, no scope expansion beyond them: (1) **BLOCKER 1 — the Mode-B/deploy drain was defeated by Horizon's own master timeout.** Horizon's `MasterSupervisor` waits only `longestActiveTimeout()` = the max supervisor `timeout` (`RedisSupervisorRepository.php:101-104`) then `exit(0)` (`MasterSupervisor.php:167-203`); with `config/horizon.php:217` at `'timeout' => 60` and `ProcessImportJob.php:56` at `$timeout = 3600`, `service scale=0` sees a clean exit 0 after 60 s while a 3,600 s job is abandoned — the exact "looks drained, was killed" signal the 137-detection was built to catch. v5 makes the stop a **single atomic `docker service scale=0`** (no `horizon:pause`, no separate `horizon:terminate`), records the **full pre-stop task-ID set** and rejects **any** new task id (not just "0 running"), and adds a **Phase-0 code prerequisite (0.8k)**: supervisor `timeout` → `env('HORIZON_SUPERVISOR_TIMEOUT', 3900)`, Redis `retry_after` → `env('REDIS_QUEUE_RETRY_AFTER', 4200)` (> the timeout). The E-3 rehearsal (§4.8 step 14) gains a > 60 s job that must COMPLETE and a RED baseline at `timeout=60` proving the defeat. **HORIZON_SUPERVISOR_TIMEOUT is a config default, not an owner decision; D-21 already covers `stop_grace_period`.** (2) **BLOCKER 2 — restic pairing + retention.** The **`$COMPLETE_SNAP`** (the actual restore candidate) is now inventory-verified at **B4a** before the attestation and before the dead-man ping, its `inventory_sha256` is bound into the attestation, and restore + O-3b re-check it (not just `manifest_sha256`). `restic forget --group-by tags` is **struck** — the unique `cycle=$TS` tag self-groups and cross-cycle ageing never runs — and replaced with **cycle-level retention** that forgets all three role snapshots of an expired cycle as one unit; the post-prune assertion now requires exactly one bound `data`+`complete`+`attestation` per retained cycle. (3) **BLOCKER 3 — bootstrap concurrency.** The dispatcher gains `concurrency: {group: prod-bootstrap, cancel-in-progress: false}` and the `attempts_used` check becomes an **atomic increment-and-re-read before any build credential is read**; the contract is reworded to "one environment bootstrap, up to three approved attempts". (4) **MAJOR 4 — offsite-lock ordering:** the offsite copy of `release-set.lock.json` and the GitHub-independent cold start are **deferred to Phase 4.3b** (after the legs are created at 4.1/4.2); Phase 0 is marked **artefact-store-only**. (5) **MAJOR 5 — `permissions:verify` ordering:** 0.8h1 (which creates it) is **sequenced before** 0.8g (which consumes it), with the dependency stated in both rows. (6) **MINOR — stale facts:** the `origin/main` workflow inventory is corrected to `ci.yml`/`smoke-test.yml`/`sonarcloud.yml` (**no `react-doctor.yml`** — verified via `git ls-tree origin/main`), and Probe A's operator message is aligned with the corrected "stays reserved" reasoning. New Phase-0 code prerequisite: **0.8k**. No new owner decision. |
| **v4** | **2026-08-05** | **This revision.** Round-3 scoped re-gate closure — dispositions in **§11.4**. The round-3 record collapsed six blockers into three root causes, and all three are structural, not editorial: (1) **R3-N1 — the Mode B fence is re-ordered and `horizon:pause` is DROPPED ENTIRELY** as a holding state. A paused Horizon is an unhealthy container by the image's own healthcheck, so v3's "pause, then drain, then stop" left an unhealthy window in which the orchestrator could replace the draining task mid-job. v4's order is **record the task ID → `horizon:terminate` → STOP the worker Application inside the same drain window (`stop_grace_period` > the longest job timeout) → assert the same task exited and no replacement appeared**; Probe B gains its missing fail-closed assertion, the host backup timer is fenced and `izipos_backup` revoked with the rest, and the residual table's false `retry_after` explanation is corrected (§4.6a). (2) **R3-N2 — restic completion becomes a PAIRED-OBJECT protocol.** v3's phase-3 `restic backup MANIFEST.json` created a manifest-only snapshot that did not contain the cycle data, so no single restic snapshot held both the data and its `complete` marker. v4 re-snapshots the whole staged directory after the promote (dedup makes it near-free), selects it by an **external `--tag`**, and binds it with a completion attestation naming the data snapshot; `restic check --read-data-subset` is downgraded to the evidence it actually is and a snapshot-scoped `restic ls --json` inventory check is added; **O-3b now audits BOTH legs** (§4.4b1, §4.4d, §6 O-3b). (3) **R3-N3 — the bootstrap exception becomes dispatchable and acyclic.** A `workflow_dispatch` workflow must exist on the default branch, which v3's Phase-0.8i sequencing made impossible; v4 lands a minimal **bootstrap dispatcher on `main` first**, and breaks the digest/commit fixed point by separating **source release identity** (the git commit) from **deployment-state metadata** (an attested, signed release-set lock published as a workflow artefact and to both backup legs, not a checked-in digest). The "exactly one build" contradiction is replaced by a **machine-enforced `bootstrap_open` state record** with an attempt cap, and **V-10 gains an explicit input/pass table with two modes** (§7.0.2a, §7.0.2b, §7.0.5, §7.4c). Also fixed, all MAJOR: V-11's non-executable pseudo-commands (**R3-N4**, §7.4a), the `\connect` psql meta-command in the provisioning grant listener (**R3-N5**, §3.4c), the stale `--fix` signature and the `channels:reconcile` fail-closed contract (**R3-N6**, §7.4b/§7.7c), D-18's false "noncurrent version" retention claim (**R3-N7**, D-18/§4.5a1), and the `set -e` verification-capture defect in the backup script (§4.4d). New owner decisions **D-20** and **D-21**; new risks **R-33…R-36**; new hypotheses **H-22/H-23**. |
| **v3** | **2026-08-05** | **This revision.** Round-2 re-gate closure — dispositions in **§11.3**. Structural changes: (1) **Mode B gains a provable drain** (tenant suspension fence → Horizon pause → active-job probe → scheduler stop → revoke → terminate → assert zero), §4.6a; (2) the two offsite legs are **split by property** — Object Storage is append-only `rclone copy --immutable` into versioned keys with a **per-object checksum inventory**, restic is the deletable mirror (§4.5a, §4.4b); (3) a **ONE-TIME BOOTSTRAP EXCEPTION** makes the first release acyclic (§7.0.2a) and the DR-3 TLS cold start becomes **one** executable path (§7.0.3); (4) `MANIFEST.json` gains a **`staged` → `complete`** two-phase write, with `complete` published only after both offsite legs verify (§4.4b/d) and the backup dead-man re-keyed to **age-of-last-COMPLETE** (O-3); (5) **V-11 becomes an executable fail-closed probe** with expected output (§7.4a); (6) **runtime DB role loses `CREATEDB`/`DROP`** — a separate provisioning identity is used only during tenant creation (§3.4c); (7) **release-set digest rotation + rollback runbook** (§7.0.5) and the initial deploy brought under the same gate; (8) one consistency pass over §§9–10 and Appendices A–E, a **single authoritative cost table** (Appendix B), and **numeric acceptance/reopen thresholds on every Appendix D hypothesis**; (9) new owner decisions **D-15…D-19**; (10) two facts folded in from parallel work: the deploy-time queue-drain caveat list and the **new fiscal-verifier command contracts** (§7.4, §7.7). |
| **v2** | **2026-08-05** | Full disposition of all 38 round-1 findings in **§11**. Structural changes: (1) a **Phase 0 image-registry + panel-independent-manifest prerequisite** (§7.0, §8 Phase 0) — production consumes digest-pinned GHCR images, never git builds; (2) **honesty pass on every number** — RPO/RTO restated conditionally, unmeasured figures demoted to 🔬 HYPOTHESIS with owners and thresholds (**Appendix D**); (3) **RTO for total host loss narrowed from 4 h to a committed ≤ 8 h** until a full-host rehearsal measures it (D-14); (4) backup cycle gains a **manifest + reconciliation protocol** and a **MinIO offsite leg**; (5) **tenant-maintenance state** added to every restore path; (6) compose source-of-truth conflict resolved (§3.7); (7) credential blast radius reduced (MinIO app key, DB role split, break-glass package); (8) owner decisions **D-9…D-14** appended. |

### v2 claim discipline (binding on this document)

Three rules the review's verdict makes non-negotiable. They apply to every sentence below.

1. **Honesty over aspiration.** A number this document has not measured is written `🔬 HYPOTHESIS` and appears in **Appendix D** with a named measurement owner, a measurement method, and an acceptance threshold. It may not be used as a commitment, an SLA, or a gate-closure argument until it is measured.
2. **UNVERIFIED stays UNVERIFIED.** Every claim the review marked UNVERIFIED (provider pricing, panel behaviour, DNS state, legal retention, live-server state) remains marked UNVERIFIED here. v2 does not resolve them by asserting them more confidently; it names who resolves them and when.
3. **Conditional claims state their conditions.** Per Finding 8: an RPO or RTO figure is meaningless without the conditions under which it holds. Every objective in §4.1 now carries its conditions and its failure modes inline.

**Scope narrowing is the preferred remedy.** Where a claim could not be made true without inflating the buildout, v2 narrows the claim rather than growing the build (RTO 4 h → committed 8 h; PITR deferred with a decision date rather than promised). **The one exception the owner has ruled non-negotiable is backups** — no backup capability is narrowed, and §4 gained scope (MinIO offsite leg, manifest/reconciliation, per-DB source metadata) rather than losing it.

---

## 1. Executive summary

### 1.1 The shape

One dedicated Hetzner box in FSN1 or NBG1, registered as the **third remote server** on the existing Dokploy Cloud panel. Eight persistent services (not staging's ten) **plus one one-shot bucket-provisioning job**. Direct Postgres — **no PgBouncer anywhere in the request path**, because transaction pooling leaks the per-request tenant DB swap [A4.2]. Backups run from **host crontab**, deliberately *not* from Dokploy, because a paused panel would silently stop them [C4].

**What v2 changed about the shape.** Production does **not** build from git. CI builds immutable images on green `main` and pushes them to GHCR; the Dokploy production Applications are `sourceType: docker`, pinned to a **digest** (§7.0). That single change is what turns DR-3 from a story into a procedure, and it is a **Phase 0 prerequisite**, not a Phase 6 afterthought. Alongside it, the repository gains a **checked-in, secret-free production compose manifest** that can cold-start the whole stack with `docker compose` on a bare host with the panel deliberately unused (§7.0.3). The panel remains the steady-state control plane; it is no longer a single point of *recovery*.

### 1.2 Headline decisions

| # | Decision | Ruling | Why | Evidence |
|---|---|---|---|---|
| 1 | Server | **AX42-1 dedicated, €97.30/mo + €49 setup**, FSN1 or NBG1 | ECC DDR5 under a chained-hash fiscal ledger; 30 % cheaper than CCX33 with 2× RAM and 2× disk after the 15 Jun 2026 price rise | [B2][B6][R4] |
| 2 | Panel | **Third remote server on the existing Dokploy Cloud panel**, upgraded to **Startup** tier | Official guidance; one control plane; a second panel is a second swarm with no migration path (issue #3508) | [C3][C4] |
| 3 | Dokploy version | **≥ v0.29.13 REQUIRED before any production wiring** | v0.29.13 is a ~15-CVE security release: command injection in DB backup/restore, SSH private-key disclosure, member→root WebSocket escalation | [C6] |
| 4 | Tenancy | db-per-tenant, **`DB_PGBOUNCER=false`**, `DB_DIRECT_HOST` = the Postgres appName. 🚨 **v3: the request-serving role no longer holds `CREATEDB`** — a separate `provisioning` connection/role issues `CREATE`/`DROP DATABASE` (§3.4c) | PgBouncer transaction pooling leaks tenant swaps under concurrency. Stancl binds the database manager to `getTemplateConnectionName()` (`DatabaseConfig.php:149-165`), which v3 redirects to a dedicated connection so the runtime credential loses cluster-destructive rights | [A4]; `vendor/stancl/tenancy/src/DatabaseConfig.php:149-165` |
| 5 | DB naming | central `iziposcentral`; tenants `izipostenant_<uuid>` (Otospex: `otospexcentral` / `otospextenant_<uuid>`) | Owner ruling. **Requires a one-line code change** — the prefix is hardcoded | `apps/api/config/tenancy.php:62` |
| 6 | Meilisearch | **OMIT the container.** Divergence from staging, deliberate | `laravel/scout` is in neither `composer.json` nor `composer.lock`; zero `Searchable`/`Laravel\Scout` references. Staging's `depends_on: meilisearch: service_healthy` can block API startup for a package that does not exist | [A12] |
| 7 | PgBouncer | **Do not deploy.** Fix the `edoburu/pgbouncer:1.22.0` → `1.22.0-p0` tag in the composes anyway so nobody resurrects it | Deployed-and-bypassed on staging; invalid tag; leaks tenant swap | [A4.2][A4.3][A7] |
| 8 | Object storage | **MinIO on-box for launch**, backed up as a Docker named volume; migrate to Hetzner Object Storage only after a verified path-style test | Media is hard-bound to `Storage::disk('s3')` regardless of `FILESYSTEM_DISK`; Hetzner OS is virtual-hosted-style by default while the app sets `AWS_USE_PATH_STYLE_ENDPOINT=true` | `MediaUploadService.php:131`; [A13][R7] |
| 9 | Backups | **Host-crontab scripted loop**, hourly: `pg_dumpall --globals-only` + per-DB `pg_dump -Fc` → Hetzner Object Storage (WORM, different DC) + Storage Box via restic | Dokploy's native backup is one schedule per DB, drops globals, and its restore path omits `timescaledb_pre_restore()`; Dokploy Schedule Jobs die with the panel | [C1][C4][D2] |
| 10 | Restore | **Serial `pg_restore` with the Timescale pre/post pairing. `-j` is FORBIDDEN.** `docs/operations/BACKUP-RECOVERY.md` is actively wrong and must be corrected | Tiger Data: *"Do not use pg_restore with the -j option. This option does not correctly restore the TimescaleDB catalogs."* | [D4]; `docs/operations/BACKUP-RECOVERY.md:152-154,232` |
| 11 | Worker memory | **2 GB is a *starting* cgroup, not a derived floor.** A measured load test is a **launch gate** (V-9) | Horizon's `memory=128` is a *self-restart threshold*, not a reservation; `php.ini:5-9` permits **256 MB per process**, so ten processes plus recycle overlap can exceed 2 GB. The `10×128+64 ≈ 1.35 GB` arithmetic in v1 was arithmetic, not measurement (Finding 6). Staging's 512 M is still plainly wrong | `apps/api/config/horizon.php:212,216,223`; `apps/api/docker/php/php.ini:5-9` |
| 12 | Deploy gate | **Disable Dokploy auto-deploy on production apps.** Deploy is triggered by a GitHub Actions job that runs only after `all-checks-pass` is green on `main` | Dokploy's git watcher builds on push and does not consult GitHub check status. Today a red `main` would auto-ship to production | [A10]; `.github/workflows/ci.yml:1022-1036` |
| **13** | **Image supply (NEW in v2; bootstrap exception added in v3)** | **Production consumes digest-pinned GHCR images. Git builds on the production host are FORBIDDEN.** CI builds and pushes on green `main`; Dokploy production apps are `sourceType: docker`. **This is a Phase 0 prerequisite.** 🚨 **v3: the green-`main` rule cannot bind the very first build** (the digest is a function of the release commit that must contain the digest — round-2 N1). §7.0.2a defines a **ONE-TIME BOOTSTRAP EXCEPTION** with a recorded owner approval and an explicit sunset. 🚨 **v4 (round-3 R3-N3): v3's version could not execute** (a `workflow_dispatch` workflow must exist on the **default branch**) **and its supersession re-created the cycle.** v4 lands a bootstrap dispatcher on `main` first (Phase 0.8b1) and **takes the digests out of git entirely** — the release set becomes an attested `release-set.lock.json` (§7.0.2b, **D-20**), so there is no metadata commit and therefore no fixed point | Findings 16/17/31/36 and round-2 N1. DR-3's 4 h claim, the "one concurrent build serializes five deploys" problem, and "the running container is tied to no attested SHA" are all the same missing artefact. Dokploy rollbacks have been registry-based since v0.26.0 [C5] | §7.0; `.github/workflows/` contains no push/registry job today |
| **14** | **Production source of truth (NEW in v2)** | **`docker-compose.dokploy.yml` is STAGING-SHAPED and is marked non-production.** Production SoT = the **checked-in secret-free production manifest** (`deploy/production/compose.production.yml`) + the §3.3 service table. No silent coexistence | Finding 3. The checked-in file advertises `DB_CONNECTION=pgsql`, `DB_DATABASE=autoerp`, `AUTO_SEED=true`, Meilisearch, PgBouncer and a 512 M worker, and its header tells operators to deploy it directly. A manual panel configuration must not coexist with a repo file that contradicts it | §3.7, §7.0.3; `docker-compose.dokploy.yml:1-5,14-49` |

### 1.3 🚨 Three findings that gate everything else

**F-1 — `origin/main` is 4,303 commits and five weeks behind `origin/dev`.**
```
origin/main  5cf61a6fc  2026-06-29  "docs: add pre-discovery codebase analysis"
origin/dev   6f14f8232  2026-08-04  "fix(scheduler): gate-review round …"
git rev-list --left-right --count origin/main...origin/dev  →  3   4303
```
🔧 **v3 (round-2 F34/N5): the absolute phrasing "there is no artifact on `main` that can be deployed" is STRUCK here as well as in §7.3.** `main` does contain Dockerfiles, lockfiles, composes and a CI workflow; what it lacks is **the intended current release** — and a stale-but-buildable `main` is the more dangerous of the two, because it is exactly what an accidental deploy would ship. **A `dev` → `main` promotion PR, passing the full CI suite, is a hard prerequisite** to the first production deploy — and it is the *first time* `backend-test`, `frontend-test`, `pos-test`, `frontend-build` and `all-checks-pass` will run against five weeks of dev work in one shot (`ci.yml:185,854,886,913,1035`). Budget real time for it. This is not a deploy step; it is a project.

**F-2 — container boot is not evidence of anything. Five distinct failures all still yield a serving API container.** *(Widened in v2 per Finding 1.)*
v1 named only the rolling-migration trap. The entrypoint is broader than that, and only the **api** target runs it at all — worker/scheduler/websocket have their own entrypoints (`apps/api/Dockerfile:150-186`):

| Failure | Behaviour | Line |
|---|---|---|
| Database unavailable after 5 tries | **Skips the entire migrate/seed/permission block** and boots | `entrypoint.sh:103-120`, `:207` |
| Central `migrate --force` fails | Caught, logged, boots | `entrypoint.sh:122-127` |
| `tenants:migrate-rolling` per-tenant errors | Logged as *"completed with per-tenant errors"*, boots | `entrypoint.sh:129-145` |
| Permission reseed fails (when requested) | Non-fatal | `entrypoint.sh:147-161` |
| `permission:cache-reset` fails | Explicitly suppressed with `\|\| true` — **and does not run at all** on the DB-unavailable path | `entrypoint.sh:163-167` |

**Ruling:** migration and permission state is an **explicit release gate outside container boot** (§7.4, V-0…V-2). Container health is never accepted as evidence that schemas or permissions are current. **A failing V-0…V-2 fails the deploy job.**

**F-3 — `SYNC_PERMISSIONS_ON_BOOT` is set in no compose file, anywhere.**
`entrypoint.sh:153-161` gates the tenant permission reseed on it; `.env.example` documents `false`. Every deploy that adds a permission therefore lands 403s on existing tenants until someone reseeds by hand — the exact recurring gap the entrypoint comment names (`uom.view` 2026-06, `loyalty.enroll` 2026-07). §7.5 rules on this.

**F-4 — tenant database names are STORED, not derived. The env prefix does not rename anything.** *(NEW in v2, per Finding 2 — this materially changes §3.2.)*
Stancl resolves a tenant DB name from the **stored** internal `db_name` first and falls back to `prefix + key + suffix` only when that value is absent (`vendor/stancl/tenancy/src/DatabaseConfig.php:39-41,66-69`). `CreateDatabase` calls `makeCredentials()` *before* creating the database, and `makeCredentials()` **sets and persists** `db_name` (`.../DatabaseConfig.php:81-97`, `.../Jobs/CreateDatabase.php:30-43`), which lands in `tenants.data` via the virtual-column trait because it is not a physical column (`app/Modules/Tenant/Domain/Tenant.php:138-179`).

Three consequences v1 got wrong:
1. `TENANCY_DB_PREFIX=izipostenant_` affects **only newly provisioned tenants**. It cannot and will not redirect an existing correctly-provisioned tenant.
2. It **will** change lookup for any legacy/corrupt tenant row that is *missing* `data.tenancy_db_name` — a silent, destructive behaviour change on exactly the rows that are already broken.
3. The repository default derives `tenant<uuid>` (no underscore — `config/tenancy.php:58-63`). If live staging databases really carry an underscore, their **stored** names are load-bearing and the derived name is a red herring. Live rows are ⚠️ **UNVERIFIED** from this repository.

**Ruling:** §3.2 gains a **blocking central-DB audit + backfill** before the code change ships, and regression tests for both stored-name precedence and default-prefix fallback.

### 1.4 Architecture diagram

```
                          Internet
                             │
        ┌────────────────────┼─────────────────────┐
        │                    │                     │
   riserpos.app       api.riserpos.app        app.dokploy.com
  (web, TLS/LE)      (api,  TLS/LE)           (control plane —
        │                    │                  Dokploy Cloud,
        └──────────┬─────────┘                  ≥ v0.29.13,
                   │                            NOT in the data path)
        ┌──────────▼──────────────────────────────────────────────┐
        │  HETZNER AX42-1  ·  FSN1/NBG1  ·  Docker Swarm (1 node) │
        │  8c/16t Zen4 · 64 GB DDR5 ECC · 2×512 GB NVMe RAID1     │
        │                                                          │
        │   ┌─────────┐   dokploy-network (overlay)                │
        │   │ Traefik │◀──────────────┬──────────────┐             │
        │   └────┬────┘               │              │             │
        │        │                    │              │             │
        │   ┌────▼─────┐        ┌─────▼─────┐        │             │
        │   │   WEB    │        │    API    │        │             │
        │   │ nginx    │  /api/ │ nginx +   │        │             │
        │   │ SPA      │───────▶│ php-fpm   │        │             │
        │   │          │ /app/  │ ROLE=api  │        │             │
        │   │          │───┐    └─────┬─────┘        │             │
        │   └──────────┘   │          │              │             │
        │                  │          │   ┌──────────▼──────────┐  │
        │             ┌────▼──────┐   │   │  WORKER (Horizon)   │  │
        │             │ WEBSOCKET │   │   │  6 queues · 2 GB    │  │
        │             │  Reverb   │   │   └──────────┬──────────┘  │
        │             │ 1 replica │   │              │             │
        │             │ :8080     │   │   ┌──────────▼──────────┐  │
        │             └────┬──────┘   │   │     SCHEDULER       │  │
        │                  │          │   │   schedule:work     │  │
        │                  │          │   └──────────┬──────────┘  │
        │        ┌─────────┴──────────┴──────────────┘             │
        │        │                                                 │
        │   ┌────▼──────┐   ┌──────────┐   ┌──────────────────┐    │
        │   │  REDIS 7  │   │  MinIO   │   │   POSTGRES 16    │    │
        │   │ cache/    │   │  s3 disk │   │ timescaledb 2.13 │    │
        │   │ queue/    │   │  (media) │   │                  │    │
        │   │ session   │   │          │   │ iziposcentral    │    │
        │   └───────────┘   └──────────┘   │ izipostenant_… × N│   │
        │                                  │ NO PgBouncer      │   │
        │                                  └─────────┬─────────┘   │
        │                                            │             │
        │   host crontab (panel-independent)         │             │
        │   ┌────────────────────────────────────────▼──────────┐  │
        │   │ hourly: pg_dumpall --globals-only                 │  │
        │   │       + per-DB pg_dump -Fc  + sha256 manifest     │  │
        │   │ → rclone → Hetzner Object Storage (WORM, other DC)│  │
        │   │ → restic  → Hetzner Storage Box BX11              │  │
        │   │ → ping healthchecks.io ON SUCCESS ONLY            │  │
        │   └───────────────────────────────────────────────────┘  │
        └──────────────────────────────────────────────────────────┘
                   │                        │
        ┌──────────▼──────────┐   ┌─────────▼──────────────────────┐
        │ Hetzner Object Stor.│   │ Hetzner Storage Box BX11       │
        │ FSN1 or NBG1        │   │ (the OTHER of FSN1/NBG1)       │
        │ object-lock / WORM  │   │ restic, dedup + encrypted      │
        └─────────────────────┘   └────────────────────────────────┘

        NOT DEPLOYED (deliberate, §3.7):  Meilisearch · PgBouncer
        OFF-BOX MONITORS:  UptimeRobot (HTTP) · healthchecks.io (dead-man)
```

---

## 2. OWNER-DECISION LIST

**Every row below blocks work. None can be decided by an agent.** Recommendations are binding defaults — if the owner does not respond, the recommendation is what gets built, except where "Blocks" says otherwise.

| # | Decision | Recommendation | Cost (EUR/mo, ex-VAT) | Blocks | Reversible? |
|---|---|---|---|---|---|
| **D-1** | **Server option + location** | **AX42-1 dedicated, 64 GB DDR5 ECC, 2×512 GB NVMe — €97.30/mo + €49 one-off setup + €1.70/mo IPv4, in FSN1 (fallback NBG1).** ECC is the argument: this is a chained-hash fiscal ledger and a silent bit-flip is the worst failure class we can have. Alternatives: **EX44-1-LTD €57.30, €0 setup** (better single-core than AX41-1-LTD at the same price, but **non-ECC DDR4** and *"offered as long as supply lasts"* — not a stable SKU); **CCX33 cloud €138.49** (buy operability: 60-second resize, snapshots, Hetzner-managed host-failure recovery — but 1.4× the price for half the RAM and half the disk after the June 2026 rise). | **99.00** + 49.00 once | **Everything.** No server = no build. | ❌ Migrating a live fiscal tenant off a box is a full DR rehearsal. Decide once. |
| **D-2** | **Domains + DNS** | Keep **`riserpos.app`** (apex → web) and **`api.riserpos.app`** (→ API), matching `docker-compose.dokploy.yml`. Owner creates the records in §Appendix A **with TTL 300**, before provisioning, so Let's Encrypt HTTP-01 succeeds on first deploy. | 0 | TLS, first deploy, Sanctum/CORS env, the `VITE_API_URL` build arg | ✅ but a domain change means a rebuild (`VITE_API_URL` is baked at build time) |
| **D-3** | **Backup destination + retention** | **Both legs.** (a) **Hetzner Object Storage €4.99/mo** — bucket in the *other* German DC from the server, **object-lock/WORM enabled**, includes 1 TB storage + 1 TB egress. (b) **Storage Box BX11 ~€3.20/mo** ⚠️ *third-party price, confirm in the order form* — restic, client-side encrypted, dedup, unlimited free traffic. 🔧 **v2 corrections (Finding 37):** these are **two mechanisms, NOT two independent failure domains** — both are Hetzner (R-4, D-13) — and they have **different threat models**, spelled out in §4.5a. ~~Operational retention is 90 days (§4.5).~~ 🚨 **STRUCK — factually wrong.** §4.5's tiers are 48 hourly / 14 daily / 8 weekly / 12 monthly; there is no 90-day operational tier. The only 90-day figure in the design is the **interim WORM lock duration** (D-11), which is a different thing. **Sub-decision D-3b: long-term fiscal retention — now NON-WAIVABLE (§4.5b).** Tunisian fiscal archival law may require **years**. Route through **E-4 (TN accountant/legal)** with a **named approver**; an owner risk acceptance does **not** close it, and **no WORM auto-lock beyond 90 days is configured until they answer** (D-11). | **8.19** ⚠️ | §4 entirely; **E-3** rehearsal; **blocks the first fiscal record** | ✅ destinations are swappable; ❌ a retention period you set too short destroys evidence you cannot recreate |
| **D-4** | **Secret store** | **1Password (Business/Teams, ~$8/user/mo)** — see §5.1 for the three-way tradeoff. It wins on the thing that actually matters here: a single human release owner needs recovery, audit history, and a CLI (`op read`) that can inject into a Dokploy API call without ever writing a plaintext file. Doppler is better at *machine* delivery but adds a second SaaS the panel would depend on; Vaultwarden is free but self-hosted, and self-hosting your break-glass credential store on infrastructure you may be trying to restore is a circular dependency. | ~7.40 | **E-1 (secret rotation)** — 9 credential rows / 12 variables must reach `revoked` before tenant #1 onboards (`docs/security/secret-rotation-2026-05-12.md:53-67`) | ✅ |
| **D-5** | **Email provider** | **Required — not optional.** `MAIL_MAILER=log` (`apps/api/.env.example:120`) silently swallows every email, and the app really sends: `EmailVerificationService`, `DocumentEmailService` (invoices to customers), `FraudAlertNotificationService`. Recommend **Brevo free tier (300/day)** for launch — €0, ample at one tenant — with **Postmark ($15/mo, 10k)** as the upgrade if invoice deliverability becomes a customer-facing issue. Either way the owner must add **SPF + DKIM + DMARC** records (Appendix A). | 0 (Brevo free) | Email verification at onboarding; invoice delivery; `MAIL_PASSWORD` row in E-1 | ✅ |
| **D-6** | **Alert destination** | **Telegram** (Dokploy supports it natively [C5]; instant on a phone; free) **plus email as a second channel**. Every alert in §6 routes here. Dokploy notification event types must include **database backups** and **volume backups** — a silent backup outage is the realistic failure mode. | 0 | §6 entirely | ✅ |
| **D-7** | **Dokploy Cloud plan tier** | **Startup, from $15/mo.** Today's panel is Cloud with 2 servers registered [A1]. Production is the third. On Hobby that is $4.50 + 2 × $4.50 = **$13.50** but stays capped at **1 user, 1 org, 2 environments**. Startup is **$1.50 more** for 3 servers, unlimited users/orgs/environments and unlimited backups. Take Startup. ⚠️ Note **remote-server monitoring is Cloud-only** [C3] — which we already are — and **OSS default is 1 concurrent build per server**, so builds queue. | ~13.90 ⚠️ USD→EUR | Registering the third server | ✅ |
| **D-8** | **Dokploy upgrade to ≥ v0.29.13 — GO/NO-GO** | **GO, and do it BEFORE the production server is registered.** v0.29.13 (2026-07-21) fixes ~15 CVE-class issues including **OS command injection in the database backup/restore commands**, **SSH private-key disclosure via server read endpoints**, and **missing authz on docker/terminal WebSockets (member → root)**. The panel currently holds SSH keys to AX42 and to staging. Registering a production server on an unpatched panel hands an attacker the production host as well. | 0 | **Hard-blocks registering the production server.** Nothing in §8 Phase 1 starts before this. | n/a |

### 2.1 Decisions added by the v2 revision (D-9 … D-14)

**D-1…D-8 keep their numbering and their content unchanged**, except that D-3's "operational retention is 90 days" sentence is **struck as factually wrong** (Finding 14 — see D-11 and §4.5) and D-3b is escalated to a **non-waivable** sub-gate.

| # | Decision | Recommendation | Cost (EUR/mo, ex-VAT) | Blocks | Reversible? |
|---|---|---|---|---|---|
| **D-9** | **Image registry for production images** (headline decision 13) | **GHCR** (`ghcr.io/otospexsolutions/erp-*`) — same org, same credentials, `packages: write` from the existing CI. ⚠️ **Pricing must be confirmed, not assumed:** GitHub Packages storage and data transfer are **free for *public* packages**; **private** packages consume the account's Packages storage and **data-transfer** allowance, and egress to a **non-GitHub-Actions** host (our Hetzner box pulling on every deploy and every DR event) is the metered direction. With an API image plausibly in the hundreds of MB, a small plan allowance can be consumed by ordinary deploy traffic. **Owner must check the current plan's Packages allowance in the GitHub billing console and decide: (a) public packages (free egress, but the image contents become public — the API image contains application source), (b) private on a plan with sufficient transfer, or (c) a different registry (Docker Hub / Hetzner-adjacent).** **(a) is not recommended** — the API image ships `COPY . .` of the full application source (`apps/api/Dockerfile`). Recommendation: **(b) private GHCR**, contingent on the allowance check. | ⚠️ **UNVERIFIED — requote at decision time.** v1 asserted "€0, free at this volume"; that assertion is withdrawn. | **Phase 0.** Headline decision 13, DR-3, §7.0, and the whole deploy pipeline | ✅ registry is swappable; the *pattern* (digest-pinned images) is not |
| **D-10** | **WAL archiving / pgBackRest — decision DATE, not a vague trigger** | v1 deferred this behind *"when a dump cycle exceeds 5 minutes"*, which is a threshold that may never fire while the exposure grows anyway (Finding 7). **Ruling: the trigger is retained as an early-warning signal, but the *decision* is date-bound. Owner decides GO/NO-GO on continuous WAL archiving (pgBackRest, `repo1-type=sftp` → Storage Box [D5]) by `launch + 30 days`, or at tenant #3, whichever is first.** A NO-GO is acceptable and must be recorded as a dated, explicit risk acceptance that the launch RPO has **no point-in-time recovery** — not left implicit. | 0 (uses the existing Storage Box) + operator time | §4.1's RPO wording; §9.1's threshold table. Does **not** block launch | ✅ |
| **D-11** | **WORM object-lock mode + duration** | ⚠️ **Blocked on E-4 (TN accountant/legal).** Interim ruling: enable object lock at bucket creation (it cannot be retrofitted [B4]) in **governance mode** with a **90-day** retention on the operational tiers — long enough to defeat a ransomware/operator-error window, short enough that a wrong guess self-heals. **No compliance-mode lock and no multi-year retention is configured until the accountant answers.** v1's "placeholder: 10 years" is withdrawn: an irreversible ten-year compliance lock set from a placeholder is a cost and privacy liability that cannot be undone. ⚠️ Provider object-lock semantics (modes offered, whether governance-mode bypass requires a separate permission, lifecycle interaction) are **UNVERIFIED** and must be read from the console at bucket creation. | included in D-3 | §4.5; **E-4** | ❌ **Compliance-mode locks are irreversible by design.** Governance mode is the reversible choice and is why it is the interim ruling |
| **D-12** | **Second custodian + offline break-glass package** | **Required, not optional** (Finding 20). One human holding one 1Password account is not a break-glass plan: it is a single point of failure across DNS, registry, panel, host SSH, both backup repositories, and the restic password. **Recommendation: a second 1Password seat for a named second custodian, plus a physical offline package (printed/sealed, or an encrypted offline volume) containing 1Password recovery material, Dokploy account recovery, GitHub org/packages recovery, DNS provider credentials + recovery, Hetzner Robot/Cloud, host SSH private key, `RESTIC_PASSWORD`, Object Storage keys, and the Dokploy env-encryption keyring.** Rehearsed once from a clean machine with the production host and the primary operator's laptop assumed unavailable (§5.4). | ~7.40 (second seat) | §5, DR-3, DR-4. Does not block launch, but **blocks the claim that DR is executable** | ✅ |
| **D-13** | **Off-provider third backup leg (R-4) — decision DATE** | v1 deferred this to *"as soon as tenant #1 has real fiscal data"*, which is a condition with no date. **Ruling: owner decides GO/NO-GO by `first fiscal record + 14 days`.** Both current legs are Hetzner; a Hetzner-wide event or account suspension loses production and both copies. ⚠️ R2/B2 pricing **UNVERIFIED**. | ⚠️ UNVERIFIED (single-digit €/mo expected) | Nothing at launch. Closes the largest residual risk in the design | ✅ |
| **D-14** | **Accept the honest RPO/RTO commitments** | v1 committed to RTO ≤ 4 h for total host loss on the strength of a lifeboat that could not be launched. **v2 narrows the commitment rather than inflating the build: committed total-host RTO becomes ≤ 8 h; 4 h remains the *target*; the measured p95 from the Phase 7.9 full-host rehearsal becomes the committed number thereafter** (§4.1, Finding 18). RPO becomes explicitly conditional (§4.1). **The owner must accept these as the numbers that get told to a customer**, because a missed RTO commitment during a real incident is a trust event, not an engineering one. | 0 | Any customer-facing availability statement; §4.1; §11 | ✅ revised upward or downward on measured evidence only |

### 2.2 Decisions added by the v3 and v4 revisions (D-15 … D-21)

**D-1…D-14 keep their numbering and their content unchanged**, except that D-3's cost line now reads from Appendix B's single authoritative table (round-2 F37) and D-4/D-12's seat count is reflected there.

| # | Decision | Recommendation | Cost (EUR/mo, ex-VAT) | Blocks | Reversible? |
|---|---|---|---|---|---|
| **D-15** | **Approve the ONE-TIME BOOTSTRAP EXCEPTION and designate the release-candidate SHA** (§7.0.2a) | **APPROVE.** 🚨 **v4 (round-3 R3-N3) changes what is being approved, so re-read it.** v3's version could not run (a `workflow_dispatch` workflow must live on the default branch, and v3 put it only on `dev`) and its supersession commit re-created the digest/commit fixed point. v4 (a) lands a **minimal bootstrap dispatcher on `main` first**, in its own reviewed control-plane commit, and (b) stops checking digests into git at all — the release set is an **attested lock artefact**, not a source file (§7.0.2b). The owner must (i) name the `dev` SHA in writing, (ii) approve the `workflow_dispatch` through the `production` GitHub environment so the approval is recorded immutably, (iii) approve the control-plane commit that puts the dispatcher on `main`, and (iv) accept that production runs `bootstrap-<sha>` images until the first green-`main` build supersedes them. **Recommended sunset: ≤ 14 days (H-21); attempt cap 3 (§7.0.2a).** ⚠️ If Phase 0.9's CI is red and the candidate changes, the bootstrap is re-run against the new SHA **with a fresh approval and a decremented attempt budget** | 0 | **Phase 0.8b, 0.8b1, 0.8c, 0.9 — i.e. the entire release.** Nothing production-side can be built without it | ✅ superseded automatically at the first green-`main` build |
| **D-16** | **Amend the launch-gate sheet with the non-waivable fiscal-retention row E-4a** (§4.5b1) | **DO IT, and do it before Phase 4.1**, because the WORM bucket's object-lock mode cannot be retrofitted (D-11, [B4]) and the interim 90-day governance lock is only defensible while E-4a is genuinely open. The exact row text and the two hard-rule amendments are given verbatim in §4.5b1. 🚨 **This document cannot make the edit** — `OWNER-manual-launch-gates-2026-07-31.md` is a Phase-E, human-only evidence sink | 0 | **Phase 7.12**, and (through D-11) the Object Storage bucket's lock configuration | ❌ a retention period that was too short destroys evidence; a compliance-mode lock that was too long cannot be released |
| **D-17** | **Accept that a single-tenant restore pauses the queue FLEET-WIDE** (§4.6a Mode B residual) | **ACCEPT at launch.** The drain (v4 steps 3–6) stops job processing and the scheduler for *every* tenant for an expected ≤ 5 min (H-19); HTTP for other tenants is untouched. 🚨 **v4 adds the worst case, and it is worth re-reading before accepting: because the worker stop must wait out the running job, a long import can extend that fleet-wide pause to the job's timeout — up to 3,600 s today (D-21, R-34).** There is one Horizon supervisor and six shared queues (`config/horizon.php:201-249`), so per-tenant queue partitioning is a code project, not a config toggle. **At one tenant this costs nothing.** Revisit if DR-1 becomes routine or at tenant #5, whichever is first | 0 (accept) / significant engineering (partition) | Nothing at launch. It bounds what DR-1 can promise multi-tenant | ✅ |
| **D-18** | 🚨 **Accept that media DELETIONS DO NOT PROPAGATE to the WORM leg** (§4.5a1) | **ACCEPT, with the privacy consequence stated — and 🚨 v4 (round-3 R3-N7) corrects the retention statement, which was materially wrong in a reassuring direction.** The append-only Object Storage leg is what makes "a compromised host cannot destroy the media" true, and the price is that an image deleted in the application **remains in the offsite copy INDEFINITELY, until an owner-authorised deletion or expiry action is performed and any object lock has expired**. v3 said it remains "until the bucket lifecycle ages the noncurrent version out". **That is false, and the mechanism is worth understanding because it changes the owner's obligation:** under `rclone copy`, a source deletion issues **no destination operation at all**. No delete marker is created, so no noncurrent version is created, so the 14-day noncurrent-version lifecycle rule (§4.5a, the lifecycle table) **has nothing to act on** — the object simply stays *current*, and current versions have **no expiry rule** by the interim ruling. Erasure is therefore a **manual owner action with a committed response time**, not an automatic one: **§4.5a1's erasure runbook is part of this decision**, and the owner accepts a **maximum response time of 30 days from a documented erasure request to confirmed removal from the WORM leg** (shorter if a supervisory authority imposes one). **This has a data-protection consequence the owner must see in these words: if a customer or regulator requires an image to be erased, erasing it in MinIO is not sufficient — the offsite copy must be dealt with separately, using the owner-held console credential the host does not have.** Mitigations in the design: the restic leg (L4a″) *is* deletable and is the mirror that reflects erasures; the fiscal-archive prefix has no lock at all until E-4a answers. ⚠️ Whether a governance-mode lock permits a console-side erase within the retention window is **UNVERIFIED** and must be read from the console at Phase 4.1 — **if it does not, the interim lock duration must be shortened, not the leg removed** | included in D-3 | §4.5a1; interacts with **D-11** and **E-4a** | ⚠️ partially — objects already written under a lock cannot be removed until it expires |
| **D-19** | **Accept the residual on the provisioning credential** (§3.4c) | **ACCEPT, and it is a genuine reduction, not a relabelling.** v3 removes `CREATEDB` and database ownership from the request-serving role, so worker, scheduler and websocket **cannot create or drop databases at all**, and no SQL-level flaw on any of the four can. The residual is that the **api** container must hold the `provisioning` credential in its environment, because `TenantProvisioningService.php:117` provisions **synchronously inside the signup request**. A full env-reading compromise of the api container therefore still reaches `CREATEDB`. **The named exit is ticketed** (queue provisioning to a dedicated worker), and it requires making signup asynchronous — a product change. The WORM leg (§4.5a) remains the containment boundary. 🚨 **The round-2 re-gate requires this to be either separated or explicitly accepted by the owner; it may not be called "closed" silently** | 0 | Nothing. It is a risk-acceptance row, and R-25 carries it | ✅ the ticket removes it |
| **D-20** *(NEW in v4)* | 🚨 **Approve moving the release-set lock OUT of git and into an attested artefact** (§7.0.2b) | **APPROVE.** This is the only change that actually breaks the digest/commit fixed point round 3 found (R3-N3 / finding 3.2), and it is a real architectural choice, not a formatting one. **What changes:** `deploy/production/compose.production.yml` stays in git, secret-free, but its image references become `${IMAGE_API}`-style variables with **no defaults**; the five digests live in `release-set.lock.json`, produced by the build workflow, **signed/attested**, published as a workflow artefact **and** copied into both offsite backup legs with the cycle. The host materialises it next to the compose file at deploy and at cold start (`BOOTSTRAP.md` step 3). **What the owner is accepting:** the digests are no longer readable from a git checkout alone — a cold start needs the lock from the artefact store *or* from a backup leg, which is why it is written to **both** legs and why DR-3 gains an explicit "fetch the lock" step. **What it buys:** `main` never has to carry a commit whose only content is deployment state, so "every commit on `main` has a corresponding production digest" becomes true without a second commit, and V-10/V-12 verify running digests against a **signed** lock rather than against a file any repo write could edit. **The rejected alternative is recorded**: a two-commit protocol with a path-filtered no-rebuild rule, which works but makes the rebuild exemption a permanently load-bearing CI rule | 0 | **§7.0.2a, §7.0.3, §7.0.5, V-10/V-12, DR-3 step 3a** | ✅ reversible to the two-commit protocol, but only before the first release |
| **D-21** *(NEW in v4)* | 🚨 **Accept the worker `stop_grace_period` that the Mode B / deploy drain requires** (§4.6a) | **ACCEPT — and note it costs money in the worst case, which is why it is a decision.** v4 drops `horizon:pause` as a holding state (a paused Horizon fails the image's own healthcheck, so an unattended pause invites the orchestrator to replace the draining task mid-job — round-3 finding 1.2). The replacement is: terminate, then **stop the worker Application inside the drain window**, and let Docker's stop grace period cover the in-flight job. **For that to be a drain rather than a kill, `stop_grace_period` on the worker service must exceed the longest job timeout — today that is `ProcessImportJob`'s 3,600 s (`ProcessImportJob.php:50-65`).** The owner accepts that (a) a worker stop can therefore block for up to an hour in the pathological case, (b) the deploy/restore runbooks state that bound up front, and (c) the alternative — capping job timeouts at, say, 900 s — is a product change to the import pipeline, ticketed but not taken now. 🎫 **Named exit:** move long imports to their own queue and their own worker service with its own grace period, so the general drain is bounded by seconds | 0 | **§4.6a step 3, §7.7b, §3.3 worker row, Phase 3.3** | ✅ the ticket bounds it |

**Monthly total at the recommendation: ≈ €136/mo + ⚠️ an unquantified container-registry line (D-9) + €49 one-off.** 🚨 **v3: this figure is taken from Appendix B's single authoritative table and nowhere else** — v2 stated ≈ €121 here while Appendix B's base band said ≈ €136, because the €121 line silently assumed one 1Password seat and a free registry. The €121 figure survives **only** as Appendix B's *low* band, which requires 1Password to already exist **and** public GHCR packages (explicitly not recommended). See Appendix B for bands, exclusions and the requote instruction.

---

## 3. Topology specification

### 3.1 Host

| Property | Value | Note |
|---|---|---|
| Model | AX42-1 (D-1) | 8c/16t Ryzen 7 PRO 8700GE, 64 GB DDR5 ECC, 2×512 GB NVMe |
| Location | FSN1 (fallback NBG1) | ⚠️ Tunis RTT to FSN/NBG is **unverified** — §10 R-1 |
| RAID | RAID1 across both NVMe (~512 GB usable) | Set at install; not the default on every image |
| Network | 1 Gbit/s guaranteed, unlimited traffic | [B2] |
| Storage headroom | 🔬 **HYPOTHESIS — the v1 "< 150 GB / ~70 % free" figure is withdrawn as unbudgeted** (Findings 15, 30). Replaced by the **line-item capacity budget in Appendix E**, which is the governing artefact | Appendix E enumerates PGDATA, local dump staging, MinIO, appstorage, Laravel logs, Docker container logs, Docker images/build cache, and failed partials, each with a growth rate and a **hard ceiling**. Alerts fire on **bytes and inodes**, not only on `/` percentage |
| Filesystem layout | **Backup staging (`/var/backups/pg`) lives on a separate LVM volume / mount, NOT on `/`** | Finding 15: 48 local full-cluster dump sets on the root filesystem is how a disk-full event takes out logging and dumping simultaneously. A dedicated mount converts "the host stops working" into "the backup job fails loudly and the dead-man fires" |
| Docker log rotation | `/etc/docker/daemon.json`: `log-driver: json-file`, `max-size: 50m`, `max-file: 5` | **Mandatory.** Unbounded json-file logs are a documented cause of the 2026-08-03 staging disk-full incident class |
| Swarm | Initialized by Dokploy's one-time server setup | Standalone swarm; remote servers do not cluster with each other [C3] |
| Firewall | Public: 80, 443, 22 (key-only). **Postgres external port: NOT exposed.** | Staging exposes 5434; production must not. Ops access is `docker exec` over SSH. |

### 3.2 Database naming — ruling and the code change it requires

**Ruling (binding):** central `otospexcentral` / `iziposcentral`; tenant `<product>tenant_<uuid>`.

For the IziPOS production deployment (tenant #1 is the TN retail/parapharmacy vertical):

| Role | Name | Env var |
|---|---|---|
| Central directory + auth | `iziposcentral` | `DB_CENTRAL_DATABASE=iziposcentral` |
| Per-tenant | `izipostenant_<uuid>` (e.g. `izipostenant_019fcbe5-87ab-7020-9223-4d642e7f15c1`, 49 chars — well inside PG's 63-byte identifier limit) | derived from the tenancy prefix **for newly provisioned tenants only** — see the ruling below |
| ~~Default DB on the tenant connection~~ | ~~`izipostemplate`~~ — **REMOVED in v2** | `DB_DATABASE=iziposcentral` |

**v2: the `izipostemplate` database is removed** (Finding 10). v1 asserted it was needed and then also listed it as something to back up and restore. Neither was established. `config/tenancy.php:56 template_tenant_connection => null`, so nothing is cloned from a template; Stancl's template connection defaults to the **central** connection (`vendor/stancl/tenancy/src/DatabaseConfig.php:100-117`), while `DB_DATABASE` configures the separate **`pgsql`** connection (`config/database.php:87-103`) — and no call site in `apps/api/app` uses `connection('pgsql')`. Creating a database to satisfy an unused connection adds a restore artefact and a naming decision for nothing. **Ruling: set `DB_DATABASE=iziposcentral` and create no template DB.** If a future change introduces a real `pgsql` consumer, it gets its own decision.

🚨 **The tenant prefix is hardcoded and cannot be set by env today:**
```php
// apps/api/config/tenancy.php:58-63
'prefix' => 'tenant',
'suffix' => '',
```

**Required change (P0, one line, backward-compatible):**
```php
'prefix' => env('TENANCY_DB_PREFIX', 'tenant'),
```
Production then sets `TENANCY_DB_PREFIX=izipostenant_`; staging inherits the `tenant` default and is untouched.

#### 🚨 What the prefix change does NOT do (v2 correction — F-4 / Finding 2)

The prefix is a **fallback**, not the resolution path. Stancl reads the **stored** `db_name` first (`vendor/stancl/tenancy/src/DatabaseConfig.php:39-41,66-69`), and every tenant provisioned through the application's own path already has one persisted: `TenantProvisioningService.php:106-123` dispatches `CreateDatabase`, which calls `makeCredentials()` **before** `createDatabase()`, and `makeCredentials()` sets and saves `db_name` (`.../Jobs/CreateDatabase.php:30-43`, `.../DatabaseConfig.php:81-97`). Because `tenancy_db_name` is not a physical column on `Tenant` (`app/Modules/Tenant/Domain/Tenant.php:138-179`), it is serialised into the `tenants.data` JSONB column (`vendor/stancl/virtualcolumn/src/VirtualColumn.php:62-84`; column exists at `database/migrations/2025_11_30_000001_create_tenants_table.php:16-27`).

So:
- **Correctly provisioned existing tenants are unaffected** by the env var. Their stored name wins. Any expectation that setting the prefix "renames" or "redirects" them is false.
- **The only rows the env var changes behaviour for are rows *missing* `data.tenancy_db_name`** — i.e. legacy or corrupt rows. Those are precisely the rows where a silent behaviour change is most dangerous.
- The repository default derives `tenant<uuid>` with **no underscore**. If live databases carry an underscore, that underscore comes from a **stored** name, not from this config. ⚠️ Live rows are **UNVERIFIED** from this repository.

**Ruling — blocking pre-flight, before the code change ships (Phase 0.5a):**

| # | Step | Evidence |
|---|---|---|
| a | Audit every row in the central DB: `tenant id`, `data->>'tenancy_db_name'`, the name the current config *would* derive, and the matching `pg_database.datname` | A four-column table with one row per tenant, on **staging** and (later) on **production** |
| b | Flag every mismatch, every NULL stored name, and every stored name with no matching physical database (and every physical `tenant*` database with no owning row) | Explicit orphan list, both directions |
| c | **Backfill** any missing stored name to the **exact physical database name** — never to the derived name | Before/after `data->>'tenancy_db_name'` per row |
| d | Regression tests proving (i) stored-name precedence beats the env prefix, and (ii) the default-prefix fallback still yields `tenant<uuid>` when no name is stored | Test file + green run |
| e | Only then land the `env()` change and deploy it | Diff; staging tenant list byte-identical before and after |

Step (b)'s orphan list is not busywork — it is the same reconciliation the backup manifest performs every hour (§4.4), and running it once now is how we learn whether the central directory and the physical cluster currently agree at all.

The change must land on `dev`, ride the `dev`→`main` promotion (F-1), and be verified by provisioning a throwaway tenant on the production cluster before tenant #1.

**3-tier naming rule compliance (`claude/database-topology.md`):** that rule governs **table** placement inside the *platform* repo's three DBs. This design creates **no new tables and no new platform databases** — only ERP per-tenant databases, which are outside that rule's scope (the ERP side is db-per-tenant by `PostgreSQLDatabaseManager`). Nothing here invents a table or a DB against the 3-tier rule.

### 3.3 Service inventory

**Eight persistent services plus one one-shot job.** Staging declares eleven service entries — ten persistent plus the one-shot `createbuckets` (`docker-compose.dokploy.yml:51-170,195-267`); the two persistent deletions (Meilisearch, PgBouncer) are deliberate (§3.7), and the one-shot bucket-provisioning workload is retained (row 9). v1's flat "eight vs ten" comparison omitted the one-shot on both sides (Finding 3).

**All four API-family services and the web service run *registry images*, not git builds** (headline decision 13, §7.0). The `Branch / target` column below therefore names the **CI build target that produces the image**, not something the production host builds.

| # | Service | Type / image | Branch / target | Domain | CPU / Mem limit | Volume | Healthcheck | Depends on |
|---|---|---|---|---|---|---|---|---|
| 1 | **postgres** | image `timescale/timescaledb:2.13.0-pg16` | — | none (internal only) | 2 CPU / **4 G** | named `pgdata` → `/var/lib/postgresql/data` | `pg_isready -U <user>` | — |
| 2 | **redis** | image `redis:7-alpine`, `command: redis-server`, `args: ["--requirepass","${REDIS_PASSWORD}","--maxmemory","384mb","--maxmemory-policy","noeviction"]` | — | none | 1 CPU / **512 M** | named `redisdata` → `/data` | `redis-cli ping` | — |
| 3 | **minio** | 🚨 **v3 (round-2 F21/N5): `minio/minio@sha256:<pinned digest>` — NEVER `:latest`.** v2 said "production images must be pinned" in §5.3b and then specified `:latest` in this very table; the contradiction is resolved here, in favour of pinning. The exact digest is recorded in `RELEASE-SETS.md` alongside the application digests (§7.0.5). **`command: "minio"`, `args: ["server","/data","--console-address",":9001"]`** | — | none (internal) | 1 CPU / **1 G** | named `miniodata` → `/data` | `mc ready local` or TCP :9000 | — |
| 4 | **api** | GitHub `otospexsolutions/erp`, Dockerfile `apps/api/Dockerfile`, context `apps/api`, target **`api`** | **`main`** | `api.riserpos.app` | 4 CPU / **2 G** | shared `appstorage` → `/var/www/html/storage` | `curl -f http://localhost/health`, start-period 60 s | postgres, redis healthy |
| 5 | **worker** | same repo, target **`worker`**. 🚨 **v4: declares `stop_grace_period` GREATER THAN the longest job timeout (3,600 s today — `ProcessImportJob.php:50-65`)** — this is **D-21**, and it is what makes §4.6a's terminate-then-stop a *drain* rather than a mid-job SIGKILL | **`main`** | none | 4 CPU / **2 G** ⚠️ | shared `appstorage` → `/var/www/html/storage` | `php artisan horizon:status \| grep -q running` — ⚠️ **note this healthcheck is why `horizon:pause` is never held (R-31, §4.6a)** | api, redis healthy |
| 6 | **scheduler** | same repo, target **`scheduler`** | **`main`** | none | 1 CPU / **512 M** | shared `appstorage` → `/var/www/html/storage` | none (image defines none) — covered by the §6 dead-man switch | api healthy |
| 7 | **websocket** | same repo, target **`websocket`** | **`main`** | none (proxied) | 1 CPU / **512 M** | — | `nc -z localhost 8080` | redis healthy |
| 8 | **web** | Dockerfile `apps/web/Dockerfile`, **context `.` (repo root)** | **`main`** | `riserpos.app` | 1 CPU / **256 M** | — | `wget -q --spider http://localhost/health` | api healthy |
| 9 | **createbuckets** *(one-shot, NEW row in v2)* | image `minio/mc:<pinned digest>` — **never `:latest`** | — | none | 0.5 CPU / 128 M | — | n/a (runs to completion) | minio healthy |

Notes that will bite if ignored:

- **`appName` gets a random 5-char suffix** even when specified [A2]. That suffixed name is the swarm hostname other services use — re-read `application-one` after every create and record the real names before writing any env that references them (`DB_DIRECT_HOST`, `REVERB_SERVER_HOST`, `WS_URL`, `AWS_ENDPOINT`).
- **`application.create` requires `serverId`** even though the schema marks it optional; omitting it returns a misleading 401 [A2].
- **`application.saveEnvironment` MCP wrapper is broken** (400 — omits `buildSecrets` + `createEnvFile`). Use `application-update` with `env` [A2].
- **MinIO needs `command` + `args` split** — its ENTRYPOINT is `exec "$@"`, so one combined string fails. The Dokploy MCP wrapper does **not** expose `args`; fall back to direct tRPC `application.update` (`claude/deploy-runbook.md:18-32`).
- **Worker `2 G` is a *starting* cgroup with a mandatory load test, not a derived floor** *(rewritten in v2 per Finding 6)*. The v1 arithmetic (`10 × 128 + 64 ≈ 1.35 GB`, "1.5 G is the floor") is **withdrawn as a sizing argument**. It excludes supervisor processes, loaded extensions, allocator overhead, **replacement-worker overlap during recycle**, and jobs that transiently exceed the threshold before Horizon recycles them — and `apps/api/docker/php/php.ini:5-9` permits **256 MB per process**, so ten processes have a *permitted* ceiling of 2.5 GB plus master, above the proposed cgroup. What remains true and verified: `config/horizon.php:212,216,223` give `memory => 128` (a **self-restart threshold**, not a reservation), production `maxProcesses => 10`, master `memory_limit=64`; and staging's 512 M is plainly inconsistent with ten processes.
  **Ruling — launch gate V-9:** run all ten processes against the heaviest real jobs (largest import, image enrichment, fiscal projection) **including a forced recycle overlap**, measure peak RSS, and size from *measured peak + 50 % headroom*. If measured peak + headroom exceeds 2 GB, either raise the cgroup or lower `maxProcesses` to fit a proven 2 GB. **Record the measurement in the V-suite (§7.4) and in Appendix D.** No OOM/restart evidence ⇒ no launch sign-off.
- **WebSocket: exactly one replica.** `config/reverb.php` defaults `REVERB_SCALING_ENABLED=false` [A11] — a second replica would not share channel state. Do not set `replicas > 1` without first enabling Redis scaling.
- **`appstorage` is a shared named volume across api + worker + scheduler.** No staging compose mounts one (`docker-compose.staging.yml` has no `storage` volume), so `storage/app/...` is ephemeral there. In production, tenant-suffixed storage (`config/tenancy.php:138 suffix_storage_path => true`), local-disk writes (16 `Storage::disk('local')` call sites [A13]) and daily logs all live there and **must survive redeploys**.
  ⚠️ **UNVERIFIED and load-bearing (Finding 3):** whether three *separate Dokploy Applications* can mount the **same** external Docker volume with correct ownership and survive independent redeploys. Reusing the label `appstorage` in three Application definitions is **not** proof that Dokploy will not scope three different volumes. **Ruling: pre-create ONE external volume on the host, mount that exact external volume by name into all three Applications, and prove it in Phase 3.2a** — write a file from `api`, read it from `worker` and `scheduler`, redeploy each of the three independently, and re-read after each. If separate Applications cannot share one volume, the fallback is to deploy api/worker/scheduler as a single Dokploy **Compose** stack (which shares volumes by construction) and accept the coupled-deploy tradeoff. Decide on the evidence, not on the label.
- **Postgres gets explicit limits.** Staging runs with `memoryLimit: null, cpuLimit: null` [A8] — an unbounded Postgres competing with a pnpm/Vite build for the same RAM. Cap it.

### 3.4 Environment contract

**Shared (Dokploy project-environment level):** `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `MAIL_PASSWORD`, `SENTRY_LARAVEL_DSN`. (`MEILISEARCH_KEY` is **dropped** — §3.7.)

**API / worker / scheduler / websocket:**

| Var | Value | Why / evidence |
|---|---|---|
| `APP_ENV` | `production` | Also selects Horizon's `maxProcesses=10` (`horizon.php:215-221`) |
| `APP_DEBUG` | `false` | |
| `APP_KEY` | *(secret)* | `entrypoint.sh:18-23` — absent ⇒ hard `exit 1` |
| `APP_URL` | `https://api.riserpos.app` | |
| `FRONTEND_URL` | `https://riserpos.app` | |
| `DB_CONNECTION` | `central` | `config/tenancy.php:50` |
| `DB_CENTRAL_DATABASE` | `iziposcentral` | `config/database.php:133` |
| `DB_DATABASE` | `iziposcentral` | §3.2 — **v2: the separate `izipostemplate` DB is removed.** `DB_DATABASE` configures the unused `pgsql` connection (`config/database.php:87-103`); pointing it at an existing DB satisfies the config without creating a restore artefact |
| `TENANCY_DB_PER_TENANT` | `true` | `config/tenancy_resolver.php:30` |
| **`TENANCY_DB_PREFIX`** | **`izipostenant_`** | **Requires the §3.2 code change first** |
| `DB_HOST` | *(postgres appName)* | |
| **`DB_DIRECT_HOST`** | *(same postgres appName)* | `entrypoint.sh:100,123,141` — migrate/seed bypass any pooler and take advisory locks |
| **`DB_PGBOUNCER`** | **`false`** | `config/database.php:100,141`. Transaction pooling leaks the per-request tenant swap [A4.2] |
| `DB_USERNAME` / `DB_CENTRAL_USERNAME` | **`izipos_app` — `NOCREATEDB`, owns no database** 🚨 **v3 change** | §3.4c. `CREATE DATABASE` no longer runs over this connection |
| **`DB_PROVISIONING_USERNAME` / `DB_PROVISIONING_PASSWORD`** | **`izipos_provisioner` (`CREATEDB`)** — 🚨 **NEW in v3.** Set on the **api service only**; api is the only container that provisions (`TenantProvisioningService.php:117`, `dispatchSync`). **Not set on worker, scheduler or websocket** | §3.4c. Also a new E-1 row (§5.2) |
| `REDIS_HOST` / `REDIS_PASSWORD` / `REDIS_CLIENT` | appName / secret / `predis` | |
| `BROADCAST_CONNECTION` | `reverb` | `config/broadcasting.php` |
| `REVERB_APP_ID` | `autoerp` | staging uses `autoerp-staging` [A8] |
| `REVERB_SERVER_HOST` | *(websocket appName)* | **Bind/proxy config only** — `config/reverb.php:29-42` + `entrypoint.sh:242-246` rewrite the API nginx `/app/` upstream to `http://$REVERB_SERVER_HOST:8080`. **This variable does NOT route server-side broadcasts** |
| **`REVERB_HOST`** | *(websocket appName)* | 🚨 **NEW in v2 — see the Reverb contract below.** `config/broadcasting.php:31-43` |
| **`REVERB_PORT`** | **`8080`** | 🚨 **NEW.** Default is `443` — wrong for internal traffic |
| **`REVERB_SCHEME`** | **`http`** | 🚨 **NEW.** Default is `https`, which also sets `useTLS => true` |
| `SANCTUM_STATEFUL_DOMAINS` | **`""` (Bearer-only)** | The proven-safe configuration; both web and POS clients send Bearer. `ResolveTenancy` fixed the cookie path in code, but Bearer-only removes the failure mode entirely [A4.1] |
| `SESSION_SECURE_COOKIE` | `true` | |
| `CORS_ALLOWED_ORIGINS` | `https://riserpos.app` | |
| `TRUSTED_PROXIES` | `*` | behind Traefik |
| `HORIZON_PREFIX` | `autoerp-prod-horizon:` | keeps prod Horizon keys distinct in Redis |
| **`AUTO_SEED`** | **`false`** | `entrypoint.sh:170`. Staging's documented block says `true`. Seeders also cannot run in a `--no-dev` image (factories need faker) [A4.4] |
| **`SEED_DEMO_PHARMACY`** | **unset** | `entrypoint.sh:198` — would run `DemoPharmacySeeder` on *every* boot |
| **`SYNC_PERMISSIONS_ON_BOOT`** | **`false` by default; flipped to `true` only for a deploy that adds permissions** | `entrypoint.sh:153-161`. See the §7.5 ruling |
| `FILESYSTEM_DISK` | `local` | Media is hard-bound to `Storage::disk('s3')` regardless (`MediaUploadService.php:131,166`). Changing the *default* disk at launch would silently redirect 16 unrelated call sites. `appstorage` volume makes `local` durable |
| `AWS_*` | MinIO appName endpoint, `AWS_USE_PATH_STYLE_ENDPOINT=true`. 🚨 **`AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` are a bucket-scoped MinIO service account — NOT `MINIO_ROOT_USER`/`MINIO_ROOT_PASSWORD`** | `config/filesystems.php:50-60`. See the MinIO credential ruling below |
| `LOG_CHANNEL` / `LOG_STACK` / `LOG_LEVEL` / `LOG_DAILY_DAYS` | `stack` / **`daily`** / **`info`** / `14` | `.env.example:23-26` ships `LOG_STACK=single` + `LOG_LEVEL=debug` — one unbounded file at debug verbosity, on the box whose sibling filled its disk on 2026-08-03 |
| `MAIL_*` | per D-5 | `.env.example:120 MAIL_MAILER=log` silently swallows mail |
| `SENTRY_LARAVEL_DSN` | *(secret)* | |
| `TENANT_BACKUP_ROOT` / `TENANT_BACKUP_KEEP` | `/var/www/html/storage/app/tenant-backups` / `14` | `config/tenant_backups.php:18,30`; lands on the `appstorage` volume |

**Web (build args + runtime env):**

| Var | Kind | Value | Why |
|---|---|---|---|
| `VITE_API_URL` | **build ARG** | `https://api.riserpos.app` | baked into the bundle |
| **`VITE_REVERB_APP_KEY`** | **build ARG** | *(the real `REVERB_APP_KEY`)* | 🔧 **v2 (Finding 4): this is a build-CONFIGURATION gap, not a Dockerfile defect.** `apps/web/Dockerfile:47-55` already declares the ARG before `RUN pnpm build`; **no build path passes it**, so `apps/web/src/lib/echo.ts:22-31` falls back to `'local_key'` while the server enforces the generated key and realtime silently fails. **§7.0.4 rules the four-part fix** (pass it everywhere · **fail production builds when absent** · CI bundle inspection · V-6 canary). Phase 0.6 — **in scope now**, including the staging composes |
| `API_URL` | runtime env | `https://api.riserpos.app` | `apps/web/docker/entrypoint.sh:11-15` — **hard exit if unset** |
| `WS_URL` | runtime env | `http://<websocket-appName>:8080` | `apps/web/docker/entrypoint.sh:24-31,145-147` — defaults to `API_URL` in bundled mode; in split mode it must point at the websocket container |

#### 🚨 3.4a The Reverb runtime contract — two paths, both explicit (v2, Finding 5 — BLOCKER)

v1 specified `REVERB_SERVER_HOST` and stopped. That variable configures where the Reverb **server binds** and what the API nginx `/app/` proxy points at (`config/reverb.php:29-42`; `entrypoint.sh:242-246`). **Server-side publishing uses a completely different variable set** — `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` (`config/broadcasting.php:31-43`, verified this session):

```php
'options' => [
    'host'   => env('REVERB_HOST'),                        // null if unset
    'port'   => env('REVERB_PORT', 443),                   // 443 by default
    'scheme' => env('REVERB_SCHEME', 'https'),             // https by default
    'useTLS' => env('REVERB_SCHEME', 'https') === 'https', // → true by default
],
```

With those unset — which is the state of **both** checked-in Dokploy composes (`docker-compose.dokploy.yml:42-46` defines only the app trio and `REVERB_SERVER_HOST/PORT`) — the API and Horizon try to publish to a **null host on port 443 over TLS**. Every server-originated broadcast fails or goes nowhere. The secret-rotation document already lists these as connection targets (`docs/security/secret-rotation-2026-05-12.md:69-73`); nothing consumes them.

**Path 1 — internal publisher (API container, Horizon worker, scheduler):**

| Var | Value | Note |
|---|---|---|
| `REVERB_HOST` | *(the websocket service's real `appName`)* | The swarm hostname. Re-read `application-one` after create — the 5-char suffix is not predictable [A2] |
| `REVERB_PORT` | `8080` | Container port, not the public port |
| `REVERB_SCHEME` | `http` | Internal overlay traffic. Also forces `useTLS => false` |
| `REVERB_APP_ID` / `REVERB_APP_KEY` / `REVERB_APP_SECRET` | *(the production trio)* | All three are required by `config/reverb.php:70-89`; a missing secret authenticates nothing |

**Path 2 — browser subscriber:**

| Setting | Value | Note |
|---|---|---|
| Endpoint | **same-origin `https://riserpos.app/app/`** | Proxied by the web container's generated nginx (`apps/web/docker/entrypoint.sh:145`), so no cross-origin, no second TLS cert, no separate public port for Reverb |
| Auth endpoint | **same-origin `/broadcasting/auth`** | `apps/web/docker/entrypoint.sh:160` |
| App key | **`VITE_REVERB_APP_KEY`, baked at build time**, byte-identical to the server's `REVERB_APP_KEY` | §3.4 / §7.0.4. `apps/web/src/lib/echo.ts:22-31` otherwise falls back to `local_key` and the handshake is rejected |
| `WS_URL` (web runtime env) | `http://<websocket-appName>:8080` | `apps/web/docker/entrypoint.sh:24-31,145-147` |

**Deployment test (V-6, mandatory — a successful handshake alone is NOT sufficient evidence):**

| # | Step | Pass criteria |
|---|---|---|
| 1 | Open the production web app in a browser; confirm the websocket connects to `wss://riserpos.app/app/…` | connection established, **and** the subscription is *authorised* (not silently rejected) |
| 2 | **Publish an event from the API container** (`php artisan tinker` → `broadcast(...)` on a real channel) | the browser receives it |
| 3 | **Publish the same event from a Horizon worker** (dispatch a job that broadcasts) | the browser receives it |
| 4 | Confirm the received payload's app key matches the server's | no `local_key` anywhere in the built bundle |

Step 3 is the one that catches the failure v1 would have shipped: the worker is a *different container* with its own entrypoint (`Dockerfile:150-168`), and if the publisher variables are set only on the API service, the API path passes and every queued broadcast — which is most of them — silently dies.

#### 🚨 3.4b MinIO credentials: the application does not get root (v2, Finding 24 — BLOCKER)

In the checked-in deployment the **same** `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` are handed to the application (`docker-compose.dokploy.yml:36-41`) **and** used as `MINIO_ROOT_USER`/`MINIO_ROOT_PASSWORD` (`:105-113`). The S3 disk consumes them for ordinary media reads and writes (`config/filesystems.php:50-60`; `MediaUploadService.php:131`). Compromise of *any* API, worker, or scheduler environment therefore yields **MinIO administrative credentials** — the ability to delete every bucket, rewrite policies, disable object lock, and destroy any backup that lands there. That is not a media credential; it is the storage tier's root password sitting in project-wide container env.

**Ruling — three distinct identities:**

| Identity | Held where | Grants |
|---|---|---|
| **MinIO root** (`MINIO_ROOT_USER`/`_PASSWORD`) | **1Password only.** Injected into the *minio service* env at deploy; **never** into api/worker/scheduler/websocket | everything |
| **Application key** (`AWS_ACCESS_KEY_ID`/`_SECRET`) | project-environment env, as today | `s3:GetObject`, `PutObject`, `DeleteObject`, `ListBucket` on **the media bucket only**. **No** admin API, no user/policy management, no other bucket, no object-lock configuration |
| **Backup key** | host-side rclone config, `chmod 600` | write to the backup prefix only (§4.5's write-without-delete ruling) |

Because secrets are entered at the **project-environment** level (§5.3), a root credential placed there is inherited by every service in the project. **The MinIO root credential must therefore be a service-level override on the minio Application, not a project-level variable** — this is the one deliberate exception to the "no per-service duplicates" rule in §5.3, and it exists because project-wide inheritance is exactly the blast-radius problem.

**Negative tests (Phase 2.6a, launch evidence):** from outside the host, ports **9000 and 9001 are closed**; MinIO has **no Dokploy domain attached**; and using the application key, `mc admin info`, `mc admin user list`, `mc mb <other-bucket>`, and any object-lock configuration call all **fail with access denied**.

#### 🚨 3.4c Database role separation — the runtime role loses `CREATEDB` and `DROP` (v3, round-2 F26 — was PARTIALLY CLOSED)

One role with `CREATEDB`, shared by api + worker + scheduler + websocket **and** owning every database it created, is a cluster-wide destructive credential. v2 split the *backup* and *admin* identities out but left the request-serving role holding `CREATEDB` and database ownership, and the re-gate correctly refused to call that closed: **a compromised API can still create and drop databases.**

**v2's justification was that Stancl provisions over the request-serving connection. That is only half true, and the other half is the fix.** Verified this session:

```php
// vendor/stancl/tenancy/src/DatabaseConfig.php:149-165
public function manager(): TenantDatabaseManager
{
    $driver = config("database.connections.{$this->getTemplateConnectionName()}.driver");
    ...
    $databaseManager->setConnection($this->getTemplateConnectionName());
    return $databaseManager;
}
```

`CREATE DATABASE` / `DROP DATABASE` are issued by the **manager**, over `getTemplateConnectionName()` (`PostgreSQLDatabaseManager.php:32-39`) — **not** over whatever connection is serving the request. `getTemplateConnectionName()` resolves `tenant.db_connection ?? tenancy.database.template_tenant_connection ?? tenancy.database.central_connection` (`DatabaseConfig.php:100-104`), and today the first two are unset, so it falls through to `central` — which is why the two collapsed into one credential. **They do not have to.**

**Ruling — four identities, and the request-serving role holds none of the destructive rights.**

| Identity | Used by | Grants (exact) |
|---|---|---|
| **`izipos_app`** | api, worker, scheduler, websocket — the `central` connection and every tenant connection derived from it | `LOGIN`; **`NOCREATEDB NOCREATEROLE NOSUPERUSER NOREPLICATION NOBYPASSRLS`**; `CONNECT` + `TEMPORARY` on `iziposcentral` and each tenant DB; `USAGE, CREATE ON SCHEMA public` in each (so migrations still work and its tables are owned by it). 🚨 **Owns no database, so it cannot `DROP DATABASE` — `DROP` requires ownership or superuser** |
| **`izipos_provisioner`** *(NEW in v3)* | **the `provisioning` connection only**, reached solely through Stancl's database manager during tenant create/delete | `LOGIN`; **`CREATEDB`**; `NOCREATEROLE NOSUPERUSER NOREPLICATION`. Owns the tenant databases it creates. **No `pg_read_all_data`; no membership in `izipos_app`** — it is a DDL identity, not a data identity |
| **`izipos_backup`** | the host backup cron **only** | `LOGIN`; `pg_read_all_data` + `CONNECT` on all DBs; **`NOCREATEDB NOCREATEROLE NOSUPERUSER`**. **Cannot write, cannot drop.** Password lives in `/etc/erp-backup.env` (chmod 600), **not in any container env** |
| **`izipos_admin`** | interactive restore/DDL, break-glass | full rights; password in 1Password **only**, never in any env anywhere |

**The three changes that make this work — all Phase 0, all small:**

1. **A `provisioning` connection in `config/database.php`**, cloned from `central` but reading `DB_PROVISIONING_USERNAME` / `DB_PROVISIONING_PASSWORD` (the `central` block already demonstrates the override pattern at `config/database.php:124-142`).
2. **Bind the manager to it without changing the tenant runtime connection.** 🚨 Setting `tenancy.database.template_tenant_connection = 'provisioning'` is the obvious move and it is **WRONG** — `DatabaseConfig::connection()` uses the *same* name as the template for each tenant's **runtime** connection config, so every tenant request would then authenticate as `izipos_provisioner`. Instead, register a two-line subclass in `tenancy.database.managers['pgsql']` that pins the manager's connection:

   ```php
   final class ProvisioningScopedPostgreSQLDatabaseManager extends PostgreSQLDatabaseManager
   {
       // Stancl calls setConnection(getTemplateConnectionName()); ignore it and
       // always issue CREATE/DROP DATABASE over the elevated provisioning role.
       public function setConnection(string $connection): void { parent::setConnection('provisioning'); }
   }
   ```

   `makeConnectionConfig()` does not read `$this->connection` (`PostgreSQLDatabaseManager.php:46-51`), so the tenant's runtime credentials are unaffected. The repo already customises this `managers` map (`config/tenancy.php:78-84`), so this is a supported extension point, not a hack.
3. **Grant the app role its rights inside each new database**, in a listener on Stancl's `DatabaseCreated` event. 🚨 **v4 (round-3 R3-N5) CORRECTS THIS BLOCK — v3's version was not executable.** v3 wrote the grant as one SQL block containing `\connect "<db>"`. **`\connect` is a psql *client meta-command*, not SQL** ([PostgreSQL psql documentation](https://www.postgresql.org/docs/18/app-psql.html)); a Laravel `DB::statement()` cannot send it, and PostgreSQL has no in-session database switch. The two grants live in **two different databases** and therefore require **two connections**:

   | # | Statement | Which connection | Which database |
   |---|---|---|---|
   | 1 | `GRANT CONNECT, TEMPORARY ON DATABASE "<db>" TO izipos_app;` | the standing **`provisioning`** connection | `iziposcentral` — a database-level grant is a cluster catalog write and does **not** need a session in the target DB |
   | 2 | `GRANT USAGE, CREATE ON SCHEMA public TO izipos_app;` | a **temporary, purpose-built connection**: the `provisioning` credentials with `database` overridden to `<db>` | the newly created tenant DB — a schema-level grant is **per-database** and can only be issued from inside it |

   The listener therefore does this, and purges the throwaway connection afterwards so it never leaks into the request's connection pool:

   ```php
   // Listener on Stancl\Tenancy\Events\DatabaseCreated — runs over `provisioning`, never `central`.
   public function handle(DatabaseCreated $event): void
   {
       $db = $event->tenant->database()->getName();

       // (1) database-level grant, from the standing provisioning connection
       $this->db->connection('provisioning')
           ->statement(sprintf('GRANT CONNECT, TEMPORARY ON DATABASE %s TO %s',
               $this->quoteIdentifier($db), $this->quoteIdentifier($this->appRole)));

       // (2) schema-level grant, from INSIDE the new database
       $name = 'provisioning_tenant_grant';
       $this->config->set("database.connections.$name",
           array_merge($this->config->get('database.connections.provisioning'), ['database' => $db]));
       try {
           $conn = $this->db->connection($name);
           $conn->statement(sprintf('GRANT USAGE, CREATE ON SCHEMA public TO %s',
               $this->quoteIdentifier($this->appRole)));
       } finally {
           $this->db->purge($name);                       // never leave it in the pool
           $this->config->set("database.connections.$name", null);
       }
   }
   ```

   Three properties this shape has that the v3 block did not, all of them testable:

   - **Identifier quoting is explicit.** The database name is tenant-derived (`TENANCY_DB_PREFIX` + UUID) and the role name is configured; both go through a double-quote-doubling `quoteIdentifier()`, never string interpolation. There is no bind-parameter form for a `GRANT` target, so quoting is the only defence and it must be unit-tested with a hostile name.
   - **The temporary connection is purged in a `finally`.** A leaked `provisioning_tenant_grant` entry would mean a later query could run as `izipos_provisioner` against a tenant DB — the exact privilege bleed §3.4c exists to prevent.
   - **The listener is idempotent.** Both grants are safe to re-issue, so a retried provisioning job cannot half-apply.

   🚨 Step (2) is **mandatory on PostgreSQL 15+**, where the `public` schema no longer grants `CREATE` to `PUBLIC`. Without it, `tenants:migrate-rolling` fails on the new tenant — loudly, which is the right failure. **Regression tests (Phase 0.8f), and the first two are new in v4:** (a) assert `current_user` = `izipos_provisioner` **and** `current_database()` = the new tenant DB inside the listener's second connection; (b) assert that after provisioning, the tenant's **runtime** connection reports `current_user` = `izipos_app`; (c) provision a throwaway tenant end-to-end and assert its migrations run (Phase 7.2 already does this; v3 made the grant part of what it proves).

**Consequences to accept, deliberately:**

- **Restores use `--no-owner --no-acl`** (already the case, §4.6b), so restored tables land owned by the restoring identity (`izipos_admin`) and the grant block above must be re-applied after a cluster restore. 🚨 **Added to §4.6c step 8 post-conditions.**
- **`izipos_backup` reads tables owned by `izipos_app`** — `pg_read_all_data` covers this regardless of owner.
- **Tenant deletion still works** through the app's own path, because `deleteDatabase()` also goes through the manager → the provisioning connection.

**Residual, stated plainly and NOT waved away:** the `provisioning` credential must be present in the **API container's** environment, because `TenantProvisioningService.php:117` calls `Bus::dispatchSync(new CreateDatabase($tenant))` — provisioning is **synchronous inside the signup request** (it has to be: the very next lines `tenancy()->initialize($tenant)` and write the first user). So a full container compromise — one that can read env — still reaches `CREATEDB` and can drop the databases `izipos_provisioner` owns.

What the split does buy, precisely: **every SQL-level flaw that is not an env read** — ORM injection, a raw-query bug, a leaked read-only DSN, a logged connection string for the runtime role, a compromised worker or scheduler or websocket container (**none of which need the provisioning credential at all, and none of which get it**) — loses the ability to create or drop databases. That is three of the four application containers fully contained and one partially. **It is a real reduction, and it is not total.** The WORM backup leg (§4.5a), which no application identity can reach, remains the actual containment boundary.

🎫 **Follow-up ticket, not a launch blocker:** move provisioning behind a queued job consumed only by a dedicated worker holding the credential, so the API container never needs it. That requires making signup asynchronous (poll/redirect instead of provision-in-request), which is a product change, not a config change — which is why v3 ships the role split now and tickets the rest rather than claiming both.

> Verified this session: the web image does **not** use `apps/web/nginx.conf.template` (which lacks `/app/` and `/broadcasting/`). `apps/web/Dockerfile:66` copies `apps/web/docker/nginx.conf`, and `apps/web/docker/entrypoint.sh:38` generates the real server block, which **does** contain `location /app/` (`:145`) and `location /broadcasting/` (`:160`). `nginx.conf.template` is dead and misleading — cosmetic cleanup ticket, not a production risk.

### 3.5 Volumes

**v2 rewrites this table (Finding 13 — BLOCKER).** v1 gave every volume a nightly panel-managed backup and then let the headline "RPO ≤ 1 hour" appear to cover them. It did not: media was on a **24-hour** RPO, and DR-3 ("restore per DR-2") restored **only Postgres**, so total host loss would have come back with an intact database full of references to media that no longer existed. **Every volume now carries its own explicit RPO and RTO.**

| Volume | Mounted into | Contents | Backup mechanism | **RPO** | **RTO contribution** |
|---|---|---|---|---|---|
| `pgdata` | postgres | the cluster | Logical dumps (§4) — **not** volume snapshots. A file-level copy of a running PGDATA is not a backup | **≤ 1 h**, conditional (§4.1) | dominant; serial `pg_restore` |
| `miniodata` | minio | product media | 🚨 **v3: TWO offsite legs with different properties (§4.5a1).** (a) **L4a `rclone copy --immutable`** into a versioned Object Storage prefix — append-only, deletions never propagate, aged by lifecycle policy; (b) **L4a″ `restic backup`** of the MinIO data dir — the deletable mirror. Plus **L4a′**, a per-object hash inventory in the cycle manifest. The nightly Dokploy volume backup is retained as a **tertiary, whole-volume** copy | **≤ 1 h** (hourly, conditional on `media.check_result == ok` and the cycle reaching `complete`), degrading to ≤ 24 h if both hourly legs fail and only the nightly volume backup survives | download + `rclone copy` back, verified object-by-object against `media-inventory.json`; small at launch |
| `appstorage` | api + worker + scheduler | tenant storage, `tenant-backups/`, daily logs | Dokploy volume backup, nightly + **log shipping** (below) | **≤ 24 h for rebuildable content; ≤ 5 min for logs** via shipping | minutes — it is mostly recreated, not restored |
| `redisdata` | redis | queue + cache | Dokploy volume backup, weekly. Low value: everything in it is reconstructible except in-flight jobs | **not an RPO commitment** — in-flight jobs are explicitly accepted as lost | zero; recreated empty |

**Media is small at launch, which is exactly why the hourly leg is cheap.** [R3] estimates ~1–5 GB for one retail catalogue (🔬 HYPOTHESIS — Appendix D). An hourly `rclone copy` of a few GB with `--checksum` transfers only new objects. The cost of *not* doing it is the failure mode above: a restored database whose media references are all 404.

**`appstorage` is documented as rebuildable-except-logs.** Its contents split three ways:

| Content | Recovery | Why |
|---|---|---|
| `storage/framework/{cache,sessions,views}` | **recreated empty** | derived state |
| Tenant-suffixed `storage/app/...` local-disk writes (16 `Storage::disk('local')` call sites [A13]) | **nightly volume backup** | generated artefacts (exports, temp import files) — regenerable by re-running the operation |
| In-app `tenant-backups/` (`config/tenant_backups.php:18,30`, 14 per tenant) | **nightly volume backup**; and it is a *convenience* copy — §4's dumps are authoritative | losing it loses nothing §4 does not already hold |
| **`storage/logs/` daily logs** | 🚨 **log shipping covers the 24-hour gap** — Laravel logs go to the `stack` channel *and* are forwarded off-box (Sentry breadcrumbs for errors; O-5). **Losing up to 24 h of on-box logs after a host loss is accepted**, because the incident-relevant records (errors, fiscal events, audit chain) are in Sentry and in the database, not only in the file | the one genuinely lossy part, and it is bounded and named |

⚠️ **Dokploy volume backups cover Docker *named* volumes only — bind mounts are not covered** [C2]. Every volume above must be a named volume. Historical bug #2686 ("volume backups delete other volume backups") means retention on this path needs verification after the first two runs — and is a further reason the **hourly rclone leg, not the panel, is the primary media protection**.

### 3.6 Ingress and TLS

- **Traefik** per-server (each remote server runs its own) [C3]. Let's Encrypt HTTP-01, `certificateType: letsencrypt`.
- Domains must resolve **before** first deploy or the ACME challenge fails.
- **Application** domain changes hot-reload; **Compose** domain changes need a redeploy [C5]. All eight services are Application-type, so this is not a constraint here.
- Add a **CAA record** for `letsencrypt.org` (Appendix A) — cheap, and it stops any other CA issuing for the apex.

### 3.7 Deliberate divergences from staging

| Divergence | Rationale | Risk accepted |
|---|---|---|
| **No Meilisearch** | `laravel/scout` is in neither `composer.json` nor `composer.lock`; no `config/scout.php`, no `Searchable` trait, no `Laravel\Scout` import, no `meilisearch` reference anywhere in `apps/api/{app,src,config,routes,database}` [A12]. Staging deploys it, healthchecks it, sets `SCOUT_DRIVER=meilisearch`, and makes the API `depends_on: meilisearch: service_healthy` — an unused container that can block API startup | If Scout is later installed, adding the container is a 10-minute task. Zero functional loss today. `MEILISEARCH_KEY` also drops out of the E-1 rotation inventory |
| **No PgBouncer** | Transaction pooling leaks the per-request tenant DB swap across pooled backends under concurrency; no session pinning exists [A4.2]. Staging deploys it and bypasses it, with its env still pointing at a **dead** postgres appName | None — it is bypassed on staging anyway. Connection count at one tenant is nowhere near a pooler's use case |
| **Postgres not externally exposed** | Staging exposes 5434 because there is no local SSH key for that box. Production has SSH | Slightly slower ad-hoc DB access; correct security posture. Also removes the "external port silently disappears on redeploy churn" failure mode [A2] |
| **`appstorage` volume mounted** | Staging has none; `storage/` is ephemeral there | None — strictly better |
| **Auto-deploy OFF** | §7 | Deploys become a deliberate act |
| **Registry images, not git builds** *(v2)* | Headline decision 13 / §7.0. Staging keeps its git-build workflow; production never builds on the host | Slightly slower hotfix loop (must go through CI). That is the intended tradeoff |

### 3.8 🚨 Source-of-truth ruling — no silent coexistence (v2, Finding 3)

The review is right that a manual panel configuration must not coexist with a repository file advertising a materially incompatible production deployment. `docker-compose.dokploy.yml` currently declares `DB_CONNECTION=pgsql`, `DB_DATABASE=autoerp`, `AUTO_SEED=true`, Meilisearch (with `depends_on: service_healthy` on the API), PgBouncer, a 512 M worker, no `appstorage`, no `DB_CENTRAL_DATABASE`/`DB_DIRECT_HOST`/`TENANCY_DB_PER_TENANT`/`DB_PGBOUNCER`, and no Reverb publisher variables — and its header tells operators to *"Deploy this file directly in Dokploy"* (`docker-compose.dokploy.yml:1-5,14-49`).

**Ruling:**

| Artefact | Status | Action |
|---|---|---|
| `docker-compose.dokploy.yml` | **STAGING-SHAPED. NOT PRODUCTION.** | 🎫 **Code follow-up ticket** (outside this document's write scope): replace the header with an explicit non-production banner naming this document and the production manifest, and stating that deploying it to production would enable `AUTO_SEED`, a pre-tenancy-flip DB config, and an unused Meilisearch dependency that can block API startup. **Do not delete it** — staging uses it |
| **`deploy/production/compose.production.yml`** *(NEW, checked in, secret-free)* | 🚨 **PRODUCTION SOURCE OF TRUTH for TOPOLOGY**, together with the §3.3 service table. 🚨 **v4 (D-20): the source of truth for WHICH IMAGES is the attested `release-set.lock.json` (§7.0.2b), not this file** — the two are deliberately different artefacts with different lifecycles | Created in Phase 0.8c (§7.0.3). **Image references are `${IMAGE_*}` variables with no defaults** (an unset variable must fail the deploy, never silently resolve to a tag), no build sections, every secret referenced as `${VAR}` with **no defaults and no values**, `env_file` pointing at a path that is created only at bootstrap time and never committed |
| `docker-compose.staging.yml` | staging | unchanged |
| `docs/operations/STAGING-SETUP.md`, `apps/api/.env.production.example`, `DEPLOYMENT.md` | **stale, pre-tenancy-flip** | R-17/R-18 — do not copy into production; ticketed separately |

The production manifest and the §3.3 table must agree. **They are verified against each other in Phase 0.10 and again at every V-suite run**; a divergence is a deploy-blocking defect, not a documentation nit.

---

## 4. Backup and disaster recovery

### 4.1 Objectives

**v2 restates every objective conditionally (Findings 7, 8, 13, 18).** An RPO or RTO figure without its conditions is a marketing number. Each row below states what must hold for the figure to be true, and what the figure degrades to when it does not.

#### RPO

| Scope | Committed | **Conditions — the figure is only true if ALL hold** | Degrades to |
|---|---|---|---|
| **Databases** | **Target RPO 1 h, conditional on cycle success** | (1) the most recent hourly cycle reached **`result: "complete"`** — which, per §4.4b1, is *only* written after (2) **both** offsite legs uploaded **and independently verified** (`rclone check` and `restic check` both exit 0); (3) the failure is detected and acted on before the *next* cycle would have run | the age of the last **`complete`** cycle — which is what O-3 measures (§6). Formally **void** from the timestamp of the first unanswered critical alert (§4.4d item 14), not from the point somebody noticed |
| **Cross-database consistency** | 🚨 **NONE. There is no cluster-consistent recovery point at launch** | — | **Cross-DB skew is possible in the provisioning window.** A tenant created between the central dump and its own dump, or vice versa, produces a restored set where the central directory and the physical cluster disagree. §4.4's **manifest + reconciliation protocol** *detects* this at restore time; it does not prevent it |
| **Point-in-time recovery** | 🚨 **NOT AVAILABLE AT LAUNCH. No WAL archiving, no PITR.** | — | The only recovery points that exist are the hourly cycles. A disaster 59 minutes after a cycle loses 59 minutes. **D-10 sets a dated decision on pgBackRest** |
| **Media (`miniodata`)** | **≤ 1 h** | the hourly **append-only** L4a leg completed **and** `media.check_result == "ok"` **and** the cycle reached `complete` (§4.4b1, §4.5a1) | ≤ 24 h (nightly volume backup only). 🚨 **Note the asymmetry v3 makes explicit: a media *deletion* has no RPO at all on the WORM leg — it never propagates (D-18). The restic mirror (L4a″) is the leg that reflects deletions** |
| **`appstorage`** | **≤ 24 h**, rebuildable-except-logs (§3.5) | nightly volume backup succeeded | log loss up to 24 h, accepted and bounded (§3.5) |
| **`redisdata`** | **no commitment** — in-flight jobs are accepted as lost | — | — |

Stated as one sentence, the way it should be told to the owner: **"We can lose up to an hour of everything, we have no way to recover to a moment in between hours, and if a backup fails silently and nobody answers the alert, the number is however long that goes unnoticed."**

#### RTO

| Scope | **Committed (v2)** | Target | Status of the number |
|---|---|---|---|
| **Single tenant** | **≤ 1 h** | 1 h | 🔬 HYPOTHESIS until the §4.8 rehearsal times it. Plausible: one `-Fc` archive into a recreated DB |
| **Postgres volume loss** | **≤ 4 h** *(was 2 h)* | 2 h | 🔬 HYPOTHESIS. v1's 2 h assumed a 272 MB cluster (itself ⚠️ UNVERIFIED) and did not budget the tenant-maintenance drain (§4.6a), serial restore of N databases, or verification |
| **Total host loss** | 🚨 **≤ 8 h** *(was 4 h — narrowed per D-14)* | 4 h | 🔬 HYPOTHESIS. **The measured p95 from the Phase 7.9 full-host rehearsal replaces this number.** See the honesty note below |
| **Hetzner-wide / account loss** | **NOT MET at launch** | — | Both backup legs are Hetzner. §10 R-4; **D-13** sets a dated decision |

**Why total-host RTO is committed at 8 h and not 4 h.** v1's 4 h was arithmetic over a lifeboat that could not be launched: no registry images existed, no deployment model consumed images, the fallback required the same Dokploy panel whose outage has no replacement path, and MinIO/appstorage were never restored at all (Findings 16, 17, 13). v2 fixes the *mechanism* — digest-pinned GHCR images plus a panel-independent checked-in manifest (§7.0) — but fixing the mechanism does not measure it. The unbudgeted terms are real and unknown:

| Term | Status |
|---|---|
| Cloud instance provisioning (~60 s) | ⚠️ UNVERIFIED provider claim |
| Manual Docker bootstrap on a bare host (panel unused) | 🔬 HYPOTHESIS — measured in the Phase 7.9 rehearsal |
| Image pull from GHCR to Hetzner | 🔬 HYPOTHESIS — depends on image size and GHCR egress (D-9) |
| **Offsite-to-cloud backup download** | ⚠️ **UNVERIFIED bandwidth.** The review's arithmetic is the right shape: at the design's own 40 GB projection, transfer alone is ~8.9 h at 10 Mbit/s, ~53 min at 100 Mbit/s, ~5.3 min at an ideal 1 Gbit/s — *before* restore |
| Serial `pg_restore` throughput (no `-j`, §4.6) | ⚠️ UNVERIFIED |
| MinIO media restore | 🔬 HYPOTHESIS — scales with catalogue size |
| Verification (V-suite + fiscal verifiers) + DNS + operator time | 🔬 HYPOTHESIS |

**8 h is the honest commitment given that every one of those is unmeasured.** It is not a target to relax toward — Phase 7.9 measures the real number, and D-14 requires the owner to accept whatever it is. If the rehearsal comes back at 3 h, the commitment moves to 4 h with evidence behind it.

**When hourly stops being free:** a full dump cycle exceeding **5 minutes wall-clock** is a monitored early-warning signal logged every run (§4.4). ⚠️ **It is no longer the *trigger* for WAL archiving** — v1 made a real capability contingent on a threshold that might never fire while the exposure grew anyway. **D-10 makes it a dated decision: pgBackRest GO/NO-GO by `launch + 30 days` or tenant #3, whichever is first** (§9.1, and it is listed as a Phase 9 hardening item in §4.3 L7).

### 4.2 Why not Dokploy's backup features

| Dokploy feature | Verdict | Reason |
|---|---|---|
| Native Postgres backup | **Not primary. Optional convenience copy of `iziposcentral` only** | `pg_dump -Fc … "$DB_NAME"` — **single-database**. N tenants = N hand-created schedules, no enumeration. **No `pg_dumpall --globals-only`**, so roles and passwords are *never* backed up. Restore requires the target DB to already exist (`pg_restore -d`, no `--create`) and **does not call `timescaledb_pre_restore()`** [C1] |
| Schedule Jobs (cron) | **Not for backups** | They execute from the panel's `node-schedule` and SSH out [C4]. A dead or paused Dokploy Cloud panel is a **silent backup outage on production**. Host crontab has no such dependency |
| Volume backups | 🔧 **SECONDARY only (v2, Finding 32).** Yes for `appstorage` and `redisdata`; for MinIO it is now the **third** copy, behind the hourly host-driven **L4a** append-only leg and the **L4a″** restic mirror (v3, §4.5a1) | Named volumes only; use "turn off container during backup" for MinIO [C2]. **A panel outage stops this path silently**, which is why media moved to the host cron and why **O-13 externally monitors volume-backup age** |
| Notifications on backup events | **Yes, mandatory** | [C5]. But note they too are panel-driven, which is why §6 adds an off-provider dead-man switch |

### 4.3 Backup layers

| Layer | What | Frequency | Destination | Retention |
|---|---|---|---|---|
| **L0** | 🚨 **Cycle manifest** (NEW in v2) — captured **first**: central dump, then the tenant list read **from that dump**, plus per-DB source metadata (§4.4a) | hourly | local → both offsite legs | with its cycle |
| **L1** | `pg_dumpall --globals-only` (roles, passwords, tablespaces) | hourly | local `/var/backups/pg/` → both offsite legs | with its cycle |
| **L2** | `pg_dump -Fc --no-owner --no-acl` per database (central + every tenant) — **no template DB, removed in v2 (§3.2)** | hourly | idem | §4.5 |
| **L3** | sha256 manifest of every artifact in the cycle | hourly | idem | with its cycle |
| **L4a** | 🚨 **MinIO media offsite replication — APPEND-ONLY** (v2 Finding 13; **rewritten in v3 for round-2 F13/N3**). `rclone copy --immutable` into a **versioned** Object Storage prefix. **`rclone sync` is FORBIDDEN on this leg** — see §4.5a1 | **hourly** | Object Storage (media prefix, own lifecycle + versioning) | append-only; ageing is done by the bucket lifecycle policy, never by the host |
| **L4a′** | 🚨 **NEW in v3 — media object inventory.** A per-object `key / size / hash / mtime` listing (`rclone lsjson --hash`) plus a verification pass (`rclone check`), written into the cycle manifest. **This is what DR-3 step 9 verifies against** | hourly, with L4a | inside the cycle directory → both offsite legs | with its cycle |
| **L4a″** | 🚨 **NEW in v3 — the deletable media mirror lives on the restic leg.** `restic backup` of the MinIO data directory, so deletions and replacements *do* propagate somewhere and the mirror can be pruned | hourly, with the cycle | Storage Box (restic) | §4.5 tiers, via `restic forget` |
| **L4b** | MinIO `miniodata` **whole-volume** copy | nightly, container stopped | Dokploy volume backup → Object Storage | 14 nightly |
| **L5** | `appstorage` + `redisdata` volumes | nightly | Dokploy volume backup → Object Storage | 7 nightly |
| **L6** | **Dokploy env-encryption keyring** | on change + monthly | 1Password (D-4) | forever |
| **L7** | **pgBackRest WAL → Storage Box SFTP — Phase 9 hardening item with a decision DATE (D-10)**, no longer "deferred until a threshold fires" | continuous, if adopted | Storage Box | per pgBackRest retention |

🚨 **L6 is easy to forget and fatal to forget.** Dokploy ≥ v0.29.12 encrypts env vars at rest with AES-256-GCM, keyring exported in the backup encryption key file [C5]. Lose the keyring and every stored production secret becomes unreadable ciphertext — including in your Dokploy backups.

### 4.4 Backup cycle — manifest, ordering, and reconciliation

Runs from a **systemd timer + service** on the production box (v2 change: not a bare crontab line — see the hardening list). Panel-independent by construction.

```
/usr/local/sbin/erp-backup.sh              # the script (explicit #!/bin/bash, explicit PATH)
/etc/systemd/system/erp-backup.service     # Type=oneshot, RuntimeMaxSec, EnvironmentFile
/etc/systemd/system/erp-backup.timer       # OnCalendar=hourly, RandomizedDelaySec, Persistent=true
/etc/erp-backup.env                        # PGUSER, PGPASSWORD (izipos_backup), RESTIC_* — chmod 600
/mnt/backups/pg/                           # local staging dir — SEPARATE MOUNT, not on /  (§3.1)
/root/.config/rclone/rclone.conf           # Object Storage creds, chmod 600
/root/.restic-password                     # restic repo key, chmod 600 (RESTIC_PASSWORD_FILE)
```

#### 4.4a 🚨 The ordering that makes the cycle reconcilable (v2, Finding 7 — BLOCKER)

v1 dumped globals, then read the database list from `pg_database`, then dumped each database independently. Each dump is internally consistent; **the set is not**, and v1 called the resulting directory a recovery point. The concrete skew cases the review names are all real and all reachable, because the application commits the central tenant row **before** creating and migrating the physical database (`TenantProvisioningService.php:106-123`):

| Case | What you get back |
|---|---|
| Database list captured before a new tenant DB exists, central dumped after the tenant row commits | a restored central row pointing at a **database that is not in the backup set** |
| Tenant DB dumped, central dump predates its tenant row | an **orphan database** with no owning row |
| Central subscription/auth change and a tenant-local fiscal write straddle their respective dumps | two halves of one logical change, hours apart in effect |

v1's mitigation was *"the next cycle heals it"* — which is exactly wrong for a disaster, because after a disaster there **is** no next cycle; the already-selected set is what you have.

**Ruling — capture order is normative, not incidental:**

| # | Step | Why this order |
|---|---|---|
| **1** | **Dump `iziposcentral` FIRST** | the central dump becomes the *authority* on what the tenant set was at capture time |
| **2** | **Read the tenant list FROM THAT DUMP**, not from `pg_database` — restore the central dump into a scratch DB (or extract with `pg_restore --data-only -t tenants`) and read `tenants.data->>'tenancy_db_name'` (with the §3.2 derived-name fallback) | the list and the central snapshot are then, by construction, the *same* instant. Reading `pg_database` instead reintroduces the skew |
| **3** | Dump `pg_dumpall --globals-only` | roles must exist before any restore |
| **4** | Dump each database from the step-2 list, **and** record any `pg_database` entry that the list does **not** contain | captures orphans *as facts in the manifest* rather than losing them silently |
| **5** | Write `MANIFEST.json` (below) and `SHA256SUMS` | the manifest is what the restore reads |

#### 4.4b `MANIFEST.json` — what every cycle records

```jsonc
{
  "cycle_ts": "20260805T070000Z",
  "capture_order": ["central", "globals", "tenants"],
  "central": { "db": "iziposcentral", "file": "iziposcentral.dump",
               "started_at": "...", "finished_at": "...", "sha256": "..." },
  "tenant_list_source": "central_dump",           // NEVER "pg_database"
  "tenants": [
    { "tenant_id": "019fcbe5-…", "stored_db_name": "izipostenant_019fcbe5-…",
      "present_in_pg_database": true, "file": "izipostenant_….dump", "sha256": "…",
      "started_at": "…", "finished_at": "…" }
  ],
  "orphan_databases": [],        // in pg_database, not in the central dump  → restore-time WARNING
  "missing_databases": [],       // in the central dump, not in pg_database → restore-time WARNING
  "source_metadata": {           // per database — Finding 11
    "iziposcentral": { "pg_version": "16.x",
                       "extensions": [{"name":"timescaledb","version":"2.13.0"}],
                       "hypertables": [] }
  },
  "media": {                     // v3 §4.5a1 — aggregates are NOT sufficient evidence
    "leg": "L4a", "objects": 0, "bytes": 0,
    "inventory_file": "media-inventory.json", "inventory_sha256": "…",
    "check_mode": "checksum",    // "checksum" | "download"
    "check_result": "ok",        // "ok" | "differences"  → non-ok blocks "complete"
    "restic_snapshot_id": "…",   // L4a″, the deletable mirror
    "finished_at": "…"
  },
  "duration_seconds": 0,

  // ---- v3 two-phase completion (round-2 F7/N2) ----
  "result": "staged",            // phase 1 value. NEVER "complete" at first write
  "offsite": {
    "object_storage": { "state": "pending", "verified_at": null, "rclone_check_rc": null },
    "restic":         { "state": "pending", "verified_at": null,
                        // v4 §4.4b2 — the PAIR, not one id. `data_snapshot` is written at
                        // promote (it exists by then); `complete_snapshot` is filled by the
                        // ATTESTATION, never by the manifest — a snapshot cannot name itself.
                        "data_snapshot": null,
                        "restic_check_rc": null,
                        "restic_inventory_check": "snapshot-scoped",   // restic ls --json $DATA_SNAP
                        "restic_check_mode": "read-data-subset",       // repository SAMPLE, not scoped
                        "restic_check_subset": null }                  // e.g. "7/12" — the rotation actually run
  }
}
```

#### 🚨 4.4b0 The cycle integrity chain — three layers, and they must not be circular (v4)

`MANIFEST.json` is **rewritten** at the promote and `ATTESTATION.json` does not exist until after it. A single flat `SHA256SUMS` over `./*` therefore **cannot** cover them: it would be invalidated by the very promote that makes the cycle restorable, and `sha256sum -c SHA256SUMS` — the fail-closed integrity gate at §4.6b step 0b and DR-3 step 7 — would fail on **every good cycle**. The scopes are fixed, and each layer is verified by the one outside it:

| Layer | File | Covers | Written | Verified by |
|---|---|---|---|---|
| 1 | `SHA256SUMS` | **the data artefacts ONLY** — every dump, `globals.sql`, `source_metadata.jsonl`, `media-inventory.json`, `media-check.txt`. 🚨 **Explicitly EXCLUDES `MANIFEST.json` and `ATTESTATION.json`** | phase 1, **never rewritten** | `sha256sum -c` at restore |
| 2 | `MANIFEST.json` | its own `central.sha256` / `tenants[].sha256` / `media.inventory_sha256` per artefact, **plus `sha256sum(SHA256SUMS)`** | phase 1 (`staged`), rewritten at promote (`complete`) | its hash is recorded in layer 3 |
| 3 | `ATTESTATION.json` | `manifest_sha256` + the restic snapshot pair (§4.4b2 B5) | promote only | the leg itself: the object version on leg A, the snapshot tree on leg B |

**Restore verifies outside-in and fails closed at each step:** read `ATTESTATION.json` → `sha256sum MANIFEST.json` equals its `manifest_sha256` → `result == "complete"` and the leg's `state == "verified"` → `sha256sum -c SHA256SUMS` → **and cross-check the two layers**: every `central.file` / `tenants[].file` appears in `SHA256SUMS` with a **byte-identical** hash to the manifest's. That last cross-check is what a tampered-manifest-only attack fails, and it costs one `jq`/`join`.

#### 🚨 4.4b1 `staged` → `complete` is a TWO-PHASE write (v3, round-2 F7/N2 — BLOCKER)

v2's prose said `result=complete` was written after both uploads; v2's **script outline called `write_manifest` at step 6, before either upload**. The re-gate is right that this is not a nit: if Object Storage succeeds and restic fails, the Object Storage copy ends up holding a manifest that reads **machine-verifiably `complete`** while the documented RPO condition ("both offsite uploads succeeded") is false. Restore-candidate selection and the dead-man semantics both break, in the direction of false confidence.

**Ruling — completion is a property of the *offsite* state, not of the local directory, and it is written twice:**

| Phase | What is written | Where | The cycle is a restore candidate? |
|---|---|---|---|
| **1 — STAGE** | `MANIFEST.json` with `"result": "staged"` and both `offsite.*.state = "pending"`; `SHA256SUMS` over **the data artefacts only** — 🚨 **v4: NOT over `MANIFEST.json`, which phase 3 rewrites, and not over `ATTESTATION.json`, which does not exist yet (§4.4b0)** | local `$STAGE` only | ❌ **NO** |
| **2 — UPLOAD** | the staged cycle is copied to **both** legs. Each leg is then **independently verified**: `rclone check --checksum --one-way` (leg A) and, on leg B, a **snapshot-scoped inventory check** (`restic ls --json "$SNAP"` against the cycle's expected file list) **plus** a repository-level `restic check --read-data-subset` — 🚨 **v4 (round-3 finding 4.2): the `--read-data-subset` run is NOT snapshot-scoped and must not be described as if it were.** **Exit codes are captured, not assumed** | both legs | ❌ still NO |
| **3 — PROMOTE** | *only if both verifications returned 0*: the manifest is rewritten with `"result": "complete"`, both `offsite.*.state = "verified"`, timestamps and exit codes filled — then **re-published to both legs**. On leg A that is an overwrite of one key. 🚨 **On leg B it is NOT an overwrite — restic snapshots are immutable — so v4 uses the paired-object protocol of §4.4b2, not v3's `restic backup MANIFEST.json`** | local + both legs | ✅ **YES** |

Consequences that must not be softened:

1. **A `complete` manifest can only exist if it was written after both legs verified.** There is no code path that produces `complete` earlier — `write_manifest` takes the state as an argument and the caller never passes `complete` before step 3.
2. **On the WORM leg, the promote is an overwrite of an existing key** — which the host credential *can* do (`PutObject` is permitted; only deletes are not), and which bucket versioning preserves. The staged version remains as a **noncurrent version**, which is exactly the audit trail you want: it records that the cycle was attempted. 🚨 The **`--immutable` flag is NOT used for the manifest promote** (it is used only for the media leg, §4.5a1) — a promoted manifest is a legitimate change to an existing key.
3. **A restore reads `result` from the leg it is restoring FROM**, and refuses on anything but `complete` (§4.4c row 1). It must additionally assert `offsite.<that leg>.state == "verified"` — a cycle can be `complete` overall while the leg in your hand is the one that was fine; that is the point.
4. **The dead-man (O-3) pings only after step 3.** Silence therefore means "no cycle reached verified-on-both-legs", which is the honest signal.
5. **Monitoring keys on the AGE OF THE LAST `complete` MANIFEST**, not on the age of the last cycle directory and not on the last cron exit code. A run that staged and failed to upload is *not* a fresh backup, and O-3's 90-minute window must be measured from the last promotion. See §6 O-3.

**If the promote itself fails** (e.g. the second re-publish errors after the first succeeded): the cycle stays `staged` on at least one leg, the dead-man does not ping, and the alert fires. That is a **false negative in the safe direction** — the data is on both legs and verified; only the marker is inconsistent — and the operator's remedy is to re-run the promote, which is idempotent. This is deliberately preferred over any scheme where the marker can lead the data.

#### 🚨 4.4b2 The restic leg needs a PAIRED-OBJECT protocol — snapshots are immutable (v4, round-3 R3-N2 — BLOCKER)

**The defect round 3 found, stated precisely.** v3's phase 3 ran `restic backup "$STAGE/MANIFEST.json"`. Every `restic backup` invocation creates a **new snapshot** ([restic backup manual](https://restic.readthedocs.io/en/stable/manual_rest.html)); restic never mutates an existing one. So after v3's phase 3 the repository contained:

- `$SNAP` — the full cycle, whose embedded `MANIFEST.json` says `"result": "staged"`; and
- a second, **manifest-only** snapshot that says `"result": "complete"` but contains **no dumps**.

Restoring the first yields all the data with a `staged` marker (§4.4c row 1 **refuses** it). Restoring the second yields a `complete` marker and nothing to restore. **There is no snapshot on leg B that is a valid restore candidate** — which is the same false-completion class F7 was created to eliminate, moved from "before the uploads" to "split across two immutable snapshots". It also made the *local* complete manifest name a snapshot ID that predates its own completion, so the marker could not name the object it belonged to without a cycle.

**Ruling — leg B's promote is a SECOND FULL SNAPSHOT plus a separate completion attestation, and the pairing is by TAG, never by self-reference.**

| # | Action | Command | Why |
|---|---|---|---|
| **B1** | **Data snapshot** (phase 2, unchanged in substance) | `restic backup --tag "cycle=$TS" --tag "role=data" "$STAGE"` → `$DATA_SNAP` | the immutable full cycle, manifest still `staged`. **Never rewritten** |
| **B2** | **Verify what B1 actually wrote** — snapshot-scoped | `restic ls --json "$DATA_SNAP"` compared against the cycle's expected file list — 🚨 **v4: that list is the `SHA256SUMS` names PLUS `MANIFEST.json`**, because `SHA256SUMS` deliberately does not cover the marker (§4.4b0); a comparison built from `SHA256SUMS` alone would accept a data snapshot with no manifest in it — plus a repository `restic check --read-data-subset=<n/t>` | 🚨 round-3 finding 4.2: `--read-data-subset=5%` checks **repository structure and a random 5 % of pack files**; it does **not** read every blob reachable from `$DATA_SNAP`. The `ls` comparison is what proves the snapshot contains the cycle; the subset read is a **repository health sample**, and the manifest records it as exactly that |
| **B3** | **Rewrite the manifest to `complete`** locally, filling in `$DATA_SNAP`, both verification exit codes, and the leg states | `write_manifest "$STAGE" complete …` | the complete marker now **names** the data snapshot it belongs to. This is only possible because the data snapshot already exists — which is why the promote is a second write, not a rewrite |
| **B4** | **Re-snapshot the WHOLE staged directory**, tagged as the complete generation | `restic backup --tag "cycle=$TS" --tag "role=complete" "$STAGE"` → `$COMPLETE_SNAP` | **dedup makes this near-free** — every file except `MANIFEST.json` is byte-identical to B1, so restic stores one changed blob plus a new tree. The result is a **self-contained snapshot holding the cycle data AND its `complete` marker**, which is precisely what leg B lacked |
| **B4a** | 🚨 **NEW in v5 (round-4 BLOCKER 2, fix a) — INVENTORY-VERIFY `$COMPLETE_SNAP`, because IT is the restore candidate, not B1's data snapshot** | `restic ls --json "$COMPLETE_SNAP"` → the canonical sorted file list; `INVENTORY_SHA256=sha256sum(that list)`; **assert it lists exactly** the `SHA256SUMS` names **plus `MANIFEST.json`** (the promote-time complete marker; `ATTESTATION.json` is NOT yet written, so it is correctly absent) | v4 inventory-verified only B1 (`role=data`), but **B4 is the snapshot a restore actually reads** and its data generation could differ from B1's if a file changed between snapshots. **This runs BEFORE B5 writes the attestation and BEFORE the dead-man ping**, and `INVENTORY_SHA256` is what B5 records — so a complete/attestation pair can never exist unless the *complete* snapshot was itself inventory-verified |
| **B5** | **Write the completion attestation** as its own small snapshot | `restic backup --tag "cycle=$TS" --tag "role=attestation" "$STAGE/ATTESTATION.json"` | `ATTESTATION.json` = `{cycle, data_snapshot, complete_snapshot, manifest_sha256, inventory_sha256, os_check_rc, restic_check_rc, promoted_at}`. 🚨 **v5: `inventory_sha256` is B4a's hash of the `$COMPLETE_SNAP` inventory** (the restore candidate), not the data snapshot's. It is the **externally-addressable index** O-3b and the restore path read first (a snapshot cannot contain its own ID; naming is always one direction) |

**Selection rules that make this unambiguous — these are the restore contract:**

1. **Restore selects by TAG, never by "latest".** `restic snapshots --tag "cycle=$TS" --tag "role=complete" --json` returns the restore candidate. `restic restore latest` is **FORBIDDEN in every runbook** — with three roles per cycle, "latest" is normally the attestation snapshot, i.e. one JSON file.
2. **The pairing is verified before any data is written.** Read the attestation, then assert the named `complete` snapshot exists, carries `role=complete` and the same `cycle` tag, that the `MANIFEST.json` inside it hashes to `manifest_sha256`, **and 🚨 v5 (round-4 BLOCKER 2, fix a) — that a fresh `restic ls --json "$COMPLETE_SNAP"` inventory hashes to the attestation's `inventory_sha256`** (the same binding B4a wrote, re-checked at read time). Any mismatch ⇒ **REFUSE**, fall back to the previous cycle, and alert. A `complete` snapshot with no attestation, an attestation naming a snapshot that is absent, or an inventory that no longer matches `inventory_sha256`, is a **failed/tampered promote**, not a restore candidate.
3. **`role=data` snapshots are never restored from directly** except as a deliberate, recorded operator override for forensic purposes — they always carry a `staged` marker by construction.
4. 🚨 **`restic forget` applies retention at the CYCLE level and forgets all THREE snapshots of an expired cycle as one unit (v5, round-4 BLOCKER 2, fix b).** `--group-by tags` is **WRONG here and is struck**: every cycle carries a **unique** `cycle=$TS` tag, so grouping on tags puts each cycle (and each role within it) in its **own singleton group** — restic then applies `--keep-hourly/daily/weekly/monthly` *within* each group, i.e. keeps everything, and the intended **cross-cycle** ageing never runs. The correct algorithm: (i) enumerate the distinct `cycle=<TS>` tags with their promotion timestamps; (ii) apply the hourly/daily/weekly/monthly policy **over the set of cycles** to compute which cycles to EXPIRE; (iii) for each expired cycle, `restic forget` the **three** snapshot IDs (`role=data`, `role=complete`, `role=attestation`) **as one unit**, then a single `restic prune`. 🚨 **v5 (fix c) — the post-prune assertion (`assert_cycle_retention_invariant`) requires EXACTLY ONE valid `data`, `complete`, AND `attestation` snapshot per retained cycle, all mutually bound** (the attestation names both, the manifest hash and the `inventory_sha256` match) — v4's assertion omitted `attestation`, which restore requires. A pruning policy that split a triple would silently manufacture the exact defect this protocol removes — the assertion is part of the script, not a convention.
5. **The local `$TS` directory keeps `ATTESTATION.json` alongside `MANIFEST.json`.** 🚨 **v4 correction: neither is in `SHA256SUMS`, and neither can be** — `SHA256SUMS` is written in phase 1 and the promote rewrites one of them and creates the other. They are covered by the **outside-in chain of §4.4b0** (attestation → `manifest_sha256` → manifest → per-file hashes → `SHA256SUMS`), which is what the restore walks. Leg A stores both as ordinary objects (it has no snapshot semantics and needs none — a key overwrite is sufficient there); leg B binds them through the `role=complete` / `role=attestation` snapshot pair.

**Cost, since "take a second full snapshot" sounds expensive and is not.** B4 re-reads the staged directory but stores only the changed manifest blob and new metadata; the dominant cost is the re-read, bounded by H-7's 5-minute cycle budget and measured as **H-22**. If that measurement shows B4 pushing the cycle over budget, the fallback — recorded now so it is not invented under pressure — is to stage `MANIFEST.json` in its own subdirectory and snapshot only that subdirectory at B4, accepting that the complete snapshot is then an attestation-with-inventory rather than a self-contained restore candidate, and marking the leg's evidence strength down accordingly in the manifest. **The fallback is a downgrade and must be recorded as a deviation, not adopted silently.**

#### 4.4c Restore-time reconciliation protocol (both directions)

Before any cluster restore proceeds, the restore script reads `MANIFEST.json` and:

| Check | On failure |
|---|---|
| 🚨 **v4, and it runs FIRST (§4.4b0):** `ATTESTATION.json` exists for the cycle and `sha256sum(MANIFEST.json)` equals its `manifest_sha256` | **REFUSE.** An absent attestation means the promote never completed; a hash mismatch means the marker was altered after promotion. Neither is a cycle you restore from — fall back to the previous complete cycle and alert |
| `result == "complete"` **AND** `offsite.<the leg being restored from>.state == "verified"` (v3, §4.4b1) | **REFUSE** — pick the previous complete cycle and say so loudly. A `staged` manifest means the cycle never proved durable success on both legs and is **not** a restore candidate |
| 🚨 **v4:** every `central.file` / `tenants[].file` appears in `SHA256SUMS` with a hash **byte-identical** to the manifest's | **REFUSE.** The two layers disagreeing means one of them was rewritten independently of the data — which is the only shape a tampered-manifest attack can take once the chain above holds |
| 🚨 **restic leg only (v4, §4.4b2; extended v5):** `ATTESTATION.json` for the cycle exists; the `complete_snapshot` it names **exists**, carries `role=complete` + the same `cycle` tag, its embedded `MANIFEST.json` hashes to `manifest_sha256`, **AND a fresh `restic ls --json` of that complete snapshot hashes to the attestation's `inventory_sha256`** (v5, round-4 BLOCKER 2 fix a) | **REFUSE and fall back to the previous cycle.** A `complete` snapshot with no attestation — or an attestation naming an absent snapshot, or one whose inventory no longer matches `inventory_sha256` — is a **failed/tampered promote**, not a restore candidate. **Never restore `role=data`** except as a recorded forensic override; it carries a `staged` marker by construction |
| `media.check_result == "ok"` and `media.inventory_sha256` matches `media-inventory.json` (v3, §4.5a1) | **WARN + operator decision** if media is out of scope for this restore; **REFUSE** for DR-3, where media is in scope |
| every `tenants[].file` exists and its sha256 matches | **REFUSE** for that database; continue only with explicit operator override, recorded |
| `orphan_databases` empty | **WARN + require operator decision**: a database with no owning central row will be restored as an unreferenced database or dropped. Never silently either |
| `missing_databases` empty | **WARN + require operator decision**: a central row pointing at a database that was not captured. The tenant will resolve to a non-existent DB and 500 on first request. Options: restore the row's tenant as *suspended*, or restore from an older cycle that has it |
| after restore: every central `tenants.data->>'tenancy_db_name'` has a matching `pg_database.datname`, **and** every restored `izipostenant_*` database has an owning central row | **FAIL the restore verification** — this is the same audit as §3.2 step (b), run as a post-condition |

**Rehearsal requirement (§4.8 step 12):** create, suspend, and delete a tenant **during** a backup cycle and prove the restore *detects* the resulting skew rather than swallowing it.

#### 4.4d Script outline

Outline, not final code — written and reviewed in §8 Phase 4. The hardening list below is normative for the final script.

```bash
#!/bin/bash
set -euo pipefail
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
# credentials come from the systemd EnvironmentFile: PGUSER, PGPASSWORD, RESTIC_PASSWORD_FILE
exec 9>/var/lock/erp-backup.lock; flock -n 9 || { echo "overlap"; exit 3; }   # exit CODE, not 0

TS=$(date -u +%Y%m%dT%H%M%SZ)
STAGE=/mnt/backups/pg/$TS.partial          # atomic: .partial → rename on success
mkdir -p "$STAGE"
PG=$(docker ps -q -f name=<postgres-appName>)   # random 5-char suffix — resolve, never hardcode

preflight_free_space                       # refuse to start if < 2× last cycle's size is free

# 1. CENTRAL FIRST — it is the authority on the tenant set
docker exec "$PG" pg_dump -U "$PGUSER" -Fc -Z6 --no-owner --no-acl iziposcentral > "$STAGE/iziposcentral.dump"

# 2. TENANT LIST **FROM THAT DUMP**, not from pg_database
TENANTS=$(extract_tenant_list_from_dump "$STAGE/iziposcentral.dump")

# 3. globals
docker exec "$PG" pg_dumpall -U "$PGUSER" --globals-only > "$STAGE/globals.sql"

# 4. per-tenant dumps + per-DB source metadata (pg version, extensions+extversion, hypertables)
for DB in $TENANTS; do
    record_source_metadata "$DB" >> "$STAGE/source_metadata.jsonl"
    docker exec "$PG" pg_dump -U "$PGUSER" -Fc -Z6 --no-owner --no-acl "$DB" > "$STAGE/$DB.dump"
done
record_orphans_and_missing "$TENANTS"      # both directions → MANIFEST.json

# 🚨 v4 (round-3 finding 2.3): under `set -e`, `cmd; RC=$?` NEVER RUNS on failure — the shell
# exits at `cmd`. Every expected-to-fail verification MUST be wrapped, or the captured exit
# codes, the direct alert and the diagnostic manifest state are all dead code.
run_rc() { if "$@"; then echo 0; else echo $?; fi; }     # the ONLY way this script captures a code

# 5. media — APPEND-ONLY offsite leg (L4a) + inventory/verification (L4a′)  [v3 §4.5a1]
rclone copy   "minio:$MEDIA_BUCKET" "hos:erp-prod-media" --immutable --checksum
rclone lsjson --hash --recursive "minio:$MEDIA_BUCKET" > "$STAGE/media-inventory.json"
MEDIA_RC=$(run_rc rclone check "minio:$MEDIA_BUCKET" "hos:erp-prod-media" \
                  --checksum --one-way --combined "$STAGE/media-check.txt")
# L4a″ — the DELETABLE mirror lives on the restic leg
MEDIA_SNAP=$(restic backup --json --tag "cycle=$TS" --tag "role=media-mirror" \
             /var/lib/docker/volumes/<miniodata>/_data | jq -r 'select(.message_type=="summary").snapshot_id')

# 6. PHASE 1 — STAGE. integrity + manifest with "result":"staged"        [v3 §4.4b1]
# 🚨 v4 (§4.4b0): DATA ARTEFACTS ONLY. `sha256sum ./*` would include MANIFEST.json — which the
# promote REWRITES — so every promoted cycle would then fail `sha256sum -c` at restore time, i.e.
# the fail-closed integrity gate would reject exactly the cycles that are good.
( cd "$STAGE" && sha256sum ./*.dump globals.sql source_metadata.jsonl \
                            media-inventory.json media-check.txt > SHA256SUMS )
write_manifest "$STAGE" staged "$MEDIA_RC" "$MEDIA_SNAP"
[ "$MEDIA_RC" = "0" ] || { alert "media check FAILED — cycle stays staged"; exit 4; }

# 7. PHASE 2 — UPLOAD leg 1: Object Storage, WORM bucket, DIFFERENT DC, write-without-delete
rclone copy "$STAGE" "hos:erp-prod-backups/$TS" --checksum
OS_RC=$(run_rc rclone check "$STAGE" "hos:erp-prod-backups/$TS" --checksum --one-way)

# 8. PHASE 2 — UPLOAD leg 2: Storage Box, restic. B1/B2 of §4.4b2.
DATA_SNAP=$(restic backup --json --tag "cycle=$TS" --tag "role=data" "$STAGE" \
            | jq -r 'select(.message_type=="summary").snapshot_id')
# B2a — SNAPSHOT-SCOPED: does this snapshot actually contain the cycle?  (finding 4.2)
#        Expected list = the SHA256SUMS names + MANIFEST.json (§4.4b0/§4.4b2 B2).
LS_RC=$(run_rc verify_snapshot_inventory "$DATA_SNAP" "$STAGE/SHA256SUMS")   # restic ls --json | compare
# B2b — repository health SAMPLE. NOT snapshot-scoped, and the manifest records it as a sample.
#        Deterministic rotating subset so every pack is covered over time.
SUBSET="$(( 10#$(date -u +%H) % 12 + 1 ))/12"
CHK_RC=$(run_rc restic check --read-data-subset="$SUBSET")
RESTIC_RC=$(( LS_RC | CHK_RC ))

# 9. PHASE 3 — PROMOTE. "complete" is written ONLY here, only if BOTH legs verified.
[ "$OS_RC" = "0" ] && [ "$RESTIC_RC" = "0" ] || {
    alert "offsite verification FAILED (os=$OS_RC restic=$RESTIC_RC) — cycle stays STAGED"; exit 5; }
# B3 — rewrite the marker locally, NAMING the data snapshot it belongs to
write_manifest "$STAGE" complete "$MEDIA_RC" "$MEDIA_SNAP" "$OS_RC" "$RESTIC_RC" "$DATA_SNAP" "$SUBSET"
# leg A: a manifest overwrite is a legitimate change to one key (versioning keeps the staged one)
rclone copy "$STAGE/MANIFEST.json" "hos:erp-prod-backups/$TS/" --checksum
# leg B: 🚨 restic snapshots are IMMUTABLE — re-snapshot the WHOLE directory (dedup makes it cheap),
#        then write the attestation that BINDS the pair. §4.4b2 B4/B5. NEVER `restic backup MANIFEST.json`.
COMPLETE_SNAP=$(restic backup --json --tag "cycle=$TS" --tag "role=complete" "$STAGE" \
                | jq -r 'select(.message_type=="summary").snapshot_id')
# B4a — 🚨 v5: inventory-verify the COMPLETE snapshot (the restore candidate), BEFORE the attestation
#        and BEFORE the ping. Expected list = SHA256SUMS names + MANIFEST.json (ATTESTATION.json not yet written).
INVENTORY_SHA256=$(inventory_hash "$COMPLETE_SNAP" "$STAGE/SHA256SUMS")   # restic ls --json | canonical-sort | sha256
[ -n "$INVENTORY_SHA256" ] || { alert "COMPLETE snapshot inventory verify FAILED — cycle stays STAGED"; exit 6; }
MANIFEST_SHA256=$(sha256sum "$STAGE/MANIFEST.json" | cut -d' ' -f1)       # the complete marker, hashed
write_attestation "$STAGE/ATTESTATION.json" "$TS" "$DATA_SNAP" "$COMPLETE_SNAP" \
                  "$MANIFEST_SHA256" "$INVENTORY_SHA256" "$OS_RC" "$RESTIC_RC"
rclone copy "$STAGE/ATTESTATION.json" "hos:erp-prod-backups/$TS/" --checksum
restic backup --tag "cycle=$TS" --tag "role=attestation" "$STAGE/ATTESTATION.json"
# 9b. 🚨 v5 (round-4 BLOCKER 2, fix b/c): CYCLE-LEVEL retention. `--group-by tags` is WRONG — every
#     cycle has a UNIQUE cycle=$TS tag, so it self-groups and cross-cycle ageing never runs.
#     Apply the policy over CYCLES, then forget all THREE snapshots of each expired cycle as a unit.
for CYC in $(cycles_to_expire --keep-hourly 48 --keep-daily 14 --keep-weekly 8 --keep-monthly 12); do
  for ROLE in data complete attestation; do
    SID=$(restic snapshots --tag "cycle=$CYC" --tag "role=$ROLE" --json | jq -r '.[0].id // empty')
    [ -n "$SID" ] && restic forget "$SID"
  done
done
restic prune
assert_cycle_retention_invariant   # EXACTLY one data + complete + attestation per RETAINED cycle, all mutually bound

# 10. commit the cycle atomically, then rotate by CAPACITY (not only by age)
mv "$STAGE" "/mnt/backups/pg/$TS"
cleanup_incomplete_cycles                  # any *.partial older than 2 cycles
prune_local_to_capacity_ceiling            # keep local staging < 20% of the mount; oldest first

# 11. dead-man: ping ONLY after the PROMOTE (§6 O-3). Silence = no verified cycle.
curl -fsS -m 10 "https://hc-ping.com/<uuid>" >/dev/null
```

Non-negotiable properties (items 6–14 are **new in v2**, per Findings 15 and 28):

1. **`set -euo pipefail` + ping-on-success-only.** A backup system that alerts on failure cannot alert when it never ran. Absence of a ping is the signal.
2. **`flock`**, and an overlap exits with a **distinct non-zero code** (v1 exited `0`, which made an overlapping — i.e. slow — cycle look like a healthy skip).
3. **The tenant list comes from the central dump** (§4.4a). Tenant #2…N are still picked up with zero configuration; the enumeration just happens against the authoritative snapshot. Orphan `pg_database` entries are recorded, not ignored.
4. **Resolve the container by `name=` filter**, never a hardcoded appName [A2].
5. **Log the wall-clock duration** to `/var/log/erp-backup.log` — the §4.1 early-warning number.
6. **Explicit interpreter and `PATH`.** A cron/systemd environment is not a login shell.
7. **Explicit credentials**: `PGUSER`/`PGPASSWORD` (the `izipos_backup` role, §3.4c) and `RESTIC_PASSWORD_FILE` come from a `chmod 600` `EnvironmentFile`. v1 used `set -u` and never assigned `PGUSER`, and listed a restic password file it never passed.
8. **`systemd` timer + `RuntimeMaxSec`**, not bare cron: bounded runtime, journald capture, `Persistent=true` so a missed cycle after a reboot runs, and **stale/hung-run detection** rather than a silent lock hold.
9. **Free-space preflight** — refuse to start (loudly, exit non-zero) if free space is under 2× the last cycle's size. A half-written dump is worse than no dump.
10. **Atomic `.partial` → rename.** Only a renamed directory with `"result":"complete"` is a restore candidate. Incomplete cycles are cleaned every run.
11. **Capacity-based local retention** (Finding 15): keep local staging **under 20 % of its mount**, pruning **oldest first**, with a hard floor of "always keep the 2 most recent complete cycles". Alert at 15 %. v1's flat "48 hourly sets locally" does not survive its own growth table — at the 200-tenant / 20–40 GB projection, 48 uncompressed sets is 1.9 TB against ~512 GB of disk. (Compression makes the real figure smaller and workload-dependent; **the compression ratio is itself 🔬 HYPOTHESIS — Appendix D** — which is precisely why the ceiling is capacity-based rather than count-based.)
12. **`find` guards**: `-mindepth 1` on every destructive `find`. v1's `find /var/backups/pg -maxdepth 1 -type d -mmin +2880 -exec rm -rf {} +` matches the staging root itself.
13. **Direct failure notification in addition to dead-man silence** — the script pushes a failure alert (§6) on any non-zero exit *and* the missing success ping catches the case where it could not. Two mechanisms because the interesting failures kill the first one.
14. 🚨 **Escalation, with the interval DEFINED (v3, round-2 F28).** v2 said "after a defined interval" and never defined it, so bounded human response was never actually committed. **The ladder, binding:**

    | Elapsed since the alert fired, with no acknowledgement | Action |
    |---|---|
    | **0 min** | Alert on **channel 1 (Telegram, D-6)** to the release owner |
    | **15 min** | Automatic re-fire on **channel 2 (email, D-6)** — a second, independent transport, because the realistic failure is a broken channel, not an inattentive human |
    | **30 min** | **Escalate to the second custodian (D-12)** on both channels, plus the custodian's phone number recorded in the break-glass package |
    | **60 min** | The incident is **logged as an unacknowledged critical** in the Phase-7 evidence set, and the next cycle's failure is treated as a **sustained backup outage** — i.e. the RPO commitment in §4.1 is formally void from the timestamp of the *first* unanswered alert, not from the point somebody noticed |

    Acknowledgement is an explicit action (a healthchecks.io/UptimeRobot ack, or a reply in the Telegram thread that the escalation job watches — **whichever D-6's tooling actually supports is confirmed in Phase 5.7, and if neither supports acknowledgement natively, the fallback is a manual ack recorded in the on-call log and the 30-minute escalation fires unconditionally**). ⚠️ Whether the chosen channels support programmatic acknowledgement is **UNVERIFIED** and is a Phase 5.7 acceptance test, not an assumption. A dead-man that only one unavailable human receives is a dead-man switch with a dead man on the other end.
15. 🚨 **Two-phase completion (v3, §4.4b1).** `write_manifest` takes the completion state as an argument; **no call site passes `complete` before both offsite legs have returned exit 0 from their own verification**. The promote is a separate, idempotent re-publish of the marker to both legs — an object overwrite on leg A, and the §4.4b2 paired-object protocol on leg B.
16. 🚨 **NEW in v4 (round-3 finding 2.3) — `set -e` and captured exit codes are mutually exclusive unless you wrap.** `set -euo pipefail` is item 1 and stays. But `rclone check …; OS_RC=$?` **never assigns** when the check fails: the shell exits at the failing command, so the alert, the `os=…` diagnostic and the staged-state bookkeeping the design advertises are all unreachable. It fails safe (nothing gets promoted) and it fails **silently**, which is the half that matters. **Every expected-to-fail verification goes through `run_rc`** (or an equivalent `if`/`errexit`-suspending form), and **each failure branch has its own test in the Phase 4 script review** — a branch that has never executed is not a branch.
17. 🚨 **NEW in v4, corrected in v5 — the restic promote is `role=complete` + `role=attestation`, never `restic backup MANIFEST.json`** (§4.4b2). 🚨 **v5 (round-4 BLOCKER 2, fix b/c): retention is applied at the CYCLE level — NOT `--group-by tags`, which self-groups on the unique `cycle=$TS` tag and never ages cycles against each other** — and forgets all three snapshots of an expired cycle as one unit, followed by `assert_cycle_retention_invariant` requiring exactly one bound `data`+`complete`+`attestation` per retained cycle. Also v5: `$COMPLETE_SNAP` (the restore candidate, not just B1's data snapshot) is inventory-verified at B4a before the attestation and before the ping, and its `inventory_sha256` is bound into the attestation and re-checked at restore and by O-3b.
18. 🚨 **NEW in v4 — `SHA256SUMS` covers the DATA ARTEFACTS ONLY, and the restore walks the chain outside-in (§4.4b0).** This is not a nicety: the promote **rewrites** `MANIFEST.json` and **creates** `ATTESTATION.json`, so a phase-1 `sha256sum ./*` would be invalidated by the promote itself and `sha256sum -c SHA256SUMS` — the fail-closed gate at §4.6b step 0b, DR-3 step 7 and §4.8 step 4 — would **reject every successfully promoted cycle**, i.e. exactly the cycles that are restorable. **Test both directions in the Phase 4 script review: a promoted cycle passes the full chain, and a cycle whose `MANIFEST.json` has been altered by one byte after promotion FAILS at the attestation step.**
19. 🚨 **NEW in v4 — verification claims are recorded at their real strength.** The manifest records `restic_check_mode = "read-data-subset"` **with the subset actually used** (`restic_check_subset`, a rotating deterministic `n/12`) and, separately, `restic_inventory_check = "snapshot-scoped"`. **No document may describe `--read-data-subset` as verifying "the new snapshot"** — it samples repository packs (official [restic repository-check documentation](https://restic.readthedocs.io/en/stable/045_working_with_repos.html)). The snapshot-scoped evidence is the `restic ls --json "$DATA_SNAP"` inventory comparison, and the **full** evidence is the §4.8 rehearsal restore, which restores the named snapshot and validates every `SHA256SUMS` entry.

### 4.5 Retention

🚨 **v1's "operational retention is 90 days" (D-3) was false against this table and is struck** (Finding 14). The tiers below never produced a 90-day operational tier. v2 fixes the inconsistency by making the tiers the single statement of retention and deleting the "90 days" phrasing everywhere — **except** as the *interim WORM lock duration* (D-11), which is a different thing and is now labelled as such.

| Tier | Keep | Local | Object Storage (WORM leg) | Storage Box (restic leg) |
|---|---|---|---|---|
| Hourly | 48 | ✅ **subject to the §4.4d capacity ceiling — the count is a maximum, not a guarantee** | ✅ | ✅ |
| Daily | 14 | ❌ | ✅ | ✅ |
| Weekly | 8 | ❌ | ✅ | ✅ |
| Monthly | 12 | ❌ | ✅ | ✅ |
| **Yearly fiscal archive** | 🚨 **NOT CONFIGURED until E-4 answers.** No placeholder, no guess | ❌ | pending D-11 | ❌ |

#### 4.5a The two legs are different mechanisms with different threat models (v2, Finding 12)

v1 presented "two independent mechanisms, two independent retention policies" as if they were two of the same thing. They are not, and conflating them hides which threats are actually covered.

| | **Leg A — Object Storage (WORM)** | **Leg B — Storage Box (restic)** |
|---|---|---|
| **Protects against** | **Host compromise / ransomware / malicious deletion.** A compromised production host cannot destroy what it cannot delete | **Operator error and accidental loss.** Cheap, deduplicated, encrypted, easy to prune |
| **Does NOT protect against** | operator error inside the retention window (locked objects cannot be cleaned up either); provider/account loss | **host compromise** — the host holds repository credentials, and `restic forget --prune` / repository destruction are exactly what those credentials permit. **Restic encryption and deduplication do not make the repository immutable** |
| **Host credential** | 🚨 **WRITE-WITHOUT-DELETE.** The rclone credential on the production host has `PutObject` on the backup prefix and **no `DeleteObject`, no `DeleteObjectVersion`, no `PutObjectRetention`/`PutObjectLegalHold` bypass, no lifecycle-configuration rights** | full repository access — **mutable by design, and that is accepted** |
| **Deletion authority** | the **bucket lifecycle policy** (configured once by the owner from the console, with a credential that never touches the host) and object-lock expiry | the host's own `restic forget --prune` |
| **Lock** | object lock enabled **at bucket creation** (cannot be retrofitted [B4]); **mode and duration per D-11** — interim: **governance mode, 90 days** on the operational tiers | none |
| **Failure domain** | Hetzner | Hetzner — 🚨 **the same provider. These are two mechanisms, NOT two independent failure domains.** R-4 / D-13 |

**Lifecycle policy — explicit mapping** (owner configures at bucket creation, Phase 4.1; ⚠️ exact rule syntax and available lock modes are **UNVERIFIED** until read from the console):

| Object prefix | Transition / expiry rule | Lock |
|---|---|---|
| `erp-prod-backups/<TS>/…` where `<TS>` is an hourly cycle not promoted to a higher tier | expire after **48 h** | governance, 90 d (D-11) — note the lock **outlives** the lifecycle expiry, which is intentional: the object becomes unlistable-by-policy but undeletable-by-attacker |
| daily-promoted cycles | expire after **14 d** | idem |
| weekly-promoted | expire after **8 w** | idem |
| monthly-promoted | expire after **12 m** | idem |
| `erp-prod-media/…` (L4a — **append-only**, §4.5a1) | **bucket versioning ON.** Current version retained indefinitely; **noncurrent** versions expire after **14 d**. 🚨 **There is no `…-media-versions/<TS>/` prefix in v3** — v2's `--backup-dir` scheme is withdrawn because it required delete rights the host does not have. Objects deleted at the source are **never** deleted here; they age out only when the owner adds an expiry rule, and the interim ruling is **no expiry rule on current versions at all** | idem |
| `erp-prod-fiscal-archive/…` | 🚨 **no rule configured until E-4** | 🚨 **no lock configured until E-4** |

**How "a compromised host cannot destroy the WORM leg" is *tested*, not asserted** (Phase 4.1a, launch evidence):

| # | Test | Expected |
|---|---|---|
| 1 | Using the **host's** rclone credential, `rclone delete` an object in the backup prefix | **access denied** |
| 2 | Using the host credential, attempt to delete an object *version* | **access denied** |
| 3 | Using the host credential, attempt to shorten or remove the object's retention | **access denied** |
| 4 | Using the host credential, attempt to modify the bucket lifecycle configuration | **access denied** |
| 5 | Using the host credential, overwrite an existing object with garbage, then read the retained version back | original version still retrievable |
| 6 | Using the host credential, `restic forget --prune` a snapshot on **Leg B** | **succeeds** — this is the documented, accepted asymmetry, and it is why Leg A exists |

Test 6 passing is the point. It is the evidence that **Leg B is not a ransomware control**, so nobody later mistakes "we have two backups" for "we are covered against host compromise."

#### 🚨 4.5a1 The two legs carry DIFFERENT properties — the media leg is split accordingly (v3, round-2 F13/N3 — BLOCKER)

The re-gate found v2's media design **internally impossible**: `rclone sync --backup-dir` was pointed at a destination credential explicitly denied `DeleteObject` **and** `DeleteObjectVersion` (§4.5a). A `sync` must delete destination objects that vanished at the source, and `--backup-dir` must *move* (= copy + delete) a superseded object before overwriting it. Neither is available to a write-without-delete identity. The command and the credential could not both be right.

**Ruling — stop trying to make one leg carry both properties. Split them explicitly:**

| Property | Which leg carries it | How |
|---|---|---|
| **Immutability / ransomware resistance** — "a compromised host cannot destroy it" | **Leg A — Object Storage** | `rclone copy --immutable` into a **versioned** bucket. **Deletions and replacements NEVER propagate.** A deleted product image stays in the offsite copy until the **lifecycle policy** (owner-configured, credential the host never holds) ages it out. `--immutable` makes a *changed* source object a **hard error** rather than a silent overwrite, so a compromised host cannot quietly replace media with garbage |
| **A faithful, deletable mirror** — "restore the bucket exactly as it was, including the deletions" | **Leg B — Storage Box / restic (L4a″)** | `restic backup` of the MinIO data directory. Restic snapshots are point-in-time and **do** reflect deletions; `restic forget --prune` ages them. **Mutable by design, and that is the accepted asymmetry** (§4.5a) |
| **Per-object verifiability** — "prove the copy is byte-correct, per object" | **both, via the manifest (L4a′)** | `rclone lsjson --hash` inventory + `rclone check` exit code, recorded per cycle (below) |

**Concrete commands, replacing v2's single `rclone sync … --backup-dir` line:**

```bash
# L4a — APPEND-ONLY replication to the WORM leg. No delete verb is ever issued.
rclone copy "minio:$MEDIA_BUCKET" "hos:erp-prod-media" \
      --immutable --checksum --error-on-no-transfer=false

# L4a′ — per-object inventory + verification, INTO the cycle directory
rclone lsjson --hash --recursive "minio:$MEDIA_BUCKET" > "$STAGE/media-inventory.json"
rclone check "minio:$MEDIA_BUCKET" "hos:erp-prod-media" \
      --checksum --one-way --combined "$STAGE/media-check.txt"
MEDIA_CHECK_RC=$?          # 0 = every source object present and hash-identical at the destination
```

Notes that are load-bearing:

- **`--one-way`** is mandatory on the check. Without it, `rclone check` reports every *superseded or deleted* object still living at the destination as a difference, and the check would never pass — which is the correct behaviour for an append-only destination and would otherwise be misread as corruption.
- **`--checksum`** compares hashes, not size+modtime. If the provider does not expose a compatible hash for the destination (⚠️ **UNVERIFIED** until read from the console), the fallback is `rclone check --download`, which downloads and hashes; it costs egress and is therefore run **daily, not hourly** (§9.1 threshold). **Which of the two is in force is recorded in the manifest** (`media.check_mode`), so a restore knows what the evidence is worth.
- **`--immutable` turns a changed source object into a non-zero exit**, which the cycle treats as a **failure**, not a warning. Media in this application is written once per asset (`MediaUploadService.php:131`); a *changed* key is either a genuine re-upload under the same key or tampering, and both deserve an operator decision. 🎫 If re-upload-under-the-same-key turns out to be a real product flow, the fix is **content-addressed keys**, not dropping `--immutable`.
- **If the provider's credential model cannot express "put but not delete" at all** (⚠️ UNVERIFIED — Phase 4.1 reads it from the console), the fallback is **object-lock in governance mode doing the enforcement instead of IAM**, and that substitution must be **recorded as a deviation in the Phase 4.1a evidence**, not silently adopted. `rclone copy` remains correct either way; only the *enforcement mechanism* changes.

**What the manifest now records for media (this is what makes DR-3 step 9 executable):**

| Field | Source | Used by |
|---|---|---|
| `media.objects`, `media.bytes` | `rclone lsjson` count/sum | quick reconciliation |
| `media.inventory_file` = `media-inventory.json`, `media.inventory_sha256` | the inventory itself | **DR-3 step 9 verifies restored objects against this file**, not against an aggregate count |
| `media.check_mode` = `checksum` \| `download` | which check ran | tells a restore how strong the evidence is |
| `media.check_result` = `ok` \| `differences` | `rclone check` exit code | non-`ok` ⇒ the cycle is **not** `complete` (§4.4b) |
| `media.restic_snapshot_id` | `restic backup --json` on the MinIO data dir (L4a″) | the deletable-mirror restore point |

v2's `"media": { "objects": 0, "bytes": 0 }` aggregate is **withdrawn as insufficient** — the re-gate is right that DR-3 asked for "sample checksums against the manifest" against a manifest that contained no checksums.

##### 🚨 4.5a2 What a deletion actually does on leg A — and the erasure runbook that follows from it (v4, round-3 R3-N7)

**The correction first, because v3 stated this wrongly in a reassuring direction** (D-18 and the §11.3 F13 row both said the offsite copy survives "until the lifecycle ages the noncurrent version out"):

| Step | What happens on leg A |
|---|---|
| The application deletes an object in MinIO | **nothing** — `rclone copy` only ever transfers *source→destination*; a source deletion issues **no destination operation** |
| Bucket versioning | has **no event** to act on. No `DeleteObject` ⇒ **no delete marker** ⇒ **no noncurrent version is ever created** |
| The 14-day noncurrent-version lifecycle rule (§4.5a) | **has nothing to act on.** It ages *superseded* versions, and a deleted-at-source object was never superseded |
| The object's state | it stays the **current** version, and current versions carry **no expiry rule** under the interim ruling (§4.5a) — i.e. **retained indefinitely** |

**Therefore erasure on leg A is a manual, owner-credentialled action with a committed response time. That runbook is part of D-18, not an afterthought:**

| # | Step | Who | Notes |
|---|---|---|---|
| 1 | Record the erasure request: requester, legal basis, the affected tenant, and the object keys or the product/media IDs they resolve to | **[O]** | the keys come from the tenant DB (`media_assets`), or from `media-inventory.json` if the row is already gone |
| 2 | Delete in MinIO (the live system) and let the restic mirror (L4a″) propagate it at the next cycle | **[A]** | this is the *only* automatic half |
| 3 | Delete the object **and all its versions** on leg A from the **owner-held console credential** | **[O]** | 🚨 the host credential cannot do this **by design** — that denial is what makes the WORM claim true. There is no automation shortcut, and adding one would remove the property |
| 4 | If a **governance-mode lock** is in force and has not expired, either use the console's governance-bypass permission or record that removal is deferred until the lock expires, **with the expiry date** | **[O]** | ⚠️ **UNVERIFIED** whether the provider's governance mode permits a console-side bypass — Phase 4.1 reads it from the console. **If it does not, the interim lock duration is shortened, not the leg removed** (D-18) |
| 5 | Re-run the next cycle's L4a′ inventory and confirm the key is absent from `media-inventory.json` **and** from `rclone lsjson` of the destination | **[A]** | this is the evidence the erasure completed |
| 6 | Record completion against the request from step 1 | **[O]** | retained with the E-4a evidence |

**Committed maximum response time: 30 days** from a documented erasure request to confirmed removal, or shorter if a supervisory authority imposes one — **except** where step 4's lock has not expired, in which case the committed action is the *recorded deferral with its expiry date*, and that is exactly the exposure D-18 asks the owner to accept in writing. 🎫 The user-facing privacy notice must reflect this; that is an owner document, not an agent edit.

#### 4.5b 🚨 Fiscal retention is a NON-WAIVABLE sub-gate (v2, Finding 14)

v1 routed long-term fiscal retention through E-4 but left it as an ordinary gate row — and E-4's repository gate permits *"a dated owner risk acceptance"* instead of an accountant/legal answer (`docs/handoff/OWNER-manual-launch-gates-2026-07-31.md:37-43`). That means "all E-1…E-10 closed" could be true while no Tunisian retention answer exists at all.

**Ruling, aligned with E-4 and with the E-7 precedent:**

> **The fiscal-retention sub-gate (D-3b) is NON-WAIVABLE. It must close with a named legal/accounting approval — not an owner risk acceptance — BEFORE the first fiscal record is written on production.**

Two irreversible failure modes justify the non-waivable status, and they point in opposite directions:

| Direction | Consequence |
|---|---|
| Retention set **too short** | evidence is destroyed and cannot be recreated. There is no recovery from this |
| An irreversible **compliance-mode** lock set **too long** from a guess | years of storage cost, and a privacy/GDPR-shaped problem (data that must be deletable on request but provably cannot be) |

**Interim posture until the accountant answers (D-11):** governance-mode lock, **90 days maximum**, on the operational tiers only. **No WORM auto-lock beyond 90 days is configured**, and the fiscal-archive prefix has no lifecycle rule and no lock at all — an unconfigured prefix is recoverable; a wrong ten-year compliance lock is not.

##### 🚨 4.5b1 Making the sub-gate BINDING, not aspirational (v3, round-2 F14 — was NOT CLOSED)

The re-gate is right, and the criticism is precise: v2 declared Phase 7.12 non-waivable **inside this document**, while the actual launch evidence sink still says only **E-7** is non-waivable (`docs/handoff/OWNER-manual-launch-gates-2026-07-31.md:20,31`) and **E-4 still closes through "a dated owner risk acceptance"** (`:42`). A gate that is declared non-waivable in a design document and waivable in the sheet that actually gates the launch is waivable.

**This document cannot fix that itself.** `OWNER-manual-launch-gates-2026-07-31.md` is a Phase-E, human-only evidence sink whose header states: *"Do not dispatch, execute, or write evidence into this file from any agent lane… write-ownership of this file… transfers to Phase E — named humans only."* Editing it from here would violate its own governance and would be exactly the kind of unilateral change that makes a gate sheet untrustworthy.

**So the deliverable is the exact text, and an owner action item to insert it.** 🚨 **OWNER ACTION — E-1/E-4 gate sheet amendment. This is a launch-blocking prerequisite for Phase 7.12, and Phase 7.12 cannot be evidenced until it is done.**

> **Insert as a NEW row immediately after E-4, numbered `E-4a`:**
>
> | # | Gate | Named owner | Evidence | Evidence location | Pass / NO-GO criteria | Status |
> |---|---|---|---|---|---|---|
> | **E-4a** | **Fiscal retention period (NON-WAIVABLE)** | Houssam (coordinates) — **second human REQUIRED: a Tunisia accountant or legal reviewer, named here before the row begins; blank blocks the row** | A written retention determination naming, per data class (fiscal receipts, Z-reports, invoices/credit notes, audit chain, supporting media), the **minimum retention period required by Tunisian law**, the reviewer's name, their professional capacity, the date, and an explicit approved / approved-with-caveats flag | `docs/pos-operations/walkthrough-rehearsal.md` (Tunisia sign-off section), appended alongside the E-4 record | **PASS only on a named legal/accounting approval. 🚨 A dated owner risk acceptance does NOT close this row — it is non-waivable by any acceptance blank, anywhere, on the same footing as E-7.** NO-GO if any data class is unaddressed. **This row must close BEFORE the first fiscal record is written on production**, because both failure directions are irreversible: retention set too short destroys evidence that cannot be recreated, and a compliance-mode WORM lock set too long from a guess cannot be undone | |
>
> **And amend the "Hard rule (verbatim)" block at `:18-31`, changing:**
> *"Tenant #1 CANNOT onboard until every row below is closed with evidence. **E-7 is non-waivable.**"*
> **to:**
> *"Tenant #1 CANNOT onboard until every row below is closed with evidence. **E-7 and E-4a are non-waivable.**"*
> **and appending to the E-7 waiver paragraph at `:31`:**
> *"**E-7 is non-waivable** by any acceptance blank, anywhere. **The same applies to E-4a: no acceptance blank, and no owner risk acceptance, closes the fiscal-retention determination.** E-4's own risk-acceptance route (`:42`) continues to apply to E-4's other four subjects — VAT rates, receipt legal fields, certification scope, Z format — but **NOT to retention**, which moves to E-4a."*

**Why retention is carved out of E-4 rather than E-4 being made non-waivable wholesale.** E-4 bundles five subjects with genuinely different reversibility. A wrong VAT rate is a config change and a correcting entry. A wrong Z format is a release. **A retention period that was too short is unrecoverable — the evidence is gone — and a compliance-mode lock that was too long is unrecoverable in the other direction.** Making all five non-waivable would either stall launch on a reversible detail or, more likely, invite a blanket acceptance that quietly re-waives the one row that must not be waived. Splitting it keeps E-4's pragmatism and makes the irreversible part binding.

**Until E-4a closes:** the interim posture above holds, `erp-prod-fiscal-archive/` has **no lifecycle rule and no lock**, and Phase 7.12's evidence cell reads `BLOCKED — awaiting E-4a`. 🎫 The gate-sheet amendment is tracked as an owner action item in the Phase 0 evidence set with the same weight as D-1…D-8.

⚠️ **Hetzner Object Storage bills a minimum 64 KB per object** [B4]. Irrelevant for `-Fc` dumps (MB-scale); **relevant for the L4a media sync**, which may contain many small thumbnails — budget accordingly (Appendix B/E).

### 4.6 🚨 CORRECTED restore procedure (TimescaleDB)

**`docs/operations/BACKUP-RECOVERY.md:152-154` (`-j 4`) and `:232` (`-j 2`) are ACTIVELY WRONG for this cluster and must not be executed.**

Tiger Data, *Logical backup with pg_dump and pg_restore*: *"Do not use `pg_restore` with the `-j` option. This option does not correctly restore the TimescaleDB catalogs."* [D4] This **overrides** the generic PostgreSQL "use `-j` for speed" advice.

`pg_dump --exclude-extension` does not exist in PG16 (it landed in PG17) [D6], so the extension definition **cannot** be excluded from the dump and the pre/post pairing is unavoidable.

#### 4.6a 🚨 Tenant-maintenance state — restores must not race live writers (v2, Finding 9 — BLOCKER)

v1 terminated connections and then issued `DROP DATABASE` / `CREATE DATABASE` as separate statements, with a live API, a ten-process Horizon, and ~30 scheduled tenant commands (`config/horizon.php:201-229`; `routes/console.php:15-149`) all free to reconnect **between** the terminate and the drop. That is not a small window: `pg_terminate_backend` returns immediately, any in-flight HTTP request or job retry reconnects on its next statement, and a writer that lands after the restore begins corrupts the very thing being recovered — silently, because row counts are verified *after*.

**Two maintenance modes. Neither is optional, and the ordering within each is normative.**

**Mode A — cluster-wide restore (DR-2, DR-3): stop the application.**

| # | Action | Verification |
|---|---|---|
| 1 | 🚨 **v3: `php artisan horizon:terminate` FIRST, and wait for the master to exit.** `config/horizon.php:175` sets `'fast_termination' => false`, so `horizon:terminate` sends SIGTERM and each worker **finishes its current job** before dying. Stopping the container first would kill jobs mid-transaction | `horizon:status` → `Horizon is inactive.` (exit 2); no `php artisan horizon` PIDs in the worker container |
| 2 | Dokploy **stop** the `api`, `worker`, and `scheduler` Applications (websocket may stay — it has no DB writes) | all three show stopped; `/health` returns connection-refused via Traefik |
| 3 | Confirm zero non-superuser sessions: `SELECT count(*) FROM pg_stat_activity WHERE usename IN ('izipos_app','izipos_provisioner')` | `0` |
| 4 | Restore (§4.6 procedure per database, serially) | per-DB `--exit-on-error` clean |
| 5 | Run the §4.4c reconciliation post-conditions | no orphans, no missing |
| 6 | Start `api` → wait healthy → start `worker` → start `scheduler` | §7.4 V-suite |

Stopping the applications is preferred over connection surgery because it is **observable** — a stopped Application is a fact in the panel and a 502 at the edge, not a race you hope you won.

#### 🚨 Mode B — single-tenant restore (DR-1) with a FAIL-CLOSED drain (v3 round-2 F9; **re-ordered in v4 for round-3 R3-N1; atomic stop + master-timeout prerequisite in v5 for round-4 BLOCKER 1**)

The round-2 re-gate is right that v2's Mode B was **not race-free from the start of the fence**: suspension is evaluated only when a request *enters* `ResolveTenancy`, `horizon:pause` only stops the *next* reservation, "pending == 0" says nothing about active jobs, and `REVOKE CONNECT` does not evict an existing session — so a writer could commit between steps 4 and 5. v3 inserted a real drain **before** the DB fence; round 3 then found that v3's drain (a) held an unsafe `paused` state whose replacement window opens *before* the trap closes, and (b) had a probe that could not fail. **v4 fixes the order and the assertions. The word deliberately used from here on is FAIL-CLOSED, not "provable"** — every step either produces evidence or aborts, but one accepted residual (an operator's own superuser session, below) is closed by discipline rather than by mechanism, and calling that "provable" was overreach.

**What the application actually gives us — verified this session, and it is not what v2 assumed:**

| Assumption | Reality | Evidence |
|---|---|---|
| "put the tenant into 503" | 🚨 **There is no 503 anywhere in this codebase, and no per-tenant maintenance mode.** A suspended tenant gets **HTTP 403 `ORGANIZATION_UNAVAILABLE`** | `ResolveTenancy.php:56-83`; `TenantStatus.php:10-16` (`Active`/`Suspended`/`Pending`/`Archived`) |
| "the suspension middleware fences everything" | The enforcing middleware is **`ResolveTenancy`** (appended to both the `api` and `web` groups and priority-pinned before `AuthenticatesRequests`). **`EnsureTenantIsActive` is DEAD CODE** — defined, never registered on any route or alias | `bootstrap/app.php:78-83,88-90,100-103`; `EnsureTenantIsActive.php` has zero registration sites |
| "suspension only returns an error" | It **also revokes every tenant PAT synchronously** via the model observer, which is the stronger half of the fence: after suspension a POS device or browser holding a bearer token fails **authentication**, not just authorisation | `TenantObserver.php:57-59`, registered `AppServiceProvider.php:132` |
| "`php artisan down` could fence it" | Not usable: `APP_MAINTENANCE_DRIVER` defaults to **`file`**, i.e. per-container, and `down` is **fleet-wide** — it would 503 every tenant | `config/app.php:121-124` |
| "`horizon:pause` is safe to hold" | 🚨 **It is not, unattended — and v4 therefore DOES NOT USE IT AT ALL.** The worker container's healthcheck is `php artisan horizon:status \| grep -q running`, and a paused Horizon prints `Horizon is paused.` (exit 1) — so a held pause **fails the healthcheck and gets the worker restarted**, and a restarted master registers as *running* and resumes consuming **mid-restore**. v3 tried to bound this by pausing first and stopping the Application at step 3b; round 3 showed that does not work either (see the row below) | `apps/api/Dockerfile:165-166`; `vendor/laravel/horizon/src/Console/StatusCommand.php:32-51` |
| 🚨 **v4 (round-3 finding 1.2): "pause, drain, then stop" leaves an unhealthy window whose length is a JOB's length, not an operator's** | The image reports unhealthy after **three 30-second checks** (`apps/api/Dockerfile:165-166`). A single in-flight job can legitimately outlive that: `ProcessImportJob` declares a **3,600 s** timeout (`ProcessImportJob.php:50-65`), and Laravel does not act on SIGTERM until `runJob()` **returns** (`Worker.php:184-202,790-798`). So between v3's step 2 (pause) and step 3b (stop), the task can be marked unhealthy and **rescheduled by the orchestrator** ([Docker Swarm services](https://docs.docker.com/engine/swarm/services/)) — the old task killed mid-job, the replacement registering as `running` and **resuming consumption**. The later reserved/backend probes would eventually fail-stop, but only *after* an unacknowledged job interruption. That is not the drain round 2 demanded | `Dockerfile:165-166`; `ProcessImportJob.php:50-65`; `Worker.php:184-202,790-798` |
| "wait for pending == 0" | `horizon:status` prints **no counts at all**, and `JobRepository` exposes `countPending()`/`countFailed()`/… but **no `countActive()`/`countReserved()`**. The in-flight signal lives in Redis: `queues:<name>:reserved` | `StatusCommand.php:32-51`; `Contracts/JobRepository.php`; `RedisQueue.php:103,140` |

**Ruling — the drain probe that does exist, and is used:** `php artisan queue:monitor <queues> --json` reports, **per queue**, `size / pending / delayed / reserved` (`Illuminate/Queue/Console/MonitorCommand.php:21-24,99-117`). **`reserved` is the count of jobs a worker has taken and not yet finished** — that is the active-job probe. Note `php artisan queue:size` is *not* a substitute: on Redis it sums pending **+ delayed + reserved** (`RedisQueue.php:103`).

🚨 **v4 re-orders this fence (round-3 R3-N1); v5 corrects the stop primitive and the master-timeout gap (round-4 BLOCKER 1). Three changes, and the first is a deletion:**

1. **`horizon:pause` is DROPPED ENTIRELY.** It is not a safe holding state — a paused Horizon is an *unhealthy container* by the image's own healthcheck, and the unhealthy window is as long as the running job, not as long as the operator's next command (the two rows above). v3 kept the pause and tried to bound it by stopping the Application afterwards; round 3 showed the trap closes **after** the unsafe interval, not before it. **v4 never enters the paused state at all.** The ceiling that `pause` was buying is bought instead by the *stop*, which is strictly stronger: a stopped service reserves nothing **and** cannot be rescheduled.
2. **The STOP is a SINGLE ATOMIC `docker service scale <stack>_worker=0` (or the panel stop, which is the same Swarm primitive) — not `horizon:terminate` followed by a separate stop.** 🚨 **v5 (round-4 BLOCKER 1, fix b):** two commands leave an interval in which the task can exit with **desired replicas still 1** and be **replaced**. `docker service scale =0` sets desired replicas to **0 and** delivers SIGTERM in one operation, so there is no such interval. Because the worker entrypoint is `exec php artisan horizon` (`entrypoint-worker.sh:48`), that SIGTERM reaches the Horizon master directly and begins its graceful drain (`fast_termination => false`, `config/horizon.php:175`, each worker finishes its current job) — a separate `horizon:terminate` is redundant and is dropped to remove the two-command window. A service whose desired count is 0 is not a service the orchestrator restarts, so **the healthcheck-replacement race is structurally absent rather than merely bounded**.
3. 🚨 **v5 — the worker Horizon SUPERVISOR TIMEOUT must cover the longest job, or the stop drains NOTHING (round-4 BLOCKER 1, fix a).** Setting desired replicas to 0 is only a *drain* if Horizon keeps the worker alive until its job finishes. **It does not, at the repo default.** On SIGTERM the master calls `terminate($status = 0)`, waits only **`longestActiveTimeout()` — the maximum supervisor `timeout`** (`vendor/laravel/horizon/src/MasterSupervisor.php:167-203`; `vendor/laravel/horizon/src/Repositories/RedisSupervisorRepository.php:101-104`, `max(fn ($supervisor) => $supervisor->options['timeout'])`) — then `exit((int) $status)` with **status 0**. The production supervisor `timeout` is **`60`** today (`config/horizon.php:217`) while `ProcessImportJob` declares a **3,600 s** timeout (`ProcessImportJob.php:56`). So `service scale=0` — no matter how large `stop_grace_period` (D-21) is — sees the master voluntarily exit **0** after 60 s, abandoning the 3,600 s job, and Docker records a **clean exit 0**: exactly the "looks drained, was killed" signal that defeats the 137-detection step 5 relies on. **D-21 (grace period) is necessary but NOT sufficient; the supervisor timeout is the second necessary condition.** The invariant, stated once: `retry_after > supervisor.timeout ≥ max(job $timeout) + margin`, and `stop_grace_period > supervisor.timeout` (D-21). Concrete values, and this is a **Phase-0 code change, not an owner decision** (Phase 0.8k): supervisor `timeout` → `env('HORIZON_SUPERVISOR_TIMEOUT', 3900)` (3,600 + 300 s); Redis queue `retry_after` → `env('REDIS_QUEUE_RETRY_AFTER', 4200)` (**strictly greater** than the supervisor timeout, `config/queue.php:71`, default 90 today) so a job still draining inside the window is **not re-reserved by another worker mid-drain**.

**Mode B, normative order. Steps 0–6 are the drain; steps 7–10 are the fence.**

| # | Action | Concrete command | Why this step exists |
|---|---|---|---|
| **0** | 🚨 **v4 — fence the AUTOMATED BACKUP IDENTITY before anything else** | `systemctl stop erp-backup.timer && systemctl is-active erp-backup.timer` → `inactive`; then `systemctl is-active erp-backup.service` → `inactive` (wait for a running cycle to finish, or `systemctl stop` it and record the aborted cycle) | Round-3 finding 1.4: `izipos_backup` holds **explicit `CONNECT` on every database** (§3.4c) and the hourly host timer uses it. It sits **outside** v3's revoke list and outside the residual table, so it could reconnect *after* the twice-zero sample — making `DROP DATABASE` fail, taking a dump while the restore is mid-write, and invalidating the claim that step 10 proves a stable fence. **It is read-only, so it is not a live writer — but a fence with an unlisted key is not a fence** |
| **1** | **Suspend the tenant** in the central directory | `php artisan tenant:deprovision <slug> --suspend --force` (`DeprovisionTenantCommand.php:16-19`; idempotent, DB preserved — `TenantDeprovisioningService.php:108-116`). Admin HTTP equivalent: `POST /api/v1/admin/tenants/{id}/suspend` (`routes/api.php:67`) | Stops *new* HTTP work for that tenant **and revokes its bearer tokens**. It does **not** stop work already inside a request — steps 2–6 handle that |
| **2** | 🚨 **v4 — RECORD THE WORKER TASK IDENTITY** *before* touching it. 🚨 **v5 — record the FULL task-ID set, not only the running one** | `WORKER_TASK=$(docker service ps <stack>_worker --filter desired-state=running --format '{{.ID}}')`, `WORKER_TASK_BASELINE=$(docker service ps <stack>_worker --format '{{.ID}}' \| sort -u)` (**every** task id the service has ever had), and `WORKER_CID=$(docker ps -q -f name=<stack>_worker)` — all recorded in the incident log | Step 5 asserts that **this same task** exited and that **no task id outside the baseline appears in any state**. 🚨 **v5 (round-4 BLOCKER 1, fix c):** "running count is 0" is not enough — a replacement can be scheduled and then itself be observed stopped; the discriminating signal is a task id that was **not in the pre-stop baseline**. Without the full set recorded up front, "the worker is gone" and "the worker was replaced and is happily consuming" look identical from the outside |
| **3** | 🚨 **v5 — ONE atomic stop: set desired replicas to 0** (no `horizon:pause`, no separate `horizon:terminate`, no two-command window) | `docker service scale <stack>_worker=0` (or the panel stop — the same Swarm primitive) | 🚨 **v5 (round-4 BLOCKER 1, fix b):** `service scale=0` atomically sets **desired replicas to 0 and** sends SIGTERM, so the task can never exit with desired still 1 and be replaced. Because `exec php artisan horizon` makes Horizon the main process (`entrypoint-worker.sh:48`), that SIGTERM begins Horizon's graceful drain directly (`fast_termination => false`, `config/horizon.php:175`, each worker finishes its current job). **The drain only COMPLETES because change 3 above raised the supervisor `timeout` (`env('HORIZON_SUPERVISOR_TIMEOUT', 3900)`) to cover the job — otherwise the master exits 0 after 60 s (`MasterSupervisor.php:167-203`) and this "stop" is a silent kill.** 🚨 **`stop_grace_period` on the worker service must also exceed the longest job timeout (3,600 s today — `ProcessImportJob.php:56`), or Docker's SIGKILL turns the drain back into a mid-job abort. That is D-21** |
| **4** | **Stop the `scheduler` Application** | panel stop | `schedule:work` has no drain semantics and no healthcheck (`Dockerfile:171-176`; `entrypoint-scheduler.sh:39`), and ~30 scheduled tenant commands iterate the fleet (`routes/console.php`). A forked `Schedule::command()` subprocess **survives** the container stop until it exits — step 6's probe B is what proves it did |
| **5** | 🚨 **v4 — ASSERT THE SAME TASK EXITED AND NOTHING REPLACED IT. 🚨 v5 — compare the FULL task-ID set to the baseline, not just "0 running"** | `docker service ps <stack>_worker --format '{{.ID}} {{.CurrentState}}'` → the recorded `$WORKER_TASK` is `Shutdown`/`Complete`, **the running-task count is 0**, **AND every task id now present was already in `$WORKER_TASK_BASELINE`** (`comm -13 <(echo "$WORKER_TASK_BASELINE") <(docker service ps <stack>_worker --format '{{.ID}}' \| sort -u)` is empty); `docker inspect --format '{{.State.ExitCode}}' $WORKER_CID` → `0` (not `137`, which is SIGKILL, i.e. the grace period was too short) | This is the assertion that makes the reorder *provable* rather than merely *plausible*. 🚨 **v5 (round-4 BLOCKER 1, fix c): ANY task id absent from the pre-stop baseline is a replacement Swarm scheduled — ABORT the fence, do not proceed to the drop.** A bare "running count is 0" misses a replacement that has already come and gone. An exit code of `137` means the job was killed, not drained: proceed only after establishing what job was interrupted and what it left behind |
| **6** | 🚨 **PROVE zero in-flight and zero sessions, with explicit fail-closed wait loops** | the two probes below — **both must pass, and both now exit non-zero on timeout** | The assertion that replaces v2's "wait for pending == 0" |
| **7** | 🚨 **v4 — `REVOKE CONNECT ON DATABASE "<db>" FROM PUBLIC, izipos_app, izipos_provisioner, izipos_backup;`** | `psql -v ON_ERROR_STOP=1` | Revoking first makes the terminate *final*. It does **not** evict existing sessions — which is why it comes **after** the drain, not instead of it. **`izipos_backup` is added in v4** (finding 1.4); step 0 stops its timer, and this revoke is the belt to that braces |
| **8** | `SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='<db>' AND pid <> pg_backend_pid();` | idem | Sweeps anything the drain did not catch (e.g. an idle-in-transaction session) |
| **9** | **Assert zero, twice, 10 s apart** | `SELECT count(*) FROM pg_stat_activity WHERE datname='<db>';` → `0` both times | Two samples, because one sample cannot distinguish "drained" from "between reconnects" |
| **10** | `DROP DATABASE` / `CREATE DATABASE` / restore (§4.6b) | — | Safe only because 0–9 hold |
| **11** | Un-fence in reverse: `GRANT CONNECT` (**including `izipos_backup`**) → verify → start `scheduler` → start `worker` → `POST /admin/tenants/{id}/activate` → 🚨 **v4: `systemctl start erp-backup.timer` and require the NEXT COMPLETE CYCLE (§4.4b1 `result == "complete"`) before the incident is closed** | reverse order of fencing | 🚨 **`activate` is HTTP-only — there is no artisan un-suspend command** (`SuperAdminController.php:273-…`). The admin route group deliberately omits the `api` middleware group, so it keeps working against a suspended tenant (`routes/api.php:57-58`) — that is what makes un-suspension possible at all. **The backup timer is the one un-fence step that is easy to forget and silent when forgotten** — a stopped timer produces no ping, so O-3 catches it within 90 minutes, but the incident is not closed on an alert not firing |

**Step 6, the two probes, verbatim. 🚨 v4 adds Probe B's missing fail-closed assertion (round-3 finding 1.3) — in v3 the loop simply ended, so after 30 failed attempts the script fell through to `REVOKE`/terminate with backends still open. That silently converted the policy from "drain the request" to "kill it and accept its rollback or partial external effects", which is exactly the choice round 2 forbade making implicitly:**

```bash
# ---- PROBE A: zero in-flight jobs on every named queue ----
# Queues are the six from config/horizon.php:209.
QUEUES=redis:default,redis:fiscal-projections,redis:enrichment,redis:images,redis:imports,redis:ingestion
for attempt in $(seq 1 60); do            # bounded: 60 × 10 s = 10 min
  RESERVED=$(docker exec "$API" php artisan queue:monitor "$QUEUES" --json --max=999999 \
             | jq '[.[] | .reserved] | add')
  [ "$RESERVED" = "0" ] && break
  echo "in-flight jobs: $RESERVED — waiting"; sleep 10
done
[ "$RESERVED" = "0" ] || { echo "DRAIN FAILED — do NOT proceed"; exit 1; }
sleep 95                                   # confirm nothing is still moving (not a wait for a reappearance)
RESERVED2=$(docker exec "$API" php artisan queue:monitor "$QUEUES" --json --max=999999 \
            | jq '[.[] | .reserved] | add')
# 🚨 v5 (round-4 finding 6): message matches the corrected reasoning — with no worker popping, an
# orphaned reserved job STAYS reserved (it does not migrate/reappear); a non-zero here means work is
# still in flight, not that a job "reappeared".
[ "$RESERVED2" = "0" ] || { echo "in-flight jobs still reserved on re-sample — do NOT proceed"; exit 1; }

# ---- PROBE B: zero backends on the target database ----
# v4: `-v ON_ERROR_STOP=1`, and a query failure is FATAL — an empty/errored probe must never
# be read as "zero". CONNS is seeded with a sentinel so a failed first attempt cannot fall
# through as if it had measured something.
CONNS=probe_not_run
for attempt in $(seq 1 30); do            # bounded: 30 × 10 s = 5 min
  if ! CONNS=$(docker exec "$PG" psql -v ON_ERROR_STOP=1 -U "$PGUSER" -d postgres -tAc \
               "SELECT coalesce(numbackends,0) FROM pg_stat_database WHERE datname='$DB';"); then
    echo "PROBE B QUERY FAILED (attempt $attempt) — treating as NOT drained"; CONNS=probe_failed
  fi
  [ "$CONNS" = "0" ] && break
  echo "sessions on $DB: $CONNS — waiting"; sleep 10
done

# 🚨 v4 (round-3 finding 1.3) — THE ASSERTION v3 OMITTED. Without it the script continues to
# REVOKE/terminate after 30 unsuccessful samples, killing whatever was still connected.
[ "${CONNS:-probe_failed}" = "0" ] || {
  echo "DRAIN FAILED — $CONNS backend(s) remain; do NOT revoke or terminate"
  exit 1
}
```

**Both probes are fail-closed and both are mandatory.** If either exits non-zero, the fence is abandoned and the restore does not start — the operator investigates what is still holding the tenant rather than terminating it. **"Terminate anyway" is available only as an explicit, recorded operator override**, and taking it means accepting an unknown rollback or partial external effect; it is not a default the script can reach by timing out.

Probe B's query is the same one `php artisan tenant:status` already surfaces as its `conns` column (`TenantHealthService.php:112-123`; `TenantStatusCommand.php:16,30-42`), and it reads from the **central** connection, so it keeps working after the tenant DB is fenced.

**Residual risk, stated plainly — this drain is bounded, not absolute. 🚨 v4 rewrites this table after round-3 finding 1.5, which found three of the six rows overstated or simply wrong:**

| Residual | Bound | Why it is acceptable |
|---|---|---|
| An HTTP request that entered `ResolveTenancy` before step 1 continues to completion | PHP-FPM request timeout (nginx/FPM ceiling, seconds), and probe B will not read `0` until its backend closes | Probe B is the assertion, not the suspension — **and in v4 probe B actually fails closed**, which is what makes this row true. In v3 the loop could time out and the script continued anyway, so this bound was asserted but not enforced |
| A `Schedule::command()` subprocess forked before step 4 | its own runtime | Probe B again — it holds a backend while it runs, and a timeout now aborts the fence rather than terminating it |
| 🚨 **CORRECTED in v4** — a worker that **crashed** holding a job leaves the payload in `queues:<name>:reserved`, and with no worker popping **it stays there** | it does **not** self-clear | **v3's explanation for the second probe-A sample was wrong.** Expired reserved jobs are migrated back to the pending list **only from the queue-pop path** (`RedisQueue.php:297-303,321-346`). Once Horizon has terminated and the worker Application is stopped, nothing pops, so nothing migrates: `queue:monitor` **counts, it does not migrate**. The orphan therefore does **not** "disappear and reappear after 95 s" — it simply stays `reserved`, and probe A stays non-zero. **The second sample is still kept**, because a non-zero-then-zero transition is a signal that something was still moving, and because the cost is 95 seconds; but the reason is "confirm nothing is in motion", not "wait for a reappearance". This is a **safe false-negative** either way — the probe over-reports rather than under-reports |
| A **superuser** or `izipos_admin` session (an operator's own psql) is not revoked by step 7 | 🚨 **operator discipline — this is an ACCEPTED RESIDUAL, not a proof** | Step 8 terminates by `datname` and step 9 asserts zero regardless of role, so any such session is *evicted and detected*. But a role with `CONNECT` that this fence cannot revoke **can reconnect between step 9 and step 10**. **v4 therefore withdraws the word "provable" from this section's heading claim in the absolute sense**: the fence is *fail-closed and evidence-producing*, and the admin exception is closed by discipline (one operator, one terminal, and the standing rule that the restore session is the only `izipos_admin` session open) — not by mechanism. 🎫 Ticket: a `pg_hba`-level or `ALTER DATABASE … ALLOW_CONNECTIONS false` fence would make it mechanical; the latter is worth testing at Phase 4 as it is a one-line strengthening |
| 🚨 **NEW in v4** — the **automated backup identity** (`izipos_backup` + `erp-backup.timer`) is a second reconnect-capable path | closed by step 0 (timer stopped, verified inactive) and step 7 (revoke) | Round-3 finding 1.4. It is read-only, so it can never corrupt the restore — but it can make `DROP DATABASE` fail and can take an inconsistent dump *of a half-restored database*, which would then be a `complete` cycle full of garbage. **Both halves are now in the procedure, and step 11 requires the next complete cycle before the incident closes** |
| The whole fence is **fleet-affecting for queues** — steps 3–4 stop job processing and the scheduler for *every* tenant, not just the target | minutes, but see D-21 | Accepted, and it is the same trade v2 made. HTTP for other tenants is untouched. There is no per-tenant queue partition in `config/horizon.php:201-249` (one supervisor, six shared queues) — building one is a code project, not a config toggle. 🚨 **v4 adds the honest worst case: because the stop must wait out the running job, a long import can extend the fleet-wide queue outage to that job's timeout (up to 3,600 s today).** That is the cost D-21 asks the owner to accept, and it is the strongest argument for the ticketed long-import queue split. 🎫 **Ticket: per-tenant or per-tenant-group queue partitioning**, if single-tenant restores ever become routine |
| 🚨 **CORRECTED in v4** — `queue:monitor` dispatches `QueueBusy` events when `size >= --max` | **none while total queue size < 999,999** — not "none" | v3 said "none at `--max=999999`", full stop. The monitor dispatches on `size >= max` (`MonitorCommand.php:115,156-169`), so the guarantee is conditional on the backlog, not on the flag. At launch volumes it is unreachable; the row is corrected so nobody later reasons from a false absolute |

🚨 **The in-app restore has the same defect.** `TenantBackupService.php:151-186` calls `deleteDatabase()` then `createDatabase()` (`:181-184`) with **no** fence, no revoke, no drain. It is already flagged for the Timescale pre/post gap (R-9); **this is a second, independent defect in the same method.** 🎫 **Code follow-up ticket** — `tenant:restore` must acquire the Mode B fence (or refuse to run unless the tenant's status is `suspended`, which is a one-line guard it could take today). **Not fixed by this document; §4.6 remains the authoritative production path either way.**

#### 4.6b Canonical single-database restore

```bash
PG=$(docker ps -q -f name=<postgres-appName>)
DB=izipostenant_<uuid>
CYCLE=/mnt/backups/pg/<TS>
DUMP=$CYCLE/$DB.dump

# 0a. VERIFY THE CYCLE IS A RESTORE CANDIDATE (§4.4c, §4.4b1)
jq -e '.result == "complete"' "$CYCLE/MANIFEST.json"
jq -e '.offsite.object_storage.state == "verified" and .offsite.restic.state == "verified"' \
   "$CYCLE/MANIFEST.json"      # v3: "staged" is NOT a restore candidate

# 0b. VERIFY INTEGRITY, OUTSIDE-IN. Never restore an unverified archive.  🚨 v4 §4.4b0
#     The manifest is NOT in SHA256SUMS (the promote rewrites it) — it is bound by the attestation.
test "$(sha256sum "$CYCLE/MANIFEST.json" | cut -d' ' -f1)" \
   = "$(jq -r .manifest_sha256 "$CYCLE/ATTESTATION.json")"      # marker ↔ attestation
( cd "$CYCLE" && sha256sum -c SHA256SUMS )                      # data artefacts
# cross-check the two layers: a tampered manifest that lists different hashes fails HERE
jq -r '.central, .tenants[] | "\(.sha256)  \(.file)"' "$CYCLE/MANIFEST.json" \
  | sort | diff - <(sed 's#\./##' "$CYCLE/SHA256SUMS" | grep -E '\.dump$' | sort)

# 0c. ENTER THE MODE B FENCE (§4.6a) — stop the backup timer, suspend tenant, terminate+stop worker,
#     stop scheduler, REVOKE CONNECT, terminate, assert zero sessions.

# 1. Drop and recreate. (Safe only because step 0c holds.)
docker exec "$PG" psql -U "$PGUSER" -d postgres -v ON_ERROR_STOP=1 -c "DROP DATABASE IF EXISTS \"$DB\";"
docker exec "$PG" psql -U "$PGUSER" -d postgres -v ON_ERROR_STOP=1 -c "CREATE DATABASE \"$DB\" OWNER \"$PGUSER\" TEMPLATE template0;"

# 2. TIMESCALE BRANCH — decided by the MANIFEST, never by querying the target (§4.6c)
if jq -e --arg db "$DB" '.source_metadata[$db].extensions[]?|select(.name=="timescaledb")' "$CYCLE/MANIFEST.json"; then
  EXTVER=$(jq -r --arg db "$DB" '.source_metadata[$db].extensions[]|select(.name=="timescaledb").version' "$CYCLE/MANIFEST.json")
  docker exec "$PG" psql -U "$PGUSER" -d "$DB" -v ON_ERROR_STOP=1 \
    -c "CREATE EXTENSION IF NOT EXISTS timescaledb VERSION '$EXTVER';"
  docker exec "$PG" psql -U "$PGUSER" -d "$DB" -v ON_ERROR_STOP=1 -c "SELECT timescaledb_pre_restore();"
  TIMESCALE=1
else
  TIMESCALE=0    # ordinary serial restore — no pre/post calls at all
fi

# 3. RESTORE — SERIAL. NO -j. EVER.
docker exec -i "$PG" pg_restore -U "$PGUSER" -d "$DB" \
  --no-owner --no-acl --exit-on-error < "$DUMP"

# 4. Leave restore mode — ONLY if step 2 entered it
[ "$TIMESCALE" = "1" ] && docker exec "$PG" psql -U "$PGUSER" -d "$DB" -v ON_ERROR_STOP=1 \
  -c "SELECT timescaledb_post_restore();"

# 5. Planner statistics are NOT restored by pg_dump
docker exec "$PG" psql -U "$PGUSER" -d "$DB" -c "ANALYZE;"

# 6. Verify — fiscal row counts against the pre-incident record (E-3 / E-10 §ii)
docker exec "$PG" psql -U "$PGUSER" -d "$DB" -c \
  "SELECT 'pos_receipts', count(*) FROM pos_receipts
   UNION ALL SELECT 'documents', count(*) FROM documents
   UNION ALL SELECT 'audit_events', count(*) FROM audit_events
   UNION ALL SELECT 'journal_entries', count(*) FROM journal_entries;"
# then the fiscal chain verifiers with --actor-id, per the E-3 procedure
```

#### 4.6c 🚨 The Timescale branch is decided by the MANIFEST, not by the target (v2, Finding 11)

v1's gate was `SELECT 1 FROM pg_extension WHERE extname='timescaledb'` **against the target database**. The review is right that this cannot work: run it *after* `CREATE EXTENSION` and it is necessarily true; run it *before* and it says nothing whatsoever about what the **source archive** contained. The question "did the source have Timescale?" is unanswerable from the target by construction. **It must be recorded at backup time** — which is why §4.4b's `source_metadata` block exists and why the restore branches on `MANIFEST.json`.

**What v2 established about which databases can actually carry the extension** (this is more precise than v1's inference, and it changes the expected answer):

| Fact | Evidence |
|---|---|
| No ERP migration installs `timescaledb`; the only `CREATE EXTENSION` anywhere in migrations is `btree_gist` | `grep -rn "CREATE EXTENSION" apps/api/database/migrations/` → `tenant/2026_04_19_140003_create_scheduling_appointments_table.php:31` |
| No migration calls `create_hypertable`; no `timescale`/`hypertable` reference exists in `app/`, `config/`, or `database/` outside two explanatory comments | verified this session |
| `audit_events` — the table behind the "audit chain (TimescaleDB)" claim — is an **ordinary tenant table** with no hypertable conversion | `database/migrations/tenant/2025_11_30_140000_create_audit_events_table.php` (38 lines, no timescale/hypertable references) |
| 🚨 **Stancl creates tenant databases `WITH TEMPLATE=template0`** | `vendor/stancl/tenancy/src/TenantDatabaseManagers/PostgreSQLDatabaseManager.php:32-35` (verified) |

The `template0` fact is decisive and v1 missed its implication. `template0` carries **no** extensions, so even if the timescaledb-docker image seeds `template1` (the conventional behaviour), **that seeding cannot propagate to any Stancl-provisioned tenant database.** By contrast, `iziposcentral` is created by hand with a plain `CREATE DATABASE`, which defaults to `TEMPLATE template1` — so **the central DB is the only database in this design that plausibly inherits the extension.**

**Therefore the precise expected state — to be confirmed, not assumed:**

| Database | Expected `timescaledb` | Confidence |
|---|---|---|
| `iziposcentral` | **possibly present** (inherited from `template1` if the image seeds it) | ⚠️ UNVERIFIED — Phase 2.2 measures it |
| `izipostenant_*` | **absent** — `TEMPLATE=template0` cannot inherit it, and no migration installs it | high, from repository evidence |
| `template1` | possibly present (image init) | ⚠️ UNVERIFIED |

**Ruling:** create `iziposcentral` with **`TEMPLATE template0`** as well (Phase 2.3), so that *no* database in this cluster carries an extension nobody asked for and the Timescale branch is dead code that stays correct if that ever changes. **Phase 2.2 still runs `\dx` against `iziposcentral`, a scratch DB, and `template1` and records the actual answer** — because "we reasoned it should be absent" is not the same as "we looked." Both branches are implemented and **both are tested** (§4.8 step 13): one restore of a manifest declaring Timescale, one declaring none.

**"The audit chain lives in TimescaleDB" is, on current repository evidence, not true of this codebase** — `audit_events` is a plain table. The stack table in `apps/erp/CLAUDE.md` lists TimescaleDB for audit logs; that is an *intent*, not an implemented fact. 🎫 **Ticket: reconcile the documented stack claim with the schema.** It does not change this design (the backup captures the table either way), but it should stop being repeated.

**Cluster restore order** *(corrected per Finding 10)*:

| # | Step | Requirement |
|---|---|---|
| 1 | Read and validate `MANIFEST.json`; run the §4.4c reconciliation checks | refuse on `result != "complete"` |
| 2 | **Roles/globals** — `psql -d postgres -v ON_ERROR_STOP=1 -f globals.sql`, run as the **`izipos_admin`** identity (§3.4c), **not** the `CREATEDB`-only application role | 🚨 `-v ON_ERROR_STOP=1` is mandatory. v1 omitted it, so a failing role statement would scroll past and every subsequent `pg_restore` would fail confusingly |
| 3 | Handle **pre-existing roles**: a freshly provisioned Postgres service already contains its bootstrap superuser and application role, so an unfiltered globals file **will** collide | The procedure must be **idempotent**: pre-filter the globals file (drop `CREATE ROLE` for roles that already exist, keep `ALTER ROLE`), or apply it and explicitly accept only "role already exists" errors — never a blanket ignore. **Rehearsed in §4.8, not improvised** |
| 4 | ⚠️ **Verify the globals dump actually captured role passwords.** `pg_dumpall --globals-only` run as a non-superuser may silently omit password material. The backup identity is `izipos_backup` (§3.4c) with `pg_read_all_data`; whether that suffices for `rolpassword` is **UNVERIFIED** and is a **Phase 4.3 acceptance test**: dump globals, restore into a scratch cluster, and prove a role can authenticate with its original password. If it cannot, the backup identity is raised (documented) or a **credential-rotation step** becomes a mandatory part of every cluster restore | binary pass/fail, measured before launch |
| 5 | `iziposcentral` | serial, `--exit-on-error` |
| 6 | Each tenant database **named in the manifest**, serially | serial, `--exit-on-error`, Timescale branch per §4.6c |
| 7 | **Restore every artefact the manifest names, and FAIL on any omission** | a restore that quietly skips a database is the failure mode this whole section exists to prevent |
| 8 | Post-conditions: §4.4c both-direction reconciliation; `ANALYZE` every DB; fiscal verifiers | — |

There is **no template database to restore** — v1 listed `izipostemplate` in L2 and then omitted it from the restore order; v2 removes the database entirely (§3.2), which resolves the inconsistency by deletion rather than by adding a step.

🚨 **The in-app `tenant:restore` command does NOT do the Timescale dance** — and, per §4.6a, also does not fence live writers. Verified: `TenantBackupService.php:260-289` invokes `pg_restore --no-owner --no-acl --exit-on-error` with **no** `timescaledb_pre_restore()` / `post_restore()` calls, and `:150-186` drops/recreates with no revoke, no terminate-and-assert, and no Horizon pause. Credit where due — it does **not** use `-j`, and it sha256-verifies before restoring (`:147`). **Ruling: the §4.6 scripted procedure is authoritative for production. `tenant:restore` may be used only after BOTH defects are fixed** (Timescale pre/post pair **and** the Mode B fence). 🎫 Two code follow-up tickets; neither is fixed by this document.

### 4.7 DR runbook — one real tenant

| # | Scenario | Detection | RPO | RTO | Procedure |
|---|---|---|---|---|---|
| **DR-1** | Tenant data corrupted / bad bulk operation | User report, or fiscal verifier failure | ≤ 1 h *(conditional, §4.1)* | ≤ 1 h 🔬 | Identify the last **complete** hourly cycle (§4.4b) → **enter the Mode B fence (§4.6a)** → §4.6b single-DB restore → §4.4c reconciliation → fiscal chain verifiers with `--actor-id` → un-fence → smoke a sale |
| **DR-2** | Postgres volume lost / cluster unrecoverable | `pg_isready` healthcheck fail + Traefik 502 → UptimeRobot | ≤ 1 h *(conditional)* | **≤ 4 h** 🔬 *(was 2 h)* | **Enter Mode A (stop api/worker/scheduler, §4.6a)** → recreate the postgres service (empty `pgdata`) → §4.6c cluster restore order, steps 1–8 → restart api → worker → scheduler → §7.4 post-deploy verification |
| **DR-3** | **Total host loss** (hardware, DC, unrecoverable compromise) | UptimeRobot + healthchecks.io silence | ≤ 1 h DB / ≤ 1 h media / ≤ 24 h appstorage *(per-volume, §4.1)* | 🚨 **≤ 8 h** 🔬 *(was 4 h — D-14)* | **Rewritten in v2 — see the full procedure below.** Provision a Hetzner CLOUD instance → **bootstrap from the checked-in manifest, panel-independent** → **pull digest-pinned images from GHCR** → restore Postgres **and `miniodata` and `appstorage`** → repoint DNS (A **and** AAAA) → §7.4 verification → smoke |
| **DR-4** | Dokploy panel down / account suspended | Panel unreachable | n/a | n/a | **Running containers and TLS keep serving** — each remote server runs its own swarm manager and Traefik [C3][C4]. Lost: deploys via the panel, panel logs, the restore UI, **volume backups and their notifications** (which is why L4a moved to the host cron — §4.3), panel-driven alerts. **Not lost: the §4.4 host backup cycle, and — new in v2 — the ability to deploy at all** (§7.0.3 gives a panel-independent deploy/rollback path for security hotfixes) |
| **DR-5** | Hetzner-wide / account loss | Everything silent | ≤ 24 h *(only if the D-13 off-provider leg exists)* | ≥ 8 h | **NOT COVERED AT LAUNCH** — §10 R-4, **D-13 sets the decision date** |

#### 🚨 DR-3 in full (v2 rewrite — Findings 16, 17, 13 were all BLOCKERs against v1's version)

v1's DR-3 was three claims stacked on nothing:

| v1 claim | Why it was fiction |
|---|---|
| *"deploy the last known-good image tag from the registry"* | **No registry existed.** No workflow performed a GHCR login, `docker/build-push-action`, or `push: true`; CI uploads only a 7-day web `dist` artifact (`.github/workflows/ci.yml:909-942`). Dokploy definitions build from git (`docker-compose.dokploy.yml:170-256`) and consume no immutable images |
| *"register it on the Dokploy panel → server setup"* | **DR-3 depended on the same panel** whose outage DR-4 concedes, and DR-4 only covers panel loss while the original host still lives. There was no panel-independent path |
| *"restore per DR-2"* | **DR-2 restores only Postgres.** `miniodata` and `appstorage` were never restored, so the recovered system would have had a complete database referencing media that did not exist |

**v2's DR-3 procedure** (each step's prerequisite is now a Phase 0 deliverable, §7.0):

| # | Step | Prerequisite | Panel needed? |
|---|---|---|---|
| 1 | Provision a Hetzner **cloud** instance (CCX23/CX43) from the Hetzner API token in the break-glass package (D-12) | ⚠️ ~60 s provisioning **UNVERIFIED** | ❌ |
| 2 | Base bootstrap: install Docker + compose plugin from the distro repo; apply `/etc/docker/daemon.json` log rotation | §7.0.3 bootstrap runbook | ❌ |
| 3 | `docker login ghcr.io` with the registry read token from the break-glass package | **D-9 registry**, token held **outside** the dead host | ❌ |
| 4 | Clone (or restore from the offsite copy) `deploy/production/compose.production.yml` — **secret-free and, 🚨 v4, VARIABLE-REFERENCING rather than digest-pinned** (§7.0.2b, D-20) | §7.0.3 manifest | ❌ |
| **4a** | 🚨 **NEW in v4 — fetch and VERIFY `release-set.lock.json`** for the `last-known-good` set, and materialise it as the image env file next to the compose. Sources in order: the GitHub artefact store; **either offsite backup leg**, where every build writes it with the cycle (§7.0.2b). **A lock whose attestation/signature does not verify is NOT deployed** | §7.0.2b, break-glass (D-12) | ❌ |
| **4b** | **If the lock cannot be obtained from anywhere** (artefact expired *and* both legs unreadable): follow §7.0.2b failure mode 1 — recover the digests from the last archived V-10 output, re-sign a reconstructed lock, and **record the reconstruction as a deviation in the incident log**. Do not improvise digests from tags | §7.0.2b; archived V-10 evidence | ❌ |
| 5 | Materialise the env file from **1Password** (`op read`), never from anything that lived on the dead box | D-4, D-12 | ❌ |
| 6 | `docker compose up -d postgres redis minio` | — | ❌ |
| 7 | Download the last **`complete`** cycle from Object Storage; walk the **outside-in integrity chain of §4.4b0** (`ATTESTATION.json` → `manifest_sha256` → `sha256sum -c SHA256SUMS` → manifest/SHA256SUMS cross-check — **not a bare `sha256sum -c`**, which does not cover the marker); validate `MANIFEST.json` — **including `offsite.object_storage.state == "verified"`** (§4.4b1). 🚨 **v4: if restoring from the restic leg instead, select by TAG (`role=complete`), read `ATTESTATION.json` FIRST and verify the pairing — never `restic restore latest`** (§4.4b2) | §4.4b/b0/b1/b2/c | ❌ |
| 8 | Cluster restore per §4.6c steps 1–8 | — | ❌ |
| 9 | 🚨 **Restore media**: `rclone copy "hos:erp-prod-media" "minio:<bucket>"`, then **verify object-by-object against `media-inventory.json`** — `rclone check minio:<bucket> hos:erp-prod-media --checksum --one-way`, plus a `jq`-driven comparison of every key/hash in the inventory against `rclone lsjson --hash` of the restored bucket. **Aggregate counts are not accepted as evidence** (v3, §4.5a1) | §4.3 L4a/L4a′ | ❌ |
| 9b | 🚨 **If the restore must reproduce deletions** (e.g. a compliance-driven media purge happened before the incident), restore from the **restic mirror (L4a″)** instead of the append-only prefix — that leg is the one that reflects deletions | §4.3 L4a″ | ❌ |
| 10 | 🚨 **Restore `appstorage`** from the nightly volume backup; accept the log gap (§3.5) | — | ⚠️ the nightly volume backup is a panel artefact; if the panel is also gone, appstorage is **recreated empty** and the loss is the bounded, documented one in §3.5 |
| **11** | 🚨 **Repoint DNS: A *and* AAAA, atomically, for both `<apex>` and `api.<apex>` — MOVED AHEAD OF THE EDGE in v3 (§7.0.3a)**, because Let's Encrypt HTTP-01 validates at the name's current address | Appendix A, D-12 (DNS credentials) | ❌ |
| 12 | `docker compose up -d traefik` → wait for certificate issuance → `docker compose up -d api worker scheduler websocket web` | §7.0.3a — **one TLS path, no alternative** | ❌ |
| 13 | §7.4 V-suite + fiscal verifiers (**new contracts — §7.4b**) + smoke | — | ❌ |
| 14 | *Afterwards, not during:* re-register the host on the panel to resume normal operations | — | ✅ |

**The panel appears exactly once, at step 14, after service is restored.** That is the structural change: the control plane is a convenience for steady state, not a dependency of recovery. **This must be rehearsed once with the panel deliberately unused** (Phase 7.9) — a cold-start path that has only ever been reasoned about is a hypothesis, and this document's own rule is that a hypothesis is not a commitment.

**Why the lifeboat is a cloud instance.** Unchanged and still correct: ordering a replacement AX42 is a ticket, sometimes a queue, occasionally days, and a hardware fault on dedicated is a Remote-Hands ticket, not a 60-second rebuild [B6]. The dedicated box is the steady-state home; **cloud is the lifeboat.** What v2 adds is that the lifeboat now has an engine.

**Supporting requirements for DR-3:**
- **Digest-pinned images in GHCR, retained** — the last known-good set must survive; **and it must be *periodically pulled* to prove it still exists and is still pullable** (a monthly pull, recorded). Dokploy rollbacks have been registry-based since v0.26.0 and rollback images are no longer stored locally [C5]. ⚠️ GHCR retention/egress terms **UNVERIFIED** — D-9.
- **A checked-in, secret-free manifest** (§7.0.3) that a human can run with `docker compose` on a bare host.
- DNS TTL **300 s** on every A/AAAA record, permanently (Appendix A) — and, per Finding 19, **TTL is not a propagation guarantee.** ⚠️ Ownership, current TTLs, and registrar availability are **UNVERIFIED**. Requirements: **DNS provider credentials and account-recovery material go in the break-glass package (D-12)**; the cutover updates **A and AAAA together** (a stale AAAA keeps IPv6 clients pinned to a dead host — a failure mode that looks like "it works for some users"); the update is **scripted, not clicked**; and the rehearsal **measures** resolver behaviour rather than assuming 300 s.
- `rclone`/`restic`/registry credentials must exist **outside** the production host, in the secret store (D-4/D-12). A DR that needs a credential that only lived on the dead box is not a DR.

### 4.8 Restore rehearsal → gate E-3

**E-3 (production migration rehearsal) cannot close without a demonstrated restore.** Rehearsal, executed on the production cluster **before tenant #1 onboards**:

| Step | Evidence produced |
|---|---|
| 1. Provision a throwaway tenant on production; confirm the DB is named `izipostenant_<uuid>` (validates §3.2) | `\l` output |
| 2. Seed it with a known fiscal fixture: ≥ 20 receipts, ≥ 1 Z-report, ≥ 1 document set | pre-restore row counts for `pos_receipts`, `documents`, `audit_events`, `journal_entries`, `fiscal_events` (five fiscal tables, per `2026-05-12-migration-audit-and-rollback.md:20-30`) |
| 3. Let one hourly cycle run untouched | cron log with wall-clock duration; `SHA256SUMS` |
| 4. Walk the **full §4.4b0 integrity chain** on that cycle — attestation → `manifest_sha256` → `sha256sum -c SHA256SUMS` → manifest/SHA256SUMS cross-check | verification output for **all four** steps. 🚨 **v4: a bare `sha256sum -c` is no longer sufficient evidence** — it does not cover `MANIFEST.json`, and the marker is the thing a false-completion attack or a half-failed promote corrupts |
| 5. **Drop the tenant DB** | `DROP DATABASE` confirmed |
| 6. Restore per §4.6 — serial, with the Timescale pairing | full command transcript |
| 7. Row counts match step 2 **exactly** | side-by-side diff |
| 8. Fiscal chain verifiers, real commands, `--actor-id`, exit 0 | verifier output |
| 9. Pull the same archive **from Object Storage** and from **Storage Box** and re-verify checksums | proves the offsite legs, not just the local dir |
| 10. Time the whole thing | the measured number that either confirms or falsifies the §4.1 RTO |
| 11. Drop the throwaway tenant; confirm the DB is gone and backups stop enumerating it | proves clean teardown |
| **12. 🚨 Skew test** (v2, Finding 7): create a tenant, suspend another, and delete a third **while a cycle is running**; then attempt a cluster restore from that cycle | the restore **detects and reports** orphan/missing databases in both directions (§4.4c) instead of silently producing a broken set |
| **13. 🚨 Both Timescale branches** (v2, Finding 11): restore one archive whose manifest declares `timescaledb` and one whose manifest declares none | the pre/post pair runs in the first and **does not run at all** in the second; both verify clean |
| **14. 🚨 Fence + drain test** (v2 Finding 9; rewritten in v3 for round-2 F9; rewritten in v4 for round-3 R3-N1; **extended in v5 for round-4 BLOCKER 1 — the v4 rehearsal did not exercise the master-timeout defeat**): (a) with **Phase 0.8k applied** (supervisor `timeout` = 3900), start a job for the target tenant whose runtime **exceeds the OLD supervisor timeout (> 60 s) and three healthcheck intervals (> 90 s)** — deliberately in the window where a repo-default master would abandon it — then execute Mode B steps 0–6 **while it is running**; (b) during steps 7–10, issue an API request for the target tenant and dispatch a fresh queued job for it; (c) after the drain, `systemctl start erp-backup.timer` early **on purpose** and confirm the fence detects the reconnect; (d) kill a worker mid-job (`docker kill` one worker process) and re-run probe A; (e) run the whole fence once with `stop_grace_period` deliberately set **below** the job's runtime; 🚨 **(f) v5 — the RED baseline: run the same > 60 s job once with the supervisor `timeout` reverted to `60`** and confirm the master exits 0 while the job is unfinished (this is the defeat, proven present before it is proven fixed) | (a) the job's own log shows **completion, not a mid-transaction abort**; `docker service ps` shows the **recorded task ID** in `Shutdown/Complete`, **zero running tasks, and no task id outside the pre-stop baseline at any point** (v5 full-set assertion); container exit code is **0, not 137**; probe A reaches `reserved == 0` only afterwards; (b) the API request gets **403 `ORGANIZATION_UNAVAILABLE`** (not 503 — §4.6a) and the job is not consumed; `pg_stat_activity` for that datname stays at **zero** throughout the drop/restore window; (c) probe B **sees the backup backend and exits non-zero** — the test fails if the fence proceeds; (d) probe A stays non-zero because the orphan **remains reserved** (it does not migrate with no worker popping — §4.6a residual table) and the fence **refuses to proceed**; (e) exit code **137** appears and the runbook's abort path fires — this is how the team learns what a too-short grace period looks like *before* it happens under pressure; 🚨 **(f) the master exits 0 with the job still running and the `role`'s row count is UNCHANGED afterwards** — demonstrating that a non-zero final count is NOT sufficient evidence of a drain, only the paired "job log shows completion + supervisor timeout ≥ job" is |
| **15. 🚨 Globals credential test** (v2, Finding 10): restore `globals.sql` into a scratch cluster and authenticate a role with its original password | pass ⇒ the backup identity captures password material; **fail ⇒ credential rotation becomes a mandatory documented step of every cluster restore** |

Steps 9 and 10 are the ones people skip. **A backup you have never restored *from the offsite copy* is a hypothesis.** Repeat monthly thereafter, one tenant, into a scratch database.

### 4.9 🚨 Full-host rehearsal — the real RTO gate (v2, Finding 18)

**The §4.8 single-tenant rehearsal is NOT the RTO gate.** v1 made an 11-step one-tenant restore the only required rehearsal and then advertised a four-hour *total-host* RTO on the strength of it. A one-tenant restore measures one `pg_restore`. It measures nothing about provisioning, image pulls, offsite download of a whole cycle, media restore, or DNS — which are, between them, most of the budget.

**Phase 7.9 — executed once before launch, then quarterly:**

| # | Step | What is measured |
|---|---|---|
| 1 | Provision a throwaway cloud instance | p50/p95 seconds |
| 2 | **Panel deliberately unused.** Bootstrap Docker + the checked-in manifest by hand (DR-3 steps 2–6) | minutes — **this is the Finding 17 rehearsal** |
| 3 | Pull every production image digest from GHCR | seconds **and bytes transferred** (feeds D-9's egress question) |
| 4 | Download the full latest cycle from **Object Storage**, then repeat from **Storage Box** | **effective Mbit/s** — the single largest unknown in the RTO budget |
| 5 | Full cluster restore, serial, every database | minutes **and seconds per GB**, so the number extrapolates as data grows |
| 6 | Media restore (L4a) + object-count and sample-checksum verification | minutes |
| 7 | `appstorage` restore | minutes |
| 8 | Full V-suite + fiscal verifiers | minutes |
| 9 | DNS cutover (A **and** AAAA) against a test hostname; observe from **at least two networks** | **observed** propagation, not the TTL value (Finding 19) |
| 10 | Operator wall-clock, including thinking time and one deliberate mistake-and-recovery | the honest total |

**The p95 of step 10 is the committed total-host RTO.** It replaces the ≤ 8 h placeholder in §4.1 and requires owner acceptance under D-14. Repeat at representative data size — a rehearsal at 272 MB (itself ⚠️ UNVERIFIED) does not validate an RTO at 40 GB, and §9.1 says that gap is a matter of tenant count, not of years.

---

## 5. Secrets design → gate E-1

### 5.1 Store selection (D-4)

| | 1Password (Business) | Doppler | Vaultwarden (self-hosted) |
|---|---|---|---|
| Cost | ~$8/user/mo | free tier, then ~$18/user/mo | €0 + a host |
| Human recovery | ✅ Recovery Kit, account recovery | ⚠️ SaaS account recovery only | ❌ **you own recovery entirely** |
| CLI injection | ✅ `op read` / `op run` — no plaintext file ever | ✅ best-in-class (`doppler run`) | ⚠️ `bw` CLI, workable |
| Audit trail | ✅ per-item access history | ✅ | ⚠️ minimal |
| Sharing with a second human | ✅ vaults, granular | ✅ | ✅ |
| **Circular-dependency risk** | none | none | 🚨 **if it runs on infrastructure you are restoring, you cannot read the credentials needed to restore it** |
| Fit here | **best** — one release owner, needs recovery + audit + a CLI that feeds a Dokploy API call | good, but adds a second SaaS in the deploy path for a benefit (dynamic sync) we don't need at one tenant | wrong shape for break-glass |

**Recommendation: 1Password.** The disqualifier for Vaultwarden is not cost or quality — it is that a break-glass store must never share a failure domain with the thing it unlocks. Doppler is genuinely better at machine delivery, but Dokploy already *is* the secret-delivery mechanism (encrypted at rest since v0.29.12 [C5]); what we need from the store is durable human-owned custody, recovery, and audit.

### 5.2 Inventory (E-1 scope)

`docs/security/secret-rotation-2026-05-12.md:53-67` — 9 rows / 12 variables, every one requiring `status = revoked` before tenant #1 onboards. Adjusted for this design:

| Variable | Severity | Production action |
|---|---|---|
| `APP_KEY` | Critical | **Generate fresh for production.** Never reuse staging's. `entrypoint.sh:18-23` hard-fails without it |
| `DB_PASSWORD` / `DB_CENTRAL_PASSWORD` | Critical | Fresh. 🔧 **v3: this is `izipos_app`, which does NOT hold `CREATEDB`** (§3.4c) |
| **`DB_PROVISIONING_PASSWORD`** | **Critical — NEW in v3** | `izipos_provisioner` (`CREATEDB`). **api service only**, never project-level, never on worker/scheduler/websocket (§3.4c) |
| `REDIS_PASSWORD` | Critical | Fresh |
| `MAIL_PASSWORD` | High | New, from D-5's provider |
| `AWS_ACCESS_KEY_ID` + `AWS_SECRET_ACCESS_KEY` | Critical | 🚨 **v3 CORRECTION (round-2 F24).** v2's inventory called these *"MinIO root creds"* while §3.4b made them the **bucket-scoped application key**. The two statements contradicted each other, and the wrong one would have been provisioned. **Binding: `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` are the BUCKET-SCOPED MinIO service account** — `s3:GetObject/PutObject/DeleteObject/ListBucket` on the **media bucket only**, no admin API, no policy or user management, no other bucket, no object-lock configuration. They are project-environment variables, as today |
| **`MINIO_ROOT_USER` + `MINIO_ROOT_PASSWORD`** | **Critical — NEW row in v3** | 🚨 **A DIFFERENT credential from the row above.** Held in 1Password; injected as a **service-level override on the minio Application only** — never project-level, because project-level inheritance would hand root to every service (§3.4b, §5.3) |
| **MinIO backup identity** | **High — NEW row in v3** | The third of §3.4b's three identities: write to the backup prefix only. Lives in the host-side rclone config (chmod 600), **not in any container env** |
| **`MEILISEARCH_KEY`** | High | **REMOVED from scope** — no Meilisearch in production (§3.7). Record the removal in the E-1 ledger as a scope change, not as a silent skip |
| `REVERB_APP_SECRET` | High | Fresh |
| `REVERB_APP_ID` / `REVERB_APP_KEY` | Medium | Fresh. `REVERB_APP_KEY` must **also** be passed as the web `VITE_REVERB_APP_KEY` build arg (§3.4) — the same value in two places, and a rotation must rebuild the web image or realtime breaks |
| `SENTRY_LARAVEL_DSN` | Medium | New production project |
| **`RESTIC_PASSWORD`** | **Critical — NEW** | Not in the original inventory. Lose it and every Storage Box backup is permanently unreadable |
| **Object Storage S3 keys** | **Critical — NEW** | rclone credentials |
| **Dokploy env-encryption keyring** | **Critical — NEW** | §4.3 L6 |
| **Hetzner Robot / Cloud API token** | **High — NEW** | Needed for DR-3 (provision the lifeboat) |
| **Production host SSH private key** | **Critical — NEW** | Dokploy holds a copy; the owner must hold an independent one |

> **Nine NEW rows** (five added in v2 — `RESTIC_PASSWORD`, Object Storage keys, the Dokploy keyring, the Hetzner API token, the host SSH key; four added in v3 — `DB_PROVISIONING_PASSWORD`, `MINIO_ROOT_USER`/`_PASSWORD`, the MinIO backup identity, and the ACME account key of §7.0.3a) exist because this design adds infrastructure the May inventory predates. **They must be appended to the E-1 inventory before that gate can honestly close** — a gate that certifies "every row revoked" against a stale row list certifies nothing. 🎫 The append is an **owner action** on `docs/security/secret-rotation-2026-05-12.md`; this document cannot make it, and E-1 cannot close until it is made.

### 5.3 Injection path

```
1Password vault "Synerivia — ERP Production"
        │  op read (operator laptop, interactive, never written to disk)
        ▼
Dokploy project-environment variables  ──encrypted at rest, AES-256-GCM (v0.29.12)
        │
        ▼
Container env at deploy
```

Rules:
- Secrets are entered **once**, at the Dokploy **project-environment** level, and referenced by services. Never per-service duplicates — that is how a rotation half-lands.
- Use `application-update` with `env`, **not** `application.saveEnvironment` (the MCP wrapper 400s [A2]).
- **No `.env` file on the production host. Ever.** `createEnvFile` stays off.
- **Exception (v2, §3.4b):** the **MinIO root credential** is a *service-level* variable on the minio Application, never a project-level one. Project-level inheritance is the whole point of the rule *and* the whole problem for a root credential.
- Rotation cadence: **annual, plus immediately on any operator offboarding or suspected exposure.** Every rotation writes one row to the E-1 Verification-Evidence ledger (`:254`).

#### 5.3a 🚨 What E-1 does NOT do (v2, Finding 25)

The injection path above **ends in container environment variables**, and no amount of at-rest encryption in the panel changes that. Encryption at rest protects the panel's database; it does not prevent the panel itself, the panel API, `docker inspect`, a privileged host user, or a compromised application process from reading deployed plaintext. E-1's ledger validates **revocation of old credentials** (`docs/security/secret-rotation-2026-05-12.md:53-67,248-274`); it does not change runtime custody.

**Reframe, binding:** E-1 is a **rotation and custody-improvement gate**, not a "plaintext risk eliminated" gate. Closing it does not license the claim that production secrets are unreadable at runtime. What actually reduces the exposure:

| Control | Status in this design |
|---|---|
| **Least-privilege per-service identities** rather than one shared credential | §3.4b (MinIO), §3.4c (Postgres) — **new in v2** |
| **Minimise project-wide inheritance** — a variable at project level is readable by every service in the project | ruled: root/admin credentials never go project-level |
| **Restrict panel roles and host Docker access** | ⚠️ panel RBAC capabilities **UNVERIFIED**; host-side, only the named operators have Docker socket access |
| **Redact panel/API/log output** | ⚠️ Dokploy redaction behaviour **UNVERIFIED** — verify before treating panel logs as safe to share in a ticket |
| **Rapid revocation procedure after panel or host compromise** | §5.5 — must be written, not improvised at 2 a.m. |
| **No `.env` file on the host, ever** | ruled; `createEnvFile` stays off. *(The DR-3 bootstrap materialises an env file transiently on the lifeboat host from 1Password; it is deleted after `docker compose up` and the credentials are rotated post-incident.)* |

#### 5.3b Keyring and panel-backup ordering (v2, Finding 21)

v1 upgraded Dokploy in Phase 0.2 but did not export and vault the environment-encryption keyring until **Phase 4.6** — while simultaneously describing loss of that keyring as fatal (§4.3 L6). That is the wrong order: the riskiest operation ran before the artefact that makes it recoverable was captured.

**Corrected order — and 🚨 v3 (round-2 F21) renumbers the §8 phase table to MATCH, because v2 corrected the prose here while the execution table still listed `0.2 upgrade` before `0.3 export/verify`. An operator following the table could still have performed the unsafe order:**

| # | Step | §8 step |
|---|---|---|
| 1 | Create the 1Password vault (D-4) **first** | **0.2** |
| 2 | **Export the CURRENT env-encryption keyring and take a panel backup; verify both** — verification means a **decrypt test against one known secret**, not "the file downloaded" | **0.2** |
| 3 | Record the current panel version and confirm rollback support ⚠️ **UNVERIFIED** | **0.2** |
| 4 | **Then** upgrade to ≥ v0.29.13 (D-8) | **0.3** |
| 5 | Validate after upgrade: both existing remote servers still reachable **and** existing secrets still decrypt | **0.3** |
| 6 | Only then register the production server | 1.5 |

⚠️ Current panel version, upgrade behaviour, CVE details, rollback support, and existing key custody are all **UNVERIFIED** from this repository.

**Patch policy (also missing from v1):** a standing monthly OS/container/base-image patch window, and **pinned image references** — `minio/minio:latest` and `minio/mc:latest` in the checked-in compose (`docker-compose.dokploy.yml:105-127`) are mutable tags and must be pinned to tested versions or digests in the production manifest (§3.3 row 9 already requires this for `mc`).

### 5.4 🚨 Break-glass package and the second custodian (v2, Finding 20 — this is D-12)

**One human with one 1Password account is not a break-glass plan.** The launch-gate document names a single owner for every row (`docs/handoff/OWNER-manual-launch-gates-2026-07-31.md:18-25,37-48`), and v1's secret list omitted 1Password recovery material, Dokploy account recovery, GitHub org/package recovery, DNS access, alerting credentials, and the `DOKPLOY_API_KEY` that Phase 6.4 later requires. ⚠️ 1Password availability, recovery behaviour, and CLI characteristics are **UNVERIFIED** from this repository.

**Package contents (offline, sealed or on an encrypted offline volume; held by the second custodian):**

| Item | Why it is in the package |
|---|---|
| 1Password **Recovery Kit** + account recovery procedure | without it, everything below is unreachable |
| Dokploy account recovery (email, MFA backup codes) | DR-4 |
| GitHub **org** recovery + packages/GHCR read token | DR-3 step 3 — no images, no recovery |
| **DNS provider** credentials + account recovery | DR-3 step 12; Finding 19 |
| Hetzner **Robot** + **Cloud** API tokens | DR-3 step 1 |
| Production host **SSH private key** | independent of the panel's copy |
| `RESTIC_PASSWORD` | lose it and every Storage Box backup is permanently unreadable |
| Object Storage keys (read/restore-capable, distinct from the host's write-only key) | DR-3 step 7 |
| **Dokploy env-encryption keyring** (L6) | §5.3b |
| Alerting (healthchecks.io, UptimeRobot, Telegram bot) + mail-provider credentials | so the successor can see and be told what is happening |
| `DOKPLOY_API_KEY` | Phase 6.4 |
| A one-page pointer to this document's §4.7 DR-3 table | the procedure is useless if it cannot be found |

**Rehearsal (Phase 7.10):** the second custodian, **from a clean machine**, with the production host and the primary operator's laptop both assumed unavailable, uses only the package to: log into 1Password, log into GHCR and pull an image, read a backup object from Object Storage, and reach the DNS console. **No production change is made** — the rehearsal proves *access*, and it is the only way to find out that one credential in the chain was never actually written down.

### 5.5 Panel compromise is a three-host incident (v2, Finding 22)

The Dokploy panel holds SSH access to AX42 (Synerivia platform), staging, and — after Phase 1.5 — production. ⚠️ The actual key material and live panel configuration are **not in this repository** and are **UNVERIFIED**. What is certain is the shape: key-only SSH does not reduce blast radius if the panel holds one reusable root-capable key for all three hosts.

**Requirements:**

| # | Requirement |
|---|---|
| 1 | **A unique key per host.** No private key reused across hosts, ever |
| 2 | A **restricted automation account** per host rather than direct root, with an explicit sudo command policy, wherever Dokploy permits it ⚠️ **UNVERIFIED whether it does** — establish before Phase 1.5 |
| 3 | **Source-IP restriction** on SSH where the panel's egress addresses are known and stable ⚠️ UNVERIFIED |
| 4 | **Host-key verification** on the panel side; a changed host key is an incident, not a prompt to accept |
| 5 | A written **rotation and revocation** procedure, rehearsed |
| 6 | An **inventory** stating which principal can reach which server — one table, kept current |
| 7 | 🚨 **The incident runbook treats panel compromise as a THREE-HOST incident**: rotate every host key, every credential the panel ever held, and every application secret stored in it. Not "check whether production was affected" — assume it was |

Item 7 is why D-8 (upgrade before registering production) is a hard block rather than hygiene: registering a production server on an unpatched panel with known SSH-key-disclosure and privilege-escalation CVEs hands an attacker the production host along with the other two.

### 5.6 Killing the `/tmp` folder

`claude/deploy-runbook.md:91`:
> *"Per-service secrets from the Apr 25 first deploy live in `/tmp/syneriva-deploy-secrets/` on the operator's laptop (chmod 600, not committed). Move to a real password manager and remove the `/tmp/` copy as part of the post-deploy cleanup (T5)."*

This is a **Synerivia platform** artifact, not an ERP one — but it is on the same laptop that will hold production ERP credentials, and `chmod 600` in `/tmp` is not a control: on macOS `/tmp` is world-traversable, indexed, and cleared unpredictably on reboot. It is simultaneously a **disclosure** risk and a **loss** risk.

**Migration plan (owner action, before D-4 is used for anything ERP):**

| # | Step | Verification |
|---|---|---|
| 1 | Create 1Password vault `Synerivia — Platform Production`; import every item from `/tmp/syneriva-deploy-secrets/`, one item per credential with its service name | Item count == file count |
| 2 | Verify each imported value against the live Dokploy env for the corresponding AX42 service | Spot-check ≥ 3, byte-equal |
| 3 | `rm -rf /tmp/syneriva-deploy-secrets/` | `ls` → no such file |
| 4 | Confirm no shell history / editor swap / backup copy survives: `grep -rl syneriva-deploy-secrets ~ 2>/dev/null` | empty |
| 5 | Update `claude/deploy-runbook.md:90-91` to point at the vault | **Orchestrator action — outside this doc's write scope. Ticket it.** |
| 6 | Treat every credential that ever sat in `/tmp` as **exposed** and rotate it | rows in the E-1 ledger |

Step 6 is not paranoia. A credential whose custody chain includes "a world-readable temp directory for three months" has no provable custody chain.

---

## 6. Observability

**Principle: nothing that monitors production may live only on production, and nothing critical may depend on the Dokploy panel** [C4].

| # | Signal | Mechanism | Lives where | Alerts to (D-6) | Threshold |
|---|---|---|---|---|---|
| **O-1** | **External uptime** — `https://riserpos.app/health`, `https://api.riserpos.app/health` | **UptimeRobot free** (50 monitors, 5-min interval) | **Off-provider SaaS** | Telegram + email | 2 consecutive failures |
| **O-2** | **TLS expiry** | UptimeRobot cert monitor | off-provider | Telegram | 14 days out |
| **O-3** | **Backup dead-man** 🚨 **v3: keyed on the AGE OF THE LAST `complete` MANIFEST**, not on cron exit status and not on the existence of a cycle directory (round-2 F7/N2) | **healthchecks.io free** — §4.4d step 11 pings **only after the PROMOTE** (§4.4b1 phase 3), i.e. only when both offsite legs have verified. 🚨 **v5 (round-4 BLOCKER 2 fix a): the ping is downstream of B4a, so it also requires the `$COMPLETE_SNAP` inventory-verify to have passed** — a cycle that staged, failed to upload, OR whose complete snapshot failed inventory verification (`exit 6`) produces **no ping**, which is the correct signal | **off-provider** | Telegram + email → escalation ladder (§4.4d item 14) | no ping in 90 min (hourly job + 30 min grace) |
| **O-3b** | 🚨 **NEW in v3 — offsite completeness audit. 🚨 v4 (round-3 finding 4.3): it now audits BOTH LEGS, because v3's version could not detect the failure it was named after.** v3 read only the newest Object Storage `MANIFEST.json`. If Object Storage received `complete` while restic's marker snapshot was missing, staged-only, or disconnected from its data snapshot, O-3b still read a document **asserting both legs verified** — it audited the assertion, not the property. v4 checks: **(a) leg A** — read the newest remote `MANIFEST.json`, assert `result == "complete"` and both `offsite.*.state == "verified"`; **(b) leg B** — `restic snapshots --tag "cycle=<TS>" --json`, assert the `role=complete` snapshot **and** the `role=attestation` snapshot exist, that the attestation names that complete snapshot **and** the data snapshot, that the manifest hash inside the complete snapshot matches the attestation's `manifest_sha256`, **and 🚨 v5 (round-4 BLOCKER 2 fix a) — that a fresh `restic ls --json` inventory of the complete snapshot matches the attestation's `inventory_sha256`** (§4.4b2 selection rule 2 — O-3b performs the SAME binding check the restore path does, not just `manifest_sha256`) | host cron, **reads both remotes** | on-box check, **off-box alert** | Telegram | **any leg** whose newest cycle is `staged`, unreadable, missing its pair, internally inconsistent (manifest OR inventory hash mismatch), or older than 90 min — **including "leg A says complete but leg B has no bound complete snapshot"**, which is exactly the state v3 was blind to |
| **O-4** | **Scheduler dead-man** 🚨 | A `->everyFiveMinutes()` heartbeat in `apps/api/routes/console.php` that curls a healthchecks.io URL. **This is the design answer to "`onFailure` logs land in the scheduler container and nobody reads them."** | off-provider | Telegram | no ping in 15 min |
| **O-5** | **Scheduler task failures** | `LOG_STACK=daily` on the shared `appstorage` volume → readable via `docker exec` **and** surviving container restarts; plus Sentry capture on `->onFailure()` | on-box + Sentry | Sentry → Telegram | any |
| **O-6** | **Disk and capacity** 🚨 **v3: widened from `df -P /` to the whole Appendix E budget** | Host systemd timer every 15 min running `/usr/local/sbin/erp-capacity-check.sh`: `df -P` (bytes), `df -Pi` (**inodes**), and **every class in Appendix E against its own ceiling** — **and it pings a healthchecks.io URL only while ALL classes are below threshold**, so a full disk, a breached class, *and* a dead host all alert | on-box check, **off-box alert** | Telegram → escalation ladder (§4.4d item 14) | **disk warn 75 %, critical 85 %; inodes 80 %; plus each Appendix E class's own warn/critical** |
| **O-7** | **Queue depth / Horizon** | Horizon dashboard at `api.riserpos.app/horizon` (auth-gated), plus a scheduled check on `Redis::llen` per queue | on-box | Telegram | > 500 pending, or > 0 in `failed_jobs` for 15 min |
| **O-8** | **Horizon supervisor alive** | Container healthcheck `horizon:status \| grep -q running` (already in the image) + O-7's depth check | Docker + panel | Telegram | 3 failed healthchecks |
| **O-9** | **Application errors** | Sentry (`SENTRY_LARAVEL_DSN`) | off-provider | Telegram | new issue / regression |
| **O-10** | **Container + host metrics** | Dokploy monitoring (Cloud-only for remote servers [C3]) | panel | Telegram | CPU > 85 % 10 min; mem > 90 % |
| **O-11** | **Backup + volume-backup events** | Dokploy notifications, event types "database backups" + "volume backups" [C5] | panel | Telegram | any failure |
| **O-12** | **Log retention** | `LOG_STACK=daily`, `LOG_DAILY_DAYS=14`, `LOG_LEVEL=info` + Docker `max-size=50m max-file=5` | on-box | — | — |

**Why the dead-man pattern (O-3, O-4, O-6) rather than failure alerts.** A failure alert requires the failing thing to be alive enough to send it. The three failure modes that actually hurt here — the backup cron silently stops, the scheduler container dies, the disk fills so nothing can write — are all modes where the failing component *cannot* alert. Ping-on-success inverts it: **silence is the alarm.** healthchecks.io's free tier (20 checks) covers all three with room to spare, and it is off-provider, so it also survives a Hetzner-wide event.

⚠️ **Dokploy monitoring retains only 2 days** (`retentionDays: 2` on the existing servers [A1]) and is panel-dependent. O-10 and O-11 are *convenience*. O-1/O-3/O-4/O-6/O-9 are the ones that must never be removed.

### 6.1 🚨 None of this exists yet — it is Phase 0 work, not Phase 5 (v2, Finding 29)

v1 placed the monitoring code in Phase 5, *after* production was already serving in Phase 3, and simultaneously made Phase 3.6 require a V-suite whose V-5 depends on the Phase 5.3 heartbeat. Verified current state:

| Monitor | Status today |
|---|---|
| O-4 scheduler heartbeat | **absent** — `routes/console.php:15-149` has the schedules and no heartbeat; **no file in the repository contains `hc-ping.com` or `healthchecks.io`** |
| O-7 Redis queue-depth check | **absent.** The existing admin monitoring counts database `jobs` rows (`MonitoringService.php:242-252,604-625`) — 🚨 **but production uses the Redis queue driver, so that counter reads zero regardless of backlog.** It is not merely missing; the thing that looks like it is measuring the wrong store |
| O-6 disk dead-man | **absent** |
| `.github/workflows/deploy-production.yml` | **absent** |

**Ruling: every monitor moves into Phase 0**, before the dev→main promotion, so the release commit contains them (§8). A production system whose alerting lands in a later phase is unmonitored for exactly the window when it is most likely to break.

### 6.2 Gaps v1 left in the alerting design (v2, Findings 28, 29, 32)

| # | Gap | Fix |
|---|---|---|
| 1 | **Panel backup notifications cannot report a total panel outage** (O-11), and the only off-panel dead-man covered the *database* cron — not the nightly MinIO/appstorage volume backups | **O-13 (NEW): an independent off-panel dead-man for volume-backup age.** A host-side check reads the age of the most recent volume-backup artefact and pings healthchecks.io only while it is fresh. (The L4a media sync is already covered by O-3's cycle ping — which is a further reason media moved onto the host cron.) |
| 2 | **No canary on the alert path itself.** An alert channel that has silently broken is worse than no channel, because it reads as "all quiet" | **O-14 (NEW): a synthetic alert through *both* channels on a fixed schedule (weekly), requiring acknowledgement.** If the canary is not acknowledged, or the integration itself fails, that is an alert. |
| 3 | **No escalation.** One human receives everything; if that human is asleep, on a plane, or unwell, the dead-man has a dead man on the other end | 🚨 **v3: the interval is now DEFINED — see the four-step ladder in §4.4d item 14 (0 min ch.1 → 15 min ch.2 → 30 min second custodian → 60 min logged as an unacknowledged critical, RPO formally void from the FIRST unanswered alert).** It applies to every critical alert (O-1, O-3, O-3b, O-4, O-6, O-13), not only backups, plus a runbook for "alert received while the owner is unavailable" stating what a second human may safely do alone |
| 4 | **Disk-full can prevent local logging and dumping simultaneously**, so the evidence of the failure is destroyed by the failure | O-6 already pings off-box; **plus** the §3.1 ruling that backup staging is on its own mount, **plus** the §4.4d free-space preflight |
| 5 | Alert **acknowledgement** was undefined | every critical alert (O-1, O-3, O-4, O-6, O-13) requires an explicit ack; an unacked alert escalates per row 3 |

### 6.3 Log and disk retention must be budgeted, not asserted (v2, Finding 30)

v1's "design total < 150 GB, ~70 % free" is withdrawn (§3.1). The omitted classes are individually small and collectively decisive:

| Class | Bound today | Note |
|---|---|---|
| Docker container logs | 5 × 50 MB **per container** → ~2 GB across eight persistent containers | **excludes** Traefik, Dokploy agents, build containers |
| Laravel daily logs | 14 files, **no per-file size cap** (`config/logging.php:53-74`) | 🎫 a `LOG_DAILY_MAX_SIZE`-equivalent or size-based rotation is a code follow-up; `LOG_LEVEL=info` (§3.4) is the interim control |
| In-app tenant backups on `appstorage` | 14 successful dumps **per tenant** (`config/tenant_backups.php:18-30`; `TenantBackupService.php:291-313`) | grows linearly with tenants **and** with per-tenant data — the fastest-growing item nobody thinks about |
| Docker images + build cache | **unbounded without a policy** | now smaller than v1 assumed, because production **pulls** images instead of building (decision 13) — but old digests still accumulate. **Weekly `docker image prune` with a keep-last-N policy** |
| Local dump staging | **capacity-ceilinged at 20 % of its own mount** (§4.4d) | the v1 "48 sets" count is a maximum, not a promise |
| Failed partials | cleaned every run (§4.4d) | v1 left them forever |

**Alerts fire on bytes and on inodes**, not only on `/` percentage — many small media objects and many small log files exhaust inodes before they exhaust bytes. **Appendix E is the line-item budget**; it is a launch deliverable, not an appendix nicety.

**Explicitly not in scope for launch:** centralized log shipping (Loki/ELK). At one tenant on one host, `docker exec` + the `appstorage`-persisted daily logs + Sentry are sufficient, and a log stack is another 1–2 GB of RAM and another thing to run out of disk. Revisit at 10 tenants or when a second host appears (§9). **Note this interacts with §3.5:** the accepted ≤ 24 h `appstorage` log loss after a host disaster is only acceptable *because* errors reach Sentry independently. If Sentry is ever removed, this decision must be revisited.

---

## 7. Deploy pipeline and promotion flow

### 7.0 🚨 Phase 0 prerequisite: immutable images and a panel-independent cold start (v2 — Findings 16, 17, 31, 36)

Four of the review's findings — a DR-3 that depends on non-existent registry images, a DR-3 that depends on the panel, five source builds serialised on the database host, and running containers tied to no attested commit — are **one missing artefact**. This section creates it, and it is **Phase 0 work: it lands before the dev→main promotion and before any production Application is created.**

#### 7.0.1 What the four API "targets" actually are (verified — this corrects an assumption on both sides)

The four API-family services are separate **Dockerfile build targets** (`apps/api/Dockerfile:145-188`), not one image switched by env:

| Target | Differs from `base` by | Entrypoint |
|---|---|---|
| `api` | `EXPOSE 80`, healthcheck `curl -f localhost/health`, `CMD entrypoint.sh` | `docker/entrypoint.sh` |
| `worker` | healthcheck `horizon:status \| grep -q running`, `CMD entrypoint-worker.sh` | `docker/entrypoint-worker.sh` |
| `scheduler` | no healthcheck, `CMD entrypoint-scheduler.sh` | `docker/entrypoint-scheduler.sh` |
| `websocket` | `EXPOSE 8080`, healthcheck `nc -z localhost 8080`, `CMD entrypoint-websocket.sh` | `docker/entrypoint-websocket.sh` |

Two things follow, and both matter:

1. **`CONTAINER_ROLE` is NOT how the roles are differentiated.** It is set only on the `api` service (`docker-compose.dokploy.yml:178`) and only selects **bundled vs split** mode *inside* `entrypoint.sh:231-237` (bundled = one container also running Horizon/Reverb/Scheduler). Any plan built on "one image, `CONTAINER_ROLE` picks the role" would be wrong.
2. **All four targets derive from the same `base` stage and differ only in `CMD`/`HEALTHCHECK`/`EXPOSE` metadata.** `base` already contains **all four entrypoint scripts** (`Dockerfile` copies `entrypoint{,-worker,-scheduler,-websocket}.sh` and chmods them). So the four tags are **one build and one set of layers**, published under four manifests — not four builds. The registry cost and build time of "five images" is, in practice, the cost of **two** (api-family + web).

**Ruling: publish four API tags plus the web tag from a single `buildx bake` / matrix build with a shared cache.** The alternative — one tag with per-service `command:` overrides — is viable *only* if the per-service healthcheck can also be overridden (otherwise `worker` inherits the API's `curl localhost/health` healthcheck and flaps forever). Since keeping the in-image healthcheck is strictly safer and costs nothing extra in storage (shared layers), **four tags is the default**. Revisit only if D-9's registry accounting makes manifests expensive.

#### 7.0.2 The build/push workflow

| Property | Ruling |
|---|---|
| Trigger | `workflow_run` on **CI success**, `branches: [main]` — never on raw push |
| Registry | **GHCR**, per D-9 |
| Tags | `ghcr.io/otospexsolutions/erp-{api,worker,scheduler,websocket,web}:{sha}` **plus** a moving `:main` for convenience that **nothing in production ever consumes** |
| 🚨 **Production reference** | **the immutable digest (`@sha256:…`)**, not a tag. A tag is a mutable pointer; DR-3 pulling `:main` six months later gets whatever `main` means then |
| Dokploy production apps | **`sourceType: docker`** with the digest. **No git provider configured on production apps at all**, so there is no path by which a source build can happen on the database host (Finding 31) |
| Provenance | each image records the release SHA; each running service **exposes or records** the SHA and digest it is running, and the deploy verification asserts it matches the approved CI run (Finding 36) |
| Retention | keep the last N release digests **and** an explicitly pinned `last-known-good`; **pull-test the last-known-good monthly** (DR-3) |
| Web build args | `VITE_API_URL` **and `VITE_REVERB_APP_KEY`** are build inputs, so the **web image is environment-specific by construction**. Its digest is bound to a target environment — do not reuse a staging web digest in production |

#### 🚨 7.0.2a The ONE-TIME BOOTSTRAP EXCEPTION (v3, round-2 N1 / F16 / F17 / F33 — BLOCKER)

**The cycle the re-gate found is real and is not a wording problem.** §7.0.2 says the workflow may push only after successful CI on `main`. Phase 0.8c requires a **digest-pinned** production manifest to be checked in, and Phase 0.9 is the *single* `dev`→`main` promotion. The production digest is a function of the release commit — so it cannot exist inside the same commit that first introduces it. Committing the discovered digest afterwards produces a new commit, which needs its own CI and produces its own digest. There is no fixed point, and there is no pre-existing production workflow or manifest to break the cycle (🚨 **v5, round-4 finding 6 — corrected against `git ls-tree origin/main .github/workflows/` this session:** `origin/main` contains **`ci.yml`, `smoke-test.yml`, `sonarcloud.yml` and nothing else** — `react-doctor.yml` is on `dev`, **not** `main`).

🚨 **v4 (round-3 R3-N3) — v3's version of this exception COULD NOT RUN, and its supersession recreated the very cycle it claimed to break. Both defects are fixed below; read the two "what changed" boxes before the normative text.**

**Change 1 — the dispatcher must exist on the DEFAULT BRANCH first (round-3 finding 3.1).** GitHub's [manual-workflow documentation](https://docs.github.com/en/actions/how-tos/manage-workflow-runs/manually-run-a-workflow) is explicit: a `workflow_dispatch` workflow must exist **on the default branch** to be dispatchable at all. The default remote branch here is `origin/main`, and `.github/workflows/` on it contains only **`ci.yml`, `smoke-test.yml` and `sonarcloud.yml`** (🚨 **v5, round-4 finding 6 — verified via `git ls-tree origin/main .github/workflows/` this session; the earlier list wrongly included `react-doctor.yml`, which is on `dev`, not `main`**). v3 landed the new build workflow on `dev` at Phase 0.8i and dispatched it *before* promoting it to `main` at Phase 0.9 — **there is no dispatchable definition at that moment.** This is a hard execution failure, not a governance nuance.

> **Ruled: option (a) — a minimal BOOTSTRAP DISPATCHER lands on `main` first, in its own separately reviewed control-plane commit (Phase 0.8b1).** It is deliberately small: `workflow_dispatch` inputs (`ref`, `expected_sha`), `environment: production`, 🚨 **the fixed non-cancelling `concurrency: {group: prod-bootstrap, cancel-in-progress: false}` of Change 3 (round-4 BLOCKER 3)**, the `bootstrap_open` guard with its atomic increment-and-re-read, and a single job that **checks out the designated `dev` ref and calls the reviewed build logic from that ref** (a reusable workflow / composite action living on `dev`). The dispatcher on `main` is the *entry point*; the build definition being executed is still the reviewed `dev` one, and the run log records both SHAs.
> **Why not option (b) — implementing the bootstrap job inside the existing `ci.yml`:** it is dispatchable today, which is its only advantage, and it would put registry credentials and a privileged bootstrap path inside the workflow every PR touches. A 30-line dedicated dispatcher with an `environment` gate has a far smaller blast radius, and it is the file the sunset in Change 3 deletes.
> **Rehearsal is mandatory, not implied:** dispatch it once against a throwaway tag pointing at a scratch registry namespace and prove that `ref=dev` executes the **dev** definition. "A new workflow on `dev`" is not runnable, and neither is an untested dispatcher.

**Change 2 — the digests stop being a git artefact, which is what actually breaks the fixed point (round-3 finding 3.2).** v3 moved the cycle rather than breaking it: build `main` commit M1 → obtain M1's digests → commit them as M2 → the provenance-bearing images for M2 have *different* digests, so production runs M1 while `main` is M2 — the exact drift v3's own text said it prevented. **v4 separates source release identity from deployment-state metadata (D-20):** the compose file in git carries `${IMAGE_API}`-style variables with no defaults, and the five digests live in a **signed/attested `release-set.lock.json`** produced by the build, published as a workflow artefact and — 🚨 **v5 (round-4 MAJOR 4): from Phase 4 onward, once the offsite legs exist** — copied to **both** offsite legs with the backup cycle. `main` therefore never carries a commit whose only content is deployment state, and there is nothing to re-commit after a build. Full mechanics and the Phase-0-artefact-only-vs-Phase-4-offsite sequencing: **§7.0.2b**.

**Change 3 — "exactly one" becomes a machine-enforced state record instead of a sentence (round-3 finding 3.3).** v3's normative text said "no re-run, no second bootstrap" and then, thirty lines later, permitted repeated attempts whenever Phase 0.9 came back red, redefining once as "once per environment, not once per attempt". Both cannot be normative. The loosening is *correct* — a red CI on 4,303 commits is the expected case (R-5) — but as written the exception had no closure condition beyond "eventually green", and its stated disable action was not implementable: GitHub can disable a **whole workflow** ([workflow management](https://docs.github.com/en/actions/how-tos/manage-workflow-runs)), not one trigger of a multi-trigger workflow; deleting `workflow_dispatch` in git is another post-build commit (and interacts with Change 2); and environment approvals gate **jobs**, they are not a global revocation.

**Ruling — the exception, verbatim. This block is the normative text; everything after it is explanation.**

> **ONE-TIME BOOTSTRAP EXCEPTION.**
> **Scope.** This exception applies to the initial stand-up of exactly one production environment. 🚨 **v5: the contract is precisely "one environment bootstrap, up to three approved attempts"** — not literal "exactly once" (a red CI on 4,303 commits makes retries the expected case, R-5) and not unbounded. It is **bounded by a state record, not by prose** (below).
> **What is permitted.** The **phase-0 production images** — `erp-{api,worker,scheduler,websocket,web}` — MAY be built and pushed to GHCR from a **designated release-candidate SHA on `dev`** — specifically the SHA that has cleared the §A promotion-readiness checks — **instead of** from a green `main`. The build MUST be triggered through the **bootstrap dispatcher on `main`** (Change 1) with an explicit `workflow_dispatch` naming that SHA, MUST run under the `production` GitHub environment so its **required-reviewer approval is recorded**, and MUST tag every resulting manifest **`bootstrap-<sha>`** in addition to its digest.
> **The state record is the enforcement, and the check is CONCURRENCY-SAFE (🚨 v5, round-4 BLOCKER 3).** A repository-variable/environment record `BOOTSTRAP_STATE` holds `{open: bool, designated_sha, attempts_used, attempt_cap, closed_at}`. Because two dispatches could otherwise read the same `attempts_used`, both pass, and both build, **two mechanisms are required and both are normative:**
> 1. **The bootstrap dispatcher declares a fixed, non-cancelling concurrency group** — `concurrency: {group: prod-bootstrap, cancel-in-progress: false}` — so GitHub serialises runs of this workflow; a second dispatch **queues behind** the first rather than racing it (GitHub Actions [workflow concurrency](https://docs.github.com/en/actions/how-tos/write-workflows/choose-when-workflows-run/control-workflow-concurrency)). `cancel-in-progress: false` is deliberate: a bootstrap build must never be cancelled mid-flight by a newer dispatch.
> 2. **The job's FIRST step is an atomic compare-and-swap, and it happens BEFORE any build credential (`docker login`, GHCR token) is read.** It reads `BOOTSTRAP_STATE`, then — atomically — **INCREMENTS `attempts_used` and RE-READS it**, and gates on the **re-read** value: it fails when `open == false`, when the dispatched SHA ≠ `designated_sha`, or when the re-read `attempts_used > attempt_cap`. `attempt_cap = 3`. Incrementing *before* the gate means a racer that somehow slipped past the concurrency group still sees its own committed count and stops; and because the increment-and-re-read precedes every credential read, **no run can build without first having claimed and observed its own attempt number**.
>
> This makes the contract mechanical, not merely serial-operator-behaviour: **one environment bootstrap, up to three approved attempts** — never "exactly once" and never unbounded.
> **What is NOT permitted.** No other artefact, no other branch, and no build on this path once `open == false`. **A changed designated SHA requires a FRESH owner approval** recorded on the `production` environment — a re-run is never implicit. Exceeding `attempt_cap` requires a **new dated amendment to D-15**, not a retry.
> **Where the digests go.** 🚨 **Not into git.** The build emits the attested `release-set.lock.json` of §7.0.2b, published as a workflow artefact (🚨 **v5, round-4 MAJOR 4: the copy to both offsite legs happens from Phase 4 onward, once those legs exist — Phase 0 is artefact-store-only**); `deploy/production/compose.production.yml` is committed **variable-referencing and digest-free**, and is part of the `dev`→`main` promotion (Phase 0.9). **There is no digest commit, therefore no fixed point.**
> **When the green-`main` rule becomes binding.** The green-`main` rule (§7.0.2 "Trigger") is **binding from the first post-promotion build onward, with no exception**. The first `workflow_run`-triggered build on green `main` supersedes the bootstrap images and emits a new attested lock.
> **Supersession.** At that first green-`main` build, the running services are moved to the `main` digests through the ordinary release-set rotation (§7.0.5) — a **lock swap and a redeploy, not a commit**. The `bootstrap-<sha>` tags are **retained, never deleted** — they are the provenance record of what the environment was first stood up from — but **nothing in production references them after supersession**, and V-10 (§7.4c) fails any service still running a bootstrap digest after that point.
> **Sunset — a transition, and it is tested.** Closure sets `BOOTSTRAP_STATE.open = false` and `closed_at`, **and deletes the bootstrap dispatcher workflow from `main`** (a control-plane commit that touches no application code and triggers no rebuild). It is **closed** when V-10 reports every production service running a digest from the first green-`main` release set. Closure is recorded in the Phase 0 evidence with the date, the superseding CI run URL, and the old/new digest table. 🚨 **The closure transition is rehearsed: after setting `open = false`, dispatch the bootstrap path once and require it to fail at the guard step, before credentials.** An untested kill switch is a decoration.

**Why the conventional two-commit protocol is still not the answer here — and what v4 does instead.** A two-commit protocol (merge, build, commit the digests back to `main`) is the conventional fix, and round 3 was right that v3's rejection of it was self-defeating, because v3 then *performed* a digest commit at supersession anyway. But the objection to it remains real: the metadata commit lands on `main` after production exists and is itself an un-deployed `main` commit, so keeping V-10's invariant true requires a permanently load-bearing, path-filtered "this commit does not need rebuilding" CI rule — a rule that is exactly one careless glob away from exempting real code. **v4 therefore takes the third option: keep `main` free of deployment state entirely (§7.0.2b, D-20).** If the owner declines D-20, the fallback **is** the two-commit protocol, and then it must be adopted *explicitly*: "release SHA" is defined as the source commit whose app contexts were built, the metadata-only pin commit is exempted by an audited path filter, and **v3's claim that no two-commit protocol is needed is struck either way.**

**Audit trail the exception must leave** — 🚨 **all seven in v4** (rows 5 and 7 changed/added), all in the Phase 0 evidence set:

| # | Artefact | Where it lives |
|---|---|---|
| 1 | The **designated SHA**, named by the owner in writing before the build | Phase 0 evidence; the `workflow_dispatch` input value |
| 2 | The **recorded owner approval** on the `production` GitHub environment for that dispatch | GitHub deployment-approval record (immutable, timestamped, names the approver) |
| 3 | The **five `bootstrap-<sha>` tags and their digests** | GHCR; transcribed into the Phase 0 evidence table |
| 4 | The **§A clearance evidence** for that SHA — what was checked, by whom, and its result | Phase 0 evidence |
| 5 | 🚨 **v4 — the attested `release-set.lock.json` for the bootstrap set** (not a digest commit) + the commit that lands the **variable-referencing, digest-free** `compose.production.yml` | workflow artefact + both offsite legs; the compose commit in git |
| 6 | The **supersession record**: first green-`main` CI run URL, new digests, the V-10 output proving no bootstrap digest is still running, and the date | Phase 0 exit evidence |
| 7 | 🚨 **v4 — the closure transition evidence**: `BOOTSTRAP_STATE` before/after, the dispatcher-deletion commit, and **the transcript of the post-closure dispatch that failed at the guard step** | Phase 0 exit evidence |

**Residual risk, stated:** for the window between first deploy and the first post-promotion build, production runs images built from a **`dev`** SHA that never passed the full `main` CI suite as `main`. The mitigations are that (a) the *same* SHA is what the Phase 0.9 promotion drives to green `all-checks-pass`, so the code is CI-proven even though the image predates the merge commit; (b) the owner approval is recorded and attributable; (c) the window is bounded by Phase 0.9 itself, which is the very next step; and (d) **no tenant data exists during that window** — Phase 7.8 (tenant #1 onboarding) is far downstream of the supersession. 🚨 **If Phase 0.9's CI comes back red and the release candidate changes, the bootstrap must be re-run against the NEW SHA and the old `bootstrap-` images must be superseded the same way. "Once" means once per environment, not once per attempt** — a re-run is permitted and must repeat the full audit trail. 🔧 **v4 replaces the last two sentences of that residual.** v3 said the wording was "deliberately looser than exactly one build" and left it there, which is how a one-time exception becomes an open capability. **In v4 the looseness is bounded and mechanical: `attempt_cap = 3`, a fresh recorded owner approval per changed SHA, a guard step that fails before credentials, and a rehearsed closure transition** (the normative block above). A red CI remains the expected case (§7.3, R-5); what changes is that "we are still bootstrapping" is now a value someone has to set, not a state that persists by default.

#### 🚨 7.0.2b The release set is an ATTESTED LOCK, not a checked-in digest (v4, round-3 R3-N3 / finding 3.2 — implements D-20)

**The invariant this preserves:** *every commit on `main` has a corresponding production digest, and every running digest traces to an approved build of a `main` commit.* **The invariant it drops:** *the digests are readable from a git checkout.* That second one is what created the fixed point, and it was never load-bearing — it was convenient.

| Artefact | Lives in | Content | Mutable? |
|---|---|---|---|
| `deploy/production/compose.production.yml` | **git**, secret-free | every image reference is `${IMAGE_API}` / `${IMAGE_WORKER}` / … **with no default and no fallback value**, so an unset variable is a hard failure, never a silent `:latest` | changes only when the topology changes |
| `release-set.lock.json` | **workflow artefact** (retained per §7.0.5's N) **+ both offsite backup legs — 🚨 v5 (round-4 MAJOR 4): the offsite copies exist only from Phase 4 onward**, once the Object Storage bucket (4.1) and Storage Box (4.2) are created and the backup cycle writes it with each run. **During Phase 0 the artefact store is the sole copy** (GitHub is available then; the GitHub-independent path is a DR property, not a Phase-0 one) | `{release_set_id, source_sha, ci_run_url, built_at, images: {api: "sha256:…", …}, bootstrap: bool}` | one per release; **never edited, only superseded** |
| The **attestation** over that lock | GitHub's build-provenance attestation for the five images, plus a signature over the lock file itself | binds `source_sha` → the five digests → the approved run | immutable |
| `deploy/production/RELEASE-SETS.md` | git, append-only | the human-readable **ledger** (§7.0.5): IDs, SHAs, run URLs, dates, `state`. 🚨 **v4: the ledger records that a set exists and what state it is in; it is NOT the source of the digests and is never the thing a deploy reads.** Its updates are ordinary documentation commits with no deployment consequence, so they create no drift | append-only |

**How a deploy consumes it:** the deploy workflow (or the operator, on the panel-independent path) fetches the lock for the target release set, **verifies its attestation/signature**, materialises it as the env file next to `compose.production.yml`, and runs `docker compose up -d`. **A lock that fails verification is not deployed** — no override, because an unverifiable lock is indistinguishable from an attacker-supplied one.

**How a COLD START consumes it** (this is the cost D-20 asks the owner to accept, and DR-3 gains a step for it): the lock is **not** in the git checkout, so the operator fetches it from the workflow artefact store or — if GitHub is unavailable, which is precisely the DR case — **from either offsite backup leg, where it was written with the cycle**. Writing it to both legs is what keeps DR-3 executable with no dependency on GitHub. **DR-3 step 3a: fetch and verify `release-set.lock.json` for the last-known-good set before starting any service.** 🚨 **v5 (round-4 MAJOR 4) — sequencing: the offsite copies do not exist until the backup legs are created at Phase 4.1/4.2 and the backup cycle runs (Phase 4.3b copies the current lock there and rehearses the GitHub-independent cold start). Phase 0's own cold-start dry run (0.8c) therefore materialises the lock from the ARTEFACT STORE, and Phase 0 is explicitly artefact-store-only.** The GitHub-independent-from-an-offsite-leg property is a Phase-4 deliverable, which is correct: there is no total-host DR scenario before backups and offsite legs exist at all.

**Two failure modes, named:**

1. **Lock lost everywhere** (artefact expired, both legs unreadable). Then the running containers still know their own digests — `docker inspect` recovers them — and the ledger names the set. **The recovery path is: read the digests off the running services (V-10 already does), re-sign a reconstructed lock, and record the reconstruction as a deviation.** This is why V-10's output is archived per deploy.
2. **Ledger and lock disagree.** The **lock wins for what is deployed**; the ledger is corrected and the discrepancy is an incident, because it means either a manual deploy or an unrecorded rotation. V-12 is the check that surfaces it (§7.0.5).

#### 7.0.3 The panel-independent cold start (Finding 17)

**Deliverable: `deploy/production/compose.production.yml`, checked in, secret-free.**

| Requirement | Detail |
|---|---|
| Digest-pinned | every image reference resolves to `@sha256:…` at deploy time. 🚨 **v4 (D-20): the digest comes from the attested `release-set.lock.json` (§7.0.2b), not from a value committed in this file** — the file carries `${IMAGE_*}` variables with **no defaults**, so an unset variable is a hard failure and there is no path to a mutable tag |
| No build sections | it cannot fall back to building |
| Secret-free | every secret is `${VAR}` with **no default and no value**; the file is safe in git |
| Complete | all eight persistent services **plus** the one-shot bucket job, the four named volumes, the network, resource limits, and healthchecks — i.e. it is the §3.3 table in executable form. 🚨 **v4: the worker service carries an explicit `stop_grace_period` exceeding the longest job timeout (D-21, §4.6a)** |
| Bootstrap runbook | `deploy/production/BOOTSTRAP.md`: install Docker, `docker login ghcr.io`, 🚨 **v4 — fetch and VERIFY `release-set.lock.json` (artefact store, or either offsite leg — §7.0.2b)**, materialise the env file from 1Password, `docker compose up -d`, **TLS per §7.0.3a — ONE path**, verify |
| 🚨 **Rehearsed once with the panel deliberately unused** | Phase 7.9 step 2. **Until that rehearsal passes, DR-3 is a hypothesis and §4.1's total-host RTO stays at the conservative ≤ 8 h** |
| Kept honest | the manifest and the §3.3 table are diffed at every V-suite run; divergence is deploy-blocking (§3.8) |

**Secondary benefit — DR-4 gets better.** With this manifest on the host, a panel outage no longer blocks a **security hotfix**: pull the new digest and `docker compose up -d <service>` directly. v1 conceded "lost: deploys" during a panel outage; v2 does not have to. 🔧 **v3 (round-2 N5): R-11 in §10 is corrected to match this** — it previously still said a panel outage stops deploys, contradicting this path and DR-4.

#### 🚨 7.0.3a TLS on a cold start — ONE executable path (v3, round-2 F17 residual)

v2 offered *"either Traefik from the same manifest or a temporary certbot/self-signed step"*. Under DR that is not a design, it is a decision left to a tired operator at the worst possible moment. **Ruled:**

> **The cold start brings up Traefik from `compose.production.yml` and obtains certificates from Let's Encrypt over HTTP-01. There is no alternative path in the runbook.**

For that to be executable, **the DNS cutover moves earlier in DR-3** — before the edge starts, not after the app is up:

| Ordering rule | Why |
|---|---|
| DNS `A` **and** `AAAA` for `<apex>` and `api.<apex>` are repointed to the lifeboat **before** `docker compose up -d traefik` | HTTP-01 validates by fetching `http://<name>/.well-known/acme-challenge/…` **at the name's current address**. If DNS still points at the dead host, the challenge fails and the operator is left improvising — which is precisely the branch v2 left open |
| Data services (`postgres`, `redis`, `minio`) and the restore run **before** the edge comes up | Nothing is served until there is something correct to serve; the DNS move is safe because the old host is gone |
| The web/api containers start **after** Traefik has a certificate | avoids a window where the SPA loads over a broken TLS handshake |

**Residual, named:** Let's Encrypt's **duplicate-certificate rate limit** (⚠️ exact current limits **UNVERIFIED** — read from LE's published limits at rehearsal time) is consumed by repeated rehearsals against the *real* production names. **Mitigations, in order:** (1) the quarterly §4.9 rehearsal uses a **dedicated rehearsal hostname**, not the production apex — §4.9 step 9 already says "against a test hostname"; (2) the ACME account key is in the break-glass package (D-12) so a re-issued cert reuses the existing account; (3) if the limit is hit during a real incident, the documented fallback is **the LE staging endpoint plus a browser trust exception for the operator only, while the rate limit resets** — and that is written down as an *incident* fallback, not as a second normal path. 🚨 **The fallback is explicitly NOT "self-signed and carry on"**: a fiscal POS client trusting a self-signed cert is a change to the client's trust store, which is a device-fleet operation, not a DR step.

#### 7.0.4 `VITE_REVERB_APP_KEY` is a build-configuration change, not a Dockerfile fix (Finding 4)

v1 framed this as a Dockerfile defect. It is not: `apps/web/Dockerfile:47-55` **already declares** `ARG VITE_REVERB_APP_KEY` before `RUN pnpm build`. The defect is that **no build path passes it** — the Dokploy compose passes only `VITE_API_URL` (`docker-compose.dokploy.yml:251-257`) and staging passes only `VITE_API_URL` + `VITE_APP_PRODUCT` (`docker-compose.staging.yml:262-269`) — so `apps/web/src/lib/echo.ts:22-31` silently falls back to `local_key` while the server enforces the generated key.

**Phase 0.6 is therefore build-configuration wiring, in four parts:**

| # | Change |
|---|---|
| 1 | Pass `VITE_REVERB_APP_KEY` as a build arg in **every** build path: the CI image workflow, `deploy/production/compose.production.yml`, `docker-compose.dokploy.yml`, and `docker-compose.staging.yml` |
| 2 | 🚨 **Fail the build when it is absent in a production build.** Remove the `local_key` fallback for production builds so a missing key is a red build, not a silently broken realtime feature |
| 3 | **CI inspects the compiled bundle** and fails if `local_key` appears in a production artefact |
| 4 | V-6 (§7.4) keeps it as a permanent deploy canary — with the §3.4a two-publisher test, not just a handshake |

Item 2 is the one that makes this stay fixed. Items 1, 3 and 4 detect the problem; item 2 makes it impossible to ship.

#### 🚨 7.0.5 Release-set rotation and rollback (v3, round-2 N4 / F31 / F32 / F35 / F36)

v2 said "keep the last N digests and a `last-known-good`" and stopped. The re-gate is right that for a single operator, digest pinning **without** a rotation procedure converts supply-chain safety into manual configuration drift: five Dokploy app references plus one checked-in compose file, kept in sync by memory.

**The unit of release is the RELEASE SET, not the image.** Five digests move together or not at all.

| Property | Ruling |
|---|---|
| **Release-set ID** | `rs-<YYYYMMDD>-<short-sha>` — immutable, assigned by the deploy workflow, stamped into the compose file as a comment **and** into each Dokploy app's description field |
| **Where the set is recorded** | Two places with **different authority**, and v4 (D-20) makes the split explicit: the **authoritative, machine-read** record is the attested `release-set.lock.json` (§7.0.2b) — artefact store + both offsite legs; the **human ledger** is `deploy/production/RELEASE-SETS.md`, checked in and append-only, one row per set with ID, release SHA, CI run URL, the five digests (**transcribed for humans, never read by a deploy**), deploy timestamp, and a `state` column (`current` / `last-known-good` / `superseded` / `quarantined`). 🚨 **A ledger commit therefore has no deployment consequence and creates no `main`-vs-production drift** — that is the whole point of the split |
| **N (how many are retained)** | 🚨 **N = 10 sets, or 90 days, whichever is longer** — chosen so that at a plausible weekly cadence the retained window always spans at least the two most recent monthly backup tiers (§4.5), because a rollback to an image older than the oldest restorable database is not a rollback, it is an outage |
| **Rotation cadence** | Digests rotate **only on a release**. There is no scheduled rotation. Separately: **`docker image prune` weekly on the host keeps the last 3 sets locally** (Appendix E), and a **monthly pull-test of `last-known-good` from GHCR** proves the registry copy still exists (Phase 6.7, DR-3) |
| **How `last-known-good` is set** | A set becomes `last-known-good` **only after** it has been `current` for **72 h with no V-suite failure, no Sentry regression alert, and no rollback** — not at deploy time. Promotion is a one-line edit to `RELEASE-SETS.md` and is part of the weekly ops check |
| **Sync between compose and panel** | **V-12 already diffs the manifest against the §3.3 table; v3 extended it to diff the release set's five digests against the five *running* digests reported by V-10.** 🚨 **v4 makes the comparison basis the ATTESTED LOCK** (§7.0.2b) rather than a checked-in file, so V-12 compares *running* against *signed* — a repo write can no longer move the thing the check compares to. Any divergence is deploy-blocking, and a lock/ledger disagreement is an incident. This is the mechanism that stops drift — not discipline |

**Rollback runbook — numbered, and the database question is answered BEFORE any container moves:**

| # | Step | Command / criterion |
|---|---|---|
| 1 | **Name the target set.** Read `RELEASE-SETS.md`; the target is normally the `last-known-good` row | record old set ID → new set ID in the incident log |
| 2 | 🚨 **Decide the schema question FIRST.** Did the current set apply any migration that the target set's code cannot run against? | `php artisan migrate:status` on the **current** deployment; diff the migration list against the target set's release SHA (`git diff <target-sha>..<current-sha> -- apps/api/database/migrations/`). **Empty diff ⇒ code-only rollback, proceed. Non-empty diff ⇒ STOP** |
| 3 | **If the diff is non-empty:** a code rollback alone is **FORBIDDEN**. Either (a) the migration is provably additive and backward-compatible (new nullable column, new table, new index) — the operator records *why*, per migration, and proceeds; or (b) the rollback becomes a **restore**: Mode A fence (§4.6a) + database restore to a cycle predating the deploy + the target image set. There is no third option, and "just roll the images back and see" is the failure mode this step exists to prevent | recorded decision, per migration |
| 4 | **Enter the fence if step 3 chose (b)** | §4.6a Mode A |
| 5 | **Roll the images in dependency order**, one service at a time, health-gated: `websocket` → `worker` → `scheduler` → `api` → `web` | after each: container healthy **and** the service's own healthcheck green before the next |
| 6 | **Panel path:** repoint each of the five Dokploy apps at the target digest and redeploy. **Panel-independent path (DR-4):** 🚨 **v4 — fetch and verify the target set's `release-set.lock.json`, materialise it as the image env file, and `docker compose up -d <service>`. Do NOT hand-edit digests into the compose file** — a hand-edited digest is unattested and V-12 will (correctly) refuse it | either is acceptable; **whichever is used, step 8 reconciles the other** |
| 7 | **Run the V-suite** — the full V-0…V-12, not a subset. V-10 must report the **target** digests | any failure ⇒ the rollback itself failed; escalate, do not iterate blindly |
| 8 | **Reconcile:** update `RELEASE-SETS.md` (old set → `quarantined`, target → `current`) and the five Dokploy app references so they agree with the **lock that is actually deployed**. 🚨 **v4: there is nothing to update in `compose.production.yml`** — it carries no digests, which is exactly why a rollback no longer creates a git commit | V-12 green |
| 9 | **Evidence:** incident log entry with old/new set IDs, the step-2/3 schema decision and its justification, per-service health timestamps, the V-suite output, and the total wall-clock | retained with the Phase-7 evidence set |

**The initial deploy is NOT exempt** (round-2 F36). Phase 3.2/3.3/3.4 create the first production Applications, and v2 deferred encoding the V-suite into the workflow to Phase 6.6 — leaving the *first* deploy manually attested. **Ruled: the first deploy runs the identical V-0…V-12 suite, executed manually from the same script the workflow will later invoke** (`scripts/verify-production-release.sh`, a **Phase 0.8g** deliverable, so the workflow and the manual run cannot diverge), **and its output is archived as Phase 3.6 evidence in the same format**. Phase 6.6 then only changes *who invokes it*, not *what runs*. The bootstrap set (§7.0.2a) is recorded as `rs-bootstrap-<sha>` in `RELEASE-SETS.md` with state `superseded` the moment the first green-`main` set deploys.

### 7.1 Current state

**There is no deploy pipeline.** `grep -rniE "dokploy|deploy|webhook|ssh|curl -X POST" .github/workflows/` returns zero matches [A10]. Deployment is entirely Dokploy-side: its GitHub-App git watcher builds the configured branch on push and **does not consult GitHub check status**.

Consequence, stated plainly: **a production app pointed at `main` with auto-deploy on would ship a red build to a live fiscal system.**

### 7.2 Promotion flow

```
feature branch ──PR──▶ dev ──────────────▶ STAGING (auto, Dokploy git watcher)
                       │                    erp.otospex.dev
                       │                    PR→dev runs only the cheap CI subset
                       │
                       └──PR (dev→main)──▶ FULL CI SUITE
                                            backend-test · backend-test-pgsql ·
                                            treasury-spine-pgsql · frontend-test ·
                                            pos-test · frontend-build · types-drift
                                            → all-checks-pass          (ci.yml:1022-1036)
                                                    │
                                              merge to main
                                                    │
                                    ┌───────────────▼────────────────┐
                                    │ .github/workflows/deploy-prod  │
                                    │  on: workflow_run              │
                                    │      workflows: [CI]           │
                                    │      types: [completed]        │
                                    │      branches: [main]          │
                                    │  if: conclusion == 'success'   │
                                    │  environment: production ◀─────┼── required
                                    │       (manual approval)        │    reviewer
                                    │  → build+push image to GHCR    │
                                    │  → POST Dokploy application-   │
                                    │    deploy × 5 app services     │
                                    │  → poll for healthy            │
                                    │  → run §7.4 verification       │
                                    │  → run smoke-test.yml against  │
                                    │    https://riserpos.app        │
                                    └────────────────────────────────┘
                                                    │
                                              PRODUCTION
                                              riserpos.app
```

**Ruling: `autoDeploy = false` on all five production Application services.** Deploys are triggered only by that workflow. Combined with GitHub's `environment: production` protection rule (a required reviewer), **every production deploy is both CI-gated and human-approved.**

### 7.3 🚨 Prerequisite: the `dev` → `main` promotion (F-1)

`origin/main` is **4,303 commits behind** and last moved 2026-06-29.

🔧 **v2 correction (Finding 34):** v1 said *"there is no artifact on `main` that can be deployed."* That is too absolute — `main` **does** contain Dockerfiles, lockfiles, compose files and a CI workflow. **The defensible claim is that `main` lacks the intended current release.** The correction matters because it changes what the promotion is: not "bootstrap a branch from nothing" but "land five weeks of divergence onto a branch that already has a plausible-looking, stale build definition" — which is the more dangerous of the two, since a stale-but-buildable `main` is exactly what an accidental deploy would ship.

**Measured scope** (verified this session): `origin/main...origin/dev` = **3 commits only on main, 4,303 only on dev**; the merge-base diff spans **8,455 files, ~1,349,569 insertions, 53,305 deletions**. There is **no `.gitmodules` and no gitlink**, so the submodule risk v1 worried about was not found — but a diff that size can still surface generated/build-artefact drift.

⚠️ **Whether CI has ever run green on GitHub is UNVERIFIED from repository files.** The workflow YAML shows heavy jobs on PRs to `main` and pushes to `main` (`ci.yml:3-8,180-185,850-935`) aggregating twelve dependencies (`:1022-1039`); **historical run status cannot be inferred from YAML** and must be read from GitHub.

**Ruling: treat the promotion as a release project, not a merge.** Required: an owner-approved merge window; a merge-conflict inventory for the three main-only commits; **full CI evidence from the GitHub UI, not from the workflow file**; a generated-artefact/build-drift check across the diff; **container builds for every target** (§7.0); an SBOM/vulnerability scan of the produced images; and a **rollback tag** on `main` before the merge lands. The existing owner-only merge gate stays.

| Prerequisite | Why it is a project, not a step |
|---|---|
| Open `dev` → `main` PR | Triggers `backend-test`, `frontend-test`, `pos-test`, `frontend-build`, `all-checks-pass` for the first time on five weeks of work (`ci.yml:185,854,886,913,1035`). PR→dev never runs these |
| Expect and budget for red | The deptrac ratchet is a known pending item at the dev→main boundary; five weeks of merges will surface more |
| Do **not** shortcut by pointing production at `dev` | It would defeat the entire CI gate and collapse staging and production onto the same artifact stream. **E-10 already rules that a push to `origin/dev` deploys STAGING and is NOT production clearance** (`OWNER-manual-launch-gates-2026-07-31.md`, E-10 row) |
| Fix `sonarcloud.yml` | It watches `main, develop`; **`develop` does not exist** [A10]. Point it at `dev` or delete it — a workflow that has never fired is a false assurance |
| Parameterise `smoke-test.yml` | `workflow_dispatch` only, `staging_url` defaults to `https://erp.otospex.dev` [A10]. Add `riserpos.app` and call it from the deploy workflow |

### 7.4 🚨 Post-deploy verification (the F-2 trap)

`entrypoint.sh:141` treats `tenants:migrate-rolling` failure as **non-fatal**. A tenant left on a drifted schema serves traffic and 500s at runtime. **Every production deploy must run this, and a failure must fail the deploy job:**

**v2 widens this to the full set of boot-time failures F-2 now documents (Finding 1), and adds the sizing, provenance and manifest gates.**

| # | Check | Command | Pass criteria |
|---|---|---|---|
| **V-0** | 🚨 **Database reachable at boot** — the entrypoint skips the entire migrate/seed/permission block after 5 failed attempts and boots anyway (`entrypoint.sh:103-120,207`) | assert the container log contains the *connected* branch, not the skip branch; independently `php artisan db:monitor` | connected; **skip branch ⇒ FAIL THE DEPLOY** |
| V-1 | Central migrations applied | `php artisan migrate:status` | zero `Pending`; **and the entrypoint's caught-and-continued central-migration failure (`entrypoint.sh:122-127`) did not occur** |
| V-2 | **Every tenant migrated** | `php artisan tenants:migrate-rolling --force` re-run (idempotent — already-migrated tenants no-op), **and grep the output for per-tenant errors** | zero errors; **non-zero grep ⇒ FAIL THE DEPLOY** |
| V-3 | Health endpoints | `curl -f https://api.riserpos.app/health` and `https://riserpos.app/health` | 200 |
| V-4 | Horizon consuming | `php artisan horizon:status` **and** the six queues from `horizon.php:209` present in the dashboard | `running`; `default`, `fiscal-projections`, `enrichment`, `images`, `imports`, `ingestion` all listed |
| V-5 | Scheduler alive | O-4 heartbeat pinged within 5 min of deploy | ping received |
| V-6 | Realtime | Web client establishes a websocket to `/app/` and receives a broadcast | connected — **this is the `VITE_REVERB_APP_KEY` canary** (§3.4) |
| V-7 | Fiscal chain intact | 🚨 **v3: the verifier command contracts CHANGED on 2026-08-05 (cat-(b) Wave 2). See §7.4b for the exact signatures — the old "all three verifiers with `--actor-id`" phrasing was wrong for two of the three even before the change** | per §7.4b: exit 0, **and** a per-tenant `ALL CHAINS VALID ✓` / `all chains verified` line for every tenant in scope |
| V-8 | Smoke | `smoke-test.yml` against `https://riserpos.app` | green |
| **V-9** | 🚨 **Worker sizing load test (v2, Finding 6)** — all 10 Horizon processes on the heaviest jobs (largest import, image enrichment, fiscal projection) **including a forced recycle overlap** | peak RSS + 50 % headroom **fits the configured cgroup**; **zero OOM kills, zero unexplained restarts**. Record the number in Appendix D. **Launch gate — run once before launch and re-run whenever `maxProcesses`, `memory`, or the job mix changes** |
| **V-10** | 🚨 **Provenance (v2, Finding 36)** — every running service's **image digest** is read from the container and checked against the attested release set. 🚨 **v4: the full input list and two-mode pass table are in §7.4c** (round-3 finding 3.4 — v3 asserted three mutually contradictory V-10 behaviours and specified no command; note in particular that a container reports a **digest, not the tag it was pulled by**, so "bootstrap-ness" is a property of the release set and is enforced by a **denylist**, not by string-matching a tag) | per §7.4c: every digest equals the attested set for the approved run, the lock's signature verifies, and — once `bootstrap_state.open == false` — **no running digest appears in the permanent bootstrap denylist**. Any unattested digest, any denylisted digest after closure, or any missing input **fails the deploy** |
| **V-11** | **Permission state** — 🚨 **v3: now an EXECUTABLE fail-closed probe with expected output, not an assertion (§7.4a)** | §7.4a's three checks all pass; **any non-zero ⇒ FAIL THE DEPLOY** |
| **V-12** | **Manifest/table agreement (§3.8)** — `deploy/production/compose.production.yml` matches the §3.3 service table, **and the ATTESTED LOCK's five digests equal the five digests V-10 read from the running services** (🚨 v4, §7.0.2b — the comparison basis is the signed lock, not a checked-in file, so a repo write cannot move the target) | any divergence is deploy-blocking; a lock-vs-ledger disagreement is an incident (§7.0.2b) |

V-2 is the whole point. Without it, "the deploy succeeded" is a claim about the container, not about the tenants. **V-0 and V-11 exist because v1's V-suite implicitly trusted the entrypoint** — and the entrypoint, verified line by line in F-2, catches and continues past every failure it can encounter.

#### 🚨 7.4a V-11 made executable and fail-closed (v3, round-2 F1 — was PARTIALLY CLOSED)

v2 said the permission state "is asserted here", which the re-gate correctly called *not a spec from which the required check can be implemented*. The entrypoint gives us nothing to trust: the reseed's failure branch prints `Permission sync: [completed with per-tenant errors - check logs]` and **returns success anyway** (`entrypoint.sh:153-161`), and `permission:cache-reset` is suppressed with `|| true` (`:167`). Both must be re-established out of band, with commands whose exit codes mean something.

🚨 **v4 (round-3 R3-N4, finding 5.1) — v3's three probes were pseudo-commands, and pseudo-commands are what F1 was raised about in the first place.** Round 3 found all three broken in the same way: they *look* runnable and cannot run.

| v3 probe | Why it could not execute |
|---|---|
| **V-11a** | The workflow was said to write `expected-permissions.txt` (one name per line) but the SQL read `expected-permissions.txt.sqlvalues` — **a file nothing produces**. No transformation into `('name'),('name')` tuples was specified, and no quoting/escaping at all. The stated alternative source, `SELECT id FROM tenants`, yields **tenant UUIDs**, while the loop feeds each value to `psql -d` — which needs a **physical database name** (`izipostenant_<uuid>`, or the stored `tenancy_db_name`, which is authoritative and may differ — §3.2/R-20) |
| **V-11b** | A bare `SELECT`. `psql` **exits 0 when a query returns rows**, so "EXPECTED OUTPUT: zero rows" was never converted into a failing deploy |
| **V-11c** | Trusted the Artisan exit code, but Spatie's `CacheReset::handle()` **returns no failure code** — it prints `Unable to flush cache` and still exits 0 (`vendor/spatie/laravel-permission/src/Commands/CacheReset.php:14-24`). The Redis scan that followed was **printed and never asserted empty** |

**Ruling — the normative V-11 is the `permissions:verify` COMMAND, and it is a Phase-0 blocker, not a "durable form" to add later.** v3 tried to have it both ways: shell-out SQL as the day-one gate with a command commissioned alongside. The SQL cannot be made correct in a design document without also specifying quoting, tenant-DB-name resolution and exit-code conversion — i.e. without writing the command anyway, in bash, untested. **So it is written in PHP, where it can be tested.**

**V-11 = `php artisan permissions:verify --expect=<file>` — one command, three assertions, one fail-closed exit.** Commissioned at **Phase 0.8h1**, contract per §7.4b's cat-(b) shape: per-tenant verdict lines, `--tenant` narrowed **in the directory query**, fail-closed aggregate exit, and a **non-zero exit when the `--tenant` filter matched nothing**.

| Assertion | What it checks | Failure |
|---|---|---|
| **V-11a** | every name in `--expect` exists in `permissions` **in every visited tenant database** | per-tenant `missing=<n>` line; any `n > 0` ⇒ aggregate FAILURE. This is the check that would have caught `uom.view` (2026-06) and `loyalty.enroll` (2026-07) |
| **V-11b** | every expected name is attached to **≥ 1 role** (`role_has_permissions`) | a permission row no role holds produces the **same 403** as a missing one, and the reseed can create rows without wiring them |
| **V-11c** | the permission cache is actually gone | see below — **this one cannot be delegated to `permission:cache-reset`'s exit code** |

**Required tests before it is trusted (all of them, and the first two are where the v3 SQL would have died):**

1. **Quoting** — a permission name containing a quote or backslash is handled; parameter binding is used, not string interpolation.
2. **Empty `--expect` file** — a deploy that adds no permissions must be an explicit **pass with a "0 expected" line**, never a silent pass.
3. **Unknown `--tenant`** — non-zero exit (per §7.4b: `1` = DB missing/unopenable, `2` = not in the central directory).
4. **Missing permission** in one tenant of several — that tenant fails, the fleet run **continues**, the aggregate is FAILURE.
5. **Unassigned permission** (exists, no role) — same.
6. **Per-tenant DB-name resolution** — the command resolves each tenant's **physical database name** through the same path the application uses (stored `tenancy_db_name` first, prefix fallback second — §3.2), so a backfilled or non-default name cannot be silently skipped. 🚨 **This is the assertion that makes the "or `SELECT id FROM tenants`" shortcut permanently forbidden.**

**V-11c — the cache check, spelled out, because the obvious implementation is wrong:**

```bash
# The Artisan exit code is NOT evidence: CacheReset::handle() returns success even when
# forgetCachedPermissions() returns false (vendor/spatie/laravel-permission/.../CacheReset.php:14-24).
docker exec "$API" php artisan permission:cache-reset          # run it UNSUPPRESSED (no `|| true`) …
KEYS=$(docker exec "$REDIS" redis-cli -a "$REDIS_PASSWORD" --scan \
       --pattern "*spatie.permission.cache*" | head -20)
[ -z "$KEYS" ] || { echo "V-11c FAILED — permission cache key still present:"; echo "$KEYS"; exit 1; }
```

… **and the assertion is the Redis scan, not the command.** Two further requirements:

- **The scan pattern must account for the cache prefix.** The *logical* key is `spatie.permission.cache` on the `default` store (`config/permission.php:177-201`), but the **physical** key carries the application cache prefix (`config/cache.php:108-115`). The wildcard above tolerates that; a hard-coded exact key would produce a **false pass**.
- 🎫 **Ticket, and it is small:** cover Spatie's `forgetCachedPermissions() === false` **while the cache existed** branch in a test, and — since that branch is where a real flush failure lives — treat its silence as the reason V-11c asserts on Redis rather than on an exit code.

🚨 **That cache is tenant-blind** — one key serves every tenant — which is why a single reset is sufficient *and* why forgetting it 403s every tenant at once.

**Interim posture, stated so nobody improvises one:** until `permissions:verify` lands at Phase 0.8h1, **there is no V-11 substitute and no deploy that adds permissions may proceed.** That is a real constraint and it is deliberate — Phase 0.8h1 is upstream of the first production deploy anyway, so the constraint costs nothing and removes the temptation to paste untested SQL into a release under time pressure.

#### 🚨 7.4b The fiscal verifier contracts CHANGED — V-suite and runbook references updated (v3)

The cat-(b) Wave-2 cross-tenant conversion (commits `97f123c1c`, `dbc5476aa`, `6c07d2730`) rewrote every fiscal verifier as a `TenantScopedCommand`. **Any runbook, gate sheet, or V-suite line written before 2026-08-05 is now describing a different command.** Verified against the code this session:

| Command | Signature (verbatim) | Fleet behaviour |
|---|---|---|
| `fiscal:verify-chains` | 🚨 **v4 CORRECTION — `{--tenant=}{--company=}{--type=}`, with NO `--fix`** — `VerifyFiscalChainsCommand.php:59-72` (fleet iterator + fail-closed aggregate at `:118-142,196-249`). v3's table still listed `{--fix}`; **the flag was removed from the command on 2026-08-05**, so an operator following v3's table would pass an **undefined option** and the verifier would error out — turning a fiscal gate into a red herring at exactly the wrong moment | no `--tenant` ⇒ **every tenant** in the central directory |
| `pos:verify-chains` | `{--tenant=}{--company=}{--terminal=}{--type=all}` — `VerifyPosChainCommand.php:68-77`; filtered-nothing failure and final aggregate at `:198-259` | idem |
| `fiscal:verify-event-chain` | `{--tenant=}{--terminal=}{--chain-context=operational}{--from-sequence=}{--actor-id=}` — `VerifyEventChainCommand.php:89-97,124-188`; **`--tenant`, `--terminal` and `--actor-id` are all REQUIRED**; there is **no fleet mode** | single chain only |
| `fiscal:preflight-gate` | `{--tenant=}` — `PreflightFiscalGateCommand.php:53-56,78-146` | no `--tenant` ⇒ every tenant |

🚨 **v4 — this table has now gone stale twice in two days, so it stops being maintained by hand.** `scripts/verify-production-release.sh` (Phase 0.8g) **generates** its verifier invocations from `php artisan help <command>`, and a **regression test asserts the parsed option set matches the expected set**, so the next signature change fails CI instead of failing a fiscal gate. A hand-maintained command table in a design document is a snapshot; the test is the contract.

**Four things every reference to these commands must now get right:**

1. 🚨 **`--actor-id` exists on `fiscal:verify-event-chain` ONLY.** `fiscal:verify-chains` and `pos:verify-chains` do not accept it and will error on it. **The phrase "all three chain verifiers with `--actor-id`" — which appears in the E-2 and E-3 gate rows and in `docs/qa/2026-05-12-first-tenant-smoke.md` — was already wrong before the conversion and is wrong now.** 🎫 **Owner action, not an agent edit** (the gate sheet is Phase-E human-only, §4.5b): correct those rows to "`fiscal:verify-chains` and `pos:verify-chains` fleet- or tenant-scoped, plus `fiscal:verify-event-chain --tenant=<uuid> --terminal=<uuid> --actor-id=<uuid>` per terminal."
2. 🚨 **Never wrap these in `tenants:run`.** They iterate the central directory themselves (`TenantScopedCommand::forEachTenantFiltered`), and `tenants:run` **swallows the child exit code** (`Run::handle()` returns null — documented at `ConfigureCashRoundingCommand.php:36-38`). Wrapping them destroys the entire fail-closed contract *and* runs a full fleet iteration once per tenant.
3. **Exit codes are now trustworthy and are the gate.** SUCCESS only if every visited tenant returned SUCCESS; the first non-SUCCESS wins; a probe fault marks the aggregate FAILURE; and an unvisited `--tenant` target returns **1** (database missing/unopenable) or **2** (not in the central directory) — `TenantScopedCommand.php:346-348,502-524`. `fiscal:verify-event-chain` remaps that 2→1 because it reserves **2** for *transient* failure (DB unreachable). 🚨 **Newly fail-closed:** `pos:verify-chains` with `--terminal`/`--company` matching **nothing** now exits non-zero (`VerifyPosChainCommand.php:171-185`); it previously printed "not found" and exited **0**. A runbook that only greps output would have passed on a silent nothing-verified.
4. **Per-tenant verdict lines are the evidence, not just the summary.** Assert both:
   - `fiscal:verify-chains` → `TENANT <id> (<slug>): ALL CHAINS VALID ✓` per tenant, then `Status: ALL CHAINS VALID ✓`
   - `pos:verify-chains` → `TENANT <id> (<slug>): all chains verified` per tenant, then `All chains verified successfully.`
   - `fiscal:preflight-gate` → `TENANT <id> (<slug>) SERVER SURFACE: clear` per tenant

   **A run that prints an aggregate pass with zero per-tenant lines is a NO-GO** — it means nothing was visited.

🎫 **Ticket (outside this document's write scope):** `docs/runbooks/fiscal-verify-all-chains.md:11-12,33-35,53-54` still documents the pre-conversion flags and wraps the verifiers in a per-company `Artisan::call()` loop; `docs/qa/2026-05-12-first-tenant-smoke.md:117-118` and `docs/handoff/DISPATCH-PLAN-v4….md:130-131` cite pre-conversion line numbers. All three need updating before E-2/E-3 execute against them. 🔧 **v4: the `--fix` removal ticket is CLOSED — the flag is gone from the command** (`VerifyFiscalChainsCommand.php:59-72` declares `{--tenant=}{--company=}{--type=}` only). The ticket that remains is the documentation sweep above, plus the §7.4b generated-command-list regression test that stops this class of drift recurring.

#### 🚨 7.4c V-10's inputs and pass table — the provenance verifier gets a contract (v4, round-3 finding 3.4)

v3 asserted three V-10 behaviours that contradicted each other and specified none of them: V-10 "requires every running service to match a specific owner-approved all-checks-green run"; the first deployment "passes the full V-suite" while V-10 reports bootstrap digests as *expected*; and after supersession V-10 "fails any service still running a `bootstrap-` digest". **There is no rule that satisfies all three, and no command was given.** Worse, the third is not inferable at runtime: 🚨 **a running container reports the DIGEST it was started from, not the TAG used to pull it** — so "the digest contains `bootstrap-`" is not a thing V-10 can ever observe. Bootstrap-ness is a property of the **release set**, not of the image.

**V-10's inputs (all five are required; a missing input is a FAIL, never a skip):**

| Input | Source |
|---|---|
| `approved_run` — the CI/dispatch run ID whose approval gated this release | the deploy workflow's own context, or the operator's input on the manual path |
| `source_sha` | the lock (§7.0.2b) |
| `expected_digests` — the five, attested | `release-set.lock.json`, **signature verified before use** |
| `bootstrap_state` — `open` / `closed`, with `designated_sha` | `BOOTSTRAP_STATE` (§7.0.2a) |
| `bootstrap_denylist` — the digests of every set ever published with `bootstrap: true` | derived from `RELEASE-SETS.md` + the retained bootstrap locks; **permanent, append-only** |

**V-10's observation:** for each of the five services, the **running image digest**, read from the container (`docker inspect --format '{{.Image}}'` → resolved manifest digest), never from a tag and never from the panel's display string.

**Pass table — two modes, and they are mutually exclusive:**

| Condition | `bootstrap_state.open == true` | `bootstrap_state.open == false` |
|---|---|---|
| Every running digest == the corresponding `expected_digests` entry, and the lock's `approved_run` matches the approved run | **PASS.** If the lock has `bootstrap: true`, V-10 **prints a prominent `BOOTSTRAP SET IN USE — supersession owed (H-21)` warning** and still passes. This is the only state in which a bootstrap digest passes | **PASS**, and only if the lock has `bootstrap: false` |
| Any running digest is in `bootstrap_denylist` | PASS with the warning above **only if it is also in `expected_digests`** — i.e. it is *this* approved bootstrap set, not a leftover | 🚨 **FAIL, unconditionally.** This is the supersession enforcement, and it is a denylist check, not a string match on a tag |
| Any running digest ∉ `expected_digests` | **FAIL** — an unattested image is running | **FAIL** |
| Lock signature/attestation fails to verify, or any input is absent | **FAIL** | **FAIL** |

**Two consequences worth stating:** (1) **tenant #1 cannot onboard while V-10 warns** — Phase 7.8 requires a clean V-10 with `bootstrap_state.open == false` (R-29); (2) V-10 is the recovery source for a lost lock (§7.0.2b failure mode 1), which is why **its full output is archived with every deploy's evidence**, not just its verdict.

### 7.5 Ruling on the permission-reseed gap (F-3)

`SYNC_PERMISSIONS_ON_BOOT` appears in **no compose file**. `entrypoint.sh:153-161` gates the cross-tenant permission reseed on it, with an explicit comment that it *"resets BUILT-IN roles to the canonical set — safe on staging, but a product decision for production tenants that may have customized built-in roles."*

**Ruling: keep it `false` in the steady state; flip it deliberately, per deploy, when the release adds permissions.**

| Situation | Action |
|---|---|
| Release adds **no** new permission | `SYNC_PERMISSIONS_ON_BOOT=false`. Nothing to do |
| Release **adds** permissions | Before deploy: set `SYNC_PERMISSIONS_ON_BOOT=true` on the **API service only**. After the deploy and V-2: set it back to `false`. `permission:cache-reset` runs unconditionally at `entrypoint.sh:167` and needs no flag |
| Any doubt | Run it. A stale permission catalog is a 403 on a new feature; a reseed only resets **built-in** roles, and no production tenant has customized built-in roles yet |

**Why not just leave it `true` permanently:** the moment a tenant customizes a built-in role, every deploy silently reverts their customization. The flag is correct; the gap is that nobody sets it. **The deploy workflow must ask the question explicitly** — add a `sync_permissions` boolean input to the deploy workflow so it is answered, not forgotten.

Rationale is reinforced by the tenant-blind Spatie permission cache: deploys that add permissions **must** `permission:cache-reset` (already unconditional at boot) *and* reseed, or new routes 403 on existing tenants — the documented `uom.view` (2026-06) and `loyalty.enroll` (2026-07) failures.

### 7.6 First-production-deploy debt

The first deploy carries **all accumulated staging deploy checklists at once**, because production starts from an empty cluster and every backfill/config step that staging received incrementally must be applied in one pass:

| Checklist | Path | Owes |
|---|---|---|
| Treasury ② instruments | `docs/handoff/treasury-phase2-deploy-checklist.md` | `tenants:migrate`, chart-of-accounts seeder re-run (portfolio accounts — a missing 5313 produced a live 422), permission reseed (`instruments.update/bounce/remit/cancel`) + `permission:cache-reset` |
| Bank directory | `docs/handoff/bank-directory-deploy-checklist.md` | existing-tenant banks backfill. 🚨 **a bare `tenants:run db:seed` SILENTLY NO-OPS** — the container injects an empty `Company` into `?Company $company = null` seeders |
| Treasury ③ cash visibility | `docs/handoff/treasury-phase3-deploy-checklist.md` | `tenants:migrate` (notifications table + posted-scoped treasury_transfer JE index), reseed `treasury.transfer` + cache reset |
| Treasury ④ expense depth | `docs/handoff/treasury-phase4-deploy-checklist.md` | per checklist |
| Treasury ⑤a outbound | `docs/handoff/treasury-phase5a-deploy-checklist.md` | payable-account 403/4035 dry-run → apply |
| Treasury ⑤b reconciliation | `docs/handoff/treasury-phase5b-deploy-checklist.md` | CARD routing configure dry-run → apply, **explicit repo code per tenant batch**. 🚨 **`tenants:run` swallows child failures — grep BOTH logs** |
| Multi-location | `docs/handoff/multiloc-deploy-checklist.md` | backfill + **skip-log check** |
| Cash rounding ①/② | `docs/handoff/cash-rounding-phase{1,2}-deploy-checklist.md` | target-scoped `pos:configure-cash-rounding` **dry-run then explicit `--tenants=<uuid>`** — gated behind **E-7** |
| UoM display precision | (memory) | DemoPharmacySeeder rerun — **N/A in production**, no demo data |
| 🚨 **Channel webhook directory (v3)** | `docs/superpowers/tickets/2026-08-05-channels-reconcile-post-migrate-deploy-step.md` | **`php artisan channels:reconcile` after migrate** — §7.7c PM-1. **Applies even on greenfield**, as a recorded no-op |
| 🚨 **Queue-drain register (v3)** | §7.7a | QD-1 / QD-2 / QD-3 — record "N/A, empty Redis" for the first deploy, **naming each**; the drain procedure itself is §7.7b |

**On a greenfield cluster most of these collapse**, because a tenant provisioned *after* the migrations exist gets the schema at signup. But the **seeders and configuration steps do not collapse**: chart-of-accounts portfolio accounts, the banks directory, CARD routing, payable accounts and cash-rounding policy are all data that must be authored for tenant #1 regardless. **Ruling: walk every checklist and mark each line `applies` / `n/a-greenfield` with a reason, before the first deploy.** A blanket "greenfield, skip" is exactly how the 5313-missing 422 happens again.

### 7.7 The queue-drain caveat list and the post-migrate deploy steps

🔧 **v3 widens this section.** v2 recorded one instance (the A2 job-deletion wave). Two more landed on 2026-08-05 in parallel work and must be reflected here, and the standing drain procedure is corrected to match the primitives that actually exist (§4.6a).

#### 7.7a The drain caveat register

| # | Change | What breaks | Status |
|---|---|---|---|
| **QD-1** | Commit `6f14f8232` **deleted** `ExpireReservationsJob`, `DailyExpiryCheck` and the marketplace scheduler closures, replacing them with four `TenantScopedCommand`s (`inventory:expire-reservations`, `batch-expiry:daily-check`, `marketplace:delta-sync`, `marketplace:reconcile`) | **A serialized payload naming a deleted class cannot be unserialized** — the worker throws on wake | in `dev`; **N/A on a greenfield production cluster** (empty Redis) |
| **QD-2** | 🚨 **NEW in v3 — marketplace job CONSTRUCTOR SIGNATURE change.** Commit `fd679a317` took `SyncSellerListingsJob` and `ReconcileListingsJob` from a **1-argument** constructor (`private readonly string $sellerId`) to a **2-argument** one (`+ $tenantId`), for `BindsTenantContext` adoption | A payload serialized with one argument cannot supply `$tenantId`. The commit's own DEPLOY NOTE: *"Any SyncSellerListingsJob / ReconcileListingsJob payload already on the queue at deploy time was serialized with one argument and will fail to unserialize `$tenantId`. Both commands re-fan out every 15 min / nightly, so drain or discard the in-flight backlog rather than retrying it."* | **Severity reduced, not eliminated, by `24dac38ee`:** `$tenantId` became a **declared, defaulted `public ?string $tenantId = null`** (`SyncSellerListingsJob.php:68`, `ReconcileListingsJob.php:80`), so a legacy payload now restores as `null` and `handle()` **discards it with a warning** instead of fatalling. **Draining is still preferred** (it avoids the warning noise and a pointless dequeue); skipping it is now survivable. `marketplace:delta-sync` / `marketplace:reconcile` re-fan-out, so nothing is lost |
| **QD-3** | 🚨 **The general shape, now a named rule.** `Illuminate\Queue\SerializesModels::__unserialize()` **skips absent payload keys**. A **promoted `public readonly string $tenantId`** on a job that was ever dispatched *without* the anchor stays **uninitialized**, and the first read fatals — making the `failed_jobs` row **permanently un-retryable** | Two jobs are genuinely exposed today: `ProcessImportJob.php:64` and `ProcessProductImageImport.php:56` (anchor added `b059bb2c5`, 2026-05-07; 4½ months of pre-anchor dispatches) | 🎫 ticketed — `docs/superpowers/tickets/2026-08-05-bindstenantcontext-promoted-readonly-unserialize.md`. **Production impact: none at launch** (empty Redis, empty `failed_jobs`), but the **rule** below is what stops the next instance |

**The rule, permanent:** *any* deploy that renames a queued job class, **changes its constructor arity or signature**, or adds a serialized property must be preceded by a drain, and the affected classes must be named in the deploy record. The symptom — jobs quietly vanishing or `failed_jobs` rows that will never retry — looks like a queue outage, not a deploy artefact, which is why it has to be a checklist line rather than institutional memory.

#### 7.7b The standing drain procedure — corrected (v3)

🚨 v2 said *"`horizon:pause` → wait for pending == 0 → deploy → `horizon:continue`"*. Per §4.6a that is **not a drain**: `horizon:pause` stops the *next* reservation, `horizon:status` reports no counts at all, and a held pause **fails the worker healthcheck and gets the container restarted**. Use the same primitives Mode B uses — 🚨 **and v4 re-orders this table to match §4.6a's v4 order, because round-3 R3-N1 applies here identically: `horizon:pause` is dropped, and the stop is issued inside the drain window:**

| # | Step | Command |
|---|---|---|
| 1 | 🚨 **v4 — record the worker task identity. 🚨 v5 — record the FULL task-ID set** | `docker service ps <stack>_worker --filter desired-state=running --format '{{.ID}}'` **and** the full baseline `docker service ps <stack>_worker --format '{{.ID}}' \| sort -u`, both recorded in the deploy record |
| 2 | 🚨 **v5 — ONE atomic stop: `docker service scale <stack>_worker=0`** (no `horizon:pause`, no separate `horizon:terminate`) | `docker service scale <stack>_worker=0` (or the panel stop — same primitive) atomically sets desired replicas to 0 **and** sends SIGTERM; `exec php artisan horizon` (`entrypoint-worker.sh:48`) begins Horizon's graceful drain (`fast_termination => false`, `config/horizon.php:175`, each worker finishes its current job). 🚨 **The drain only completes if the supervisor `timeout` covers the job (`env('HORIZON_SUPERVISOR_TIMEOUT', 3900)`, Phase 0.8k) — otherwise the master exits 0 after 60 s (`MasterSupervisor.php:167-203`) and this "stop" is a silent kill.** 🚨 **`stop_grace_period` must also exceed the longest job timeout (D-21)** |
| 3 | 🚨 **v4 — assert the same task exited and nothing replaced it. 🚨 v5 — full-set comparison** | the recorded task ID is `Shutdown`/`Complete`, **running-task count is 0**, **no task id outside the recorded baseline appears in any state**, container exit code is `0` (a `137` means SIGKILL — the job was killed, not drained; investigate what it left behind before deploying) |
| 4 | **Prove zero in-flight** | `php artisan queue:monitor <the six queues> --json --max=999999` → `reserved == 0` on every queue, **sampled twice ≥ 95 s apart**. 🚨 **v4 corrects the reason for the second sample**: with no worker popping, an orphaned reserved job does **not** migrate back and reappear (`RedisQueue.php:297-303,321-346`) — it stays reserved and the probe stays non-zero. The second sample confirms nothing is still moving; it is not a wait for a reappearance |
| 5 | Deploy | §7.2 |
| 6 | Resume | start the worker Application; Horizon comes up unpaused |
| 6 | **If the backlog will not drain** | **discard the affected payloads deliberately** — `php artisan queue:clear redis --queue=<name>` for the named queue, or targeted `failed_jobs` deletion — and **record exactly what was discarded** in the deploy record. An undocumented discard is indistinguishable from data loss |

| Context | Impact | Action |
|---|---|---|
| **First production deploy** | **None** — Redis is empty on a new cluster | Record "N/A, empty Redis" as the evidence, naming QD-1/QD-2/QD-3. Do not skip silently |
| **Any subsequent deploy matching the rule above** | in-flight payloads dead-letter, discard-with-warning, or (for QD-3 shapes) become permanently un-retryable | the six steps above |

🚨 **v4 note on scope:** round 3 confirmed the QD-2/QD-3 serialization facts against live source (`SyncSellerListingsJob.php:56-88`, `ReconcileListingsJob.php:68-101`, `ProcessImportJob.php:58-66`, `ProcessProductImageImport.php:50-59`, `SerializesModels.php:73-99`) — **the payload register is current and needs no change.** What it *also* found is that this procedure inherited §4.6a's defects wholesale, which is why the table above is re-ordered rather than merely cross-referenced. A drain procedure that is right in one section and stale in another is how the wrong one gets followed.

#### 🚨 7.7c Post-migrate deploy steps that are NOT migrations (v3)

Some state cannot be created by a migration and will not self-heal before the next scheduled sweep. These run **after** `migrate`/`tenants:migrate-rolling` and **before** V-3:

| # | Step | Why it cannot be skipped |
|---|---|---|
| **PM-1** | 🚨 **`php artisan channels:reconcile`** — backfills `channel_webhook_directory` | The migration `2026_08_05_000001_create_channel_webhook_directory_table.php` creates the table **empty**, and that table is the **only** way the unauthenticated `POST /api/v1/webhooks/channels/{channelId}` route can resolve which tenant owns a channel (`channels` is a tenant table, so the callback cannot read it without already knowing the tenant). Only two things ever write a pointer: the `Channel` model observer (covers channels created *from now on*) and the nightly 03:30 `channels:reconcile` self-heal. **Between deploy and the first 03:30 sweep, every pre-existing channel's webhook returns a fail-closed 404.** Source: `docs/superpowers/tickets/2026-08-05-channels-reconcile-post-migrate-deploy-step.md` |

**How PM-1 is run — three rules, all of which invert the usual habits:**

- 🚨 **Do NOT wrap it in `tenants:run`.** It is a `TenantScopedCommand` and iterates the central directory itself; wrapping it runs a full fleet iteration **once per tenant**.
- 🚨 **v4 CORRECTION (round-3 R3-N6, finding 6.3) — `channels:reconcile`'s exit code is NOT yet the contract v3 assigned it, and Phase 6.6 must not treat it as deployment-critical until the code change below lands.** v3 said a non-zero exit "reliably covers a missing DB / mis-migrated tenant". Verified against live source, it does not:
  - `ChannelReconcileCommand` returns the base aggregate unchanged (`ChannelReconcileCommand.php:63-88,164-178`), and `TenantScopedCommand` records a **known-missing tenant database as `skipped` without changing the aggregate from success** (`TenantScopedCommand.php:261-318,347-360`). A fleet run that silently skipped the one tenant you cared about **exits 0**.
  - Directory registration returns **`false`** rather than throwing on a per-pointer central write failure (`ChannelWebhookDirectoryRegistrar.php:29-45,56-81`), and the command **ignores that boolean** (`ChannelReconcileCommand.php:130-149`). Every pointer write can fail and the command still **exits 0**.

  **Required code change, and it is a Phase-0 deliverable (0.8j), not a nice-to-have:** have the command inspect `skippedTenantIds()` and **return failure when it is non-empty**; count `register() === false` outcomes and **return failure after completing the fleet** (per-tenant continuation is retained — the point is to finish and then fail, not to abort). **Tests: a known-missing physical tenant DB, and a registrar write failure.** Until that lands, **the central-count and 403-not-404 probes below are the gate** — they are independent assertions and they are what Phase 6.6 keys on. 🚨 **Do not write "gate on the exit code" into any runbook before 0.8j is merged**; a trusted exit code that lies is worse than one nobody trusts.
- **Gate on the exit code once 0.8j has landed** — and never wrap it in `tenants:run` regardless, since `tenants:run` always exits 0 and would destroy the contract even after it becomes true.
- **It is idempotent** (`updateOrCreate` per channel) and **it also prunes** pointers whose channel or tenant no longer exists. A healthy fleet reports `Pruned 0 stale webhook directory pointer(s).` **A non-zero prune count on the FIRST run after this deploy would be surprising** (the table starts empty) and is worth investigating before proceeding.

**Verification (central DB):** `SELECT count(*) FROM channel_webhook_directory;` must equal the sum of `SELECT count(*) FROM channels` across every tenant database. Then, per tenant, spot-check one channel end to end: `POST /api/v1/webhooks/channels/<real channel id>` with a fresh `X-Channel-Timestamp` must reach **signature verification (403 on a bad signature)**, not 404. A 404 means the pointer is missing.

**Production applicability:** on a greenfield cluster with no channels configured, PM-1 is a **no-op that must still be run and recorded** (`0 pointers, 0 pruned`) — because the *next* deploy, after tenant #1 has configured a channel, is the one where skipping it silently breaks inbound webhooks. 🎫 The step is owed to the E-9 staging runbook and to whatever cutover document E-10 produces; both are Phase-E human-owned, so this document records the requirement and the ticket carries the edit.

**Also carry forward from `6f14f8232` — one paragraph, not two.** 🔧 *(v4, round-3 finding 6.4: v3 printed this note twice with only wording differences; the duplicate is deleted and the survivor is sharpened.)* `TenantScopedCommand::forEachTenant` iterates `Tenant::all()` **unfiltered**, so a missing or suspended tenant DB fires an `onFailure` `Log::error` every tick. Loud by design — **treat the first such alert as data, not as an incident.** 🚨 **But distinguish the two cases, because they exit differently:** a **probe fault** (the tenant exists and the query failed) marks the aggregate FAILURE, while a **known-absent database** is currently recorded as `skipped` **with the aggregate left at success** (`TenantScopedCommand.php:261-318,347-360`) — which is precisely the hole PM-1's 0.8j change closes for `channels:reconcile`, and which is worth auditing for every other scheduled `TenantScopedCommand` before it bites somewhere else. (This also interacts with §4.6a: a tenant fenced for a Mode B restore *will* generate these for the duration of the fence, which is one more reason the scheduler is stopped at step 4 of the fence.)

---

## 8. Buildout execution plan

Legend: **[O]** = owner action (agents cannot do it). **[A]** = orchestrator/agent action. Every step names the evidence that proves it.

### 🚨 8.0 Resequencing (v2, Finding 33 — BLOCKER against v1's order)

v1's phase order asked for verification before its prerequisites existed:

| v1 defect | Evidence |
|---|---|
| Phase 3.6 required all of V-1…V-8, but **V-5 depends on the scheduler heartbeat added in Phase 5.3** | v1 §8 |
| The deploy workflow and registry were **Phase 6**, after Phase 3 had already created and *built* five production Applications | v1 §8 |
| Phase 0.9 promoted `dev`→`main` **before** the Phase 5 monitor code and the Phase 6 workflow existed — **and no later dev→main step was specified**, so those artefacts had no path to `main` at all | v1 §8 |

**Ruling — one promotion, and everything the release needs is in it.** Every repository prerequisite moves ahead of the single final `dev`→`main` promotion: tenancy env support **and the §3.2 audit/backfill/tests**, Vite build-arg wiring, the Reverb runtime contract, restore-path code changes and their tests, the scheduler/backup/queue/volume-backup monitors, **the image build+push workflow**, **the panel-independent production manifest**, the production deploy workflow, smoke-test parameterisation, and the stale-doc corrections.

**Registry images and the panel-independent manifest exist before Phase 3.** Only then are production Applications provisioned and deployed, and only then does the V-suite run — against artefacts that exist.

### Phase 0 — Decisions, repository prerequisites, and the release commit (blocks everything)

> **v2: this phase absorbed Phase 5's monitor code and Phase 6's registry/workflow work.** Phase 0.9 (the promotion) is now the *last* step of Phase 0, not an early one, and it is the only dev→main promotion in the plan.

| # | Step | Who | Evidence |
|---|---|---|---|
| 0.1 | Answer **D-1…D-8** and **D-15…D-21** (🚨 v4 adds **D-20** attested lock / no checked-in digests and **D-21** worker `stop_grace_period`; **D-20 gates 0.8b/0.8b1/0.8c** and **D-21 gates 3.3**) | **[O]** | Decisions recorded in this document's §2/§2.2 with date |
| **0.2** | 🚨 **v3 (round-2 F21): RENUMBERED SO THE ORDER IS UNAMBIGUOUS IN THE TABLE, NOT ONLY IN THE PROSE.** Create the 1Password vault (D-4); **export the CURRENT Dokploy env-encryption keyring and take a panel backup; VERIFY both** (restore-test the keyring against one known secret — a keyring you have never verified is a file, not a backup); record the current panel version and confirm rollback support; execute the §5.6 `/tmp` migration | **[O]** | Vault item count; keyring + panel-backup items dated in 1Password; **a decrypt test transcript**; current version string; `ls /tmp/syneriva-deploy-secrets` → not found |
| **0.3** | **Upgrade Dokploy Cloud to ≥ v0.29.13** (D-8) — **STRICTLY AFTER 0.2.** Validate afterwards: both existing remote servers reachable **and** existing secrets still decrypt | **[O]** | Panel version string screenshot / API response; server list; one secret read back post-upgrade |
| 0.4 | Create DNS records per Appendix A, **TTL 300** | **[O]** | `dig +short riserpos.app` / `api.riserpos.app` return the new IP |
| **0.5a** | 🚨 **BLOCKING central-DB audit + backfill of stored `tenancy_db_name`** (§3.2, F-4) — on staging first | Four-column table per tenant; orphan list **both directions**; backfilled rows recorded |
| 0.5b | Land the `TENANCY_DB_PREFIX` env change (§3.2) on `dev` **with regression tests for stored-name precedence AND default-prefix fallback** | **[A]** | Diff; `config/tenancy.php` reads `env('TENANCY_DB_PREFIX','tenant')`; **staging tenant list byte-identical before and after**; green tests |
| 0.6 | **Build-configuration wiring for `VITE_REVERB_APP_KEY` (§7.0.4)** — pass it in every build path, **fail production builds when absent**, add the CI bundle inspection | **[A]** | Diff; **a deliberately key-less production build FAILS**; CI bundle check green; staging bundle no longer contains `local_key` |
| **0.6a** | 🚨 **Land the Reverb publisher contract (§3.4a)** — `REVERB_HOST`/`PORT`/`SCHEME` in every deployment definition | **[A]** | Diff; staging broadcast observed from **both** API and Horizon |
| 0.7 | Fix `docs/operations/BACKUP-RECOVERY.md:152-154,232` — remove `-j`, add the Timescale pairing (§4.6) | **[A]** | Diff; no `-j` remains in the file |
| 0.8 | Fix `TenantBackupService`: emit the Timescale pre/post pair **and** acquire the §4.6a Mode B fence (or refuse without a maintenance flag) | **[A]** | Tests + diff at `TenantBackupService.php:150-186,260-289` |
| **0.8a** | 🚨 **Land ALL monitor code (was Phase 5)** — O-4 scheduler heartbeat, O-6 disk dead-man, **O-7 rewritten against the Redis queue** (`MonitoringService` counts DB `jobs` rows, which read zero in production — §6.1), O-13 volume-backup age, O-14 alert canary | **[A]** | Diffs + first pings from a non-production environment |
| **0.8b** | 🚨 **Land the image build+push workflow (§7.0.2)** — was Phase 6.1/6.2. 🚨 **v4: it also emits and attests `release-set.lock.json` (§7.0.2b).** 🚨 **v5 (round-4 MAJOR 4): at Phase 0 the lock is published to the WORKFLOW ARTEFACT STORE ONLY — the copy to both offsite legs is deferred to Phase 4.3b, since the offsite legs do not exist until 4.1/4.2** | **[A]** | Five digests pushed to GHCR from a green run; **a deliberate red-CI run pushes NOTHING**; a lock artefact whose **signature verifies** and whose digests match the pushed manifests |
| **0.8b1** | 🚨 **NEW in v4 (round-3 R3-N3) — land the BOOTSTRAP DISPATCHER on `main`**, in its own separately reviewed control-plane commit, **before** 0.8i can run (§7.0.2a Change 1). Includes the `BOOTSTRAP_STATE` guard step **and 🚨 v5 (round-4 BLOCKER 3): the fixed `concurrency: {group: prod-bootstrap, cancel-in-progress: false}` group and the atomic increment-and-re-read of `attempts_used` before any credential read** | **[O]** approves the control-plane commit, **[A]** authors | The workflow file present on `origin/main`; the `concurrency` block present and non-cancelling; **a rehearsal dispatch against a scratch registry namespace proving `ref=dev` executes the DEV build definition**; a guard-step transcript showing failure when `open == false`; 🚨 **v5: a two-concurrent-dispatch test proving the second run queues (not races) and that `attempts_used` cannot be double-counted past `attempt_cap`** |
| **0.8c** | 🚨 **Land `deploy/production/compose.production.yml` + `BOOTSTRAP.md` (§7.0.3)** — secret-free, **and 🚨 v4: VARIABLE-REFERENCING (`${IMAGE_*}`, no defaults), NOT digest-pinned in git** (§7.0.2b, D-20). `BOOTSTRAP.md` gains the fetch-and-verify-the-lock step. Worker service carries `stop_grace_period` per D-21 **and the raised supervisor timeout is a build-time config (Phase 0.8k)** | **[A]** | Files checked in; `docker compose config` **fails loudly with the image variables unset**; **secret scan clean**; 🚨 **v5 (round-4 MAJOR 4): a cold-start dry run that materialises the lock from the WORKFLOW ARTEFACT STORE and comes up** — the offsite-leg (GitHub-independent) cold start is rehearsed at Phase 4.3b, after the legs exist |
| **0.8d** | Land `.github/workflows/deploy-production.yml` (§7.2) and the smoke-test parameterisation; fix/delete `sonarcloud.yml`'s non-existent `develop` branch | **[A]** | Workflow files; smoke callable with a production URL |
| **0.8e** | 🎫 Mark `docker-compose.dokploy.yml` non-production (§3.8 header banner) | **[A]** | Diff |
| **0.8f** | 🚨 **v3 — land the DB role separation (§3.4c)**: the `provisioning` connection in `config/database.php`, `ProvisioningScopedPostgreSQLDatabaseManager` registered in `tenancy.database.managers['pgsql']`, and the `DatabaseCreated` listener issuing the `GRANT USAGE, CREATE ON SCHEMA public` block | **[A]** | Diff; **a provisioning test that creates a throwaway tenant and runs its migrations as `izipos_app` with `NOCREATEDB`**; a negative test proving `izipos_app` cannot `CREATE DATABASE`; **a test proving the tenant's RUNTIME connection still authenticates as `izipos_app`, not `izipos_provisioner`** (the trap in §3.4c note 2) |
| **0.8h1** | 🚨 **NEW in v4 — land the `permissions:verify {--tenant=} {--expect=}` command (§7.4a)** with its six required tests. **This is now the whole of V-11** — v3's inline SQL is withdrawn as non-executable (round-3 R3-N4). 🚨 **v5 (round-4 MAJOR 5): SEQUENCED BEFORE 0.8g — 0.8g dry-runs `permissions:verify`, which this step creates, so it cannot precede it** | **[A]** | Command + tests green, **including hostile-quoting, empty-expect, unknown-tenant, and per-tenant physical-DB-name resolution**; a deliberate missing permission on one tenant of several ⇒ fleet continues, aggregate exits non-zero |
| **0.8g** | 🚨 **v3 — land `scripts/verify-production-release.sh`** (the V-0…V-12 suite as one script, §7.0.5), so the Phase 3.6 manual run and the Phase 6.6 workflow invoke **identical** checks; call `permissions:verify` (§7.4a) and **generate** the verifier invocations from `php artisan help` (§7.4b). 🚨 **v4: implement V-10 to the §7.4c input/pass table — both modes — and V-12 against the ATTESTED LOCK, not a checked-in file.** 🚨 **v5 (round-4 MAJOR 5): REQUIRES 0.8h1 FIRST — the `permissions:verify` command it consumes/dry-runs must already exist** | **[A]** | Script; a dry run against staging; a deliberately-failing V-2 and V-11 each **exit non-zero**; **V-10 exercised in both bootstrap modes**, including a denylisted digest after closure → FAIL, and an unverifiable lock → FAIL; the §7.4b option-set regression test red on a mutated signature |
| **0.8h** | 🚨 **v3 — land the capacity check** `/usr/local/sbin/erp-capacity-check.sh` + its systemd timer (Appendix E), and the **O-3b offsite-manifest audit** (§6) | **[A]** | Script; a synthetic breach of one class fires the alert and the corresponding enforcement command |
| **0.8j** | 🚨 **NEW in v4 — make `channels:reconcile` fail-closed (§7.7c, round-3 R3-N6)**: fail when `skippedTenantIds()` is non-empty; count `register() === false` and fail after completing the fleet | **[A]** | Diff; **a test with a known-missing physical tenant DB exits non-zero**; a test with a registrar write failure exits non-zero; per-tenant continuation preserved |
| **0.8k** | 🚨 **NEW in v5 (round-4 BLOCKER 1) — raise the worker Horizon supervisor `timeout` and the Redis `retry_after` so the Mode-B/deploy drain actually drains (§4.6a)**: `config/horizon.php:217` `'timeout' => 60` → `env('HORIZON_SUPERVISOR_TIMEOUT', 3900)` (≥ longest job `$timeout` 3,600 + margin); `config/queue.php:71` `retry_after` default 90 → `env('REDIS_QUEUE_RETRY_AFTER', 4200)` (**strictly greater** than the supervisor timeout). **The repo default 60 makes the master exit 0 after 60 s and abandon a 3,600 s job** (`MasterSupervisor.php:167-203`, `RedisSupervisorRepository.php:101-104`). This is a **config-default code change, not an owner decision** | **[A]** | Diff; **`HorizonQueueCoverageTest` green**; a rehearsal running a > 60 s job through `docker service scale=0` that **COMPLETES (exit 0, not 137)** — §4.8 step 14(a); and the RED baseline of §4.8 step 14(f) with `timeout=60` reproducing the abandonment |
| **0.8i** | 🚨 **v3 — the ONE-TIME BOOTSTRAP EXCEPTION build (§7.0.2a)**. Requires **D-15** and **0.8b1** first — 🚨 **v4: without the dispatcher on `main` this step is not merely risky, it CANNOT EXECUTE** (round-3 finding 3.1) | **[O]** approves, **[A]** dispatches | The seven audit-trail artefacts of §7.0.2a: named SHA, recorded environment approval, five `bootstrap-<sha>` tags + digests, §A clearance evidence, **the attested bootstrap lock + the variable-referencing compose commit**, the supersession record (filled later), and **the closure-transition evidence including a post-closure dispatch that fails at the guard** |
| 0.9 | **Open the `dev`→`main` PR; drive it to green `all-checks-pass`** (§7.3) — **now the LAST step of Phase 0**, containing every artefact above | **[A]** + **[O]** merge | `all-checks-pass` green **read from the GitHub UI**; merge-conflict inventory; build-drift check; **images built for every target**; SBOM/vuln scan; **rollback tag on `main` before the merge**; `origin/main` moved. 🚨 **v3: the first `workflow_run` build on green `main` SUPERSEDES the bootstrap images** — record the supersession (§7.0.2a) and confirm **V-10 in `bootstrap_state.open == false` mode passes with no denylisted digest running** (§7.4c). 🚨 **v4: supersession is a LOCK SWAP + redeploy, not a digest commit** — there is no second commit to make, which is the whole point of D-20 |

### Phase 1 — Server provisioning

| # | Step | Who | Evidence |
|---|---|---|---|
| 1.1 | Order the AX42-1 in FSN1; RAID1 across both NVMe | **[O]** | Hetzner Robot confirmation; IPv4 + IPv6 recorded |
| 1.2 | ⚠️ **Measure Tunis→FSN1 RTT** (§10 R-1) — `mtr` from a Tunis client, or a €5.49 CX23 in FSN1 vs NBG1 for a day | **[O]** | RTT numbers recorded; if > 120 ms, re-open D-1's location |
| 1.3 | Base hardening: SSH key-only, root login policy, `ufw`/nftables allowing 80/443/22 only | **[O]** | `ss -tlnp`; firewall ruleset |
| 1.4 | `/etc/docker/daemon.json` log rotation (§3.1) | **[O]** or **[A]** | `docker info \| grep -i logging`; file contents |
| 1.5 | Register as the third remote server on the panel; run Dokploy server setup | **[O]** | New `serverId` recorded; Docker + swarm + `dokploy-network` + Traefik present |
| 1.6 | Create project + `Production` environment; record IDs | **[A]** | project id, environment id |

### Phase 2 — Data services

| # | Step | Who | Evidence |
|---|---|---|---|
| 2.1 | Create **postgres** (`timescale/timescaledb:2.13.0-pg16`), named volume, **limits 2 CPU / 4 G**, **no external port** | **[A]** | `application-one` → real `appName` recorded; `pg_isready` green |
| 2.2 | 🚨 **Measure `timescaledb` presence** — `\dx` on `postgres`, on `template1`, on `iziposcentral`, and on a scratch DB created **each way** (`TEMPLATE template0` and `TEMPLATE template1`) | **[A]** | `\dx` output recorded in §4.6c's table; **the expectation stated there (tenants absent because Stancl uses `TEMPLATE=template0`) is confirmed or refuted in writing** |
| 2.3 | 🚨 **v3: create the FOUR roles** per §3.4c — `izipos_app` (**`NOCREATEDB`**, no superuser/createrole/replication, owns no database), **`izipos_provisioner` (`CREATEDB`, DDL only)**, `izipos_backup` (`pg_read_all_data`, read-only), `izipos_admin` (vault-only). Create `iziposcentral` **`WITH TEMPLATE template0`**. **No `izipostemplate` DB** (§3.2) | **[A]** | `\du` shows exactly those four with the ruled attributes; `\l` shows `iziposcentral` only; **negative tests: `izipos_backup` cannot write; `izipos_app` cannot `CREATEROLE`; 🚨 `izipos_app` CANNOT `CREATE DATABASE`; `izipos_app` cannot `DROP DATABASE` on any tenant DB; `izipos_provisioner` cannot read tenant table data** |
| 2.4 | Create **redis** with `requirepass`, `maxmemory 384mb`, `noeviction` | **[A]** | `redis-cli -a … ping` → PONG; `CONFIG GET maxmemory-policy` → `noeviction` |
| 2.5 | Create **minio** — **`command:"minio"` + `args:[...]` via direct tRPC** (the MCP wrapper lacks `args`) | **[A]** | Container running; console reachable internally |
| 2.6 | Create the media bucket (one-shot `minio/mc`, **digest-pinned image**, bucket private); **create the bucket-scoped application service account and the backup identity (§3.4b)** | **[A]** | `mc ls` shows the bucket; anonymous GET denied; three distinct identities exist |
| **2.6a** | 🚨 **MinIO negative tests (§3.4b)** | **[A]** | From outside the host: **9000 and 9001 closed**; **no Dokploy domain** on minio. With the application key: `mc admin info`, `mc admin user list`, `mc mb <other>`, and any object-lock call all **denied** |

### Phase 3 — Application services

| # | Step | Who | Evidence |
|---|---|---|---|
| 3.1 | Enter all secrets at the **project-environment** level from 1Password (§5.3) | **[O]** | Dokploy env list (names only); no `.env` file on the host |
| **3.1a** | 🚨 **Pre-create ONE external `appstorage` volume on the host** (§3.3) | **[A]** | `docker volume inspect` |
| 3.2 | Create **api** — **`sourceType: docker`, digest-pinned GHCR image (§7.0), NO git provider, autoDeploy OFF** — full §3.4 env, external `appstorage` mounted | **[A]** | `appName` recorded; running digest matches the approved release; `/health` 200 |
| **3.2a** | 🚨 **Prove the shared volume across separate Applications** (§3.3) | **[A]** | Write from `api`, read from `worker` **and** `scheduler`; **redeploy each of the three independently and re-read after each**. On failure, fall back to a single Compose stack and record the change |
| 3.3 | Create **worker** (digest-pinned, **2 G starting cgroup**), **scheduler**, **websocket** (**1 replica**), each with the §3.4a Reverb publisher variables | **[A]** | `horizon:status` running; 6 queues visible; `nc -z localhost 8080`; broadcast observed from the worker |
| 3.4 | Create **web** — digest-pinned image **built with `VITE_API_URL` and `VITE_REVERB_APP_KEY`**, runtime `API_URL` + `WS_URL` | **[A]** | Built bundle **does not** contain `local_key`; `/health` 200 |
| 3.5 | Attach domains + Let's Encrypt | **[A]** | `curl -vI https://riserpos.app` — valid cert, correct SAN |
| 3.6 | Run the full §7.4 **V-0…V-12** suite — 🚨 **v3 (round-2 F36): by invoking `scripts/verify-production-release.sh`, the SAME script the Phase 6.6 workflow will call.** The first deploy is not manually attested against a different checklist; Phase 6.6 changes only *who invokes it* | **[A]** | All pass, output archived **in the workflow's output format**. **V-9's load test may run here or in Phase 7, but it is a launch gate either way.** V-10 will report `bootstrap-<sha>` digests at this point — expected, and its sunset is tracked by H-21 |

### Phase 4 — Backups (before any real data exists)

| # | Step | Who | Evidence |
|---|---|---|---|
| 4.1 | Create the Object Storage bucket in the **other** German DC, **object lock enabled at creation** (mode + duration per **D-11**: interim governance/90 d), **explicit lifecycle rules per the §4.5a mapping**, and **three separate credentials** (host write-only, owner console, restore-capable for the break-glass package) | **[O]** | Bucket config; lifecycle rules recorded verbatim |
| **4.1a** | 🚨 **Run the six §4.5a WORM tests** — including test 6, which must **succeed** on the restic leg | **[A]** | Six transcripts. **"A compromised host cannot destroy the WORM leg" becomes an evidenced statement, not an assertion** |
| 4.2 | Order the Storage Box; create a sub-account; `restic init` | **[O]** | `restic snapshots` responds; `RESTIC_PASSWORD` in 1Password **and** in the break-glass package (D-12) |
| 4.3 | Write, install and dry-run `/usr/local/sbin/erp-backup.sh` per **§4.4a–d** (central-first ordering, tenant list from the central dump, `MANIFEST.json`, source metadata, L4a media sync, all 14 hardening properties) | **[A]** | One manual run producing a **complete** cycle; both offsite legs contain it; **manifest orphan/missing lists present and empty** |
| **4.3a** | 🚨 **Globals credential acceptance test (§4.6c step 4 / §4.8 step 15)** — restore `globals.sql` into a scratch cluster and authenticate a role with its original password | **[A]** | Pass/fail recorded. **On fail, credential rotation becomes a mandatory documented step of every cluster restore** |
| **4.3b** | 🚨 **NEW in v5 (round-4 MAJOR 4) — now that the offsite legs exist (4.1/4.2): copy the current `release-set.lock.json` to BOTH offsite legs, and rehearse the GitHub-INDEPENDENT cold start** (materialise + verify the lock from an offsite leg with the panel **and** GitHub deliberately unused — §7.0.2b). Deferred here from Phase 0, which is artefact-store-only because these legs did not exist yet | **[A]** | The lock present and signature-verifying on both legs; a cold-start dry run that fetches the lock **from an offsite leg** (not the artefact store) and comes up; DR-3 step 3a/4a/4b exercised |
| 4.4 | Install the **systemd timer + service** (not a bare crontab line — §4.4d); register the healthchecks.io checks (O-3, O-13) | **[A]** | Two consecutive automated cycles land; two pings received; `systemctl list-timers` shows the schedule |
| 4.5 | Configure Dokploy volume backups: `miniodata` nightly **with container-stop** (secondary to L4a), `appstorage` + `redisdata` nightly | **[A]** | One successful run each; a restore of a **test file** from the `miniodata` backup |
| **4.5a** | 🚨 **v3: verify the L4a append-only media leg end to end, INCLUDING the delete and replace cases the round-2 re-gate required** (§4.5a1) | **[A]** | (1) Upload a product image → next cycle's `media-inventory.json` lists its **key and hash**, `media.check_result == "ok"`; (2) **delete** it from MinIO → the next cycle still succeeds, the object is **still present** in the Object Storage prefix (deletions do not propagate — D-18), and the **restic mirror (L4a″) no longer contains it**; (3) **replace** an object under the same key → `rclone copy --immutable` **fails the cycle loudly** and the cycle stays `staged`; (4) restore the deleted object from the Object Storage prefix and verify it byte-for-byte against the inventory hash; (5) record whether `check_mode` is `checksum` or `download` (**H-20**) |
| 4.6 | Export and vault the Dokploy env-encryption keyring (L6) | **[O]** | Keyring item in 1Password, dated |
| 4.7 | **Break the backup on purpose** (rename the rclone config) and confirm the dead-man fires | **[A]** | Telegram alert received; restore config; next ping green |

4.7 is not optional. **An untested alarm is a decoration.**

### Phase 5 — Observability activation

> **The monitor *code* moved to Phase 0.8a** (Finding 33). Phase 5 is now only the external-service configuration that needs a live production host to point at.

| # | Step | Who | Evidence |
|---|---|---|---|
| 5.1 | UptimeRobot monitors O-1, O-2 | **[O]** | Both green; one deliberate downtime produces an alert |
| 5.2 | healthchecks.io checks O-3, O-4, O-6, **O-13** | **[A]** | Four checks green |
| 5.3 | Point the Phase-0 heartbeat/disk/queue monitors at the production endpoints | **[A]** | First pings; `df` log |
| 5.4 | Sentry production project; DSN into env | **[O]** DSN, **[A]** wiring | A deliberate test exception appears in Sentry |
| 5.5 | Dokploy notifications → Telegram, incl. **database-backup and volume-backup** event types | **[O]** | Test notification received |
| 5.6 | O-7 queue-depth check **against Redis** (§6.1) | **[A]** | Alert fires on a synthetic backlog **that the old DB-`jobs` counter would have reported as zero** |
| **5.7** | 🚨 **O-14 alert canary + escalation to the second custodian (D-12)** | **[O]** + **[A]** | Synthetic alert delivered on **both** channels and **acknowledged**; an unacknowledged test escalates within the defined interval |

### Phase 6 — Deploy pipeline activation

> **The registry workflow, production deploy workflow, manifest, and smoke parameterisation moved to Phase 0.8b–0.8d** (Finding 33) — they must be in the release commit. Phase 6 is now the panel/GitHub-side configuration that requires the production Applications to exist.

| # | Step | Who | Evidence |
|---|---|---|---|
| 6.1 | Create the Dokploy **registry credential** for GHCR and point the five production apps at their digests | **[O]** creds, **[A]** wiring | A digest pulled and deployed with **no source build on the host** |
| 6.2 | *(moved to 0.8d)* — verify here that a **deliberate red-CI run does NOT deploy** | **[A]** | Red-run transcript showing no deployment |
| 6.3 | Configure the `production` GitHub environment with a required reviewer | **[O]** | Setting screenshot; an approval prompt observed |
| 6.4 | Store `DOKPLOY_API_KEY` as a GitHub secret **and in the break-glass package (D-12)** | **[O]** | Workflow authenticates; package item recorded |
| 6.5 | Smoke run green against production; confirm `sonarcloud.yml` now fires | **[A]** | Both run transcripts |
| 6.6 | Wire the workflow to invoke `scripts/verify-production-release.sh` (**V-0…V-12**, unchanged from Phase 3.6) + the §7.7b drain steps + the §7.7c **PM-1 `channels:reconcile`** post-migrate step, and add the `sync_permissions` boolean input (§7.5) that feeds V-11's `expected-permissions.txt` | **[A]** | A synthetic V-2 failure **fails the job**; a synthetic V-10 digest mismatch **fails the job**; a synthetic V-11a `missing=1` **fails the job**; a non-zero `channels:reconcile` exit **fails the job** |
| **6.7** | **Monthly last-known-good pull test** scheduled (DR-3, §7.0.5) | **[A]** | First pull recorded |
| **6.8** | 🚨 **v3 — create `deploy/production/RELEASE-SETS.md`** with the bootstrap set recorded, and rehearse the §7.0.5 **rollback** once against a deliberately-superseded set | **[A]** | The nine rollback steps executed on a non-production-critical release, including the step-2/3 schema decision, with a timed transcript |

### Phase 7 — Rehearsal and gate closure

| # | Step | Who | Evidence |
|---|---|---|---|
| 7.1 | Walk §7.6's checklists; mark every line `applies` / `n/a-greenfield` **with a reason** | **[A]**, **[O]** approves | Annotated checklist set |
| 7.2 | Provision a throwaway tenant; confirm `izipostenant_<uuid>` naming | **[A]** | `\l` output |
| 7.3 | **Execute the §4.8 restore rehearsal, all 15 steps** (v2 added the skew, both-Timescale-branch, fence, and globals-credential tests) | **[A]**, **[O]** witnesses | Full transcript → **gate E-3** |
| 7.4 | Execute the E-3 migration-audit procedure on production (backup + checksum + five fiscal row counts + pretend-run + schema diff + `migrate:status` + verifiers) | **[O]** | `docs/qa/2026-05-12-migration-audit-and-rollback.md` evidence cells filled |
| 7.5 | Complete **E-1**: rotate every row incl. the five NEW ones (§5.2); ledger + sign-off | **[O]** | `docs/security/secret-rotation-2026-05-12.md:53-67` all `revoked`; `:254` ledger; `:269` sign-off |
| 7.6 | Record the **E-10 environment decision** ("tenant #1 runs on production") + release revision + fresh just-in-time backup | **[O]** | Gate-sheet Status cell |
| 7.7 | Tear down the throwaway tenant; confirm backups stop enumerating it **and that the manifest records no orphan** | **[A]** | `\l`; next cycle's manifest |
| **7.9** | 🚨 **FULL-HOST REHEARSAL, all 10 steps of §4.9, with the Dokploy panel deliberately unused** | **[A]**, **[O]** witnesses | Timed transcript. **The p95 total is the committed total-host RTO (D-14).** Until this passes, DR-3 is a hypothesis |
| **7.10** | 🚨 **Break-glass rehearsal (§5.4)** — second custodian, clean machine, host and primary laptop assumed unavailable | **[O]** ×2 | Access proven to 1Password, GHCR, Object Storage, DNS. No production change made |
| **7.11** | 🚨 **V-9 worker load test** (§7.4) if not already run in Phase 3.6 | **[A]** | Measured peak RSS; cgroup or `maxProcesses` adjusted on the evidence |
| **7.12** | 🚨 **Fiscal-retention sub-gate closed as gate-sheet row `E-4a` — NON-WAIVABLE (§4.5b/§4.5b1)**. **Prerequisite: D-16, the owner's insertion of the E-4a row and the two hard-rule amendments** | **[O]** | The E-4a row exists in `OWNER-manual-launch-gates-2026-07-31.md` **and** is closed with a named legal/accounting approver, dated, with data-class-specific retention periods. **An owner risk acceptance does NOT close it.** Until D-16 is done, this cell reads `BLOCKED — awaiting E-4a` |
| 7.8 | Onboard tenant #1 — **only after E-1…E-10 and E-4a are closed, E-7 and E-4a non-waivable, AND 7.9/7.10/7.11/7.12 are complete**. 🚨 **v3 adds one more: V-10 must report NO `bootstrap-` digest running** (§7.0.2a sunset, H-21) | **[O]** | Gate sheet fully closed; V-10 transcript |

---

## 9. Expansion path

### 9.1 Database growth

Measured, not estimated [A15][R3]: an essentially **empty** tenant DB costs **~24–25 MB** (236 tables + indexes dominate); the whole staging cluster is **272 MB** across 8 tenants.

| Tenants | Schema-only | With realistic data (20× today's largest) | Hourly backup cycle |
|---|---|---|---|
| 1 | ~25 MB | ~50 MB | seconds |
| 10 | ~250 MB | ~1–2 GB | seconds |
| 50 | ~1.25 GB | ~5–10 GB | tens of seconds |
| 200 | ~5 GB | ~20–40 GB | **⚠️ re-evaluate** |

**Thresholds and what to do at each:**

| Trigger | Action |
|---|---|
| **Backup cycle > 5 min** | **Early-warning signal only (v2).** The pgBackRest decision is **date-bound under D-10** (`launch + 30 days` or tenant #3, whichever first) and no longer contingent on this threshold. If adopted: **pgBackRest, `repo1-type=sftp` → Storage Box** [D5] for continuous WAL; keep the logical loop for per-tenant restores — PITR is cluster-wide only and single-tenant PITR means restore-to-scratch then extract [D3] |
| **Local backup staging > 15 % of its mount** | Alert. At 20 %, the cycle prunes oldest-first automatically (§4.4d) and the alert escalates — the count-based "48 hourly" tier is a maximum, not a promise |
| **Sustained Postgres RSS > 3 GB, or CPU contention with builds** | Move Postgres to its own host (see 9.2) |
| **> ~50 tenants, or the first tenant with genuine transaction volume** | Move Postgres to its own host regardless |
| **Disk > 60 %** | On AX42's ~512 GB usable: order a Storage Box/volume for the local backup staging dir first, then plan the split |
| **Sustained > 100 req/s** | Scale php-fpm children, then API replicas. **Not** a near-term concern: modelled peak is 10–25 req/s against ~320 req/s theoretical for one pool [R1] |

### 9.2 When Postgres gets its own host

**Not before the triggers above.** Splitting at one tenant buys isolation you don't need and doubles the ops surface [R4-D].

When it happens: a second Hetzner box on a **private vSwitch** (dedicated) or private Network (cloud), Postgres alone on it, app services pointing `DB_HOST`/`DB_DIRECT_HOST` at the private IP. **PgBouncer still does not enter the picture** unless and until session pinning exists for the tenant DB swap — that is a code-level prerequisite [A4.2], not a config toggle.

### 9.3 Adding the second production tenant

Tenant #2 needs no infrastructure change:
1. Provision via the normal signup path → a new `izipostenant_<uuid>` DB.
2. **Backups pick it up automatically** — 🔧 **v3 correction (round-2 N5): §4.4a enumerates the tenant list FROM THE CENTRAL DUMP, not from `pg_database`.** v2 left this sentence saying `pg_database` here, contradicting the central-dump-authoritative rule that §4.4a exists to establish. `pg_database` is still read, but only to compute the **orphan** list (§4.4a step 4). (Automatic pickup is precisely what Dokploy's per-DB schedules could not do [C1].)
3. Run the §7.6 tenant-scoped configuration steps (chart of accounts, banks, CARD routing, cash rounding).
4. Add its fiscal chain to the monthly restore-rehearsal rotation.

### 9.4 Second vertical (Otospex)

`otospexcentral` + `otospextenant_<uuid>` on the **same** cluster is fine at this scale — separate databases, separate `DB_CENTRAL_DATABASE`, separate app service set, one Postgres. Split to a second cluster only when 9.1's triggers fire for either vertical.

🚨 **v1 named only separate databases, which is nowhere near enough (Finding 27).** Separate databases isolate *rows*. They isolate nothing else, because almost every other namespace defaults off `APP_NAME`:

| Shared surface | Default | Consequence if only the DB is separated |
|---|---|---|
| Redis key prefix | derives from `APP_NAME` (`config/database.php:190-207`) | two verticals sharing a keyspace if `APP_NAME` collides |
| Cache prefix | derives from `APP_NAME` (`config/cache.php:104-116`) | cross-vertical cache reads |
| Queues | **same Redis connection, same queue names** (`config/queue.php:67-74`) | 🚨 **an Otospex job can be consumed by an IziPOS worker** — the worst of these, because it crosses tenancy at the job level |
| Horizon metadata | `HORIZON_PREFIX` (`config/horizon.php:59-75`) | isolates **only the dashboard metadata** — v1's "change `HORIZON_PREFIX`" isolates the *reporting*, not the *work* |
| Reverb | shared credentials/scaling traffic if not separated | cross-vertical broadcast leakage |

**Ruling — before Otospex is added, specify and test:** distinct Redis **databases or instances** with distinct credentials; distinct cache/session prefixes; **distinct queue namespaces** (not just `HORIZON_PREFIX`); distinct DB roles per §3.4c; distinct MinIO buckets and application keys per §3.4b; distinct Reverb app credentials; and distinct alerting identities so an Otospex page is distinguishable from an IziPOS page at 3 a.m. **Add cross-product isolation tests** — the specific assertion that matters is that a job dispatched by one vertical is never consumed by the other's workers.

### 9.5 Storage scaling

MinIO on-box (~1–5 GB for one retail catalogue [R3]) is right until either media exceeds ~50 GB or off-box durability becomes the binding requirement. Migration to Hetzner Object Storage is a one-time `rclone sync` **into the application's own media bucket** — 🚨 **not** into the append-only backup prefix, where `sync` is forbidden (§4.5a1); this is a different destination with a different credential — plus an env change — **gated on verifying path-style addressing**, since the app sets `AWS_USE_PATH_STYLE_ENDPOINT=true` and Hetzner OS is virtual-hosted-style by default [R7].

### 9.6 What is deliberately NOT on the path

POS device update distribution. `apps/pos` is a Tauri 2 desktop app with no Dockerfile; **there is no update-feed service anywhere** and device updates are out-of-band today [A8]. If production IziPOS devices need an update channel, that is **net-new product work**, not an infrastructure line item — and it should be raised now, because "how do we ship v65 to the customer's terminal" is a question with a manual answer today.

---

## 10. Open risks and unknowns

| # | Risk | Severity | Evidence | Mitigation / owner |
|---|---|---|---|---|
| **R-1** | ⚠️ **Tunis→FSN1/NBG1 RTT is UNVERIFIED.** Research could not retrieve authoritative figures; the 35–55 ms band is geographic reasoning only [B3] | Medium | [B3][R4] | **Measure in Phase 1.2** before committing. POS is offline-first (device SQLite), so sale completion is a queued sync, not a synchronous round trip — 40 vs 70 ms is tolerable. But dashboard UX is not offline-first |
| **R-2** | **LTD server tiers are "as long as supply lasts"** — EX44-1-LTD / AX41-1-LTD can vanish | Low | [B2] | Only bites if the owner picks Option B in D-1. The AX42-1 recommendation is a stable SKU |
| **R-3** | ⚠️ **Storage Box price (~€3.20 BX11) is third-party**, not from hetzner.com (client-side rendered, unscrapeable). Object Storage EUR figures also carry a conflicting third-party listing | Low | [B4][B5] | Confirm both in the order form / console before budgeting. Magnitude is not in doubt (single-digit €/mo) |
| **R-4** | 🚨 **Both backup legs are Hetzner.** A Hetzner-wide event or account suspension loses production *and* both copies | **High** | [R5] | **Accepted at launch, with a named exit:** add an off-provider third leg (Cloudflare R2 or Backblaze B2) as soon as tenant #1 has real fiscal data. ⚠️ R2/B2 pricing was **not verified** this session. **This is the single largest residual risk in this design and the owner should be told so in those words** |
| **R-5** | 🚨 **`origin/main` is 4,303 commits behind** — the dev→main promotion is a project with unknown red-CI surface | **High** | measured this session | Phase 0.9. Start it **first**; it has the longest and least predictable lead time of anything here |
| **R-6** | 🐞 **`VITE_REVERB_APP_KEY` is never passed** — shipped bundles use `'local_key'` while the server enforces the real key, so realtime silently fails **on staging today** | Medium | `apps/web/Dockerfile:51`; `apps/web/src/lib/echo.ts:27`; no compose passes it | **v2:** Phase 0.6 per **§7.0.4** — pass it in every path, **fail the production build when absent**, CI bundle inspection, V-6 canary. **This is a live staging defect** today, and R-24 is a *second, independent* reason realtime is broken there |
| **R-7** | 🐞 **`Storage::disk('private')` has no configured driver** — `InvoiceService.php:234,246` will throw `Disk [private] does not have a configured driver` | Medium | [A13] | Fix before tenant #1 issues its first invoice through that path. **Out of this document's scope — ticket it** |
| **R-8** | ⚠️ **Whether `timescaledb` is installed in ERP databases is unmeasured.** No migration installs it; no `create_hypertable` exists; the image conventionally seeds `template1` | Medium | verified this session | Phase 2.2. Both outcomes handled by the §4.6 conditional — but the restore procedure must not be written against a guess |
| **R-9** | **`tenant:restore` lacks the Timescale pre/post pairing** | Medium | `TenantBackupService.php:260-289` | Phase 0.8, or use only the §4.6 scripted path |
| **R-10** | **No cross-database transactional consistency.** `pg_dumpall` and a per-DB loop both snapshot each database at a *different* instant. Only cluster-wide PITR delivers instant-agreement | **High** *(raised from Medium in v2)* | [D1] PostgreSQL §25.1; Finding 7 | 🔧 **v1's mitigation — "the next cycle heals it" — is WITHDRAWN. It is wrong for a disaster: after a disaster there is no next cycle.** v2 substitutes: (1) **central-first capture with the tenant list read from the central dump** (§4.4a), which eliminates the list-vs-central class of skew entirely; (2) **manifest + both-direction reconciliation at restore time** (§4.4c), which *detects* the remaining provisioning-window skew and forces an operator decision instead of silently restoring a broken set; (3) **D-10's dated pgBackRest decision** as the real fix. **Residual, stated plainly: cross-DB skew remains possible in the provisioning window and is detected, not prevented** |
| **R-11** | **Dokploy panel is a single point of control** — no supported migration from remote-server to standalone (issue #3508) | Medium | [C4] | Accepted deliberately. **Backups live in host systemd specifically so a dead panel is not a data-loss event** (DR-4). 🔧 **v3 correction (round-2 N5): v2 still said "deploys stop" here, contradicting §7.0.3 and DR-4.** With the checked-in manifest on the host, a panel outage does **NOT** stop deploys — `docker compose up -d <service>` against a new digest is the documented hotfix path (§7.0.3, §7.0.5 step 6 "panel-independent path"). What a panel outage *does* stop: panel logs, the restore UI, volume backups (hence O-13), and panel-driven alerts |
| **R-12** | **OSS/Cloud default is 1 concurrent build per server** (max 2 as of v0.29.9) | Low | [C3] | 🔧 **v3 correction (round-2 N5): v2 justified this with "the 64 GB production box means a queued build never starves the running app", which is stale — production has NO git provider and performs NO builds** (§7.0.2, decision 13). The build-concurrency limit therefore applies only to the **staging** server and to CI, and is irrelevant to production capacity |
| **R-13** | **Dokploy volume-backup retention bug #2686** ("volume backups delete other volume backups") | Low | [C2] | Verify retention after the first two nightly runs (Phase 4.5). Postgres does not depend on this path |
| **R-14** | **Reverb cannot scale horizontally** — `REVERB_SCALING_ENABLED` defaults `false` | Low | [A11] | One replica, enforced. Enabling Redis scaling is a config + test task if a second is ever needed |
| **R-15** | **`sonarcloud.yml` watches a non-existent `develop` branch** — it has never fired on real work | Low | [A10] | Phase 6.5: point at `dev`/`main` or delete. A workflow that never runs is worse than no workflow, because it reads as coverage |
| **R-16** | 🚨 **`docs/operations/BACKUP-RECOVERY.md` currently instructs a TimescaleDB-forbidden restore** (`-j 4` / `-j 2`) | **High** | `:152-154`, `:232` vs [D4] | Phase 0.7. Until fixed, that document is a trap for anyone restoring under pressure — which is exactly when people follow written procedures without questioning them |
| **R-17** | **`docs/operations/STAGING-SETUP.md` and `apps/api/.env.production.example` are both pre-tenancy-flip** — `DB_CONNECTION=pgsql`, `AUTO_SEED=true`, no central/tenancy/pgbouncer vars | Medium | [A8][A9] | **Do not copy either into production.** §3.4 is the authoritative env contract. Correcting them is out of scope here — ticket it |
| **R-18** | **`DEPLOYMENT.md` is materially stale** ("Last Updated: December 2025") — never mentions `tenants:migrate-rolling`, `SYNC_PERMISSIONS_ON_BOOT`, split containers, or `DB_DIRECT_HOST` | Low | [A7] | Same: do not use it. Ticket a rewrite pointing at this document |
| **R-19** | 🚨 **Container boot is not evidence.** Five distinct entrypoint failures still yield a serving API container (F-2) | **High** | `entrypoint.sh:103-167,207` verified | V-0…V-2 and V-11 as out-of-band deploy gates (§7.4). **The residual is that a *manual* container restart bypasses the gate** — so any manual restart must be followed by the V-suite, and that must be in the runbook |
| **R-20** | 🚨 **Stored `tenancy_db_name` precedence.** The prefix env change affects only new tenants and silently changes behaviour for rows missing the stored name (F-4) | **High** | `DatabaseConfig.php:39-41,66-69,81-97`; `Tenant.php:138-179` | Phase 0.5a blocking audit + backfill + regression tests. ⚠️ Live row state **UNVERIFIED** until the audit runs |
| **R-21** | 🚨 **Media was outside the RPO/RTO scope in v1** — DR-3 would have restored a complete database referencing non-existent objects | **High** *(now mitigated)* | Finding 13 | L4a hourly media offsite leg + explicit per-volume RPO/RTO (§3.5) + DR-3 steps 9–10. **Residual: `appstorage` remains ≤ 24 h, and its logs are the accepted loss** |
| **R-22** | ⚠️ **Cross-Application shared volume behaviour is UNVERIFIED.** Three Dokploy Applications may or may not be able to mount one external volume safely across independent redeploys | Medium | Finding 3 | Phase 3.2a proves it. **Named fallback: collapse api/worker/scheduler into one Compose stack.** Do not discover this after tenant #1 |
| **R-23** | ⚠️ **GHCR private-package egress cost/quota is UNVERIFIED** and production pulls on every deploy and every DR event | Medium | D-9 | Owner checks the billing console at decision time. **v1's "GHCR: €0.00, free at this volume" is withdrawn** |
| **R-24** | 🚨 **`REVERB_HOST`/`PORT`/`SCHEME` are unset everywhere**, so server-side broadcasts publish to a null host on 443/TLS. **Realtime is broken on staging today for two independent reasons** (this and R-6) | **High** | `config/broadcasting.php:31-43` verified; `docker-compose.dokploy.yml:42-46` | Phase 0.6a + §3.4a's two-publisher V-6 test |
| **R-25** | 🚨 **The application currently runs with MinIO root credentials**, and one DB role holds `CREATEDB` plus ownership plus backup access | **High** *(materially reduced in v3)* | `docker-compose.dokploy.yml:36-41,105-113`; Findings 24, 26; round-2 F24/F26 | §3.4b/§3.4c identity split + Phase 2.3/2.6a negative tests. 🔧 **v3 replaces v2's residual, which claimed the split was impossible.** Stancl issues `CREATE`/`DROP DATABASE` over `getTemplateConnectionName()` (`DatabaseConfig.php:149-165`), **not** over the request-serving connection, so `izipos_app` now holds **`NOCREATEDB` and owns no database**. **New residual, narrower and owner-accepted under D-19: the `provisioning` credential must sit in the API container's env because signup provisions synchronously (`TenantProvisioningService.php:117`).** Worker, scheduler and websocket never receive it |
| **R-28** | 🚨 **NEW in v3 — media deletions do not propagate offsite, which is a data-protection exposure, not only a storage one** | **Medium** | §4.5a1; D-18 | The append-only WORM leg is deliberate. **Erasure requests must be executed against the offsite copy separately, with the owner-held console credential.** ⚠️ Whether a governance-mode lock permits a console-side erase within its window is **UNVERIFIED** (Phase 4.1). The restic mirror (L4a″) does reflect deletions |
| **R-29** | 🚨 **NEW in v3 — production runs `bootstrap-<sha>` images built from a `dev` SHA until the first green-`main` build** | **Medium** | §7.0.2a; D-15; H-21 | Bounded by Phase 0.9, which is the very next step; **no tenant data exists in the window**; the approval is recorded immutably; V-10 fails any `bootstrap-` digest after supersession and **tenant #1 cannot onboard while one is running** (Phase 7.8) |
| **R-30** | 🚨 **NEW in v3 — a single-tenant restore pauses the queue fleet-wide** | Low at one tenant, **rising with tenant count** | §4.6a Mode B residual; D-17; H-19 | Accepted at launch. One supervisor, six shared queues (`config/horizon.php:201-249`). 🎫 Per-tenant queue partitioning ticketed; revisit at tenant #5 or if DR-1 becomes routine |
| **R-31** | 🚨 **NEW in v3 — a held `horizon:pause` gets the worker container restarted by its own healthcheck**, which silently resumes job consumption mid-fence. 🔧 **v4 raises the severity and the mechanism**: the unhealthy window opens after **90 s** (three 30 s checks) while a single job may legitimately run for **3,600 s** (`ProcessImportJob.php:50-65`), and SIGTERM is not honoured until `runJob()` returns (`Worker.php:184-202,790-798`) — so v3's "pause, then stop" closed the trap **after** the unsafe interval, not before it | **High** *(raised from Medium in v4)* | `apps/api/Dockerfile:165-166` vs `StatusCommand.php:32-51`; `ProcessImportJob.php:50-65`; `Worker.php:184-202,790-798` | 🚨 **v4: `horizon:pause` is removed from every procedure in this document.** §4.6a and §7.7b now **terminate and stop inside the same drain window**, with the task ID recorded beforehand and a no-replacement assertion afterwards, and `stop_grace_period` > the longest job timeout (**D-21**). 🎫 Ticket (now optional rather than load-bearing): make the worker healthcheck tolerate `paused` as healthy, which would make pause a safe primitive again |
| **R-32** | 🚨 **NEW in v3 — the fiscal-verifier command contracts changed on 2026-08-05 and three launch documents still describe the old ones** | Medium | §7.4b; `VerifyFiscalChainsCommand.php:48-52`; `VerifyPosChainCommand.php:53-57` | 🎫 Tickets for `docs/runbooks/fiscal-verify-all-chains.md`, `docs/qa/2026-05-12-first-tenant-smoke.md:117-118`, and the E-2/E-3 gate rows' "all three verifiers with `--actor-id`" phrasing (**`--actor-id` exists on `fiscal:verify-event-chain` only**). **E-2 and E-3 cannot be executed against stale command text**. 🔧 **v4: the contracts changed AGAIN on 2026-08-05 (`--fix` removed from `fiscal:verify-chains`), which is why §7.4b now generates its command list from `php artisan help` under a regression test rather than restating signatures by hand** |
| **R-33** | 🚨 **NEW in v4 — the `release-set.lock.json` is a new single point of failure for a cold start** | Medium | §7.0.2b; D-20 | Mitigated by triple placement (artefact store + **both** offsite legs) and by the V-10-output reconstruction path (§7.0.2b failure mode 1). **The residual is real and is the price of breaking the digest fixed point**: a git checkout alone no longer tells you what to run. DR-3 steps 4a/4b make it explicit rather than discovered under pressure, and §4.9's full-host rehearsal **must exercise the from-a-backup-leg path**, not the convenient artefact path |
| **R-34** | 🚨 **NEW in v4 — a worker stop can block for up to the longest job timeout (3,600 s today), extending a fleet-wide queue outage during any Mode B restore or drain-requiring deploy** | Medium | D-21; `ProcessImportJob.php:50-65` | Owner-accepted under **D-21**, bounded and measured by **H-19**. 🎫 Named exit: a dedicated long-import queue + worker service with its own grace period, so the general drain is bounded by seconds. **Do not "fix" this by shortening `stop_grace_period`** — that converts a slow drain into a mid-job kill, which is the defect, not the cure |
| **R-35** | 🚨 **NEW in v4 — `TenantScopedCommand` records a known-missing tenant database as `skipped` WITHOUT failing the aggregate**, so scheduled fleet commands can exit 0 having visited nothing | Medium | `TenantScopedCommand.php:261-318,347-360`; `ChannelReconcileCommand.php:63-88,164-178`; `ChannelWebhookDirectoryRegistrar.php:29-45,56-81` | Phase **0.8j** fixes it for `channels:reconcile` (the one this design gates a deploy on). 🎫 **Ticket: audit every other scheduled `TenantScopedCommand` for the same hole** — it is a base-class behaviour, so `channels:reconcile` is where it was noticed, not where it is confined. Until then, **exit codes of fleet commands are corroborating evidence, never sole evidence** |
| **R-36** | 🚨 **NEW in v4, sharpened in v5 — a retention policy that is not CYCLE-aware could split a cycle's snapshot triple**, leaving data without its completion marker (or the reverse) and silently recreating the false-completion class. 🚨 **v5: `--group-by tags` is worse than that — it self-groups on the unique `cycle=$TS` tag and never ages cycles at all, so the repository grows without bound** | Medium | §4.4b2 selection rule 4 | Cycle-level enumeration + `restic forget` of all three role snapshots per expired cycle as a unit, then `restic prune`, then `assert_cycle_retention_invariant` (exactly one bound `data`+`complete`+`attestation` per retained cycle) after every prune (§4.4d step 9b). **The assertion is in the script, not in the convention** — a retention policy is exactly the kind of thing that gets tuned later by someone who has not read §4.4b2 |
| **R-26** | ⚠️ **The "audit chain lives in TimescaleDB" claim is not implemented.** `audit_events` is an ordinary tenant table; no migration installs the extension or creates a hypertable; Stancl uses `TEMPLATE=template0` so tenant DBs cannot inherit one | Low *(documentation risk, not data risk)* | verified this session; `PostgreSQLDatabaseManager.php:32-35` | Phase 2.2 measures reality. 🎫 Ticket to reconcile the stack documentation. **Backups capture the table either way** — the risk is that someone plans around a capability that is not there |
| **R-27** | **Single-human operations.** One person holds every credential and receives every alert | **High** | Finding 20; `OWNER-manual-launch-gates-2026-07-31.md:18-25` | **D-12** second custodian + break-glass package + §6.2 escalation. **This is a people problem with an infrastructure symptom and it cannot be fixed by more automation** |

---

## 11. Review disposition — all 38 findings

Source: `docs/superpowers/reviews/2026-08-05-production-env-design-review.md` (verdict **REJECT**). Every BLOCKER and REQUIRED finding has an explicit disposition. **Argued-back** entries appear only where this session had evidence the reviewer did not; each states that evidence.

> 🚨 **v3 reading note.** The table below is the **v2 record** and is preserved unedited as history. **Sixteen of these dispositions were re-gated in round 2 and did not survive intact** — findings **1, 7, 9, 13, 14, 16, 17, 21, 24, 26, 28, 30, 31, 32, 33, 34, 35, 36, 37, 38**. Where a row below conflicts with **§11.3** or **§11.4**, **the LATER section wins** — §11.4 (round 3) over §11.3 (round 2) over this table — and the section references in the v2 rows (e.g. "Phase 0.3" for the keyring, `rclone sync` for L4a, "three identities" counts) may point at text v3 or v4 has since rewritten or renumbered.

**Legend:** ✅ ACCEPTED — change made, section named · 🔧 ACCEPTED WITH VARIATION — the defect is real, the fix differs · ⚖️ PARTIALLY ARGUED BACK — substance accepted, a specific sub-claim corrected · ⏭️ DEFERRED — with a reason and a date/gate.

### 1. Claims against code

| # | Sev | Finding | Disposition | Where |
|---|---|---|---|---|
| **1** | REQ | Entrypoint failure semantics broader than admitted — 5 failure classes all still boot | ✅ **ACCEPTED.** F-2 rewritten from one trap to a five-row table; migration/permission state is an explicit out-of-band release gate; V-0 and V-11 added | §1.3 F-2, §7.4 |
| **2** | REQ | Tenant DB names are **stored** first, derived only as fallback; prefix env changes little and changes the wrong rows | ✅ **ACCEPTED — this materially changed the design.** New F-4; §3.2 gains a blocking audit + backfill + regression tests as Phase 0.5a; new risk R-20 | §1.3 F-4, §3.2, §8 Phase 0.5a |
| **3** | REQ | Checked-in Dokploy compose is not the proposed topology; a repo file must not advertise an incompatible production deploy; shared-volume-across-Applications is UNVERIFIED | ✅ **ACCEPTED in full.** New §3.8 source-of-truth ruling (compose marked non-production via ticket; checked-in production manifest is SoT); one-shot bucket job restored as service row 9 and the "8 vs 10" framing corrected; Phase 3.2a proves the shared volume with a named fallback | §3.3, §3.7, **§3.8**, §7.0.3, §8 Phase 3.2a, R-22 |
| **4** | REQ | Vite needs no Dockerfile change — the gap is build configuration | ✅ **ACCEPTED.** Phase 0.6 rephrased as build-config wiring in all four paths, **plus fail-the-build when absent in production** and CI bundle inspection | **§7.0.4**, §8 Phase 0.6 |
| **5** | 🚨 **BLOCK** | Reverb contract incomplete — `REVERB_SERVER_HOST` ≠ the publisher variables; broadcasts go nowhere | ✅ **ACCEPTED.** Full §3.4a with both env matrices, the four-step deployment test, and the *publish-from-Horizon* case that a handshake test would miss; new R-24; Phase 0.6a | **§3.4a**, §7.4 V-6 |
| **6** | REQ | 2 GB is not a defensible floor; `php.ini` allows 256 MB/process | ✅ **ACCEPTED.** The `10×128+64` argument is withdrawn; 2 G is a *starting cgroup*; **V-9 measured load test with recycle overlap is a launch gate**; result recorded in Appendix D | Decision 11, §3.3, §7.4 V-9, §8 7.11 |

### 2. Backup / restore

| # | Sev | Finding | Disposition | Where |
|---|---|---|---|---|
| **7** | 🚨 **BLOCK** | No cross-database recovery point; a directory of independent dumps is not cluster-consistent | 🔧 **ACCEPTED WITH VARIATION** (per orchestrator ruling). Hourly logical dumps remain the launch mechanism, **relabelled honestly**; added **central-first capture, tenant list read FROM the central dump, `MANIFEST.json`, and both-direction restore-time reconciliation**; WAL/pgBackRest becomes a Phase-9 item with a **decision date (D-10)** rather than a vague trigger. v1's "the next cycle heals it" is explicitly withdrawn | §4.1, **§4.4a–c**, §4.3 L0/L7, R-10, **D-10** |
| **8** | REQ | "RPO ≤ 1 h" is a schedule target, not a guarantee | ✅ **ACCEPTED — and its phrasing is now the template for the whole document.** §4.1 restated as conditional tables with explicit degradation; the one-sentence owner-facing version added | §4.1 |
| **9** | 🚨 **BLOCK** | Restore races live writers; terminate-then-drop has a reconnection window | ✅ **ACCEPTED.** New §4.6a with **Mode A** (Dokploy stop of api/worker/scheduler) and **Mode B** (suspend tenant → pause Horizon → stop scheduler → **REVOKE CONNECT** → terminate → **assert zero**); rehearsal step 14 tests it. **`TenantBackupService:150-186` noted as having the same defect — 🎫 code follow-up ticket, not fixed here** | **§4.6a**, §4.8 step 14, §8 Phase 0.8 |
| **10** | REQ | Cluster restore order incomplete; globals not fail-fast; template DB unestablished; globals-as-non-superuser UNVERIFIED | ✅ **ACCEPTED.** `izipostemplate` **removed** rather than documented (it was never established as needed); restore order rewritten with `-v ON_ERROR_STOP=1`, idempotent pre-existing-role handling, an **admin** restore identity, "restore every manifest artefact and fail on omission", and a **globals-credential acceptance test** (Phase 4.3a / rehearsal step 15) | §3.2, §3.4, **§4.6c**, §8 Phase 4.3a |
| **11** | REQ | The Timescale procedure cannot know what the *source* had; `template0` defeats the `template1` inference | ⚖️ **ACCEPTED, and sharpened with evidence the review did not have.** Branch now keys on **`MANIFEST.json` source metadata** (pg version, extensions + exact `extversion`, hypertable inventory), never on the target's `pg_extension`. **Added precision:** `CREATE DATABASE` defaults to `TEMPLATE template1`, so `iziposcentral` — created by hand — is the *only* database that could plausibly inherit the extension, while Stancl's `TEMPLATE=template0` means **no tenant database can**. Further: `audit_events` is an **ordinary tenant table** (`migrations/tenant/2025_11_30_140000_…`, 38 lines, no hypertable), so the "audit chain in TimescaleDB" claim is documentation, not implementation. **Ruling: create `iziposcentral` with `TEMPLATE template0` too**, measure in Phase 2.2, implement and test **both** branches | **§4.6c**, §4.4b, §8 Phase 2.2/2.3, §4.8 step 13, R-26 |
| **12** | REQ | Restic retention and WORM are different mechanisms with incompatible failure modes | ✅ **ACCEPTED.** §4.5a gives each leg its own threat model, credential, and deletion authority: **Object Storage = write-without-delete host credential + object lock (mode/duration per D-11) + an explicit lifecycle mapping**; **restic = mutable by design, protects operator error, NOT host compromise**. Six tests prove it, including one that must **succeed** (pruning restic) to make the asymmetry evidenced | **§4.5a**, §8 Phase 4.1a, **D-11** |
| **13** | 🚨 **BLOCK** | Total-host recovery omits media and appstorage — the RPO/RTO scope is false | ✅ **ACCEPTED.** MinIO gets its **own hourly offsite leg** (L4a, `rclone sync`/`mc mirror` on the same cron — media is small at launch); `appstorage` documented as **rebuildable-except-logs** with log shipping covering the loss; **per-volume RPO/RTO stated explicitly**; DR-3 restores media and appstorage as numbered steps | §3.5, §4.1, §4.3 L4a, §4.7 DR-3, R-21 |
| **14** | REQ | Legal-retention gate is waivable; "90 days" contradicts the tier table | ✅ **ACCEPTED.** The "90 days" sentence is **struck**; the tier table is the sole retention statement; **the fiscal-retention sub-gate is declared NON-WAIVABLE before the first fiscal record** (aligned with E-4 and the E-7 precedent, Phase 7.12); the "placeholder: 10 years" WORM lock is withdrawn — **no auto-lock beyond 90 days until the accountant answers (D-11)** | §4.5, **§4.5b**, D-3/D-11, §8 Phase 7.12 |
| **15** | REQ | 48 local dump sets do not fit the document's own growth model | ✅ **ACCEPTED.** **Capacity-based ceiling** (local staging < 20 % of its mount, prune oldest first, alert at 15 %, always keep the 2 most recent complete cycles); incomplete-cycle cleanup every run; **staging directory moved off `/` onto its own mount**; free-space preflight; the compression ratio itself registered as a hypothesis | §3.1, §4.4d (items 9–12), §4.5, §9.1, Appendix D/E |

### 3. DR / RTO

| # | Sev | Finding | Disposition | Where |
|---|---|---|---|---|
| **16** | 🚨 **BLOCK** | The lifeboat depends on registry images that do not exist | ⚖️ **ACCEPTED, with one sub-claim corrected.** New **§7.0**: CI builds and pushes on green `main` to GHCR, **digest-pinned**, Dokploy production apps are `sourceType: docker` with **no git provider at all**; moved to **Phase 0**, before application creation. **Corrected sub-claim:** the review implies five separate builds. Verified: all four API targets derive from the same `base` stage and differ **only** in `CMD`/`HEALTHCHECK`/`EXPOSE` (`Dockerfile:145-188`), and `base` already contains all four entrypoint scripts — so it is **one build, five manifests, shared layers**, not five builds. Also corrected against the orchestrator's own hypothesis: staging differentiates by **build target**, not by `CONTAINER_ROLE` (which is set only on `api` and selects bundled-vs-split inside `entrypoint.sh:231-237`). Four API tags + web remains the default because dropping to one tag would make `worker` inherit the API healthcheck | **§7.0.1–7.0.2**, §3.3, §8 Phase 0.8b, D-9 |
| **17** | 🚨 **BLOCK** | DR-3 requires the same panel whose outage has no replacement path | ✅ **ACCEPTED.** **§7.0.3**: a checked-in, secret-free, digest-pinned `deploy/production/compose.production.yml` + `BOOTSTRAP.md` for a manual `docker compose` cold start; **rehearsed once with the panel deliberately unused** (Phase 7.9 step 2); DR-3 rewritten as 14 steps in which **the panel appears only at step 14, after service is restored**. Bonus: DR-4 no longer concedes "deploys lost". **Honest RTO consequence:** the mechanism now exists but is unmeasured, so per the orchestrator's instruction the number is stated honestly — **committed ≤ 8 h, target 4 h, measured p95 replaces it** (D-14) | **§7.0.3**, §4.7 DR-3, §4.1, **D-14** |
| **18** | REQ | 4 h RTO has no transfer budget and no full-host rehearsal | ✅ **ACCEPTED.** New **§4.9 full-host rehearsal** (10 measured steps, quarterly, at representative data size) as **the** RTO gate — the one-tenant restore explicitly is not; every RTO term listed as UNVERIFIED or HYPOTHESIS with its owner | **§4.9**, §4.1, §8 Phase 7.9, Appendix D |
| **19** | REQ | TTL 300 solves neither cutover access nor dual-stack failure | ✅ **ACCEPTED.** DNS provider credentials + recovery added to the break-glass package (D-12); **A and AAAA updated atomically** with the stale-AAAA failure mode named; cutover **scripted**; rehearsal **measures observed propagation from two networks** instead of trusting the TTL | §4.7 DR-3, §4.9 step 9, §5.4, D-12 |
| **20** | REQ | A one-human 1Password chain is not a break-glass plan | ✅ **ACCEPTED.** New **§5.4** break-glass package (12 named items incl. 1Password recovery, Dokploy/GitHub/DNS recovery, `DOKPLOY_API_KEY`) + second custodian (**D-12**) + a clean-machine rehearsal (Phase 7.10); new risk R-27 | **§5.4**, **D-12**, §8 Phase 7.10 |

### 4. Security

| # | Sev | Finding | Disposition | Where |
|---|---|---|---|---|
| **21** | REQ | Panel keyring backed up *after* the risky upgrade | ✅ **ACCEPTED.** **§5.3b** reorders: vault → export **and verify** keyring + panel backup → confirm rollback → upgrade → validate servers **and** secret decryption → only then register production. Monthly patch policy added; `minio/minio:latest` and `minio/mc:latest` pinned | **§5.3b**, §8 Phase 0.3, §3.3 row 9 |
| **22** | REQ | One panel compromise has three-server SSH blast radius | ✅ **ACCEPTED.** **§5.5**: unique key per host, restricted automation account, source-IP restriction, host-key verification, rotation/revocation procedure, principal→server inventory, and **"panel compromise is a three-host incident"** in the runbook | **§5.5** |
| **23** | MINOR | Postgres needs no exposure, but live acceptance evidence is required | ✅ **ACCEPTED.** Postgres stays un-routed; `ss`, firewall ruleset, Docker service inspection, and an **external negative connection test** are launch evidence; "never reuse the local compose on production" is covered by §3.8 | §3.1, §3.8, §8 Phase 1.3/2.1 |
| **24** | 🚨 **BLOCK** | The application is given MinIO root credentials | ✅ **ACCEPTED.** **§3.4b**: three identities (root → vault + **service-level only**, bucket-scoped app key, separate backup key); MinIO stays domain-less and unpublished; five negative tests in Phase 2.6a. Also names the project-level-inheritance trap that makes this easy to get wrong | **§3.4b**, §5.3, §8 Phase 2.6a, R-25 |
| **25** | REQ | E-1 rotates secrets but cannot eliminate plaintext runtime exposure | ✅ **ACCEPTED.** **§5.3a** reframes E-1 as *rotation and custody improvement*, explicitly **not** "plaintext risk eliminated", with the six controls that actually reduce exposure and a note that closing E-1 licenses no such claim | **§5.3a** |
| **26** | REQ | The shared runtime DB role is a cluster-wide destructive credential | 🔧 **ACCEPTED WITH VARIATION.** **§3.4c** splits `izipos_app` / `izipos_backup` / `izipos_admin`. **The residual is stated rather than hidden:** `izipos_app` must keep `CREATEDB` because Stancl provisions over the request-serving connection — 🎫 ticketed as "move `CREATE DATABASE` behind a narrow provisioning path". The WORM leg is named as the real containment boundary | **§3.4c**, §4.5a, R-25 |
| **27** | MINOR | Second-vertical path lacks Redis and credential isolation | ✅ **ACCEPTED.** §9.4 gains a five-row shared-surface table (Redis prefix, cache prefix, **queue names**, Horizon metadata, Reverb) and a pre-Otospex ruling with cross-product isolation tests. **`HORIZON_PREFIX` isolates the dashboard, not the work** | §9.4 |

### 5. Operational gaps

| # | Sev | Finding | Disposition | Where |
|---|---|---|---|---|
| **28** | REQ | Single-human ops has detection but no escalation; the script outline has ~8 concrete defects | ✅ **ACCEPTED in full.** systemd timer/service with `RuntimeMaxSec` and journald; explicit shebang and `PATH`; `EnvironmentFile` supplying `PGUSER`/`PGPASSWORD`/`RESTIC_PASSWORD_FILE`; free-space preflight; atomic `.partial`→rename; `find -mindepth 1`; distinct overlap exit code; stale-run detection; incomplete-cycle cleanup; **direct failure notification in addition to dead-man silence**; **escalation to the second custodian** | §4.4d items 1–14, §6.2 |
| **29** | REQ | Monitoring depends on code and alert paths that do not exist; O-7 counts the wrong store | ✅ **ACCEPTED.** **§6.1** states the verified absent state — including that `MonitoringService:242-252,604-625` counts DB `jobs` rows while production uses Redis, so it **reads zero regardless of backlog**; **all monitor code moves to Phase 0.8a**; §6.2 adds **O-13** (volume-backup dead-man) and **O-14** (alert-delivery canary requiring acknowledgement) | **§6.1**, **§6.2**, §8 Phase 0.8a |
| **30** | REQ | Log and disk claims are not bounded by the stated math | ✅ **ACCEPTED.** The "< 150 GB / ~70 % free" claim is **withdrawn**; **§6.3** + **Appendix E** give a line-item budget with growth rates and hard ceilings incl. Docker logs, uncapped Laravel daily logs, per-tenant in-app backups, images/build cache, and partials; **alerts on bytes AND inodes**; image-prune policy added | §3.1, **§6.3**, **Appendix E** |
| **31** | REQ | One concurrent build serializes five deploys and builds on the DB host | ✅ **ACCEPTED — and structurally eliminated.** Production apps have **no git provider**, so no source build can occur on the database host at all; images are built once in CI and pulled by digest; rollout is deliberately serialised with health gates | §7.0.2, §3.3, R-12 |
| **32** | REQ | Panel outage silently stops the non-database backup path and deploy control | ✅ **ACCEPTED.** Media moves to the **host-driven hourly L4a leg**; **O-13** externally monitors volume-backup age; **§7.0.3** gives a panel-independent deploy/rollback path for security hotfixes; DR-4 updated accordingly | §4.3 L4a, §6.2, §7.0.3, §4.7 DR-4 |

### 6. Sequencing

| # | Sev | Finding | Disposition | Where |
|---|---|---|---|---|
| **33** | 🚨 **BLOCK** | Phase order requires verification before its prerequisites exist | ✅ **ACCEPTED.** New **§8.0** states the three concrete ordering defects and resequences: **every repository prerequisite — including monitors, the image workflow, and the production manifest — lands before the single dev→main promotion**, which is now the *last* step of Phase 0. Registry and manifest exist **before Phase 3**; Phases 5 and 6 are reduced to activation-only | **§8.0**, §8 Phases 0/5/6 |
| **34** | REQ | The promotion is larger than stated; "no artifact on main" is too absolute; CI history is not repo-verifiable | ⚖️ **ACCEPTED with the correction adopted verbatim.** v1's absolute phrasing is replaced with *"`main` lacks the intended current release"*, and the measured scope (8,455 files / ~1.35 M insertions) is stated. Promotion is treated as a release project: merge window, conflict inventory, **CI evidence read from GitHub not inferred from YAML**, build-drift check, container builds for every target, SBOM/vuln scan, rollback tag. No `.gitmodules` found — v1's submodule concern dropped | §7.3, §8 Phase 0.9 |
| **35** | REQ | Multiple stated prerequisites are unimplemented today | ✅ **ACCEPTED.** The inventory becomes **Phase 0 exit criteria** (0.5a–0.9), each with evidence, and **no production Application is created until the release commit contains every artefact** | §8 Phase 0, §8.0 |
| **36** | REQ | The initial production deploy is only accidentally CI-gated | ✅ **ACCEPTED.** Registry/deploy gate is built **before** production apps exist; **V-10 provenance check** requires each running service to report its release SHA and image digest and asserts it matches the owner-approved green CI run; a mismatch fails the deploy | §7.0.2, §7.4 V-10, §8 Phase 6.6 |

### 7. Cost / claim audit

| # | Sev | Finding | Disposition | Where |
|---|---|---|---|---|
| **37** | REQ | Owner-decision numbers are externally unverifiable; two internal statements are wrong | ✅ **ACCEPTED.** D-3's "operational retention is 90 days" is **struck as false**; "two independent mechanisms" is corrected to **"two mechanisms, one provider failure domain"** (§4.5a); **GHCR "€0.00, free at this volume" is withdrawn** (D-9/R-23). Appendix B rebuilt with **low/base/high bands, an explicit exclusions list, and a requote-at-decision-time instruction with dated evidence** | D-3, D-9, §4.5a, **Appendix B** |
| **38** | REQ | Service-count, RPO/RTO, sizing and timing numbers are targets, not facts | ✅ **ACCEPTED — this drove the whole v2 claim discipline.** Every unmeasured figure is now `🔬 HYPOTHESIS` with a **measurement owner, method, and acceptance threshold** in **Appendix D**, which also separates *verified static config* from *provider claims* from *performance estimates*. Launch/RTO sign-off is blocked until the measured evidence exists | **Appendix D**, header claim discipline, §4.1 |

### 11.1 What was argued back (and why)

Only two, and both are refinements rather than rejections. **No BLOCKER was argued back.**

| # | Sub-claim corrected | Evidence the reviewer did not have |
|---|---|---|
| **16** | "The design requires five distinct application targets, so 'a tagged image' is insufficient unless it means four API targets plus web" — true as stated, but it implies five builds and five image payloads | All four API targets derive from one `base` stage and differ **only** in `CMD`/`HEALTHCHECK`/`EXPOSE` (`apps/api/Dockerfile:145-188`); `base` already contains **all four entrypoint scripts**. It is **one build with shared layers published under four manifests**. The registry/build cost is that of **two** images, not five — which is why the Phase-0 registry prerequisite is cheap enough to be non-negotiable. *(Also corrected in the other direction: staging does **not** differentiate roles via `CONTAINER_ROLE` — that variable is set only on `api` and selects bundled-vs-split inside `entrypoint.sh:231-237`. Roles come from build targets.)* |
| **11** | "Restore into `template0`" and the general framing that Timescale presence is unknown across the board | `CREATE DATABASE` **defaults to `TEMPLATE template1`**, so the manually created `iziposcentral` is the only database that could inherit an image-seeded extension, while Stancl's `WITH TEMPLATE=template0` (`PostgreSQLDatabaseManager.php:32-35`) means **no tenant database can**. Additionally `audit_events` — the table behind the "audit chain in TimescaleDB" claim — is an **ordinary tenant table** with no hypertable. The manifest-driven branch is adopted exactly as the reviewer required; what changes is that the *expected answer* is now derived and stated, and `iziposcentral` is also created `TEMPLATE template0` so the branch is dead code by design |

### 11.2 Findings that shrank the design rather than growing it

Per the orchestrator's narrowing principle, four changes made claims true by **removing** scope:

| Finding | Removed |
|---|---|
| 10 | The `izipostemplate` database — deleted rather than documented and restored |
| 14 | The "placeholder: 10 years" WORM lock — no irreversible lock from a guess |
| 17/18 | The 4 h total-host RTO commitment → **8 h until measured** |
| 8 | The unconditional "RPO ≤ 1 hour" → a conditional target with named degradation |

**The one place scope was deliberately grown is backups**, per the owner's non-negotiable: the MinIO hourly offsite leg (L4a), the cycle manifest with per-DB source metadata, both-direction reconciliation, and four additional rehearsal steps.

---

## 11.3 Round-2 re-gate disposition (v3)

Source: `docs/superpowers/reviews/2026-08-05-production-env-design-review-round2.md` — verdict **REJECT**, four BLOCKER clusters and ten REQUIRED items, plus new risks **N1–N6**. Findings the round-2 re-gate marked **CLOSED** (F2, F3, F4, F5, F6, F8, F10, F11, F12, F15, F18, F19, F20, F22, F25, F29) are not re-litigated here and are unchanged in v3 except where a v3 edit touched them incidentally.

**Legend as in §11:** ✅ ACCEPTED · 🔧 ACCEPTED WITH VARIATION · ⚖️ PARTIALLY ARGUED BACK · ⏭️ DEFERRED.

> 🚨 **v4 reading note.** The tables below are the **v3 record** and are preserved as history. **Round 3 re-gated the four BLOCKER clusters and four of the ten REQUIRED items, and six of those eight did not survive intact** — **F9, F13/N3, F16/F17/F33/N1, F7/N2, F1, F26** (and §7.4b/§7.7's session folds). Where a row below conflicts with **§11.4**, **§11.4 wins.** Three specific corrections to the text below, called out because they are factual rather than merely superseded: the **F9** row still describes a `horizon:pause` step that v4 has **removed entirely**; the **F13/N3** row repeats the false *"lifecycle ages the noncurrent version"* claim that R3-N7 struck (see **D-18** and **§4.5a2**); and the **F1** row's *"the SQL is the gate from day one"* is **withdrawn** — that SQL was not executable, and V-11 is now the `permissions:verify` command alone (**§7.4a**, R3-N4).

### 11.3a The four BLOCKER clusters

| ID | Round-2 disposition | v3 closure | Where |
|---|---|---|---|
| **F9** | NOT CLOSED — "Mode B is not race-free from the start of the fence" | ⚖️ **ACCEPTED IN SUBSTANCE, with the orchestrator's own premise corrected.** A real drain is inserted **before** the DB fence: suspend → `horizon:pause` → **`horizon:terminate`** (a genuine drain, because `config/horizon.php:175` sets `fast_termination => false`) → **stop the worker Application** → stop the scheduler → **two bounded wait-loop probes** (`queue:monitor … --json` `reserved == 0`, sampled twice ≥ 95 s apart; `pg_stat_database.numbackends == 0`) → revoke → terminate → assert zero twice. **Corrections to the ruling as issued:** (1) there is **no 503** — a suspended tenant returns **403 `ORGANIZATION_UNAVAILABLE`** (`ResolveTenancy.php:56-83`), and the fence's stronger half is the **synchronous PAT revocation** in `TenantObserver.php:57-59`; (2) `EnsureTenantIsActive` is **dead code**, registered nowhere — the enforcing middleware is `ResolveTenancy`; (3) **no true "active jobs" probe exists in Horizon** (`JobRepository` has no `countActive`/`countReserved`), so the probe is framework-level `queue:monitor`, not Horizon; (4) **holding `horizon:pause` is itself unsafe** — the worker healthcheck greps `running`, so a held pause restarts the container and silently resumes consumption (new risk R-31). Residual risks are enumerated in a table rather than implied | **§4.6a**, §4.8 step 14, R-30, R-31, H-19, D-17 |
| **F13 / N3** | PARTIALLY CLOSED / BLOCKER — "`rclone sync --backup-dir` is incompatible with a no-delete credential, and DR-3 verifies checksums against a manifest that has none" | ✅ **ACCEPTED in full, exactly as the ruling directed.** The two properties are split across the two legs: **Leg A = `rclone copy --immutable` into a versioned bucket, deletions never propagate, lifecycle ages noncurrent versions**; **Leg B = restic, the deletable mirror (L4a″)**. `sync` and `--backup-dir` are struck, as is the `…-media-versions/<TS>/` prefix. The manifest gains **L4a′**: a per-object `key/size/hash` inventory (`rclone lsjson --hash`) plus an `rclone check --checksum --one-way` result and its `check_mode`, all of which DR-3 step 9 verifies against object-by-object. `--one-way` is load-bearing and explained. The privacy consequence of never propagating deletions is escalated to an owner decision | **§4.5a1**, §4.3 L4a/L4a′/L4a″, §4.4b, §4.7 DR-3 step 9/9b, §8 Phase 4.5a, **D-18**, R-28, H-20 |
| **F16 / F17 / F33 / N1** | PARTIALLY CLOSED / NOT CLOSED / BLOCKER — "the green-main rule and the digest-pinned release commit form a bootstrap cycle"; "TLS cold start is a non-decision" | ✅ **ACCEPTED.** A **ONE-TIME BOOTSTRAP EXCEPTION** is defined verbatim: phase-0 images built from an owner-designated §A-cleared `dev` SHA via `workflow_dispatch` under the `production` environment (so the approval is recorded), tagged `bootstrap-<sha>`; the manifest pins those digests; **green-`main` becomes binding from the first post-promotion build**; supersession and sunset are defined, with a six-item audit trail. **Why the conventional two-commit protocol was rejected is stated.** Separately, TLS collapses to **one** path — Traefik from the same manifest with LE HTTP-01 — which required moving the **DNS cutover ahead of the edge** in DR-3, and the LE rate-limit residual is named with an ordered mitigation list that explicitly excludes "self-signed and carry on" | **§7.0.2a**, **§7.0.3a**, §4.7 DR-3 steps 11–12, §8 Phase 0.8i/0.9, **D-15**, R-29, H-21 |
| **F7 / N2** | PARTIALLY CLOSED / BLOCKER — "`write_manifest` runs before either upload, so a leg can hold a false `complete`" | ✅ **ACCEPTED.** Completion becomes a **two-phase write**: phase 1 writes `"result": "staged"` with both `offsite.*.state = "pending"`; phase 2 uploads and **independently verifies each leg** (`rclone check` and `restic check --read-data-subset`, exit codes captured); phase 3 rewrites `complete` and re-uploads it to both legs. **No call site can produce `complete` earlier.** A restore asserts `complete` **and** the specific leg's `verified` state. The dead-man pings only after the promote, and **O-3 is re-keyed to the age of the last `complete` manifest**, with a new **O-3b** that reads the newest manifest **from the remote leg**. The failure mode of a half-failed promote is named as a deliberate false-negative-in-the-safe-direction | **§4.4b**, **§4.4b1**, §4.4c, §4.4d steps 6–11 + item 15, §4.6b step 0a, §4.1, **O-3 / O-3b** |

### 11.3b The ten REQUIRED items

| ID | Round-2 disposition | v3 closure | Where |
|---|---|---|---|
| **F1** | PARTIALLY CLOSED — "V-11 gives no executable command or pass criterion; it says the state is *asserted here*" | ✅ **ACCEPTED.** V-11 becomes three executable checks with **stated expected output**: **V-11a** per-tenant SQL asserting every expected permission exists (`missing=0` per tenant DB, any other value fails the deploy), **V-11b** asserting each is attached to ≥ 1 role (zero rows), **V-11c** running `permission:cache-reset` **unsuppressed** and proving the `spatie.permission.cache` key is gone. A durable `permissions:verify` `TenantScopedCommand` is commissioned alongside, following the cat-(b) contract — **but the SQL is the gate from day one**, so V-11 is never "asserted" | **§7.4a**, §7.4 V-11, §8 Phase 0.8g, 6.6 |
| **F14** | NOT CLOSED — "aspirational prose, not a binding sub-gate; E-4 still closes through a dated owner risk acceptance" | ✅ **ACCEPTED, delivered as the ruling specified** (the gate sheet is Phase-E human-only, so this document supplies text, not an edit). §4.5b1 gives the **verbatim `E-4a` gate row** to insert, **plus the two exact amendments to the "Hard rule" block** (`E-7 and E-4a are non-waivable`; the carve-out of retention from E-4's acceptance route). The insertion is owner decision **D-16**, a prerequisite of Phase 7.12, and Phase 7.12's evidence cell reads `BLOCKED — awaiting E-4a` until it lands. **Why retention is carved out of E-4 rather than making all five subjects non-waivable is argued explicitly** | **§4.5b1**, **D-16**, §8 Phase 7.12/7.8, header |
| **F21** | NOT CLOSED — "the execution plan still numbers 0.2 upgrade before 0.3 export/verify"; "`minio/minio:latest` vs the pinning policy" | ✅ **ACCEPTED.** The **phase table itself is renumbered**: `0.2` = vault + keyring export **and verify** (verification defined as a **decrypt test against a known secret**) + version/rollback record; `0.3` = the upgrade. §5.3b's prose table now carries a `§8 step` column so the two cannot drift again. `minio/minio:latest` in the §3.3 service table is replaced with a **pinned digest**, recorded in `RELEASE-SETS.md` | §8 Phase 0.2/0.3, §5.3b, §3.3 row 3 |
| **F24** | PARTIALLY CLOSED — "E-1 calls the `AWS_*` pair *MinIO root creds* while §3.4b makes them the bucket-scoped app key" | ✅ **ACCEPTED.** The E-1 row is rewritten to state unambiguously that `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` are the **bucket-scoped application key**, and **two new rows** are added for the genuinely-separate identities: `MINIO_ROOT_USER`/`_PASSWORD` (1Password → **service-level override on the minio Application only**) and the host-side backup identity. The "five NEW rows" count is corrected to **nine** | §5.2, §3.4b |
| **F26** | PARTIALLY CLOSED — "the request-serving role still has `CREATEDB` and owns every DB" | 🔧 **ACCEPTED WITH VARIATION — and v2's justification is retracted as factually wrong.** Stancl issues `CREATE`/`DROP DATABASE` through the **manager**, over `getTemplateConnectionName()` (`DatabaseConfig.php:149-165`), not over the request-serving connection. v3 therefore ships the separation: `izipos_app` becomes **`NOCREATEDB`** and **owns no database** (so it cannot `DROP`), a new **`izipos_provisioner`** holds `CREATEDB` behind a dedicated `provisioning` connection, and a `DatabaseCreated` listener grants the app role `USAGE, CREATE ON SCHEMA public` in each new database (**mandatory on PG15+**). The obvious-but-wrong implementation (`template_tenant_connection = 'provisioning'`) is called out as a trap. **Every grant is spelled out.** The remaining residual — the api container must hold the provisioning credential because signup provisions synchronously — is **explicitly owner-accepted under D-19**, not called closed | **§3.4c**, §3.4, §5.2, §8 Phase 0.8f/2.3, **D-19**, R-25 |
| **F28** | PARTIALLY CLOSED — "the escalation interval is still *a defined interval* and never defined" | ✅ **ACCEPTED.** A four-step ladder with real numbers: **0 min** channel 1 → **15 min** channel 2 → **30 min** second custodian → **60 min** logged as an unacknowledged critical, **with the RPO commitment formally void from the first unanswered alert**. Applied to every critical alert, not just backups. Whether the channels support programmatic acknowledgement is marked ⚠️ UNVERIFIED with a Phase 5.7 acceptance test and a stated fallback. The completion-order half of F28 is closed under F7/N2 | §4.4d item 14, §6.2 row 3, §8 Phase 5.7 |
| **F30** | PARTIALLY CLOSED — "tenant local writes say only *policy required at 10 tenants*, in-app backups say *re-evaluate*, Laravel logs have no cap, images say *keep last N* without N" | ✅ **ACCEPTED.** **Every** Appendix E row now carries a numeric ceiling, the **15-minute cron check** that measures it, the **alert threshold**, and **the enforcement command**. The four named gaps get concrete values: tenant local writes **≤ 5 GB / 30 days**, in-app tenant backups **≤ 10 GB** (enforced by dropping `TENANT_BACKUP_KEEP` 14 → 7), Laravel logs **≤ 2 GB** (host-side purge to the newest 3 files), images **N = last 3 sets on the host, 10 in GHCR**. A governing rule states that **automatic** deletion is permitted only for data that is derived, regenerable, or held authoritatively elsewhere — which is why PGDATA, media and WAL alert instead | **Appendix E**, §6.3, §8 Phase 0.8h |
| **F31 / F32 / F35 / F36 / N4** | PARTIALLY CLOSED / REQUIRED — "no digest rotation or rollback procedure; the initial deploy is not protected by the release gate" | ✅ **ACCEPTED.** New **§7.0.5**: the **release set** as the unit of release (`rs-<date>-<sha>`), an append-only `RELEASE-SETS.md`, **N = 10 sets or 90 days whichever is longer** (justified against the backup retention window), a defined rotation cadence, a `last-known-good` promotion rule (**72 h clean**, not at deploy time), and a **nine-step rollback runbook whose step 2 answers the schema-compatibility question before any container moves** — with "roll the images back and see" explicitly forbidden. **V-12 is extended to diff the manifest's digests against V-10's running digests**, which is the anti-drift mechanism. **The initial deploy is brought under the same gate** by making Phase 3.6 invoke the *same* `scripts/verify-production-release.sh` the Phase 6.6 workflow later calls. R-11/R-12's contradictions are corrected under N5 | **§7.0.5**, §7.4 V-12, §8 Phase 0.8g/3.6/6.6/6.8, R-11, R-12 |
| **F34 / F37 / N5** | PARTIALLY CLOSED — seven stale statements contradicting their governing sections | ✅ **ACCEPTED — all seven, individually.** §9.3's `pg_database` enumeration → corrected to the central dump; **R-11** "panel loss stops deploys" → corrected against §7.0.3/DR-4; **R-12**'s 64 GB build-queue justification → struck (production performs no builds); **Appendix A**'s "DR-3 has a 4-hour RTO" → corrected to ≤ 8 h committed / 4 h target; **Appendix B**'s free-GHCR line and the total derived from it → **the entire headline table and its correction block are DELETED and replaced by one authoritative bands table**, with §2's total recomputed to match (≈ €136 + registry, not ≈ €121); **`minio/minio:latest`** → pinned (also F21); **E-1's "MinIO root creds"** → corrected (also F24). §1.3's absolute "no artifact on `main`" is struck to match §7.3 | §9.3, R-11, R-12, Appendix A, **Appendix B**, §3.3, §5.2, §1.3, §2 |
| **F38 / N6** | NOT CLOSED — "threshold cells are circular or merely say what the result feeds" | ✅ **ACCEPTED.** Every Appendix D hypothesis gets **both** a numeric/binary **acceptance rule** and a **reopen rule**. The three sampled failures are de-circularised with real numbers: **H-5 ≥ 100 Mbit/s** (with < 25 Mbit/s reopening D-13), **H-10 ≤ 20 GB** (with > 50 GB firing §9.5 at launch), **H-13 ≤ 2 GB per pull set and projected egress ≤ the plan allowance** (which is what turns D-9 from "unquantified" into a decision). H-6, H-8, H-9 and H-15 likewise. **H-9 is relabelled `MEASUREMENT OUTPUT` with its downstream formula stated**, per the re-gate's own instruction, and carries a binary sub-assertion that *is* a gate. Three new hypotheses (**H-19 drain duration, H-20 media check mode, H-21 bootstrap supersession lag**) cover the mechanisms v3 introduces | **Appendix D.2** |

### 11.3c What was argued back in round 2

| # | Sub-claim | Evidence |
|---|---|---|
| **F9 (the ruling, not the finding)** | The orchestrator's ruling said to "put API into tenant-503 (suspend flag the app already honors)". **There is no 503 in this codebase and no per-tenant maintenance mode.** | `ResolveTenancy.php:56-83` returns **403 `ORGANIZATION_UNAVAILABLE`** for `TenantStatus::Suspended`/`Archived`. `php artisan down` is unusable as a per-tenant fence: `APP_MAINTENANCE_DRIVER` defaults to `file` (per-container) and `down` is fleet-wide (`config/app.php:121-124`). **The finding stands and is fixed; only the mechanism's name changes** — and the fence turns out to be *stronger* than assumed, because suspension also revokes tenant PATs synchronously (`TenantObserver.php:57-59`) |
| **F9 (drain probe)** | The ruling asked for "horizon:status / queue size probes". **Neither works.** `horizon:status` prints three states and no counts (`StatusCommand.php:32-51`); `queue:size` on Redis sums pending **+ delayed + reserved** (`RedisQueue.php:103`), so it cannot isolate in-flight. **`queue:monitor --json`'s `reserved` field is the only in-flight signal available**, and `horizon:terminate` — not `horizon:pause` — is the only actual drain |
| **F26** | v2 asserted that full separation "is not available without code changes" because "Stancl creates tenant databases over the central/template connection". **The second half is wrong**, and it was load-bearing for v2's refusal to fix it. `DatabaseConfig::manager()` binds the manager to `getTemplateConnectionName()` and the manager is what issues the DDL (`DatabaseConfig.php:149-165`; `PostgreSQLDatabaseManager.php:32-39`). The separation **is** available, for roughly ten lines of code — which v3 ships |
| **QD-2 (folded-in fact)** | The 2026-08-05 marketplace constructor change carries a DEPLOY NOTE demanding a drain. **Its severity was reduced hours later by `24dac38ee`**: `$tenantId` became a declared, defaulted `public ?string $tenantId = null`, so a legacy one-argument payload now restores as `null` and is **discarded with a warning** instead of fatalling. Draining remains preferred; skipping it is now survivable. 🚨 **The audit document's Finding-B closure text still describes the superseded promoted-`readonly` shape and is stale** — §7.7a records the current state |

---

## 11.4 Round-3 scoped re-gate disposition (v4)

Source: `docs/superpowers/reviews/2026-08-05-production-env-design-review-round3.md` — verdict **REJECT**. Round 3 was a **scoped** re-gate: it re-examined only the round-2 dispositions for **F9; F13/N3; F16/F17/F33/N1; F7/N2; F1, F14, F21, F26; the §7.7a–c queue-drain and `channels:reconcile` folds; and the §7.4b verifier-contract fold**. It did not re-open settled round-1 items, and neither does this section.

Its six blockers collapse to **three root causes**, and v4 treats them as such rather than as six independent patches — a patch per symptom is how v3's Mode B ended up with a pause it should not have had and a probe that could not fail.

**Legend as above.** One additional marker: 🧭 **PREMISE CORRECTED** — the finding is accepted and fixed, but a factual claim in the review's own reasoning is amended against source, with the evidence stated.

### 11.4a The three root causes (six BLOCKERs)

| ID | Round-3 finding | v4 closure | Where |
|---|---|---|---|
| **R3-N1** *(findings 1.2, 1.3)* | BLOCKER — "step 3b closes the healthcheck trap only **after** the unsafe interval"; "Probe B is described as mandatory but its verbatim loop never fails" | ✅ **ACCEPTED IN FULL, and the fix is a re-order plus a deletion, not an addition.** 🚨 **`horizon:pause` is DROPPED ENTIRELY** — v3's error was treating `paused` as a holding state when it is an *unhealthy container state*, and the unhealthy window (90 s: three 30 s checks) is far shorter than a legitimate job (3,600 s for `ProcessImportJob`), with SIGTERM not honoured until `runJob()` returns. v4's order: **stop the backup timer → suspend → RECORD the worker task ID → `horizon:terminate` immediately followed by the Application STOP (inside the drain window) → ASSERT the same task exited, zero running tasks, no replacement, exit code 0 not 137 → stop scheduler → both probes → revoke → terminate → assert zero twice**. Because the service is *stopping* for the whole drain rather than *unhealthy*, the replacement race is **structurally absent, not merely bounded** — which required making `stop_grace_period` > the longest job timeout an explicit owner decision (**D-21**), since that is what converts the stop into a drain. **Probe B gains the missing `[ "${CONNS:-probe_failed}" = "0" ] \|\| exit 1` assertion**, runs `psql -v ON_ERROR_STOP=1`, treats a query failure as fatal, and seeds `CONNS` with a sentinel so an errored probe can never read as zero. §7.7b's deploy drain is re-ordered identically — it had silently inherited the same defects. 🚨 **v5 SUPERSEDES the two-command "terminate then stop" described here with a single atomic `docker service scale=0`, and adds the master-timeout prerequisite (Phase 0.8k) that makes the stop drain rather than kill — see §11.5 BLOCKER 1** | **§4.6a**, §7.7b, §4.8 step 14, **D-21**, **Phase 0.8k**, R-31 *(severity raised)*, R-34, H-19 *(re-anchored)* |
| **R3-N2** *(finding 4.1)* | BLOCKER — "the restic *complete re-upload* is a different, manifest-only snapshot", so no snapshot contains both the cycle data and its `complete` marker | ✅ **ACCEPTED IN FULL.** The defect is exactly as stated: every `restic backup` creates a **new** snapshot and restic never mutates an existing one, so v3's `restic backup "$STAGE/MANIFEST.json"` produced a `complete` marker containing no dumps, alongside a data snapshot permanently marked `staged` — i.e. **leg B had no valid restore candidate at all**, which is the same false-completion class F7 was raised to eliminate. **v4 adopts the review's second suggested remedy** (a second full-directory snapshot, dedup-cheap, selected externally) **and its first** (a completion attestation), because each closes a different half: the re-snapshot gives a *self-contained* restore candidate; the attestation gives an *externally addressable index*, since a snapshot can never contain its own ID. New **§4.4b2** defines B1–B5, tag-based selection (`role=data` / `role=complete` / `role=attestation`, all sharing `cycle=<TS>`), a **verify-the-pairing-before-restoring** rule, a ban on `restic restore latest`, and **tag-aware `restic forget` with a post-prune orphan assertion** (R-36) so retention cannot split a pair | **§4.4b1**, **§4.4b2**, §4.4c, §4.4d steps 8–9b + items 17–18, §4.7 DR-3 step 7, R-36, **H-22** |
| **R3-N3** *(findings 3.1, 3.2)* | BLOCKER — "the pre-promotion `workflow_dispatch` cannot run the new workflow as sequenced"; "supersession recreates the digest/commit fixed-point cycle" | ✅ **ACCEPTED IN FULL — both halves, and the second one changes an architectural decision.** (a) **Dispatchability:** GitHub requires a `workflow_dispatch` definition on the **default branch**, and v3's workflow existed only on `dev` at Phase 0.8i. v4 takes the review's **option (a)**: a minimal **bootstrap dispatcher lands on `main` first** (Phase **0.8b1**, its own reviewed control-plane commit) and invokes the reviewed build logic from the designated `dev` ref; option (b) — folding it into `ci.yml` — is **rejected with its reason** (registry credentials and a privileged path inside the workflow every PR touches). The rehearsal the review demanded is a required deliverable of 0.8b1. (b) **The fixed point:** v3 moved the cycle rather than breaking it, and its own §7.0.2a text simultaneously forbade the second commit it later required at supersession. v4 takes the review's **first** proposed model — **separate source release identity from deployment-state metadata**: git keeps a variable-referencing, digest-free compose; the five digests live in an **attested `release-set.lock.json`** published as a workflow artefact **and** written to both offsite legs; `RELEASE-SETS.md` is demoted to a human ledger that no deploy reads. Supersession becomes a **lock swap**, so there is no metadata commit and no drift. This is **D-20**, and its cost (a cold start needs the lock from the artefact store or a backup leg) is carried as **R-33** with DR-3 steps 4a/4b and hypothesis **H-23**. **v3's claim that no two-commit protocol is needed is struck**; the two-commit protocol is recorded as the named fallback if the owner declines D-20 | **§7.0.2a** *(rewritten)*, **§7.0.2b** *(new)*, §7.0.3, §7.0.5, §4.7 DR-3 4a/4b, §8 Phase **0.8b1**/0.8c/0.8i/0.9, **D-20**, R-33, H-23 |

### 11.4b The MAJORs and the remaining scope dispositions

| ID | Round-3 finding | v4 closure | Where |
|---|---|---|---|
| **R3-N4** *(finding 5.1)* | MAJOR — "F1 / V-11 is still not executable or fail-closed": a file nothing writes (`expected-permissions.txt.sqlvalues`), no quoting, tenant **UUIDs** fed to `psql -d`, a `SELECT` that exits 0 on rows, and an Artisan exit code that is success even on its error branch | ✅ **ACCEPTED — and the remedy is to stop pretending shell SQL can be the gate.** v3 wanted both an inline-SQL day-one gate *and* a commissioned command; making that SQL correct requires specifying quoting, physical-DB-name resolution and exit-code conversion — i.e. writing the command anyway, in bash, untested. **v4 makes `permissions:verify {--tenant=} {--expect=}` the whole of V-11**, a Phase **0.8h1** blocker, with the six tests the review named (quoting, empty input, unknown tenant, missing permission, unassigned permission, per-tenant DB-name resolution — the last making the `SELECT id FROM tenants` shortcut permanently forbidden). **V-11c asserts on the Redis scan, never on `permission:cache-reset`'s exit code** (`CacheReset.php:14-24` returns success on its failure branch), and the scan pattern tolerates the cache **prefix** (`config/cache.php:108-115`), which an exact-key match would miss. **Interim posture is stated rather than left to improvisation: no deploy that adds permissions proceeds before 0.8h1** | **§7.4a** *(rewritten)*, §7.4 V-11, §8 Phase **0.8h1** |
| **R3-N5** *(finding 5.2)* | MAJOR — "F26's Stancl trap analysis is correct, but the grant listener is not implementable as shown": `\connect` is a **psql client meta-command**, not SQL a `DB::statement()` can send | ✅ **ACCEPTED.** The review is right and the error was a real one, not a typo: the two grants live in **two different databases** and PostgreSQL has no in-session database switch. v4 replaces the block with the review's prescribed shape — the **database-level** grant on the standing `provisioning` connection, then a **temporary connection** cloned from `provisioning` with `database` overridden to the new tenant DB for the **schema-level** grant, purged in a `finally`. Added beyond the review's ask, because both are cheap and both are how this goes wrong later: **explicit identifier quoting with a hostile-name unit test** (a `GRANT` target cannot be bound as a parameter), and the review's own suggested assertions — `current_user` / `current_database()` inside the listener **and** `current_user` on the tenant's runtime connection — promoted to required Phase-0.8f tests. **F26's role model was confirmed sound by the review and is unchanged**; D-19's residual stands | **§3.4c** *(item 3 rewritten)*, §8 Phase 0.8f, D-19 |
| **R3-N6** *(findings 6.2, 6.3)* | MAJOR — "§7.4b is stale against today's verifier signatures" (`--fix` removed the same day); "`channels:reconcile` does not have the fail-closed exit contract v3 assigns it" | ✅ **ACCEPTED, both.** (a) `fiscal:verify-chains` is now `{--tenant=}{--company=}{--type=}` with **no `--fix`** (`VerifyFiscalChainsCommand.php:59-72`); the table is corrected, the removal ticket is **closed**, source ranges are updated, and — because this table has now gone stale twice in two days — **§7.4b's command list is generated from `php artisan help` under a regression test**, so the next signature change fails CI instead of failing a fiscal gate. (b) The `channels:reconcile` contract is **withdrawn until the code matches it**: `TenantScopedCommand` records a known-missing tenant DB as `skipped` **without failing the aggregate** (`:261-318,347-360`), and `ChannelWebhookDirectoryRegistrar::register()` returns `false` rather than throwing while the command ignores the boolean (`:29-45,56-81` / `:130-149`) — so it can exit 0 having skipped a tenant or failed every pointer write. **Phase 0.8j** makes it fail on both, with the two tests the review named; **until it lands, the central-count and 403-not-404 probes are the gate**, and no runbook may say "gate on the exit code". Generalised as **R-35**, because this is base-class behaviour and `channels:reconcile` is where it was noticed, not where it is confined | **§7.4b**, **§7.7c**, §8 Phase 0.8g/**0.8j**, R-32 *(updated)*, **R-35** |
| **R3-N7** *(finding 2.2)* | MAJOR — "the deletion-retention statement contradicts the actual append-only model": D-18 and the §11.3 F13 row said a deleted image survives only until lifecycle ages the **noncurrent version** | ✅ **ACCEPTED — and this one mattered most as an honesty defect, because it understated an owner obligation.** Under `rclone copy` a source deletion issues **no destination operation**, so there is no delete marker, therefore **no noncurrent version is ever created**, therefore the 14-day noncurrent-version rule has nothing to act on: the object stays **current**, and current versions have **no expiry rule**. Every such statement is changed to **"indefinitely, until an owner-authorised deletion/expiry action is performed and any lock expires."** New **§4.5a2** adds the mechanism table and the **six-step erasure runbook** the review required, and D-18 now carries a **committed maximum response time of 30 days** (with the lock-not-yet-expired case handled as a recorded deferral rather than a promise nobody can keep). The §4.5a lifecycle table itself was **already correct** and is unchanged | **D-18**, **§4.5a2**, §4.5a1, §11.3 reading note, R-28 |
| **finding 2.3** | MAJOR — "the script cannot capture the advertised per-leg verification failures under `set -e`" | ✅ **ACCEPTED.** `set -euo pipefail` (item 1) means `rclone check …; OS_RC=$?` **never assigns** on failure — the shell exits at the failing command, so the captured code, the direct alert and the diagnostic manifest state were all unreachable. It failed safe *and silently*, and the silence is the part that matters. v4 introduces `run_rc()` and routes **every** expected-to-fail verification through it, with **each failure branch individually tested** in the Phase 4 script review (item 16) | §4.4d script + item 16 |
| **finding 4.2** | MAJOR — "`restic check --read-data-subset=5%` does not verify *the new snapshot*" | ✅ **ACCEPTED, and the claim is downgraded rather than defended.** The command checks repository structure and a **random subset of repository pack files**; it is not scoped to `$SNAP`. v4 (a) adds the review's suggested **snapshot-scoped `restic ls --json "$DATA_SNAP"`** inventory comparison as the evidence that the snapshot contains the cycle, (b) rotates a **deterministic `n/12` subset** so all packs are covered over time, (c) records both in the manifest under their real names (`restic_check_mode`, `restic_check_subset`, `restic_inventory_check`), and (d) makes the **§4.8 rehearsal restore of the named snapshot with full `SHA256SUMS` validation** the only "full" evidence claim | §4.4b2 B2, §4.4d item 18, §4.8 |
| **finding 4.3** | MAJOR — "O-3b reads only the Object Storage marker and cannot detect restic-side promotion drift" | ✅ **ACCEPTED.** v3's O-3b read a document that *asserted* both legs were verified — it audited the assertion, not the property, which is a poor trait in a check named "offsite completeness audit". v4's O-3b queries **both** legs: leg A's remote manifest, **and** leg B's `role=complete` + `role=attestation` snapshots with the pairing verified. It alerts if **either** leg is absent, staged, unpaired, inconsistent, or older than 90 minutes | **§6 O-3b**, §4.4b2 |
| **finding 1.4** | MAJOR — "the host backup identity is outside the fence and outside the six residuals" | ✅ **ACCEPTED.** `izipos_backup` holds explicit `CONNECT` on every database and the hourly timer uses it, yet v3 revoked only `PUBLIC`/`izipos_app`/`izipos_provisioner`. It is read-only so it cannot corrupt the restore — but it can make `DROP DATABASE` fail and can dump a **half-restored** database into a cycle that then promotes to `complete`. v4 adds **step 0** (stop `erp-backup.timer`, verify inactive, handle a running `erp-backup.service`), adds `izipos_backup` to the revoke, and makes **step 11 require the next COMPLETE cycle before the incident closes** — the un-fence step that is easiest to forget and silent when forgotten | §4.6a steps 0/7/11, §4.8 step 14(c) |
| **finding 1.5** | MAJOR — "the six residual risks are not all honestly characterized" | ✅ **ACCEPTED, and the table is rewritten rather than annotated.** Three rows were wrong or overstated: (1) 🧭 the *"crashed worker's job reappears after 95 s"* explanation is **false** — expired reserved jobs migrate **only from the queue-pop path** (`RedisQueue.php:297-303,321-346`), so with no worker popping the orphan simply **stays reserved**; the second sample is kept (it is cheap and it confirms nothing is moving) but its stated reason is corrected, and the same correction is applied to §7.7b and §4.8 step 14; (2) the admin/superuser row is relabelled an **accepted operator-discipline residual**, and the section heading's claim is changed from "PROVABLE" to **FAIL-CLOSED** — with a 🎫 ticket for `ALTER DATABASE … ALLOW_CONNECTIONS false` as a cheap mechanical strengthening; (3) `QueueBusy` "none at `--max=999999`" is corrected to **"none while total queue size < 999,999"** (`MonitorCommand.php:115,156-169`). The backup-identity row is added (finding 1.4), and the fleet-wide-pause row now carries the honest worst case introduced by D-21 | §4.6a residual table, §4.6a heading, §7.7b, §4.8 step 14 |
| **finding 6.4** | MINOR — "§7.7 duplicates the same unfiltered-iteration note" | ✅ **ACCEPTED.** The duplicate paragraph is deleted and the survivor is sharpened to make the distinction the review asked for: a **probe fault** fails the aggregate, a **known-absent database** is currently `skipped` with success — which is R-35, and which is why the note now points at Phase 0.8j | §7.7c |

### 11.4c Round-3 confirmations — recorded so they are not re-litigated

| Finding | Status | Note |
|---|---|---|
| **1.1** — the F9 code facts (403 not 503, `EnsureTenantIsActive` dead, `fast_termination=false`, `queue:monitor --json` fields, six queues, `retry_after` 90 s) | **CONFIRMED ACCURATE** | No fix required. v4 changes the *procedure* built on these facts, not the facts |
| **2.1** — the F13/N3 no-delete credential conflict | **CLOSED** | `rclone copy --immutable` + `check --one-way` semantics, the per-object inventory, and DR-3 step 9's per-key comparison are all coherent. The adjacent defects (2.2, 2.3) are dispositioned above |
| **3.5** — the TLS branch is now singular | **CLOSED** | One cold-start path (DNS → Traefik → LE HTTP-01 → app); the staging-endpoint text is explicitly an *incident* fallback. Unchanged in v4 |
| **4.4** — the Object Storage half of the three-phase protocol | **CORRECTLY ORDERED** | Subject to the `set -e` fix (2.3), which v4 makes. Unchanged otherwise |
| **5.3 / F14** | **CLOSED AS DESIGNED, OPEN AS AN EXECUTION GATE** | §4.5b1 supplies the verbatim E-4a row and hard-rule amendments; the sheet is Phase-E human-only, so **the launch stays NO-GO until the named owner inserts them** (D-16). No design change in v4 |
| **5.4 / F21** | **CLOSED** | Phase order and MinIO/mc pinning confirmed. No change in v4 |
| **6.1 / QD-2, QD-3** | **CURRENT AGAINST LIVE SOURCE** | The marketplace nullable-default and import promoted-`readonly` analyses are accurate. The **procedure** they sit in inherited F9's defects and is re-ordered under R3-N1 |

### 11.4d What was argued back in round 3

| # | Sub-claim | Evidence |
|---|---|---|
| **finding 1.5, row 2** | 🧭 The review is right that v3's stated reason was false, and v4 corrects it — **but v4 keeps the second probe-A sample**, which the finding's wording ("the stated reason for the second sample is false") could be read as removing. Retained deliberately: an orphaned reserved entry staying reserved keeps the probe non-zero, which is a **safe false-negative**, and a non-zero→zero transition across the samples is itself evidence that something was still in motion when the first sample ran. The cost is 95 seconds inside a restore that is already minutes long | `RedisQueue.php:297-303,321-346`; §4.6a residual table |
| **finding 3.2** | The review offered two workable models. v4 takes the **first** (attested lock, no checked-in digests) rather than the two-commit protocol, because the two-commit protocol's rebuild exemption must be a **permanently load-bearing path-filtered CI rule** — one careless glob away from exempting real code. 🚨 **The two-commit protocol is recorded as the explicit fallback if the owner declines D-20**, and in that case its definition of "release SHA" and its audited path filter are adopted verbatim from the review | §7.0.2a, §7.0.2b, D-20 |
| **finding 4.1** | The review offered a completion-attestation model **or** a second full snapshot selected by external tag. v4 takes **both**, because they close different halves: the second snapshot supplies a *self-contained* restore candidate (an attestation alone still leaves the only complete-marked object holding no data), and the attestation supplies the *external index* needed because a snapshot cannot name itself. The added cost is measured as **H-22**, with a recorded downgrade path if it breaches H-7's cycle budget | §4.4b2 |
| **§7.4a (finding 5.1)** | The review's concrete fix says to make `permissions:verify` "the normative Phase-0 gate **now**", and v4 does exactly that — including **dropping v3's inline SQL entirely** rather than keeping it as an interim gate. Stated explicitly because the deletion is the substantive part: an interim gate that cannot be tested is what produced this finding | §7.4a |

## 11.5 Round-4 scoped re-gate disposition (v5)

Source: `docs/superpowers/reviews/2026-08-05-production-env-design-review-round4.md` — verdict **REJECT**. A **scoped** re-gate of R3-N1/N2/N3 and the Phase-0 code blockers only; it did not re-open settled round-1/2/3 items, and neither does this section. Every finding is closed **in the design** with the review's own prescribed fix, verified against `apps/api` this session. **No fix demanded a new owner decision** — `HORIZON_SUPERVISOR_TIMEOUT` is a config-default code change (Phase 0.8k), and `stop_grace_period` is already D-21.

| ID | Round-4 finding | v5 closure | Where |
|---|---|---|---|
| **BLOCKER 1** | "Horizon's 60 s master timeout defeats the 3,600 s drain": `MasterSupervisor` waits only `longestActiveTimeout()` = max supervisor `timeout`, then exits 0 — a long job is abandoned while the container reports clean exit 0, defeating the 137-detection | ✅ **CLOSED HERE.** Verified: `config/horizon.php:217` `'timeout' => 60`; `ProcessImportJob.php:56` `$timeout = 3600`; `RedisSupervisorRepository.php:101-104` (`max(... options['timeout'])`); `MasterSupervisor.php:167-203` (`terminate($status=0)` waits `longestActiveTimeout()` then `exit((int)$status)`); `entrypoint-worker.sh:48` `exec ... horizon`. Fixes landed: **(a)** the design now requires supervisor `timeout` ≥ longest job `$timeout` + margin — **`env('HORIZON_SUPERVISOR_TIMEOUT', 3900)`** — and `retry_after` strictly greater — **`env('REDIS_QUEUE_RETRY_AFTER', 4200)`** (`config/queue.php:71`), stated as the invariant `retry_after > supervisor.timeout ≥ max(job $timeout)+margin` and `stop_grace_period > supervisor.timeout` (D-21); **(b)** the stop is a **single atomic `docker service scale=0`** that sets desired replicas to 0 **and** SIGTERMs in one operation (the separate `horizon:terminate` is dropped as a two-command window); **(c)** step 5 compares the **full task-ID set** to a pre-stop baseline and rejects **any** new task id, not just "0 running"; **(d)** §4.8 step 14(a) uses a > 60 s job that must COMPLETE + no new task id, and step 14(f) adds a RED baseline at `timeout=60` reproducing the defeat. **New Phase-0 code prerequisite: 0.8k** (config default 60 is wrong for production drain) | **§4.6a** changes 2–3, steps 2/3/5, §7.7b steps 1–3, §4.8 step 14(a)/(f), **Phase 0.8k**, §7.0.3 |
| **BLOCKER 2** | "restic pairing + retention": `$COMPLETE_SNAP` never inventory-verified before the attestation/ping; `restic forget --group-by tags` self-groups on the unique cycle tag so cross-cycle retention never runs; post-prune assertion omits `attestation` | ✅ **CLOSED HERE.** **(a)** New step **B4a** inventory-verifies `$COMPLETE_SNAP` (`restic ls --json`) BEFORE B5 writes the attestation and BEFORE the dead-man ping, computes `inventory_sha256`, binds it into the attestation; restore selection rule 2, the §4.4c restic row, and **O-3b** all re-check the inventory hash of the *complete* snapshot (not just `manifest_sha256`). **(b)** `--group-by tags` is struck; retention is applied at the **cycle level** (enumerate cycles → apply hourly/daily/weekly/monthly over cycles → `restic forget` all three role snapshots of each expired cycle as a unit → `restic prune`). **(c)** `assert_cycle_retention_invariant` now requires exactly one mutually-bound `data`+`complete`+`attestation` per retained cycle | **§4.4b2** B4a + rules 2/4, §4.4c, §4.4d step 9b + items 17, **§6 O-3b**, R-36 |
| **BLOCKER 3** | "`BOOTSTRAP_STATE` check/increment is not serialized": two concurrent dispatches can both pass and both build | ✅ **CLOSED HERE.** The bootstrap dispatcher gains a **fixed non-cancelling `concurrency: {group: prod-bootstrap, cancel-in-progress: false}`** (GitHub serialises runs; a second dispatch queues), and the guard step becomes an **atomic increment-and-re-read of `attempts_used` BEFORE any build credential is read**, gating on the re-read value (`attempt_cap = 3`). Contract reworded to **"one environment bootstrap, up to three approved attempts"** — not literal "exactly once". 0.8b1 evidence adds a two-concurrent-dispatch test | **§7.0.2a** Change 1 + state-record bullet + Scope, §8 Phase 0.8b1 |
| **MAJOR 4** | "Phase 0 requires offsite-lock operations before Phase 4 creates the offsite legs" | ✅ **CLOSED HERE — deferral chosen (the cleaner option).** The offsite copy of `release-set.lock.json` and the GitHub-independent (offsite-leg) cold start are **deferred to new Phase 4.3b**, after the Object Storage bucket (4.1) and Storage Box (4.2) exist. **Phase 0 is marked artefact-store-only** — 0.8b publishes the lock to the workflow artefact store only, and 0.8c's cold-start dry run materialises it from the artefact store. Made consistent in §7.0.2a Change 2 / "where the digests go", §7.0.2b lock-location row and cold-start prose, and DR-3 (which is post-launch, so its offsite fetch is valid) | **§7.0.2a**, **§7.0.2b**, §8 Phase 0.8b/0.8c/**4.3b** |
| **MAJOR 5** | "0.8g consumes/dry-runs `permissions:verify` before 0.8h1 creates it" | ✅ **CLOSED HERE.** 0.8h1 is **physically moved before 0.8g** in the Phase-0 table, and the dependency is stated explicitly in **both** rows ("SEQUENCED BEFORE 0.8g" / "REQUIRES 0.8h1 FIRST") | **§8 Phase 0.8h1/0.8g** |
| **MINOR 6** | "the `origin/main` workflow inventory is stale (`react-doctor.yml`), and Probe A's message still says 'job reappeared'" | ✅ **CLOSED HERE.** `git ls-tree origin/main .github/workflows/` this session returns **`ci.yml`, `smoke-test.yml`, `sonarcloud.yml`** only — `react-doctor.yml` is on `dev`. Corrected in §7.0.2a (two places) and the Appendix C verification log. Probe A's failure message changed from "A crashed worker's job reappeared" to "in-flight jobs still reserved on re-sample", matching the corrected "stays reserved" reasoning; the `sleep 95` comment no longer claims a retry_after relationship | **§7.0.2a** ~L1709/1713, Appendix C.1, §4.6a Probe A |

### 11.5a Round-4 confirmations — recorded so they are not re-litigated

| Finding | Status | Note |
|---|---|---|
| **0.8b1 dispatcher on `main`** | **CONFIRMED PREREQUISITE** | Correctly gates 0.8i; `origin/main` has no dispatcher today. Only its concurrency contract needed the BLOCKER-3 fix, now applied |
| **0.8h1 `permissions:verify`** | **CONFIRMED PREREQUISITE** | No implementation exists; Spatie's `CacheReset.php:14` logs without failing, so the replacement is necessary. Only its ordering vs 0.8g needed fixing (MAJOR 5) |
| **0.8j `channels:reconcile` fail-closed** | **CONFIRMED, UNCHANGED** | `TenantScopedCommand.php:261` skips a known-missing DB without failing the aggregate; `ChannelReconcileCommand.php:130` ignores `register()`'s boolean; `register()` returns false on failure (`ChannelWebhookDirectoryRegistrar.php:45`). The v4 contract matches source; no change |

### 11.5b What was argued back in round 4

| # | Sub-claim | Evidence |
|---|---|---|
| **line number `config/horizon.php:201`** | 🧭 The review cited the supervisor `timeout` at **:201**; the current file has `'timeout' => 60` at **:217** (`grep -n "'timeout' => 60"`). The mechanism is identical; the design cites the verified **:217**. Likewise `ProcessImportJob` `$timeout = 3600` is at **:56** (review said :48/:50) and the Horizon internals are at `MasterSupervisor.php:167-203` / `RedisSupervisorRepository.php:101-104` (review said :167 / :97). All facts confirmed; only the line anchors are corrected against source | `config/horizon.php:217`; `ProcessImportJob.php:56`; `MasterSupervisor.php:167-203`; `RedisSupervisorRepository.php:101-104` |
| **MAJOR 4 — deferral vs pre-creation** | The review offered two options; v5 takes **deferral** (offsite operations → Phase 4.3b, Phase 0 artefact-only) rather than pulling bucket/Storage-Box creation into Phase 0, because a total-host DR scenario cannot exist before backups and offsite legs do, so the GitHub-independent property is genuinely a Phase-4 deliverable and pre-creating the legs in Phase 0 would only front-load cost with no Phase-0 consumer | §7.0.2b, Phase 4.3b |

---

## Appendix A — DNS record template

Owner action (D-2). Replace `<APEX>` with the production apex (default: `riserpos.app`), `<IPV4>` / `<IPV6>` with the Hetzner values.

| Type | Name | Value | TTL | Purpose |
|---|---|---|---|---|
| `A` | `<APEX>` | `<IPV4>` | **300** | Web SPA |
| `AAAA` | `<APEX>` | `<IPV6>` | **300** | Web SPA over IPv6 (dedicated includes a /64) |
| `A` | `api.<APEX>` | `<IPV4>` | **300** | API |
| `AAAA` | `api.<APEX>` | `<IPV6>` | **300** | API over IPv6 |
| `CNAME` | `www.<APEX>` | `<APEX>.` | 3600 | Redirect convenience |
| `CAA` | `<APEX>` | `0 issue "letsencrypt.org"` | 3600 | Only Let's Encrypt may issue |
| `TXT` | `<APEX>` | `v=spf1 include:<MAIL_PROVIDER_SPF> ~all` | 3600 | **D-5** — SPF |
| `CNAME`/`TXT` | `<selector>._domainkey.<APEX>` | *(provider value)* | 3600 | **D-5** — DKIM |
| `TXT` | `_dmarc.<APEX>` | `v=DMARC1; p=quarantine; rua=mailto:<OPS_EMAIL>` | 3600 | **D-5** — DMARC |

**TTL 300 on the A/AAAA records is a DR requirement, not a preference.** 🔧 **v3 correction (round-2 N5): v2 justified this with "DR-3's 4-hour RTO", a number this design no longer commits to.** The committed total-host RTO is **≤ 8 h**, with 4 h as a target and the §4.9 measured p95 replacing both (D-14). The DNS requirement is unchanged and stands on its own: a repoint that takes an hour to propagate is an hour added to whatever the measured RTO turns out to be, and §7.0.3a now puts the DNS cutover **ahead of** the TLS step, so a slow propagation blocks certificate issuance as well as traffic. Set TTL 300 and leave it.

**Order matters:** records must resolve *before* the first Dokploy deploy, or the Let's Encrypt HTTP-01 challenge fails and the deploy lands without TLS.

## Appendix B — Cost summary at the recommendation

🚨 **v3 (round-2 F37/N5): there is now ONE cost table.** v2 printed a headline table that still carried `GHCR 0.00 — free at this volume` and derived a total from it, then withdrew that same line two subsections below. A reader taking the first table at face value got a number the document itself disowned. **v2's headline table and its `B.1` correction block are DELETED; the bands table below is the single authoritative cost view, and the total is recomputed from it.**

### B.1 The authoritative table

| Item | Low | **Base (the number to quote)** | High | Driver / status |
|---|---|---|---|---|
| AX42-1 dedicated, FSN1 (D-1) | 97.30 ⚠️ | **97.30** ⚠️ | 97.30 ⚠️ | fixed SKU; ⚠️ price UNVERIFIED, requote [B2] |
| Primary IPv4 (dedicated) | 1.70 ⚠️ | **1.70** ⚠️ | 1.70 ⚠️ | [B2] |
| Hetzner Object Storage (D-3a) | 4.99 ⚠️ | **4.99** ⚠️ | ⚠️ **> 4.99** | incl. 1 TB storage + 1 TB egress [B4]. High band: the **L4a media leg with bucket versioning** (§4.5a1) retains noncurrent versions for 14 d and **never propagates deletions**, so the prefix grows monotonically until lifecycle ages it; the **64 KB minimum object size** [B4] penalises many small thumbnails |
| Hetzner Storage Box BX11 (D-3b) | ~3.20 ⚠️ | **~3.20** ⚠️ | ~3.20 ⚠️ | third-party price — **confirm in the order form** [B5] |
| Dokploy Cloud Startup (D-7) | ~13.90 ⚠️ | **~13.90** ⚠️ | ~13.90 ⚠️ | $15/mo, FX-dependent [C3] |
| 1Password (D-4 + **D-12 second seat**) | 0 (already owned) | **~14.80** | ~14.80 | **two seats.** The second custodian is required, not optional |
| **Registry — GHCR (D-9)** | 0 (public packages — **NOT recommended**: the API image ships `COPY . .` of the full application source) | ⚠️ **UNQUANTIFIED** | ⚠️ **UNQUANTIFIED** | 🚨 **v1's "€0.00, free at this volume" is WITHDRAWN and does not reappear anywhere in this document.** Private packages meter storage **and data transfer**, and every production deploy plus every DR event pulls to a **non-GitHub-Actions** host — the metered direction. Requote from the billing console (D-9, R-23). Sized by H-13 |
| Mail (D-5) | 0 (Brevo free) | **0** | ~14 (Postmark) | deliverability |
| Monitoring (UptimeRobot + healthchecks.io) | 0 | **0** | 0 | free tiers cover this scale |
| **MONTHLY TOTAL** | **≈ €121** | **≈ €136 + ⚠️ registry** | **⚠️ open, bounded below by €136** | |
| **ONE-OFF** | **€49** | **€49** | **€49** | server setup |

**How to read the base column, in one sentence:** *"About €136 a month, plus a container-registry line nobody has priced yet, plus €49 once."* 🚨 **Any statement of a total that omits the registry qualifier is wrong** — including the one in §2, which is written to match this table.

For comparison, the same design on CCX33 cloud (Option C) would be ≈ €169/mo — 32 % more for half the RAM, half the disk, and no ECC. ⚠️ Same requote caveat applies to that figure.

### B.3 Explicitly excluded from every figure above

VAT · domain renewal · paid mail growth · Sentry growth beyond the free tier · **the D-13 off-provider third backup leg** · **DR cloud-instance runtime during an incident** · **restore egress beyond included allowances** · **registry egress (D-9)** · quarterly full-host rehearsal costs (§4.9 — a throwaway cloud instance and its transfer, four times a year) · **incident labour**.

**Requote every external item from the current provider console at decision time and attach dated evidence.** No number in this appendix should be treated as current when it is read.

---

## Appendix C — Evidence verified in this session

Claims below were checked against the working tree at `dev @ bb3e6190e`, not taken from research or memory:

| Claim | Location |
|---|---|
| Tenant DB prefix is hardcoded `'tenant'`, no env | `apps/api/config/tenancy.php:62-63` |
| `tenants:migrate-rolling` failure is non-fatal | `apps/api/docker/entrypoint.sh:141` (`"completed with per-tenant errors"`) |
| `SYNC_PERMISSIONS_ON_BOOT` gates the tenant reseed; `permission:cache-reset` is unconditional | `apps/api/docker/entrypoint.sh:153-161`, `:167` |
| `REVERB_SERVER_HOST` rewrites the API nginx upstream to `http://$HOST:8080` | `apps/api/docker/entrypoint.sh:243-245`; `apps/api/docker/nginx/default.conf:60-66` |
| Horizon: 6 queues, `memory=128`, production `maxProcesses=10` | `apps/api/config/horizon.php:209,216,223` |
| `pg_restore` in the app has **no** Timescale pre/post pair (and no `-j`) | `apps/api/app/Modules/Tenant/Application/Services/TenantBackupService.php:260-289` |
| `BACKUP-RECOVERY.md` uses forbidden `-j 4` / `-j 2` | `docs/operations/BACKUP-RECOVERY.md:152-154`, `:232` |
| `all-checks-pass` gates on 12 jobs, main-only | `.github/workflows/ci.yml:1022-1036` |
| `origin/main` is 4,303 commits behind `origin/dev`, last moved 2026-06-29 | `git rev-list --left-right --count origin/main...origin/dev` → `3 4303` |
| `VITE_REVERB_APP_KEY` ARG declared, never passed; echo falls back to `'local_key'` | `apps/web/Dockerfile:51`; `apps/web/src/lib/echo.ts:27`; `grep REVERB_APP_KEY docker-compose*.yml` |
| Web nginx is generated by the entrypoint (has `/app/` + `/broadcasting/`); `nginx.conf.template` is dead | `apps/web/Dockerfile:66`; `apps/web/docker/entrypoint.sh:38,145,160`; `apps/web/nginx.conf.template` (no such locations) |
| `API_URL` unset ⇒ web entrypoint hard-exits | `apps/web/docker/entrypoint.sh:11-15` |
| `MAIL_MAILER=log` by default; the app really sends mail | `apps/api/.env.example:120`; `EmailVerificationService`, `DocumentEmailService`, `FraudAlertNotificationService` |
| `LOG_STACK=single`, `LOG_LEVEL=debug` by default | `apps/api/.env.example:23-26`; `apps/api/config/logging.php:57,72` |
| No migration installs `timescaledb`; no `create_hypertable` | `grep -rn "CREATE EXTENSION" apps/api/database/migrations/` → only `btree_gist` at `tenant/2026_04_19_140003_…:31` |
| API workdir is `/var/www/html`; no storage volume in any compose | `apps/api/Dockerfile:90`; `docker-compose.staging.yml` |
| E-10 explicitly rules that a push to `origin/dev` is not production clearance | `docs/handoff/OWNER-manual-launch-gates-2026-07-31.md`, E-10 row |

### C.1 Additional evidence verified during the v2 revision

| Claim | Location |
|---|---|
| Stancl resolves the **stored** `db_name` first; the prefix is only a fallback | `vendor/stancl/tenancy/src/DatabaseConfig.php:39-41,66-69` |
| `CreateDatabase` persists `db_name` **before** creating the database | `vendor/stancl/tenancy/src/Jobs/CreateDatabase.php:30-43`; `DatabaseConfig.php:81-97` |
| 🚨 **Stancl creates tenant databases `WITH TEMPLATE=template0`** | `vendor/stancl/tenancy/src/TenantDatabaseManagers/PostgreSQLDatabaseManager.php:32-35` |
| Server-side broadcasting uses `REVERB_HOST`/`PORT`/`SCHEME`, defaulting to `null`/`443`/`https` | `apps/api/config/broadcasting.php:31-43` |
| Roles are separate **build targets**, not `CONTAINER_ROLE` — and all four derive from one `base` stage, differing only in `CMD`/`HEALTHCHECK`/`EXPOSE` | `apps/api/Dockerfile:145-188` |
| `CONTAINER_ROLE` selects bundled-vs-split **inside** the api entrypoint; it is set only on the `api` service | `apps/api/docker/entrypoint.sh:231-237`; `docker-compose.dokploy.yml:178` |
| `base` contains all four entrypoint scripts (`entrypoint{,-worker,-scheduler,-websocket}.sh`) | `apps/api/Dockerfile` COPY + chmod block |
| `audit_events` is an **ordinary tenant table** — no hypertable, no extension | `apps/api/database/migrations/tenant/2025_11_30_140000_create_audit_events_table.php` (38 lines) |
| No `timescale`/`hypertable` reference in `app/`, `config/`, or `database/` beyond two explanatory comments | grep across `apps/api` excluding `vendor` |
| 🚨 **v5 (round-4 finding 6) — `origin/main` `.github/workflows/` holds three files: `ci.yml`, `smoke-test.yml`, `sonarcloud.yml` — no registry or deploy workflow, and NO `react-doctor.yml` (that file is on `dev`)** | `git ls-tree origin/main .github/workflows/` this session |
| The checked-in Dokploy compose is staging-shaped (`DB_CONNECTION=pgsql`, `DB_DATABASE=autoerp`, `AUTO_SEED=true`, Meilisearch, 512 M worker) yet its header instructs direct deployment | `docker-compose.dokploy.yml:1-5,14-49,195-212` |

---

## Appendix D — Numeric claim register (v2, Finding 38)

**Rule: no figure in this register may be used as a commitment, an SLA, or a gate-closure argument until its Status is `MEASURED`.** Owner column = who produces the evidence, not who is accountable for the outcome.

### D.1 Verified static configuration — usable as fact

| Claim | Value | Evidence |
|---|---|---|
| Horizon production queues | 6 | `config/horizon.php:209` |
| Horizon production `maxProcesses` | 10 | `config/horizon.php:216` |
| Horizon worker restart threshold | 128 MB *(a threshold, **not** a reservation)* | `config/horizon.php:223` |
| Horizon master memory limit | 64 MB | `config/horizon.php:177-188` |
| PHP per-process memory limit | **256 MB** | `docker/php/php.ini:5-9` |
| API build targets + web | 4 + 1, one shared `base` | `apps/api/Dockerfile:145-188` |
| `all-checks-pass` dependencies | 12, main-only | `.github/workflows/ci.yml:1022-1039` |
| `origin/main…origin/dev` divergence | 3 / **4,303**; merge-base diff 8,455 files, ~1,349,569 insertions | measured this session |
| Laravel daily log retention | 14 files, **no size cap** | `config/logging.php:53-74` |
| In-app tenant backups retained | 14 per tenant | `config/tenant_backups.php:18-30` |

### D.2 🔬 Hypotheses — measurement required

🚨 **v3 (round-2 F38/N6): every row now carries a NUMERIC or BINARY acceptance rule AND a reopen rule.** The re-gate spot-checked H-5, H-10 and H-13 and found circular or non-threshold cells ("≥ the rate H-2 assumes" when H-2 specifies no rate; "feeds L4a cost + RTO"; "feeds D-9"), with the same defect in H-6, H-8, H-9 and H-15. **Where a measurement is genuinely informational, its column is relabelled `MEASUREMENT OUTPUT` and the downstream decision formula is stated** — per the re-gate's own instruction. A cell that says what a number feeds is not a threshold.

| # | Claim | Owner | Method | **Acceptance rule (numeric/binary)** | **Reopen rule — what makes this a problem** | Status |
|---|---|---|---|---|---|---|
| H-1 | Worker peak RSS, 10 processes, heaviest jobs, incl. recycle overlap | **[A]** | V-9 load test (§7.4) | **peak RSS × 1.5 ≤ 2048 MB** | peak × 1.5 **> 2048 MB** ⇒ raise the cgroup **or** lower `maxProcesses` until it fits, then re-run. **Any OOM kill or unexplained restart = automatic fail** regardless of the RSS number | ⬜ NOT MEASURED — **launch gate** |
| H-2 | Total-host RTO | **[A]** + **[O]** | §4.9 full-host rehearsal, 10 steps | **p95 ≤ 8 h** (the committed number) | p95 **> 8 h** ⇒ the committed RTO is **raised to the measured p95** and D-14 is re-signed. p95 **≤ 4 h** ⇒ the commitment may be lowered to 4 h. **Movement in either direction requires owner re-acceptance** | ⬜ NOT MEASURED — **gates D-14** |
| H-3 | Postgres-volume-loss RTO | **[A]** | timed DR-2 during Phase 7.9 | **≤ 4 h** | **> 4 h** ⇒ restate DR-2's committed RTO to the measured value and re-check §9.1's "move Postgres to its own host" triggers | ⬜ NOT MEASURED |
| H-4 | Single-tenant RTO | **[A]** | §4.8 step 10 | **≤ 1 h**, *including* the §4.6a drain (probe A can legitimately consume ~2 min of that) | **> 1 h** ⇒ restate DR-1's RTO; **> 2 h** ⇒ investigate the drain bound before restating, since the fence is the new term | ⬜ NOT MEASURED |
| H-5 | Offsite→cloud restore bandwidth, per leg | **[A]** | §4.9 step 4, **both legs**, timed at the cycle's real size | 🚨 **De-circularised: ≥ 100 Mbit/s effective.** At the design's own 40 GB projection that is ~53 min of transfer, which fits an 8 h budget with room for the other nine steps | **< 100 Mbit/s** ⇒ recompute H-2's budget with the measured rate and, if the recomputed p95 exceeds 8 h, either raise the RTO commitment or add a nearer restore source. **< 25 Mbit/s** (~3.6 h for 40 GB) ⇒ **reopen D-13** (off-provider leg) as a *latency* decision, not only a durability one | ⬜ NOT MEASURED — **the largest unknown in the RTO budget** |
| H-6 | Serial `pg_restore` throughput | **[A]** | §4.9 step 5 | **≥ 20 MB/s of dump input**, i.e. a 40 GB compressed set restores in ≤ ~35 min | **< 20 MB/s** ⇒ feed the measured rate into H-2 and re-test the commitment. **< 5 MB/s** ⇒ escalate: at that rate the restore, not the download, becomes the RTO driver, and PITR (D-10) changes from optional to load-bearing | ⬜ NOT MEASURED |
| H-7 | Full dump-cycle wall-clock | **[A]** | logged every run (§4.4d item 5) | **< 5 min** | **≥ 5 min** ⇒ early-warning alert (§9.1). **≥ 30 min** ⇒ the hourly cadence is no longer achievable (cycles would overlap; `flock` exits 3) and pgBackRest (D-10) is **forced**, not optional | ⬜ NOT MEASURED |
| H-8 | `-Fc -Z6` compression ratio | **[A]** | first cycle: compare `pg_database_size()` sum against the dump-set bytes | **≥ 4:1** (the ratio Appendix E's 20 %-of-mount ceiling is sized against) | **< 4:1** ⇒ recompute Appendix E's local-staging ceiling and the Object Storage line in Appendix B; **< 2:1** ⇒ the "48 hourly sets" maximum is unreachable within the ceiling and the retention tier is cut before launch | ⬜ NOT MEASURED |
| H-9 | Cluster size (v1 asserted 272 MB / 8 tenants; ~24–25 MB empty tenant) | **[A]** | `\l+` on staging, recorded with a date | **MEASUREMENT OUTPUT** — no pass/fail. **Downstream formula:** it is the input to H-8's ceiling and to §9.1's growth table. **Binary sub-assertion that IS a gate: a measurement artefact with a date must exist before H-2's rehearsal is run**, because a rehearsal at an unrecorded data size validates nothing | ⚠️ **UNVERIFIED — no measurement artefact exists.** Reopen: any rehearsal executed without this recorded is **invalid** | ⚠️ UNVERIFIED |
| H-10 | Media volume at launch (~1–5 GB) | **[A]** | `mc du` after catalogue load | 🚨 **De-circularised: ≤ 20 GB** — the point at which the hourly append-only leg still fits comfortably inside Object Storage's 1 TB inclusion over a 14-day version window | **> 20 GB** ⇒ re-band Appendix B's Object Storage line and re-time the L4a leg against H-7's 5-min budget. **> 50 GB** ⇒ §9.5's "migrate MinIO off-box" trigger fires **at launch** rather than later | ⬜ NOT MEASURED |
| H-11 | Tunis→FSN1/NBG1 RTT (35–55 ms band) | **[O]** | `mtr` from Tunis (Phase 1.2) | **≤ 80 ms** | **> 80 ms** ⇒ record and proceed, but re-test dashboard UX explicitly (POS is offline-first, dashboards are not). **> 120 ms** ⇒ **reopens D-1's location choice** | ⬜ NOT MEASURED |
| H-12 | Request-rate model (10–25 req/s peak vs ~320 req/s per pool) | **[A]** | load test at tenant #1 | **sustained capacity ≥ 5 × the measured peak**, i.e. ≥ 125 req/s if peak is 25 | **< 5×** ⇒ scale php-fpm children before onboarding tenant #2. **< 2×** ⇒ launch-blocking; §9.1's scaling steps move ahead of tenant #1 | ⬜ NOT MEASURED |
| H-13 | Image size and per-deploy registry egress | **[A]** | §4.9 step 3 — bytes transferred pulling all five digests | 🚨 **De-circularised: total pull ≤ 2 GB per deploy**, and **monthly projected egress (pulls/month × pull size) ≤ the plan's included data transfer**, read from the billing console | **> 2 GB per pull set**, or projected egress **> the plan allowance** ⇒ **D-9 reopens** with three named options: a larger plan, a slimmer API image (multi-stage prune), or a different registry. **This is the number that turns D-9 from "unquantified" into a decision** | ⬜ NOT MEASURED — **gates D-9** |
| H-14 | Total on-disk footprint (v1: "< 150 GB, ~70 % free") | **[A]** | **Appendix E** budget + live `df`/`du` | **every class under its Appendix E ceiling, and `/` under 60 %** | any class over its ceiling ⇒ that class's enforcement action fires (Appendix E). `/` **> 60 %** ⇒ §9.1's "order a Storage Box/volume, then plan the split" | ⚠️ **WITHDRAWN as stated; rebuilt from Appendix E** |
| H-15 | Observed DNS propagation after an A+AAAA change | **[A]** | §4.9 step 9, **two independent networks**, polling both A and AAAA | 🚨 **De-circularised: ≥ 95 % of resolvers polled return the new address within 15 min, and 100 % within 60 min** | **> 15 min to 95 %** ⇒ add the measured delay to H-2's budget as a fixed term. **> 60 min to 100 %** ⇒ investigate the provider's TTL honouring; a stale **AAAA** in particular is the "works for some users" failure mode (Finding 19) and is treated as a **launch-blocking DNS defect**, not a slow propagation | ⬜ NOT MEASURED |
| H-16 | Whether `pg_dumpall --globals-only` as `izipos_backup` captures role passwords | **[A]** | Phase 4.3a | **BINARY: a role restored from `globals.sql` into a scratch cluster authenticates with its original password** | fail ⇒ **credential rotation becomes a mandatory documented step of every cluster restore**, and the §4.6c step 4 text switches from "verify" to "rotate" | ⬜ NOT MEASURED |
| H-17 | Whether `timescaledb` is present in `iziposcentral` / `template1` / tenant DBs | **[A]** | Phase 2.2 `\dx` on all four targets | **BINARY, and the expected answer is stated in advance (§4.6c): absent from every tenant DB.** Recording it is the gate; **both restore branches are implemented and tested regardless of the answer** | **present in any `izipostenant_*` database** ⇒ the `TEMPLATE template0` reasoning in §4.6c is wrong somewhere and the restore procedure is re-derived before Phase 4 | ⬜ NOT MEASURED |
| H-18 | Whether three Dokploy Applications can share one external volume across independent redeploys | **[A]** | Phase 3.2a | **BINARY: write from `api`, read from `worker` AND `scheduler`, redeploy each of the three independently, re-read after each — all six reads succeed with correct ownership** | any read fails ⇒ **fall back to a single Dokploy Compose stack** for api/worker/scheduler and record the coupled-deploy tradeoff (R-22) | ⬜ NOT MEASURED |
| **H-19** | 🚨 **NEW in v3 — Mode B drain duration.** How long §4.6a's drain takes on a live system. 🔧 **v4 re-anchors the measurement**: there is no `horizon:pause` any more, so it is timed **from `horizon:terminate` to both probes green** (steps 3–6) | **[A]** | §4.8 step 14 (fence + drain test), timed, on a fleet with a representative job mix **and separately with a ≥ 90 s job running** | **≤ 5 min** for the representative mix; **and, separately, the long-job case must complete within `stop_grace_period` with container exit code 0** — a `137` is an automatic fail regardless of elapsed time | **> 5 min** ⇒ the drain becomes the dominant term in H-4 and DR-1's RTO is restated. **> 10 min** ⇒ reopen the per-tenant queue-partitioning ticket (§4.6a residual table). **Any exit code 137** ⇒ `stop_grace_period` is too short for the real job mix and **D-21's bound is wrong** — raise it or split the long queue before launch |
| **H-20** | 🚨 **NEW in v3 — media check mode and duration.** Whether the provider exposes a hash rclone can compare without downloading, and how long L4a′ takes | **[A]** | Phase 4.5a, first cycle | **BINARY: `rclone check --checksum --one-way` completes without falling back to `--download`, in ≤ 60 s at launch catalogue size** | no comparable hash ⇒ `check_mode` becomes `download`, the check moves to **daily** (not hourly), the egress is added to Appendix B's Object Storage high band, and §4.5a1's evidence strength is downgraded in the manifest |
| **H-22** | 🚨 **NEW in v4 — cost of the restic completion re-snapshot (§4.4b2 B4).** The added wall-clock of taking a second full-directory snapshot after the promote, and the bytes it actually stores | **[A]** | Phase 4 first cycles: compare cycle duration with and without B4, and read `restic backup --json`'s `data_added` for the B4 snapshot | **≤ 60 s added AND `data_added` ≤ 5 MB** (dedup should store one changed manifest blob plus tree metadata) | **> 60 s** ⇒ recheck against H-7's 5-minute cycle budget; if the cycle no longer fits, adopt §4.4b2's recorded fallback (snapshot the manifest subdirectory only) **and mark leg B's evidence strength down in the manifest** — do not silently drop B4. **`data_added` materially above 5 MB** ⇒ dedup is not behaving as assumed and the whole two-snapshot design should be re-derived before launch |
| **H-23** | 🚨 **NEW in v4 — cold-start lock retrieval.** Whether `release-set.lock.json` can actually be fetched and verified **from an offsite backup leg** with no access to GitHub, and how long it takes | **[A]** | §4.9 full-host rehearsal, **with the artefact store deliberately unavailable** — this is the path R-33 exists for | **BINARY: the lock is retrieved from a backup leg, its signature verifies, and `docker compose up -d` starts with the correct digests — in ≤ 10 min** | retrieval fails, or the signature cannot be verified without GitHub ⇒ **D-20 is not safely implementable as specified** and the design falls back to the two-commit protocol (§7.0.2a). **> 10 min** ⇒ add the measured time to H-2's RTO budget as a fixed term |
| **H-21** | 🚨 **NEW in v3 — bootstrap supersession lag.** Elapsed time between the §7.0.2a bootstrap deploy and the first green-`main` build superseding it | **[A]** | Phase 0 evidence, timestamps | **≤ 14 days** | **> 14 days** ⇒ the exception has stopped being "one-time" in practice; the owner must either re-approve it explicitly with a new sunset date or halt production changes until the promotion lands. **Under no circumstances does tenant #1 onboard while a `bootstrap-` digest is running** (V-10 enforces) |

### D.3 ⚠️ UNVERIFIED external claims — stay UNVERIFIED until requoted from the provider

Server SKU availability and every price in D-1 · the 15 Jun 2026 price rise · dedicated provisioning time · Object Storage and Storage Box pricing, inclusions, egress, and **object-lock modes/semantics** · 1Password plan/pricing/FX · Brevo/Postmark pricing, quotas, and deliverability · Dokploy Cloud tiers, server counting, backup limits, **remote-server monitoring**, Telegram event coverage, **1-concurrent-build limit**, 2-day metrics retention, panel/account outage behaviour, upgrade entitlement and rollback support · GHCR private-package storage/egress terms · domain ownership, renewal cost, and current TTLs · cloud-instance ~60 s provisioning · **Tunisian fiscal retention law** · GitHub CI historical run status.

**Provider claims and performance estimates are kept separate from D.1's static configuration values on purpose.** Conflating them is how "verified" ends up attached to a price.

---

## Appendix E — Capacity budget (v2, Findings 15 and 30)

Replaces v1's *"design total < 150 GB [R3], ~70 % free"*, which was an assertion with no line items. **This is a launch deliverable: it is filled in with measured values during Phase 2–4 and re-checked at every capacity alert.** Ceilings are enforced; alerts fire on **bytes and inodes**.

🚨 **v3 (round-2 F30): every row now has a NUMERIC ceiling, the CRON CHECK that measures it, the ALERT THRESHOLD, and THE COMMAND that enforces it.** The re-gate is right that v2's table was not uniformly a budget: "policy required at 10 tenants", "re-evaluate", "no size cap", and "keep last N" without defining N are **triggers and tickets, not enforceable ceilings**. A ceiling with no mechanism is a wish.

**All checks below run from one host script, `/usr/local/sbin/erp-capacity-check.sh`, on a systemd timer every 15 minutes** (the same pattern as §4.4's backup timer — panel-independent by construction). It emits to Telegram on breach **and** pings healthchecks.io only while every class is under its ceiling, so a dead check is itself an alert (this is O-6, widened from `df /` to the whole table).

| Class | Location | Growth driver | **Hard ceiling** | **Check (every 15 min)** | **Alert** | **Enforcement command** | Measured |
|---|---|---|---|---|---|---|---|
| PGDATA | `pgdata` volume | tenants × data | **≤ 60 % of the disk** | `du -sb /var/lib/docker/volumes/<pgdata>/_data` | warn 50 %, critical 60 % | **manual** — §9.1's "move Postgres to its own host". 🚨 No automatic action exists or should: silently deleting database files is never correct | ⬜ |
| **Local dump staging** | `/mnt/backups/pg` — **own mount** | cycles × cluster size ÷ compression (H-8) | **< 20 % of its mount**, floor of ≥ 2 complete cycles | `df -P /mnt/backups` | **15 %** | **automatic** — `prune_local_to_capacity_ceiling` in `erp-backup.sh` (§4.4d item 11), oldest-first | ⬜ |
| Incomplete cycles | same | failures | **zero `*.partial` older than 2 cycles** | `find /mnt/backups/pg -mindepth 1 -maxdepth 1 -name '*.partial' -mmin +120` | any hit older than 2 cycles | **automatic** — `cleanup_incomplete_cycles` every run (§4.4d item 10) | ⬜ |
| MinIO media | `miniodata` | catalogue size (H-10) | **≤ 50 GB on-box** | `mc du local/<bucket>` | warn 25 GB, critical 50 GB | **manual** — §9.5 migration to Hetzner Object Storage, gated on the path-style test | ⬜ |
| appstorage — framework caches | `appstorage` | none (derived) | **≤ 1 GB** | `du -sb storage/framework` | 1 GB | **automatic** — `php artisan optimize:clear` (safe: derived state only) | ⬜ |
| appstorage — tenant local writes | `appstorage` | exports/imports | 🚨 **v3 defines it: ≤ 5 GB total, and no file older than 30 days.** v2 said only "pruning policy required at 10 tenants", which is a ticket, not a ceiling | `du -sb storage/app/*/ ` per tenant suffix | warn 3 GB, critical 5 GB | **automatic** — `find storage/app -mindepth 2 -type f -mtime +30 -delete` (exports and temp import files are **regenerable by re-running the operation**, §3.5 — which is exactly why a time-based purge is safe here and nowhere else in this table) | ⬜ |
| **appstorage — in-app tenant backups** | `appstorage` | **`TENANT_BACKUP_KEEP` × tenants × tenant data** | 🚨 **v3 defines it: ≤ 10 GB total.** v2 said "re-evaluate at 10 tenants" | `du -sb storage/app/*/tenant-backups` | warn 7 GB, critical 10 GB | **automatic on breach** — lower `TENANT_BACKUP_KEEP` from **14 to 7** (`config/tenant_backups.php:30`) and re-run the service's own pruner (`TenantBackupService.php:291-313`). **Safe because §4's dumps are authoritative and these are a convenience copy** (§3.5) | ⬜ |
| Laravel daily logs | `appstorage` | traffic × `LOG_LEVEL` | 🚨 **v3 defines it: ≤ 2 GB total across the 14 retained files.** v2 said "no per-file cap exists", which is a true statement about the framework and not a ceiling | `du -sb storage/logs` | warn 1 GB, critical 2 GB | **automatic** — delete the oldest daily files beyond the newest 3 (they are also in Sentry for errors, §6.3). **Plus the standing controls:** `LOG_LEVEL=info` and `LOG_DAILY_DAYS=14` (§3.4). 🎫 Size-based rotation remains a code ticket; the host-side purge is the enforcement until it lands | ⬜ |
| Docker container logs | `/var/lib/docker` | 5 × 50 MB **per container** | **≤ 3 GB** (8 persistent containers ≈ 2 GB, **plus Traefik and the Dokploy agent**) | `du -sb /var/lib/docker/containers` | 3 GB | **automatic at the daemon** — `max-size: 50m`, `max-file: 5` (§3.1). A breach means an unrotated container appeared: `docker inspect --format '{{.HostConfig.LogConfig}}'` every container to find it | ⬜ |
| Docker images + build cache | `/var/lib/docker` | release cadence × image size (H-13) | 🚨 **v3 defines N: keep the last 3 release sets (15 image manifests) + the pinned `last-known-good` set.** v2 said "keep last N" without defining N | `docker system df` | warn 20 GB, critical 30 GB | **automatic, weekly** — `docker image prune -af --filter "until=720h"` **after** asserting via `RELEASE-SETS.md` (§7.0.5) that the retained sets survive. 🚨 The registry, not the host, is the durable copy — the host prune can never orphan a rollback target because §7.0.5 keeps 10 sets **in GHCR** | ⬜ |
| Postgres WAL | `pgdata` | write volume | **≤ 5 GB** at launch; grows materially **if D-10 adopts pgBackRest** | `du -sb <pgdata>/pg_wal` | 5 GB | **manual/investigate** — sustained WAL growth at one tenant means a stuck replication slot or an unarchived segment, not normal load. Revisit the ceiling at D-10 | ⬜ |
| **Inodes (all mounts)** | `/`, `/mnt/backups` | many small media objects, many small log files | **≤ 80 % inode use** | `df -Pi` | 80 % | **investigate** — the usual cause is the media prefix or an unrotated log dir; the byte ceilings above are the fix | ⬜ |

**Alert thresholds, consolidated:** disk **75 % warn / 85 % critical** (O-6) · backup staging **15 % of its mount** · **inode use 80 %** · **any class exceeding its own warn/critical row above**. Every critical alert enters the §4.4d item 14 escalation ladder.

**One rule that governs the whole table:** an **automatic** enforcement action may only delete data that is either derived, regenerable by re-running an operation, or held authoritatively elsewhere. Everything else is **manual** and alerts instead. That is why PGDATA, MinIO media and WAL have no automatic action, and why the in-app tenant backups do — §4's dumps are the authority for those.
