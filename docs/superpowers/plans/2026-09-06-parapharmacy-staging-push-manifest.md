# Parapharmacy staging push manifest (canonical)

> Single source of deployment truth for every parapharmacy remediation slice plan.
> Slice plans **reference** this file; they do not re-derive deploy steps.
> Verified against the repo at local `dev` on 2026-09-06. Every `path:line` below was read.
> Anything not provable from the repo is in **§6 UNVERIFIED**.

Scope: `apps/erp` → staging (`erp.otospex.dev` / `api.erp.otospex.dev`). Production (AX42) is out of scope.

---

## 0. Topology caveat — read before using §1

`docker-compose.staging.yml` is checked in and describes the intended staging topology
(api / worker / scheduler / websocket / web + postgres, redis, pgbouncer, meilisearch, minio).
Whether staging is actually deployed **as that compose stack** or as **separate Dokploy
applications** (an API app and a web app, each with its own Environment tab) is
**UNVERIFIED from the repo** — but the evidence points at separate applications: the web is a
distinct Dokploy application with its own id and `autoDeploy=false`
(`docs/factory/WORKFLOW.md:206-208`). This matters for exactly one thing: **how a new env var
reaches a container** (§1 row E). Treat both paths as live until §6 U-1 is closed.

> **RESOLVED 2026-09-09 — `separate_applications`.** Read-only Dokploy inspection: nine Dokploy applications (API `x5wfthp8-7cVbiUfI6Hq7`, worker `KKYDsAvk4UpYfJXVmsDj2`, scheduler `HSXqHvmo_vq7NAYIjrE3T`, web `mY6P_PHb4pw-2LdG1Y7Ml`, …) and zero compose services. Env vars reach a container ONLY through each application's own Dokploy `env` field followed by a redeploy of that application. Full facts, IDs and the `SYNC_PERMISSIONS_ON_BOOT=true` finding: [`2026-09-09-parapharmacy-staging-topology-U1-resolution.md`](2026-09-09-parapharmacy-staging-topology-U1-resolution.md). The compose path below is retained for history only.

---

## 1. Facts table — mechanisms, triggers, evidence, pitfalls

| # | Mechanism | Trigger | What it does | Evidence | Pitfalls |
|---|---|---|---|---|---|
| A | **API auto-deploy on push** | a push that fast-forwards `origin/dev` | Rebuilds + restarts the staging API image; the entrypoint then migrates and seeds (rows B–D) | `docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md:4`; `docs/factory/WORKFLOW.md:217`; memory `feedback_push_dev_autodeploys_migrations` | **The push IS the deploy** — a checklist saying "run X before the migration" is not a gate. Each push is a ~5 min API outage (Traefik 502) while boot migration + permission sync run. |
| B | **Central migrations at boot** | API container start | `php artisan migrate --force` against the **direct** PG host (`DB_DIRECT_HOST`, default `postgres`), bypassing PgBouncer (advisory locks) | `apps/api/docker/entrypoint.sh:103-136` | **Non-fatal.** A failure prints `Migrations: [failed - check logs]` and boot continues serving traffic on a drifted schema (`:132-136`). |
| C | **Rolling tenant migrations at boot** | API container start, after B | `php artisan tenants:migrate-rolling --force` — drives Stancl `tenants:migrate` **one tenant at a time**, isolating per-tenant failures; idempotent | `entrypoint.sh:138-154`; `apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:48-53,80-104,110-133,158-175` | **Non-fatal at boot** (`entrypoint.sh:150-154` prints `[completed with per-tenant errors]` and continues). The command exits 1 if any tenant failed (`:168-174`) — that exit code is **swallowed by the entrypoint**. It is a **documented no-op** when `tenancy_resolver.db_per_tenant` is false (`RollingTenantMigrationCommand.php:57-62`; flag `apps/api/config/tenancy_resolver.php:30`, default **false**, and **absent from `docker-compose.staging.yml`**). |
| D | **Boot seeding** | API container start | `AUTO_SEED=true` seeds only when tenant count is 0 (`entrypoint.sh:179-200`); `SEED_DEMO_PHARMACY=true` runs `DemoPharmacySeeder` every boot, idempotent (`:202-215`); `permission:cache-reset` always (`:176`) | `entrypoint.sh:176-215`; `docker-compose.staging.yml:53,57` | `RolesAndPermissionsSeeder` sync is **opt-in** via `SYNC_PERMISSIONS_ON_BOOT=true` (`entrypoint.sh:162-170`) and that var is **absent from `docker-compose.staging.yml`** → new permissions do **not** reach existing tenants on deploy → 403s. Run it explicitly (§2 Push 4). |
| E | **Env vars into containers** | compose `environment:` inheritance | Every Laravel service inherits the `x-api-env` anchor; values come from `${VAR}` interpolation. There is **no `env_file:`** anywhere in the file | `docker-compose.staging.yml:17-57,184-186,212-213,233-234,248-249` | **A var not listed in `x-api-env` never reaches the container** on the compose path — setting it in Dokploy alone changes nothing. On the separate-applications path Dokploy's Environment tab injects directly. Either way: **read the value back from inside the container** before believing a flag is on (§2 Push 5). |
| F | **Dormant-code push (feature flags)** | any push | A flag declared `'x' => (bool) env('FLAG', false)` in `config/*.php` ships **inert**: nothing set, nothing on | `apps/api/config/treasury.php:28`; `apps/api/config/country_defaults.php:6-7`; `apps/api/config/marketplace.php:6` | **Dormant push is supported and is the intended shape.** The flag must be read identically in API *and* worker; the G3 lane records an audited-but-real skew failure mode (`docs/superpowers/tickets/2026-08-08-g3-shift-variance-gl-deploy-notes.md:115-119`, reason `feature_disabled_after_enqueue` at `:194`). |
| G | **Queue / Horizon** | worker container | Horizon consumes **redis only**; every `onQueue('x')` and `public $queue = 'x'` must appear in `defaults.*.queue`; the `staging` environment key must exist or Horizon starts **zero** supervisors | `apps/api/config/horizon.php:201-220,222-242`; guard `apps/api/tests/Unit/Config/HorizonQueueCoverageTest.php:10-34,37` | `QUEUE_CONNECTION` must be `redis` in every POS-serving env (`g3-…-deploy-notes.md:99-111`); `config/queue.php` defaults to `database` — jobs would enqueue and never be consumed with a green-looking Horizon. Staging compose sets it (`docker-compose.staging.yml:34`). |
| H | **`tenants:run` exit status is untrustworthy** | any fleet command | Stancl's `tenants:run` **discards each child exit code** | `apps/api/app/Console/Commands/SeedChartsCommand.php:47-55`; `apps/api/app/Modules/POS/Commands/CloseOrphanedShiftCommand.php:126-128`; `docs/handoff/RUNBOOK-day-one-census.md:9,25` | **Never gate on `$?` for a `tenants:run` invocation.** Capture stdout and grep the stable per-tenant markers (`DAY-ONE CENSUS`, `DRIFT(`, `status=FAILED`, `Chart provisioning:`). |
| I | **Backups** | manual only | `php artisan tenant:backup [slug\|--all]` runs `pg_dump` (custom format) into `storage/app/tenant-backups/<uuid>/<ts>.dump` | `apps/api/app/Modules/Tenant/Application/Commands/BackupTenantCommand.php:19-22`; `apps/api/app/Modules/Tenant/Application/Services/TenantBackupService.php:21-22,338` | **That path is INSIDE the container and no volume is mounted on `api`** (`docker-compose.staging.yml:178-201`; volume list `:281-285`) → the next deploy destroys it. Take the backup **on the host, outside the deploy**: host `pg_dump` per the runbook pattern (`/Users/houssamr/Projects/syneriva/claude/deploy-runbook.md:83`), or `tenant:backup` immediately followed by `docker cp` out. |
| J | **Web is a separate deploy** | explicit Dokploy deploy | Staging web has `autoDeploy=false` — promoting to `origin/dev` does **not** update the web bundle | `docs/factory/WORKFLOW.md:206-208`; `docs/handoff/HANDOVER-next-session-2026-07-05.md:8` (app id `mY6P_PHb4pw-2LdG1Y7Ml`) | On 2026-07-04 the staging bundle sat **78 commits stale, undetected** (`WORKFLOW.md:220-227`). Verify **both** asset hash **and** a feature fingerprint. |
| K | **Push discipline hook** | PreToolUse on a `dev`-targeting push | Denies force-pushes and pushes of a behind/diverged local `dev`, printing the exact reconcile command | `.claude/hooks/git-dev-push-guard.sh:12-24,73-75,78-88` | Fails **open** if `jq` is missing (`:32`). Commit and push must be **separate** Bash calls so the hook can inspect the push (`WORKFLOW.md:203-205`). It also false-positives on heredocs containing example push commands — write such files with an editor tool, not a heredoc. |
| L | **Parent-repo runbook scope** | — | `/Users/houssamr/Projects/syneriva/claude/deploy-runbook.md:54-66` documents boot-time `migrate --force` for the **Synerivia platform app on AX42 production**, not `apps/erp` staging | same | Do not cite its `AUTO_MIGRATE=false` opt-out for the ERP — the ERP entrypoint has no such switch. Its useful-for-us parts are the Dokploy quirks (`:16-52`) and the host `pg_dump` pattern (`:83`). |

---

## 2. The canonical five-push sequence

Acyclic by construction: nothing in push *n* may depend on anything shipped in push *n+1*.
A slice may **collapse** pushes it does not need (state it explicitly) but may never **reorder** them.
Placeholders: `<slice>`, `<flag>`, `<FLAG_ENV>`, `<tenant-uuid>`, `<api-container>`.

Common shell prelude (every push):

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp
git fetch origin dev
git log --oneline dev..origin/dev | wc -l    # MUST be 0 (PROMOTION-CHECKLIST-2026-08-26.md:15-20)
git log --oneline origin/dev..dev | wc -l    # your ahead count
PREFLIGHT_TEST_PATHS='<covering test paths>' ./scripts/preflight.sh
git commit -m "<msg>" -- <explicit paths>    # separate Bash call from the push
# then, as its own separate Bash call, promote local dev to origin/dev (fast-forward only)
CANDIDATE_SHA="$(git rev-parse HEAD)"; export CANDIDATE_SHA
```

**Backup-before step (every push that migrates or backfills).** Take it on the host so it
survives the deploy (row I):

```bash
# on the staging host, BEFORE promoting
PG_CTR="$(docker ps --format '{{.Names}}' | grep -i postgres | head -1)"; export PG_CTR
docker exec "$PG_CTR" pg_dump -Fc -U autoerp -d tenant_<tenant-uuid> \
  > /root/backup-<slice>-$(date -u +%Y%m%dT%H%M%SZ).dump
ls -l /root/backup-<slice>-*.dump          # confirm non-zero BEFORE promoting
```

### Push 1 — Preflight commands and censuses (no schema, no behaviour)

Ships read-only census/repair tooling and anything a later migration depends on. Nothing here
may require a column that does not exist yet.

```bash
# after the deploy settles, from the API container (Dokploy console or docker exec)
php artisan tenants:run tenant:census-day-one --option='fail-on-drift=1' 2>&1 | tee /tmp/<slice>-census-p1.log
grep -c 'DRIFT(' /tmp/<slice>-census-p1.log        # row H: grep, never trust $?
php artisan tenants:run pos:census-vat-legs 2>&1 | tee /tmp/<slice>-vatlegs-p1.log
```

- Rollback point: revert the commit; commands are read-only, nothing to undo.
- Exit criterion: the pre-migration census baseline is **captured to a file and attached to the slice plan**.

### Push 2 — Additive schema only

Only additive, self-guarding migrations (`ADD COLUMN NULL`, `CREATE … IF NOT EXISTS`, partial
unique indexes). No destructive DDL over financial history — ever. A migration with a manual
prerequisite must be **self-guarding in code**, because the push runs it immediately (row A).

Verification, per tenant, after the auto-deploy settles:

```bash
docker logs <api-container> 2>&1 | tee /tmp/<slice>-boot-p2.log
grep -n 'Rolling tenant migrations' /tmp/<slice>-boot-p2.log
grep -nE 'FAILED|Done with errors|per-tenant errors' /tmp/<slice>-boot-p2.log   # row C
grep -c '→ ' /tmp/<slice>-boot-p2.log                                          # per-tenant lines
# belt: re-run explicitly and read ITS exit code (not swallowed here)
php artisan tenants:migrate-rolling --force; echo "rolling-exit=$?"
php artisan tenants:migrate-rolling --force --tenant=<tenant-uuid>   # single-tenant re-check
```

Grep literals: success `Done. N tenant(s) migrated, 0 failed.`
(`RollingTenantMigrationCommand.php:163`); failure `Done with errors.` (`:169`);
no-op `Database-per-tenant mode is OFF` (`:58` — see §6 U-2 before concluding the migration landed).

- Rollback point: **forward-only.** An additive column/index stays; a follow-up migration corrects it.
  Never roll a migration back over fiscal/GL history.

### Push 3 — Dormant code (flags default false)

All behaviour ships inert. Declare the flag once:

```php
// apps/api/config/<slice>.php  — same shape as config/treasury.php:28
'<flag>' => (bool) env('<FLAG_ENV>', false),
```

Read it identically in the request path and the queued path (API/worker parity, row F). A new
named queue must be added to `config/horizon.php` `defaults.*.queue` (`:209`) or
`HorizonQueueCoverageTest` fails the build (row G).

Post-deploy assertion that it really is inert, in **both** containers:

```bash
php artisan tinker --execute="echo config('<slice>.<flag>') ? 'ON' : 'OFF';"   # api container   → OFF
php artisan tinker --execute="echo config('<slice>.<flag>') ? 'ON' : 'OFF';"   # worker container → OFF
```

- Rollback point: none needed — the flag is off. Worst case, revert the commit.

### Push 4 — Backfill and verification

Backfills and seeders are **not** part of a deploy (row D) and must be run by hand. Captured-IDs
pattern — run the command, capture stdout, export the variable **before** any consumer uses it:

```bash
php artisan tenants:list 2>&1 | tee /tmp/<slice>-tenants.log
TENANT_IDS="$(grep -oE '[0-9a-f-]{36}' /tmp/<slice>-tenants.log | sort -u | tr '\n' ',')"
export TENANT_IDS
echo "TENANT_IDS=$TENANT_IDS"                # assert non-empty BEFORE using it

php artisan tenants:seed --force --class='Database\Seeders\RolesAndPermissionsSeeder' \
  2>&1 | tee /tmp/<slice>-perms.log
php artisan permission:cache-reset

# censuses — all read-only, all grep-gated (row H)
php artisan tenants:run pos:census-vat-legs 2>&1 | tee /tmp/<slice>-vatlegs-p4.log
php artisan inventory:lot-drift-census --all-tenants --fail-on-drift 2>&1 | tee /tmp/<slice>-lotdrift.log
php artisan inventory:repair-phantom-default-batches --all-tenants --dry-run 2>&1 | tee /tmp/<slice>-phantom.log
php artisan treasury:reconcile --tenant=<tenant-uuid> 2>&1 | tee /tmp/<slice>-treasury.log
php artisan documents:repair-paid-never-posted --dry-run 2>&1 | tee /tmp/<slice>-paidnever.log
php artisan tenants:run tenant:census-day-one --option='fail-on-drift=1' 2>&1 | tee /tmp/<slice>-census-p4.log
grep -c 'DRIFT(' /tmp/<slice>-census-p4.log   # compare against the Push-1 baseline
```

Signatures verified: `pos:census-vat-legs` (`apps/api/app/Console/Commands/PosReceiptVatLegCensusCommand.php:76-79`
— **must** run under `tenants:run`; a bare `--tenant` reports a false "chart not provisioned",
`PROMOTION-CHECKLIST-2026-08-26.md:85`); `inventory:lot-drift-census`
(`LotLedgerDriftCensusCommand.php:52-56`); `inventory:repair-phantom-default-batches`
(`RepairPhantomDefaultBatchesCommand.php:108-113`); `treasury:reconcile`
(`ReconcileTreasuryCommand.php:128-129`); `documents:repair-paid-never-posted`
(`RepairPaidNeverPostedDocumentsCommand.php:85-86`, dry-run is the default);
`tenant:census-day-one` (`DayOneCensusCommand.php:17-21`); `tenant:backup`
(`BackupTenantCommand.php:19-22`).

- Rollback point: forward-only corrective backfill. Every repair command above has a dry-run form
  — run it first and attach its output.

### Push 5 — Activation

```bash
# 1. WORKER first, then API (g3-…-deploy-notes.md:115-119)
#    Set <FLAG_ENV>=true for the worker service/app, redeploy it, then verify:
php artisan tinker --execute="echo config('<slice>.<flag>') ? 'ON' : 'OFF';"   # worker → ON
# 2. Then the API service/app; same read-back → ON
# 3. Cache + queue settle
php artisan config:clear && php artisan config:cache
php artisan permission:cache-reset
php artisan horizon:terminate        # graceful restart onto the new config
php artisan horizon:status           # supervisors running (g3-…-deploy-notes.md:112-114)
# 4. Scheduler container: redeploy so it inherits the same env
# 5. websocket / web: redeploy only if the slice changed them (§3)
```

If the env path is the compose file, adding `<FLAG_ENV>` to `x-api-env`
(`docker-compose.staging.yml:17-57`) is itself a code change and rides in as a sixth, trivial
push. The slice plan must say which path it uses.

- Rollback point: **set `<FLAG_ENV>=false`, redeploy worker then API.** That is the whole rollback.
  No schema is rolled back; corrections are forward-only.
- Known trap: a job enqueued while the flag was true and consumed after it flipped false is audited,
  not silent — `feature_disabled_after_enqueue` (`g3-…-deploy-notes.md:194`).

---

## 3. Web deploy block (only when the slice touches `apps/web`)

```bash
# 0. capture the CURRENTLY served asset hash BEFORE deploying
curl -s https://erp.otospex.dev/ | grep -oE '/assets/index-[A-Za-z0-9_-]+\.js' | sort -u | tee /tmp/<slice>-web-before.txt

# 1. explicit Dokploy deploy — staging web is autoDeploy=false (WORKFLOW.md:206-208)
#    application id: mY6P_PHb4pw-2LdG1Y7Ml   (MCP tool: mcp__dokploy-mcp__application-deploy)

# 2. asset hash MUST differ
curl -s https://erp.otospex.dev/ | grep -oE '/assets/index-[A-Za-z0-9_-]+\.js' | sort -u | tee /tmp/<slice>-web-after.txt
diff /tmp/<slice>-web-before.txt /tmp/<slice>-web-after.txt   # non-empty diff == fresh bundle

# 3. feature fingerprint — grep the SERVED bundle for a string unique to this slice
NEW_ASSET="$(head -1 /tmp/<slice>-web-after.txt)"; export NEW_ASSET
curl -s "https://erp.otospex.dev${NEW_ASSET}" | grep -c '<slice-unique-string>'   # MUST be >= 1
```

Both checks are mandatory — hash alone missed a 78-commit-stale bundle (`WORKFLOW.md:220-227`).

**Build fingerprint (preferred, two lines).** `/build-fingerprint.json` now exists
(`apps/web/tools/write-build-fingerprint.mjs`, wired into `pnpm build` at
`apps/web/package.json:8`, served `no-store` by `apps/web/docker/entrypoint.sh:171-186`):

```bash
curl -s https://erp.otospex.dev/build-fingerprint.json | tee /tmp/<slice>-fingerprint.json

jq -r '.build_sha' /tmp/<slice>-fingerprint.json
# MUST equal the promoted CANDIDATE_SHA. "unknown" == U-9 not closed (Dokploy is not
# passing the BUILD_SHA build arg) — fall back to the asset-hash + grep pair above.

jq -r '.feature_fingerprint' /tmp/<slice>-fingerprint.json
# MUST equal, at CANDIDATE_SHA:
#   node apps/web/tools/write-build-fingerprint.mjs --print-fingerprint
# It is a sha256/16 of the sorted route paths in scripts/factory/manifests/routes-web.yaml,
# so it moves only when the route SET changes — a slice that adds no route legitimately
# leaves it unchanged; `build_sha` is the freshness signal, this is the shape signal.
```

Keep the asset-hash + grep pair as the mandatory fallback while U-9 is open.

**Playwright smoke.** Existing specs: `apps/web/e2e/smoke/{auth,product,settings,treasury-spine,
treasury-phase5a-outbound,treasury-phase5b-reconciliation}.smoke.ts`; runner
`pnpm --dir apps/web test:e2e` (`apps/web/package.json:27`). The onboarding campaign can also be
pointed at staging (§4).

**Private-port local-stack caveat.** Browser legs run against a *local* stack must not reuse
another session's shared `:8010` API / `:5173` vite: stand up the worktree's own API on `:8011`
and vite on `:5174` via an in-tree override config, set `QUEUE_CONNECTION=sync`, and run the
scripted `@playwright/test` harness — the MCP browser profile is a single shared Chrome and is
often locked (memory `reference_local_playwright_scripted_harness`). Playwright `outputDir` must
live outside the vite root or HMR reloads the SPA mid-test.

**Device build.** A POS/Tauri build is macOS-bound and laptop-only (`WORKFLOW.md:38`) — never part
of a staging push. If the slice needs one, list it as a separate item.

---

## 4. Gate checklist (slice plans copy this block verbatim)

- [ ] **Onboarding campaign GREEN** — `scripts/campaign-onboarding.sh` (local) or
      `scripts/campaign-onboarding.sh --web https://erp.otospex.dev --api https://api.erp.otospex.dev --country TN`
      (`docs/qa/ONBOARDING-CAMPAIGN.md:5-22`; flags `scripts/campaign-onboarding.sh:9-29`).
      Promotion reads the **ledger**, not the exit code (`ONBOARDING-CAMPAIGN.md:3`); the target
      must run a worker consuming `imports` + `fiscal-projections` (`:49`); registration is
      throttled and every run leaves a tenant behind (`:50,53`).
- [ ] **Day-one census CLEAN** — `php artisan tenants:run tenant:census-day-one --option='fail-on-drift=1'`,
      every verdict line clean; grep `DAY-ONE CENSUS` / `DRIFT(` because `tenants:run` discards exit
      codes (`docs/handoff/RUNBOOK-day-one-census.md:7-9,25`). CLAUDE.md rule 22.
- [ ] **Promotion-checklist rows** — migrations enumerated, each declared self-guarding
      (`PROMOTION-CHECKLIST-2026-08-26.md:22-67`); non-self-running seeders listed (`:69-74`);
      post-deploy censuses run (`:84-90`); Horizon queue coverage confirmed (`:94`).
- [ ] **Preflight green at host scope** — `PREFLIGHT_TEST_PATHS='…' ./scripts/preflight.sh` on the
      laptop; full suite is VPS/CI only (`WORKFLOW.md:36,147-162`). A `paths` run with **no** paths
      skips PHPUnit and is not a green.
- [ ] **dev-push-guard behaviour understood** — force-push to `dev` denied; a behind/diverged local
      `dev` denied with the exact reconcile command (`.claude/hooks/git-dev-push-guard.sh:73-88`).
      Commit and push are separate Bash calls.
- [ ] **Fast-forward-only promotion** — `git log --oneline dev..origin/dev | wc -l` is `0` before
      promoting; never rewrite shared history (`PROMOTION-CHECKLIST-2026-08-26.md:15-20`; CLAUDE.md rule 21).
- [ ] **Backup taken on the host and verified non-zero** before any migrating/backfilling push (row I).

---

## 5. How a slice plan references this manifest

Put this sentence in the slice plan's deployment section, verbatim:

> Deployment follows `docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md`
> (five-push sequence §2, web block §3, gate checklist §4). This slice supplies only the variables
> below; it does not restate deploy mechanics.

Then supply exactly these per-slice variables:

| Variable | Meaning | Example |
|---|---|---|
| `<slice>` | short slug used in log/backup filenames | `parapharmacy-lot-expiry` |
| **Migrations list** | every file under `apps/api/database/migrations*`, in filename order, each annotated *additive / self-guarding / prerequisite* | `2026_09_08_100000_add_x_to_y.php` — additive, self-guarding |
| **Flags** | `<flag>` config key, `<FLAG_ENV>` env name, config file, default | `<slice>.enabled` / `<SLICE>_ENABLED` / `config/<slice>.php` / `false` |
| **Commands** | new/changed artisan signatures, whether each runs under `tenants:run`, and its stdout grep marker | `slice:backfill-z` · `tenants:run` · marker `SLICE-Z:` |
| **Censuses** | which §2 Push 1/4 censuses are in scope + the pass/fail grep | `tenant:census-day-one`, `inventory:lot-drift-census` |
| **Web changes** | yes/no — if yes, the §3 feature-fingerprint string | yes · `slice-lot-expiry-v1` |
| **Device build** | yes/no (POS/Tauri; laptop-only, never part of a staging push) | no |
| **Queues** | any new `onQueue`/`$queue` name (must be added to `config/horizon.php:209`) | none |
| **Collapsed pushes** | any of the five omitted, and why | Push 4 omitted — no backfill |
| **Env path** | compose `x-api-env` edit vs Dokploy Environment tab (§6 U-1) | Dokploy Environment tab |

---

## 6. UNVERIFIED register

| id | Claim | Why unverified | What would verify it |
|---|---|---|---|
| U-1 **(RESOLVED 2026-09-09: separate Dokploy applications — see `2026-09-09-parapharmacy-staging-topology-U1-resolution.md`)** | Staging deploys from `docker-compose.staging.yml` (compose stack) vs. separate Dokploy applications | The repo holds the compose file, but `WORKFLOW.md:206-208` describes an independent web *application* with its own id and `autoDeploy=false` — application-shaped, not compose-shaped | Dokploy `project-all` / `application-one` on the staging project; read the app type and its Environment tab |
| U-2 | `TENANCY_DB_PER_TENANT=true` is set on staging | **Absent** from `docker-compose.staging.yml`; `config/tenancy_resolver.php:30` defaults to `false`, and with it false the boot `tenants:migrate-rolling` is a documented no-op (`RollingTenantMigrationCommand.php:57-62`) | In the staging API container: `php artisan tinker --execute="var_dump(config('tenancy_resolver.db_per_tenant'));"` |
| U-3 | Staging API Dokploy application id `x5wfthp8-7cVbiUfI6Hq7` | Appears only inside a sibling 2026-09-06 plan file; no runbook or handoff records it | Dokploy `project-all`; then pin it in `docs/factory/WORKFLOW.md` beside the web id |
| ~~U-4~~ | ~~A `/build-fingerprint.json` endpoint carrying `build_sha`~~ | **CLOSED 2026-09-07 — implemented.** Emitter `apps/web/tools/write-build-fingerprint.mjs`, tests `apps/web/tools/__tests__/write-build-fingerprint.test.mjs`, wired into `pnpm build` (`apps/web/package.json:8`), sha passed via `ARG BUILD_SHA` (`apps/web/Dockerfile:63-74` + the manifest COPY at `:53`), served `no-store` at `location = /build-fingerprint.json` (`apps/web/docker/entrypoint.sh:171-186`) | Done — the §3 fingerprint block replaces this row. The remaining risk is whether the sha actually arrives: see U-9 |
| U-5 | Staging host / SSH / DB creds for the host-side `pg_dump` | The runbook SSH block (`deploy-runbook.md:69-87`) is **AX42 production** for the *platform* app; the ERP staging host is recorded only in memory (`157.180.71.252:5434`, creds via Dokploy `postgres-one`) and those ports churn on redeploys | Owner confirms the staging host + `postgres-one` creds; record them in a handoff, not in memory only |
| U-6 | Whether `SYNC_PERMISSIONS_ON_BOOT` is set in the Dokploy environment | Absent from the compose file; on the application-shaped path it could still be set in Dokploy | Read the Dokploy Environment tab, or `php artisan tinker --execute="echo getenv('SYNC_PERMISSIONS_ON_BOOT');"` in the container |
| U-7 | That promoting `origin/dev` triggers the staging API build today | Asserted by `PROMOTION-CHECKLIST-2026-08-26.md:4`, `WORKFLOW.md:217` and memory — all observational; no repo file configures it | Dokploy `application-one` on the API app → `autoDeploy` field |
| U-8 | Nginx `/health` and the `curl`/`grep` asset-hash shape on the live staging web | The healthcheck exists in `apps/web/Dockerfile:107-108`, but the served bundle's exact `index-<hash>.js` markup was not fetched (read-only repo session, no network calls made) | Run the §3 step 0 command once against staging and paste the output into the slice plan |
| U-9 | A `BUILD_SHA` build argument actually reaches the web image build | The Dockerfile declares `ARG BUILD_SHA` (`apps/web/Dockerfile:73-74`) and the builder stage has no `.git` to fall back on, so the sha can ONLY arrive as a build arg. **Which of the two deploy shapes is in use is itself unverified (U-1)**, and each needs a different thing done, so the in-repo half was wired for both: `BUILD_SHA: ${BUILD_SHA:-}` is now listed in the web `args:` map of `docker-compose.staging.yml:274` and `docker-compose.dokploy.yml:261` (compose forwards ONLY enumerated args). The environment/UI half cannot be read from the repo | Deploy once, then `curl -s https://erp.otospex.dev/build-fingerprint.json \| jq -r '.build_sha'` — it MUST be the deployed commit sha, not `"unknown"`. If `"unknown"`: **compose-shaped** deploy → the `args:` entry is present, so export `BUILD_SHA=<deployed sha>` in the deploy environment that runs `docker compose build`; **application-shaped** deploy → add `BUILD_SHA` to the web application's Build Args in Dokploy (application id `mY6P_PHb4pw-2LdG1Y7Ml`). Settle U-1 first if unsure |
