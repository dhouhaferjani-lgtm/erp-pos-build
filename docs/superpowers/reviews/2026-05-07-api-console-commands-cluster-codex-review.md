# Codex adversarial review - api.console-commands cluster (Section 14)

Review date: 2026-05-07
Branch tip reviewed: ff7ebbc5
Reviewer: codex (cross-agent review of Claude's implementation)

Verdict: REQUEST-CHANGES
Commit reviewed: ff7ebbc5

## Verdict
REQUEST-CHANGES. The classification architecture and most of the cat-(a) rewiring are sound, but `api.console-commands.002` is not fully closed: the scheduler still reaches an unscoped `scheduling_appointment_reminders` read through `AppointmentReminderService::scheduleFor()`, and the regression test misses that branch because its seeded appointment is only 20 hours out, causing `scheduleFor()` to return before the read.

## Per-callsite verification
| Callsite | Status | Evidence |
|---|---|---|
| `api.console-commands.001` / `nf525:export-jet` | Closed | `ExportNf525JetCommand` extends `TenantScopedCommand`, declares required `--tenant` + `--company` options, calls `bindTenantAndCompanyFromOptions()` before export, and relies on `CompanyContext::requireCompanyId()` for the export service run. Feature tests cover matching and cross-tenant pairs. |
| `api.console-commands.002` / `scheduling:schedule-appointment-reminders` | Not fully closed | Command-level appointment and due-reminder reads are scoped by `tenant_id`, and `DispatchAppointmentReminder` now carries `(reminderId, tenantId)` and scopes reminder + appointment reads. However `ScheduleAppointmentReminders::scheduleUpcomingForTenant()` calls `AppointmentReminderService::scheduleFor()`, whose existing-reminder lookup lacks `tenant_id`. |
| `api.console-commands.003` / `workshop:check-expiring-certifications` | Closed | Command extends `TenantScopedCommand`, iterates tenants, calls `findExpiringWithin($days, $tenant->id)`, and the Eloquent implementation filters `workshop_technician_certifications.tenant_id`. Tenant-only scope matches the migration. |

## TenantScopedCommand base verification
`TenantScopedCommand` uses a `final handle()` template method and delegates to `executeCommand()`, so subclasses cannot bypass the base by overriding `handle()`. `bindTenantAndCompanyFromOptions()` rejects missing options, validates `tenant` via `Rule::exists('tenants', 'id')`, validates `company` via `ScopedExists::tenant('companies', $tenantId)`, and binds `CompanyContext`; `CompanyContext` is registered as a singleton in `AppServiceProvider`, so native Laravel constructor injection resolves. `forEachTenant()` aggregates the first non-success exit code. One design note: it does not call tenancy-package `run()`, so the invariant relies on explicit `tenant_id` predicates in each closure.

## cat-(b) annotation audit
Verified the 24 non-POS cat-(b) annotations plus the parallel POS annotation present at `ff7ebbc5`. The justifications are specific and load-bearing: they name the fleet-wide scan, maintenance/audit lifecycle operation, sweep metadata operation, or tenant-lifecycle reason. No boilerplate-only annotations found.

## Architecture test verification
`ConsoleCommandTenantContextTest::loadDeferralsFresh()` reads `tests/Architecture/fixtures/console-command-deferrals.json` inside the test method path, not at class-load. Discovery filters abstract classes with `ReflectionClass::isAbstract()`, so `AbstractSweepInventoryCommand` is correctly excluded while its concrete sweep subclasses carry their own annotations.

## Caller-scope verification
Re-verified `findExpiringWithin`: exactly one non-test caller, `CheckExpiringCertifications`, plus the interface and Eloquent implementation. Re-verified `DispatchAppointmentReminder`: exactly one non-test dispatcher, `ScheduleAppointmentReminders::dispatchDueRemindersForTenant()`, and scheduling tests instantiate the two-arg constructor directly. `AppointmentReminder.tenant_id` exists in `2026_04_19_140007_create_scheduling_appointment_reminders_table.php`.

## Findings
1. BLOCKER - `apps/api/app/Modules/Scheduling/Application/Services/AppointmentReminderService.php:73`: `scheduleFor()` checks for an existing reminder with `where('appointment_id')`, `where('channel')`, and `where('scheduled_for')`, but no `where('tenant_id', $appointment->tenant_id)`. This is reached from `ScheduleAppointmentReminders::scheduleUpcomingForTenant()` at `apps/api/app/Modules/Scheduling/Infrastructure/Commands/ScheduleAppointmentReminders.php:100`, so the scheduler path still performs an unscoped `scheduling_appointment_reminders` read for appointments whose reminder time is still in the future. Remediation: add `->where('tenant_id', $appointment->tenant_id)` to the lookup and add a query-log assertion that captures every `scheduling_appointment_reminders` select during the scheduler run.
2. BLOCKER - `apps/api/tests/Feature/Console/ConsoleCommandTenantIsolationTest.php:194`: the scheduler regression test only filters `scheduling_appointments` selects, not reminder selects from `AppointmentReminderService`. The helper seeds appointments at `Carbon::now()->addHours(20)` (`ConsoleCommandTenantIsolationTest.php:401`), so `scheduleFor()` exits before the unscoped existing-reminder lookup because the default reminder window is 24 hours before start. Remediation: seed at least one appointment more than 24 hours out inside the 48-hour horizon, and assert every `scheduling_appointment_reminders` select has `tenant_id`.

## Verification gates
- `vendor/bin/phpunit tests/Feature/Console/ConsoleCommandTenantIsolationTest.php` - PASS, 6 tests / 14 assertions.
- `vendor/bin/phpunit tests/Architecture/ConsoleCommandTenantContextTest.php` - PASS, 1 test / 2 assertions.
- `vendor/bin/phpunit tests/Feature/Scheduling/AppointmentReminderServiceTest.php` - PASS, 9 tests / 28 assertions.
- `vendor/bin/phpstan analyse --no-progress --memory-limit=2G` - blocked by sandbox TCP listener EPERM; reran `vendor/bin/phpstan analyse --no-progress --memory-limit=2G --debug` serially - PASS, no errors.
- `vendor/bin/pint --test ...` on the requested paths - PASS.
- `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` - PASS, 1527 events / 321 callsites / 0 problems.
- Hostile grep - only `app/Console/Commands/AbstractSweepInventoryCommand.php` reported, expected because it is abstract.
- POS-surface invariant check - no non-`pos-stabilization` commits reported.

## Recommendation
Do not lock the cluster yet. Add the missing `tenant_id` predicate in `AppointmentReminderService::scheduleFor()`, strengthen the scheduler query-log test so it exercises and asserts reminder reads, then rerun the same gates.
