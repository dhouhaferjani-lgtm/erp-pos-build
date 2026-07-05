# Multi-DB Topology Architecture — Path 3 (DB-per-tenant on shared cluster + dedicated tier)

**Status**: Draft v4 (v1, v2, v3 superseded — see review chain in `docs/superpowers/reviews/`)
**Date**: 2026-05-10
**Purpose**: Architectural design feeding the **2026-07-15 topology decision** under [`master plan §23`](../plans/2026-05-02-tenant-isolation-master-plan.md).
**Scope**: Designs the *destination architecture* — placement profiles, control-plane catalog, connection resolver, cross-cutting bootstrappers, migration mechanics (one-time public-schema extraction + ongoing DB-to-DB promotion via logical replication), cutover atomicity protocol, PgBouncer topology, migration fan-out contract.

**What this doc is and isn't**: this is the **topology decision design**, not the execution contract. Mechanism choices that gate the topology decision are in the spec body. Operational thresholds, capacity envelopes, taxonomies, and inventories that gate *execution* are listed as acceptance artifacts (§11) — they don't have to be complete to lock the topology choice, but they must be complete to start cutover.

---

## 0. What this doc owns vs. what it doesn't, and what changed in v4

| Concern | Owned here | Owned elsewhere |
|---|---|---|
| Topology recommendation for 2026-07-15 decision | ✅ | — |
| Catalog schema, resolver state matrix, cutover atomicity protocol | ✅ | — |
| Placement profiles (shared / regional / dedicated) | ✅ | — |
| Bootstrappers for cache, filesystem, queue, search, Redis | ✅ | — |
| PgBouncer topology + capacity assumptions (envelope deferred to A5 artifact) | ✅ | — |
| Migration fan-out contract | ✅ | — |
| Initial public-schema → tenant-DB extraction (one-time) — mechanism | ✅ §5.A | — |
| Ongoing DB-to-DB promotion / demotion — mechanism | ✅ §5.B | — |
| **Rollback primitive** (target-side slot at cutover) | ✅ §5.B.5 | — |
| **Cutover atomicity and cache-fencing protocol** | ✅ §5.D | — |
| Cross-tenant operations disposition pattern | ✅ | — |
| Per-tenant operational thresholds (hypertable size, write rate, FK taxonomy) | acceptance artifacts §11 | execution phase |
| PgBouncer memory envelope (RAM, shared_buffers, work_mem, Horizon worker count, p95 tx duration) | acceptance artifact A5 | execution phase staging measurement |
| Migration corpus baseline | acceptance artifact A6 | CI baseline |
| Tactical app-level scoping sweep | — | [Master plan §§1–17](../plans/2026-05-02-tenant-isolation-master-plan.md) |
| POS-only RLS pilot, composite FK guardrails | — | [Master plan §§19–20](../plans/2026-05-02-tenant-isolation-master-plan.md), [Foundation plan Phases 2–3](../plans/2026-05-02-tenant-isolation-nf525-database-foundation-plan.md) |
| Industry survey / pattern comparison | — | [Architecture survey](../research/2026-05-01-multi-tenancy-architecture-survey.md) |
| Cutover scheduling (rolling, ≤30-min window, Tunisia-first) | — | [Master plan §23 step 6](../plans/2026-05-02-tenant-isolation-master-plan.md) |
| NF525 evidence pack | — | [Master plan §21](../plans/2026-05-02-tenant-isolation-master-plan.md) |
| French e-invoicing | — | [Master plan §22](../plans/2026-05-02-tenant-isolation-master-plan.md) |

### What changed since v3

The Codex round-3 review (`docs/superpowers/reviews/2026-05-10-multi-db-topology-codex-round3-review.md`) returned `REQUIRES-DIFFERENT-APPROACH` with 2 new BLOCKERs + 6 MAJORs + 1 MINOR + 1 NIT. v4 addresses them as follows:

| v3 problem (per round-3 review) | v4 resolution |
|---|---|
| BLOCKER — rollback replay is unsound: a newly-created reverse subscription doesn't capture writes already made on target | §5.B.5 reframes rollback around a **target-side logical replication slot created at cutover**. Slot accumulates WAL changes on target throughout the 7-day rollback window. Rollback consumes that slot via a source-side subscription; loss-only fallback if slot fails or disk threshold breaches |
| BLOCKER — extraction freeze relied on all-table RLS, but Foundation Plan Phase 3 only pilots POS RLS | §5.A.4 replaces RLS freeze with **read-only-connection mechanism**: when `tenants.status='migrating'`, the resolver issues all connections with `default_transaction_read_only=on`. Covers app + workers + scheduled jobs uniformly; operator maintenance is a separate pause-during-cutover policy |
| MAJOR — G6 REPLICA IDENTITY check too weak (DEFAULT is catalog default; partial unique indexes can't serve; FULL inefficient on large rows) | G6 strengthened to introspect `pg_class.relreplident`, `pg_index.indisprimary`/`indisreplident`, partial predicates (`indpred`), expression indexes (`indexprs`), nullable identity columns, row width. Require PK or `REPLICA IDENTITY USING INDEX` with non-partial, non-expression, non-nullable index. `FULL` only by explicit exception |
| MAJOR — cutover ordering and cache fencing under-specified | New **§5.D Cutover atomicity and cache fencing protocol** with explicit ordering: monotonic catalog version, cache invalidation barriers, sequence copy timing, single-transaction catalog flip. New gate G10 (cutover ordering test fixture) |
| MAJOR — TimescaleDB hypertable copy at cutover can violate ≤30-min | §5.B.3 adds hypertable size threshold (`HYPERTABLE_THRESHOLD=1GB` default per tenant). Above threshold → extended-window operator approval per master plan §23. New acceptance artifact A10 (per-tenant hypertable size inventory) |
| MAJOR — PgBouncer math optimistic without memory model | §8 explicitly frames Y1=100 as a **hypothesis pending A5 envelope measurement**. A5 acceptance artifact requires: target node RAM, shared_buffers, work_mem, Horizon worker count, p95 tx duration, reserve_pool_timeout, max_user_connections, noisy-tenant behavior. 750-conn split-trigger is engineering threshold to prove |
| MAJOR — migration linter trusts comments + grandfather plan vague | §7.3 reframes: **AST classifier dominates comments**. Lint rejects `// @migration-phase: additive` comment if classifier sees mutative operations. A6 acceptance artifact: migration corpus baseline (filename × detected ops × human disposition × expiry) |
| MAJOR — FK closure for shared rows hand-wavy | §5.A.3 names ownership taxonomy as a prerequisite. New acceptance artifact A9 (FK ownership taxonomy: tenant-owned / company-owned / global-immutable-reference / global-mutable-reference / shared-with-tenant-overrides; per-FK copy/seed/reject decision) |
| MINOR — gates don't prove cutover/replication claims | New gates G10 (cutover ordering), G11 (rollback slot setup), G12 (cache version fencing). G6 strengthened per above |
| NIT — G2/G5 relationship not stated | §6.5 + §11 add: "G2 proves inventory completeness before topology lock; G5 is the runtime safety net preventing future bypass routes from landing without A4 disposition" |

NF525 framing remains: self-attestation restored 2026-02-24 per economie.gouv.fr. Hard external date is **e-invoicing receive 2026-09-01** (master plan §22).

---

## 1. Recommendation

**Adopt Path 3 (Hybrid: DB-per-tenant on shared cluster + dedicated enterprise tier)** for the 2026-07-15 topology decision.

Concretely:
- **SaaS tier**: every tenant gets its own PostgreSQL **database** on a shared PG cluster. Tenants share host, OS, PgBouncer, backups, monitoring, ops cadence — but each has an independent database namespace.
- **Regional tier**: same shape, in-region cluster.
- **Dedicated tier**: a single tenant on its own PG cluster.

**Migration delta primitives**:
- **Forward promotion (§5.B)**: PostgreSQL logical replication (publications + subscriptions + replication slots).
- **Rollback replay (§5.B.5)**: target-side logical replication slot created at cutover, retained throughout the 7-day rollback window. Source-side subscription consumes the slot during rollback.
- **One-time pooled-public-schema extraction (§5.A)**: row-filtered exporter with consistent snapshot semantics.
- **Cutover write freeze (§5.A.4 + §5.B.4)**: read-only-connection mechanism — resolver issues `default_transaction_read_only=on` for tenants with `status='migrating'`.

This recommendation is the same as v3's; v4 changes only the *implementation contracts* that the recommendation depends on.

---

## 2. Placement profiles

Each tenant is bound to exactly one profile at any time.

| Profile | Tenant DB topology | Cluster | Backup story | Use case |
|---|---|---|---|---|
| `shared` | One tenant DB per tenant; many tenant DBs per cluster | Shared multi-DB PG | Per-DB nightly + cluster-wide PITR; per-tenant restore via `pg_dump <db>` | Long-tail SaaS — IziPOS, Tunisia, MA, free/trial |
| `regional` | Same as `shared`, cluster pinned to region | Shared multi-DB PG, in-region | Same, in-region | Country-bound residency contracts (FR, IT, UK) |
| `dedicated` | Exactly one tenant DB on a dedicated cluster | Dedicated PG cluster | Per-DB; PITR per cluster | Enterprise contracts where the certifier strategy or procurement language calls for a hardware boundary |

App-level `tenant_id` / `company_id` columns remain in tables during transition; they become structurally redundant but kept for defense-in-depth, the POS RLS pilot, and reusing the same Eloquent models in cross-tenant tooling.

---

## 3. Control-plane catalog (`erp_control`)

A new database **`erp_control`** holds the tenant catalog, shard registry, migration ledger, promotion log, and communication audit. Resolver reads via Redis-cached lookups (60s TTL with version-fenced invalidation per §5.D).

> **Naming**: this database is owned by the ERP runtime; NOT the Synerivia platform's `platform` database.

### 3.1 Schema

```sql
-- Physical placement targets (created BEFORE tenants — referenced by tenants.shard_id)
CREATE TABLE shards (
    id              text PRIMARY KEY,
    profile         text NOT NULL CHECK (profile IN ('shared','regional','dedicated')),
    region          text,
    host_secret_ref text NOT NULL,
    pgbouncer_host  text NOT NULL,
    status          text NOT NULL CHECK (status IN
                        ('provisioning','active','draining','retired')),
    capacity_hint   integer,
    region_lock     boolean NOT NULL DEFAULT false,
    created_at      timestamptz NOT NULL
);

-- Tenants and their CANONICAL placement (single-binding invariant per §3.2).
-- catalog_version is a monotonic counter incremented on every promotion cutover;
-- consumed by the cache-fencing protocol in §5.D.
CREATE TABLE tenants (
    id                uuid PRIMARY KEY,
    profile           text NOT NULL CHECK (profile IN ('shared','regional','dedicated')),
    region            text,
    shard_id          text NOT NULL REFERENCES shards(id),
    database_name     text NOT NULL,
    status            text NOT NULL CHECK (status IN
                          ('provisioning','active','migrating','suspended','archived')),
    catalog_version   bigint NOT NULL DEFAULT 1,    -- monotonic; bumped on cutover
    created_at        timestamptz NOT NULL,
    promoted_at       timestamptz,
    metadata          jsonb NOT NULL DEFAULT '{}'::jsonb,
    UNIQUE (shard_id, database_name)
);

CREATE TABLE migration_ledger (
    shard_id        text NOT NULL REFERENCES shards(id),
    tenant_id       uuid REFERENCES tenants(id),
    migration       text NOT NULL,
    ran_at          timestamptz NOT NULL,
    duration_ms     integer,
    PRIMARY KEY (shard_id, tenant_id, migration)
);

-- Promotion audit + rollback machinery. forward_slot_name tracks the publisher slot
-- on the source during sync; rollback_slot_name tracks the post-cutover capture slot
-- on the TARGET (created at cutover, retained until rollback_window_ends_at).
CREATE TABLE tenant_promotions (
    id                       uuid PRIMARY KEY,
    tenant_id                uuid NOT NULL REFERENCES tenants(id),
    from_shard               text NOT NULL,
    from_database_name       text NOT NULL,
    to_shard                 text NOT NULL,
    to_database_name         text NOT NULL,
    kind                     text NOT NULL CHECK (kind IN (
                                 'extract-from-public',
                                 'promote',
                                 'demote')),
    reason                   text NOT NULL,
    state                    text NOT NULL CHECK (state IN (
                                 'queued','preparing','exporting','syncing','verifying',
                                 'cutover','complete','rolled_back','failed')),
    started_at               timestamptz NOT NULL,
    cutover_started_at       timestamptz,
    cutover_ended_at         timestamptz,
    completed_at             timestamptz,
    rollback_window_ends_at  timestamptz,
    rollback_consent         jsonb,
    forward_slot_name        text,                  -- source-side slot during §5.B sync
    rollback_slot_name       text,                  -- TARGET-side slot for rollback replay
                                                    -- (created at cutover, dropped at window end or rollback completion)
    rollback_slot_wal_bytes  bigint,                -- updated by monitoring; alert on threshold
    notes                    text
);

CREATE TABLE tenant_communications (
    id                        uuid PRIMARY KEY,
    tenant_id                 uuid NOT NULL REFERENCES tenants(id),
    promotion_id              uuid REFERENCES tenant_promotions(id),
    template                  text NOT NULL,
    sent_to                   text NOT NULL,
    sent_at                   timestamptz NOT NULL,
    payload                   jsonb NOT NULL,
    response_received_at      timestamptz,
    response_payload          jsonb
);
```

### 3.2 Status matrix and the single-binding invariant

**Invariant**: `tenants.shard_id` and `tenants.database_name` always identify the canonical binding (where new traffic goes). Source preservation during cutover is owned by `tenant_promotions.from_*`. The resolver reads only `tenants.*` and never branches on promotion source/target. The catalog flip in §5.D is the single atomic transaction that rotates the canonical binding.

| `tenants.status` | `shards.status` | Resolver behavior | Operator behavior |
|---|---|---|---|
| `active` | `active` | Resolve normally, build DSN | Healthy steady state |
| `active` | `draining` | Resolve normally; emit `tenant_on_draining_shard` | Plan tenant moves off this shard |
| `active` | `retired` | **503 with `shard_retired`** | Catalog inconsistency — alert |
| `active` | `provisioning` | **503 with `shard_not_ready`** | Should not happen; alert |
| `provisioning` | any | **423 Locked with `tenant_provisioning`** | Provisioning workflow in progress |
| `migrating` | any | Connection issued with **`SET default_transaction_read_only = on`**. Reads succeed; writes fail at PG level with `25006 read_only_sql_transaction`. App surfaces as 423 Locked with `tenant_migrating` | See §5.A.4 / §5.B.4; freeze window in effect |
| `suspended` | any | **403 Forbidden with `tenant_suspended`** | Billing / compliance hold |
| `archived` | any | **410 Gone** | Tenant data retained for retention period; not serving |

The matrix lives in repo as `apps/api/database/erp-control/resolver-status-matrix.yaml` (A2 acceptance artifact, fixture for G1 test).

### 3.3 Control-plane availability — Y1 + Y2 posture

**Y1 (degraded acceptable)**: `erp_control` lives on the same Hetzner PG cluster as `shared-default`, as a separate database. Daily logical backup + WAL archiving for PITR. RTO ≤ 30 min, RPO ≤ 5 min. Blast radius accepted: tenants on `shared-default` are down regardless if that cluster is down; tenants on regional/dedicated shards depend on `shared-default` availability via the catalog.

**Y2 trigger conditions** (any one):
- First production-grade `regional` shard provisioned, OR
- First `dedicated` cluster provisioned for a customer with a stated SLA, OR
- `erp_control` exceeds 1 GB or sees > 100 reads/sec sustained.

**Y2 posture**: managed PG instance with synchronous replica + automatic failover.

**Cache layer**: resolver caches catalog reads 60s. Catalog version (per §5.D) is included in cache value; resolver checks `tenants.catalog_version` against cached value on every request and refreshes on mismatch (cheap because the check is part of the cached payload — only the version-mismatch path takes the catalog hit).

---

## 4. TenantConnectionResolver

```php
final class TenantConnectionResolver
{
    public function __construct(
        private readonly TenantCatalog $catalog,
        private readonly ConnectionFactory $factory,
        private readonly RequestTenantContext $ctx,
        private readonly SecretsResolver $secrets,
    ) {}

    public function connection(): Connection
    {
        $tenantId = $this->ctx->tenantId();
        if ($tenantId === null) {
            return $this->controlPlaneConnection();
        }

        $binding = $this->catalog->bindingFor($tenantId);
        $this->dispatchStatusOrThrow($binding);  // §3.2 matrix

        $conn = $this->factory->make($this->buildConfig($binding));

        if ($binding->status === 'migrating') {
            $conn->statement("SET default_transaction_read_only = on");
        }

        return $conn;
    }
}
```

### 4.1 Failure modes (all fail closed)

- Catalog unreachable, no cache: 503 `catalog_unavailable`.
- Catalog unreachable, cache present: serve from cache for remainder of TTL.
- Status from §3.2: dispatch per matrix.
- Secret resolution fails: 503 `secret_unavailable`.
- PgBouncer / PG cluster unreachable: 503 `database_unavailable`.

### 4.2 Connection pooling

All clients connect through PgBouncer (§8). Migration runner bypasses PgBouncer per existing entrypoint pattern.

### 4.3 Cross-cutting bootstrappers

| Resource | Stancl bootstrapper | v4 behavior | Implementation |
|---|---|---|---|
| Database | `DatabaseTenancyBootstrapper` | Activated; resolver wraps Stancl `Tenant::run(fn () => …)` | Stancl + resolver |
| Cache (Redis) | `CacheTenancyBootstrapper` (uses tags) | **Replace with key prefix** `tenant:<id>:`; tag-based unreliable across multi-DB | Owned thin wrapper |
| Direct Redis calls | `RedisTenancyBootstrapper` (commented out) | **Activate** with prefix `tenant:<id>:` | Stancl + audit task |
| Filesystem / object storage | `FilesystemTenancyBootstrapper` (local only) | **Extend to S3** via owned `S3TenantBootstrapper` (per-tenant prefix on keys) | Owned + Stancl |
| Queue (Horizon) | `QueueTenancyBootstrapper` | **Active** with stale-job policy: jobs carry `tenant_id` AND `expected_shard_id` AND `expected_database_name` AND `expected_catalog_version`; worker reroutes or holds for review on mismatch | Stancl + worker base class |
| Search (Meilisearch) | None | Per-tenant index name `<env>_<tenant_id>_<resource>` | Owned thin wrapper |

---

## 5. Migration mechanics

### 5.A `tenant:extract-from-public` — one-time pooled→tenant-DB extraction

Runs once per tenant during initial migration from current pooled `public` schema to multi-DB topology. After extraction, the tenant lives in its own DB on a `shared` shard.

#### 5.A.1 Why this is different from §5.B

The source is a row-filtered slice of a shared schema, not a database. Logical replication operates between databases / publications and cannot row-filter across all tenant-scoped tables with consistent FK closure semantics. The extraction tool is therefore an application-aware exporter.

#### 5.A.2 Export tool

```
php artisan tenant:extract-from-public <tenant_uuid> --to-shard=shared-default
```

Internals:
1. Tool reads tenant-scoped table manifest.
2. For each table in topological order: `SELECT … WHERE tenant_id = :uuid`; writes `pg_dump`-compatible custom-format.
3. FK closure: shared/system rows referenced by tenant rows handled per **A9 ownership taxonomy** (§5.A.3).
4. Sequences: per-tenant sequences re-initialized on target via `setval(MAX() + margin)` after the freeze window starts.
5. Fiscal hash chains: per-terminal/per-company chains travel intact; `verify-chains` runs on target before cutover.

#### 5.A.3 Reference-data ownership taxonomy (artifact A9)

Codex finding #8: "copy-as-needed deduplication" was hand-wavy because the schema mixes ownership models. Concrete examples: `tax_configurations` is referenced by `companies.default_tax_configuration_id`, `products.default_tax_configuration_id`, AND `vat_period_breakdowns.tax_configuration_id`; `withholding_tax_rules.company_id` is nullable (mixes country-level and company-specific rules).

The extraction tool requires an **ownership taxonomy artifact** (`apps/api/docs/extraction-fk-taxonomy.yaml`) classifying every FK from a tenant-scoped table as one of:

| Class | Behavior at extraction |
|---|---|
| **tenant-owned** | Copied with the tenant (e.g., `pos_receipts`, `payment_instruments`) |
| **company-owned** | Copied with the tenant's company rows |
| **global-immutable-reference** | NOT copied; restored via seeders on target (e.g., countries, currencies) |
| **global-mutable-reference** | Copied with consistent snapshot; per-row decision documented in taxonomy. Snapshot held for the full extraction window via repeatable-read transaction |
| **shared-with-tenant-overrides** | Source row + tenant override copied; tenant's reference replaced with the override on target. Pattern used for `tax_configurations` overrides |

The taxonomy artifact must enumerate every FK. The extraction tool refuses to run on tenant-scoped tables whose FKs are not classified.

#### 5.A.4 State machine specialization

```
queued → preparing → exporting → verifying → cutover [≤30 min lock] → complete
```

- `preparing`: target DB created on `shared-default`; full migration suite runs; manifest verified against source schema; target writes blocked.
- `exporting`: row-filtered export per §5.A.2 with FK closure per §5.A.3; source serves reads + writes normally.
- `verifying`: row counts, fiscal hash chain re-verification on target snapshot, golden-fixture smoke tests.
- `cutover`: source writes are locked **via the read-only-connection mechanism** (Codex BLOCKER #2 fix). When `tenants.status='migrating'`, the resolver issues `SET default_transaction_read_only = on` on every connection (§3.2 + §4); this covers app, queue workers (via TenantAwareJob), scheduled tasks. Operator maintenance during cutover is paused; emergency operator access goes through a separate `app_admin` role used only for incident response. The §5.D atomic protocol orchestrates: final delta export captures rows committed during verifying, sequences copied, atomic catalog flip, verification rerun on target.
- `complete`: source rows for this tenant retained read-only for 7-day window. Source-side cleanup deferred until rollback window closes.

**Lock window achievable**: yes for SMB tenants. Final delta is bounded by max-write-rate × verifying duration; for SMB POS tenants this is seconds to a minute. Tenants whose projected lock exceeds 30 min require extended-window operator approval per master plan §23.

#### 5.A.5 Tunisia-first sequencing

1. **Sacrificial rehearsal candidate**: a Tunisian test tenant with representative data. If absent, seed from `DemoTenantSeeder`.
2. **Rehearsal flow** (in staging): pooled `public` schema → `shared-default` shard, tenant DB `tenant_<uuid>`. Capture lock-window timing, fiscal hash re-verification time, smoke pass/fail, restore-to-source rehearsal.
3. **Production execution**: real Tunisian tenants in waves of 5, increasing as rehearsal data accumulates.

If no production Tunisian tenants exist when this lands, surface as an amendment to master plan §23 step 6 sequencing.

### 5.B `tenant:promote` / `tenant:demote` — ongoing DB-to-DB moves

Recurring promotion primitive used after initial extraction completes.

#### 5.B.1 Forward delta primitive — PostgreSQL logical replication

1. **On source**: `CREATE PUBLICATION FOR ALL TABLES` (excluding TimescaleDB hypertables and materialized views per §5.B.3). Slot name recorded in `tenant_promotions.forward_slot_name`.
2. **On target**: target DB provisioned + migrations applied + table prereqs verified per §5.B.2. `CREATE SUBSCRIPTION` pointing at source's publication; subscription's initial sync copies a consistent snapshot.
3. Replication slot on source captures WAL changes from snapshot point; subscriber applies them in commit order.
4. **Cutover**: §5.D protocol orchestrates write freeze, LSN catch-up, sequence copy, rollback-slot creation on target (per §5.B.5), atomic catalog flip, subscription drop, source-slot deletion.

#### 5.B.2 Table prerequisites for logical replication

Every tenant-scoped table must satisfy:
- **Primary key OR `REPLICA IDENTITY USING INDEX <i>`** where `<i>` is a **unique, non-partial, non-expression, non-nullable** index. `REPLICA IDENTITY DEFAULT` is acceptable only when the table has a real PK (verified, not assumed).
- **`REPLICA IDENTITY FULL`** allowed only by explicit table-level exception entry citing measured row width and update/delete volume. Not a default fallback.
- **No `IDENTITY` columns** that conflict with subscriber-side inserts; `bigserial`/`uuid` PKs preferred.
- **Sequences**: not replicated automatically. Cutover step copies `last_value` per sequence from source to target via `setval(value + safety_margin)` while writes are blocked (§5.D step 5).
- **Triggers**: subscriber triggers disabled by `session_replication_role = replica` (Postgres default); business-logic triggers do not run during sync. Enabled at cutover completion.

G6 acceptance gate (§11) introspects `pg_class.relreplident`, `pg_index.indisprimary`, `pg_index.indisreplident`, partial predicates (`pg_index.indpred`), expression indexes (`pg_index.indexprs`), nullable identity columns, row width. Fails on any tenant-scoped table without a valid primary or replica identity per the rules above.

#### 5.B.3 Excluded from logical replication

Tables that should NOT travel via subscription:
- **TimescaleDB hypertables** (audit trail) — hypertable size threshold check applies.
  - **`HYPERTABLE_THRESHOLD=1GB` per tenant default** (configurable). Tenants below threshold: hypertables copied via `pg_dump --table=…` snapshot at cutover within the ≤30-min budget.
  - Tenants above threshold: extended-window operator approval per master plan §23. A10 acceptance artifact (per-tenant hypertable size inventory) gates the sequencing.
- **Materialized views**: recreated on target post-cutover via `REFRESH MATERIALIZED VIEW`.
- **Sequences**: handled per §5.B.2.

#### 5.B.4 State machine specialization

```
queued → preparing → syncing → verifying → cutover [≤30 min lock] → complete
```

- `preparing`: target DB created with full migration suite + table prereqs verified (G6); source publication created; target subscription created (snapshot kicks off here).
- `syncing`: subscription replicates initial snapshot + ongoing changes. Source serves reads + writes normally.
- `verifying`: row counts compared; sample-row diffs; fiscal hash re-verification on target's current state; hypertable size re-checked against threshold.
- `cutover`: §5.D atomic protocol.
- `complete`: source DB retained read-only until `rollback_window_ends_at` (7 days). Rollback slot on target retained for the same window.

#### 5.B.5 Rollback — target-side slot capture (Codex BLOCKER #1 fix)

The fundamental change from v3: **the change-capture stream that rollback replay consumes is created at cutover, not after-the-fact**. Without this, a post-hoc reverse subscription only streams *future* writes — it cannot capture writes already made on target since cutover.

**Setup at cutover (§5.D step 6)**: as the last step before releasing the write lock, the cutover protocol creates a logical replication slot on the **target** DB:

```sql
SELECT pg_create_logical_replication_slot(
  'rollback_<promotion_id>',
  'pgoutput'
);
```

The slot is recorded in `tenant_promotions.rollback_slot_name`. A publication on target named `rollback_pub_<promotion_id>` covers all tenant-scoped tables (same shape as the forward publication).

**During the 7-day rollback window**: target writes accumulate WAL captured by the slot. WAL bytes are monitored (`tenant_promotions.rollback_slot_wal_bytes` updated by a periodic job from `pg_replication_slots.wal_status` / `confirmed_flush_lsn`).

**Disk threshold**: if accumulated WAL exceeds `ROLLBACK_SLOT_WAL_THRESHOLD` (default: 10 GB per slot), an alert fires. Operator decision tree:
1. Accelerate rollback decision deadline (e.g., shrink window from 7 days to current+24h).
2. OR drop the slot — forfeits replay-required path; rollback would fall back to loss-only with consent.

**Rollback execution (within 7-day window)**:
1. Operator opens rollback. Source DB still exists (read-only since cutover).
2. On source: lift the read-only restriction temporarily (operator role); create subscription pointing at target's publication consuming `rollback_<promotion_id>` slot. `copy_data=false` because source already has all pre-cutover rows; only post-cutover changes apply.
3. Subscription catches up; row counts and chain endpoints verified.
4. **Cutover-back**: target writes locked (status flips to `migrating` for the canonical-back-to-source flip). Subscription on source catches up to target's current LSN. §5.D atomic protocol runs in reverse direction: catalog flip back to source binding, target subscription dropped, target rollback slot deleted (it was the source for replay; now it's gone).
5. Mark `tenant_promotions.state='rolled_back'`.

**Loss-only fallback**: if target slot fails or is deliberately dropped per the disk threshold path, replay is impossible. Rollback then requires the loss-only path (pre-recorded tenant consent in `tenant_promotions.rollback_consent` per §5.B.6).

**Slot disposal**: at `complete` past `rollback_window_ends_at`, the rollback slot is dropped and the rollback publication removed. A scheduled job handles this; operator can also drop the slot on tenant demand if the customer waives the rollback window.

#### 5.B.6 Rollback consent (loss-only fallback)

Identical to v3. Decision tree:

```
Defect detected within 7-day rollback window:
  ↓
Replay-required path (default — uses §5.B.5 slot):
  - Rollback slot present on target → execute §5.B.5 procedure
  ↓
Loss-only path (only when slot failed / dropped / replay infeasible):
  - Account team contacts tenant
  - Tenant signs consent template recording: write_loss_interval, affected_data_summary,
    acceptance_signatory + timestamp, communication_log_ref
  - Consent stored in tenant_promotions.rollback_consent (jsonb)
  - Operator authorizes rollback only after consent landed
  - Flip catalog back to source, mark `rolled_back`
```

Template lives at `docs/runbooks/templates/rollback-consent-template.md` (A8 acceptance artifact).

### 5.C Shared state-machine shape

Both §5.A and §5.B use the canonical state set:

```
queued → preparing → exporting/syncing → verifying → cutover → complete
                                                              ↘ rolled_back (within window)
(any earlier stage)                                           ↘ failed
```

Shared concepts: catalog row in `tenant_promotions` (kind distinguishes `extract-from-public` / `promote` / `demote`), communication log in `tenant_communications`, atomic cutover via §5.D protocol, rollback consent infrastructure.

### 5.D Cutover atomicity and cache fencing protocol

This is the single concrete protocol both §5.A and §5.B follow during the cutover state. Solves Codex round-3 finding #4 (cutover ordering and cache fencing under-specified).

```
Cutover protocol (target-side, executed by promotion runner):

1. Acquire global write-lock for tenant:
     - Open transaction in erp_control
     - UPDATE tenants SET status='migrating', catalog_version = catalog_version + 1
       WHERE id = :tenant_id
     - Commit immediately (resolver sees migrating + new version on next refresh)

2. Cache-fencing barrier:
     - Publish invalidation event on Redis pub/sub: tenancy:invalidate:<tenant_id>:<new_version>
     - Each app instance, on receipt, evicts cached binding
     - Promotion runner waits for ack from every active app instance OR ≤2s grace (whichever first)
     - Stale cache entries that survive past TTL+grace serve the new version on
       next request because resolver compares cached catalog_version against
       tenants.catalog_version on every hit

3. (Forward path only — §5.B) Wait for subscriber to reach source LSN:
     - SELECT pg_current_wal_lsn() on source; capture as final_lsn
     - Poll subscription progress on target until pg_stat_subscription.received_lsn >= final_lsn
     - Timeout: if not caught up within window budget, abort cutover (state→failed)

4. (Forward path only — §5.B) For excluded tables (hypertables, materialized views):
     - pg_dump source-side hypertables with --table=… into target
     - REFRESH MATERIALIZED VIEW on target

5. Copy sequences:
     - For each sequence in source: target.setval(source.last_value + safety_margin)
     - Safety margin = max projected concurrent writes × 10 (configurable; default 1000)

6. Create rollback slot on TARGET (Codex BLOCKER #1 fix):
     - SELECT pg_create_logical_replication_slot('rollback_<promotion_id>', 'pgoutput')
     - CREATE PUBLICATION rollback_pub_<promotion_id> FOR ALL TABLES (matching forward publication shape)
     - Update tenant_promotions: rollback_slot_name, rollback_window_ends_at = now() + 7 days

7. Atomic catalog flip in erp_control:
     - BEGIN;
     -   UPDATE tenants SET shard_id = :to_shard, database_name = :to_database_name,
                              status = 'active', catalog_version = catalog_version + 1
              WHERE id = :tenant_id;
     -   UPDATE tenant_promotions SET state = 'cutover', cutover_ended_at = now()
              WHERE id = :promotion_id;
     - COMMIT;

8. Cache-fencing barrier (post-flip):
     - Publish tenancy:invalidate:<tenant_id>:<final_version>
     - Wait for ack OR ≤2s grace

9. Drop forward path artifacts:
     - DROP SUBSCRIPTION on target (if forward subscription still exists)
     - DROP PUBLICATION on source forward path
     - Delete source-side replication slot

10. Mark complete:
     - UPDATE tenant_promotions SET state = 'complete', completed_at = now()
       WHERE id = :promotion_id
```

**Atomicity guarantees**:
- Steps 1, 7, 10 are individual `erp_control` transactions; step 7 is the canonical-binding flip (the single-binding invariant point).
- Steps 2–6 happen while the tenant is in `migrating` state with writes blocked (read-only-connection mechanism); reads continue from source until step 7 commits.
- Step 8 ensures all app instances observe the new binding before the next read could land on source (where it would be inconsistent with the new sequence values).
- Failure at any step before 7: cutover aborted; tenant status reverted to `active` on source; cleanup of partial target artifacts via `tenant:promotion-cleanup`.
- Failure between step 7 and 10: cutover succeeded but cleanup pending; `tenant:promotion-cleanup` finishes the cleanup safely (idempotent).

**Lock window**: typical SMB tenant 10–60 seconds. Hypertable copy (step 4) is the biggest variable; threshold check at A10 keeps tenants under the 30-min cap.

G10 acceptance gate (§11) is a fixture test that walks the protocol step-by-step against a staging tenant pair, asserting each ordering constraint.

---

## 6. Cross-tenant operations

### 6.1 Three patterns, one banned

| Pattern | Status | When to use |
|---|---|---|
| A. Per-tenant iteration | Allowed | Bounded fan-out (≤ low hundreds tenants synchronously, or background) |
| B. Analytics store query | Required for unbounded queries | Cross-tenant joins, sorted pagination, full-fleet search |
| C. Shared-DB privileged role | **BANNED** | No shared DB to apply it to under Path 3 |

### 6.2 Pattern A — Per-tenant iteration

```php
foreach ($this->catalog->activeTenants() as $tenant) {
    $tenant->run(function () use ($collector) {
        $collector->add(Receipt::recentSealedCount());
    });
}
```

N bounded by config (`max_tenant_fanout=200` synchronous default). Above that → queued aggregator. Per-tenant errors isolated.

### 6.3 Pattern B — Analytics store

For super-admin views needing cross-tenant joins, sorted pagination, or full-text search. Store choice (ERP-side ClickHouse / expanded TimescaleDB / federation) deferred to §9; Pattern B endpoints **held** until that decision.

### 6.4 Cross-tenant endpoint disposition

Every existing super-admin route, scheduled job, broadcast handler, admin Filament resource MUST be classified in `apps/api/docs/cross-tenant-disposition.yaml` (A4 acceptance artifact).

| Category | Migration target |
|---|---|
| Control-plane query | `erp_control` |
| Per-tenant iteration with bounded N | Pattern A |
| Per-tenant iteration via queued aggregator | Pattern A async |
| Requires analytics store | Pattern B (deferred infra) |
| Currently broken under Path 3 | Held; alternative needed |

### 6.5 `CrossTenantContext` middleware sunset

The `cross_tenant=true` middleware is a transition aid. **G2 proves inventory completeness before topology lock** (every super-admin route classified in A4); **G5 is the runtime safety net in `TENANCY_MODE=multi_db`**, failing the build for any route with `#[CrossTenantRoute]` or relying on `cross_tenant=true` bypass that isn't mapped in A4. The two gates are complementary, not redundant.

---

## 7. Migration fan-out contract

### 7.1 Migrator choice

Wrap Stancl's `tenants:migrate`. Adds `erp_control.migration_ledger`, halt-on-fail with operator escape, schema-version-tolerance contract, long-running-migration isolation.

### 7.2 Failure mode

**Default: halt-on-fail.** First failure stops fan-out; idempotent re-run picks up where it left off. **Operator escape**: `--continue-on-fail`.

### 7.3 Schema-version-tolerance — AST classifier dominates comments

App code must tolerate `(migration-N applied, migration-N+1 not applied)` for fan-out duration. v3's "trust comments" model is replaced (Codex round-3 finding #7).

**The lint rule classifies migrations by AST first, comments second**:
1. Parse the migration file (PHP-AST).
2. Detect `Schema::*` calls and classify each operation:
   - **Additive**: `addColumn` nullable, `addColumn` with default, `Schema::create`, `Schema::table` adding indexes via `CREATE INDEX CONCURRENTLY`.
   - **Subtractive**: `dropColumn`, `dropTable`, `dropIndex`, `dropForeign`.
   - **Mutative**: `renameColumn`, `->change()` (type/nullable/default), `dropIndex` followed by `unique`/`index` in same migration, `DB::statement` containing `ALTER`/`UPDATE`.
3. Detect raw SQL via `DB::statement` / `DB::raw` and pattern-match for `ALTER`, `UPDATE`, `DROP`, `CREATE`. Unparseable raw SQL is treated as mutative by default.
4. **Comments are accepted only when AST agrees**: `// @migration-phase: additive` is rejected if classifier sees any subtractive/mutative operation. `// @migration-phase: expand` and `// @migration-phase: contract` are valid markers for two-phase patterns and require a corresponding pair migration in git history (linker traces by filename pattern).
5. Migrations classified as mutative without `expand`/`contract` markers fail the build.

**A6 acceptance artifact: migration corpus baseline** — `apps/api/database/migrations/.fan-out-safety-baseline.yaml` records every existing migration with: `{filename, detected_operations, human_disposition (one of: grandfathered/refactor-required/already-expand-contract), expiry (date by which grandfathered migrations must be refactored or accepted as permanent)}`. CI compares migrations against baseline; new migrations cannot be added to baseline without explicit human action.

The three migrations cited in Codex round-3 review (renameColumn at 2026_05_03, ->change() at 2026_04_26, drop+unique at 2026_05_08) appear in the baseline as `grandfathered`. They cannot be applied via fan-out as-is; if any of them needs to run on a tenant DB, the operator pre-applies them out-of-band and records the application in `erp_control.migration_ledger` manually.

### 7.4 Long-running migrations on dedicated DBs

Fan-out parallelized with concurrency limit (default 4). Per-tenant timeout enforced; tenant DB exceeding timeout marked failed and surfaced for operator review without blocking the rest.

### 7.5 Deploy ordering

1. Container startup: migrate `erp_control` only.
2. Container becomes healthy on **previous** schema.
3. Operator-triggered or scheduled job runs `tenants:migrate` to fan out across tenant DBs.
4. App code from step 2 already tolerates new schema; uses new fields as tenant DBs catch up.

---

## 8. PgBouncer topology and capacity assumptions

### 8.1 Topology

**One PgBouncer instance per cluster.** Replace current `edoburu/pgbouncer` setup (single hardcoded `DATABASE_URL`) with PgBouncer using a dynamic `[databases]` block generated from `erp_control.tenants` filtered by `shard_id`, reloaded on tenant add/remove via `RELOAD`.

**Pool mode**: `transaction`. **Generated `[databases]` entries force a single server user**:

```
tenant_<uuid> = host=<shard_pg_host> port=5432 dbname=tenant_<uuid> user=autoerp_app pool_size=5 reserve_pool_size=2 max_db_connections=10
```

Migrations bypass PgBouncer (existing `DB_DIRECT_HOST` pattern). `max_user_connections` set globally to bound the cluster.

### 8.2 Capacity assumptions (hypothesis pending A5 envelope)

**v4 reframes Codex round-3 finding #6 honestly**: the Y1=100 capacity claim is a *hypothesis* until the A5 acceptance artifact (PgBouncer memory envelope) is produced.

Naive formula: `tenant_databases × forced_users × (pool_size + reserve_pool_size)` = steady-state server connections per cluster. With `forced_users=1`, `pool_size=5`, `reserve_pool_size=2`:

| Year | Tenant DBs | Naive server connections | Assumed PG `max_connections` | Status |
|---|---|---|---|---|
| Y1 | 100 | 100 × 1 × 7 = 700 | 900 hypothesis | Comfortable IF A5 envelope confirms |
| Y3 | 500 | 500 × 1 × 7 = 3500 | impractical on one node | Cluster split required |
| Y5 | 1000 | spread across 3+ clusters | per-cluster 1500 | Multi-cluster steady state |

**A5 acceptance artifact (`docs/architecture/pgbouncer-capacity-multi-db.md`) MUST measure**:
- Target Hetzner node RAM (e.g., 32 GB, 64 GB, 128 GB) and `shared_buffers` allocation (typically 25%).
- `work_mem` per-backend (default 4 MB; consider workload).
- Per-backend memory at idle (~10 MB) and under typical query load (variable; Horizon long transactions can hold connections).
- Horizon worker count and p95 transaction duration.
- `reserve_pool_timeout` interaction with peak load.
- `max_user_connections` global limit.
- Noisy-tenant behavior under `max_db_connections=10` (one tenant cannot exceed 10 concurrent server connections).
- Realistic split-trigger threshold (default placeholder: 750 server connections; A5 envelope must confirm or revise).

**Until A5 lands, Y1=100 is a working hypothesis.** Topology decision can lock without A5 (the topology choice doesn't change with the envelope), but execution sequencing requires A5 before the first production SaaS tenant lands on multi-DB.

### 8.3 PgBouncer admin and monitoring

`SHOW POOLS` exported. `tenant_connection_count` metric tagged by tenant_id (sample at 1% if cardinality risk). Catalog-to-`[databases]` generator runs as sidecar; signals PgBouncer `RELOAD` on changes.

---

## 9. Cross-cutting non-decisions (genuinely deferred)

| # | Question | Default | Locks at |
|---|---|---|---|
| 9.1 | Analytics store choice | None — Pattern B endpoints held | First Pattern B endpoint shipped, OR Y2 |
| 9.2 | `regional` profile separate vs tag | Separate | First regional shard provisioned |
| 9.3 | PgBouncer admin tooling (manual `RELOAD` vs orchestrator) | Manual sidecar Y1 | First operational pain point |
| 9.4 | Tenant DB naming convention | UUID `tenant_<uuid>` | First tenant provisioned |
| 9.5 | Removal of `tenant_id`/`company_id` columns post-migration | Defer indefinitely | Driven by data-model cleanup |

---

## 10. Out of scope

- Slice plan / sprint breakdown — owned by master plan §23.
- Per-tenant cutover scheduling — operational under master plan §23 step 6.
- NF525 evidence pack — master plan §21.
- E-invoicing PDP partner selection — master plan §22.
- POS-only RLS pilot, composite FK guardrails — master plan §§19–20 + foundation plan.
- Frontend changes — none at the pattern level. Customer-facing communication for maintenance windows + rollback consent IS in scope (§5.B.6 templates referenced as A8 artifact).
- Cross-vertical isolation (Otospex vs IziPOS) — configuration on a tenant.
- Synerivia platform integration — push-based.

---

## 11. Acceptance criteria for the 2026-07-15 topology decision

Decision is "locked" when **every artifact in §11.1 is committed** AND **every validation gate in §11.2 passes**.

Counts: **10 artifacts + 12 gates = 22 items total.**

### 11.1 Artifacts (10)

- [ ] **A1. Catalog DDL + ERD** — `apps/api/database/erp-control/schema.sql` + `docs/architecture/erp-control-erd.md`. DDL ordered (`shards` before `tenants`).
- [ ] **A2. Resolver state matrix** — `apps/api/database/erp-control/resolver-status-matrix.yaml` covering all `(tenants.status × shards.status)` cells.
- [ ] **A3. Bootstrapper decision matrix** — `docs/architecture/multi-db-bootstrappers.md` with sections per resource: implementation file path, Stancl-vs-owned decision, migration steps, test refs.
- [ ] **A4. Cross-tenant endpoint disposition** — `apps/api/docs/cross-tenant-disposition.yaml`, populated for every super-admin controller, scheduled job, broadcast handler, admin Filament resource.
- [ ] **A5. PgBouncer capacity envelope** — `docs/architecture/pgbouncer-capacity-multi-db.md` with sections per §8.2 list (RAM, shared_buffers, work_mem, Horizon worker count, p95 tx duration, reserve_pool_timeout, max_user_connections, noisy-tenant behavior, measured split-trigger).
- [ ] **A6. Migration corpus baseline** — `apps/api/database/migrations/.fan-out-safety-baseline.yaml` with every existing migration: filename, detected operations, human disposition, expiry rule.
- [ ] **A7. Control-plane availability runbook** — `docs/runbooks/erp-control-availability.md` with sections: Y1 posture + accepted blast radius, Y2 trigger conditions, schema migration procedure, cache stale-read policy, failover procedure, recovery from full catalog loss.
- [ ] **A8. Promotion runbook** — `docs/runbooks/tenant-extraction-and-promotion.md` with sections: §5.A extraction state-machine playbook, §5.B promotion state-machine playbook, **§5.D cutover atomicity protocol playbook**, §5.B.5 rollback slot setup + replay procedure, lock-window expectations per tenant size, rehearsal checklist, rollback consent template reference, maintenance window template reference.
- [ ] **A9. Reference-data ownership taxonomy** — `apps/api/docs/extraction-fk-taxonomy.yaml` per §5.A.3. Every FK from a tenant-scoped table classified.
- [ ] **A10. Per-tenant hypertable size inventory** — `apps/api/docs/hypertable-size-inventory.yaml` per §5.B.3. Every TimescaleDB hypertable per tenant with size in MB; tenants over `HYPERTABLE_THRESHOLD` flagged for extended-window approval.

### 11.2 Validation gates (12)

- [ ] **G1. Resolver state matrix test** — `apps/api/tests/Architecture/TenantResolverStatusMatrixTest.php` reads A2 fixture; asserts every cell.
- [ ] **G2. Disposition table completeness** — `apps/api/tests/Architecture/CrossTenantDispositionCompletenessTest.php` scans super-admin controllers, schedules, broadcasts, Filament resources; fails on uncategorized routes. **Pre-lock inventory completeness check.**
- [ ] **G3. Migration fan-out CI rule** — `apps/api/tests/Architecture/MigrationFanOutSafetyTest.php` parses migrations via AST; AST dominates comments per §7.3. Compares against A6 baseline.
- [ ] **G4. Tenancy config dedup** — `apps/api/tests/Architecture/TenancyConfigTest.php` asserts `config('tenancy.database.managers.pgsql') === PostgreSQLDatabaseManager::class`.
- [ ] **G5. CrossTenantContext sunset** — `apps/api/tests/Architecture/CrossTenantSunsetTest.php`: in `TENANCY_MODE=multi_db`, fails for any route with `#[CrossTenantRoute]` or `cross_tenant=true` not mapped in A4. **Runtime safety net.**
- [ ] **G6. Replica identity introspection (strengthened)** — `apps/api/tests/Architecture/ReplicaIdentityTest.php` introspects `pg_class.relreplident`, `pg_index.indisprimary`, `pg_index.indisreplident`, `pg_index.indpred` (partial predicates), `pg_index.indexprs` (expression indexes), nullable identity columns, row width. Asserts every tenant-scoped table has either: (a) a real PK, OR (b) `REPLICA IDENTITY USING INDEX` with a non-partial, non-expression, non-nullable index. `FULL` allowed only with explicit exception entry.
- [ ] **G7. Tunisia-first rehearsal candidate named** — `docs/superpowers/coordination/tunisia-first-rehearsal.md` identifies sacrificial tenant, rehearsal cluster, target date.
- [ ] **G8. Master plan §23 step 1 references this spec** — link added with explicit choice "Path 3."
- [ ] **G9. Cert SOT WS-ERP item updated** — `2026-05-02-tenant-isolation-certification-sot.yaml` ERP-001 references this spec's artifacts.
- [ ] **G10. Cutover ordering protocol fixture** — `apps/api/tests/Integration/CutoverProtocolTest.php` walks the §5.D protocol step-by-step against a staging tenant pair; asserts ordering constraints (catalog version monotonic, cache invalidation barrier, LSN catch-up, sequence copy timing, atomic flip in single transaction, rollback slot creation order).
- [ ] **G11. Rollback slot setup test** — `apps/api/tests/Integration/RollbackSlotSetupTest.php` verifies that at the cutover step, a logical replication slot named `rollback_<promotion_id>` exists on the target with non-null `confirmed_flush_lsn`, and that `tenant_promotions.rollback_slot_name` and `rollback_window_ends_at` are populated.
- [ ] **G12. Cache version fencing test** — `apps/api/tests/Integration/CacheFencingTest.php` simulates a stale cache after catalog flip; verifies that resolver compares `tenants.catalog_version` against cached value and refreshes on mismatch (not just TTL expiry).

When all 22 items check, master plan §23 moves from "decide topology" to "execute topology." Execution sequencing remains under §23 step 6.

---

## 12. References

**Master plan and certification SOT**:
- [`2026-05-02-tenant-isolation-master-plan.md`](../plans/2026-05-02-tenant-isolation-master-plan.md) — §23 owns the migration; this spec feeds step 1.
- [`2026-05-02-tenant-isolation-nf525-database-foundation-plan.md`](../plans/2026-05-02-tenant-isolation-nf525-database-foundation-plan.md) — POS-only RLS pilot, composite FKs, DB audit baseline.
- [`2026-05-02-tenant-isolation-certification-sot.yaml`](../plans/2026-05-02-tenant-isolation-certification-sot.yaml) — WS-ERP workstream this spec feeds.
- [`tenant-isolation-sweep-inventory.yml`](../plans/tenant-isolation-sweep-inventory.yml) — tactical callsite inventory.

**Research**:
- [`2026-05-01-multi-tenancy-architecture-survey.md`](../research/2026-05-01-multi-tenancy-architecture-survey.md) — industry evidence; Frappe/Odoo precedent.
- [`2026-05-01-db-per-tenant-migration-mechanics.md`](../research/2026-05-01-db-per-tenant-migration-mechanics.md) — Path 1/2/3 mechanics.

**Reviews**:
- [`2026-05-09-multi-db-topology-codex-review.md`](../reviews/2026-05-09-multi-db-topology-codex-review.md) — v1 review; topology semantics BLOCKER.
- [`2026-05-09-multi-db-topology-codex-rereview.md`](../reviews/2026-05-09-multi-db-topology-codex-rereview.md) — v2 review; migration primitives BLOCKER.
- [`2026-05-10-multi-db-topology-codex-round3-review.md`](../reviews/2026-05-10-multi-db-topology-codex-round3-review.md) — v3 review; rollback replay + extraction freeze BLOCKERs (addressed in v4).

**Code references**:
- `apps/api/config/tenancy.php` — Stancl configuration; needs dedup of `pgsql` key (G4).
- `apps/api/config/database.php` — current connections.
- `apps/api/app/Modules/Tenant/Domain/Tenant.php` — Stancl `HasDatabase`-shaped model.
- `apps/api/app/Http/Middleware/CrossTenantContext.php` — transition aid; sunset gated by G5.
- `apps/api/app/Shared/Presentation/Validation/ScopedExists.php` — current row-level validation pattern.
- `apps/api/docker/entrypoint.sh:95–123` — current migration-bypasses-PgBouncer pattern.
- `docker-compose.dokploy.yml:124–141` — current PgBouncer config; replaced under §8.1.

**External**:
- economie.gouv.fr revised 2026-02-24 — NF525 self-attestation restoration.
- PostgreSQL — logical replication architecture (`https://www.postgresql.org/docs/17/logical-replication-architecture.html`), publication (`https://www.postgresql.org/docs/17/logical-replication-publication.html`), conflicts (`https://www.postgresql.org/docs/17/logical-replication-conflicts.html`), logical decoding (`https://www.postgresql.org/docs/current/logicaldecoding.html`), `pg_create_logical_replication_slot` (`https://www.postgresql.org/docs/current/functions-admin.html#FUNCTIONS-REPLICATION`).
- PgBouncer — config (`https://www.pgbouncer.org/config.html`), usage (`https://www.pgbouncer.org/usage.html`).
- Microsoft SaaS multi-tenancy guidance — pattern taxonomy.
- Frappe Cloud / Odoo Online — operational precedent.
