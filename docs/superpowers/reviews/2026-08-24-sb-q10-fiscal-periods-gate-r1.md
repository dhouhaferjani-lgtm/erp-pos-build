# Gate record — Session B lane Q-10 "fiscal-period quick fixes" — treasury lens, ROUND 1

- **Lane / branch:** `fix/sb-q10-fiscal-period-quickfixes`
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/sb-q10-fiscal-periods`
- **Commit under review:** `ea15dca28` (single commit) on base `2cbc7de64`; diff = `git diff dev...HEAD`
- **Reviewer lens:** treasury-reviewer (adversarial, code-grounded). Date: 2026-08-24.
- **Brief:** `docs/sessions/session-B-2026-08-23/BRIEF-Q10-fiscal-periods.md`
- **Evidence:** tenancy sub-report HIGH #2 in `docs/handoff/AUDIT-state-machine-sweep-sub-reports-2026-08-23.md`
- **MIGRATION-BEARING:** yes — `apps/api/database/migrations/tenant/2026_08_24_140000_add_fiscal_period_transition_audit_columns.php` (additive-only, verified on live PG)

## Verdict

**spec ✅ + quality ACCEPT-with-conditions (CHANGES-REQUESTED on conditions C-1 and C-2 only).**

All three brief items are implemented and are what they claim to be. Every implementer claim (a)–(f) was
re-derived from the files; none was found false. No money arithmetic is introduced anywhere in the lane
(no bcmath, no float, no `getScale()`), no journal entry, treasury row or VAT period is touched. The two
conditions are merge mechanics + a missing regression pin, not code defects.

### Conditions (parent must satisfy before/at merge)

- **C-1 (blocking, merge mechanics).** Resolve the `feature-lane-manifest.json` conflict to
  **gated_ceiling 1164 / Company 31 / Voucher 11**, NOT 1163. See finding I-2 — the naive
  "both sides say 1163, keep 1163" resolution leaves `FeatureLaneManifestCheckerTest` red.
- **C-2 (blocking, evidence).** Add ONE regression pin for the auto-lock ↔ reopen interaction
  (finding I-1): a test that reopens a period and then runs `lockExpiredPeriods()` and asserts the
  ruled outcome. Whichever outcome the parent rules (re-close is accepted, or a grace is added),
  it must be pinned — today it is neither tested nor documented.
- **C-3 (non-blocking, parent action at merge).** Append `FiscalPeriodReopenEndpointTest` to the live
  `--filter` allowlist at `.github/workflows/ci.yml:969` (B-3 / Q-6 / Q-7 precedent). The reviewer did
  NOT edit `.github/**`. See ruling on item 8.

## Findings (ordered by severity)

**Critical:** none. Specifically checked and clean: no float/`parseFloat`/`number_format` on money anywhere
in the diff; no `CurrencyScaleResolverInterface` use at all (nothing in this lane does money arithmetic);
no cross-module Eloquent import (everything is `App\Modules\Company\*` + `App\Modules\Identity\Domain\User`
for FK docblocks, which the table already referenced); `deptrac` violations **182 on dev and 182 on the lane**
(unchanged, implementer's claim confirmed).

### [IMPORTANT] I-1 — the nightly auto-lock silently undoes a reopen within 24h, and overwrites its audit stamp
`apps/api/app/Modules/Company/Application/Services/FiscalPeriodAutoLockService.php:150-175`
`closeOldPeriods()` selects on exactly two predicates — `where('status', PeriodStatus::Open)` (:158) and
`where('end_date','<',$cutoffDate)` (:159). Nothing consults `reopened_at`. A period reopened by an
accountant on 2026-08-24 (its `end_date` is by construction older than the threshold — that is why it was
closed) is re-closed by the 01:00 scheduler on 2026-08-25, and the row's `status_actor` flips from
`user:<uuid>` back to `system:auto-lock` and `status_changed_from` to `open`, so even the *record* that a
human reversed the close is partly overwritten (`reopened_at/by/reason` survive; the actor of the last
transition does not).
**Why it matters:** the entire justification for lane item (c) is "the operator's only recovery was a manual
`UPDATE fiscal_periods`". A recovery edge with a <24h life is a much weaker fix than the record claims, and
the interaction is not covered by any test in the lane (`FiscalPeriodReopenEndpointTest` never runs the
auto-lock; `FiscalPeriodAutoLockServiceTest` never reopens).
**Suggested fix:** cheapest honest option — exclude periods whose `reopened_at` is within a grace window (or
newer than `closed_at`) from `closeOldPeriods()`, and pin it. Alternatively rule "re-close is intended, the
operator posts the correction the same day" and pin THAT with a test + a line in the service docblock. Either
is acceptable; leaving it unstated is not (C-2).

### [IMPORTANT] I-2 — manifest raise collides with Q-5's raise; the union is 1164, not 1163
`apps/api/tests/feature-lane-manifest.json` (`gated_ceiling`, and group `Company`)
Measured, not assumed: base `2cbc7de64` = gated 1162 / Company 30; **`dev` = gated 1163 / Company 30**
(Q-5 raised Voucher 10→11); **lane = gated 1163 / Company 31**. Both sides edited the same
`"gated_ceiling"` line 1162→1163 for *different* classes, so the merge conflicts textually and the correct
resolution is **1164** (1162 + 1 Voucher + 1 Company), with `Company.classes = 31` and `Voucher.classes = 11`.
**Why it matters:** `FeatureLaneManifestCheckerTest` enforces the ceiling; a 1163 resolution ships a red
architecture gate and hides one un-laned class.
**Suggested fix:** C-1 above. (`FeatureLaneManifestCheckerTest` is green *in the lane as it stands* — the
defect only materialises at merge.)

### [IMPORTANT] I-3 — the only regression pin on the reopen guards executes nowhere in CI
`apps/api/tests/Feature/Company/FiscalPeriodReopenEndpointTest.php:39` ·
`apps/api/tests/feature-lane-manifest.json` (group `Company` → lane `feature-lane-tenancy/Company`, PARKED
behind `vars.SELF_HOSTED_RUNNER_READY`) · `.github/workflows/ci.yml:969` and `:1093` (the two live
`--filter` allowlists — neither names it; verified by grep).
The Locked-is-terminal rule, the closed-successor ordering invariant, the closed-fiscal-year refusal, the
accountant/admin-only permission and the cross-company 404 are pinned **only** here.
Mitigating (verified): the auto-lock and provider tests are in `tests/Unit`, and `ci.yml:420` runs
`php artisan test --testsuite=Unit`, so lane items (a) and (b) DO have live CI coverage. Only item (c) does not.
**Suggested fix:** C-3 (parent appends the class name at merge; the implementer correctly flagged this in the
manifest note rather than quietly laning it).

### [IMPORTANT] I-4 — the per-country evidence is non-discriminating on the *window value*
`apps/api/tests/Unit/Company/Application/Services/FiscalPeriodAutoLockServiceTest.php:451-498`
(`test_it_resolves_the_lock_window_per_company_country`) ·
`apps/api/app/Modules/Company/Application/Services/CountryFiscalRulesProvider.php:59-95`
TN and FR both carry `periodAutoLockMonths: 1`, so the TN-vs-FR arm of the test would pass unchanged against
the OLD hard-coded `getRulesForCountry('TN')` implementation. The only genuinely discriminating arm is the DE
one (a hard-coded-TN implementation would have closed the German period; the lane's does not) — which proves
"country is consulted", not "each country's own window is applied".
**Why it matters:** the headline claim of item (a) is a compliance claim; the test suite cannot currently
distinguish it from the bug it replaced except through the skip path.
**Suggested fix (not blocking):** `CountryFiscalRulesProvider` is `final` with `private getRulesForXX()`
methods, so no test-only registry override or PHPUnit double is possible today. Making it non-final (or
extracting a `FiscalRulesRegistryInterface` seam) would let one test assert a 3-month country locks on a
3-month window. Recorded as residual R-2. See ruling on item 1.

### [MINOR] M-1 — refusals carry no machine-readable code
`FiscalPeriodReopenService.php:67,71,77,81` — four `\DomainException`s with prose messages, mapped to a
bare `{"message": ...}` 422 at `FiscalPeriodController.php:53-56`. The sibling lane this copied has a typed
`ReturnPeriodRefusalCode` (`app/Modules/Taxation/Domain/Enums/PeriodLockRefusalCode.php:29-31`); rule 9 of
CLAUDE.md is about columns, not exceptions, so this is a consistency gap, not a violation. A future FE cannot
branch on "locked" vs "successor closed" vs "year closed". **Ordering was checked and is correct:** Locked
(terminal) → not-Closed → fiscal-year-closed → successor; the most specific refusal wins.

### [MINOR] M-2 — no `->whereUuid('id')` on the new route
`apps/api/app/Modules/Company/routes.php:66-68`. A malformed `{id}` reaches
`FiscalPeriodController.php:45` `findOrFail($id)`; on PostgreSQL that is a `22P02 invalid input syntax for
type uuid` and there is **no `QueryException` renderable in `bootstrap/app.php`** (grepped) → 500 instead of
404. NOT a lane regression: the sibling `app/Modules/Taxation/routes.php:111` reopen route has the identical
shape. One-line hardening, consistent with `app/Modules/Partner/routes.php:29`.

### [MINOR] M-3 — stale docblock on the command after the refactor
`apps/api/app/Console/Commands/LockExpiredFiscalPeriodsCommand.php` (unmodified by the lane) still reasons
about "The service's **bulk updates** carry no `tenant_id` predicate … every step is idempotent". The bulk
updates are gone. The *conclusion* survives (the per-row path is equally idempotent — the status predicates
exclude already-transitioned rows), but the prose now describes code that does not exist.

### [MINOR] M-4 — long write transaction replaces three bulk statements
`FiscalPeriodAutoLockService.php:87-125`. One `DB::transaction` still wraps the whole tenant, but its content
is now N individual `UPDATE`s (one per period, per year) instead of 3 set-based statements. `chunkById(500)`
bounds *memory*, not transaction duration or row-lock hold time. Acceptable at current tenant sizes; worth a
residual for the fleet.

### [MINOR] M-5 — behaviour delta: soft-deleted companies are now skipped
`FiscalPeriodAutoLockService.php:88` iterates `Company::query()`, and `Company` uses `SoftDeletes`
(`app/Modules/Company/Domain/Company.php:135`). The previous bulk updates had no company predicate at all,
so periods of soft-deleted companies were locked; now they are not. Arguably an improvement, but it is an
unstated behaviour change. Record it.

### [MINOR] M-6 — the reopen is not an event, and the audit columns are last-transition-only
`FiscalPeriodReopenService.php:95-101` emits `Log::info` only. The sibling emits a domain event
(`VatPeriodManagementService.php:186` `VatPeriodFiled`). Combined with I-1, the durable record of a reopen
degrades over time. The brief explicitly chose the light column idiom over a transitions table, so this is
scope-correct — recorded as residual R-3, not a defect.

## Positive verifications (checked adversarially, found sound)

- **No GL / treasury / VAT mutation on reopen.** `FiscalPeriodReopenService.php:57-113` writes exactly one
  table (`fiscal_periods`), reads `fiscalYear`, logs. No `journal_entries`, no `payment_repositories`,
  no `vat_periods`. Confirmed by reading the whole file.
- **Reopening a fiscal period cannot bypass a Closed/Filed VAT return.**
  `app/Modules/Taxation/Application/Services/VatPeriodBackdatingGuard.php:62-71` checks the VAT period FIRST
  and refuses independently of fiscal-period status; the fiscal-period check at `:73` is the *second* gate.
  So the reopen lifts one gate of two, exactly as intended, and the VAT lane is untouched.
- **`chunkById` while mutating the filtered `status` column is SAFE.** `chunkById` is keyset pagination
  (`where id > $lastId order by id`), not `OFFSET` — rows that drop out of the `status` predicate after being
  updated cannot cause the classic skipped-page hazard. Verified at `:161-176`, `:196-210`, `:231-250`.
- **Idempotency on re-run.** All three steps exclude already-transitioned rows by status/`is_closed`
  predicate; `test_it_does_not_modify_already_locked_periods` (:201-231) additionally asserts `updated_at`
  is not bumped. Verified green.
- **No observers/model events on `FiscalPeriod`** (`grep FiscalPeriod::observe` → none), so switching from
  bulk `update()` to per-row `save()` fires no new side effects.
- **`status_changed_from` derivation at `:236-238`** handles both cast (`PeriodStatus`) and raw-string
  `getOriginal('status')` returns — correct on Laravel 12 where `getOriginal()` applies casts.
- **Successor check is company-scoped and status-scoped** (`FiscalPeriodReopenService.php:118-123`:
  `where('company_id', …)->where('start_date','>',…)->whereIn('status',[Closed,Locked])`). Cross-company
  leakage is impossible; the endpoint additionally scopes the lookup (`FiscalPeriodController.php:43-45`),
  and `test_a_period_of_another_company_is_not_reachable` (:261-279) pins the 404.
- **Row is re-read `lockForUpdate()` inside the transaction** (`:62-64`), so a reopen racing the nightly
  auto-lock cannot double-write.
- **Permission wiring is real.** `fiscal-periods.reopen` seeded at `RolesAndPermissionsSeeder.php:291`,
  granted to `accountant` at `:816`, `admin` via `Permission::all()` at `:536`. Seeder is idempotent
  (`firstOrCreate` at `:39` + `syncPermissions` at `:536`). Route middleware group at `routes.php:24` carries
  `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]` — rule 12 satisfied.
  `manager` exclusion and `viewer` 403 are both pinned by tests (:211-234).
- **Frontend permission map is genuinely in sync.** Regenerated from the worktree to a scratch path
  (`php artisan permissions:export-frontend-map --path=…`) and byte-diffed against the committed
  `apps/web/src/hooks/permissionsMap.generated.ts` → **identical**. The CI hash gate at
  `.github/workflows/ci.yml:2515-2530` will pass. Worktree left clean (`git status --porcelain` empty).
- **Migration is additive-only, verified against a live PostgreSQL**, not just read: `\d fiscal_periods` on
  the throwaway DB shows all 7 new columns nullable with no default, no CHECK, no unique index, and the two
  FKs `fiscal_periods_locked_by_foreign` / `fiscal_periods_reopened_by_foreign` → `users(id) ON DELETE SET
  NULL`, matching the pre-existing `closed_by` FK. `users` is a tenant table created in the same tenant stack
  (2025_11_30_*), so the FK is intra-database — correct under db-per-tenant. SQLite parity holds: the FK
  clauses are `DB::getDriverName() === 'pgsql'`-guarded (`:82`, `:92`) and the columns themselves are created
  on both drivers; `ComplianceTablesTest` passes on both.

## Rulings

### Ruling on item 1 — country resolution honesty: **ACCEPT, with residual R-2**
The per-company path reads **`$company->country_code`** (`FiscalPeriodAutoLockService.php:94`), which is the
real attribute (`app/Modules/Company/Domain/Company.php:45,218` — ISO 3166-1 alpha-2, fillable, and the same
value `CompanyCreated` carries). It is `trim()`ed and a blank/whitespace value returns `false` from
`CountryFiscalRulesProvider::hasDedicatedRules()` (`:45-54`) → the company is skipped, counted, and
`Log::warning`ed with `company_id` + `country_code` (`:99-105`), and the `continue` at `:107` skips **all
three** steps (pinned by `test_it_does_not_close_fiscal_years_of_skipped_companies` :415-449 — the sharpest
test in the lane, because closing the year would have re-entered STEP 3 and locked the periods anyway).
It never falls back to TN, and never consumes `getGenericRules()`. That part is honest and well-evidenced.
The *window value* claim is not discriminating (finding I-4) because TN and FR are both 1 month and the
provider is `final`+private, blocking a test-only override. **Ruling: acceptable for a quick-fix lane** — the
fail-safe skip is the compliance-relevant behaviour and it IS discriminating; the value claim is deferred.
**The registry remains hardcoded PHP** (`CountryFiscalRulesProvider` methods; no seeded settings table for
fiscal windows exists anywhere in the repo — verified). This lane therefore does not satisfy the CLAUDE.md
"country accounting = seeded settings, never hardcoded" rule; it *reduces* the violation from "one country's
window applied to all" to "each country's hardcoded window, unknown countries refused". **Recorded as
residual R-1 for the LEDGER**, not charged against this lane.

### Ruling on item 5 — the dropped auto-created fiscal years in the reopen fixture: **HONEST**
`tests/Feature/Company/FiscalPeriodReopenEndpointTest.php:62-68` deletes the auto-created years with an
explicit, accurate comment. I verified the claim rather than accepting it: `FiscalYearCreationService:123-142`
(`determinePeriodStatus`) marks every period ending more than one month ago as `Closed` at company creation,
so an auto-created FY 2026 on 2026-08-24 carries Jan–Jun as Closed. The fixture period is period 3 (March),
so April/May/June would be legitimate Closed **successors** and every case in the file would 422 for the wrong
reason. The deletion is therefore necessary, not concealment.
**And it does not hide a production refusal:** in a real tenant the newest Closed period has only Open
successors (July/August are Open by the same `determinePeriodStatus` arithmetic), so a real first reopen
*succeeds*. The genuine consequence — that only the **most recent** closed period is ever reopenable, and only
while its fiscal year is open — is a true property of the ordering invariant, inherited deliberately from
`VatPeriodManagementService::reopenPeriod` (`:133-163`), and it is documented in the service docblock.
**Recommendation (non-blocking, folds into C-2):** add one test that does NOT delete the auto-created years
and reopens the newest auto-created Closed period, so the production-shaped path has a pin.

### Ruling on item 8 — CI reachability: **the parent SHOULD append the class at merge**
`FiscalPeriodReopenEndpointTest` lands in `feature-lane-tenancy/Company`, which is PARKED behind
`vars.SELF_HOSTED_RUNNER_READY`; neither live allowlist (`ci.yml:969` fiscal-invariant PG lane, `ci.yml:1093`
tenancy PG lane) names it. It is a PostgreSQL-relevant test (uuid PK lookups, permission team scoping, the new
tenant migration) and it is the sole pin on four guards. The B-3 / Q-6 / Q-7 precedent — `Q-7` appended
`TerminalClaimHardeningTest` to the `:969` list at merge — applies directly. **Rule: append
`FiscalPeriodReopenEndpointTest` to the `:969` allowlist at merge (C-3).** The reviewer did not touch
`.github/**`. Note that items (a)/(b) are already live via `--testsuite=Unit` at `ci.yml:420`.

## Gate verified — per file, per driver (run BY PATH; full suite never run)

SQLite (`phpunit.xml` defaults, `:memory:`):

| Path | Result |
|---|---|
| `tests/Unit/Company/Application/Services/FiscalPeriodAutoLockServiceTest.php` + `…/CountryFiscalRulesProviderTest.php` | **22 tests, 65 assertions — OK** (5 PHPUnit deprecations, pre-existing `/** @test */` style) |
| `tests/Feature/Company/FiscalPeriodReopenEndpointTest.php` + `tests/Feature/Fiscal/LockExpiredFiscalPeriodsCommandTest.php` + `tests/Feature/Company/ComplianceTablesTest.php` | **33 tests, 66 assertions — OK** |
| `tests/Unit/Taxation/VatPeriodManagementServiceTest.php` + `tests/Feature/Taxation/VatPeriodControllerTest.php` + `tests/Feature/Taxation/VatPeriodManagerCannotMutateTest.php` + `tests/Architecture/FeatureLaneManifestCheckerTest.php` | **96 tests, 508 assertions — OK** (the copied-shape suites: unregressed) |
| `tests/Architecture` (whole directory) | 154 tests, **5 failures — ALL INHERITED**, none naming any file in the lane diff: `AuthLifecycleTest` (POS/routes.php:99 non-literal middleware), `ConsoleCommandTenantContextTest` (12 pre-existing commands), `ControllerTenantContextTest` (8 pre-existing methods — **`FiscalPeriodController::reopen` is NOT among them**), `DocumentPerActionBaselineRatchetTest` (`DPA_BASELINE_PROTECTED_BLOB` unset locally — fails closed by design), `QueueJobTenantContextTest` (2 pre-existing Product jobs). `DocumentPerActionWriteGuardTest`, `EnumBackedStatusLiteralTest`, `TenantScopedFindCallsTest`, `TenantScopedExistsRulesTest`, `TreasuryBalanceWritePortTest` all **green** — the new controller/service tripped no architecture guard. |

PostgreSQL 5433, throwaway DB `autoerp_gate_q10` (created, migrated, **DROPPED** at end of gate):

| Path | Result |
|---|---|
| the five lane/adjacent files above (`FiscalPeriodAutoLockServiceTest`, `CountryFiscalRulesProviderTest`, `FiscalPeriodReopenEndpointTest`, `LockExpiredFiscalPeriodsCommandTest`, `ComplianceTablesTest`) | **55 tests, 131 assertions — OK** |
| schema proof | `\d fiscal_periods` on the migrated PG DB — 7 new nullable columns + 2 new FKs to `users`, no default/CHECK/unique (output reviewed in-gate) |

Static / structural:

- `pint --test` on all 12 changed backend files → `{"result":"pass"}`
- `phpstan analyse` (level 8, project `phpstan.neon`) on the 7 changed/new app+migration files → **[OK] No errors**
- `deptrac analyse` — **lane 182 violations / dev 182 violations** (both measured this session; implementer's baseline claim confirmed)
- manifest checker (`FeatureLaneManifestCheckerTest`) green **in-lane**; see I-2 for the merge-time arithmetic
- `permissions:export-frontend-map` regenerated from the worktree → byte-identical to the committed artifact

## Manifest union (compute at merge — C-1)

| | gated_ceiling | Company | Voucher |
|---|---|---|---|
| base `2cbc7de64` | 1162 | 30 | 10 |
| `dev` (after Q-5) | 1163 | 30 | **11** |
| lane `ea15dca28` | 1163 | **31** | 10 |
| **post-merge union (required)** | **1164** | **31** | **11** |

## Deploy steps (S-19 migration-bearing bundle)

1. `php artisan tenants:migrate` — applies `2026_08_24_140000_add_fiscal_period_transition_audit_columns`.
   Additive-only, no backfill, no pre-flight census, no per-tenant abort path (all 7 columns nullable; both
   FKs are on columns that are NULL for every existing row). Safe on a live tenant with existing periods.
2. **Re-seed roles/permissions per tenant** — `php artisan tenants:run db:seed --class=RolesAndPermissionsSeeder`
   (or the fleet's standard seeder step). Without it, `fiscal-periods.reopen` does not exist in an existing
   tenant and the route fails CLOSED with 403 for everyone including `accountant`. Seeder is idempotent.
3. **Reset the Spatie permission cache** after step 2 (`permission:cache-reset` / the seeder's
   `forgetCachedPermissions()` covers the seeding process only).
4. No POS device version bump, no frontend deploy required (no UI consumes the endpoint yet — residual R-4).

## Residuals for the LEDGER

- **R-1** — `CountryFiscalRulesProvider` remains **hardcoded PHP**, not seeded settings. No fiscal-window
  settings table exists in the repo. CLAUDE.md "country accounting = seeded settings, never hardcoded" is
  still violated fleet-wide for fiscal windows; this lane narrowed the blast radius (unknown countries are now
  refused instead of inheriting TN). Program-scope fix.
- **R-2** — no test can pin the *value* of a country's lock window because TN and FR are both 1 month and
  `CountryFiscalRulesProvider` is `final` with `private` rule methods (no seam for a test-only registry
  override). Cheap fix when the class next changes.
- **R-3** — reopen emits no domain event and no audit-log row; `status_actor`/`status_changed_from`/`closed_at`
  are last-transition-only and are overwritten by the next auto-lock. Full transition history is program scope
  (the `PeriodStatus` machine + transitions table).
- **R-4** — no frontend surface: the permission is in `permissionsMap.generated.ts` but nothing in
  `apps/web/src` calls `POST /api/v1/fiscal-periods/{id}/reopen` (grepped). The accountant cannot use the
  feature without curl until a UI lands.
- **R-5** — `Locked` remains terminal and any period inside a closed fiscal year is unreachable, so the
  reopen edge only ever addresses the newest closed period of an open fiscal year. Intended for this lane;
  the year-level reopen is program scope.
- **R-6** — M-3/M-4/M-5: stale command docblock, longer write transaction, soft-deleted companies now skipped.
- **Merge-conflict watch (Session A collision matrix):** the lane touches `RolesAndPermissionsSeeder.php`
  (two hunks: `:291` permission name, `:816` accountant grant) and the generated permissions map — both are
  shared, high-traffic files; and `feature-lane-manifest.json` (guaranteed conflict, see C-1). Nothing else in
  the diff is on Session A's matrix: no opening-balance service, no VAT resolution, no `StockLevel`, no PIN,
  no web document pages. Scope is clean.

---
*Gate run by treasury-reviewer. No files were modified except this record. Throwaway PG database
`autoerp_gate_q10` was dropped. Not merged — a human merges.*
