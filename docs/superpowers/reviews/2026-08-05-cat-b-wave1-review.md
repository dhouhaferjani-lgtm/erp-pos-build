# Adversarial review — cat-(b) Wave 1 (6 cross-tenant surface conversions + N1..N5 re-gate)

**Date:** 2026-08-05
**Scope:** `318406dfb, 56f8abe9a, 8e443a9f0, 500f0bcff, 66f70b1be, 0f742a70d, c3b61ecaa, 452fbfef9, 9949a4e95` (apps/api)
**Reviewer:** tenancy-authz-reviewer (read-only; this file is a record, not a merge)

## VERDICT

**spec ❌ (one unauthenticated 500 vector + one compat-mode conversion gap) · quality CHANGES-REQUESTED**

The tenancy reasoning in this wave is genuinely good — the six broken surfaces are correctly
diagnosed, the fail-closed N1 semantics are right and actually pinned by tests, and the
`unserialize()` claim holds. Three things must change before merge; the first is the only
hard blocker.

---

## Environment caveats (read first — they bound what these findings prove)

* **The working tree moved under me.** A concurrent session was editing
  `apps/api/app/Console/TenantScopedCommand.php` during the review and has since committed it
  as `dbc5476aa` (wave 2: `forEachTenantFiltered` / `forEachExplicitlySelectedTenant`). All
  wave-1 analysis was done against the **committed** 382-line version
  (`git show 9949a4e95:apps/api/app/Console/TenantScopedCommand.php`); the wave-2 diff is
  purely ADDITIVE and leaves `forEachTenant()` byte-identical, so the test results below hold.
* **PHPStan is NOT attributable to this wave.** `vendor/bin/phpstan` on the changed paths
  reports 23 `larastan.console.undefinedOption` errors, all at `TenantScopedCommand.php:352`
  for `$this->option('all-tenants')`. The reviewed commit contains **zero** occurrences of
  `all-tenants` (verified via `git show`). Those errors belong to wave 2.
* **Two architecture gates are ALREADY RED on `dev`; this wave neither causes nor fixes them.**
  `tests/Architecture/ConsoleCommandTenantContextTest.php` (8 unclassified commands:
  `ScanPercentScaleDrift`, `ExportFrontendPermissionsMap`, `ConfigureMethodRepositoryRoutingCommand`,
  `BackfillPayableInstrumentAccountsCommand`, `BackfillTaxDetailsCommand`, `RunEnrichmentCommand`,
  `BackfillLocationAttributionCommand`, `BackfillMembershipsCommand`) and
  `tests/Architecture/QueueJobTenantContextTest.php` (`SendEnrichmentFeedbackJob`,
  `SendBrandMappingJob`). Confirmed pre-existing:
  `git diff --stat 260371b12..9949a4e95` touches none of them, and
  `tests/Architecture/fixtures/console-command-deferrals.json` has been `[]` since `d313af0a2`.
* **Tests run GREEN.** 25 tests / 107 assertions
  (`TenantScopedCommandForEachTenantTest` + `LockExpiredFiscalPeriodsCommandTest` +
  `DetectFraudPatternsCommandTest`); 142 tests / 478 assertions
  (`tests/Feature/Channel` + `tests/Feature/PlatformIntegration` +
  `tests/Unit/Modules/PlatformIntegration`).
* **The suite is SQLite + compat mode** (`apps/api/phpunit.xml:41-46`:
  `DB_CONNECTION=sqlite`, `TENANCY_DB_PER_TENANT=false`). Every PostgreSQL-only and
  db-per-tenant-only behaviour in this wave is therefore unverifiable by the suite. B1 and B2
  both live in exactly that blind spot.

---

## BLOCKERS

### [Critical] B1 — Unauthenticated 500 on any non-UUID `{channelId}`; no throttle, no route constraint

`apps/api/app/Modules/Channel/Presentation/Controllers/ChannelWebhookController.php:75`
passes the raw route segment straight into
`apps/api/app/Modules/Channel/Infrastructure/Directory/ChannelWebhookDirectoryRegistrar.php:69-72`:

```php
$tenantId = ChannelWebhookDirectoryEntry::query()
    ->whereKey($channelId)          // <- raw route param
    ->value('tenant_id');
```

`channel_webhook_directory.channel_id` is a PostgreSQL `uuid` column
(`apps/api/database/migrations/2026_08_05_000001_create_channel_webhook_directory_table.php:35`).
`where channel_id = 'garbage'` raises `SQLSTATE[22P02] invalid input syntax for type uuid`.
Nothing catches it — `apps/api/bootstrap/app.php` registers no `QueryException` render callback —
so it is a **500**.

Reachability, all confirmed by reading:
* Route has **no** uuid constraint: `apps/api/app/Modules/Channel/Presentation/routes.php:17`
  is a bare `Route::post('{channelId}', ChannelWebhookController::class)`; there is no global
  `Route::pattern` anywhere in `app/`, `bootstrap/`, `routes/`.
* Route has **no rate limiting**: the group is `['api']`
  (`routes.php:15`) and `apps/api/bootstrap/app.php:47-110` never calls `throttleApi()` nor
  prepends a `throttle:` middleware to the `api` group.
* The endpoint is unauthenticated by design (external sales platforms call it).

So any anonymous caller can drive unbounded 500s / Sentry events with
`POST /api/v1/webhooks/channels/x` + an `X-Channel-Timestamp` header. This is precisely the
repo's own recorded pitfall ("validate `Str::isUuid()` before `where('uuid', $val)` or it 500s")
and the SQLite suite structurally cannot catch it — `ChannelWebhookTenantResolutionTest` only
ever posts `(string) Str::uuid()`.

**Fix:** guard before the lookup, and belt-and-braces the route:

```php
if (! Str::isUuid($channelId)) {
    throw new NotFoundHttpException('Unknown channel.');   // same fail-closed answer
}
```
plus `->where('channelId', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}')`
and a `throttle:` middleware on `routes.php:14-19`. Add a test that posts a non-UUID and asserts 404.

*(Note: `Channel::query()->findOrFail($channelId)` at `ChannelWebhookController.php:89` has the
same shape but is unreachable with a bad id once the guard above lands.)*

### [Important] B2 — `enrichment:check-pending` is the ONLY conversion with no compat-mode tenant predicate

`apps/api/app/Modules/PlatformIntegration/Application/Commands/CheckPendingEnrichmentsCommand.php:70`
calls `findPendingEnrichments(limit: 50, staleMinutes: 10)`, whose implementation
(`apps/api/app/Modules/Product/Infrastructure/Services/ProductEnrichmentQueryService.php:20-24`)
is a **bare `Product::query()`** with no tenant scoping at all.

Under `tenancy_resolver.db_per_tenant=false` — the pre-flip compat mode **and the mode the whole
test suite runs in** — `forEachTenant()` runs the closure once per tenant against ONE shared
database without switching. So the same ≤50 products are polled once per tenant: N× outbound
platform HTTP calls per 15-minute tick, and the docblock's headline claim at lines 38-42
("`limit: 50` is now a PER-TENANT budget… one noisy tenant can no longer starve the rest of the
fleet") is simply false in that mode.

This is inconsistent with its own sibling commits, which explicitly state the predicate is
**required**:
* `apps/api/app/Console/Commands/DetectFraudPatterns.php` — `Company::query()->where('tenant_id', $tenant->id)`
* `apps/api/app/Modules/Channel/Infrastructure/Commands/ChannelReconcileCommand.php:73-78` —
  `whereHas('company', fn ($q) => $q->where('tenant_id', $tenant->id))`

`PendingEnrichmentDTO` already carries `tenantId`, so the filter is free (either scope the query
or skip DTOs whose `tenantId !== $tenant->id`).

### [Important] B3 — Missing deploy step: existing channels are unroutable until 03:30

`php artisan migrate` creates `channel_webhook_directory` **empty**. Only the observer
(`ChannelServiceProvider.php:35`) fills it going forward; every channel that already exists gets
its pointer from the nightly `channels:reconcile` self-heal
(`ChannelReconcileCommand.php:80-88`). Between deploy and 03:30 every pre-existing channel's
webhook returns a fail-closed 404. Not a regression (they were 500ing), but the audit record
`9949a4e95` documents the self-heal without ever saying to run it at deploy time.

**Fix:** add `php artisan channels:reconcile` (or `tenants:run channels:reconcile`) to the
post-migrate deploy checklist for this release.

---

## REQUIRED

### [Important] R1 — The compat-mode predicates silently DROP data when `companies.tenant_id` has drifted

`companies.tenant_id` exists in the tenant database too
(`apps/api/database/migrations/tenant/2025_11_30_104000_create_companies_table.php:26`), so under
db-per-tenant the predicate is redundant *if the column is correct*. When it is not — a tenant DB
restored from another tenant's dump, a seeder default, a mis-provisioned tenant — the row is now
**silently excluded** where the pre-conversion `Company::all()` / `Channel::query()` inside the
tenant DB would have picked it up. There is no log line.

For `ChannelReconcileCommand` the consequence compounds: a drifted channel is never reconciled
**and** never gets a webhook-directory pointer, so its webhooks 404 forever with no signal.

**Fix:** under `db_per_tenant`, compare the unfiltered row count to the filtered one and
`Log::warning` the delta with the offending ids.

### [Important] R2 — The central directory has no prune leg

`ChannelReconcileCommand.php:80-88` only ever `register()`s. Nothing deletes rows for channels
removed outside Eloquent (raw SQL, `truncate`, a tenant DB restored to a pre-channel snapshot) or
for deprovisioned tenants. `ChannelWebhookDirectoryRegistrar::forget()` (lines 63-66) is wired to
the model `deleted` event only.

Each stale row buys an anonymous caller a **full tenant-database switch** before the 404:
`ChannelWebhookController.php:87` calls `initializeIfProvisioned()` BEFORE
`Channel::findOrFail()` at `:89`. That partially undoes the commit's own stated reason for
rejecting fan-out ("one forged request costing N database switches").

**Fix:** inside the per-tenant sweep, delete directory rows whose `tenant_id` is this tenant and
whose `channel_id` is not in this tenant's `channels`.

### [Important] R3 — Suspended / Archived tenants are processed by the webhook; the authenticated path rejects them

`ChannelWebhookController.php:87` binds via `TenancyResolver::initializeIfProvisioned()`, which
applies **no status predicate at all**
(`apps/api/app/Modules/Tenant/Application/Services/TenancyResolver.php:51-95` — the only gate is
database existence). The authenticated request path explicitly rejects them:
`apps/api/app/Modules/Identity/Presentation/Middleware/ResolveTenancy.php:65-69`
(`in_array($tenant->status, [TenantStatus::Suspended, TenantStatus::Archived], true)`).

So an unauthenticated external callback keeps ingesting orders into a suspended (non-paying)
tenant while its own users are locked out. That may be the intended call — it matches
`BindsTenantContext`'s documented "suspension is reversible, the database is preserved on
purpose" stance — but nothing in the new code states it, and it is a policy divergence between
two entry points into the same tenant.

**Fix:** decide explicitly, document it on `ChannelWebhookController`, and if suspension should
stop ingestion, reject with the same fail-closed 404 (never a distinguishable status).

### [Important] R4 — Foreground + `withoutOverlapping(30)` sizing is unstated

`withoutOverlapping(30)` is a mutex **expiry**, not a run-time cap: once a run exceeds 30 minutes
the next tick starts **concurrently**.

* `fraud:detect` (`apps/api/routes/console.php:51-56`) now analyses every company of every
  tenant, in-process, with `runInBackground()` deliberately removed. Overlap ⇒ duplicate fraud
  alerts and duplicate fraud-triggered stock counting.
* `enrichment:check-pending` (`apps/api/routes/console.php:172-176`) now makes up to 50
  **synchronous outbound HTTP calls per tenant** every 15 minutes, also in-process
  (`CheckPendingEnrichmentsCommand.php:87` `checkStatusRaw`). At N tenants the wall clock is
  N × 50 × RTT against a 30-minute expiry and a 15-minute cadence.

The `runInBackground()` removals are justified as "observe the exit code inline" while the same
comments concede `onFailure()` fires in both modes — so the trade buys little and costs real
serialisation. At one live tenant none of this bites; the sizing assumption should be recorded
(or the poller fanned out to the queue) before the fleet grows.

### [Important] R5 — `fraud:detect` overwrites an infra FAILURE with INVALID

`apps/api/app/Console/Commands/DetectFraudPatterns.php` — the tail block returns `self::INVALID`
(2) whenever `--company` was supplied and `$analysedCompanies === 0`, **discarding** an aggregate
`FAILURE` (1) that `forEachTenant()` may have produced from a probe throw. An operator whose
target tenant's database was unreachable is told "Company X was not found in any reachable
tenant" (exit 2) instead of the infra failure (exit 1). The message does point at the log, so
signal is degraded rather than lost.

**Fix:** only return `INVALID` when `$exit === self::SUCCESS`.

---

## MINOR

* **M1 — existence oracle in the third 404 shape.** `ChannelWebhookController.php:78` and `:84`
  both emit `NotFoundHttpException('Unknown channel.')` — identical, as the comment promises.
  But directory row + live tenant + missing channel row falls through to
  `Channel::findOrFail()` at `:89` → `ModelNotFoundException` → the handler in
  `apps/api/bootstrap/app.php` renders `{"error":{"code":"NOT_FOUND","message":"… Channel …"}}`.
  Different body ⇒ distinguishable, contradicting "the caller must not be able to tell them apart".
* **M2 — registrar docblock overclaims.** `ChannelWebhookDirectoryRegistrar.php:29-36` says a
  failure "must never be the reason a channel cannot be created", but only the
  tenant-unresolvable branch (lines 47-55) is soft. A central-connection fault inside
  `updateOrCreate` (lines 57-60) propagates and WILL abort the channel create/transaction. Wrap
  it or correct the docblock.
* **M3 — probe-throw log amplification.** `TenantScopedCommand.php:227-233` emits one `ERROR`
  **per tenant**. A central outage after `Tenant::all()` materialises produces N error lines +
  N Sentry events per tick per scheduled command. Consider first-N / aggregate-once.
* **M4 — duplicated helper.** `DetectFraudPatterns::stringOption()` is a verbatim copy of the
  private `TenantScopedCommand::stringOption()` (`TenantScopedCommand.php:376-381`). Promote the
  base one to `protected`.
* **M5 — tenancy never ended in the webhook controller.** After `initializeIfProvisioned()` at
  `ChannelWebhookController.php:87` there is no `tenancy()->end()`. Harmless under FPM; would
  leak under Octane. `laravel/octane` is not in `composer.json`, so informational only.
* **M6 — the platform's `tenant_id` is trusted verbatim.** Nothing checks that the anchored
  tenant actually owns `tracking_id`. Physical isolation contains the blast radius (a wrong
  anchor lands on the `ModelNotFoundException` "unknown tracking id" path), but the platform
  contract ticket should say the ERP treats the field as authoritative.
* **M7 — `Tenant::find($this->tenantId)` takes an unvalidated string into a uuid PK.**
  `apps/api/app/Jobs/Concerns/BindsTenantContext.php:78`. Signature-gated, so only a buggy or
  compromised platform reaches it, but a malformed value is a `QueryException` retry storm
  rather than a clean discard. Guard with `Str::isUuid()` and discard-with-warning.

---

## What I verified as CORRECT (the eight attack questions, answered)

**1. N1 fail-closed probe — CORRECT, and the INVALID-vs-FAILURE distinction survives.**
Probe RETURNS false ⇒ `skippedTenantIds` + WARNING, aggregate untouched
(`TenantScopedCommand.php:242-252`). Probe THROWS ⇒ `skippedTenantIds` + ERROR + aggregate
FAILURE (`:217-240`). Conflating both into `skippedTenantIds` is right, because
`failIfTenantFilterUnvisited()` (`:334-356`) keeps the distinction that actually matters:
**FAILURE (1)** for anything in `skippedTenantIds` (either sub-case), **INVALID (2)** only for
"absent from the central directory". INVALID therefore stays reserved for operator input error,
which is the correct semantics, and the emitted wording ("does not exist or could not be
opened") honestly covers both sub-cases.
Central-connection loss: dies **before** `Tenant::all()` (`:214`) ⇒ the command throws ⇒ Artisan
exit 1 ⇒ `onFailure()` fires; dies **after** ⇒ every probe throws ⇒ aggregate FAILURE (1) ⇒
`onFailure()` fires. Both claimed behaviours confirmed.
Pinned by `test_a_throwing_database_probe_is_a_failure_not_a_silent_skip` and
`test_a_throwing_database_probe_makes_a_tenant_filter_fail_loudly`, with the fault injected
realistically (`config(['tenancy.database.managers' => []])`, the same shape as
`DatabaseManagerNotRegisteredException`) and an assertion that exactly ONE log line at level
`error` is produced per tenant. N2 (status parameterised through the openable tenant) and N5
(`Tests\Traits\ProvisionsTenantDatabases`) are both real fixes.

**2c. The central migration is genuinely central and prerequisite-free.**
`apps/api/database/migrations/2026_08_05_000001_create_channel_webhook_directory_table.php` sits
in the ROOT migrations directory; `apps/api/config/tenancy.php:195-199` pins `tenants:migrate*`
to `database_path('migrations/tenant')` only, so it can never run against a tenant DB.
`apps/api/docker/entrypoint.sh:123` runs `php artisan migrate --force` (default connection =
`central` in prod per `config/database.php:20` + the `central` block at `:124-140`) **before**
`tenants:migrate-rolling` at `:141`. The migration is a pure `Schema::create` with no data
dependency ⇒ staging auto-deploy is safe. (Deploy still owes B3.)

**2a. The central directory is the right call, and it leaks nothing meaningful.** The channel id
is the only identifier the request carries; the signature cannot be checked first because
verification needs the channel's adapter + credentials, i.e. the same unreadable tenant row. The
table holds `channel_id → tenant_id` and nothing else. Channel ids are UUIDv4 (`HasUuids` on
`apps/api/app/Modules/Channel/Domain/Models/Channel.php:26`) so they are not enumerable, and the
404 does not distinguish unknown-id from dead-tenant (see M1 for the one shape that does).
Signature-verification ORDER is acceptable: the cheap `X-Channel-Timestamp` freshness check runs
first (`ChannelWebhookController.php:67-70`), and an attacker without a valid channel id gets one
indexed central PK lookup, not a DB switch. The DB-switch-before-signature cost is only reachable
with a real channel id — see R2/B1 for the two ways to make it cheaper than intended.

**3. The `unserialize()` claim is CORRECT.** A promoted constructor property's default lives on
the *parameter*, never in the class's `default_properties_table`, so `unserialize()` — which
does not call the constructor — leaves it uninitialized; a class-level declared default is
applied at object init and survives. Both
`apps/api/app/Modules/PlatformIntegration/Application/DTOs/EnrichmentWebhookPayload.php:31` and
`.../Jobs/ProcessEnrichmentWebhookJob.php` (`public ?string $tenantId = null;`) follow the rule.
Pinned by `test_a_legacy_payload_without_the_anchor_restores_as_null_instead_of_fatalling`, which
additionally asserts the serialized job carries `payload` **and nothing else** (regex on the
serialized string) and round-trips a zero-property DTO.
**No event class is LOST if the platform never adopts the contract**, with one narrow exception:
the poller re-polls `Pending`/`Enriching` products with a non-null `platform_submission_id` and
`updated_at < now()-10min` (`ProductEnrichmentQueryService.php:20-24`) and re-dispatches the
identical `EnrichmentWebhookReceived`, so ordinary results arrive late, not never. A webhook for
a product whose local status is **already terminal** (a re-enrichment echo, or a
Rejected-after-review) never enters the poller's window and IS dropped — worth a line in
`docs/superpowers/tickets/2026-08-05-enrichment-webhook-platform-contract.md`.
The listener guard (`apps/api/app/Modules/Product/Application/Listeners/ProcessEnrichmentEventListener.php:44-52`)
throws a `RuntimeException` **before** any query, so it stays retryable, and the `catch` is
correctly NOT widened to `QueryException`. It is deterministic, so it will burn its retries into
`failed_jobs` — that is the intended fail-loud shape.

**4. `fiscal:lock-expired-periods` idempotency — VERIFIED; nothing is double-locked or skipped.**
`apps/api/app/Modules/Company/Application/Services/FiscalPeriodAutoLockService.php:89-131`:
STEP 1 `status=Open AND end_date<cutoff → Closed`; STEP 2 `is_closed=false AND end_date<today →
true`; STEP 3 `fiscal_year_id IN closed AND status IN (Open,Closed) → Locked`. Every predicate
excludes its own output, so a second pass changes zero rows. Under db-per-tenant each tenant DB
is visited exactly once; under compat the first tenant's pass sweeps the fleet and passes 2..N
are no-ops. A tenant whose probe throws is skipped **and** raises the aggregate ⇒ `onFailure()`
fires, so "skipped" is never silent. Removing the `catch (\Exception)` is a strict improvement:
the swallow was what converted the fleet-wide 42P01 into an unobserved FAILURE exit.

**5. `fraud:detect`'s `tenant_id` predicate — correct, not a regression** (modulo R1). The column
exists in the tenant DB, so it is redundant-but-harmless under db-per-tenant and **required**
under compat, where one shared DB would otherwise let every tenant's pass re-alert on every other
tenant's companies.

**6. `enrichment:check-pending` registration — FIXED and genuinely pinned.**
`PlatformIntegrationServiceProvider::boot()` now calls
`$this->commands([CheckPendingEnrichmentsCommand::class])` behind `runningInConsole()`, and
`test_the_command_is_a_registered_artisan_command` asserts
`Artisan::all()` has the `enrichment:check-pending` key. The test file's own note is right that
the `schedule:list` assertion alone would NOT have caught it — `Schedule::command()` takes an
unvalidated string. This was a real finding beyond the audit.

**7. `channels:reconcile` — reasoning holds on both counts.** `withoutOverlapping(30)`: the bare
default is 1440 minutes, exactly the `dailyAt()` gap, so a crashed process swallows the next
night's run entirely — the cap is correct (see R4 for the upper bound). Dropping
`Schema::hasTable('channels')` is also correct: `channels` is a tenant migration
(`apps/api/database/migrations/tenant/2026_05_24_120000_create_channels_table.php`) that every
tenant DB receives, and compat mode has one shared DB that also receives it, so a missing table
means a **mis-migrated tenant**, not "nothing to do". The only environment where it is
legitimately absent is a tenant mid-`tenants:migrate-rolling` at 03:30 — which now yields one
tenant FAILURE plus an `onFailure()` alert, i.e. the correct loud answer.

**8. Compat mode across all six conversions — 5 of 6 fine.** fiscal (fleet-wide × N, idempotent,
documented); fraud (tenant_id predicate); channels:reconcile (company.tenant_id predicate);
channel webhook (`initializeIfProvisioned()` returns false without switching, `findOrFail` reads
the shared DB, and the observer still populates the directory because the registrar reads
`companies.tenant_id` off the shared connection); enrichment webhook job
(`withTenantContext` exercised green in the compat suite,
`EnrichmentWebhookTenantContextTest::test_the_job_binds_the_anchored_tenant_before_the_listener_runs`).
The sixth is **B2**.

**9. Iteration guards — three are REAL, one is partly decorative.** Genuinely go red on a revert
to fleet-wide queries:
`LockExpiredFiscalPeriodsCommandTest::test_nothing_is_locked_when_the_tenant_directory_is_empty`
(orphan fiscal data untouched with an empty directory),
`DetectFraudPatternsCommandTest::test_a_company_whose_tenant_is_absent_from_the_directory_is_never_analysed`,
`ChannelReconcileCommandTest::test_a_channel_whose_tenant_is_absent_from_the_directory_is_never_dispatched`.
Weaker: `CheckPendingEnrichmentsCommandTest` binds a spy that returns an **empty** collection, so
its guards only pin the CALL COUNT (2 tenants ⇒ 2 calls, 0 tenants ⇒ 0 calls). A revert to one
fleet-wide call does go red — but **no test can go red on B2**, because no real product row is
ever read. The file's own NOTE already concedes tenancy is not asserted (compat suite).

---

## What to fix before merge

Guard `{channelId}` with `Str::isUuid()` (+ route uuid constraint + throttle) before the central
directory lookup — B1; scope `findPendingEnrichments()` per tenant — B2; add
`channels:reconcile` to the post-migrate deploy checklist — B3.

---

## DISPOSITION — fix round, 2026-08-05 (local `dev`)

Every finding addressed. Wave 2 (`dbc5476aa..61189c8b8`) had already landed when this round
started; `forEachTenant()` is still byte-identical to the reviewed version apart from the M3 log
cap below, so the review's analysis held. The 23 transient `larastan.console.undefinedOption`
errors the reviewer saw mid-wave-2 are gone — PHPStan is clean on every changed path.

| # | Disposition | Where |
|---|---|---|
| **B1** | **FIXED**, all three layers. `->whereUuid('channelId')` on the route; `Str::isUuid()` in both `ChannelWebhookController` and `ChannelWebhookDirectoryRegistrar::resolveTenantId()` (a malformed id gets the SAME fail-closed 404 as an unused one); `throttle:channel-webhook` = 60/min/IP. The suite is SQLite, which compares a TEXT primary key to `'garbage'` happily, so the regression tests pin what actually goes red: the malformed id never reaches the directory QUERY, the route carries the constraint, the route carries the named throttle, and the 61st request is a 429. | `cfb9e9280` |
| **B2** | **FIXED**, and made testable. `findPendingEnrichments()` takes a REQUIRED `tenantId` (not an optional one — a default is how this call site was missed) and applies the predicate BEFORE `limit`. The reviewer's "no test can go red on B2" is closed in two places: the service test seeds two tenants' pending products in the shared database (verified red with the predicate removed), and the command spy now records the tenant id it was handed. The false docblock claim is replaced with the correction. | `686245cdd` |
| **B3** | **CODE SIDE N/A — documented + ticketed.** The step is recorded in the audit doc's wave-1 section with the `tenants:run` trap, the exit-code gate and a verification query. The runbook line itself is owed by the release owner: `STAGING-RUNBOOK-first-tenant-2026-07-31.md` is the authoritative Phase-E gate E-9 document (owner-executed) and `STAGING-DEPLOY-RUNBOOK-2026-07-28.md` is superseded historical record marked "do not execute it separately" — this session edits neither. | `docs/superpowers/tickets/2026-08-05-channels-reconcile-post-migrate-deploy-step.md` |
| **R1** | **FIXED.** New `WarnsOnTenantScopeDrift` concern compares the tenant database's unfiltered count to the predicate's count and WARNs the delta with the offending ids (capped at 20). Inert under compat mode — the probes are closures, so nothing is even queried there — and the id probe only runs once a delta exists. Wired into `ChannelReconcileCommand` and `DetectFraudPatterns`; unit-tested directly, because the behaviour needs `db_per_tenant=true`, which the SQLite feature suite cannot drive against real tenant tables. | `e656c0e01` |
| **R2** | **FIXED**, both legs. Per tenant: prune pointers whose channel id is absent from that tenant's `channels`, AFTER the self-heal `register()` and only when the enumeration completed (a mid-enumeration throw leaves the tenant's pointers alone rather than deleting against a partial list; an unreached tenant never opens a slot at all). Once, outside the iteration: prune pointers whose `tenant_id` is absent from central `tenants`, as a subquery so a failing central read raises instead of deleting. | `6e5d176bd` |
| **R3** | **DECIDED: PROCESS.** Webhooks for a **suspended** tenant are ingested, deliberately. Machine traffic is not redelivered — an external platform that gets a 404 drops the order permanently — and suspension is a *reversible commercial lever* whose database is preserved on purpose, so refusing ingestion would destroy data the tenant is entitled to on reinstatement. Locking the tenant's *users* out (`ResolveTenancy`, control plane) while its *machine* traffic keeps landing (data plane) is the intended asymmetry. A tenant whose ingestion must genuinely stop is deprovisioned (central row gone) or has its database taken offline — both already fail closed. Documented on `TenancyResolver` and on `ChannelWebhookController`. | `2a248521e`, `cfb9e9280` |
| **R4** | **FIXED.** Every "caps the mutex" comment corrected — it is an EXPIRY; past it the lock evaporates and the next tick runs CONCURRENTLY. The daily per-tenant iterators (`channels:reconcile`, `fraud:detect`, `fiscal:lock-expired-periods`) go 30 → **720**: above any plausible fleet runtime, at half the 24-hour gap so a crashed process still cannot swallow the next night. `enrichment:check-pending` keeps 30 (two ticks) with its real bound recorded — N × 50 synchronous outbound calls in-process, where the fix past that scale is fanning out to the queue, NOT raising the expiry. Marketplace entries deliberately untouched (2026-08-04 wave, not this one). | `2a248521e` |
| **R5** | **FIXED.** `fraud:detect` returns `INVALID` only when the iteration itself was clean; an aggregate `FAILURE` now survives. Same split `failIfTenantFilterUnvisited()` keeps for `--tenant`. Pinned with the fault injected the realistic way (no database manager registered for the driver). | `a4e2b5703` |
| **M1** | **FIXED.** `Channel::findOrFail()` → `find()` + the same `NotFoundHttpException('Unknown channel.')`. All four fail-closed 404s are now byte-identical, asserted by comparing the response bodies of unknown-id / dead-tenant / missing-channel-row. | `cfb9e9280` |
| **M2** | **FIXED.** The central `updateOrCreate` is wrapped and logged; the registrar now actually delivers what its docblock promised, so a central fault can no longer abort a channel create from inside the model observer. | `cfb9e9280` |
| **M3** | **FIXED.** Individual probe-fault ERROR lines capped at 3, remainder collapsed into one aggregate ERROR with the totals. Exit code, skipped list and `onFailure()` unchanged. Pinned with a 6-tenant fleet-wide fault. | `2a248521e` |
| **M4** | **FIXED.** `TenantScopedCommand::stringOption()` promoted to `protected` and fifteen verbatim subclass copies deleted (verified identical first; PHP forbids narrowing an inherited protected method to private, so promoting *required* removing them). `RunEnrichmentCommand` / `SweepInventoryGenerateCommand` keep theirs — they extend `Command`. | `a4e2b5703` |
| **M5** | **FIXED** rather than left informational. The controller releases the binding it opened, in a `finally`. | `cfb9e9280` |
| **M6** | **DOCUMENTED** in the platform-contract ticket: the ERP treats `tenant_id` as authoritative and cannot cross-check it, so the platform must echo `X-Tenant-Id` verbatim. Physical isolation keeps a wrong anchor on the "unknown tracking id" path. The same edit records the review's other open note — a webhook for a product whose local status is already terminal never enters the poller's window and IS dropped, so "delayed, not lost" has exactly one exception. | `docs/superpowers/tickets/2026-08-05-enrichment-webhook-platform-contract.md` |
| **M7** | **FIXED.** `Str::isUuid()` guard in `BindsTenantContext` turns a 22P02 retry storm into one deterministic, self-describing failure. It immediately caught a real fixture defect: `CreatesChannelSchema` seeded `tenants.id = 'tenant-1'`, which only ever resolved because that trait hand-rolls the table with a string id — it had been exercising jobs with an anchor production would reject. | `2a248521e` |

**Verification:** `tests/Feature/Channel` (44), `tests/Feature/PlatformIntegration`,
`tests/Feature/Compliance/DetectFraudPatternsCommandTest`, `tests/Unit/Console`,
`tests/Unit/Jobs`, `tests/Unit/Shared/ProductEnrichmentQueryServiceTest`,
`tests/Feature/Security/FirstTenantProductionSecurityTest`, `tests/Feature/Jobs`,
`tests/Feature/Marketplace` — all green. `pint --dirty` clean. PHPStan clean on every changed
`app/` path. `tests/Architecture` stash-baseline **unchanged** — the same 5 pre-existing failures
before and after (the 2 the reviewer named, plus `AuthLifecycleTest`,
`ControllerTenantContextTest`, `InventoryCostLockCoverageTest`).
