# Gate r2 (treasury/accounting lens) — Session I lane I-2, day-one tenant census

- **Fix commit:** `6d34f10bc` (range `3d2cd7027..6d34f10bc`), worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/i2-fresh-tenant-guards`
- **r1 record:** `docs/superpowers/reviews/2026-08-30-i2-fresh-tenant-guards-gate-r1-treasury.md`
- **Reviewer:** treasury-reviewer (adversarial, code-grounded). No tests re-run (orchestrator: Feature 19 pass + 1 intended incomplete on PG `autoerp_test_i`; ratchet 7/7; phpstan/pint/manifest clean). Nothing edited outside this file.
- **Verdict:** **MERGEABLE with one text fix** — all three MAJORs are resolved, two of them beyond what r1 asked for. Two new findings, both about *justification text and a crash path*, not about the guards' predicates.

---

## 1. Resolution of the r1 findings

| r1 finding | Status | Evidence |
|---|---|---|
| MAJOR-1 GL-linked drawer/safe | **RESOLVED** | `DayOneCensus.php:246-252`, `:259`, `:270-275`, `:281` |
| MAJOR-2 cash-tender coherence | **RESOLVED** | `DayOneCensus.php:296-324` |
| MAJOR-3 ratchet bypassable by column order | **RESOLVED (exceeded)** | `TenantOnlyUniqueIndexScanner.php:54-62` |
| MINOR-1 onboarding row misnamed | **NOT-ADDRESSED (deliberate)** | `DayOneCensus.php:372-373` unchanged |
| MINOR-2 SCOPE_REQUIRED hardcoded | **NOT-ADDRESSED (deliberate)** | `DayOneCensus.php:160-164` unchanged |
| MINOR-3 CONDITIONAL/SOFT fail-open compare | **RESOLVED** | `ProvisioningRequiredPurposesV1.php:129-138`, `:356-367` |
| MINOR-4 raw-table vs model-scope drift | **PARTIAL** — location half resolved, soft-delete half not | `DayOneCensus.php:223` resolved; `:333-337` unchanged |
| MINOR-5 `'draft'` magic string | **RESOLVED** | `DayOneCensus.php:335`; `FreshTenantCensusInvariantsTest.php:396` |
| MINOR-6 zero-company verdict line | **RESOLVED** | `DayOneCensusCommand.php:43-49` |

### (a) Drawer AND safe require active + `gl_account_id IS NOT NULL` — CONFIRMED

- Drawer: `apps/api/app/Modules/Tenant/Application/Services/DayOneCensus.php:246-252` adds a second count with `->whereNotNull('gl_account_id')`; the pass predicate at `:259` is `$drawers === 1 && $glLinkedDrawers === 1`.
- Safe: `:270-275` / `:281`, same shape — a safe with a null GL link now FAILS.
- Expected/actual strings say GL-linked: `expected: 'active_attributed_cash_registers=1; GL-linked=1'` (`:257`), `actual: "…; gl_linked={$glLinkedDrawers}"` (`:258`); safe equivalents at `:279-280`.
- Planted null-GL drawer proves it live: `apps/api/tests/Feature/Tenant/DayOneCensusCommandTest.php:98-118` nulls `gl_account_id` on the **second** location's drawer (a repository created through the real `POST /api/v1/locations` path, `BuildsFreshTenantCensusFixture.php:89-102`) and asserts `gl_linked=0`, the literal `GL-linked` in the expected column, `DRIFT(1)` and exit `1`. `DRIFT(1)` — not `DRIFT(n)` — is the load-bearing part: it proves exactly one row flipped and nothing else in the fixture is incidentally failing.
- **≥1 active POS location asserted, non-vacuously:** `DayOneCensus.php:228-236` emits a per-company `active_pos_locations>=1` row before the per-location loop, so a company with zero active POS locations can no longer produce a vacuous CLEAN by iterating an empty set. Proven by `DayOneCensusCommandTest.php:140-158`, which deactivates every `pos_enabled` location and asserts `active_pos_locations=0` + `DRIFT(1)` + exit 1.
- The location query itself now filters `->where('is_active', true)` (`:223`), matching `FreshTenantCensusInvariantsTest.php:165-169` — the census/test predicate divergence in r1 MINOR-4 is gone.

**Withdrawal of an r1 claim.** r1 MAJOR-1 said `PaymentController` "routes on the legacy `account_id` column". That is **wrong** and I retract it: `PaymentController.php:764` and `:789` both guard on `gl_account_id`, and the only `account_id` read in that file (`:567`) is `journal_lines.account_id` inside a `whereHas('lines', …)` on the 401 leg — a different table. I could not find any consumer that routes on `payment_repositories.account_id`. The `gl_account_id`-only predicate the fix implemented therefore covers every consumer I can cite (`ReceiptPaymentService.php:254-261`, `GeneralLedgerService.php:3915/:4181/:4409`, `RefundCompensationService.php:199-212`, `PaymentController.php:764/:789`), and my r1 suggestion to also assert `account_id` is unproven and should not be actioned.

**Residual (minor):** no planted null-GL **safe** test — only the drawer arm is proven live. The safe predicate is three lines of the same shape, so this is a coverage note, not a defect.

### (b) `cash_tender_coherent` — CONFIRMED, all three census shapes are RED

`DayOneCensus.php:296-324` now asserts three things, not a headcount:

```php
passed: $active >= 1
    && count($cashTenders) === 1                 // exactly one active is_cash_tender
    && strtoupper($flaggedCode) === 'CASH'       // and it is the CASH-family code
    && $cashCodeMethods === 1,                   // and nothing else reads as CASH by code
```

Against `apps/api/database/migrations/tenant/2026_08_27_100000_census_cash_tender_invariant_violations.php`:

- **Shape A** (`:26-29`, exact `CASH` with `is_cash_tender=false`). `unique(company_id, code)` makes a second exact-`CASH` row impossible, so the flagged set is either empty (→ `count !== 1`, FAIL) or holds some other code (→ `strtoupper($flaggedCode) !== 'CASH'`, FAIL). **RED.**
- **Shape B** (`:28-32`, mixed-case cash family unflagged beside the canonical row). Flag clauses pass; `cashCodeMethods` counts both `CASH` and `Cash` under `UPPER(code)='CASH'` (`:305-308`) → `2 !== 1`. **RED.** Without a canonical row it degenerates to shape A. **RED.**
- **Shape C** (`:34-36`, flag on a non-`CASH` code). Two flagged rows → `count === 2`, FAIL; one flagged row on a non-cash code → `strtoupper !== 'CASH'`, FAIL. **RED.**
- **Cardinality-1 shape C is planted live:** `DayOneCensusCommandTest.php:120-138` renames the sole flagged method to `CASH_ALT` and asserts `active_cash_tenders=1` (i.e. the old cardinality-only predicate would have passed), `flagged_code=CASH_ALT`, `cash_code_methods=0`, `DRIFT(1)`, exit 1. This is the exact regression r1 named.
- The fixture side is pinned too: `FreshTenantCensusInvariantsTest.php:240-253` asserts the flagged set has exactly one member, that its `strtoupper(code) === 'CASH'`, and that exactly one row matches `UPPER(code)='CASH'`.

Two informational notes, neither a defect:
- `cashCodeMethods` (`:305-308`) deliberately omits `is_active`, so an **inactive** mixed-case `Cash` row also trips the row. That is the conservative direction and it agrees with the migration's own remedy for shape B (`:200-207` says *rename*, not deactivate), so an operator following the runbook can clear it.
- A single lowercase `cash` row carrying the flag is shape C by the migration's exact-code test (`:92`) but passes the census, because both sides are normalised through `UPPER`/`strtoupper`. That is what r1 asked for and it matches the only live code-side consumer — `ShiftExpectedCashService.php:338` reads `UPPER(code) = 'CASH'`, and I found **no** production consumer comparing `code === 'CASH'` exactly. So the census's definition tracks the consumers; the migration's is stricter because it mirrors the write-path guard.

### (c) CONDITIONAL/SOFT via the manifest's public accessors — CONFIRMED, no fail-open compare

`DayOneCensus.php:165-172` now calls `ProvisioningRequiredPurposesV1::conditionalPurposes()` / `::softPurposes()`; the bare `$entry['classification'] !== 'CONDITIONAL'` string comparison is gone (old `classifiedPurposePresence()` deleted, replaced by `purposePresence(array $purposes)` at `:395-405`). The accessors (`ProvisioningRequiredPurposesV1.php:129-138`) delegate to `purposesByClassification()` (`:356-367`), which compares against the **private** consts inside the one scope that can see them — the same additive precedent as `requiredPurposes()` and exactly what its docblock at `:96-106` prescribes. The manifest DATA is untouched (`entries()` diff is additive accessors only). Both partitions are pinned by value in `apps/api/tests/Unit/CountryDefaults/ProvisioningRequiredPurposesV1ConformanceTest.php:108-128`, so a silent shrink to `[]` is now a red test rather than a `conditional=0/0` line nobody reads.

Not selected: an equivalent `scopeRequiredPurposes()` (r1 MINOR-2). `DayOneCensus.php:160-164` still hardcodes `SalesStampDutyPayable` + `'TN'`. See §3.

### (d) `DocumentStatus::Draft` — CONFIRMED

`DayOneCensus.php:335` is `->where('status', DocumentStatus::Draft->value)` (import at `:10`); the test's `numberedDraftCount()` uses the enum case directly (`FreshTenantCensusInvariantsTest.php:396`). Rule 9 satisfied on both sides.

### (e) `DAY-ONE CENSUS <tenant> -: NO-COMPANY` + exit 1 — CONFIRMED

`apps/api/app/Console/Commands/DayOneCensusCommand.php:44-48` emits exactly `'DAY-ONE CENSUS '.$this->tenantId().' -: NO-COMPANY'` and returns `self::FAILURE`; `tenantId()` was widened to a nullable-arg form (`:92-104`) that falls back to the bound Stancl tenant, so the tenant token is real under `tenants:run`. Proven end to end by `DayOneCensusCommandTest.php:169-208`, which swaps the default connection to an in-memory SQLite carrying only a `companies` table, binds the tenant, and asserts the literal line plus exit `1` — and restores the connection in a `finally`. The `-` occupies the company column, so the line stays greppable with the same shape as the CLEAN/DRIFT lines. A bonus guard landed alongside: a non-UUID `--company` now exits `INVALID` (2) before touching the UUID column (`:35-39`, tested at `:160-167`) — that closes the `Str::isUuid` 500 trap noted in project memory.

### (f) No floats, no bcmath — CONFIRMED

`git diff 3d2cd7027..6d34f10bc | grep '^+'` filtered for `(float)`, `(double)`, `floatval`, `round(`, `parseFloat`, `Number(`, `number_format`, `bcadd|bcsub|bcmul|bcdiv|bccomp`, `getScale` returns **zero** hits. The census emits integer counts and label strings only; no monetary or quantity value is read, formatted, or compared anywhere in the diff. Rule 19: **clean**.

### MAJOR-3 — resolved beyond the request

`TenantOnlyUniqueIndexScanner.php:54-62` dropped the leading-column test entirely. The predicate is now *"any non-primary unique index that does not contain `company_id`"*, with only a single-column `id`/`uuid` exemption. That is strictly stronger than the `in_array('tenant_id', …)` I suggested: it also catches a unique with **no** tenant column at all (which is how `price_lists(code)` surfaced into the baseline). Liveness proves both new arms — `unique(products.sku)` with no tenant column is reported (`TenantOnlyUniqueRatchetLivenessTest.php:82-96`) and `(tenant_id, company_id, code)` is still correctly ignored (`:49-66`). Two further hardenings landed unasked:
- `every_table_with_a_qualifying_unique_is_explicitly_classified` (`TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:221-238`) forces every table in the live schema carrying a qualifying unique into either `CATALOGUE_TABLES` or `EXCLUDED_TABLES` with a written reason, with a liveness case that creates a throwaway table and asserts the remediation string (`TenantOnlyUniqueRatchetLivenessTest.php:98-112`). New tables can no longer arrive unclassified.
- `waiver` became optional and now *discriminates*: an entry with only `key` is frozen legacy debt counted against `LEGACY_ENTRY_CEILING = 7` (`:187`, `:210-218`); a `waiver` string means "legitimately tenant-global". The baseline's 7 un-waived entries (`brands` ×2, `loyalty_members(phone)`, `price_lists(code)`, `product_attributes(code)`, `vehicles(vin)`, `vehicles(license_plate)`) sit exactly at the ceiling, so legacy debt has zero headroom. Treasury-relevant tables (`payment_methods`, `payment_repositories`, `accounts`, `tax_configurations`, `documents`) are in `CATALOGUE_TABLES` and carry **no** baseline entry — i.e. every one of their uniques already contains `company_id`. Good.

---

## 2. New findings in this round

### [MAJOR] r2-N1 — `EXCLUDED_TABLES['journal_entries']` states a reason the GL schema contradicts, and it silences a live second-company failure

`apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:115`:

```php
'journal_entries' => 'Journal entry identity and numbering are ledger-global rather than operator catalogue keys.',
```

"ledger-global" is false. The GL chain is explicitly **per company**: `apps/api/database/migrations/tenant/2026_07_08_100400_add_chain_sequence_unique_index_to_journal_entries.php:35-39` creates `uniq_je_company_chain_sequence ON journal_entries (company_id, chain_sequence)`, and its own docblock (`:21`) says the sequence "must be globally unique **within the company chain**". Meanwhile the numbering unique is tenant-wide: `apps/api/database/migrations/tenant/2025_11_30_100000_create_journal_entries_table.php:41` — `$table->unique(['tenant_id', 'entry_number'])` — and no later migration redefines it (grepped `dropUnique` / `entry_number` across `database/migrations/tenant/`: no hits).

That mismatch is a real day-one, second-company defect on `dev` today:

- `AccountingOpeningService::generateEntryNumber()` (`apps/api/app/Modules/Accounting/Application/Services/AccountingOpeningService.php:912-938`) reads the max with `->where('company_id', $companyId)` and mints `sprintf('OB-%s-%06d', $year, $nextNumber)`.
- `ArApOpeningLedgerService::generateOpeningEntryNumber()` (`:266-287`) is deliberately the same sequence and the same advisory-lock key.
- So company A's first opening batch mints `OB-2026-000001`; company B's first opening batch **in the same tenant and year** reads its own (empty) max, mints `OB-2026-000001` again, and the insert at `AccountingOpeningService.php:689-700` hits `journal_entries_tenant_id_entry_number_unique`. There is no catch and no retry — the batch fails with a raw 23505 after passing every validation, including the coverage refusal at `:674-684`. Both docblocks (`:904-906`, `:259-263`) already name that unique as "the second line of defence" against a *concurrency* race; neither notices it is also a hard multi-company collision.
- Ordinary GL numbering is safe — `AccountingService::sourceEntryNumber()` (`:1991-1996`) suffixes the document UUID. Only the ordinal `OB-{year}-{seq}` sequences collide.

This is the same class as `documents(tenant_id, type, document_number)`, which **this batch fixes** (`database/migrations/tenant/2026_08_30_100500_enforce_company_scoped_document_numbers.php`) and which the lane's own convention edit lists as fixed (`docs/conventions/09-SECOND-OF-EVERYTHING.md:19`).

Why it matters here rather than in an accounting lane: fixing the collision is out of a guards-only lane's scope, but **writing down a false reason is not**. `EXCLUDED_TABLES` is the gate's permanent record; a future reviewer who greps `journal_entries` finds a sentence asserting the numbering is ledger-global and stops. The ratchet then certifies this table forever and no test will ever surface the collision.

**Fix (text only, in this lane):** replace the reason with the truth and the tracking pointer, e.g. *"Entry numbers are system-generated, so this is out of the operator-catalogue scope of this ratchet. NOTE: the live `(tenant_id, entry_number)` unique is nonetheless tenant-wide while the `OB-{year}-{seq}` generator is company-scoped (`AccountingOpeningService.php:912-938`) — company B's first opening batch collides with company A's. Tracked separately as a second-of-everything defect."* Then log the defect for an accounting lane. Do **not** move `journal_entries` into `CATALOGUE_TABLES` — it is not operator-editable and would misuse the ratchet's charter.

### [MAJOR] r2-N2 — the new `Company` model hydration turns a soft-deleted company into an unhandled crash of the census command

`DayOneCensus.php:61-70` selects from the raw `companies` table with **no** `whereNull('deleted_at')`, and `:80` (new in this commit) hydrates each row with `Company::query()->findOrFail((string) $row->id)`. `Company` uses `SoftDeletes` (`apps/api/app/Modules/Company/Domain/Company.php:31`, `:139`) and the table has the column (`database/migrations/tenant/2025_11_30_104000_create_companies_table.php:102`), so the global scope excludes trashed rows and `findOrFail` throws `ModelNotFoundException` on the first soft-deleted company.

The failure mode is the bad one for a read-only operator tool: instead of reporting `DRIFT(n)`, `tenant:census-day-one` dies with a stack trace and takes the rest of the tenant's companies with it — and this command is a promotion precondition per `CLAUDE.md` rule 22 and the runbook. It also runs under `tenants:migrate`-adjacent fleet automation where a stack trace is noise, not a signal.

Reachability is low: I found no HTTP delete route for companies (`app/Modules/Company/routes.php` has store/show/update and settings only, no `destroy`) and no `Company::…->delete()` in `app/`. So today this needs a console/tinker/DB soft delete. It is still a latent crash introduced by *this* commit — before `:80` the raw loop would merely have censused the trashed company.

**Fix:** add `->whereNull('deleted_at')` to the `companies` query at `DayOneCensus.php:61-70`. One line; it fixes the crash and the "census a deleted company" wrongness at once, and it is the same fix as the unaddressed half of r1 MINOR-4.

### [MINOR] r2-N3 — the `units_visible_min_19` row's description promises per-company visibility the delegate does not implement

`DayOneCensus.php:108` describes the row as *"Tenant-scoped rows, per-company visibility"*, and `:97` now delegates to `UnitsProvisioningService::visibleActiveUnitCount($company['model'])`. That method (`app/Modules/Uom/Application/Services/UnitsProvisioningService.php:18-26`) filters on `$company->tenant_id` only — it is byte-for-byte the tenant-wide query the census used to run inline. Delegating is right (its docblock at `:16-17` names it the substitution point for OQ-G-25, so the census moves with the units lane instead of forking), but the description currently over-promises: two companies in one tenant will always report the same count. Say "tenant visibility pending OQ-G-25" or leave the wording to the units lane that makes it company-aware.

### [MINOR] r2-N4 — three exit codes now, one documented

`DayOneCensusCommand.php:21` still says *"Exit 0 = report complete/clean, 1 = drift when --fail-on-drift is set."* The command now also exits `1` unconditionally on NO-COMPANY (`:48`, independent of `--fail-on-drift`) and `2` on a malformed `--company` (`:38`). `docs/handoff/RUNBOOK-day-one-census.md` is not in the diff, so the operator's documented grep and exit contract are both stale. Update the `$description` and the runbook line together.

---

## 3. Deliberately-not-selected items — acceptable for a guards-only merge?

**Yes for all three, with the caveat below.**

- **MINOR-1 (`onboarding_checklist_consistent` misnamed).** Unchanged at `DayOneCensus.php:372-373`. The row is still a restatement of invariants 2/5/6 plus a `degraded` probe, because `OnboardingChecklistService`'s three provisioning steps are live data probes with no stored flag to disagree with (`OnboardingChecklistService.php:110-134`). Acceptable: it cannot produce a **false green** — a derived probe that agrees with the data is redundant, not wrong. The cost is a future reader mistaking it for flag-vs-data reconciliation. Log it; do not block.
- **MINOR-2 (SCOPE_REQUIRED hardcoded).** Unchanged at `DayOneCensus.php:160-164`. Acceptable today: the manifest holds exactly one SCOPE_REQUIRED entry (`ProvisioningRequiredPurposesV1.php:63`) and `assertConforms()` pins the partition at `28 + 1 + 4 + 10` (`:305-307`), so adding a second SCOPE_REQUIRED entry turns that assertion red and forces someone back to this code. The blind spot is bounded by a red test. Note the asymmetry though: this round added `conditionalPurposes()` and `softPurposes()` but not `scopeRequiredPurposes()`, so two of three reporting partitions are now authority-derived and the one that actually gates `passed` is not. Cheap to finish later.
- **MINOR-4, soft-delete half.** Unchanged at `DayOneCensus.php:333-337`: the raw `documents` count still includes trashed rows while the test's Eloquent `numberedDraftCount()` excludes them, so a trashed numbered draft is a permanent `DRIFT(1)` no operator can clear from the UI and no test can see. On its own that is acceptable for guards-only (low reachability, and a false FAIL is the safe direction). **But** it is the same missing `whereNull('deleted_at')` as r2-N2, where the consequence is a crash rather than a spurious FAIL. Since you are touching the file for r2-N2 anyway, fix both in the same edit.

---

## 4. Checked again and still clean

- **Rule 19 / money.** See (f). No monetary or quantity value is touched anywhere in the diff; `DayOneInvariantResult` remains `final readonly` and fully typed.
- **`required_purposes_tagged`** still resolves by `system_purpose` per company with `=== 1` (`DayOneCensus.php:154-158`, `:382-389`), reading the 28 REQUIRED from the authority accessor; the CONDITIONAL/SOFT counts remain report-only and now come from the authority too.
- **`refund_purposes_seeded_by_country_template`** unchanged and still country-agnostic (`:197-211`).
- **Ordering pin survives** — `FreshTenantCensusInvariantsTest.php:196-221` (chart-only company, both repositories at `location_id = NULL`) is untouched by this commit.
- **Second-of-everything.** Second company via `POST /api/v1/companies` and second location via `POST /api/v1/locations` under `actingAs` (`BuildsFreshTenantCensusFixture.php:69-102`); the new drawer test deliberately plants its drift on the **second** location, so the guard is proven on the company/location that single-fixture tests would have missed.
- **Data-meaning tests.** Every new command test asserts the census's own `expected`/`actual` strings and the per-company verdict count (`gl_linked=0`, `flagged_code=CASH_ALT`, `cash_code_methods=0`, `active_pos_locations=0`, `DRIFT(1)`), not a status code or "no exception". The ratchet liveness tests remain pure `(live, baseline) → report` calls with in-memory baselines (`TenantOnlyUniqueRatchetLivenessTest.php:68-81`), so staleness is proven without editing the committed file.
- **Module boundaries.** `DayOneCensus` now imports `App\Modules\Company\Domain\Company` (`:8`) and `App\Modules\Uom\Application\Services\UnitsProvisioningService` (`:14`). The Uom dependency is a module's public service class — the sanctioned cross-module form under rule 6, and constructor-injected (`:31-35`, no `app()`). The `Company` model import is a pre-existing pattern in this module, not a new violation class (`OnboardingChecklistService.php:8`, `TenantProvisioningService.php:7`, `TenantInitializationService.php:13`, `Tenant.php:9`), and `deptrac.yaml` does not enforce cross-module coupling.
- **CI wiring** is a one-line comment addition above an unchanged `--filter` (`.github/workflows/ci.yml:1113`); the four classes were already in the list. No manifest change in this commit.
- **Convention doc** (`docs/conventions/09-SECOND-OF-EVERYTHING.md:19`) was rewritten to match the regenerated baseline and now separates "legacy, to re-scope" from "waived as tenant-global by nature". The seven legacy entries listed there match the seven un-waived baseline entries and the ceiling of 7 exactly.

---

## What to fix before merge

Rewrite the `journal_entries` reason in `EXCLUDED_TABLES` (`TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:115`) to state the truth and point at the `OB-{year}-{seq}` collision, and log that collision as an accounting-lane defect (r2-N1); add `->whereNull('deleted_at')` to the `companies` query at `DayOneCensus.php:61-70`, which also closes the unaddressed half of r1 MINOR-4 (r2-N2). r2-N3/N4 and the three not-selected MINORs can be logged.

**VERDICT: spec ✅ + quality MERGEABLE** — merge after the r2-N1 reason text and the r2-N2 one-line `whereNull('deleted_at')`; both are contained edits inside files this commit already touches and need no new test.
