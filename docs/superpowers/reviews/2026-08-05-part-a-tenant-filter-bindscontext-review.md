# Adversarial review — `cb3c67d67` (forEachTenant active-only) + `fd679a317` (marketplace jobs BindsTenantContext)

Date: 2026-08-05 · Reviewer: tenancy-authz-reviewer (adversarial, code-grounded)
Scope: `apps/api` only. Both commits are already on local `dev`; findings are a **fix-forward wave**.
Paths below are relative to `apps/erp/apps/api` unless stated otherwise.

## VERDICT: spec ❌ + quality CHANGES-REQUESTED

Commit 1 ships a behaviour change materially wider than its stated intent, justified by two
factual claims about the codebase that are false. Commit 2's design choices are sound for the
shipping (db-per-tenant) mode, but its serialization mitigation is incomplete and its new
assertions under-pin the contract they claim to pin.

---

## BLOCKERS

### B1 [Critical] `--tenant`-targeted repair commands silently no-op on non-active tenants — and the commit message asserts the opposite

`TenantScopedCommand.php:163-165` claims:

> "Commands that must reach non-active tenants (deprovisioning, re-provisioning, lifecycle
> repair) are cat-(a-singleshot) and take an explicit `--tenant` instead; they never route
> through here."

Four commands take `--tenant` **as a filter applied INSIDE the `forEachTenant()` closure**, i.e.
they do route through here:

| Command | Signature | Filter site |
|---|---|---|
| `treasury:reconcile` | `ReconcileTreasuryCommand.php:128-129` | `:147`, `:155-158` |
| `fiscal:retry-projections` | `RetryFiscalProjectionsCommand.php` (`--tenant`) | `:64`, `:73-85`, `:115-117` |
| `accounting:backfill-refund-compensation-accounts` | `BackfillRefundCompensationAccountsCommand.php:55-57` | `:72`, `:79-88` |
| `fiscal:backfill-sealed-hash-algorithm` | `BackfillSealedHashAlgorithmCommand.php:77-81` | `:95`, `:105-117` |

Because the status gate at `TenantScopedCommand.php:176-184` runs **before** the closure, the
`--tenant` comparison is never reached for a non-active tenant. Consequence for an operator:

```
php artisan fiscal:retry-projections --tenant=<suspended-uuid>
# → exit 0, "…0 rows…" — no error, no warning, nothing done
```

Why it matters: the last two are **one-time data backfills** (accounting chart purposes; fiscal
`pos_receipts.sealed_hash_algorithm`). A tenant that is Suspended/Pending at the moment the
backfill wave runs is permanently skipped and there is no signal that it was. `treasury:reconcile`
and `fiscal:retry-projections` are the documented operator recovery paths for cash drift and
dead-lettered fiscal projections; both now return SUCCESS having done nothing on exactly the
tenants an operator is most likely to be repairing.

**Fix:** when `--tenant` is supplied, bypass the status filter (pass an explicit
`?string $onlyTenantId` into `forEachTenant()`, or add `forEachTenantIncludingInactive()`), OR at
minimum fail loudly (`INVALID` + stderr) when `--tenant` names a tenant that the filter skipped.
Also correct the docblock at `TenantScopedCommand.php:163-165` — it currently documents the
opposite of the code.

### B2 [Critical] Pending tenants are fully reachable at request time — the "same predicate" claim is false

`TenantScopedCommand.php:153-156` and the commit message both assert that `isActive()` is
"the same predicate `ResolveTenancy`/`AuthController` apply". It is not:

- `Tenant::isActive()` — `Tenant.php:185-188` — `status === Active` (excludes Pending).
- `ResolveTenancy.php:69` — `in_array($tenant->status, [TenantStatus::Suspended, TenantStatus::Archived], true)` — **Pending passes**.
- `AuthController.php:270` — identical two-status list — **Pending logs in**.
- `EnsureTenantIsActive.php:37` is the only middleware that uses `isActive()`, and it is
  **registered nowhere**: `grep -rn EnsureTenantIsActive app/ bootstrap/ routes/` returns only
  the class itself and a docblock reference in `TenantDeprovisioningService.php:9,35`.
- `tenants.status` DB default is `'pending'` — `database/migrations/2025_11_30_000001_create_tenants_table.php:20`.

So a Pending tenant is a **live, logged-in, transacting tenant** that as of this commit silently
receives: no stock-reservation expiry, no batch-expiry sweep or 7-day critical alert, no treasury
reconciliation/freeze, no fiscal projection retry, no held-order expiry, no appointment reminders,
no subledger reconciliation, no recurring expenses, no certification expiry checks. Silent, and by
design INFO-level so nothing alerts.

The mainline creation paths do set Active (`TenantProvisioningService.php:89`,
`CreateTenantCommand.php:72`, `AuthController.php:371`), so exposure is bounded to rows created
outside them (raw inserts, platform-side provisioning, imports/restores) — but the DB default
guarantees any such row lands in the silently-unswept state.

**Fix:** either align `forEachTenant()` with the request-time predicate (skip only
`Suspended`/`Archived`), or make Pending explicitly out-of-scope by first closing the gap in
`ResolveTenancy`/`AuthController` (and registering `EnsureTenantIsActive`). Do not leave the two
layers disagreeing about what "active" means. Correct the docblock either way.

---

## REQUIRED

### R1 [Important] Skipping Suspended is not noise reduction — those tenants never produced the noise

`TenantDeprovisioningService.php:104-118`: "Suspend a tenant: REVERSIBLE, so the database is
preserved. Only the central status flips to Suspended." A suspended tenant's per-tenant database
is intact, so `tenancy()->initialize($tenant)` at `TenantScopedCommand.php:191` **succeeds** for
it and the continue-on-throw ERROR at `:197-202` never fired. The stated root cause
(`initialize()` against a missing/closed DB) does not apply to Suspended at all.

What the commit actually does for Suspended is **silently disable every batch control** on a
state that is explicitly designed to be reversible — including `treasury:reconcile` (cash-drift
detection + repository freeze, a fraud control) and `fiscal:retry-projections` (dead-letter
recovery for fiscal projections). Tampering or drift occurring while suspended is now undetected,
and on reactivation there is no catch-up pass. For a compliance-oriented ERP this is the wrong
default for a reversible state.

`Archived` is a different case: `deprovision()` **deletes the central `tenants` row**
(`TenantDeprovisioningService.php:100`, `:165-171`), so a deprovisioned tenant never appears in
`Tenant::all()` at all. Nothing in `app/` ever assigns `TenantStatus::Archived`
(`grep -rn "TenantStatus::Archived" app/` → only the enum + comparisons), so Archived rows are
set externally.

**Fix:** narrow the skip to statuses that genuinely cannot be opened, and justify each one
against the real failure mode rather than the lifecycle label.

### R2 [Important] The filter is a proxy for the wrong predicate — Active tenants with a missing DB still spam ERROR

The actual failure condition is "the tenant database is missing/unreachable", not
"status ≠ Active". `TenancyResolver` already implements the correct probe:
`TenancyResolver.php:60` — `if (! $tenant->database()->manager()->databaseExists($tenant->getDatabaseName()))`.
`forEachTenant()` calls `tenancy()->initialize($tenant)` bare at `TenantScopedCommand.php:191`
with no such probe.

An **Active** tenant whose DB is absent still throws every tick. That is reachable:
`TenantProvisioningService.php:225-243` — `compensate()` swallows a failed physical drop
(`catch (\Throwable) { // best-effort; reconcile sweeps stragglers }`) and only afterwards deletes
the central rows; any interruption between the two, plus manual drops and partial restores, leaves
an Active row with no database.

**Fix:** add a `databaseExists()` pre-check (mirroring `TenancyResolver.php:60`) with an INFO skip
+ a distinct log key. That fixes A7 at the root and would let the status filter be dropped or
narrowed to R1's minimum.

### R3 [Important] Serialization mitigation is incomplete — pre-existing `failed_jobs` rows become permanently un-retryable

Failure mode confirmed, not assumed:

- `SerializesModels::__unserialize()` — `vendor/laravel/framework/src/Illuminate/Queue/SerializesModels.php:92-94` —
  `if (! array_key_exists($name, $values)) { continue; }`. An old one-arg payload has no
  `tenantId` key, so the promoted readonly property stays **uninitialized**.
- Reproduced on PHP 8.4.15: reading it throws
  `Error: Typed property …::$tenantId must not be accessed before initialization`
  — thrown at `SyncSellerListingsJob.php:62` / `ReconcileListingsJob.php:74` via
  `BindsTenantContext.php:64`.
- `config/horizon.php:216` — `'tries' => 1` — so it fails once and lands straight in `failed_jobs`.

The DEPLOY NOTE covers the **in-flight** queue ("drain or discard the in-flight backlog"). It does
not cover `failed_jobs` rows for these two classes that already exist at deploy time — and
"a `queue:retry` of a `failed_jobs` row" is the headline scenario the commit cites as its own
motivation (`SyncSellerListingsJob.php:26-31`). Those rows are now un-retryable forever.

A forward-compatible alternative exists and was verified on PHP 8.4.15: a **declared (non-promoted)
property with a default** survives unserialization of an old payload as its default —
`public ?string $tenantId = null;` unserialized from a one-arg payload yields `null`, no fatal
(and `__serialize()` at `SerializesModels.php:44-46` omits default-valued properties, so the
payload does not grow). Combined with a fallback in `BindsTenantContext` to
`tenant()?->getTenantKey()` (the bootstrapper's stamp) before throwing, old payloads would drain
correctly instead of dying.

**Fix (pick one):** (a) declared-default property + trait fallback; (b) explicitly add
"purge/rewrite pre-existing `failed_jobs` rows for these two classes" to the deploy note; (c)
version the job class for one release.

### R4 [Important] A red test sits in a file `fd679a317` edited

`tests/Feature/Marketplace/MarketplaceScheduledCommandsTest.php:119`
(`test_commands_are_registered_with_scheduler`) **fails on `dev` right now** — `schedule:list`
does not contain `marketplace:delta-sync`. Cause is the marketplace kill-switch
(`config/marketplace.php:6` — `'enabled' => env('MARKETPLACE_ENABLED', false)`) gating the
`callAfterResolving(Schedule::class, …)` block in `MarketplaceServiceProvider.php:54-71`.

Pre-existing (introduced by `6f14f8232`, which predates both commits under review; neither commit
touches the provider, the config, or that test method) — **not caused by this wave**. But the
commit added assertions to this exact file and shipped without the file green.

Observed:
```
Tests: 5, Assertions: 23, Failures: 1.   # MarketplaceScheduledCommandsTest.php alone
```
`MarketplaceListingJobsTenantContextTest` and `TenantScopedCommandForEachTenantTest` both pass
(5/5 and 6/6).

**Fix:** repair or re-scope the stale assertion (gate it on `config('marketplace.enabled')`).

### R5 [Important] The INFO log has the same cardinality as the ERROR log it replaces

`forEachTenant()` consumers on the scheduler and their cadence:

- every 15 min — `fiscal:retry-projections` (`routes/console.php:22-25`),
  `inventory:expire-reservations` (`:101-106`), `pos:expire-held-orders`
  (`HeldOrderServiceProvider.php:40`), `marketplace:delta-sync`
  (`MarketplaceServiceProvider.php:59-65`, currently dark by kill-switch)
- hourly — `scheduling:schedule-appointment-reminders` (`routes/console.php:147-149`)
- daily — `treasury:reconcile` (`:56`), `treasury:instrument-maturity-alerts` (`:65`),
  `expenses:generate-recurring` (`:71`), `batch-expiry:daily-check` (`:123`),
  `accounting:check-subledger-reconciliation` (`:136`), `workshop:check-expiring-certifications`
  (`:142`), `marketplace:reconcile` (`MarketplaceServiceProvider.php:67`)

≈ **415 INFO lines per non-active tenant per day** (≈ 320 with marketplace dark). The commit's
"a single Log::info per tenant per run" is literally true and materially misleading at fleet
scale: severity dropped ERROR→INFO, volume did not change at all.

**Fix:** emit one aggregate line per run (`skipped_count` + id list) instead of one per tenant, or
log at DEBUG.

---

## MINOR

### M1 Exit-code semantics DID change (the claim "unchanged" is wrong)
Previously a non-active tenant with a missing DB threw → `$exit = FAILURE` (`:203`) → aggregate
FAILURE → the `onFailure()` hooks fired (`routes/console.php:104-106`, `:126-128`,
`MarketplaceServiceProvider.php:63-65`, `:69-71`). Now those runs exit SUCCESS. That is the
intended win, but it is a change, and it removes the only aggregate signal that a tenant was not
processed. The docblock statement at `:161-162` describes the new behaviour correctly; the framing
"unchanged" does not.

### M2 One weak assertion in the new unit test
`TenantScopedCommandForEachTenantTest.php:193` — `assertFalse(tenancy()->initialized)` is not
load-bearing: the `finally` block at `TenantScopedCommand.php:204-208` always ends tenancy, so it
would pass even if the skip were removed. The non-vacuous assertions in that test are `:191`
(`SUCCESS`) and `:192` (`assertNotContains`), plus `:155` (`assertSame([$active->id], $processed)`)
in the data-provider test — those do fail if the skip is removed. Test is sound overall; the
assertion is decorative.

### M3 `BindsTenantContext` docblock now contradicts its own two newest adopters
- `BindsTenantContext.php:27-35` states layer (2): "the using class **MUST** also chain
  `->where('tenant_id', $this->tenantId)` on every query against a tenant-scoped table". Both new
  adopters deliberately do not (`SyncSellerListingsJob.php:36-45`, `ReconcileListingsJob.php:37-53`).
  The rejection reasoning is correct for db-per-tenant, but the trait now documents a mandate two
  of its users violate by design. Update the trait docblock, not just the job docblocks.
- `BindsTenantContext.php:41-46` claims `Tenant::find()` returns null when the tenant "has been
  soft-deleted, **suspended**, or never existed". False for suspended: `Tenant` has no
  `SoftDeletes` (`Tenant.php:62-70` — `HasDatabase, HasDomains, HasFactory, HasUuids`) and
  `find()` does not filter status. A suspended tenant resolves and the job runs — which now
  directly contradicts commit 1's "suspended tenants are skipped" contract. Deletion is what
  produces null (`TenantDeprovisioningService.php:165-171` deletes the central `tenants` row).

### M4 Compat-mode cross-anchoring is now explicit rather than fixed
`MarketplaceScheduledCommandsTest.php:296-313` documents the compat-mode reality: 2 tenants × 2
active sellers = 4 dispatches, and tenant A dispatches a job for tenant B's seller stamped
`tenantId = tenantA->id`. Under db-per-tenant this is impossible (physical isolation — the seller
id was read from A's DB). Under `TENANCY_DB_PER_TENANT=false` (`phpunit.xml:46`, and the pre-flip
compat mode) that job binds A and writes `last_sync_at` on B's seller row plus syncs B's products.
`MarketplaceDeltaSyncCommand.php:41-42` calls this "a re-sync, not a correctness bug" — accurate
for the shipping mode; in compat mode it is a cross-tenant write, and this commit makes the wrong
anchor explicit instead of removing it. Answer to the review question: **no**, a job dispatched
for tenant A cannot mutate tenant B's rows **under db-per-tenant**; it can under compat mode, and
it always could — this change does not introduce the exposure.

### M5 New assertions do not pin the sellerId↔tenantId pairing
`MarketplaceScheduledCommandsTest.php:174-211` — `dispatchedSellerIds()` and
`dispatchedTenantIds()` each `sort()` their own list independently, and the expectations at
`:296-313` are likewise sorted separately. The test therefore asserts only the *multiset* of
tenant ids, never which tenant id was paired with which seller id. A regression that swapped
anchors (e.g. `dispatch($seller->id, $seller->tenant_id)` where the values happened to coincide as
a multiset) would still pass. Assert the ordered pairs.

### M6 `TenantRun::run()` has no `try/finally` — a throw inside the closure leaks tenancy
`vendor/stancl/tenancy/src/Database/Concerns/TenantRun.php:18-33`: `tenancy()->initialize($this);
$result = $callback($this);` then revert — **no** `finally`. If `handle()`'s closure throws
(`SyncSellerListingsJob.php:62-84`), tenancy is never reverted. In a queue worker this is bounded
by `QueueTenancyBootstrapper`'s `JobFailed` listener
(`vendor/stancl/tenancy/src/Bootstrappers/QueueTenancyBootstrapper.php:76-78, 95-117`) — but that
listener early-returns when the payload has no `tenant_id` (`:97-100`), i.e. exactly the
synchronous/console-dispatch path this commit claims to make safe. No test covers "throw inside
the closure". Consider wrapping `withTenantContext()` in its own `try/finally`.

---

## Answers to the specific challenges

**Commit 1 (a) — is skipping Pending correct for every consumer?** No. See B2: Pending tenants are
serviceable at request time, so a Pending tenant that is genuinely mid-provisioning is
indistinguishable from a Pending tenant that is live and trading. Full consumer list (all via
`forEachTenant`): `treasury:reconcile`, `treasury:instrument-maturity-alerts`,
`expenses:generate-recurring`, `inventory:expire-reservations`, `batch-expiry:daily-check`,
`accounting:check-subledger-reconciliation`, `workshop:check-expiring-certifications`,
`scheduling:schedule-appointment-reminders`, `fiscal:retry-projections`, `pos:expire-held-orders`,
`marketplace:delta-sync`, `marketplace:reconcile`, plus the two backfills
(`accounting:backfill-refund-compensation-accounts`, `fiscal:backfill-sealed-hash-algorithm`).
A missed batch-expiry day is a *missed 7-day critical alert on drug/food stock* with no catch-up
pass anywhere in `BatchExpiryDailyCheckCommand.php:69-100` — not acceptable silently.

**Commit 1 (b) — compliance problem from skipping Suspended?** Yes, but not where the brief
guessed. `fiscal:lock-expired-periods` does **not** route through `forEachTenant` — it is a plain
`Command` (`app/Console/Commands/LockExpiredFiscalPeriodsCommand.php:51`) carrying
`@cross-tenant-by-design` (`:49`), i.e. tenant-blind and a separate pre-existing gap, untouched by
this commit. The real compliance exposure is `treasury:reconcile` (cash-drift detection + freeze)
and `fiscal:retry-projections` (fiscal dead-letter recovery) going dark on a reversible,
DB-preserved Suspended tenant — see R1 — plus the two one-time backfills permanently skipping it
(B1).

**Commit 1 (c) — info-log noise at scale?** Yes: ~415 lines/day/skipped tenant, identical
cardinality to the ERROR stream it replaces. See R5.

**Commit 1 (d) — exit-code semantics unchanged?** No. See M1.

**Commit 2 (a) — is the iterating-tenant-id choice sound?** Yes for db-per-tenant, the shipping
mode: the seller id was read from that tenant's database, so the iterating tenant *is* the correct
anchor and `$seller->tenant_id` would be NULL for external sellers
(`MarketplaceSellerFactory::external()`, pinned at
`MarketplaceListingJobsTenantContextTest.php:150-178`). Rejecting the audit's
`where('tenant_id', …)` recommendation is correct for the same reason. Caveat in M4 (compat mode).

**Commit 2 (b) — old-payload failure mode + mitigation completeness?** Confirmed
`Error: Typed property … must not be accessed before initialization`, one attempt, straight to
`failed_jobs`. Mitigation is **incomplete** for pre-existing `failed_jobs` rows, and a safer
forward-compatible alternative (declared non-promoted default + trait fallback) was verified to
work. See R3.

**Commit 2 (c) — tenant DB gone between dispatch and execution?** Three distinct outcomes:
directory row deleted (`deprovision()`) → `RuntimeException` from `BindsTenantContext.php:66-72`
→ 1 try → `failed_jobs`; status flipped to Suspended (DB preserved) → job **runs normally** and
mutates a tenant the scheduler now refuses to touch (M3); DB dropped with the directory row still
present → PDOException on the first query → `failed_jobs`. So yes, `failed_jobs` noise is
reintroduced for the last case — bounded, because commit 1 stops the fan-out for non-active
tenants, which is exactly the coupling that makes M3's inconsistency visible.

**Commit 2 (d) — do the new tests pin the claimed behaviour?** Mostly yes, not vacuously.
`MarketplaceListingJobsTenantContextTest` observes the bound tenant *at the moment data is
touched* via a `ListingSyncService` subclass (`:244-260`) — a collaborator, not the unit under
test, so this is legitimate. Limits: the suite runs `TENANCY_DB_PER_TENANT=false`
(`phpunit.xml:46`), so it proves `tenancy()->initialized` + `tenancy()->tenant->id`, **not** a
physical database switch; and see M5 (pairing not asserted) and M6 (no throw-inside-closure case).
`TenantScopedCommandForEachTenantTest` is non-vacuous (6/6 pass; `:155` and `:192` fail if the
skip is removed) with one decorative assertion (M2).

---

## What to fix before this wave is considered closed

Make `--tenant` bypass the status filter (or fail loudly) in the four repair/backfill commands
(B1); reconcile the Active-vs-request-time-predicate divergence for Pending and stop silently
disabling reversible Suspended tenants (B2, R1); replace the status proxy with a
`databaseExists()` probe (R2); and either make `$tenantId` unserialize-tolerant or extend the
deploy note to purge pre-existing `failed_jobs` rows for the two job classes (R3).

---

# RE-GATE — fix commit `24dac38ee` (on top of `cb3c67d67` / `fd679a317`)

Date: 2026-08-05 · Reviewer: tenancy-authz-reviewer (adversarial re-gate, code-grounded, read-only)
Scope: `apps/api`. Every claim below was probed in the working tree, not read off the commit message.
Paths relative to `apps/erp/apps/api` unless stated.

## VERDICT: spec ✅ (all four blockers closed) + quality **APPROVE-WITH-FIXES**

`24dac38ee` genuinely closes B1, B2, R1, R2, R3 and M3, and the tests it ships are non-vacuous
and green. It introduces one new fail-open in the probe's error branch (N1) and one test that
does not pin the property it claims to pin (N2). Both are ~5-line fixes.

## Verification of the original findings

**B1 — CLOSED.** `TenantScopedCommand.php:200-201` resets and `:206`/`:217` record the
skipped/visited id lists; `:291-313` `failIfTenantFilterUnvisited()` returns `INVALID` for a
tenant absent from the directory (`:307-312`) and `FAILURE` for one the probe skipped
(`:297-305`), each with a stderr line. All four in-closure `--tenant` commands call it
immediately after `forEachTenant()` and return its value before printing their summary:
`ReconcileTreasuryCommand.php:263-265` (aggregate at `:279-281` still folds `$tenantExit`),
`RetryFiscalProjectionsCommand.php:153-155`,
`BackfillRefundCompensationAccountsCommand.php:121-123`,
`BackfillSealedHashAlgorithmCommand.php:146-148`.
**No fifth command was missed:** 17 classes extend `TenantScopedCommand`; only those four declare
`{--tenant=}` *and* route through `forEachTenant()`. The other three `--tenant` carriers are
cat-(a-singleshot) and use `bindTenantAndCompanyFromOptions()` —
`EnableV4RefundAuthoringCommand.php:65`, `DisableV4RefundAuthoringCommand.php:106`,
`ExportNf525JetCommand.php:51`. The false docblock is gone.

**B2 / R1 / R2 — CLOSED.** `forEachTenant()` contains no status logic at all; the only skip is
`TenantScopedCommand.php:205` `$dbPerTenant && ! $this->tenantDatabaseExists($tenant)`, and
`tenantDatabaseExists()` (`:326`) calls `$tenant->database()->manager()->databaseExists($tenant->getDatabaseName())`
— the same shape as `TenancyResolver.php:60`. Skip logs at WARNING (`:208`) and never touches the
aggregate (`:243-245`).

**Compat-mode bypass — justified, NOT a hole (verified, not taken on trust).** In compat mode the
probe would return false for *every* tenant (there are no per-tenant databases), so bypassing it is
required, not a shortcut. And the claim that `initialize()` is a no-op there is true twice over:
`TenancyServiceProvider.php:49-58` gates `BootstrapTenancy`/`RevertToCentralContext` on
`tenancy_resolver.db_per_tenant` at fire time, and `TenantScopedCommand.php:223-226` does not even
call `tenancy()->initialize()` unless `$dbPerTenant`. Probe connection is also correct: stancl's
`DatabaseConfig::manager()` (`vendor/stancl/tenancy/src/DatabaseConfig.php:149-165`) pins the
manager to `getTemplateConnectionName()` → `config('tenancy.database.central_connection')`
(`:100-105`, `config/tenancy.php:50,56`), so the probe reads `pg_database` on **central**, never on
the swapped default. No wrong-DB read.

**R3 — CLOSED, as the review's option (a).** `SyncSellerListingsJob.php:68` and
`ReconcileListingsJob.php:80` both declare `public ?string $tenantId = null;` (non-promoted,
defaulted), constructor still takes `string $tenantId` (`:70-75` / `:82-87`) so new dispatches
cannot omit it, and `handle()` discards a null anchor with a warning and zero data access
(`SyncSellerListingsJob.php:79-89`, `ReconcileListingsJob.php:91-101`). The `__serialize()`
default-omission behaviour is real in this vendor tree
(`vendor/laravel/framework/src/Illuminate/Queue/SerializesModels.php:44-46`), so payloads do not
grow, and `__unserialize()`'s skip of absent keys (`:92-94`) now yields the default instead of an
uninitialized typed property. The proof test is non-vacuous: it asserts the serialized payload
genuinely lacks the key, then that `last_sync_at` is untouched, tenancy was never initialized, and
the warning fired (`MarketplaceListingJobsTenantContextTest.php:184-253`).

**M3 — CLOSED in the docblock.** `BindsTenantContext.php:30-40` now frames `where('tenant_id', …)`
as the consumer's choice with the nullable-column rationale, `:41-50` documents the
declared-defaulted-property contract, and `:52-61` corrects the null semantics (deletion, not
suspension). See N3 for the one string that was not updated with it.

**R4 — incidentally green.** `MarketplaceScheduledCommandsTest` now boots with
`Tests\Traits\EnablesMarketplaceModule` (`:46`) and runs 5/5.

## Test evidence (run, not assumed)

```
tests/Unit/Console/TenantScopedCommandForEachTenantTest.php        OK 12 tests, 69 assertions
tests/Feature/Marketplace/MarketplaceListingJobsTenantContextTest.php
  + tests/Feature/Fiscal/RetryFiscalProjectionsCommandTest.php     OK 14 tests, 57 assertions
tests/Feature/Marketplace/MarketplaceScheduledCommandsTest.php     OK  5 tests, 24 assertions
tests/Feature/Inventory/ExpireStockReservationsCommandTest.php
  + tests/Feature/BatchExpiry/BatchExpiryDailyCheckCommandTest.php OK  7 tests, 42 assertions
tests/Feature/Accounting/SubledgerReconciliationCommandTest.php    OK  4 tests, 17 assertions
phpstan (4 changed app files)                                      [OK] No errors
```
`database/` was clean before and after every run — the `beforeApplicationDestroyed` cleanup works
on the normal path.

## NEW findings

### N1 [Important] A throwing probe is treated as "database missing" — an infra fault silently skips the whole fleet at exit 0

`TenantScopedCommand.php:323-337` catches **every** `Throwable` from the probe and returns `false`,
i.e. "cannot open ⇒ skip", which `:205-215` turns into a WARNING that by design never degrades the
aggregate (`:243-245`). But the probe cannot distinguish *"pg_database says no"* from *"I could not
ask"*. Two reachable global faults answer "no" for **every** tenant:

- central connection lost *after* `Tenant::all()` at `:203` materialised the directory — every
  subsequent `PostgreSQLDatabaseManager::databaseExists()`
  (`vendor/stancl/tenancy/src/TenantDatabaseManagers/PostgreSQLDatabaseManager.php:41-44`, a
  `SELECT` on the central connection) throws;
- driver/config drift — `DatabaseConfig::manager()` throws `DatabaseManagerNotRegisteredException`
  (`vendor/stancl/tenancy/src/DatabaseConfig.php:155-157`) when the central connection's driver has
  no registered manager.

Result: 100% of tenants skipped, WARNING-only, **exit SUCCESS**, on every tick — so the ops signal
wired to these runs never fires (`routes/console.php:56-59` treasury:reconcile `onFailure`,
`:101-106` inventory:expire-reservations `onFailure`, and the equivalents for batch-expiry /
marketplace). Cash-drift freeze, fiscal dead-letter recovery and batch-expiry alerts all go dark
silently. Pre-`cb3c67d67` this same fault surfaced as ERROR + FAILURE.

This also breaks the docblock's own claim at `:315-322` that the probe mirrors `TenancyResolver`:
the resolver **fails closed** on a throwing probe under db-per-tenant
(`TenancyResolver.php:81-89` → `TenantUnavailableException` → 503). The command fails open, silently.

*Fix:* separate the two outcomes. A probe that RETURNS false ⇒ skip + WARNING (current, correct).
A probe that THROWS ⇒ treat as that tenant's FAILURE (log ERROR, `$aggregate = FAILURE`) — it is an
infra fault, not evidence of a missing database. Cheapest shape: let `tenantDatabaseExists()`
rethrow (or return a tri-state) and handle it in the loop. Optional belt-and-braces: if
`skippedTenantIds !== [] && visitedTenantIds === []`, degrade the aggregate.

Note the four `--tenant` commands are already loud in this scenario (they hit the FAILURE branch at
`:297-305`); the exposure is confined to the ~13 unattended scheduler consumers.

### N2 [Important — test] The status-agnostic contract is only pinned in compat mode, where the probe cannot run

`TenantScopedCommandForEachTenantTest.php:136-171` (`test_lifecycle_status_is_not_a_filter`) never
sets `tenancy_resolver.db_per_tenant`, so it runs with the flag false and `:205`'s
`$dbPerTenant && …` short-circuits before any status could be consulted. The db-per-tenant leg,
`:185-242`, does parameterise status — but only on the tenant that has **no** database
(`$missing`, `:190`); the openable one is created with the default `TenantStatus::Active`
(`:189` — `createTenantWithDatabase('has-db-'.$status->value)`, no `$status` argument), despite the
assertion message at `:215` claiming "regardless of its lifecycle status".

So a regression that reintroduced `if ($dbPerTenant && ! $tenant->isActive()) continue;` — exactly
B2/R1 — would leave this suite green. The whole point of the fix is unpinned in the mode that ships.

*Fix:* pass `$status` through at `:189` (`createTenantWithDatabase('has-db-'.$status->value, $status)`).
One argument.

### N3 [Minor] The operator-facing exception string still carries the falsehood M3 removed from the docblock

`BindsTenantContext.php:83` — "The dispatched tenant may have been soft-deleted, **suspended**, or
never existed" — directly contradicts the corrected docblock 30 lines above (`:52-61`: `Tenant` has
no SoftDeletes, `find()` applies no status predicate, a suspended tenant resolves normally). This is
the string an on-call engineer reads out of `failed_jobs`. Fix the message to match: "the central
`tenants` row is gone (deprovisioned) or never existed".

### N4 [Minor] Provisioned tenant DB files are not gitignored, so an abnormal test exit leaves untracked junk

`tests/Traits/ProvisionsTenantDatabases.php:32-40` `touch()`es `database_path($tenant->getDatabaseName())`
and unlinks it in `beforeApplicationDestroyed`. The trait docblock (`:25-26`) claims "nothing leaks
into `database/`" — true only for a normal teardown; a fatal/interrupt leaves the file. The name is
`prefix + tenant key` = `tenant<uuid>` (`config/tenancy.php:60-61`, `Tenant.php:255-266`) with no
extension, and `database/.gitignore` only ignores `*.sqlite*` — so a leftover shows up as untracked
and is committable. Add `tenant*` to `database/.gitignore` (verified clean on the happy path; this
is the crash path only). No hidden global state otherwise: filenames are UUID-unique so paratest
processes cannot collide, and the closure is `static` with no `$this` capture.

### N5 [Minor] `TenantScopedCommandForEachTenantTest` duplicates the new trait instead of using it

`:336-345` re-implements `createTenantWithDatabase()` (touch + tearDown unlink at `:302-309`) while
`Tests\Traits\ProvisionsTenantDatabases` exists for exactly this. Two copies of the same
SQLite-file-is-a-database assumption will drift. Use the trait.

### N6 [Minor] Other `BindsTenantContext` adopters contradict the trait's new property guidance

`BindsTenantContext.php:41-50` now says a class that was **ever dispatched without** the anchor MUST
use a declared, defaulted property. `ProcessImportJob.php:64` and `ProcessProductImageImport.php:56`
gained `public readonly string $tenantId` in `b059bb2c5` (2026-05-07) — the same commit that
introduced the trait — so pre-2026-05-07 payloads for those classes have the identical
"un-retryable `failed_jobs` row" exposure R3 described. Same shape at
`GenerateRenditions.php:61`, `ExtractDocumentJob.php:45`, and the four Channel jobs
(`IngestChannelOrderJob.php:25`, `DispatchStockChangeToChannelJob.php:38`,
`ChannelReconciliationJob.php:28`, `DispatchProductToChannelJob.php:29`). Whether any such rows
still exist ~3 months later is an ops question — **cannot verify from code**. Ticket it; do not
widen this commit.

### N7 [Minor — residual, not regressed] R5 (log cardinality) was mitigated by scope, not by aggregation

Still one line per skipped tenant per run, now at WARNING. But the skipped population changed from
"every non-Active tenant" to "every tenant with no database", which should be ~0 in a healthy fleet
— so the ~415 lines/day/tenant figure no longer applies in practice. Acceptable as-is; revisit only
if N1's fail-open is left in place (a central outage would then emit `tenants × commands` WARNING
lines with no failing exit).

## Answers to the re-gate questions

- **Can a throwing probe abort the batch?** No — `:325-336` catches everything, and the loop
  `continue`s. **Can it mask a transient central outage as skip-everything?** Yes, and that is N1:
  100% skipped, WARNING level, exit 0, `onFailure` hooks silent. Judgement: **not acceptable** as
  the default for unattended fiscal/treasury controls, and a regression against the pre-`cb3c67d67`
  behaviour of erroring. It is a small, well-scoped fix.
- **Is the compat-mode bypass itself a hole?** No — verified against `TenancyServiceProvider.php:49-58`
  and `TenantScopedCommand.php:223-226`. In compat mode there is nothing to probe and nothing is
  initialized; probing there would skip the entire fleet.
- **Was a fifth `--tenant`-in-closure command missed?** No (enumerated above).
- **Did the four collateral test wirings weaken anything?** No. The diff for
  `SubledgerReconciliationCommandTest`, `BatchExpiryDailyCheckCommandTest`,
  `ExpireStockReservationsCommandTest` and `MarketplaceScheduledCommandsTest` changes only the two
  `createTenant(...)` lines into `provisionTenantDatabase(createTenant(...))` plus the trait import;
  every assertion is byte-identical, and all four suites are green. Their probe commands only record
  tenant ids, so the empty SQLite file is never queried.

## What to fix before merge

Make a throwing `databaseExists()` probe a FAILURE rather than a silent skip (N1), and parameterise
the openable tenant's status in the db-per-tenant probe test so the status-agnostic contract is
actually pinned in the shipping mode (N2). N3–N7 can ride the next wave.
