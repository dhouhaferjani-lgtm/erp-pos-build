# Staging topology — manifest U-1 RESOLVED (2026-09-09, read-only Dokploy API inspection)

Resolves `docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md` §0 / §6 U-1. Source: Dokploy `project.all` + `application.one` (read-only) on 2026-09-09 by the W-LOT orchestrator session. No environment VALUES are reproduced here — only identifiers, mechanisms and variable NAMES.

## Verdict

**`separate_applications`.** ERP staging is NOT deployed from `docker-compose.staging.yml`. The Dokploy project **New ERP** (`a_quW5mtmNArxuyZO6adQ`), environment **Staging** (`Ree8z_4ixu7vAgBLdI_A_`) holds **nine Dokploy applications + one PostgreSQL service and zero compose services**. The compose branch of every slice plan collapses; a "Push 6" compose edit never exists.

## Applications (environment `Ree8z_4ixu7vAgBLdI_A_`, server `Tz-Y6I79uol1P-N87L1cE` = "Development" Hetzner VPS `157.180.71.252`, ssh user `root`)

| Role | applicationId | appName (container / service name on the host) | Build | Deploy trigger |
|---|---|---|---|---|
| API | `x5wfthp8-7cVbiUfI6Hq7` | `erp-staging-api-kghqex` | `apps/api/Dockerfile`, context `./apps/api`, stage `api` | github `otospexsolutions/erp` branch `dev`, `autoDeploy=true` |
| Worker (Horizon) | `KKYDsAvk4UpYfJXVmsDj2` | `erp-staging-worker-p2yjl1` | same Dockerfile, stage `worker` | `autoDeploy=true` |
| Scheduler | `HSXqHvmo_vq7NAYIjrE3T` | `erp-staging-scheduler-iqjxrv` | same Dockerfile, stage `scheduler` | `autoDeploy=true` |
| WebSocket (Reverb) | `Qneza6LP0JDf-sPNX2uGx` | `erp-staging-websocket-3f40op` | — | — |
| Web | `mY6P_PHb4pw-2LdG1Y7Ml` | `erp-staging-web-dqepfa` | `apps/web/Dockerfile`, context `.` | `autoDeploy=true` (`docs/factory/WORKFLOW.md:206-208` saying `autoDeploy=false` is STALE) |
| PostgreSQL | postgres `1CRiRxlMFCMynVj1CMhMs` | `erp-staging-postgres-8x7pbx` | Dokploy database service | — |
| Redis / Meilisearch / MinIO / PgBouncer | `bNsVBVlZuDLwnrkbVg-oV` / `UHg_48ya1gI42jNU5Nopb` / `qgXyh4hlPXy00Nm4lgnoE` / `8iADcEgnBcmajG-10ek7W` | `erp-staging-redis-ugrwwu` / `erp-staging-meilisearch-u6fiyz` / `erp-staging-minio-i14onc` / — | — | — |

Domains: API `api.erp.otospex.dev`, web `erp.otospex.dev`. Deployment logs live on the host under `/etc/dokploy/logs/<appName>/`.

## How an env var reaches a container (manifest §1 row E, resolved)

- Each application carries its own `env` field (Dokploy Environment tab) with `createEnvFile=true`; there is **no shared environment-level env** (the environment's `env` is empty) and no compose interpolation. `command`/`args` are unset on API/worker/scheduler — the image entrypoint (`apps/api/docker/entrypoint.sh`) selects behaviour from `CONTAINER_ROLE` (`api` / `worker` / `scheduler`, one per application).
- A new variable must be added to **each** of the three Laravel applications separately (API, worker, scheduler), then each application **redeployed** — a value edited in Dokploy does not reach a running container until its next deploy.
- Executable paths: Dokploy tRPC `application.update` with the full `env` text (the `application.saveEnvironment` MCP wrapper returns 400 — `claude/deploy-runbook.md:38-40`), then `application.redeploy` / `application.deploy`; readback = `application.one` `env` field plus, on the host, `docker ps --filter name=<appName> --format '{{.ID}}'` → `docker exec <id> printenv <VAR>`. The Dokploy API key location is documented in `claude/deploy-runbook.md:30`.
- Every `origin/dev` fast-forward rebuilds and restarts **all four** app containers (API, worker, scheduler, web). Container-local files do not survive; host files under `/root` on `157.180.71.252` do.

## Facts that change the slice plans

1. **`SYNC_PERMISSIONS_ON_BOOT=true` is set on the staging API application** (not on worker/scheduler). Per `apps/api/docker/entrypoint.sh:157-165` this runs `tenants:seed --class=RolesAndPermissionsSeeder` at **every API boot**, i.e. on every push. Any gate phrased "prove boot permission synchronization is off" is false on staging today; slice plans must instead prove the seeder is inert for existing tenants while the enforcement flag is off, and treat every push as a seeder run.
2. `AUTO_SEED=false` on all three; `permission:cache-reset` runs at every boot (`entrypoint.sh:176`).
3. Worker and scheduler share the API image; they receive the enforcement flag only if it is added to their own application env.
