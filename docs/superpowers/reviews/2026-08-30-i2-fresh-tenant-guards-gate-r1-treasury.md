# Gate r1 (treasury/accounting lens) — Session I lane I-2, day-one tenant census

- **Commit:** `3d2cd7027` (worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/i2-fresh-tenant-guards`)
- **Brief:** `docs/sessions/session-I-process-hardening-2026-08-29/LANE-I2-fresh-tenant-guards-BRIEF.md` (r5)
- **Reviewer:** treasury-reviewer (adversarial, code-grounded). No tests re-run (orchestrator: 14 pass + 1 intended incomplete). Nothing edited outside this file.
- **Verdict:** **CHANGES-REQUIRED** — spec conformance is good; three of the invariants are weaker than the sentence they print, and one of them reports CLEAN on a treasury shape that a sibling migration in the same batch can create today.

---

## Findings

### MAJOR-1 — `one_drawer_per_pos_location_and_one_safe` counts drawer ROWS, never their GL link: a tenant that cannot take POS cash reports CLEAN
`apps/api/app/Modules/Tenant/Application/Services/DayOneCensus.php:223-238` (per-location drawer count) and `:241-254` (safe count) assert only `company_id + location_id + type + is_active`. Neither `account_id` nor `gl_account_id` is examined.

A drawer with `gl_account_id = NULL` is not a drawer:
- `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:254-261` throws `RuntimeException` ("… is not linked to a General Ledger account") for every POS payment on it.
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3915`, `:4181`, `:4409` all refuse to post on a null `gl_account_id`.
- `apps/api/app/Modules/Fiscal/Application/Services/RefundCompensationService.php:199-212` selects the cash repository with `->whereNotNull('gl_account_id')` and throws `missing_cash_repository` otherwise — so the tenant also cannot refund.
- Treasury states the hazard in its own words at `apps/api/app/Modules/Treasury/Application/Services/LocationCashRegisterProvisioner.php:44-47`: *"an un-GL-linked one is invisible to the resolver anyway — so it logs and returns null rather than minting a row that would look like provisioning succeeded."*

That shape is reachable, not hypothetical, and the two provisioners disagree about it:
- `LocationCashRegisterProvisioner::provision()` (`:100-108`) refuses to create the row when `Account::findByPurpose(..., Cash)` is null. Census would then see `active_attributed_cash_registers=0` → FAIL. Correct.
- `PaymentRepositoryProvisioningService::provisionForCompany()` (`apps/api/app/Modules/Treasury/Application/Services/PaymentRepositoryProvisioningService.php:49-55` warns, then `:62-70` `forceCreate(['account_id' => $cashAccount?->id, 'gl_account_id' => $cashAccount?->id, …])`) **creates the row anyway with both columns NULL**. Census sees `active_attributed_cash_registers=1` → **CLEAN**.
- The G-3c backfill that ships in the same batch does the same thing across the whole fleet on every `tenants:migrate`: `apps/api/database/migrations/tenant/2026_08_30_100800_backfill_company_payment_repositories.php:122-131` (null cash account → `Log::warning` only) then `:160-161` inserts `'account_id' => $cashAccountId, 'gl_account_id' => $cashAccountId` — i.e. NULL.

Why it matters: this is exactly the regression class the lane exists to pin. If a future edit moves repository provisioning **before** chart seeding in `CompanyController::store()` (today chart is step 5, `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:173`; repositories are step 6.5, `:196`), every second company gets two un-postable repositories and `tenant:census-day-one` still prints CLEAN — while `required_purposes_tagged` stays green because the chart itself is fine. An ordering regression identical in shape to N-12 would pass the detector built to catch N-12.

**Fix:** in `DayOneCensus::repositories()` require `whereNotNull('gl_account_id')` (and report `account_id` too, since `PaymentController` routes on the legacy `account_id` column while POS/GL route on `gl_account_id` — a repository with one set and the other NULL is a split). Suggested `actual` string: `active_attributed_cash_registers=1; gl_linked=1`. Add a planted-drift case in `DayOneCensusCommandTest` (`update(['gl_account_id' => null])`) so the new predicate is proven live.

### MAJOR-2 — `payment_methods_seeded` counts the flag only; it is not the cash-tender invariant I-1 defines
`apps/api/app/Modules/Tenant/Application/Services/DayOneCensus.php:262-280` asserts `count(active AND is_cash_tender) === 1`. The I-1 census migration defines cash-ness as a **coherence** property between two predicates that different consumers read — `apps/api/database/migrations/tenant/2026_08_27_100000_census_cash_tender_invariant_violations.php:24` (shape A: `code='CASH'` with `is_cash_tender=false`), `:28` (shape B, mixed-case cash family), `:34` (shape C: `is_cash_tender=true` on a non-`CASH` code).

Shape C alone, and A+C together, pass the day-one census with `active_cash_tenders=1` while `UPPER(code)='CASH'` consumers and flag consumers disagree about which tender is cash — the split the I-1 migration was written to surface. On a fresh tenant the seeder is coherent; on any staging tenant (where this command is meant to run, `docs/handoff/RUNBOOK-day-one-census.md:6`) it is not guaranteed.

**Fix:** assert set equality, not cardinality — `{active AND is_cash_tender}` == `{active AND UPPER(code)='CASH'}`, and report both counts in `actual`. One extra query; makes the row a real contract instead of a headcount.

### MAJOR-3 — the Part C ratchet is bypassable by column order
`apps/api/tests/Architecture/Support/TenantOnlyUniqueIndexScanner.php:47-49` keeps an index only when `$columns[0] === 'tenant_id'`. A new `unique(['sku', 'tenant_id'])` / `unique(['code', 'tenant_id'])` on any `CATALOGUE_TABLES` entry is the identical second-company hazard (company B cannot create what company A owns) and the scanner silently drops it — no growth, no baseline entry, green.

**Fix:** replace the leading-column test with `in_array('tenant_id', $columns, true)`; the existing `company_id` exclusion at `:50-52` already keeps company-scoped keys out, so this is strictly safer. Add a fourth liveness case (`CREATE UNIQUE INDEX … ON products (sku, tenant_id)` → reported).

### MINOR-1 — `onboarding_checklist_consistent` cannot detect the drift it is named after
`DayOneCensus.php:304-333` reads `OnboardingChecklistService::getStatus()`, whose three provisioning steps are **live data probes**: `checkTaxConfig` = `default_tax_configuration_id !== null` (`apps/api/app/Modules/Tenant/Application/Services/OnboardingChecklistService.php:110-117`), `checkPaymentMethods` = `exists()` (`:119-125`), `checkPaymentRepositories` = `exists()` (`:127-134`). There is no stored completion flag to disagree with the data, so "a step completed while its data is missing" is structurally unreachable; the row is a restatement of invariants 2, 5 and 6. The stored table the brief's invariant 9 implies (`onboarding_checklists.is_completed`, `apps/api/database/migrations/tenant/2025_12_30_115832_create_onboarding_checklists_table.php:20`) has **no model and no writer in `app/`** (grep: zero hits) — it is dead. Residual value is real but narrow: `degraded === true` catches a probe that throws.
**Fix:** rename to what it proves (e.g. `onboarding_probes_not_degraded`) or state in the description that the probes are derived, so a later reader does not treat this row as flag-vs-data reconciliation. Note the dead table for a separate cleanup lane.

### MINOR-2 — SCOPE_REQUIRED is hardcoded, not derived from the manifest partition
`DayOneCensus.php:160-164` hardcodes `SalesStampDutyPayable` + `country_code === 'TN'`. The manifest is a partition (`ProvisioningRequiredPurposesV1::entries()`, one `SCOPE_REQUIRED` entry today at `apps/api/app/Modules/CountryDefaults/Domain/Services/ProvisioningRequiredPurposesV1.php:63`). A second SCOPE_REQUIRED entry added later is silently never censused. `requiredPurposes()` is correctly consumed (28 REQUIRED, verified by counting `self::REQUIRED,` entries at `:34-61`), and a duplicate purpose is DB-impossible (`accounts_company_purpose_unique`, `apps/api/database/migrations/tenant/2025_12_06_002513_add_company_id_and_system_purpose_to_accounts.php:57`), so `!== 1` degenerates to "missing" — correct and future-proof.
**Fix:** derive the SCOPE_REQUIRED list from `entries()` and carry the scope predicate on the manifest, or add a comment pinning the hardcode to the single current entry.

### MINOR-3 — the CONDITIONAL/SOFT reporting reintroduces the exact fail-open the manifest docblock forbids
`DayOneCensus.php:165-166` and `:345-357` filter with `$entry['classification'] !== 'CONDITIONAL'` / `'SOFT'` — bare strings compared against **private** consts. `ProvisioningRequiredPurposesV1.php:96-106` documents this pattern as the reason `requiredPurposes()` exists: *"a bare string compared against a PRIVATE const. That comparison FAILS OPEN."* Impact is report-only (both numbers are informational, `passed` does not depend on them), so `conditional=0/0` would just be a silently useless line rather than a false green.
**Fix:** add `conditionalPurposes()` / `softPurposes()` accessors on the authority (additive, same precedent as `requiredPurposes()`), or accept and comment the report-only status.

### MINOR-4 — census reads raw tables and so bypasses model scopes the test applies
The census uses `DatabaseManager::table()` throughout; the test uses Eloquent. Two divergences:
- `documents` — `DayOneCensus.php:286-290` counts soft-deleted rows (`Document` uses `SoftDeletes`, `apps/api/app/Modules/Document/Domain/Document.php:115`), while the test's `numberedDraftCount()` (`tests/Feature/Tenant/FreshTenantCensusInvariantsTest.php:384-390`) excludes them. A trashed numbered draft yields a permanent `DRIFT(1)` the operator cannot clear from the UI, and the test cannot see it.
- `locations` — `DayOneCensus.php:214-219` selects `pos_enabled` locations with **no** `is_active` filter; the test at `tests/Feature/Tenant/FreshTenantCensusInvariantsTest.php:164-168` filters `is_active = true`. Nothing deactivates a drawer when a location is deactivated (`LocationController::update()` only ever provisions, `apps/api/app/Modules/Company/Presentation/Controllers/LocationController.php:294`), so today this is benign — but the two predicates disagree, and the test asserts the census rows pass, so census-side predicate drift here is untestable.
**Fix:** add `->whereNull('deleted_at')` to the documents count and align the location predicate with the test (or state in the description that inactive POS-enabled locations are deliberately in scope).

### MINOR-5 — `status = 'draft'` magic string (rule 9)
`DayOneCensus.php:288` uses the literal `'draft'` while the same file correctly uses `RepositoryType::CashRegister->value` at `:226`. `DocumentStatus::Draft = 'draft'` (`apps/api/app/Modules/Document/Domain/Enums/DocumentStatus.php:9`). Same literal in the test at `tests/Feature/Tenant/FreshTenantCensusInvariantsTest.php:387`.
**Fix:** `DocumentStatus::Draft->value`.

### MINOR-6 — a tenant with zero companies exits 1 without a parseable verdict line
`apps/api/app/Console/Commands/DayOneCensusCommand.php:36-42` returns `FAILURE` regardless of `--fail-on-drift`, and prints `DAY-ONE CENSUS: no companies exist …` — which does not match the `DAY-ONE CENSUS <tenant> <company>: CLEAN|DRIFT(n)` shape the runbook tells the operator to grep (`docs/handoff/RUNBOOK-day-one-census.md:8`). Harmless under `tenants:run` (exit discarded) but it breaks the stated exit-code contract for the direct form.
**Fix:** honour `--fail-on-drift` for the empty case, or document the exception in `$description` and the runbook.

---

## Checked and clean (no finding)

- **Rule 19 / money.** No float, no `parseFloat`, no `number_format`, no `bcmath`, no `CurrencyScaleResolverInterface` usage anywhere in the diff. The census reports counts only; `DayOneInvariantResult` (`apps/api/app/Modules/Tenant/Application/DTOs/DayOneInvariantResult.php:8-16`) is `final readonly`, fully typed, no `mixed`. The command's table is count/label output only. **Clean.**
- **`required_purposes_tagged` is a real contract.** Resolves by `system_purpose` (`DayOneCensus.php:335-342`), never by account code; `=== 1` per company; 28 REQUIRED read from `ProvisioningRequiredPurposesV1::requiredPurposes()` (the authority accessor, not a caller-side string filter). The vacuous-pass risk (a manifest whose REQUIRED set shrinks to `[]` ⇒ `required=0/0` CLEAN) is covered outside this lane by `tests/Unit/Accounting/SystemAccountPurposeTest.php:13-27` and `tests/Feature/Accounting/SeededChartManifestRequiredPurposeCompletenessTest.php`. **No duplicate-purpose blind spot** (see MINOR-2).
- **`refund_purposes_seeded_by_country_template`** resolves `SalesReturn` / `RefundWriteOff` by purpose per company, country-agnostically (`DayOneCensus.php:191-205`) — so an FR or generic registration is held to the same bar, and a future template that drops either fails here rather than at `RefundCompensationService.php:187-195`. Only TN is exercised by the fixture; that is a coverage note, not a defect, because the predicate carries no country branch.
- **Safe cardinality** — `=== 1` at `DayOneCensus.php:241-251`: a company with two active safes FAILS. Correct.
- **`is_active`** is checked on every repository, payment-method and account query (`:227`, `:244`, `:264-269`, `:340`).
- **The ordering pin survives N-12/G-3c.** `tests/Feature/Tenant/FreshTenantCensusInvariantsTest.php:196-221` seeds a chart-only company with no locations and asserts both repositories land at `location_id = NULL` — the pre-N-12 shape — proving attribution is an ordering property. This still holds because `PaymentRepositoryProvisioningService::defaultLocationId()` (`:92-102`) returns null on an empty location set; `CompanyController::store()` passes `$location->id` explicitly (`:196`) and registration creates Main first. Good.
- **Second company via the real path** (`POST /api/v1/companies` under `actingAs`, `tests/Feature/Tenant/Concerns/BuildsFreshTenantCensusFixture.php:69-87`) and **second POS location via the real path** (`POST /api/v1/locations` → `LocationController::provisionCashRegisterIfPosEnabled`, `:89-102`). Second-of-everything satisfied.
- **`no_numbered_drafts` after the numbering-per-company migration.** `documents` numbering is now company-scoped (`apps/api/database/migrations/tenant/2026_08_30_100500_enforce_company_scoped_document_numbers.php` present in tree); the census filters `company_id` (`:287`), so the query is right. The draft is created through the real HTTP `documents/auto-save` service path (`FreshTenantCensusInvariantsTest.php:307-322`), not a factory. Good.
- **Verdict line** `DAY-ONE CENSUS {tenant} {company}: CLEAN|DRIFT(n)` (`DayOneCensusCommand.php:73`) is emitted per company and asserted verbatim in three command tests. `--fail-on-drift` truthiness handles the `tenants:run` `'1'` string (`:76-80`). Exit 1 only when the option is set and drift > 0; report-only default proven by `DayOneCensusCommandTest.php:96-110`.
- **Connection liveness** — `DayOneCensusCommandTest.php:112-136` proves the census resolves the default connection at call time, not at construction (the `tenants:run` reality). Good test; not a tautology.
- **Repository `currency`** is not written by the provisioner but is NOT NULL in the DB; `PaymentRepository::booted()` (`apps/api/app/Modules/Treasury/Domain/PaymentRepository.php:103-120`) defaults it from the owning company and throws otherwise, so no census gap there.
- **Module boundaries** — `DayOneCensus` (Tenant/Application) imports only enums and a Domain service from Accounting / CountryDefaults / Treasury; `deptrac.yaml:17-22` explicitly does not enforce cross-module coupling and `ModuleApplication → ModuleDomain` is allowed. No violation. (It does read other modules' tables raw — see MINOR-4 for the concrete cost.)
- **CI wiring** is append-only: four class tokens appended at the end of the `backend-test-pgsql --filter` line, no reflow (`.github/workflows/ci.yml:1114`). Manifest raise 29→31 with a written note and `gated_ceiling` 1223→1225 (`apps/api/tests/feature-lane-manifest.json`). Baseline JSON is 9 entries, every one carrying a non-empty `waiver` (enforced at `tests/Architecture/Support/TenantOnlyUniqueBaseline.php:38-41`); `products`/`product_variants(sku)`/`partners(vat_number)` correctly absent post-G-3a, `documents` correctly absent post-Session-J, `payment_repositories` correctly absent (company-scoped code key).
- **Ratchet staleness direction** is real, not asserted-into-existence: `TenantOnlyUniqueRatchetChecker::check()` is a pure `(live, baseline) → report` function and the stale case is proven with an in-memory baseline without touching the committed file (`TenantOnlyUniqueRatchetLivenessTest.php:67-81`). Correct per convention 08.

---

## What to fix before merge

Make invariant 5 require `gl_account_id` (MAJOR-1) and invariant 6 assert code/flag coherence (MAJOR-2), each with a planted-drift case in `DayOneCensusCommandTest`; change the ratchet scanner's leading-column test to `in_array('tenant_id', …)` (MAJOR-3). The MINORs can land in the same round or be logged.

**VERDICT: spec ✅ (all nine invariants present and wired to the shared service; second-of-everything, CI, manifest and baseline conform to the r5 brief) + quality CHANGES-REQUESTED.**
