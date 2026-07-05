REQUIRES-DIFFERENT-APPROACH

The draft should not feed the 2026-07-15 topology decision in its current form. The Path 3 recommendation is plausible, and several primitives are pointing in the right direction, but the design silently changes the researched Path 3 from "Stancl multi-DB for SaaS plus dedicated enterprise" into "pooled row-level shared/regional shards plus dedicated enterprise." That changes the security, migration, PgBouncer, backup, cross-tenant, and acceptance story. The fix is not to abandon hybrid isolation; it is to either align the design with the already-researched Path 3 semantics, where SaaS tenants get separate databases on shared infrastructure, or explicitly rename and re-score this as a different row-level-sharded-plus-dedicated topology before the decision is locked.

## Numbered Findings

1. **BLOCKER - §1/§2 topology semantics - The draft says Path 3 but designs a different topology.**

   Evidence: the spec recommends "Stancl multi-database mode for the SaaS tier" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:33`), while the `shared` profile says "`shared` | many | Long-tail IziPOS... | App-level scoping" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:53`). The research's Path 3 says "SaaS tier uses Path 1 (multi-DB on a shared cluster)" (`docs/superpowers/research/2026-05-01-db-per-tenant-migration-mechanics.md:42`) and later makes that concrete as "Tenants share Postgres host, separate databases" (`docs/superpowers/research/2026-05-01-db-per-tenant-migration-mechanics.md:364`).

   Issue: a shared PostgreSQL host with one database per SaaS tenant is not the same thing as a shared database/shard with many tenants and app-level scoping. The spec's catalog stores `tenant.shard_id` and `shards.dsn_secret_ref`, not a per-tenant database name, so the resolver has no place to express SaaS-tier database-per-tenant except by making every tenant a `dedicated` shard. That collapses the intended tier model.

   Proposed fix: pick one topology and rename the profiles accordingly. If this is true Path 3, redefine `shared` as "many tenant databases per shared PostgreSQL cluster/PgBouncer fleet" and add per-tenant database identity to the catalog. If the intended destination is pooled row-level shared shards plus dedicated enterprise databases, stop calling it Path 3, add a new comparison against the survey/master-plan decision tree, and explicitly accept the continued app-level/RLS dependency for the SaaS tier.

2. **MAJOR - §3/§4 resolver and catalog schema - The resolver does not enforce catalog state and ignores shard state.**

   Evidence: the catalog allows tenant statuses "`provisioning`,`active`,`migrating`,`suspended`,`archived`" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:77`) and shard statuses "`active`,`draining`,`retired`,`provisioning`" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:89`). The resolver only checks "`if ($binding->status === 'migrating')`" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:149`) and its binding comment includes only "{shard_id, profile, region, status}" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:147`).

   Issue: `suspended`, `archived`, `provisioning`, `draining`, and `retired` have no runtime semantics. A suspended tenant can still resolve a connection. A tenant on a draining/retired shard can still be sent traffic. `dsn_secret_ref` is recorded in `shards` but absent from the resolver binding, so `configForShard()` hides a second catalog lookup and a second failure mode.

   Proposed fix: add an explicit tenant/shard status matrix with HTTP behavior and operator behavior for every state. Make `bindingFor()` return the shard record or define a second `shardFor()` lookup with cache invalidation rules. Add required acceptance tests for suspended tenants, archived tenants, provisioning tenants, draining shards, retired shards, missing secret refs, and unavailable secrets manager.

3. **MAJOR - §3/§4 bootstrapping and HA - "Fail closed with 503" is not an availability design.**

   Evidence: the catalog "sits separately from any tenant data" and "the resolver reads from it on every request" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:66`). Failure mode is only "Catalog unreachable -> request 503; never silently falls back" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:168`). Current Dokploy production topology is one PostgreSQL service, "`postgres: image: timescale/timescaledb:2.13.0-pg16`" (`docker-compose.dokploy.yml:55-56`).

   Issue: the catalog is now the first dependency for every tenant request, queue job, promotion, and cross-tenant operation. The draft does not specify whether `erp_control` lives in the same PostgreSQL cluster as the tenant shards, whether it has HA/replication, how it is migrated, how regional failover works, what cache staleness is allowed during failover, or how a bad catalog migration is rolled back.

   Proposed fix: add a control-plane availability subsection before approval. It must name the catalog deployment target, backup/PITR policy, migration order, read replica/failover posture, Redis cache stale-read policy, health checks, and explicit degraded modes. If the first version is a single Dokploy PostgreSQL database, say that and record the accepted downtime/blast radius.

4. **MAJOR - §5 promotion state machine - The lock window can exceed the master plan's 30-minute tenant window.**

   Evidence: the spec sets "`queued`: tenant marked `status='migrating'`; mutating requests rejected with 423" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:207`) before `exporting`, `importing`, and `verifying`. The master plan requires "Maintenance window per tenant: <=30 minutes" (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:844`).

   Issue: marking the tenant as migrating at `queued` locks writes for the full export/import/verify duration, not just final cutover. For a non-trivial tenant, `pg_dump`, import, row count checks, hash-chain verification, and smoke tests can easily exceed 30 minutes. That violates the plan's operational contract while claiming deference to it.

   Proposed fix: split `preparing/exporting/importing/verifying` from a short `write_locked/cutover` state. Use snapshot export plus final delta freeze, or explicitly document that the 30-minute window is being replaced and obtain master-plan approval for that change.

5. **MAJOR - §5 rollback - The spec downgrades rollback from replay-or-loss to loss-only and misses tenant consent timing.**

   Evidence: the spec says "`rolled_back`: connection pointer flipped back; new writes since cutover are not replayed" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:213`). The master plan says "new writes since cutover are replayed on the public schema (or the tenant accepts the write loss, documented per tenant)" (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:842`) and requires the maintenance window be "Communicated to the tenant 7 days in advance" (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:844`).

   Issue: loss-only rollback is allowed only as one branch of the master plan, not the default. The draft says "Owner alerts the tenant" after rollback, which is too late for consent to write loss.

   Proposed fix: add an operational rollback decision tree: replay required by default, write loss only with pre-recorded tenant/account-team acceptance, legal/commercial owner, timestamp, affected write interval, and customer communication template. Add this to the promotion audit table or linked runbook.

6. **MAJOR - §6 cross-tenant operations - Existing super-admin endpoints do not fit the proposed patterns.**

   Evidence: the spec says "Per-tenant iteration (preferred)" and "This is the only pattern compatible with `dedicated` profile tenants" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:227`). Current super-admin code does fleet-wide pooled queries such as "`total_users` => DB::table('users')->count()" (`apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php:40`) and paginates users with "`$query = User::with(['tenant']);`" plus "`$users = $query->orderBy('created_at', 'desc')->paginate(20);`" (`apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php:344`, `apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php:370`).

   Issue: per-tenant iteration can compute counts at N=hundreds, but it cannot preserve global pagination, filtering, ordering, and joins for user directories, invoice/payment directories, or "list all sealed receipts" style audit screens without a materialized cross-tenant store. The spec defers that store and does not inventory which current super-admin routes break under dedicated/profile mixing.

   Proposed fix: add a cross-tenant endpoint disposition table before approval. Classify every current super-admin and scheduled/audit flow as control-plane-only, per-tenant iteration with bounded N, unsupported until analytics store, or shared-profile-only legacy. For global list screens, specify pagination semantics and whether dedicated tenants appear.

7. **MAJOR - §6 security model - Pattern 3 reintroduces an unscoped shared-DB role by convention.**

   Evidence: the spec permits "a `super_admin` PG role can read across tenants on that shard" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:231`) and says every use "must be gated by an explicit profile check" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:231`). The current `CrossTenantRoute` attribute says architecture tests "skip tenant-scoping checks when this attribute is present" (`apps/api/app/Shared/Architecture/CrossTenantRoute.php:13-15`), and `CrossTenantContext` says the request flag lets downstream code "legitimately bypass tenant filters" (`apps/api/app/Http/Middleware/CrossTenantContext.php:16-18`).

   Issue: this creates a high-privilege escape hatch whose safety depends on discipline and route annotation, which is exactly the class of failure the tenant-isolation sweep is closing. It also does not compose with `regional` even though regional is structurally shared. The statement "does NOT extend to `regional`" is a policy choice, not an enforceable database boundary.

   Proposed fix: either ban Pattern 3 for application code, or make it enforceable: separate read-only DB role, central wrapper API, static rule forbidding direct use outside approved classes, profile assertions backed by catalog tests, audit logging for every use, and a sunset plan once analytics exists. If regional is shared, explain why it is excluded or include it with the same controls.

8. **MAJOR - §4/§8.1 bootstrappers - Cache/filesystem/queue/search are named but not designed, and the Stancl details do not match the draft.**

   Evidence: the spec lists "Cache: per-tenant key prefix", "Filesystem / object storage: per-tenant bucket prefix", "Queue payloads: every job carries `tenant_id`", and "Search index: Meilisearch index name includes `tenant_id`" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:177-181`), then says "These already exist as Stancl bootstrappers" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:183`). Current Stancl config lists database/cache/filesystem/queue bootstrappers only (`apps/api/config/tenancy.php:39-44`), cache uses a tag base rather than the stated key prefix (`apps/api/config/tenancy.php:94-100`), filesystem rewrites local disk roots and storage paths (`apps/api/config/tenancy.php:107-138`), and the Redis bootstrapper is commented out (`apps/api/config/tenancy.php:44`).

   Issue: search has no Stancl bootstrapper here, Redis direct calls are not prefixed, cache tagging is not the same as key prefixing, and filesystem behavior is local-disk suffixing rather than object-store bucket prefixing. Queue tenancy is especially load-bearing for promotions because stale jobs can run under the wrong tenant after a shard flip.

   Proposed fix: resolve 8.1 before topology lock, not at first shard provisioning. Add a resource-by-resource design with exact Stancl classes or owned replacements, job payload schema, stale-job behavior during promotion, cache invalidation, Redis direct-call scanning, Meilisearch index naming, object storage prefixing, and tests.

9. **MAJOR - §8.5 PgBouncer - The default conflicts with current Dokploy shape and lacks capacity math.**

   Evidence: the spec defaults to "Single PgBouncer with named pools per shard, until pool count exceeds practical PgBouncer limits" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:264`). Current Dokploy PgBouncer has one `DATABASE_URL=.../autoerp`, `DEFAULT_POOL_SIZE=20`, and `MAX_CLIENT_CONN=200` (`docker-compose.dokploy.yml:128-132`). The migration mechanics research warns that "pool size x tenant count x Postgres host capacity" is "the actual operational ceiling" (`docs/superpowers/research/2026-05-01-db-per-tenant-migration-mechanics.md:291-292`).

   Issue: "until practical limits" is not a topology decision. In the current setup, PgBouncer is configured for one database URL, not a dynamic catalog of named pools. The entrypoint already has to bypass transaction pooling for migrations because "PgBouncer in transaction mode doesn't support advisory locks used by Laravel's migration runner" (`apps/api/docker/entrypoint.sh:95-97`), which becomes more complex across N shards/tenant DBs.

   Proposed fix: include a concrete PgBouncer design sketch in the spec, not only acceptance criteria. It should choose dynamic discovery vs generated config, estimate pool counts at Year 1/3/5, define `max_db_connections`, define how migrations connect directly per shard, and say when the topology moves to per-shard PgBouncer instances.

10. **MAJOR - §7 migration fan-out - Halt-on-fail is not reconciled with Stancl or deploy semantics.**

   Evidence: the spec recommends "halt-on-fail as the default" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:246`). The mechanics research says "`php artisan tenants:migrate` runs `database/migrations/tenant/` migrations against every tenant DB" (`docs/superpowers/research/2026-05-01-db-per-tenant-migration-mechanics.md:224-225`) and notes the mitigation that "`--queue` flag runs migrations asynchronously per tenant" (`docs/superpowers/research/2026-05-01-db-per-tenant-migration-mechanics.md:232-234`). Current deploy runs "`php artisan migrate --force`" on API startup (`apps/api/docker/entrypoint.sh:122-124`).

   Issue: the draft proposes a custom `platform.migration_ledger` but does not say whether it replaces Stancl's migration table, wraps `tenants:migrate`, or runs a separate migrator. Halt-on-fail leaves mixed schema versions; queued fan-out leaves mixed schema versions by design; neither is safe unless app code is compatible with both old and new schemas during rollout.

   Proposed fix: choose the migrator contract. If using Stancl, document how `tenants:migrate`, queueing, per-tenant migration tables, and `platform.migration_ledger` interact. If owning the migrator, document locking, retries, online-schema-change rules, deploy ordering, app compatibility requirements, and how long-running dedicated DB migrations avoid blocking the shared fleet.

11. **MAJOR - §8 deferred questions - Several "not prerequisites" are actually load-bearing for the July decision.**

   Evidence: the spec says open questions are "not prerequisites for the topology recommendation" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:256`). The SOT acceptance for ERP-001 requires the migration path to include "tenant provisioning, PgBouncer/connection pooling, backups, and test-suite migration" (`docs/superpowers/plans/2026-05-02-tenant-isolation-certification-sot.yaml:363`).

   Issue: 8.1 bootstrappers, 8.3 migration failure mode, and 8.5 PgBouncer topology are not implementation details after the topology decision; they determine whether the topology is operable in the existing Dokploy/Horizon/Redis setup. 8.2 cross-tenant analytics is also load-bearing for current super-admin list screens if dedicated tenants must be visible.

   Proposed fix: split §8 into "must decide before topology lock" and "can decide during execution." Move 8.1, 8.3, 8.5, and the super-admin subset of 8.2 into the former. Leave only genuinely non-blocking refinements deferred.

12. **MAJOR - §9/§10 acceptance and out-of-scope - The lock criteria are mostly document updates, not verifiable architecture readiness.**

   Evidence: acceptance includes items like "Master plan §23 step 1 references this design doc" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:273`) and "Tunisia-first sequencing remains intact under this design" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:280`). Out of scope says "Frontend / UX changes - none required for any of the above" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:293`).

   Issue: the criteria do not verify the hard parts: resolver behavior, control-plane HA, PgBouncer scale, migration fan-out, backup/restore, current super-admin compatibility, job/cache/filesystem/search tenancy, or rollback communication. Declaring no UX/customer-facing change also hides the required tenant maintenance-window and write-loss consent process from the master plan.

   Proposed fix: replace or augment §9 with measurable artifacts: catalog DDL/ERD, resolver state matrix, PgBouncer capacity sketch, bootstrapper decision, migration fan-out contract, backup/restore runbook, super-admin endpoint disposition table, first Tunisia rehearsal candidate, and signed rollback/maintenance communication template.

13. **MINOR - §3/§8.6 naming - The spec keeps the collision in the heading while saying to avoid it later.**

   Evidence: §3 is titled "Control-plane catalog (`platform` DB)" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:64`) and says "A new database `platform` sits separately" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:66`). The naming note says implementation should disambiguate, for example "`erp_control` or `tenancy_control`" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:68`), and §8.6 defaults to "Use `erp_control`" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:265`).

   Issue: if the default is already `erp_control`, the spec should not introduce and repeat `platform` as the conceptual name. The collision is especially risky because Synerivia platform is a real adjacent system and current docs already use "platform" for cross-app concerns.

   Proposed fix: rename §3 and every reference to `erp_control` or a more precise `erp_tenancy_control`. Reserve "platform" for Synerivia platform or generic prose only.

14. **MINOR - §5 Tunisia-first sequencing - The draft asserts it but does not name the first migration that exercises the topology.**

   Evidence: the spec says "Initial promotions select tenants where a defect window is operationally tolerable; French / regulated tenants migrate last" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:217`). The master plan says first paying customers are Tunisia and "France/regulated coming later" (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:24`).

   Issue: under the draft's actual shared-shard design, long-tail Tunisian/IziPOS tenants may remain pooled and never exercise a promotion at all. The first real promotion could therefore be an enterprise/dedicated case, which is the opposite of "Tunisia-first" rehearsal.

   Proposed fix: add the first concrete rehearsal shape: for example, promote a sacrificial Tunisian tenant from current pooled public schema to the target SaaS profile, then optionally to dedicated, with success metrics. If no Tunisian tenant will move in the first wave, say the master-plan sequencing needs amendment.

15. **MINOR - §0/§2 legal framing - Mostly consistent, but "NF525-certified deployments" overstates the topology relationship.**

   Evidence: the spec correctly says NF525 is "no longer a hard statutory deadline" and is "a commercial-assurance differentiator" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:27`). But the `dedicated` profile use case includes "NF525-certified deployments" (`docs/superpowers/specs/2026-05-09-multi-db-topology-design.md:55`). The survey says "No regulator surveyed mandates a specific tenancy model" (`docs/superpowers/research/2026-05-01-multi-tenancy-architecture-survey.md:142`).

   Issue: the legal framing is internally mostly right, but the placement table can be read as "NF525 implies dedicated DB." That is stronger than the master plan and survey support.

   Proposed fix: reword to "enterprise NF525/commercial-assurance deployments where the contract or certifier evidence strategy calls for a dedicated boundary." Keep NF525 urgency out of the topology decision.

## Things The Spec Gets Right

- It correctly keeps the tactical tenant-isolation sweep, POS-only RLS pilot, composite FK guardrails, NF525 evidence pack, and e-invoicing work in their existing owners instead of redesigning them.
- It correctly treats a control-plane catalog as operational state rather than config-as-code. The instinct is right; the missing piece is the HA/migration/runbook design.
- It correctly recognizes promotion/demotion as an operational primitive and records a promotion audit trail.
- It correctly flags cross-tenant analytics as a separate durable store problem instead of pretending a single SQL query will keep working after multi-DB.
- It correctly carries forward the restored NF525 self-attestation framing and keeps e-invoicing as the hard external deadline.

## Verdict Reasoning

This is `REQUIRES-DIFFERENT-APPROACH` because the draft cannot be approved by minor edits while its profile table contradicts the Path 3 topology it claims to adopt. Once that is corrected, most other issues become tractable design gaps: define the catalog status matrix, decide bootstrappers and PgBouncer before topology lock, map current cross-tenant surfaces, and make promotion/rollback compatible with the master plan's 30-minute window and tenant communication requirements. The review does not require abandoning hybrid tenancy; it requires making the hybrid topology explicit and operable before it becomes the §23 decision source.
