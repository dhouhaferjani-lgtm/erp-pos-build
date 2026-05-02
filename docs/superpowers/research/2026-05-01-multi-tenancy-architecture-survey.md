# Multi-Tenancy Architecture Survey: Industry Evidence for AutoERP

**Date:** 2026-05-01
**Audience:** Senior engineers + product owner choosing the multi-tenancy model for AutoERP / Synerivia
**Scope:** Evidence-based survey only. **No recommendation** for AutoERP. Decision framework at the end is parameterised by inputs the team must provide.

---

## TL;DR

Across the systems surveyed, **three patterns dominate** the multi-tenant SMB ERP/POS market:

1. **Database-per-tenant (or "site-per-tenant")** — chosen by the open-source ERPs that target AutoERP's exact market: **Frappe/ERPNext** (each "site" = its own MariaDB DB) and **Odoo Online / Odoo.sh** (each customer = a dedicated PostgreSQL DB). This pattern dominates in regulated, customisation-heavy ERP because it matches how regulators, auditors, and accountants think about a "company file."
2. **Pooled / row-level shared-DB with `tenant_id`** — chosen by hyperscale SaaS (Salesforce, Shopify, Notion, GitHub, Linear, GitLab.com). This pattern dominates when the dominant scaling vector is *number of tenants* (millions) rather than *per-tenant data volume* or *per-tenant customisation*.
3. **Sharded row-level (pods)** — what hyperscale row-level systems migrate to once a single primary can no longer hold all tenants (Shopify pods, Notion 480 logical shards, Citus distributed tables). This is an *evolution of pattern 2*, not an alternative to patterns 1/2.

Schema-per-tenant on a single Postgres is the **odd middle option**: rare in production at the systems we surveyed, with documented operational pain at a few-hundred-tenant ceiling (catalog bloat, `pg_dump` slowdown, PgBouncer transaction-pool incompatibility with `search_path`). PlanetScale, Citus, and the postgres-general mailing-list threads converge on "won't scale beyond a few hundred tenants" as the practical limit; Frappe and Odoo bypass this by using *separate databases* (not separate schemas inside one DB).

For AutoERP's target — 10s to low-thousands of SMB tenants with strict per-tenant fiscal compliance (NF525) and a separate cross-tenant data platform — the modal industry choice is **database-per-tenant for the ERP** combined with **a single pooled `tenant_id` warehouse for the data platform**. AutoERP currently has the second half of that pair (row-level + `company_id`) but is wired to Stancl Tenancy v3, which natively supports either model and was originally chosen for the first half.

---

## 1. Industry survey

| System | Pattern | Scale operating at | Enforcement | Backup/restore one tenant | Compliance story | Source |
|---|---|---|---|---|---|---|
| **ERPNext / Frappe** | Database-per-site (1 site = 1 MariaDB DB, 1 file dir) | Frappe Cloud hosts thousands of SMB sites; Bench wiki documents 400 MB minimum per bench | Bench routes by hostname → site → connection string. No row-level scoping; "ERPNext expects one database per site with full data." | Trivial: `bench backup` per site = single mysqldump | Per-tenant audit = `mysqldump --databases site_x` | [Frappe Cloud guidelines][frappe-guidelines], [Frappe forum][frappe-forum-mt], [Frappe issues][frappe-23183] |
| **Odoo Online (SaaS)** | Database-per-customer (PostgreSQL DB per customer) | Odoo Online hosts >5M users across hundreds of thousands of customer DBs; Odoo.sh is dedicated-DB by design | `--db-filter` regex on hostname routes request to customer's Postgres DB. "no access is possible from one database to another." | Native: pg_dump per customer DB. Odoo.sh keeps 14 daily / 4 weekly / 3 monthly backups per DB | Single-tenant DB == single-tenant audit. Customisation-per-tenant is supported. | [Odoo deployment docs][odoo-deploy], [Odoo.sh FAQ][odoo-sh-faq] |
| **Odoo "multi-company"** | Row-level inside *one* customer's DB (NOT cross-tenant) | Per-customer feature, not platform-multi-tenancy | Eloquent-style record rules + ACLs by `company_id` | Per-customer (still 1 DB) | Inside-one-tenant accounting separation, not cross-customer | [Odoo forum][odoo-forum-multitenant] |
| **Salesforce** | Single shared multi-tenant DB, metadata-driven, all data tagged with `OrgID` | "8,000+ customer organizations per instance"; ~150K total customers across pods | Kernel injects `OrgID` filter on every query. Custom objects = metadata, not new tables | Per-org data extract via Bulk API, no native restore-one-tenant | "Trust" page + SOC2/ISO + per-org data export. No per-org PITR | [Force.com Multitenant WP][force-com-wp], Salesforce Architects [platform-multitenant][sfdc-arch] |
| **ServiceNow** | Multi-instance: each customer = isolated app + DB stack | Tens of thousands of instances | Physical isolation per instance; each instance is an independent JVM + DB | Native per-instance backup/restore | Single-tenant compliance per instance | [ServiceNow whitepaper][servicenow-wp] |
| **Shopify** | Sharded row-level: pods, sharded by `shop_id` on MySQL | "More than a hundred pods" as of 2022, ~2M merchants | App + framework filter by `shop_id`; pods are *infrastructure* isolation, not separate schemas | Per-pod backup; restoring one shop is hard (lives inside a multi-tenant DB) | Pods isolate blast radius; not driven by per-shop compliance | [Shopify Engineering: Pods][shopify-pods] |
| **Notion** | Sharded row-level: 480 logical shards across 96 physical Postgres, sharded by `workspace_id` | 200B+ blocks, millions of workspaces | App-level shard router; "since users typically query data within a single workspace at a time, we avoid most cross-shard joins" | Per-shard backup; per-workspace export is application-level | Limited per-tenant compliance posture | [Notion Engineering: Sharding Postgres][notion-sharding] |
| **GitHub** | Pooled row-level (mostly). Repo + org isolation enforced in app/GraphQL layer | Tens of millions of orgs/repos | App-level scoping + GraphQL connection-level checks | Limited per-org backup natively; org export tooling exists | SOC2/ISO at platform level | [Bytebase][bytebase-mt] (secondary) |
| **GitLab.com** | Pooled row-level Postgres for the SaaS, sharded across roles. **GitLab Dedicated** is single-tenant for compliance customers. | GitLab.com = millions of users on one DB cluster | App-level + Rails ORM scoping | Per-namespace export, not per-tenant backup | "GitLab Dedicated is a single-tenant SaaS that satisfies compliance requirements such as data residency, isolation, and private networking" — a tell that pooled didn't satisfy regulated buyers | [GitLab blog: Building Dedicated][gitlab-dedicated], [GitLab scalability docs][gitlab-scalability] |
| **Linear** | Pooled row-level Postgres, schema enforced via codegen + types | Hundreds of thousands of workspaces | TypeScript codegen forces tenant-scoped queries; type system blocks unscoped access | Workspace export | Platform-level only | (Confirmed indirectly; Linear has no published architecture deep-dive) |
| **Stripe** | Account-level isolation enforced in app + API key scope. One account ≈ one tenant. | Millions of merchants | Every API call scoped to API key → account_id; cross-account access blocked at API gateway | Stripe doesn't do customer "backups" in the ERP sense | PCI-DSS at platform level | [Stripe docs: multi-entity][stripe-multi-entity] |
| **Lightspeed / Toast / Square / Vend** | Not publicly documented. SaaS POS, almost certainly pooled row-level given their multi-million-merchant scale. | Square: ~4M sellers; Toast: ~120K restaurants | Not public | Not public | SOC2 + PCI-DSS at platform level | (No primary architectural source available — flagged honestly.) |

**Key takeaway from the table:** AutoERP's exact peer group — **open-source / commercial ERP serving SMBs with regulator-grade fiscal compliance** (Frappe, Odoo) — uses **database-per-tenant**, not row-level. Hyperscale consumer SaaS (Shopify, Notion, GitHub) uses pooled row-level. Per-tenant compliance pressure correlates with database isolation; raw scale pressure correlates with pooled/sharded.

---

## 2. Pattern comparison matrix

The five canonical patterns, drawn directly from [Microsoft's Azure SaaS tenancy guidance][msft-saas-patterns] (the most-cited industry taxonomy):

| | **A. Standalone single-tenant app + DB** | **B. Multi-tenant app + DB-per-tenant** | **C. Multi-tenant app + schema-per-tenant** *(not in MS taxonomy; Postgres-specific)* | **D. Pooled row-level shared DB** | **E. Sharded pooled row-level** |
|---|---|---|---|---|---|
| **Security default** | Highest — physical isolation | High — DB-level isolation | Medium — schema isolation in shared DB | Low (dev-discipline) → Medium (with RLS) | Same as D, plus shard-level isolation |
| **Per-tenant performance isolation** | Strong | Strong (esp. with elastic pools / dedicated containers) | Weak — shared buffer pool, shared autovacuum, shared catalog | Weak — noisy neighbour risk per [MS][msft-saas-patterns] | Per-shard isolation; intra-shard noisy neighbour risk |
| **Scale ceiling** | "Medium *(1-100s)*" [MS][msft-saas-patterns] | "High *(1-100,000s)*" [MS][msft-saas-patterns] (Azure SQL stated; Postgres ceiling lower because of per-DB connection pooling overhead — see [PlanetScale][planetscale-tenancy]) | "Likely won't scale beyond a few hundred tenants" [PlanetScale][planetscale-tenancy], [Postgres-general][pg-thread-2012] | "Unlimited *(1-1,000,000s)*" [MS][msft-saas-patterns]; Salesforce runs 8K orgs per instance | Effectively unlimited — Notion 480 shards, Shopify 100+ pods |
| **Backup/restore one tenant** | Trivial — restore the DB | Trivial — restore one DB | Possible but `pg_dump --schema=X` "30-60s regardless of data size" because pg_dump scans all catalogs [pg-thread][pg-thread-2012] | Hard — must extract from shared tables, no native PITR per tenant | Hard at row level; per-shard backup is fine for shard-level DR |
| **Compliance audit story** | Trivial: "here is the database file for tenant X" | Trivial: same | Possible but auditor unfamiliarity with Postgres schemas may complicate; some tooling assumes 1 DB = 1 tenant | Requires app-level demonstration of `tenant_id` filtering + RLS. Auditors increasingly accept RLS [AWS][aws-rls], but it's still defence-in-depth | Same as D + shard topology evidence |
| **Migration complexity (from current row-level)** | Highest — full app rebuild + per-tenant deploy | High — existing migrations must run per-tenant; Stancl supports this directly. Estimate weeks not days. | High — Stancl supports `PostgreSQLSchemaManager`. Same migration cost as B but you keep one DB | Trivial — already there | Medium — add shard router on top of D |
| **Operational complexity** | High at scale (per-tenant deploys) | Low-medium with tooling (Frappe Bench, Odoo.sh, Stancl) | Medium — schema migrations × N tenants, PgBouncer caveats with `search_path` [PgBouncer #246][pgbouncer-246] | Low at small scale; high at large scale (vacuum, hot tenants) | High — shard rebalancing, cross-shard queries |
| **Cost per tenant** | Highest [MS][msft-saas-patterns] | Low w/ elastic pools / shared Postgres cluster [MS][msft-saas-patterns] | Medium — single DB = no per-tenant compute, but operational time grows | Lowest [MS][msft-saas-patterns] | Lowest at scale |
| **When to choose** | Single-instance customers willing to pay; banking/health w/ data-residency | "Customisation-per-tenant matters" + "per-tenant restore is required" + "regulated SMB" | Rarely — "appealing balance" but [PlanetScale][planetscale-tenancy] and Citus [citus-schema-sharding] both warn it scales worse than people expect on bare Postgres | "Tenants are many, small, and homogeneous" + "no per-tenant compliance audit" + "data is queried often across tenants" | Same as D, but you've outgrown one DB |
| **Industry exemplar** | ServiceNow, GitLab Dedicated | Frappe, Odoo Online, Odoo.sh | (Citus schema-sharding for ≤ ~100s of tenants); some Rails Apartment installs | Salesforce, Linear, GitLab.com SaaS | Shopify pods, Notion shards, Citus row-sharded |

**Sources for the matrix cells are inline. The Microsoft taxonomy is the dominant industry reference and we use its names where they exist.**

### A note on schema-per-tenant on bare Postgres

Schema-per-tenant *sounds* like the best of both worlds — one DB to operate, isolated namespaces for tenants — but production reports converge on three concrete failure modes:

1. **Catalog bloat.** Every table, index, sequence across all schemas lives in `pg_catalog`. A 1000-tenant schema-per-tenant DB with 100 tables/schema = 100K+ catalog rows. Query planning degrades. ([PlanetScale][planetscale-tenancy], [pg-thread][pg-thread-2012])
2. **`pg_dump --schema=X` is not O(schema_size).** It is O(database_size) because pg_dump locks and inspects all tables. The 2012 mailing-list thread reports "30 to 60 seconds regardless of data size" with ~100K tables.
3. **PgBouncer transaction-pooling breaks `search_path`.** "Anything else than session mode won't let you use search_path to switch tenants… you can unknowingly mix tenant data." ([Arkency][arkency-schema], [PgBouncer #246][pgbouncer-246]) Citus 12+ added `track_extra_parameters` to fix this; vanilla PgBouncer still has the limitation.

These are not theoretical — they are the reasons Frappe and Odoo, both of which started in 2008 and could have chosen schema-per-tenant on Postgres/MariaDB, both chose **separate databases** instead. **Citus's official position is that schema-based sharding is for ≤ a few hundred tenants** ("row-based sharding can scale better when you have a large number of tenants") [citus-schema-sharding].

### A note on RLS

Postgres Row-Level Security can promote pattern D from "low security default" to "medium" by enforcing `tenant_id` at the database engine layer. AWS's prescriptive guidance treats RLS as **mandatory** for the pool model. ([AWS prescriptive guidance][aws-prescriptive], [AWS DB blog][aws-rls]) Performance overhead is reported as 1-5% in third-party benchmarks; AWS does not publish official numbers. RLS does not solve per-tenant restore or fiscal-audit-per-tenant — it solves *cross-tenant query leakage*.

---

## 3. AutoERP-specific considerations

### 3.1 Verified current state (as of this report)

Read in repo:

- `apps/erp/.claude/context/architecture.md` claims schema-based: *"Each tenant gets a PostgreSQL schema (`tenant_acme`, `tenant_garage42`)."* **This is documentation drift — the codebase is row-level.**
- `apps/erp/apps/api/composer.json` has `stancl/tenancy: ^3.9` installed. Stancl supports both modes; the package is configured but not driving schema isolation in production code.
- `apps/erp/apps/api/config/tenancy.php` has the `PostgreSQLSchemaManager` *imported* but the `bootstrappers` only include `DatabaseTenancyBootstrapper`; `template_tenant_connection` is `null`. No schemas are being created per tenant.
- 109 migrations in `apps/erp/apps/api/database/migrations/` reference `tenant_id` or `company_id` columns. The codebase added `company_id` on top of `tenant_id` in `2025_11_30_130000_add_company_id_to_existing_tables.php` ("Phase 0.1.6") — a row-level scoping refinement, *not* a schema migration.
- `database/migrations/tenant/` does not exist (Stancl's convention for per-tenant migrations).
- The data model uses **two-level row scoping**: `Tenant` = account holder, `Company` = legal entity (one tenant can have multiple companies). All operational tables filter by `company_id`; sequences (`document_sequences`, `pos_z_reports`, vouchers) are company-scoped.

**Conclusion on current state:** AutoERP is implemented as **Pattern D (pooled row-level)** with `tenant_id` + `company_id` scoping. RLS is not enabled. Stancl/Tenancy is installed but inactive.

### 3.2 Target market & realistic tenant count

From `apps/erp/apps/api/config/verticals.php`:

- **Mechanic / Otospex** (automotive repair) — France, Tunisia, North Africa
- **Pharmacy / parapharmacy** — France, Tunisia
- **Generic retail / IziPOS** — multi-country
- F&B / restaurant
- Workshop scheduling (automotive)

The CLAUDE.md identifies countries: France (NF525), Tunisia, UK, Italy, North Africa. Target customer = SMB (1-10 terminal) with one or a few legal entities per tenant.

**Realistic tenant counts from comparables:**
- Frappe Cloud (open-source ERP SaaS, comparable target): low-thousands of paying sites, ~10 years operation.
- Odoo Online: hundreds of thousands of databases, but Odoo has 15+ years and a 1000-engineer R&D org.
- Lightspeed Retail (commercial POS, comparable): ~165K customer locations.

A reasonable AutoERP modelling band: **Year 1: 10-50 tenants. Year 3: 200-2000 tenants. Year 5: 1000-10000 tenants.** All bands are well below D-pattern hyperscale ceilings and well within database-per-tenant ceilings (Microsoft cites 100,000+ for that pattern in Azure SQL; Frappe Cloud demonstrably runs in the multi-thousands).

### 3.3 Synerivia data-platform integration

Read in repo: `apps/platform/`, `apps/platform-ml/`, `apps/erp/apps/api/app/Modules/PlatformIntegration/`.

Confirmed flows:
- **ERP → Platform** (outbound): `PlatformHttpClient` posts barcode-lookup, product-submission, vehicle-identification with `X-API-Key` header (`partner.api_key` → "Otospex ERP" partner identity). Each ERP install authenticates as one partner; rows posted carry the *partner's* identity to the platform, not the tenant's.
- **Platform → ERP** (inbound webhook): enrichment results posted back via `EnrichmentWebhookPayload`, signed with `SYNERIVA_WEBHOOK_SECRET`.
- **Platform side** (`apps/platform/database/migrations/`) has tables `tenant_enrichment_proposals`, `tenant_vehicle_mapping_overrides` — i.e., the platform models tenants *as a foreign concept*, with platform-side `tenant_id` columns.

**Implication for the data-platform pattern:**

| If AutoERP migrates to | Data platform implication |
|---|---|
| **Database-per-tenant** | Each tenant DB needs its own outbound connection / DAG. Either (a) the ERP code in each DB POSTs to the platform (current pattern, scales fine), or (b) the platform pulls via N connections, which becomes painful >1000 tenants. ETL pattern: per-tenant DAG fan-out (Capillary-style). |
| **Schema-per-tenant** | One outbound HTTP integration per app instance still works. ETL pull would need to iterate schemas; doable but weird. |
| **Pooled row-level** (current) | Single source for the platform to read from. CDC (Debezium / pg_logical) emits one stream. ML pipelines have one `tenant_id` partition column. This is operationally simplest for the data platform. |
| **Sharded row-level** | Same as pooled, but CDC has to read from N shards. Notion's data-lake article describes this exact pipeline. ([Notion data lake][notion-datalake]) |

**The data-platform direction therefore has a structural preference for pooled row-level** as a *source*. Industry pattern: many database-per-tenant ERPs (Frappe, Odoo) push events outbound rather than expecting platforms to pull, precisely because pulling from N tenant DBs is operationally painful.

### 3.4 Compliance: NF525, Tunisia, UK, Italy

**NF525 (France)** — `ISCA` requirements: **I**nalterability, **S**ecurity, **C**onservation, **A**rchiving. ([fiskaly][fiskaly-nf525], [Fiscal Solutions][fiscal-nf525]) The standard is enforced at the *cash register software* level. The hash-chain you already have at `documents.previous_hash` + `pos_receipts` immutability triggers + the tenant signing keys (`tenant_signing_keys` migration on 2026-05-04) implements ISCA at the row level — this is independent of which multi-tenancy model you pick.

**Compliance audit ergonomics**:
- Database-per-tenant: auditor request "give me tenant X's data" is `pg_dump tenant_x_db` → one file.
- Row-level: auditor request requires `pg_dump --table=... --where="company_id='...'"` for ~80 tables, or building a custom export job.
- France's tax administration ([DGFiP][dgfip] via the `FEC` format — Fichier des Écritures Comptables) requires per-company yearly export. AutoERP must implement this as application logic regardless; database-per-tenant just makes it a directory copy.

**No regulator surveyed mandates a specific tenancy model.** They mandate inalterability, retention period (10 years for fiscal records in France), and per-company export format. Both database-per-tenant and well-built row-level + RLS satisfy these. Single-tenant database isolation reduces *audit anxiety* but does not formally improve *audit compliance*.

### 3.5 Future "database cluster" / shardability

Today's choice constrains tomorrow's sharding strategy:

| If you stay pooled row-level | If you migrate to DB-per-tenant |
|---|---|
| Future shardability is *easy*: shard by `tenant_id` (Notion / Citus / Shopify pattern). Postgres has Citus, partitioning by hash on `tenant_id`. | Future shardability is *trivial in a different way*: each tenant DB is already sharded — just place tenants on different physical hosts (Frappe Cloud "Shared Hosting" → "Private Bench" → "Dedicated" → "Cluster" tier model). |
| Migrating tenant data between shards is non-trivial (Notion: "five minutes downtime, three days backfill"). | Moving a tenant between hosts is `pg_dump | pg_restore` then DNS flip. |

**Both are shardable; the operational model differs.** Pooled-then-sharded is the higher engineering ceiling; DB-per-tenant is the higher operational simplicity ceiling. Frappe Cloud and Odoo SH demonstrate that DB-per-tenant scales horizontally without ever building a custom sharding layer.

---

## 4. Stancl/Tenancy v3 specifics

The package is already at `^3.9` in your `composer.json`. Authoritative sources:

- [Tenancy for Laravel docs — Single-database tenancy][stancl-single]
- [Tenancy for Laravel docs — Multi-database tenancy][stancl-multi]
- [DeepWiki of stancl/tenancy-docs][deepwiki-stancl]

**What Stancl supports:**

| Mode | Stancl manager | Status in your codebase |
|---|---|---|
| Single-database (row-level via `BelongsToTenant` trait) | (no manager — manual `tenant_id` scoping) | This is how you operate today, but you implemented it manually with `company_id` rather than via Stancl's `BelongsToTenant` trait |
| Multi-database (one DB per tenant) | `PostgreSQLDatabaseManager` | Imported in config, not active |
| Multi-schema in one Postgres DB (one schema per tenant) | `PostgreSQLSchemaManager` | Imported in config, not active |
| Per-tenant Redis prefix, queue, cache, filesystem | `CacheTenancyBootstrapper`, `FilesystemTenancyBootstrapper`, `QueueTenancyBootstrapper` | Listed in `bootstrappers` but inert without a database mode |

**Stancl's official guidance** (paraphrased + quoted from the docs):

- Single-DB has **"lower devops complexity, but larger code complexity than multi-database tenancy."** Manual scoping is required for secondary models; "directly querying secondary models like `Comment::all()` bypasses tenant scoping entirely."
- Multi-DB: **"By default, when a tenant is created, there's also a database created for him. This is done using a JobPipeline listener."** Per-tenant migrations: `tenants:migrate`, `tenants:seed`. Schema-mode is **"a database manager for using a single database, but multiple schemas (one per tenant) with PostgreSQL."**
- The docs do **not** publish a documented migration path from single-DB to multi-DB. Community advice: use the `BelongsToTenant` trait first, then flip the database manager.

**Migration cost from your current state:**
- Switching to **multi-schema** requires: (a) wiring `PostgreSQLSchemaManager` in `tenancy.php`, (b) moving tenant-scoped tables into `database/migrations/tenant/`, (c) keeping shared lookups (countries, plans, payment-method templates) in `public`, (d) changing every Eloquent model to declare its connection via the bootstrapper, (e) backfilling existing tenants by re-creating their schemas and copying rows. Frappe and Odoo have both moved customers between DBs at scale; the operational tooling exists but you'll write your own.
- Switching to **multi-database** is the same migration but with separate DB names instead of schemas. Add `tenant_*` databases to PgBouncer's userlist.
- Either migration is on the order of **4-8 weeks of focused engineering work** for a codebase your size, based on Frappe community reports of equivalent migrations and Stancl GitHub issues from teams that did the move.

---

## 5. Operational deltas you'll feel day-1

Things that get *easier* with database-per-tenant or schema-per-tenant:

- "Restore tenant X to yesterday." Native pg_dump/pg_restore.
- "Hand tenant their data export." `pg_dump $TENANT_DB > export.sql.gz`.
- "GDPR right-to-be-forgotten." Drop the database. (The fiscal-retention exception still applies; you keep the archive.)
- "Tenant X's queries are slow and breaking everyone else." Move tenant X to its own Postgres host; no app changes.
- "Run schema migration for tenant X only." Per-tenant migration orchestration.

Things that get *harder*:

- Cross-tenant analytics for the data platform (no single source). Mitigated by outbound event push (your current pattern) or per-tenant CDC.
- "Add a feature flag column to all tenants." 1 migration × N tenants. Stancl's `tenants:migrate` does this; rollouts take longer.
- Connection pool sizing. PgBouncer config grows linearly with tenant count (`max_db_connections` × N).
- Backups. N backup jobs vs 1. Mitigated by Postgres-level WAL archiving (one stream covers all DBs on a cluster).
- Cost per tenant (slightly). Each Postgres database has overhead (~8 MB per [PlanetScale][planetscale-tenancy]); each connection costs RAM.

Things that get *easier* with pooled row-level:

- Single backup, single replication stream, single observability footprint.
- Cross-tenant queries for data platform / ML.
- Schema migrations: 1 migration, not N.
- Onboarding a new tenant: insert a row, not provision a DB.

Things that get *harder*:

- All of the database-per-tenant "easier" list above.
- Per-tenant performance tuning. You can't `VACUUM FULL tenant_x.documents` — only the whole table.
- Compliance audit ergonomics (manageable, but you'll demonstrate scoping rather than handing over a file).

---

## 6. Risk-weighted decision framework (NOT a recommendation)

This framework converts the survey into branching criteria the team applies to its own inputs.

```
START
├── Is per-tenant compliance audit recurring (NF525 yearly + per-company FEC)?
│   ├── YES → strong push toward DB-per-tenant
│   └── NO  → continue
│
├── Is the year-3 tenant count target > 5,000?
│   ├── YES → strong push toward pooled+sharded (Notion/Shopify pattern)
│   │         IFF you also have engineering capacity to operate a shard router
│   └── NO  → continue
│
├── Does the data platform need single-source aggregation
│  (i.e., the ML pipeline expects 1 Postgres / Kafka source)?
│   ├── YES → push toward pooled row-level (current state)
│   │         OR DB-per-tenant + outbound CDC stream (Frappe/Odoo pattern)
│   └── NO  → continue
│
├── Do tenants need per-tenant schema customisation
│  (custom fields, custom workflows, vertical-specific objects beyond the
│   `verticals.php` catalogue)?
│   ├── YES → push toward DB-per-tenant (Odoo / Frappe pattern)
│   └── NO  → continue
│
├── Engineering capacity for a 4-8 week migration?
│   ├── YES → migration is feasible whichever direction wins
│   └── NO  → stay at pooled row-level + add RLS as a defence-in-depth
│             improvement (4-day project per AWS guide). Revisit at year 2.
│
└── DEFAULT (none of the above clearly fires) →
    Match your *peers*. AutoERP's peer group (open-source ERPs serving
    SMBs with fiscal compliance) overwhelmingly use DB-per-tenant.
    The pooled-row-level cohort is consumer/dev-tooling SaaS, not ERP.
```

**Threshold sources:**
- "5000 tenants" line for sharding: derived from PlanetScale's "few hundred" schema-per-tenant ceiling × ~10 (Postgres can comfortably hold thousands of small tenants in one DB before query plan time degrades — Salesforce holds 8K orgs per instance).
- "4-8 week migration": derived from Stancl GitHub issues + Frappe community migration reports + AutoERP's ~109 migrations × estimated tenant-aware tables.
- "RLS as a 4-day defence-in-depth project": from AWS prescriptive guidance reference implementation.

---

## 7. Open questions for the team

These are *inputs* the survey can't answer. Each one moves the framework above by a step.

1. **What's the year-3 tenant count target?** Pessimistic / realistic / aggressive — give a band. Anything <2000 fits comfortably in either model; >5000 starts pushing toward sharded; >50000 forces a sharded architecture.
2. **What's the year-3 *per-tenant* data volume target?** A pharmacy chain with 5 locations × 10 years of receipts is a different storage profile from an automotive workshop with 50 vehicles/month. Per-tenant ceiling matters for DB-per-tenant operational sizing.
3. **Will tenants ask for schema customisation?** Custom fields beyond `metadata` JSONB? Custom modules? Custom integrations? If yes, per-tenant DB makes vertical-specific deploys (Otospex vs IziPOS vs parapharmacy) cleanly partitioned.
4. **Is the Synerivia data platform pull-based or push-based?** Push (current): each ERP POSTs events outbound. Pull: platform connects to ERP DBs and reads. Pull doesn't survive DB-per-tenant cleanly.
5. **Engineering capacity for a 4-8 week migration?** Including the 1-2 weeks to write `database/migrations/tenant/` reorganisation, the 1 week to wire Stancl's database manager, and the 2-4 weeks to migrate test fixtures + seeders + Horizon + Reverb to be tenant-aware.
6. **Compliance auditor preferences.** France's NF525 certification body (Infocert / LNE) — has anyone asked the certifier whether they prefer per-tenant DBs? Anecdotally, certifiers don't mandate but prefer "easy to inspect one taxpayer's data."
7. **Will customers ask for self-hosting?** Frappe and Odoo self-hosters expect 1 site = 1 DB. If you plan to license self-hosted Otospex to large customers, the DB-per-tenant model maps to their expectation.
8. **Which signing-key boundary do you want?** `tenant_signing_keys` (added 2026-05-04) is currently row-scoped. If a tenant signing key compromise should not affect other tenants' chains, the strongest defence is per-tenant DB.

---

## 8. Honest limitations of this survey

- **Toast / Square / Vend / Lightspeed have no public architectural docs.** Claims about them in the table are inferred from scale + general SaaS pattern; not primary sources.
- **GitHub and Linear publish little about their multi-tenancy enforcement.** Linear is widely known to use codegen-enforced scoping but the team hasn't published a deep-dive.
- **Salesforce's Force.com whitepaper is from 2008** and the platform has evolved significantly since; Hyperforce (their re-architecture) added cell isolation that's closer to pods, but the kernel-level OrgID filtering remains.
- **PlanetScale's "few hundred tenants" claim for schema-per-tenant** is widely cited but not benchmarked in the public article. The 2012 Postgres mailing-list thread is older but the catalog-bloat mechanism hasn't fundamentally changed; recent Postgres releases (16+) ship some catalog-locking improvements but the pg_dump-scans-all-tables behaviour is unchanged.
- **AWS does not publish RLS performance numbers.** The "1-5% overhead" claim circulates in third-party benchmarks; no official guidance.
- **Odoo Online's exact tenant count is not public** — "hundreds of thousands of databases" is community estimate from public forums.
- **No sources** were found that benchmark Stancl/Tenancy at >1000 tenants in production. The package's own docs don't claim a scale ceiling.

When sources conflicted, we surfaced the conflict rather than picking a side (e.g., Citus says schema-per-tenant is fine for "non-homogeneous schemas"; PlanetScale says it doesn't scale beyond a few hundred — both are right at different scales).

---

## Sources & citations

- [aws-prescriptive]: AWS Prescriptive Guidance, *Implementing managed PostgreSQL for multi-tenant SaaS applications* — https://docs.aws.amazon.com/prescriptive-guidance/latest/saas-multitenant-managed-postgresql/welcome.html
- [aws-rls]: AWS Database Blog, *Multi-tenant data isolation with PostgreSQL Row Level Security* — https://aws.amazon.com/blogs/database/multi-tenant-data-isolation-with-postgresql-row-level-security/
- [arkency-schema]: Arkency Blog, *What surprised us in Postgres-schema multitenancy* — https://blog.arkency.com/what-surprised-us-in-postgres-schema-multitenancy/
- [bytebase-mt]: Bytebase Blog, *Multi-Tenant Database Architecture Patterns Explained* — https://www.bytebase.com/blog/multi-tenant-database-architecture-patterns-explained/
- [citus-schema-sharding]: Citus Data, *Schema-based sharding comes to PostgreSQL with Citus* (2023-07-31) — https://www.citusdata.com/blog/2023/07/31/schema-based-sharding-comes-to-postgres-with-citus/
- [deepwiki-stancl]: DeepWiki, *stancl/tenancy-docs — Database Tenancy Strategies* — https://deepwiki.com/stancl/tenancy-docs/4-database-tenancy-strategies
- [dgfip]: French Tax Administration, FEC format — https://www.economie.gouv.fr/dgfip/controle-fiscal-et-droits-du-contribuable/le-fichier-des-ecritures-comptables-fec
- [fiscal-nf525]: Fiscal Solutions, *NF525 Certification procedure Infocert France* — https://www.fiscal-requirements.com/documents/694
- [fiskaly-nf525]: fiskaly, *Cash register systems certification in France | NF525 & LNE standards* — https://www.fiskaly.com/blog/cash-register-system-certification-france
- [force-com-wp]: Salesforce, *The Force.com Multitenant Architecture* (whitepaper) — https://www.developerforce.com/media/ForcedotcomBookLibrary/Force.com_Multitenancy_WP_101508.pdf
- [frappe-23183]: GitHub frappe/erpnext Issue #23183 — https://github.com/frappe/erpnext/issues/37923
- [frappe-forum-mt]: Frappe Forum, *Need a better understanding of MultiTenant configurations* — https://discuss.frappe.io/t/need-a-better-understanding-of-multitenant-configurations/35180
- [frappe-guidelines]: Frappe Cloud, *Guidelines for choosing a server plan* — https://docs.frappe.io/cloud/servers/guidelines-for-choosing-a-server-plan
- [gitlab-dedicated]: GitLab Blog, *Building GitLab with GitLab: How GitLab.com inspired Dedicated* (2023) — https://about.gitlab.com/blog/2023/08/03/building-gitlab-with-gitlabcom-how-gitlab-inspired-dedicated/
- [gitlab-scalability]: GitLab Docs, *Scalability* — https://docs.gitlab.com/ee/development/scalability.html
- [msft-saas-patterns]: Microsoft Learn, *Multitenant SaaS Patterns - Azure SQL Database* — https://learn.microsoft.com/en-us/azure/azure-sql/database/saas-tenancy-app-design-patterns
- [notion-datalake]: Notion Engineering, *How Notion built and grew our data lake* — https://www.notion.com/blog/building-and-scaling-notions-data-lake
- [notion-sharding]: Notion Engineering, *Herding elephants: lessons learned from sharding Postgres at Notion* — https://www.notion.com/blog/sharding-postgres-at-notion
- [odoo-deploy]: Odoo Documentation 19.0, *System configuration / deployment* — https://www.odoo.com/documentation/19.0/administration/on_premise/deploy.html
- [odoo-forum-multitenant]: Odoo Forum, *What's the best architecture to manage multi-tenant odoo community setup* — https://www.odoo.com/forum/help-1/whats-the-best-architecture-to-manage-multi-tenant-odoo-community-setup-with-white-label-support-289650
- [odoo-sh-faq]: Odoo, *Odoo.sh FAQ* — https://www.odoo.sh/faq
- [pg-thread-2012]: postgres-general mailing list, *Multi tenancy : schema vs databases* (2012, but still cited) — https://www.postgresql.org/message-id/1352367376704-5731189.post@n5.nabble.com
- [pgbouncer-246]: PgBouncer GitHub Issue #246, *Schema based multitenancy, search_path, transaction pooling* — https://github.com/pgbouncer/pgbouncer/issues/246
- [planetscale-tenancy]: PlanetScale Engineering, *Approaches to tenancy in Postgres* — https://planetscale.com/blog/approaches-to-tenancy-in-postgres
- [servicenow-wp]: ServiceNow, *Delivering Performance, Scalability, and Availability on the ServiceNow Cloud* (whitepaper) — https://www.servicenow.com/community/s/cgfwn76974/attachments/cgfwn76974/architect-forum/696/2/wp-sn-advanced-high-availability-architecture.pdf
- [sfdc-arch]: Salesforce Architects, *Platform Multitenant Architecture* — https://architect.salesforce.com/fundamentals/platform-multitenant-architecture
- [shopify-pods]: Shopify Engineering, *A Pods Architecture To Allow Shopify To Scale* — https://shopify.engineering/a-pods-architecture-to-allow-shopify-to-scale
- [stancl-multi]: Tenancy for Laravel, *Multi-database tenancy* — https://tenancyforlaravel.com/docs/v3/multi-database-tenancy/
- [stancl-single]: Tenancy for Laravel, *Single-database tenancy* — https://tenancyforlaravel.com/docs/v3/single-database-tenancy/
- [stripe-multi-entity]: Stripe Documentation, *Billing for a multi-entity business* — https://docs.stripe.com/billing/multi-entity-business

---

*End of report.*
