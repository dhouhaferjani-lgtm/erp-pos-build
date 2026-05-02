# DB-per-Tenant Migration Mechanics

> **Status:** technical reference. Companion to
> `2026-05-01-multi-tenancy-architecture-survey.md`. This document is
> mechanics, not strategy — it explains *how* a migration to Stancl multi-
> database mode would work concretely against the AutoERP codebase, and
> evaluates the "clone the codebase" alternative.

**Date:** 2026-05-01
**Audience:** technical lead + senior engineers planning the migration

---

## Three concrete migration paths

The team has three real options for moving away from pooled row-level. Each
preserves the work landed in sweep B.

### Path 1 — Stancl multi-database mode (recommended technical path)

One Laravel codebase, one set of code, one deployment. Stancl's
`PostgreSQLDatabaseManager` provisions a new PostgreSQL database on tenant
signup, points the connection at it for the duration of the request, runs
migrations per-tenant. The Synerivia data platform integration (outbound
HTTP push) keeps working unchanged because each tenant install POSTs from
its own connection.

Same code, different database per request.

### Path 2 — "Clone the codebase entirely" (full single-tenant deployments)

Each tenant gets a complete dedicated deployment: their own Laravel app
process, their own PostgreSQL instance, their own Redis, their own backups.
This is the ServiceNow / GitLab Dedicated model. Operationally heavy at
scale, but trivial isolation.

Possible at AutoERP's scale only as an enterprise tier (1-10 customers
willing to pay for full dedicated infrastructure). Not the default.

### Path 3 — Hybrid (Stancl multi-DB for SaaS, Path 2 available as enterprise tier)

The pragmatic shape. SaaS tier uses Path 1 (multi-DB on a shared cluster).
A specific enterprise customer who wants their own deployment gets Path 2.
Both run the same Laravel codebase; only the deployment topology differs.

This is what Frappe does (Frappe Cloud "Shared Hosting" tier vs "Dedicated
Cluster" tier — same Frappe code, different infrastructure).

---

## Path 1 — Stancl multi-DB migration: what actually has to change

### What's already in place (verified in repo)

- `composer.json:29` has `stancl/tenancy: ^3.9`.
- `config/tenancy.php` imports `Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager`.
- A `Tenant` model exists at `app/Modules/Tenant/Domain/Tenant.php`.
- `tenants` and `domains` tables are in `database/migrations/` (central tables).
- The codebase splits "central" (single shared) from "tenant" (per-tenant) conceptually — countries, currencies, tax rate templates are in `public`; payment_methods, partners, products are tenant resources (just sharing the same DB today).

### What has to change

#### 1. Activate the database manager in config

`config/tenancy.php`:

```php
'database' => [
    'central_connection' => env('DB_CONNECTION', 'central'),
    'template_tenant_connection' => env('TENANCY_TENANT_CONNECTION', 'tenant'),
    'prefix' => env('TENANCY_DB_PREFIX', 'tenant_'),
    'suffix' => env('TENANCY_DB_SUFFIX', ''),
    'managers' => [
        'pgsql' => Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager::class,
    ],
],
'bootstrappers' => [
    Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper::class,
    Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper::class,
    Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper::class,
    Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper::class,
    // RedisTenancyBootstrapper if you keep tenant Redis isolated.
],
```

#### 2. Set up the `central` and `tenant` connections in `config/database.php`

```php
'connections' => [
    'central' => [
        'driver' => 'pgsql',
        // ... shared-cluster config; this is the existing connection ...
    ],
    'tenant' => [
        'driver' => 'pgsql',
        // ... template; Stancl rewrites the database name per request ...
    ],
],
```

The `central` connection holds: `tenants`, `domains`, `countries`,
`currencies`, `subscription_plans`, etc. The `tenant` connection is the
template Stancl uses to reconstruct the per-tenant connection at runtime.

#### 3. Split migrations into central vs tenant directories

```
apps/api/database/migrations/        (existing — becomes "central")
apps/api/database/migrations/tenant/ (new — per-tenant)
```

Decision rule: a table is **central** if it is shared across all tenants
(tenants registry, countries, currencies, subscription billing). A table
is **tenant** if it is tenant-scoped (companies, payment_methods, partners,
products, documents, pos_receipts, etc.).

Most of the 109 existing migrations are tenant-scoped. The mechanical work
is moving them, dropping the `tenant_id` column on each one, and
re-running them per-tenant.

Two strategies:

**Strategy A (clean cut, less migration code):** Generate fresh tenant-side
schema by squashing existing migrations into a single
`database/migrations/tenant/0001_create_tenant_schema.php` that creates
all tenant tables. Then run `tenants:migrate --fresh` against new tenant
DBs. Existing tenants' data is migrated separately via a one-shot script.

**Strategy B (preserve migration history):** Move every existing migration
file into `tenant/`, edit each to remove `tenant_id` (since the schema is
now the tenant), keep timestamps, run `tenants:migrate` to reconstruct
each tenant's history. More verbose but preserves the audit trail.

For a codebase with no production customers yet (per the user), **Strategy
A is the right call** — squashing 109 migrations into a clean baseline is
massively cheaper than editing 109 files.

#### 4. Add `BelongsToTenant` (or a lightweight replacement) to multi-tenant models

Stancl's `BelongsToTenant` trait adds the `tenant_id` global scope and
auto-fills `tenant_id` on creation. Under multi-DB, `tenant_id` is no
longer needed for isolation (the schema does that), but it's useful as
metadata for fiscal events and audit logs.

Decision: keep `tenant_id` columns for the audit/event use case but DROP
the `where('tenant_id', ...)` scoping that sweep B added — the
`BelongsToTenant` trait + the per-tenant connection makes them redundant.

The architecture test from sweep B Task 2 evolves: instead of catching
unscoped `exists:`, it now catches accidental queries that target the
`central` connection for a tenant table.

#### 5. Tenant resolution middleware

Currently the codebase identifies tenant via the User model's
`tenant_id`. Under multi-DB, tenant resolution happens BEFORE auth — the
hostname or a path segment routes the request to the right tenant DB,
THEN auth runs against that DB's `users` table.

Three resolution strategies, pick one:

- **Subdomain-based:** `acme.app.synerivia.com` → tenant `acme`. Stancl's
  `InitializeTenancyByDomain` middleware. Clean, fits multi-app strategy.
- **Path-based:** `app.synerivia.com/t/acme/...`. Stancl's
  `InitializeTenancyByPath`. Useful if you can't control DNS / wildcard
  certs.
- **Header-based:** `X-Tenant: acme`. Stancl's
  `InitializeTenancyByRequestData`. Useful for the desktop sync API
  specifically — Tauri can include the tenant in every request.

The Tauri desktop client sets `X-Tenant` (or includes a JWT with the
tenant claim). The web app uses subdomain resolution. Mix is fine.

#### 6. Tenant provisioning pipeline

Stancl's `JobPipeline` runs on `TenantCreated` events. The default
pipeline:

```php
JobPipeline::make([
    Jobs\CreateDatabase::class,        // CREATE DATABASE tenant_acme
    Jobs\MigrateDatabase::class,       // tenants:migrate for new tenant
    Jobs\SeedDatabase::class,          // tenant-specific seeders (chart of accounts, default tax rates)
    Jobs\ProvisionPgBouncerEntry::class, // custom job — add to PgBouncer userlist
])->send(fn (TenantCreated $event) => $event->tenant)->shouldBeQueued();
```

This is the load-bearing pipeline. Test against a tenant-creation
end-to-end test before launch.

#### 7. PgBouncer per-tenant pooling

This is the operational gotcha. Each tenant DB needs its own pool entry
in `pgbouncer.ini`:

```ini
[databases]
tenant_acme = host=postgres dbname=tenant_acme pool_size=10
tenant_garage42 = host=postgres dbname=tenant_garage42 pool_size=10
; ... per tenant
```

Two paths:

- **Auto-discovery:** PgBouncer's `auth_query` + `auth_file` to discover
  tenants dynamically. Requires PgBouncer 1.16+.
- **Provisioning job:** the `ProvisionPgBouncerEntry` job above writes a
  templated `pgbouncer.ini` and `SIGHUP`s PgBouncer.

Frappe and Odoo solve this differently — Frappe Cloud uses dynamic
config; Odoo SH provisions a new PgBouncer per dedicated DB. For
AutoERP at the modeled scale (200-2000 tenants by year 3), dynamic
PgBouncer config is sufficient.

Connection pool sizing math: if each tenant gets 10 connections × 2000
tenants × 1 PgBouncer = 20,000 backend connections. Postgres
`max_connections` realistically tops out at ~500-1000 per host.
**Therefore at year 3 scale you need either pool size 1-2 per tenant
(viable if traffic is light per tenant) or shard tenants across
Postgres hosts.**

#### 8. Per-tenant migrations

`php artisan tenants:migrate` runs `database/migrations/tenant/`
migrations against every tenant DB. Add to deploy pipeline:

```bash
php artisan migrate                 # central tables
php artisan tenants:migrate         # per-tenant tables
```

Tenant migrations are slow at scale. A new column on a tenant table is
1 migration × N tenants. Mitigation: `--queue` flag runs migrations
asynchronously per tenant, with a dashboard tracking progress.

#### 9. Test suite refactor

`RefreshDatabase` doesn't work with multi-DB. Stancl provides
`Tests\TestCase` with `Tenancy\Testing\TenancyTestKit` that initializes
a test tenant, runs tenant migrations, and cleans up.

Every existing feature test that relies on `RefreshDatabase` must be
updated to use the Stancl test kit. Estimated ~1 week of mechanical
work for the existing ~1100 backend tests.

#### 10. Cross-tenant queries (admin tools, fiscal verify, data platform)

Some flows legitimately need to query across tenants:

- Admin dashboard listing all tenants' subscription status.
- Fiscal `verify-chains` console command running per-terminal across
  all tenants for compliance audit.
- Data platform CDC stream (if pull-based) — but you're push-based
  today, so this isn't an issue.

Stancl's API:

```php
Tenant::all()->each(function (Tenant $tenant) {
    $tenant->run(function () use ($tenant) {
        // Inside this closure, tenant context is the given tenant.
        $receipts = Receipt::query()->count();
        // ...
    });
});
```

This is the inverse of `withoutGlobalScope` — explicit opt-in to
cross-tenant operation, one tenant at a time. Cleaner audit story than
"this query bypasses scoping."

### Migration timeline

| Phase | Duration | Deliverable |
|---|---|---|
| Setup: configure connections, bootstrappers, tenant resolution | 3-5 days | Stancl wired, tenant creation provisions a fresh DB |
| Migration split: move tenant migrations to `tenant/`, squash to baseline | 5-7 days | Strategy A baseline migration runs cleanly on a fresh tenant DB |
| Test suite refactor: update ~1100 tests to use Stancl test kit | 5-7 days | Full suite passes against multi-DB |
| Operational: PgBouncer auto-config, backup runbook, monitoring | 3-5 days | Production-ready provisioning pipeline |
| Cross-tenant code paths: admin tools + fiscal verify-chains | 2-3 days | Cross-tenant flows use `$tenant->run(fn () => ...)` |
| Data migration (existing Tunisian customers): per-tenant export → fresh DB → import | 2-3 days | Existing customers migrated; rollback verified |
| Soak on staging: real traffic patterns, connection pool sizing | 5-10 days | Confidence to cut over |
| **Total focused work** | **4-6 weeks** | |

This is consistent with the 4-8 week range in the survey.

### Cost model

- **Storage:** N tenant DBs × ~8 MB Postgres overhead each. Negligible
  until tenant count reaches thousands.
- **Connections:** as above — pool size × tenant count × Postgres host
  capacity. The actual operational ceiling.
- **Backup:** N pg_dumps. Use Postgres-level WAL archiving for
  cluster-wide backups; per-tenant pg_dump for tenant-export use cases.
- **Monitoring:** N tenant DBs need to be in your dashboards. Datadog /
  Grafana have per-database breakdowns.

---

## Path 2 — "Clone the codebase entirely"

The user asked specifically about this option. Mechanics:

### Two interpretations

**2a — Clone repo per tenant.** Literally `git clone` for each tenant,
deploy as a separate Laravel app, separate Docker container, separate
Postgres, separate Redis. Each tenant has their own everything.

**2b — Single repo, single Docker image, but per-tenant deployment
infrastructure.** One codebase (you don't fork), but your deployment
tooling (Dokploy, k8s) provisions a fresh app + DB + Redis stack per
tenant. Same image, N runtimes.

### Difference in practice

Both are "single-tenant deployments at scale." 2b is what ServiceNow and
GitLab Dedicated do. 2a is what self-hosted Odoo customers do
(install-per-customer).

### Operational reality

For 1-10 dedicated customers (enterprise tier), 2b is feasible. Dokploy
provisions a tenant stack on signup; tenant signs up to a custom
hostname; backups run independently per tenant.

For 100+ customers, 2b becomes a scheduling problem. You're now
operating N Postgres instances, N Redis instances, N PHP-FPM pools.
Costs scale linearly with tenant count. Not viable as the default tier.

Frappe Cloud's tier model:
- "Shared Bench" — multiple tenants on one Bench (Stancl multi-DB
  equivalent). Default.
- "Private Bench" — dedicated Bench per customer (Path 2b). Enterprise
  upcharge.
- "Dedicated Cluster" — full infrastructure isolation (Path 2a-ish).
  Major enterprise.

This is the right shape: **default to Path 1, offer Path 2b as
enterprise.**

### Migration cost from current state to Path 2 (default)

If you wanted Path 2 as the default tier:
- Same code changes as Path 1 (you still want Stancl `BelongsToTenant`
  for the same reasons).
- PLUS: per-tenant deployment automation. Estimate 2-3 weeks ON TOP OF
  Path 1's 4-6 weeks.
- PLUS: ongoing operational cost. N stacks vs 1.

**Net: Path 2 alone is a worse default than Path 1 by every measure for
SaaS-tier customers.** Reserve it for enterprise.

---

## Path 3 — Hybrid (Stancl multi-DB SaaS + Path 2 enterprise tier)

The recommended technical shape. Same Laravel codebase. Different
deployment topology per tier.

```
SaaS tier (default):
  - Path 1: Stancl multi-DB on shared cluster.
  - Tenants share Postgres host, separate databases.
  - Operational scale: hundreds to low-thousands of tenants per cluster.

Enterprise tier (premium upcharge):
  - Path 2b: dedicated Postgres + Redis + app instance.
  - One tenant per stack.
  - Operational scale: 1 customer per stack.
  - Same Docker image; different docker-compose / k8s manifest.
```

Migration cost: Path 1's 4-6 weeks. Path 2b is added at the deployment
infrastructure layer when the first enterprise customer signs.

---

## What survives sweep B regardless of path?

| Sweep B artefact | Survives Path 1? | Survives Path 2? |
|---|---|---|
| `ScopedExists` helper | Yes — Stancl's `BelongsToTenant` global scope is defence-in-depth, the helper layers on it for explicit-scope discipline. | Yes — same reason. |
| Architecture test (Presentation `exists:` gate) | Yes — rule evolves to "tenant tables must use the tenant connection," same mechanism. | Yes — same. |
| Architecture test (service `find()` gate) | Yes — same logic, different rule. | Yes. |
| Convention doc (`08-TENANT-ISOLATION.md`) | Yes — discipline survives, examples updated. | Yes. |
| Per-cluster regression tests (Treasury, Document, Inventory, etc.) | Yes — feature tests don't care about the isolation mechanism, they assert behaviour. | Yes. |
| Corrected `architecture.md` / `CLAUDE.md` | Yes — needs another edit when the migration lands, but the corrected baseline is the foundation. | Yes. |

**None of sweep B is wasted.** The migration to Path 1 (or Path 3) is
strictly additive on top of the explicit-scope discipline. The migration
window itself REQUIRES the discipline as defence-in-depth.

---

## Decision shape (for the team to confirm)

The team's strategic call (from earlier discussions):
- Year 3 target: 200-2000 tenants.
- Compliance pressure: Tunisia first (low), France/NF525 later (high).
- Per-tenant data: SMB POS + ERP, moderate per-tenant volume.
- Engineering capacity: available, no production customer.
- Synerivia integration: outbound push (works for any topology).

**These inputs map cleanly to Path 3.** Stancl multi-DB as the SaaS
default for the modeled scale, with the option to spin up dedicated
deployments for enterprise customers when they ask. Migration in 4-6
weeks of focused work, on top of sweep B which lands in 1-2 weeks.

The "clone the codebase" framing the user raised is the enterprise tier
of Path 3, not the default. Confirming that interpretation is the next
question.

---

## Open questions for the team to confirm before starting Path 1

1. **Subdomain or path-based tenant resolution?** Subdomain
   (`acme.app.synerivia.com`) requires wildcard DNS + wildcard cert and
   is cleanest. Path-based (`app.synerivia.com/t/acme`) avoids that
   infrastructure but is uglier. Tauri desktop sync uses
   `X-Tenant` header regardless.
2. **Strategy A or B for migrations?** Squash to baseline (A — recommended,
   no production customer to preserve audit trail) or preserve full
   history (B).
3. **PgBouncer auto-discovery or provisioning job?** Auto-discovery is
   slicker; provisioning jobs are explicit. Pick based on ops team
   preference.
4. **When does the migration start?** After sweep B lands (recommended)
   or in parallel? In parallel risks merge conflicts on every test file
   touched.
5. **Existing Tunisian customers — how many, what's their data
   volume?** Affects the data-migration runbook in week 5.
6. **Do you want enterprise-tier (Path 2b) infrastructure ready at
   launch?** If yes, that's an additional 2-3 weeks AFTER Path 1.
   Recommended to add later when the first customer asks.

Answering these unblocks the migration plan.
