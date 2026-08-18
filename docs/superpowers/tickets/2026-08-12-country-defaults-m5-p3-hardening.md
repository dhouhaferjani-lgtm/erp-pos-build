# Country defaults M5 P3 hardening

Source registers:

- `docs/handoff/reviews/country-defaults-phase-a/M5-round3.md`
- `docs/handoff/reviews/country-defaults-phase-a/M5-round4.md`
- M5 execution record: `docs/sessions/codex-country-defaults-phase-a-report.md`

This ticket is the durable disposition for every M5 P3 raised in rounds 3 and 4. Items marked
deployment-blocking are operational prerequisites, not optional cleanup.

## Deployment-blocking runbook

### Release 2 flag flip and rollback (round 3 #5)

Laravel production uses cached configuration. Changing
`COUNTRY_DEFAULTS_PROVISIONING_ENABLED` does nothing to already-cached processes by itself.

The Release 2 flip has a blocking precondition after authenticated G2 certification and assignment:
run `php artisan country-defaults:verify` on staging and production. Both commands must exit zero
before changing the flag. If either command exits non-zero, keep
`COUNTRY_DEFAULTS_PROVISIONING_ENABLED=false`; do not enable template-backed company creation or
tenant registration.

Only after that two-environment gate passes, perform the Release 2 flip to `true`:

1. change the deployment environment value;
2. rebuild Laravel's config cache (`php artisan config:cache` on the released artifact/container);
3. restart every API/application process so no old config remains resident;
4. terminate/restart Horizon workers (`php artisan horizon:terminate`, with the process supervisor
   bringing Horizon back) and restart any non-Horizon queue workers;
5. verify `config('country_defaults.provisioning_enabled')` from the running release.

For rollback, set the value to `false` and repeat steps 2–5. Rollback is not complete until the
false value is recached and all app/Horizon/queue processes have restarted. Owners: release
engineering + platform operations. Status: **OPEN — blocking gate must be copied into the
production release runbook before Release 2**.

### Release 1 is not behaviorally inert (round 3 #4)

M5's D-5 expense behavior is intentionally not gated by `provisioning_enabled`:
`ExpenseCategorySeeder` now fails loudly for an absent chart or missing mapped code, and the
additional-company path no longer catches/logs/continues after chart failure. Release 1 therefore
changes failure behavior even while readers remain on legacy charts. Pre-deploy smoke must create a
company for every currently supported legacy country and confirm the protected expense mappings.
Owners: accounting domain + release engineering. Status: **OPEN operational note; code behavior is
accepted by the M5 brief**.

## Code/test follow-ups

- **Round 3 #6 — purpose-match `is_system` parity:** CLOSED in M5. Template seeding now promotes
  only code matches and preserves purpose-only matches, with a regression test.
- **Round 3 #7 — optional tenant lookup:** make tenant identity mandatory in
  `CountryDefaultsChartOfAccountsSeeder`; remove the contract-permitted unscoped global
  `findOrFail`. Owner: tenancy. Status: OPEN.
- **Round 3 #8 — hard-coded zero scale:** replace the template seeder's `'0.000'` literal with the
  project money/currency scale resolver when the account-balance storage boundary is standardized.
  It is string-safe today and not a float bug. Owner: accounting. Status: OPEN, low risk.
- **Round 3 #9 — M5 scope creep:** audit and separately document or revert the incidental demo
  fixture corrections (`started_at`, duplicate/default `is_physical`, metadata null guard, generic
  PHPDocs). Owner: demo-fixtures. Status: OPEN hygiene.
- **Round 3 #10 — successful additional-company HTTP path:** add an HTTP-level success test with
  template provisioning enabled; current success coverage reaches the service directly while HTTP
  coverage is failure/rollback focused. Owner: company + country-defaults. Status: OPEN.
- **Round 3 #11 — pre-commit external side effect:** move or defer
  `RegisterCompanyWithGrowthAdvisor` so `CompanyCreated` cannot register an external phantom company
  before the surrounding DB transaction commits and later provisioning rolls back. Owner:
  progression/integrations. Status: OPEN; investigate before enabling the live client in Release 2.
- **Round 3 #12 / round 4 #6 — red-first evidence durability:** preserve failing-command output in
  milestone reports or separate commits for future waves. M5 later rounds record chronological red
  output in the session/milestone report, but git history still bundles tests and fixes. Owner:
  execution harness. Status: PROCESS FOLLOW-UP.
- **Round 4 #5 — file-level frozen-seeder allowlist:** the unsafe public service seam was removed;
  the only flag-true legacy access now sits in `LegacyExistingChartRepairPreviewer`, which owns and
  always rolls back its nested transaction. Harden the guard further with AST/control-flow checks
  if frozen references are ever added to a mixed-purpose file again. Owner: country-defaults.
  Status: MITIGATED, hardening OPEN.
- **Round 4 #7:** carries round 3 #7–#11 above; no item is dropped by this consolidation.

## Operator cross-reference

The legacy treasury repair procedure is corrected in
`docs/handoff/treasury-phase2-deploy-checklist.md`: under template provisioning, only the
`accounting:seed-charts --dry-run` legacy diagnostic remains available, its output is not assigned-
template parity and cannot be applied, `country-defaults:verify` is the assignment-health successor,
and live-chart repair requires a reviewed one-off migration.

## Terminal-audit F-10 note (parent, 2026-08-18): the `transactionLevel()` guards are construction-guaranteed tripwires, NOT test-proven controls

`TemplatePublishingService::assertAuditTransaction()` (:379-384), `TemplateAssignmentService`
(:252-257) and `AdminTemplateAccount` (:39-47) refuse writes outside an active central
transaction. No test exercises the refusal branch: under `RefreshDatabase` an outer transaction
is always open, so `transactionLevel() < 1` is unreachable in the test lane, and every production
call site sits inside the service's own `$connection->transaction(...)` closure, so the branch is
also unreachable by construction in production. These guards are tripwires against a future
refactor dropping the transaction wrapper — do not cite them as proven isolation controls. If
coverage is ever wanted, the repo idiom is popping the RefreshDatabase transaction to reach
level 0 (`TreasuryMovementServiceRecordTest.php:380-386`; hazard documented by name in
`ReverseCustomerAdvanceJournalEntryTest.php:182-191`).
