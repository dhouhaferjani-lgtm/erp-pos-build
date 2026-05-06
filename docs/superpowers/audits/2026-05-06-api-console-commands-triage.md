# `api.console-commands` triage — Artisan command classification

> Audit date: 2026-05-06
> Author: Claude (orchestrator) for cluster `api.console-commands`
> Branch: `feat/tenant-isolation-sweep-execution` @ `d259232e`
> Master plan: `docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md` §14
> Section 9 grammar (`@cross-tenant-by-design`) governs cat-(b) annotations.

## Method

Filtered `apps/api/app/Console/Commands/*.php` and `apps/api/app/Modules/**/Commands/*.php`
to classes that `extends Illuminate\Console\Command` (directly or via an abstract base).
The `app/Modules/*/Application/Commands/*.php` files outside the list below are
domain-tier command-bus DTOs (e.g. `Workshop/WorkOrder/.../AddLineCommand.php`) — they
do NOT extend `Illuminate\Console\Command` and are out of scope.

Each concrete class is classified into:
- **(a)** Per-tenant operation that must extend the new `TenantScopedCommand`. Two sub-shapes:
  - **(a-singleshot)**: takes validated `--tenant=<id>` + `--company=<id>` and runs once under that bound context.
  - **(a-per-tenant-iter)**: scheduler / batch command that must wrap its body in `Tenant::all()->each(fn ($t) => $t->run(…))` per master plan §14 invariant 2 (no cross-tenant queries from the command body or from the dispatched jobs).
- **(b)** Cross-tenant by design: carries class-level PHPDoc `@cross-tenant-by-design <non-empty justification>` per master plan §9.
- **(deferred-to-pos)**: lives under `apps/api/app/Modules/POS/`; classification deferred to the `api.pos-stabilization` cluster per the POS surface invariant.

The abstract base `AbstractSweepInventoryCommand` is skipped (cannot be instantiated; the
arch test must filter `\ReflectionClass::isAbstract()`).

## Triage table

| # | Class | Path | Signature | Category | Justification (cat-b) / Notes |
|---|-------|------|-----------|----------|--------------------------------|
| 1 | `AbstractSweepInventoryCommand` | `app/Console/Commands/AbstractSweepInventoryCommand.php` | (abstract base) | **skip** | abstract — arch test must filter |
| 2 | `BackfillFiscalYears` | `app/Console/Commands/BackfillFiscalYears.php` | `fiscal-years:backfill` | **(b)** | Iterates `Company::doesntHave('fiscalYears')` fleet-wide; `--company` is an optional narrowing filter. Pre-deploy backfill task. |
| 3 | `DetectFraudPatterns` | `app/Console/Commands/DetectFraudPatterns.php` | `fraud:detect` | **(b)** | Daily scheduled fleet-wide fraud detection; iterates `Company::all()` to flag draft-abandonment patterns across tenants. |
| 4 | `FixOrphanedProducts` | `app/Console/Commands/FixOrphanedProducts.php` | `import:fix-orphaned-products` | **(b)** | **Confirmed (Task 2):** issues a single cross-tenant `Product::query()->leftJoin('stock_levels', …)->whereNull('stock_levels.id')` (`FixOrphanedProducts.php:37-45`). Per-product remediation is scoped by reading the product's own `company_id` field (`FixOrphanedProducts.php:67-76`). Justification: `Maintenance task that repairs products lacking stock_levels; the fleet-wide leftJoin scan is the design (orphan products may exist across any tenant) and per-product remediation is anchored on the product's own company_id field.` |
| 5 | `GenerateProductImageVariants` | `app/Console/Commands/GenerateProductImageVariants.php` | `products:generate-image-variants` | **(b)** | **Confirmed (Task 2):** queries `ProductImage::query()` fleet-wide (no tenant/company filter), with optional `--product` narrowing (`GenerateProductImageVariants.php:23-28`). Dispatched `GenerateImageVariants` job receives only `image_id, storage_path, storage_disk` — operates on storage paths, not tenant data, so no context-rebind is needed. Justification: `Maintenance batch that generates WebP variants for ProductImage rows fleet-wide; per-image jobs operate on storage paths only.` |
| 6 | `LockExpiredFiscalPeriodsCommand` | `app/Console/Commands/LockExpiredFiscalPeriodsCommand.php` | `fiscal:lock-expired-periods` | **(b)** | Daily scheduler closing/locking expired fiscal periods across all companies (delegates to `FiscalPeriodAutoLockService`). |
| 7 | `MigrateParapharmacyDataCommand` | `app/Console/Commands/MigrateParapharmacyDataCommand.php` | `parapharmacy:migrate-data` | **(b)** | **Confirmed (Task 2):** one-shot migration walking the entire `ParapharmacyProductMetadata` table (`MigrateParapharmacyDataCommand.php:40-50`). No `--tenant`/`--company` accepted. Writes to translation pivot tables (`product_ingredient`, `key_component_product`, `health_claim_product`, `certification_product`) with the source row's own `product_id` as the anchor. Justification: `One-shot data migration walking the entire ParapharmacyProductMetadata table (cross-tenant by definition) to normalize JSONB fields into pivot tables anchored on each row's own product_id.` |
| 8 | `SweepInventoryBlockCommand` | `app/Console/Commands/SweepInventoryBlockCommand.php` | `sweep:inventory:block` | **(b)** | Sweep tooling — operates on the inventory YAML, not on per-tenant resources. |
| 9 | `SweepInventoryClaimCommand` | `app/Console/Commands/SweepInventoryClaimCommand.php` | `sweep:inventory:claim` | **(b)** | Sweep tooling. |
| 10 | `SweepInventoryDeferCommand` | `app/Console/Commands/SweepInventoryDeferCommand.php` | `sweep:inventory:defer` | **(b)** | Sweep tooling. |
| 11 | `SweepInventoryGenerateCommand` | `app/Console/Commands/SweepInventoryGenerateCommand.php` | `sweep:inventory:generate` | **(b)** | Sweep tooling — runs the five scanners. |
| 12 | `SweepInventoryReviewCommand` | `app/Console/Commands/SweepInventoryReviewCommand.php` | `sweep:inventory:review` | **(b)** | Sweep tooling. |
| 13 | `SweepInventoryStartCommand` | `app/Console/Commands/SweepInventoryStartCommand.php` | `sweep:inventory:start` | **(b)** | Sweep tooling. |
| 14 | `SweepInventoryStatusCommand` | `app/Console/Commands/SweepInventoryStatusCommand.php` | `sweep:inventory:status` | **(b)** | Sweep tooling. |
| 15 | `SweepInventorySubmitCommand` | `app/Console/Commands/SweepInventorySubmitCommand.php` | `sweep:inventory:submit` | **(b)** | Sweep tooling. |
| 16 | `SweepInventoryUnblockCommand` | `app/Console/Commands/SweepInventoryUnblockCommand.php` | `sweep:inventory:unblock` | **(b)** | Sweep tooling. |
| 17 | `SweepInventoryVerifyHistoryCommand` | `app/Console/Commands/SweepInventoryVerifyHistoryCommand.php` | `sweep:inventory:verify-history` | **(b)** | Sweep tooling. |
| 18 | `TestE2EGLPosting` | `app/Console/Commands/TestE2EGLPosting.php` | `test:e2e-gl-posting` | **(b)** | Manual dev/test command using `Tenant::first()` / `Company::first()`; not intended for production runs. |
| 19 | `TestTaxRecoverability` | `app/Console/Commands/TestTaxRecoverability.php` | `test:tax-recoverability` | **(b)** | Manual dev/test command using `Company::where('name', …)`; not for production. |
| 20 | `BackfillFiscalHashesCommand` | `app/Modules/Compliance/Commands/BackfillFiscalHashesCommand.php` | `fiscal:backfill` | **(b)** | One-shot retroactive hash-chain backfill across all companies (`--company` optional). |
| 21 | `VerifyFiscalChainsCommand` | `app/Modules/Compliance/Commands/VerifyFiscalChainsCommand.php` | `fiscal:verify-chains` | **(b)** | Iterates `Company::all()` to verify fiscal chain integrity; CI/audit-grade fleet-wide check. |
| 22 | `ExportNf525JetCommand` | `app/Modules/Compliance/Commands/ExportNf525JetCommand.php` | `nf525:export-jet` | **(a-singleshot)** | **Single-company NF525 JET export.** `--company` is REQUIRED; per-run scope is exactly one company. Add `--tenant` validation + `ScopedExists::tenantAndCompany`; bind `CompanyContext` so the export service runs under that tenant. |
| 23 | `CheckPendingEnrichmentsCommand` | `app/Modules/PlatformIntegration/Application/Commands/CheckPendingEnrichmentsCommand.php` | `enrichment:check-pending` | **(b)** | Polls platform for status updates on the in-flight enrichment outbox; the outbox is fleet-wide by design (each event is dispatched with the platform_submission_id and the listener re-binds tenant context). |
| 24 | `CreateTenantCommand` | `app/Modules/Tenant/Application/Commands/CreateTenantCommand.php` | `tenant:create` | **(b)** | Creates a NEW tenant; the operation is *about* tenants, not bound to one. |
| 25 | `ResetTenantCommand` | `app/Modules/Tenant/Application/Commands/ResetTenantCommand.php` | `tenant:reset` | **(b)** | Tenant lifecycle management (reset by slug); explicitly cited in master plan §14 as a cat-(b) example. Also iterates `Tenant::all()` in the error path. |
| 26 | `AuditDiscountsCommand` | `app/Modules/Treasury/Presentation/Console/AuditDiscountsCommand.php` | `tolerance:audit-discounts` | **(b)** | Pre-deploy CI gate that sweeps the entire `documents`/`document_lines` surface for tolerance violations; cross-tenant by design (the gate must check the whole dataset). |
| 27 | `ScheduleAppointmentReminders` | `app/Modules/Scheduling/Infrastructure/Commands/ScheduleAppointmentReminders.php` | `scheduling:schedule-appointment-reminders` | **(a-per-tenant-iter) [FLIPPED FROM (b)]** | **Task 1 verdict:** the dispatched `DispatchAppointmentReminder` queue job's `handle()` (`DispatchAppointmentReminder.php:44-92`) does NOT rebind tenant context — it calls `AppointmentReminder::query()->find($this->reminderId)` (line 47) without first setting `CompanyContext` or wrapping with `Tenant::find(...)->run(...)`. Per the user's stricter cat-(a) criteria ("any dispatched job operates without rebinding context"), the command flips to cat-(a). Implementation phase MUST wrap the command body in per-tenant iteration AND/OR fix the job to rebind context from the reminder's stored `tenant_id`. Two real implementation actions, not just annotation. |
| 28 | `CheckExpiringCertifications` | `app/Modules/Workshop/Technician/Infrastructure/Commands/CheckExpiringCertifications.php` | `workshop:check-expiring-certifications` | **(a-per-tenant-iter) [FLIPPED FROM (b)]** | **Task 1 verdict:** the repository contract `TechnicianCertificationRepositoryInterface::findExpiringWithin(int $days): Collection` (`TechnicianCertificationRepositoryInterface.php:25`) is unscoped — it returns a cross-tenant collection with no tenant/company arg. Per the user's stricter cat-(a) criteria ("the repository contract is unscoped"), the command flips to cat-(a). The downstream `TechnicianCertificationExpiring` event is dispatched synchronously via `Illuminate\Contracts\Events\Dispatcher` (`CheckExpiringCertifications.php:51`) — synchronous listeners inherit the (unbound) command context, so no rebind happens there either. Implementation phase MUST add a `tenantId`/`companyId` arg to `findExpiringWithin` AND wrap the command body in per-tenant iteration, OR introduce a contract variant that returns per-tenant batches. |
| 29 | `VerifyPosChainCommand` | `app/Modules/POS/Commands/VerifyPosChainCommand.php` | `pos:verify-chains` | **deferred-to-pos** | Lives under `apps/api/app/Modules/POS/`; defer to `api.pos-stabilization` per POS surface invariant. Tracked via `apps/api/tests/Architecture/fixtures/console-command-deferrals.json` and `docs/superpowers/audits/2026-05-06-catalog-pos-cluster-residuals.md`. Likely cat-(b) when handled there (iterates `Terminal::active()` for fiscal hash chain verification — the `--company` option is purely a narrowing filter, the design is fleet-wide chain integrity). |
| 30 | `ExpireHeldOrdersCommand` | `app/Modules/POS/Infrastructure/Commands/ExpireHeldOrdersCommand.php` | `pos:expire-held-orders` | **deferred-to-pos** | **Task 3 verification confirms user's claim:** delegates to `HeldOrderService::expireOrders()` which executes `HeldOrder::where('status', Held)->whereNotNull('expires_at')->where('expires_at', '<', now())->update(['status' => Expired])` (`HeldOrderService.php:153-159`) — a fleet-wide UPDATE with NO `tenant_id` / `company_id` predicate. **Accidentally cross-tenant, not by-design.** When `api.pos-stabilization` picks it up, it should be cat-(a-per-tenant-iter). Tracked in the residuals doc + deferrals fixture. |

## Counts (final)

| Bucket | Count |
|---|---|
| Total Artisan command classes found | **30** |
| Abstract (skipped by arch test) | 1 |
| Concrete classifiable | 29 |
| **cat-(a)** — per-tenant operation | **3** |
| ↳ (a-singleshot) — `--tenant` + `--company` | 1 |
| ↳ (a-per-tenant-iter) — wrap in `Tenant::all()->each(...)` | 2 |
| **cat-(b)** — cross-tenant by design | **24** |
| **deferred-to-pos** | **2** |

## Manual inventory rows planned

### `api.console-commands` cluster — **3 rows** (= cat-(a) count)

| stable_key | file | sub-shape | expected_fix |
|---|---|---|---|
| `manual:api.console-commands:nf525-export-jet` | `apps/api/app/Modules/Compliance/Commands/ExportNf525JetCommand.php` | a-singleshot | Extend `TenantScopedCommand`; validate `--tenant=<uuid>` via `ScopedExists::tenant`, validate `--company=<uuid>` via `ScopedExists::tenantAndCompany`; bind `CompanyContext` for the run. Feature test asserts cross-tenant inputs rejected and same-tenant inputs succeed. |
| `manual:api.console-commands:scheduling-appointment-reminders` | `apps/api/app/Modules/Scheduling/Infrastructure/Commands/ScheduleAppointmentReminders.php` | a-per-tenant-iter | **Two tracks of work**: (1) wrap the command body in `Tenant::all()->each(fn ($t) => $t->run(fn () => …))` so the upcoming-appointment query is bound per-tenant; (2) fix `DispatchAppointmentReminder::handle()` to rebind `CompanyContext` from the reminder's stored `tenant_id`/`company_id` BEFORE calling `find()`. Feature test asserts the scheduler does not leak appointments across tenants and the dispatched job does not silently read across tenants. |
| `manual:api.console-commands:workshop-check-expiring-certifications` | `apps/api/app/Modules/Workshop/Technician/Infrastructure/Commands/CheckExpiringCertifications.php` | a-per-tenant-iter | **Two tracks of work**: (1) tighten the `TechnicianCertificationRepositoryInterface::findExpiringWithin` contract to take a tenant/company id (or return per-tenant batches); (2) wrap the command body in per-tenant iteration so the synchronous `TechnicianCertificationExpiring` listeners run under bound context. Feature test asserts cross-tenant certs are not surfaced when scheduling per a single tenant. |

### `api.pos-stabilization` cluster — **0 manual YAML rows**

POS deferral is handled via the residuals audit doc + a JSON fixture consumed by the
arch test (Step 7); the api.console-commands cluster does NOT mutate the
api.pos-stabilization YAML. See `### POS deferral mechanism` below.

## POS deferral mechanism (residuals doc + JSON fixture)

Per the kickoff Concern-1 amendment + Task 3 directive:

1. A new section `Round-5+ additions from api.console-commands triage (2026-05-06)` is
   appended to `docs/superpowers/audits/2026-05-06-catalog-pos-cluster-residuals.md`
   listing the 2 deferred POS Artisan commands with classification reasons.
2. A two-entry deferrals fixture lives at
   `apps/api/tests/Architecture/fixtures/console-command-deferrals.json`.
3. The arch test (Step 7) reads this fixture and skips the (a)/(b) requirement for
   listed classes.
4. POS surface diff stays empty: the fixture lives under `tests/Architecture/`, not
   under POS module paths, and the audit doc lives under `docs/`.

## Cluster scope (commits planned)

This cluster's net diff lands ENTIRELY outside `apps/api/app/Modules/POS`, `apps/pos`,
and `apps/api/app/Modules/Voucher` (POS surface invariant).

Planned commits, in order:
1. **kickoff (claim + start)** — sweep:inventory state change for api.console-commands; manual rows + history events for the 3 cat-(a) callsites; residuals doc + deferrals JSON fixture committed alongside.
2. **TDD red** — `tests/Feature/Console/ConsoleCommandTenantIsolationTest.php` covering:
    - `nf525:export-jet` cross-tenant denial + same-tenant success.
    - `scheduling:schedule-appointment-reminders` per-tenant isolation (two tenants, one appointment each → exactly the appointment from the bound tenant is scheduled).
    - `workshop:check-expiring-certifications` per-tenant isolation (two tenants, one expiring cert each → only the bound tenant's cert event fires).
3. **TenantScopedCommand base** — `app/Console/TenantScopedCommand.php` (template-method `final handle()` → `protected abstract executeCommand()`). Supports both sub-shapes:
    - (a-singleshot): if subclass declares `--tenant` + `--company` options the base auto-validates + binds `CompanyContext`.
    - (a-per-tenant-iter): the base exposes a helper `protected function forEachTenantCompany(callable $fn): int` that subclasses use inside `executeCommand()`.
4. **ExportNf525JetCommand cat-(a-singleshot) wiring**.
5. **ScheduleAppointmentReminders cat-(a-per-tenant-iter) wiring**, plus `DispatchAppointmentReminder::handle()` context rebind.
6. **CheckExpiringCertifications cat-(a-per-tenant-iter) wiring**, plus `TechnicianCertificationRepositoryInterface::findExpiringWithin` contract change.
7. **cat-(b) annotation sweep** — apply `@cross-tenant-by-design` PHPDoc to all 24 cat-(b) commands using §9 grammar.
8. **Architecture test** — `tests/Architecture/ConsoleCommandTenantContextTest.php` (reads the deferrals JSON fixture; enforces classification on every non-abstract concrete `Illuminate\Console\Command` subclass).
9. **Submit + Codex adversarial review** — submit the 3 callsites, dispatch Codex headless review with the §14 invariants as the contract.

## Step 4.5 — caller-scope verification findings (2026-05-06)

Pre-Step-5 verification per the kickoff: confirm the FULL caller-scope of the two
contract changes the cat-(a) flips imply, plus the cron schedule of the deferred
POS command.

### 4.5.1 — `TechnicianCertificationRepositoryInterface::findExpiringWithin`

`grep -rn 'findExpiringWithin' apps/api/ --include='*.php'`:

| Caller | Path | Type |
|---|---|---|
| `CheckExpiringCertifications::handle` | `apps/api/app/Modules/Workshop/Technician/Infrastructure/Commands/CheckExpiringCertifications.php:44` | **command (in scope)** |
| `EloquentTechnicianCertificationRepository::findExpiringWithin` | `apps/api/app/Modules/Workshop/Technician/Infrastructure/Persistence/EloquentTechnicianCertificationRepository.php:41` | **interface implementation** (not a caller) |
| `ConsoleCommandTenantIsolationTest` | `apps/api/tests/Feature/Console/ConsoleCommandTenantIsolationTest.php` | test (out-of-scope for caller-impact) |

**Result: exactly 1 non-test caller (the command itself). No other code depends on the unscoped form.**

**Recommendation:** tighten the contract directly. Change the signature to
`findExpiringWithin(int $days, string $tenantId): Collection` (tenant-only is
the right anchor since `workshop_technician_certifications` table has only
`tenant_id`, no `company_id` — see migration `2026_04_19_120002_create_workshop_technician_certifications_table.php:22`).
The single caller (`CheckExpiringCertifications`) gets refactored to wrap the
call in per-tenant iteration: `Tenant::all()->each(fn ($t) => $repo->findExpiringWithin($days, $t->id))`.

**Scope impact:** 1 interface signature change + 1 implementation update + 1
command body refactor. No additional manual rows needed; the existing
`api.console-commands.003` row covers it.

### 4.5.2 — `DispatchAppointmentReminder` job dispatchers + tenant anchor

`grep -rn 'DispatchAppointmentReminder' apps/api --include='*.php' | grep -v '/tests/'`:

| Site | Path | Kind |
|---|---|---|
| `use` import + `@see` docblock | `apps/api/app/Modules/Scheduling/Application/Services/AppointmentReminderService.php:12,27` | references only, NOT a dispatch |
| `use` import + `::dispatch($reminder->id)` | `apps/api/app/Modules/Scheduling/Infrastructure/Commands/ScheduleAppointmentReminders.php:12,101` | **only actual dispatcher (in scope)** |
| `final class DispatchAppointmentReminder` | `apps/api/app/Modules/Scheduling/Infrastructure/Jobs/DispatchAppointmentReminder.php:33` | declaration |

**Result: exactly 1 non-test dispatcher (the scheduler command itself).** The job runs ONLY in the scheduler context. After the scheduler refactor binds per-tenant context, the dispatch happens under bound context — but for defense-in-depth, the job should ALSO rebind from its own anchor.

**Tenant anchor confirmation:** `AppointmentReminder.tenant_id` column EXISTS
(migration `2026_04_19_140007_create_scheduling_appointment_reminders_table.php:34`:
`$table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();`).
The job can read `$reminder->tenant_id` and bind context for subsequent reads.

**Recommended fix shape:** Two-track defense-in-depth.

1. **Scheduler-side (load-bearing):** wrap the appointment query + dispatch loop in `Tenant::all()->each(fn ($t) => Tenant::find($t->id)->run(fn () => …))` so each dispatch happens with a bound `CompanyContext`. (Tenancy::run binds the multi-tenancy package's context; for the shared-DB phase we ALSO need to set `CompanyContext::setCompanyId(...)` if a company-scoped helper applies — note that appointments live at the `tenant_id + company_id` grain but dispatched reminders live at `tenant_id` only.)
2. **Job-side (defense-in-depth):** the first lookup is unavoidably by primary key (UUID; globally unique). After loading the reminder, read `$reminder->tenant_id` and bind via `Tenant::find($reminder->tenant_id)?->run(fn () => …)` for subsequent reads (the parent appointment relationship traversal at `DispatchAppointmentReminder.php:59`).

**Scope impact:** 1 scheduler command refactor + 1 job rebind; both stay within the existing `api.console-commands.002` manual row.

### 4.5.3 — `ExpireHeldOrdersCommand` cron schedule (bonus urgency context)

`grep -rnE 'pos:expire-held-orders|ExpireHeldOrdersCommand' apps/api --include='*.php'`:

`apps/api/app/Modules/POS/Providers/HeldOrderServiceProvider.php:40`:
```php
$schedule->command('pos:expire-held-orders')->everyFifteenMinutes();
```

**Cron schedule: every 15 minutes (96 invocations / day).** Each invocation runs
the fleet-wide UPDATE confirmed in Task 3:

```php
return HeldOrder::where('status', HeldOrderStatus::Held)
    ->whereNotNull('expires_at')
    ->where('expires_at', '<', now())
    ->update(['status' => HeldOrderStatus::Expired]);
```

with no tenant_id / company_id predicate.

**Urgency context for the api.pos-stabilization owner:** this is a high-frequency
cross-tenant write that fires constantly in production. While the side effect (status
flip from `held` → `expired` on already-expired orders) is benign in single-tenant
test environments, multi-tenant production fires it 96 times/day across every
tenant simultaneously. Documenting in the residuals doc so the POS owner can
prioritize accordingly.

This is captured in `docs/superpowers/audits/2026-05-06-catalog-pos-cluster-residuals.md`
under the "Round-5+ additions" section.

## Out-of-scope tracker

- **`InventoryService::mutate()` chain-orphan hardening** (audit `2026-05-04-inventory-mutate-orphan-gap.md`, option 1: refuse no-event data change). Standalone ~30-line chore for a future fresh commit; this cluster's manual-row script appends history events per row (the documented safe path).
- **`AbstractSweepInventoryCommand`** is abstract and is excluded from the arch test scan; if a future refactor instantiates it directly, the arch test would need to be revisited.
- **`HeldOrderService::expireOrders()` fleet-wide UPDATE** (`HeldOrderService.php:153-159`) is a real tenant-isolation bug surfaced during Task 3 verification. Out of scope for this cluster (POS module surface). Documented in the residuals doc; api.pos-stabilization owner picks it up.
