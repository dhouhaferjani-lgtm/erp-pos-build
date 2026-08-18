## M7 Adversarial Merge-Gate Review — Round 1
**Base:** `7d85232cc` → **HEAD:** `16bc07b8c` · **Lenses:** treasury, tenancy-authz, frontend-conventions

### What I verified independently (not taken from the report)
- **Backend union reproduces exactly.** I ran all 36 §1.1 files on SQLite by path: **202 passed / 8 skipped / 1,682 assertions** once the one uncommitted in-flight test is discounted (my raw totals were 105+7skip/1300 and 97+1skip+1fail/383 → 1683−1 = 1682). The report's SQLite numbers are honest and reproducible.
- FE set: 4 files, 42 tests green (41 at HEAD + 1 uncommitted). PHPStan/Pint clean on every changed backend file, including the ones outside the declared scope. No float touches money/quantity anywhere in the branch (Rule 19 not otherwise engaged — Phase A is non-monetary). No `admin-sensitive` rate-limiter gap; middleware, provider, route-file and i18n three-place registration all wired correctly.

---

### Findings register

**1 — P1 · CONFIRMED · `docs/handoff/CODEX-DISPATCH-country-defaults-phase-a-2026-08-10.md:208-239` + `apps/api/tests/Feature/Accounting/SeedChartsCommandTest.php:219-311`**
The M7 union omits every *modified* pre-existing Phase A regression suite, and §1.1 was never amended as the brief requires. `SeedChartsCommandTest` gained four Phase A tests — `test_template_provisioning_refuses_to_mutate_existing_company_charts`, the two legacy-dry-run tests, and the previewer non-persistence test — and M5's own evidence was explicitly "the exact eight M5 files **plus `SeedChartsCommandTest`**" (report §M5, 60/218). M7 claims "a clean rerun of the complete accumulated M1–M6 evidence set", yet the manifest never runs it. Same for `CompanyTaxProvisioningServiceTest` (+32, S-1 delegation), `OnboardingTaxStepTest`, `EndToEndTaxResolutionTest`, `CountryTaxConfigurationRegistryTest`.
*Failure scenario:* someone removes the `provisioning_enabled` guard at `SeedChartsCommand.php:97-104`; the entire 36-file union still goes green and the promotion gate certifies branch-wide assertion 8(4) ("no Phase A command mutates an existing company's accounts") while the only test proving the command-level half of that constraint is never executed. I ran the four excluded suites — they pass today (23/111), so this is gate incompleteness, not a live regression.
*Fix:* amend §1.1 + `PHASE_A_TESTS`, rerun on both engines, restate counts.

**2 — P1 · CONFIRMED · `docs/handoff/progress/country-defaults-phase-a.progress.yaml:88-99` (commits `0009efebd` → `3092413df`) + report §"M7 authenticated-HTTP verification fixture"**
The `blocked_owner` gate recorded on 2026-08-12 — *"manufacturing these via CLI or test fixtures would violate the certification authority invariant; Owner must complete Release 1 authenticated certification"* — was deleted on 2026-08-17 and replaced by an unattributed "Ruling:" in the report, with no owner decision recorded anywhere in the branch. The resolution is self-contradictory inside one section: it states *"A disposable active `super_admin` logged in through `POST /api/v1/admin/auth/login`"* and then *"No synthetic actor or direct database certification was manufactured."* A disposable super_admin created for the fixture **is** a synthetic actor.
Worse, the harness that produced the green — `scripts/phase-a-authenticated-verifier-fixture.sh` — is **untracked** (`git ls-files` miss; created 19:53 today, after the evidence commit at 19:41). Manifest item 6's `country-defaults:verify` zero-exit is therefore not reproducible from the branch as committed, and the literal item-6 command against the configured central DB still exits non-zero.
*Failure scenario:* the branch merges on an owner gate that the implementer closed on its own authority, backed by evidence no reviewer or operator can re-run.

**3 — P2 · CONFIRMED (by experiment) · `apps/api/tests/Feature/CountryDefaults/ProvisioningFlagMatrixTest.php:38,49,60,74,86,114,138`**
Branch-wide assertion 8(6) requires a named test proving *both* flags remain default-off. `DefaultsEditorLoginFlagTest.php:18` genuinely asserts the default for `external_editors_enabled`; `ProvisioningFlagMatrixTest` never asserts a default — every case sets `provisioning_enabled` explicitly, and no test anywhere reads it un-overridden (grep confirms only explicit `config([...])` writes).
*Bypass proof:* I re-ran the union subset with `COUNTRY_DEFAULTS_PROVISIONING_ENABLED=true COUNTRY_DEFAULTS_EXTERNAL_EDITORS_ENABLED=true`. The external-editors half **caught it** (`DefaultsEditorLoginFlagTest` failed — "Failed asserting that true is false"). The provisioning half passed clean. A config/env default flipped to `true` — i.e. template provisioning silently live in Release 1 — ships through the gate undetected.

**4 — P2 · CONFIRMED · brief `PHASE_A_PHP_SCOPE` (M7 item 4a) vs. actual diff**
The declared static scope expands to exactly the 86 files the report cites; the branch changes **93** backend production files. Excluded: `app/Console/Commands/SeedChartsCommand.php`, `app/Console/Commands/BackfillChartPurposesCommand.php`, `app/Modules/Accounting/Application/Services/LegacyExistingChartRepairPreviewer.php` (new, 94 lines, owns the rollback-only preview seam), `app/Modules/Taxation/Application/Services/CompanyTaxProvisioningService.php`, `app/Modules/Expense/Application/Services/ExpenseCategoryProvisioningService.php`, `database/seeders/Contracts/ChartOfAccountsSeederContract.php`, `database/seeders/CountryDefaultsChartOfAccountsSeeder.php`.
*Bypass I tried that FAILED:* I ran PHPStan L8 and Pint over all seven — **no errors, `{"result":"pass"}`**. So no live defect; the finding is that the gate's own static scope does not cover new code it introduced, including the M5 rollback seam.

**5 — P2 · PLAUSIBLE · `apps/api/composer.json:9` (`"ext-intl": "*"` added to `require`) vs `.github/workflows/ci.yml:40,118,156,234,376,678,798,1030`**
The branch promotes `ext-intl` to a hard composer platform requirement (driven by `CanonicalCoaSerializer.php:106-112`, which refuses a polyfill Normalizer). No vendor package previously hard-required it — all existing references are `suggest`. Every CI job's `setup-php` extension list is `dom, curl, libxml, mbstring, zip, pcntl, pdo, pdo_pgsql, redis` — **no `intl`** — and every job runs `composer install --no-interaction --prefer-dist` **without** `--ignore-platform-reqs`.
*Failure scenario:* merge to `dev` (auto-deploy lane) → all 8 CI jobs fail at dependency install with "requires ext-intl * but it is missing". Production `Dockerfile:73` does install `intl`, so the container is safe; CI is the exposure. The M7 manifest has no `composer validate` / CI-parity item, and `composer.json` is absent from both the brief's §1 file inventory and `PHASE_A_PHP_SCOPE`, so the change entered the branch undeclared. **Verify before merge:** add `intl` to the CI extension lists (or confirm the runner image already loads it) — this is the one finding I could not settle from inside the worktree.

**6 — P2 · CONFIRMED · working tree at review time**
The gate is being run against a branch that is not frozen. `git status` at HEAD: modified `apps/web/src/features/admin/country-defaults/pages/TemplateListPage.tsx` (production — adds `lifecycleMutationPending` double-submit guards), modified `TemplateListPage.test.tsx` and `VerifyCountryDefaultsCommandTest.php`, modified tracked build artifact `apps/web/tsconfig.tsbuildinfo`, plus the untracked fixture script from finding 2. The uncommitted backend test makes the union **red** on disk (1 failed) even though HEAD is green.
*Consequence:* the tsbuildinfo churn contradicts the report's "The generated `tsconfig.tsbuildinfo` change was mechanically reversed after the build", and CLAUDE.md rule 10 (no build artifacts in commits) is at risk. Freeze the branch, commit or discard, and re-establish the manifest against a clean tree.

**7 — P3 · CONFIRMED · commit `8421bc5c9` (10 files under `apps/api/app/Modules/CountryDefaults/Presentation/Requests/`)**
M7 forbids new implementation; this commit edits production files (import/docblock normalization driven by Pint). Non-behavioral and pint-clean, and I checked the obvious hazard — no file that now imports `Illuminate\Contracts\Validation\Rule` also calls `Rule::` statically (`CloneTemplateRequest`, `ListDefaultsEditorsRequest`, `PublishTemplateRequest`, `ShowTemplateRequest`, `UpdateTemplateRequest`, `ResetDefaultsEditorCredentialsRequest`, `ValidateTemplateRequest` all clean) — **that bypass FAILED**. Recorded only because it widens the M7 envelope without a covering test.

**8 — P3 · CONFIRMED · `apps/api/database/seeders/ExpenseCategorySeeder.php:75-110`**
The loud-failure conversion is **not flag-gated**: with `provisioning_enabled=false` (the Release 1 default), a company whose chart lacks a mapped expense code now throws `DomainException` at creation where it previously skipped. This is a live tenant-facing behavior change shipping in Release 1, against the branch's "both flags off ⇒ no behavior change" activation story and non-goal N-D. It **is** disclosed (`OWNER-CHECKLIST-CONSOLIDATED-2026-08-01.md` §G5 and the M5 P3 ticket), so this is a visibility note, not a hidden change.

**9 — P3 · CONFIRMED · `apps/api/.env.example`, `.env.production.example`**
Neither documents `COUNTRY_DEFAULTS_PROVISIONING_ENABLED` or `COUNTRY_DEFAULTS_EXTERNAL_EDITORS_ENABLED`, though checklist steps G1/G4/G5 instruct operators to set and flip them.

**10 — P3 · CONFIRMED · `apps/api/tests/Feature/CountryDefaults/TemplateImmutabilityTest.php:446`, `apps/api/database/migrations/2026_08_11_100300_import_legacy_coa_templates_as_drafts.php:11,16`**
`app()` in the M7 fixture-restoration hook and in the bootstrap migration. Migrations/tests are the customary exception to the constructor-injection rule; noted for completeness only.

### Lens coverage
- **treasury** — applied: COA template content, protected-code registry, 41-purpose partition, timbre/absorber semantics, provisioning charts, expense-category GL mapping. Assertions 8(1), 8(2), 8(4), 8(5) are non-vacuous (AST/token-level guards, live enum counts, real-service runs); 8(6) is half-vacuous (finding 3).
- **tenancy-authz** — applied: `central_admin` alias, three-role matrix, `throttle:admin-sensitive` registered at `AppServiceProvider.php:268`, central-connection-under-tenancy coverage, additive migrations discovered by the production top-level path. The `routes/api.php:44` widening of `/logout` + `/me` from `super_admin` to `central_admin` is the brief's sanctioned minimal auth edit and `EnsureCentralAdmin` preserves the `is_active` check.
- **frontend-conventions** — applied: namespace registered in all three i18n locations, en+fr complete with `ar` falling back to `en` per existing precedent, nav role-filtered from the single `adminRoutePolicies` manifest, design tokens used, no hardcoded user-facing strings introduced. No FE finding at HEAD.

### Bypasses attempted that FAILED to find a defect
Rule-import shadowing in the normalized FormRequests · broken `new ChartOfAccountsService(...)` / `new CountryTaxConfigurationRegistry` call sites after the constructor changes (none exist) · PHPStan/Pint on the seven out-of-scope files · forcing `external_editors_enabled=true` to prove assertion 8(6) vacuous (the test correctly caught it) · reproducing the SQLite union counts (they match the report to the assertion).

### Required before merge
Findings 1 and 2 block. 3–6 must be closed in the same round: add the default-off assertion, widen the test union and static scope, settle the `ext-intl`/CI question, and re-run the manifest against a frozen tree.

VERDICT: CHANGES-REQUIRED
