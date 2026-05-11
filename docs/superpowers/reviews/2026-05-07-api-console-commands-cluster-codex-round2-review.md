# Codex round-2 adversarial review - api.console-commands cluster (Section 14)

Review date: 2026-05-07
Branch tip reviewed: 99b6f5aa
Reviewer: codex (round-2 cross-agent review of Claude's follow-up implementation)

Verdict: APPROVE
Commit reviewed: 0f51492f
Round-1 review: docs/superpowers/reviews/2026-05-07-api-console-commands-cluster-codex-review.md
Round-1 commit reviewed: ff7ebbc5
Round-2 follow-up commit (round-1 BLOCKER remediation): 99b6f5aa

Per the multi-batch fix-commit convention from the kickoff: the
`Commit reviewed:` line above pins the most-common fix_commit across the
cluster's callsites (api.console-commands.001/002/003 all carry
fix_commit=0f51492f from initial submission). The round-2 follow-up
commit `99b6f5aa` extends the original fix on api.console-commands.002
(AppointmentReminderService::scheduleFor() tenant_id predicate + test
strengthening). Both commits combined constitute the approved fix surface.

## Verdict
APPROVE. The two round-1 BLOCKER findings are closed at 99b6f5aa, and no new findings emerged from the round-2 hostile audit of the three cat-(a) console command paths.

## Round-1 blocker closure
1. BLOCKER 1 closed - `apps/api/app/Modules/Scheduling/Application/Services/AppointmentReminderService.php:73-78` now scopes the existing-reminder lookup with `->where('tenant_id', $appointment->tenant_id)` before the appointment/channel/scheduled-for predicates. This is the scheduler-reachable lookup identified in round 1, and tenant-only scope is correct for `scheduling_appointment_reminders`.
2. BLOCKER 2 closed - `apps/api/tests/Feature/Console/ConsoleCommandTenantIsolationTest.php:175-240` now seeds appointments at `now()+36h`, captures both `scheduling_appointments` and `scheduling_appointment_reminders` SELECTs, asserts each set is non-empty, and asserts every captured reminder SELECT contains `tenant_id`. The test is not hidden behind an if-skip; the added assertions execute, as shown by the console test increasing to 21 assertions.

## Cat-(a) call-path audit
| Callsite | Status | Evidence |
|---|---|---|
| `api.console-commands.001` / `nf525:export-jet` | Closed | `ExportNf525JetCommand` extends `TenantScopedCommand`, requires `--tenant` + `--company`, validates the company through `ScopedExists::tenant('companies', $tenantId)`, binds `CompanyContext`, then calls `Nf525JetExportService` with the validated company id. The provider path for `buildExportSnapshot()` is company-bound. |
| `api.console-commands.002` / `scheduling:schedule-appointment-reminders` | Closed | The command iterates via `forEachTenant()`. Upcoming appointment reads, due reminder reads, `AppointmentReminderService::scheduleFor()` existing-reminder reads, and `DispatchAppointmentReminder::handle()` reminder/appointment reads all carry tenant predicates. |
| `api.console-commands.003` / `workshop:check-expiring-certifications` | Closed | The command iterates via `forEachTenant()` and calls `findExpiringWithin($days, $tenant->id)`. The Eloquent repository filters `workshop_technician_certifications.tenant_id`; tenant-only scope matches the migration shape. |

## Hostile grep
The requested scheduler-surface grep reports the scoped command/job/service reads plus legacy scheduling repository/query-object reads such as `EloquentAppointmentRepository` and `UpcomingAppointmentsQuery`. Those legacy reads are not introduced by the cluster diff and are not reachable from the three cat-(a) console command paths reviewed here. No scheduler-command-path read remains unscoped.

## Verification gates
- `vendor/bin/phpunit tests/Feature/Console/ConsoleCommandTenantIsolationTest.php` - PASS, 6 tests / 21 assertions.
- `vendor/bin/phpunit tests/Architecture/ConsoleCommandTenantContextTest.php` - PASS, 1 test / 2 assertions.
- `vendor/bin/phpunit tests/Feature/Scheduling/AppointmentReminderServiceTest.php` - PASS, 9 tests / 28 assertions.
- `vendor/bin/phpstan analyse --no-progress --memory-limit=2G` - blocked by sandbox TCP listener EPERM; reran `vendor/bin/phpstan analyse --no-progress --memory-limit=2G --debug` serially - PASS, no errors.
- `vendor/bin/pint --test app/Modules/Scheduling/ tests/Feature/Console/ tests/Architecture/ConsoleCommandTenantContextTest.php` - PASS.
- `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` - PASS, 1527 events / 321 callsites / 0 problems.

## Recommendation
Approve the round-2 fix. The round-1 BLOCKERs are closed, the strengthened regression test exercises the previously missed reminder-lookup branch, and no additional unscoped reads were found in the cat-(a) console command call graph.
