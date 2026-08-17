# Codex A→Z dispatch — Country Defaults (super admin), **Phase A** (2026-08-10)

> ## ⚙️ EXECUTION MODE — SELF-REVIEWING WAVE (read this before anything else)
> This wave runs under **`docs/handoff/SELF-REVIEW-HARNESS.md`**. You do NOT hand back to a human
> between milestones. At the end of every milestone you run the adversarial review YOURSELF by
> invoking Opus through the CLI bridge `scripts/adversarial-review.sh` (it calls
> `claude -p --model opus`), read its register, and loop scoped fix rounds until ACCEPT — then move on.
> - **Track state in `docs/handoff/progress/country-defaults-phase-a.progress.yaml`** — read it first,
>   update it after every milestone (status, commit SHA, verdict path, fix_rounds). It is your resume point.
> - Wherever a milestone says a reviewer "gates" it — that is now the harness's self-review loop (the
>   milestone's `review_lenses` in the YAML are the lenses to apply).
> - **STOP and escalate only at the three harness STOP conditions:** fix rounds exhausted, an owner gate
>   (standing constraints: `defaults_editor` stays flag-off pending MFA; NO existing-company mutation, ever),
>   or an architecture contradiction. Set the YAML `status` + `blockers` and end your run.
> - Pre-dispatch (human, not you): the owner spec read-through, and re-pin `base_sha` (V-1). If `origin/dev`
>   advanced past `7d85232cc`, re-run M0's reconciliation before coding.
> - Per-milestone registers go to `docs/handoff/reviews/country-defaults-phase-a/`. Branch NOT merged, NOT pushed.

**Revision 2.** Revision 1 was adversarially reviewed and REJECTED (4 P1 + 6 P2) — register:
`docs/superpowers/specs/reviews/2026-08-10-country-defaults-phase-a-handover-review.md`. Every
prescribed resolution is adopted here (F-1..F-10), with one recorded dispute (§8). Seven of the
eight former VERIFY-AT-DISPATCH questions are now settled from repository evidence and removed.

**Model/effort (owner directive):** Codex SOL 5.6, HIGH effort. Workhorse mode: implement
end-to-end, TDD red-first, milestone by milestone, handing back at **every** milestone for the
Opus/reviewer quality gates below (house rule: adversarial review at every milestone).

**Authoritative design:** `docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md`
— **Rev 14, FINAL VERDICT ACCEPT** (`ed6fe896f`), after 14 rounds / 62 findings, all resolved.
Register: `docs/superpowers/specs/reviews/2026-08-08-country-defaults-spec-review.md` (Round 14).

**Citation convention:** every path in this brief is **repo-root-relative** from
`/Users/houssamr/Projects/syneriva/apps/erp` — i.e. begins `apps/api/`, `apps/web/`, `.claude/`,
or `docs/`. All line anchors were verified against **`7d85232cc`** (= `HEAD` = `origin/dev` at
authoring time). Re-pin before dispatch (§7 V-1).

**Read before writing any code, in this order:**
1. The spec (283 lines; every section is load-bearing).
2. §0 + §0.1 of this brief — post-spec repo drift and the settled decisions.
3. `.claude/context/architecture.md` (module structure `:18-43`, cross-module rule `:45-52`) and
   `docs/conventions/03-AUTHORIZATION.md`.

**Do NOT relitigate anything the spec marks as an owner ruling or a closed finding.** Locked:
template-library + country-assignment model; framework + COA first; scoped `defaults_editor`
central-admin role; F-01 (nine treasury literal instrument codes stay protected literals in v1);
F-07 (templates apply to *newly created companies* only); F-18 (certification metadata at publish,
four-eyes deferred). If you believe a locked ruling is wrong, write it in the report and continue.

---

## §0 — Post-spec repo drift (settled facts, not open questions)

The accounting-gaps lanes landed **after** the spec froze. `origin/dev` is `7d85232cc`
("Merge branch 'codex/accounting-gaps-cghi' into dev"), containing the SEEDS lane (A/B/D/E/F) and
C/G/H/I. Each row below is **resolved** — implement it, do not re-ask.

| # | Spec statement | Reality at `7d85232cc` | Resolution (binding) |
|---|---|---|---|
| **D-1** | §5.4: TN missing `SalesDiscount`; FR missing `CostOfGoodsSold`, `GeneralExpense`, `SalesDiscount`, `CustomerAdvance`, `SupplierAdvance`, code `624` | **All six are closed.** `apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php:179,190,235,277,321`; `apps/api/database/seeders/FranceChartOfAccountsSeeder.php:181,202,289,312,327,391`; `apps/api/database/seeders/GenericChartOfAccountsSeeder.php:135,143,165,178,211` | **The result is known and stated now (F-5): the missing-REQUIRED set is EMPTY for TN, FR and Generic (`[]`,`[]`,`[]`), and the old content deltas are EMPTY.** M0 still *reproduces* this mechanically (§M0) and M4's suite stays data-driven from the manifest so any future manifest addition re-opens it. **Human HTTP publish remains mandatory even with zero content edits** — only an authenticated publish request can set `certified_by`/`published_at`/`content_hash`/scope (spec §4.2.6, §6). |
| **D-2** | §5.2: the three seeder classes are "frozen forever as compat artifacts" | They were edited 2026-08-09/10 (`2bee58c48`, `977c23802`, `22e630654`, `2e45b1b10`) and carry **no** deprecation marker (`apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php:13-22`, `apps/api/database/seeders/FranceChartOfAccountsSeeder.php:13-21`, `apps/api/database/seeders/GenericChartOfAccountsSeeder.php:12-18`) | Freeze point = **`7d85232cc`**. Docblock + static-guard tasks are **assigned** (M1 task 7, M5 task 6 — F-6). **Replay semantics (state verbatim in the M0 addendum):** tenant migration `apps/api/database/migrations/tenant/2026_03_23_300000_fix_existing_tunisian_companies_tax_setup.php:44-53` instantiates the **live** `TunisiaChartOfAccountsSeeder` **only when a Tunisian company has zero accounts**. A not-yet-run migration therefore seeds the definition frozen at the *deployed source version*, not the bytes of its authoring date; already-run migrations do not rerun. It must remain the **only** production replay consumer of a frozen seeder. |
| **D-3** | §4.2.1/§6: `CountryAccountingCapabilities` is a new central versioned registry, timbre = `{TN}` | Taxation owns the live predicate (`apps/api/app/Modules/Taxation/Application/Registries/CountryTaxConfigurationRegistry.php:22-33,48-50`) and both tax-surface reads consume it (`apps/api/app/Modules/Taxation/Presentation/Controllers/TaxConfigurationController.php:245-265`) | **Dependency direction settled (F-1) — see §0.1 S-1.** One authority, contract in `Shared/Contracts`, implementation in the new module, Taxation delegates. |
| **D-4** | §4.2.4: protected demo-consumer codes = five (`613`,`615`,`616`,`624`,`626`) for TN+FR; `*` has none | `apps/api/database/seeders/ExpenseCategorySeeder.php:35-65` — French-plan map has **seven** non-null codes (`613`,`615`,`616`,`624`,`626`,**`6061`**,**`6064`**); generic map has **four** (`6130`,`6170`,`6250`,`6256`); country selection at `:107-115` is TN/FR vs default | Protected-code registry carries **three** country variants (TN, FR, wildcard/generic) — TN and FR share the demo codes but **not** all instrument-code variants; the wildcard variant now has demo-consumer codes the spec assumed absent. Drift test asserts registry ⇄ both `ExpenseCategorySeeder` maps. |
| **D-5** | §5.2: the silent `GeneralExpense` fallback "becomes a loud failure" | `apps/api/database/seeders/ExpenseCategorySeeder.php:73-91` cannot distinguish an absent mapped code from a deliberate `null` before `$account ??= $fallback`, and silently `continue`s when no account exists at all | Re-scoped: a **deliberate `null` mapping may resolve `GeneralExpense`** (by design); an **absent mapped code** and a **wholly absent COA** must fail loudly. |
| **D-6** | §1: `requiredPurposes()` = 11 of 41 | It returns **13** (`apps/api/app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php:155-181`); the enum still has exactly **41** cases (`:14-103`) | The point hardens: `requiredPurposes()` is **not** the operational manifest. The conformance suite must not key off it. |
| **D-7** | §2 N3: G+H are a named precondition for the certification claim | G+H shipped, **but** `settings.update` authorizes a request accepting `country_code` (`apps/api/app/Modules/Tenant/Presentation/Requests/UpdateCompanySettingsRequest.php:15-17,41`), the controller writes it (`apps/api/app/Modules/Tenant/Presentation/Controllers/CompanySettingsController.php:103-154`), and `tax_configurations` is country-scoped with no `company_id` (`apps/api/database/migrations/tenant/2025_12_30_100000_create_tax_configurations_table.php:13-38`) | **Settled, no dispatch question (F-7) — see §0.1 S-5.** |
| **D-8** | §5.4/N1: existing-tenant purpose gaps are separate backfill tickets | `apps/api/app/Console/Commands/BackfillChartPurposesCommand.php` and `apps/api/database/migrations/tenant/2026_08_10_090000_backfill_chart_purposes.php` exist and create/map purpose rows on **existing** charts | N1 unchanged (Phase A never mutates an existing company). **No Phase A resolver, service, or test may infer live-chart ⇄ template parity** — backfilled tenants diverge from every template by construction. |

**Deliverable of §0:** the branch's first commit is the reconciliation addendum
`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design-ADDENDUM-2026-08-10.md`
recording D-1..D-8 with reproduced numbers and the §0.1 decisions. **Do not edit the ACCEPTed spec
body**; the addendum is the delta record and everything downstream cites it.

## §0.1 — Settled decisions (formerly VERIFY-AT-DISPATCH; do not re-open)

**S-1 — Timbre authority shape (F-1).** Exactly one production authority for the timbre predicate
and the capability version:
- `apps/api/app/Shared/Contracts/CountryDefaults/CountryAccountingCapabilities.php` — **interface**
  exposing `supportsStampDuty(string $countryCode): bool` and `version(): string`.
- `apps/api/app/Modules/CountryDefaults/Application/Services/CountryAccountingCapabilitiesService.php`
  — the **sole implementation**, holding the `{TN}` predicate and the version scalar together.
- Bound in `apps/api/app/Modules/CountryDefaults/Providers/CountryDefaultsServiceProvider.php`.
- `CountryTaxConfigurationRegistry` **constructor-injects the shared contract**,
  **`supports_stamp_duty` is removed from its `MAP`**, and `supportsStampDuty()` delegates. Only
  the seeder-class map stays in Taxation.
- Rationale (binding): an implementation in `Shared/Contracts` would violate `.claude/context/architecture.md:45-52`
  (interfaces only); importing the Taxation registry from the new module would make the central
  certification kernel depend on a tenant-tax application class; leaving the boolean in the
  Taxation `MAP` would create the duplicate authority this lane forbids.
- **Required test:** a source/architecture test that FAILS if a second production predicate or a
  second capability country-set appears anywhere.

**S-2 — Module placement + file map (F-2).** A **new module** rooted at
`apps/api/app/Modules/CountryDefaults/` (Domain/Application/Infrastructure/Presentation per
`.claude/context/architecture.md:18-43`). The existing `apps/api/app/Modules/Admin/` is a
monitoring-only slice (`Presentation/Controllers/MonitoringController.php` is its only controller)
and `apps/api/app/Http/Controllers/Api/Admin/` holds legacy fleet/vertical controllers — **neither
is the home for a domain-generic template lifecycle**. Only central-authentication integration
(middleware + the auth-route edit) stays under `apps/api/app/Http/`. Full path map in §1.

**S-3 — `admin/auth` minimal edit (F-3).** See M3 task 3. Login stays public + throttled; **only**
the nested logout/`me` group swaps `'super_admin'` for the central-admin check while retaining
`auth:sanctum-admin`; the full-admin group is byte-identical.

**S-4 — Release-1 / Release-2 activation contract (F-4).** See M5 task 5. `apps/api/config/country_defaults.php`,
env `COUNTRY_DEFAULTS_PROVISIONING_ENABLED` (default **false**), uncached call-time reads at both
company-creation paths; Release 2 is a **deployment configuration change**, rollback is restoring
`false`.

**S-5 — Country-code mutability (F-7).** **Phase A does not wait for, and does not implement,
`country_code` immutability** (a separate settings-guards lane owns it). Phase A's certification
claims cover **unconditional template-layer timbre invariants only**. The tenant-side stamp
capability check is a **usability guard, never an authorization control**, until that lane closes
(`docs/superpowers/tickets/2026-08-10-country-code-mutable-authz-authority.md:8-23,33-35`). This
limitation goes verbatim into the M0 addendum and the M7 checklist.

**S-6 — Effort envelope.** **9–12 dev-days**, per the accepted spec §11. Report actuals.

---

## §1 — Prescribed file map (F-2)

Backend module — `apps/api/app/Modules/CountryDefaults/`:

```
Domain/ValueObjects/CertificationScope.php
Domain/Enums/TemplateDomain.php
Domain/Enums/TemplateStatus.php
Domain/Services/ProvisioningRequiredPurposesV1.php          # the 41-entry manifest
Domain/Registries/ProtectedAccountCodeRegistry.php           # 3 country variants (D-4)
Domain/Exceptions/TemplateRecertificationRequiredException.php
Domain/Exceptions/TimbreCountryRequiresExactAssignmentException.php
Application/Services/CountryAccountingCapabilitiesService.php  # S-1 sole implementation
Application/Services/CanonicalCoaSerializer.php
Application/Services/TemplatePublishingService.php
Application/Services/TemplateAssignmentService.php
Application/Services/CountryTemplateResolver.php
Application/Services/StaticCountryCatalogProvider.php          # versioned central ISO-3166 (§3.3)
Infrastructure/Models/AdminTemplate.php
Infrastructure/Models/AdminTemplateAccount.php
Infrastructure/Models/CountryTemplateAssignment.php
Infrastructure/Seeders/TemplateChartOfAccountsSeeder.php
Presentation/Controllers/TemplateController.php
Presentation/Controllers/TemplateRowController.php
Presentation/Controllers/AssignmentController.php
Presentation/Controllers/DefaultsEditorController.php
Presentation/Console/VerifyCountryDefaultsCommand.php
Presentation/Requests/ListTemplatesRequest.php             # GET /templates?domain=
Presentation/Requests/CreateTemplateRequest.php            # POST /templates
Presentation/Requests/CloneTemplateRequest.php             # POST /templates/{id}/clone
Presentation/Requests/ShowTemplateRequest.php              # GET /templates/{id}
Presentation/Requests/UpdateTemplateRequest.php            # PUT /templates/{id}
Presentation/Requests/UpsertTemplateRowsRequest.php        # PUT /templates/{id}/rows
Presentation/Requests/ValidateTemplateRequest.php          # GET /templates/{id}/validation?scope=
Presentation/Requests/PublishTemplateRequest.php           # POST /templates/{id}/publish
Presentation/Requests/ListAssignmentsRequest.php           # GET /assignments?domain=
Presentation/Requests/AssignTemplateRequest.php            # PUT /assignments/{countryCode}
Presentation/Requests/ListDefaultsEditorsRequest.php       # GET /editors
Presentation/Requests/CreateDefaultsEditorRequest.php      # POST /editors
Presentation/Requests/UpdateDefaultsEditorRequest.php      # PUT /editors (disable / re-enable / role change)
Presentation/Requests/ResetDefaultsEditorCredentialsRequest.php  # F-17 reset-credentials action
Presentation/Resources/TemplateSummaryResource.php         # list rows
Presentation/Resources/TemplateResource.php                # header + rows + certification metadata
Presentation/Resources/TemplateAccountResource.php
Presentation/Resources/TemplateValidationReportResource.php
Presentation/Resources/CountryTemplateAssignmentResource.php
Presentation/Resources/DefaultsEditorResource.php
Presentation/routes.php            # loaded by the provider
Providers/CountryDefaultsServiceProvider.php
```

**Request-count reconciliation (exact):** spec §8 defines **15** verb/route operations (the table's
13 rows, with the final `GET/POST/PUT /editors` row counting as three). `DELETE /templates/{id}`
and `POST /templates/{id}/archive` are bodyless and take no FormRequest, leaving **13** operations,
each mapped 1:1 to one of the first thirteen Request files above (the read endpoints' Requests
carry route/query validation and the authorization check). `ResetDefaultsEditorCredentialsRequest`
is a **14th** file: spec §7 F-17 requires a distinct reset-credentials lifecycle action that §8's
`/editors` row collapses. **13 spec-§8 Requests + 1 F-17 Request = 14 Request files.**

Wiring and satellites:

| artifact | path |
|---|---|
| Shared contract | `apps/api/app/Shared/Contracts/CountryDefaults/CountryAccountingCapabilities.php` |
| Provider registration | `apps/api/bootstrap/providers.php` (append `CountryDefaultsServiceProvider::class`) |
| Route loading precedent | `apps/api/app/Modules/SupportAccess/Providers/SupportAccessServiceProvider.php:27-52` (`loadRoutesFrom(__DIR__.'/../Presentation/routes.php')` in `boot()`, commands registered under `runningInConsole()`) |
| Central migrations | `apps/api/database/migrations/2026_08_11_100000_create_admin_templates_table.php`, `apps/api/database/migrations/2026_08_11_100100_create_admin_template_accounts_table.php`, `apps/api/database/migrations/2026_08_11_100200_create_country_template_assignments_table.php` — **directly in `database/migrations/`, NOT a `central/` subdirectory** (see the warning below) |
| Bootstrap data migration | `apps/api/database/migrations/2026_08_11_100300_import_legacy_coa_templates_as_drafts.php` |
| Verify command | `apps/api/app/Modules/CountryDefaults/Presentation/Console/VerifyCountryDefaultsCommand.php` (signature `country-defaults:verify`), registered in the provider |
| Golden exporter | `apps/api/app/Modules/CountryDefaults/Infrastructure/Export/LegacyCoaGoldenExporter.php` |
| Golden artifacts | `apps/api/tests/Fixtures/CountryDefaults/goldens/tn.legacy-v1.txt`, `fr.legacy-v1.txt`, `generic.legacy-v1.txt` (each with a sibling `.sha256`) |
| Config | `apps/api/config/country_defaults.php` |
| Middleware | `apps/api/app/Http/Middleware/EnsureCentralAdmin.php` (does not exist yet). **M3 adds `'central_admin' => EnsureCentralAdmin::class` beside the aliases currently at `apps/api/bootstrap/app.php:106-107`** — those live lines register `'super_admin' => EnsureSuperAdmin::class` and `'central_admin_role' => RequireCentralAdminRole::class` only |
| Backend tests | enumerated file-by-file in §1.1 |
| API lang strings | `apps/api/lang/{en,fr}/country_defaults.php` (pattern: the `taxation.*` messages added by item G) |
| Frontend feature | `apps/web/src/features/admin/country-defaults/` (**see the dispute note, §8**) |
| Frontend i18n | `apps/web/src/locales/en/adminCountryDefaults.json` + `apps/web/src/locales/fr/adminCountryDefaults.json`; register in `apps/web/src/lib/i18n.ts` in **all** the places that namespace registration touches (per-locale import `:55`/`:111` style, per-locale `resources` map `:219`/`:276`, and the `ns:` array `:442`) |

> ⚠️ **Central migrations live directly in `apps/api/database/migrations/` — never in a `central/`
> subdirectory.** No such subdirectory exists; the live central migrations sit at the top level
> (e.g. `apps/api/database/migrations/2026_08_07_020100_enforce_impersonation_mirror_uniqueness.php`),
> production runs plain `php artisan migrate --force` (`apps/api/docker/entrypoint.sh:121-127`), and
> only the tenant subdirectory is separately registered, in tests
> (`apps/api/app/Providers/AppServiceProvider.php:207-226`). Laravel's migrator globs only the
> immediate `*_*.php` children of each registered path, so a file placed under
> `database/migrations/central/` would be **silently skipped by the production deployment path**.
> Follow the live top-level naming pattern shown above.

### §1.1 — Test file inventory (exact filenames; this is the M7 union)

These are the **only** Phase A test files. Add a file here only by amending this table in the same
commit, so the M7 command manifest stays literal and complete.

| M | Backend test files (paths relative to `apps/api/`) |
|---|---|
| M1 | `tests/Unit/CountryDefaults/CountryAccountingCapabilitiesServiceTest.php`<br>`tests/Unit/CountryDefaults/CapabilityAuthoritySingularityTest.php` (S-1 architecture test)<br>`tests/Unit/CountryDefaults/CertificationScopeTest.php` (truth table)<br>`tests/Unit/CountryDefaults/ProvisioningRequiredPurposesV1ConformanceTest.php`<br>`tests/Unit/CountryDefaults/ProvisioningRequiredPurposesRegistrationRatchetTest.php`<br>`tests/Unit/CountryDefaults/ProtectedAccountCodeRegistryDriftTest.php`<br>`tests/Unit/CountryDefaults/CanonicalCoaSerializerGoldenTest.php`<br>`tests/Unit/CountryDefaults/FrozenSeederDocblockTest.php`<br>`tests/Feature/Taxation/TaxConfigurationCapabilityDelegationTest.php` (characterization) |
| M2 | `tests/Feature/CountryDefaults/TemplatePublishGateTest.php`<br>`tests/Feature/CountryDefaults/TemplatePublishTimbreRulesTest.php`<br>`tests/Feature/CountryDefaults/TemplateImmutabilityTest.php`<br>`tests/Feature/CountryDefaults/TemplateAssignmentServiceTest.php`<br>`tests/Feature/CountryDefaults/TemplateLifecycleRaceTest.php`<br>`tests/Feature/CountryDefaults/TemplateAuditTransactionTest.php`<br>`tests/Feature/CountryDefaults/CentralConnectionUnderTenancyTest.php`<br>`tests/Feature/CountryDefaults/CountryCodeNormalizationTest.php` |
| M3 | `tests/Feature/CountryDefaults/CentralAdminRouteInventoryTest.php`<br>`tests/Feature/CountryDefaults/AdminAuthRouteBoundaryTest.php`<br>`tests/Feature/CountryDefaults/DefaultsEditorLifecycleTest.php`<br>`tests/Feature/CountryDefaults/DefaultsEditorLoginFlagTest.php`<br>`tests/Feature/CountryDefaults/TemplateApiEndpointTest.php`<br>`tests/Feature/CountryDefaults/AssignmentApiEndpointTest.php` |
| M4 | `tests/Feature/CountryDefaults/LegacyGoldenParityTest.php`<br>`tests/Feature/CountryDefaults/BootstrapKeyAssertionImportTest.php`<br>`tests/Feature/CountryDefaults/VerifyCountryDefaultsCommandTest.php`<br>`tests/Feature/CountryDefaults/CapabilityRegistryBumpTransitionTest.php`<br>`tests/Feature/CountryDefaults/CertifiedFixtureDeltaTest.php` |
| M5 | `tests/Feature/CountryDefaults/CountryTemplateResolverTest.php`<br>`tests/Feature/CountryDefaults/ProvisioningFlagMatrixTest.php`<br>`tests/Feature/CountryDefaults/ProvisioningNoCacheTest.php`<br>`tests/Feature/CountryDefaults/CompanyCreationRollbackTest.php`<br>`tests/Feature/CountryDefaults/TemplateChartOfAccountsSeederSemanticsTest.php`<br>`tests/Feature/CountryDefaults/ExpenseCategorySeederLoudFailureTest.php`<br>`tests/Feature/CountryDefaults/NoExistingCompanyMutationTest.php`<br>`tests/Feature/CountryDefaults/FrozenSeederProvisioningIsolationTest.php` |
| M1–M5 compatibility | `tests/Feature/Accounting/BackfillChartPurposesMigrationTest.php`<br>`tests/Feature/Accounting/BackfillCustomerAndSupplierAdvanceAccountsTest.php`<br>`tests/Feature/Accounting/BackfillPurchaseStampDutyAccountTest.php`<br>`tests/Feature/Accounting/BackfillSalesRoundingDifferenceAccountsTest.php`<br>`tests/Feature/Accounting/DocumentCancellationGlReversalTest.php`<br>`tests/Feature/Accounting/DocumentGlPreflightTest.php`<br>`tests/Feature/Accounting/InvoiceGLIntegrationTest.php`<br>`tests/Feature/Accounting/SeedChartsCommandTest.php`<br>`tests/Feature/Document/CancelRefusedOnNonOpenVatPeriodTest.php`<br>`tests/Feature/Document/InvoiceDeliveryNoteConfirmationTest.php`<br>`tests/Feature/Document/Types/CreditNoteDocumentTest.php`<br>`tests/Feature/Document/Types/InvoiceDocumentTest.php`<br>`tests/Feature/Expense/ExpenseCategorySeederTest.php`<br>`tests/Feature/Seeders/DemoSeedersTaxTest.php`<br>`tests/Feature/Seeders/SeededProductsHaveTaxRateTest.php`<br>`tests/Feature/Taxation/CompanyTaxProvisioningServiceTest.php`<br>`tests/Feature/Taxation/EndToEndTaxResolutionTest.php`<br>`tests/Feature/Tenant/OnboardingTaxStepTest.php`<br>`tests/Feature/Treasury/InstrumentAccountResolverTest.php`<br>`tests/Feature/Treasury/PayableInstrumentAccountsTest.php`<br>`tests/Unit/Taxation/CountryTaxConfigurationRegistryTest.php` |

| M | Frontend test files (paths relative to `apps/web/`) |
|---|---|
| M6 | `src/features/admin/country-defaults/__tests__/TemplateListPage.test.tsx`<br>`src/features/admin/country-defaults/__tests__/TemplateEditorPage.test.tsx`<br>`src/features/admin/country-defaults/__tests__/AssignmentsPage.test.tsx`<br>`src/features/admin/__tests__/adminRoleShell.test.tsx` (three-role landing, `/admin` index redirect, direct-URL guards, nav filtering) |

---

## Non-goals (explicit — violating these fails the gate)

- **N-A. `defaults_editor` ACTIVATION stays feature-flagged OFF.** Implement the role, middleware
  matrix, lifecycle endpoints, FE shell and login block, but `country_defaults.external_editors_enabled`
  defaults **false** and external editors cannot authenticate until the central-admin MFA lane
  lands. Do not implement MFA here.
- **N-B. Treasury literal-code migration is a follow-up lane** (spec §13.1, treasury-reviewer
  gated). The nine `InstrumentAccountResolver` codes are *protected literals* in Phase A. Do not
  refactor `InstrumentAccountResolver`; do not touch withholding's `42236`/`42237`.
- **N-C. No existing-company mutation, EVER.** Templates apply at **company creation only** (owner
  ruling F-07). No migration, command, or service in this branch may write to a live company's
  `accounts`. A test proves the negative; M7 asserts it branch-wide.
- **N-D. No tenant-facing changes.** No new tenant routes, no changes to the tenant account API,
  none to `TaxConfigurationController` **behavior** (the S-1 delegation is a refactor that must
  preserve responses byte-for-byte), none to `TaxCalculationService`, and no `country_code`
  immutability work (S-5).
- **N-E. Domain scope = `chart_of_accounts` only.** The framework is domain-generic; only the COA
  content table and editor ship. No tax-rate / payment-settings / withholding / pricing /
  expense-category *content* tables (Phase B+).
- **N-F. No four-eyes publish approval, no template-content i18n** (spec N4/N5).
- **N-G. No entrypoint changes.** Rollout is externally gated by runbook + `country-defaults:verify`
  (spec §6). Do not make `apps/api/docker/entrypoint.sh` self-guarding — it runs plain
  `php artisan migrate --force` at `:121-127` and that must stay unchanged.

---

## Milestones

Envelope **9–12 dev-days** (S-6). Each milestone is a gate: hand back, wait for verdicts, fix
rounds ≤5, then proceed. **Do not start milestone N+1 while N is under review** unless the
orchestrator says so. Every milestone has named reviewers (§6).

### M0 — Reconciliation + baseline pinning (~0.5–1 d) — *documentation-only*

**Red-first exception (F-9):** M0 changes documentation only, so no red test is expected. Its
obligation instead is a **reproducible reconciliation command whose pre-addendum output is captured
verbatim in the report** (e.g. a committed throwaway script or an artisan tinker one-liner that
prints, per country, the missing-REQUIRED set and the scope-dependent result).

**Scope:** write the addendum. It must record:
1. **The 27-REQUIRED result** reproduced mechanically: TN `[]`, FR `[]`, Generic `[]` — computed
   over the complete definition arrays (`apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php:125-350`,
   `apps/api/database/seeders/FranceChartOfAccountsSeeder.php:124-429`,
   `apps/api/database/seeders/GenericChartOfAccountsSeeder.php:106-237`).
2. **The scope-dependent result (F-5):** `SalesStampDutyPayable` is present in TN
   (`apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php:208`) — required, since TN is the
   timbre scope — and **absent from FR and Generic**, which is what the non-timbre ban requires.
   A "zero REQUIRED" statement alone is **not** a certification-readiness matrix; both results are
   reported, and M4 then runs the **full** publish gate, not only the REQUIRED check.
3. **Old content deltas: empty** — the spec's §5.4 delta table (TN + `SalesDiscount`; FR + five
   purposes + `624`) is superseded. Human HTTP publish is still mandatory (D-1).
4. The **replay statement** from D-2, verbatim.
5. The full protected-code variant sets from D-4, and the D-5 re-scoping.
6. The **S-5 limitation** verbatim (certification claims are template-layer only; the tax guard is
   usability-only).
7. The §0.1 decisions S-1..S-6 as the executed design.

**Gate:** adversarial Codex (addendum) + **treasury-reviewer** (do the reproduced numbers match the
charts?).

---

### M1 — Invariant kernel: contract, registries, manifest, serializer (~2 d)

No DB tables, no HTTP.

1. `Shared/Contracts/CountryDefaults/CountryAccountingCapabilities.php` +
   `Application/Services/CountryAccountingCapabilitiesService.php` + provider binding + **Taxation
   delegation** (constructor-inject the contract into `CountryTaxConfigurationRegistry`, delete
   `supports_stamp_duty` from its `MAP`, delegate `supportsStampDuty()`) — S-1 exactly.
   `apps/api/app/Modules/Taxation/Presentation/Controllers/TaxConfigurationController.php:245-265`
   behavior must be unchanged (N-D).
2. `Domain/ValueObjects/CertificationScope.php` — exact-set XOR `*`, never mixed; no
   timbre/non-timbre mixing within an exact set; `*` = generic **non-timbre** fallback.
3. `Domain/Services/ProvisioningRequiredPurposesV1.php` — the complete 41-case partition
   (27 REQUIRED / 1 SCOPE-REQUIRED / 4 CONDITIONAL / 9 SOFT), each entry
   `(purpose, call site, classification, gate kind, evidence citation)`. Allowed gate kinds:
   `MODULE_GATE`, `DOMAIN_PRECHECK_4XX` — those two only.
4. Manifest **conformance suite** + the **PHPStan/AST registration ratchet** (a new throwing
   purpose-resolution call site absent from the manifest fails CI).
5. `Domain/Registries/ProtectedAccountCodeRegistry.php` — three country variants (D-4).
6. `Application/Services/CanonicalCoaSerializer.php` — spec §5.4 byte contract: projection
   `(code, name, type, parent_code, system_purpose, is_system, sort_order)` with `parent_id`
   resolved to parent code and `is_active` **excluded**; rows sorted ascending by `sort_order`;
   per-row `json_encode` with `JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR`
   in exactly that field order, `null` as JSON null, strings valid UTF-8 normalized to **NFC**;
   rows joined by `\n` with **no trailing newline**; SHA-256 over the UTF-8 bytes.
7. **Frozen-seeder markers (F-6):** add `@deprecated compatibility artifact; frozen at 7d85232cc`
   to all three class docblocks (`apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php:13-22`,
   `apps/api/database/seeders/FranceChartOfAccountsSeeder.php:13-21`,
   `apps/api/database/seeders/GenericChartOfAccountsSeeder.php:12-18`).
   Content edits to those classes remain forbidden.

**Invariants:** exactly one production place answers "is this country timbre-capable" and one
answers "what is the capability version"; **every** non-timbre scope — exact *or* wildcard —
contains **no** `SalesStampDutyPayable` row (runtime absorber selection picks stamp by mere
existence, so a correctly-typed stamp row in an FR template would silently misdirect FR rounding
residuals — spec §4.2.1); `is_active` never enters the canonical projection.

**Tests (red first):** scope-algebra truth table (`["TN"]`, `["FR"]`, `["*"]`, rejected `["FR","*"]`,
rejected `["TN","FR"]` × assignments TN/FR/unknown-ISO/`*`); serializer golden vector **with
non-ASCII** (accented French names) pinning exact bytes; manifest conformance incl. a **deliberately
misclassified fixture entry that MUST fail**; SOFT entries asserted to have no registered throwing
site; `UninvoicedRevenue` caller-scan over production roots (`apps/api/app/`, `apps/api/routes/`,
`apps/api/config/`, `apps/api/database/`, `apps/api/bootstrap/`) with tests excluded;
protected-registry drift tests vs `InstrumentAccountResolver` **and** vs both
`apps/api/database/seeders/ExpenseCategorySeeder.php:35-65` maps; capability ⇄ tax-seeder drift CI
test; unlisted country resolves **non-timbre** (fail-closed) explicitly; **S-1 architecture test**
(no second predicate/country-set); a characterization test proving the Taxation delegation left
`TaxConfigurationController` responses unchanged.

**Gate:** **treasury-reviewer** + adversarial Codex.

---

### M2 — Central schema, models, publish/assignment services (~2 d)

No HTTP yet.

**Scope:** the three central tables exactly per spec §3 (incl. `UNIQUE(id, domain)` parent key, the
composite FK `(template_id, domain)` → `admin_templates(id, domain)`, `UNIQUE(template_id, code)`,
`UNIQUE(template_id, system_purpose)` where not null, `UNIQUE(template_id, sort_order)`,
`UNIQUE(country_code, domain)`, immutable UNIQUE `bootstrap_key`). Models under
`Infrastructure/Models/` using Stancl's `CentralConnection` concern. `TemplatePublishingService`
implementing all six §4.2 gate rules. `TemplateAssignmentService`. Clone / archive / delete /
re-point with central transactions and `SELECT … FOR UPDATE` row locks in deterministic id order.
Audit row written **inside** the same central transaction.

**Invariants:** published templates immutable, including `certified_country_codes` and
`capability_registry_version` (set at publish, never edited); status/scope checks only **after**
acquiring locks; a template referenced by any assignment can be neither archived nor deleted; the
`*` row is pinned and non-removable; a mutation without its audit row **cannot commit**;
provisioning-relevant reads are never cached (admin list endpoints may cache).

**Tests (red first):** every publish-gate rule positive + negative (purpose/type compatibility;
`system_purpose !== null ⇒ is_system === true`; protected codes exist with the resolver's queried
types **and** `is_system=true`, incl. an `is_system=false` negative; structural checks); the
non-timbre stamp-purpose negative rule at publish **and** re-run at assignment; scope immutability;
transactional races (publish-vs-assign, archive-vs-assign) under real PostgreSQL; a
**tenancy-initialized central-read test** (central resolution works while the default connection
points at a tenant DB — pattern `apps/api/tests/Feature/Services/TenantConfigCacheTenancyTest.php:177-203`);
`country_code` normalization (`tn` → `TN`) at every write boundary.

**Migrations:** the three files go **directly in `apps/api/database/migrations/`** (§1 warning — a
`central/` subdirectory would be skipped by `php artisan migrate --force`), on the central
connection, idempotent and **unattended-safe**: pushing to `origin/dev` auto-deploys, and central
migrations run at boot with failures logged while boot continues. Schema only in this milestone.

**Gate:** **tenancy-authz-reviewer** + **treasury-reviewer** + adversarial Codex.

---

### M3 — Central-admin access control + HTTP API surface (~1.5–2 d)

1. `SuperAdminRole::DefaultsEditor = 'defaults_editor'` added to
   `apps/api/app/Models/Enums/SuperAdminRole.php` (no backfill, no rename; `support_approver`
   four-eyes semantics untouched).
2. `apps/api/app/Http/Middleware/EnsureCentralAdmin.php` — authenticated `SuperAdmin`, active, any
   role (authentication split from capability). Register alias `central_admin` beside the existing
   aliases at `apps/api/bootstrap/app.php:106-107`. Capability stays with
   `central_admin_role:` (`RequireCentralAdminRole` already enforces active state + exact listed
   roles — `apps/api/app/Http/Middleware/RequireCentralAdminRole.php:17-30`).
3. **The minimal auth edit (S-3 / F-3), byte-for-byte:**
   - `POST /admin/auth/login` stays **public and throttled** — `apps/api/routes/api.php:37-41`
     unchanged. (Moving the whole `admin/auth` prefix under a central-admin check would require an
     already-authenticated administrator to log in.)
   - **Only** the nested logout/`me` group at `apps/api/routes/api.php:43-47` changes its
     `'super_admin'` entry to `EnsureCentralAdmin::class` (or the `central_admin` alias), **keeping
     `auth:sanctum-admin`**.
   - The `Route::prefix('admin')` full-admin group at `apps/api/routes/api.php:56-59` stays
     **byte-identical** (`auth:sanctum-admin`, `super_admin`, `throttle:admin-sensitive`).
     `EnsureSuperAdmin` continues to enforce the exact `super_admin` role plus active state
     (`apps/api/app/Http/Middleware/EnsureSuperAdmin.php:42-59`).
4. New route group in `apps/api/app/Modules/CountryDefaults/Presentation/routes.php`, loaded by the
   provider (precedent `apps/api/app/Modules/SupportAccess/Providers/SupportAccessServiceProvider.php:27-52`):
   prefix `admin/country-defaults`, middleware `auth:sanctum-admin` + `EnsureCentralAdmin` +
   `central_admin_role:super_admin,defaults_editor` + a throttle consistent with
   `throttle:admin-sensitive`.
5. All spec §8 endpoints with FormRequests, strict typing, enums, standard envelope.
6. `/editors` lifecycle (create / disable / re-enable / reset-credentials) carries a **nested
   `central_admin_role:super_admin`**; disable and role change revoke all Sanctum tokens; every
   lifecycle action is audited.
7. Login feature flag `country_defaults.external_editors_enabled` (default **false**, from
   `apps/api/config/country_defaults.php`) blocks `defaults_editor` authentication (N-A).

**Invariants:** the backend is authoritative for authorization; the term "owner role" does not
exist; `RequireCentralAdminRole` grants only exact listed roles — if you refactor it,
`EnsureCentralAdmin` must be added to the SupportAccess group
(`apps/api/app/Modules/SupportAccess/Presentation/routes.php:17-46`) in the **same** change so the
active-account check is not lost.

**Tests (red first) — the four required route-inventory assertions (F-3) plus the matrix:**
1. **unauthenticated** `POST /admin/auth/login` remains reachable (not 401 from middleware);
2. **all three roles** (`super_admin`, `support_approver`, `defaults_editor`) succeed on
   `/admin/auth/me` and `/admin/auth/logout`;
3. a `defaults_editor` token gets **403 on every full-admin route**, enumerated by iterating the
   route table, **including every `/editors` verb**, with the only allowances being auth
   profile/logout + the country-defaults template/assignment routes;
4. the full-admin group's middleware list is asserted **unchanged** (`auth:sanctum-admin`,
   `super_admin`, `throttle:admin-sensitive`).
Plus: MFA-flag login block; token revocation on disable/role-change; audit rows per lifecycle action.

**Gate:** **tenancy-authz-reviewer** (primary) + adversarial Codex.

---

### M4 — Bootstrap import, goldens/parity, `country-defaults:verify` (~2 d)

**Scope:**
- **Legacy golden exporter** running the **frozen** seeders (as of `7d85232cc`) into a scratch
  schema and serializing the canonical projection. **No reflection into private arrays.** Goldens
  committed immutable under `apps/api/tests/Fixtures/CountryDefaults/goldens/`.
- **Central data migration** — `apps/api/database/migrations/2026_08_11_100300_import_legacy_coa_templates_as_drafts.php`
  (top-level, **not** under `central/` — §1 warning) — importing the three legacy templates **as
  DRAFTS**, keyed by immutable
  `bootstrap_key` (`coa.tn.legacy-v1`, `coa.fr.legacy-v1`, `coa.generic.legacy-v1`), each one
  atomic transaction (header + all rows). The key is an **assertion, not a presence flag**: on
  collision, recompute the canonical serialization and require exact domain, expected bootstrap
  status, and matching hash + row count — **any mismatch aborts the migration with a diagnostic**.
- **No migration ever creates a published row.** There is **no artisan certify path**:
  `certified_by` may only be set from the authenticated Sanctum actor of an HTTP publish request
  (spec §6) — this holds even though the content deltas are empty (D-1).
- `country-defaults:verify` per spec §6, incl. the **global drift scan**: every current
  `country_template_assignments` row — all exact countries **and** `*` — compared against its
  template's stored `capability_registry_version`; superseded **unassigned** published versions are
  integrity-checked only (stored `content_hash` matches their rows) and do **not** block. Drift ⇒
  **non-zero exit**; no report-only mode.
- Data-driven certified-fixture delta suite derived from the manifest, asserting the **M0**
  results: per-country missing-REQUIRED sets **and** the scope-dependent `SalesStampDutyPayable`
  result (present for TN; absent for FR/Generic), then the **full publish gate** — not only "zero
  REQUIRED" (F-5).

**Invariants:** bootstrap drafts match the legacy goldens **byte-for-byte** — that is the parity
gate. `bootstrap_key` immutability + bootstrap-only assignment enforced in the model (guarded
attribute) **and** in the service.

**Tests (red first):** bootstrap key-assertion suite (keyed wrong-domain row, missing child rows,
altered content, an already-published keyed row, concurrent import attempts, retry after a failed
import); `verify` red/green incl. drafts-only state and missing certification fields; the
**registry-bump transition** — with `TN`, `FR`, `*`, plus at least one additional *unchanged* exact
assignment, `verify` stays non-zero until the **last** assignment is re-pointed, then passes even
though superseded versions remain published; the delta suite above.

**Gate:** **treasury-reviewer** + **tenancy-authz-reviewer** + adversarial Codex.

---

### M5 — Provisioning rewire (~2 d) — **the riskiest milestone**

1. `Application/Services/CountryTemplateResolver.php` reading on the `central` connection,
   implementing spec §5.1's **authoritative 4-step algorithm**: (1) normalize (trim + uppercase);
   (2) exact assignment → **2b version check** — the template's `capability_registry_version` must
   equal the current capability version, else `TemplateRecertificationRequiredException`;
   (3) no exact assignment + **timbre-capable** country ⇒
   `TimbreCountryRequiresExactAssignmentException`; (4) otherwise the pinned `*` assignment (same
   2b check). **No caching on provisioning resolution.**
2. `Infrastructure/Seeders/TemplateChartOfAccountsSeeder.php` reproducing the **actual** current
   semantics: first pass inserts missing rows, preserves existing name/type/purpose, promotes
   `is_system`; second pass **re-issues `parent_id` for every definition including pre-existing
   rows**.
3. **Consumer inventory** (spec §5.2, anchors refreshed at `7d85232cc`):
   - `apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:208-219`
     (new-tenant registration `match`) → resolver;
   - `apps/api/app/Modules/Accounting/Application/Services/ChartOfAccountsService.php:139-148`
     (`seederForCountry` `match`) → resolver;
   - `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:156-163`
     (additional-company path) → resolver, and **remove the catch-log-continue** so a COA failure
     rolls the company creation back (spec §5.3). Note the adjacent
     `ExpenseCategoryProvisioningService` call (`apps/api/app/Modules/Expense/Application/Services/ExpenseCategoryProvisioningService.php`)
     which today deliberately no-ops when the chart is absent — with rollback that branch becomes
     unreachable; assert it.
   - `apps/api/database/seeders/DatabaseSeeder.php`,
     `apps/api/database/seeders/DemoTenantSeeder.php`,
     `apps/api/database/seeders/CoffeeShopSeeder.php`,
     `apps/api/database/seeders/TunisianParapharmacySeeder.php` (+ its two feature tests re-scoped
     and labeled historical-compat), and `apps/api/database/seeders/ParapharmacySeeder.php` /
     `apps/api/database/seeders/DemoPharmacySeeder.php` via `ChartOfAccountsSeederContract`
     (the class-name override becomes a country/template parameter).
   - `apps/api/database/seeders/ExpenseCategorySeeder.php` per **D-5**.
4. **Failure semantics (spec §5.3):** registration failure throws into the existing compensation
   flow; additional-company creation **rolls back**.
5. **Release-1 / Release-2 activation contract (S-4 / F-4):** add
   `apps/api/config/country_defaults.php` with
   `'provisioning_enabled' => (bool) env('COUNTRY_DEFAULTS_PROVISIONING_ENABLED', false)` and the
   independently default-off `'external_editors_enabled'`. The branch may contain **both** readers,
   but **both** company-creation paths select the template reader **from the uncached config value
   at call time** — no boot-time capture, no cache wrapper, no memoization. **Release 1** deploys
   with the flag false (schema + idempotent draft import; readers stay legacy). After authenticated
   certification, all assignments, and a **zero exit** from `country-defaults:verify` on staging
   **and** production, **Release 2 is a deployment configuration change** setting it true.
   **Rollback = restore false.**
6. **Frozen-seeder static guard (F-6):** a test/static guard asserting provisioning has **no**
   direct dependency on the three frozen seeder classes when `provisioning_enabled` is true — the
   only permitted production consumers being the golden exporter and the historical migration
   `apps/api/database/migrations/tenant/2026_03_23_300000_fix_existing_tunisian_companies_tax_setup.php:44-53`,
   plus explicitly labeled historical-compat tests.

**Invariants:** no existing-company mutation (N-C); no live-chart ⇄ template parity assumption
(D-8); lowercase `tn` resolves TN on **both** provisioning paths (the additional-company request
persists a 2-char code verbatim).

**Tests (red first):** resolver exact / wildcard / missing / lowercase; capability transitions in
**both** directions through **both** provisioning paths, refused until re-pointed; a timbre country
without an exact assignment ⇒ typed error on both paths; an accepted 2-letter non-timbre code
absent from `CountriesSeeder` provisions via wildcard; company-creation rollback in both paths;
rerun-after-manual-reparent pin; `ExpenseCategorySeeder` loud failure for a **missing mapped code**
and for a **wholly absent COA**, while a `null` mapping still resolves `GeneralExpense`;
**both flag values × both provisioning paths** (false ⇒ legacy seeder path, true ⇒ template path);
an explicit **no-cache proof** for the flag and the resolver (change the value/assignment mid-test,
observe the next provisioning call honor it); explicit negative test that provisioning never writes
to an existing company's accounts.

**Gate:** **treasury-reviewer** + **tenancy-authz-reviewer** + adversarial Codex.

---

### M6 — Admin frontend (~1.5–2 d)

Feature root `apps/web/src/features/admin/country-defaults/` (§8 dispute note), nav section
"Country Defaults":
1. **Templates list** — domain filter, status chips, certification metadata incl. scope,
   Clone/Archive.
2. **Template editor** — spreadsheet grid (code, name, type, parent, purpose dropdown, system
   flag); protected rows **locked with an explanatory tooltip**; persistent validation panel;
   Publish modal collecting `standard_ref` + `certified_country_codes` and showing the content hash
   on success.
3. **Assignments view** — static country catalog × assigned template; **pinned wildcard row**;
   re-point confirm copy "affects newly created companies only"; out-of-scope templates not offered.
4. **Three-role shell:** the stored role union in
   `apps/web/src/features/admin/stores/adminAuthStore.ts` gains `defaults_editor`. Login success
   (`apps/web/src/features/admin/pages/AdminLoginPage.tsx`) and the `/admin` index route land each
   role on its home — `super_admin` → `/admin/dashboard`, `defaults_editor` →
   `/admin/country-defaults`, `support_approver` → `/admin/support-access`. Today the admin route
   block begins at `apps/web/src/routes/index.tsx:401` and its index redirect is the unconditional
   `<Route index element={<Navigate to="/admin/dashboard" replace />} />` at `:420`. Client route
   guards mirror the backend matrix; nav renders only each role's permitted sections —
   `support_approver` must not be stranded on a forbidden dashboard.

**Invariants:** `t()` for **every** user-facing string (namespace `adminCountryDefaults`, **en + fr
mandatory**, both complete incl. `_many` plural forms; `ar` optional but must not break
`apps/web/src/lib/i18nRawKeyCoverage.test.tsx`); design tokens from `@/lib/designTokens` only (no
hardcoded Tailwind colors); plain (non-tenant-scoped) query keys as on existing admin pages; no
`any`; domain types flow from backend DTOs via `php artisan typescript:transform` — never hand-edit
generated types.

**Tests (red first, Vitest):** grid validation; locked protected rows; role-filtered nav, login
landing, `/admin` index redirect, and direct-URL guards **for all three roles**; assignment
re-point confirm.

**Gate:** **frontend-conventions-reviewer** + **tenancy-authz-reviewer** (role guards mirror the
backend matrix) + adversarial Codex.

---

### M7 — Whole-branch integration gate (~0.5–1 d)

**Red-first exception (F-9):** **no new implementation is permitted in M7**, so no new red test is
expected. Its obligation is a **clean rerun of the complete accumulated M1–M6 evidence set**, plus
the revert-replay records already captured per behavior change.

**Command / evidence manifest (F-10) — every command below is runnable by copy-paste; paste each
one's output into the report.** `$PHASE_A_TESTS` is the §1.1 backend union, defined once here and
reused by items 1 and 2:

```bash
#!/usr/bin/env bash
# Any failing command aborts the manifest — this block IS the promotion gate.
set -euo pipefail

# Required, no defaults: the block fails loudly rather than running against the wrong database.
: "${PGHOST:?set PGHOST (e.g. 127.0.0.1)}"
: "${PGPORT:?set PGPORT (e.g. 5432)}"
: "${PGUSER:?set PGUSER}"
: "${PGPASSWORD:?set PGPASSWORD to a non-empty value; for trust-auth local PG use any placeholder}"
: "${PHASE_A_DB:?set PHASE_A_DB (dedicated test database, e.g. autoerp_country_defaults_test)}"
: "${PHASE_A_MIGRATE_DB:?set PHASE_A_MIGRATE_DB (scratch migration database)}"

# ── run from apps/api ──────────────────────────────────────────────────────────
cd apps/api

PHASE_A_TESTS="\
tests/Unit/CountryDefaults/CountryAccountingCapabilitiesServiceTest.php \
tests/Unit/CountryDefaults/CapabilityAuthoritySingularityTest.php \
tests/Unit/CountryDefaults/CertificationScopeTest.php \
tests/Unit/CountryDefaults/ProvisioningRequiredPurposesV1ConformanceTest.php \
tests/Unit/CountryDefaults/ProvisioningRequiredPurposesRegistrationRatchetTest.php \
tests/Unit/CountryDefaults/ProtectedAccountCodeRegistryDriftTest.php \
tests/Unit/CountryDefaults/CanonicalCoaSerializerGoldenTest.php \
tests/Unit/CountryDefaults/FrozenSeederDocblockTest.php \
tests/Feature/Taxation/TaxConfigurationCapabilityDelegationTest.php \
tests/Feature/CountryDefaults/TemplatePublishGateTest.php \
tests/Feature/CountryDefaults/TemplatePublishTimbreRulesTest.php \
tests/Feature/CountryDefaults/TemplateImmutabilityTest.php \
tests/Feature/CountryDefaults/TemplateAssignmentServiceTest.php \
tests/Feature/CountryDefaults/TemplateLifecycleRaceTest.php \
tests/Feature/CountryDefaults/TemplateAuditTransactionTest.php \
tests/Feature/CountryDefaults/CentralConnectionUnderTenancyTest.php \
tests/Feature/CountryDefaults/CountryCodeNormalizationTest.php \
tests/Feature/CountryDefaults/CentralAdminRouteInventoryTest.php \
tests/Feature/CountryDefaults/AdminAuthRouteBoundaryTest.php \
tests/Feature/CountryDefaults/DefaultsEditorLifecycleTest.php \
tests/Feature/CountryDefaults/DefaultsEditorLoginFlagTest.php \
tests/Feature/CountryDefaults/TemplateApiEndpointTest.php \
tests/Feature/CountryDefaults/AssignmentApiEndpointTest.php \
tests/Feature/CountryDefaults/LegacyGoldenParityTest.php \
tests/Feature/CountryDefaults/BootstrapKeyAssertionImportTest.php \
tests/Feature/CountryDefaults/VerifyCountryDefaultsCommandTest.php \
tests/Feature/CountryDefaults/CapabilityRegistryBumpTransitionTest.php \
tests/Feature/CountryDefaults/CertifiedFixtureDeltaTest.php \
tests/Feature/CountryDefaults/CountryTemplateResolverTest.php \
tests/Feature/CountryDefaults/ProvisioningFlagMatrixTest.php \
tests/Feature/CountryDefaults/ProvisioningNoCacheTest.php \
tests/Feature/CountryDefaults/CompanyCreationRollbackTest.php \
tests/Feature/CountryDefaults/TemplateChartOfAccountsSeederSemanticsTest.php \
tests/Feature/CountryDefaults/ExpenseCategorySeederLoudFailureTest.php \
tests/Feature/CountryDefaults/NoExistingCompanyMutationTest.php \
tests/Feature/CountryDefaults/FrozenSeederProvisioningIsolationTest.php \
tests/Feature/Accounting/BackfillChartPurposesMigrationTest.php \
tests/Feature/Accounting/BackfillCustomerAndSupplierAdvanceAccountsTest.php \
tests/Feature/Accounting/BackfillPurchaseStampDutyAccountTest.php \
tests/Feature/Accounting/BackfillSalesRoundingDifferenceAccountsTest.php \
tests/Feature/Accounting/DocumentCancellationGlReversalTest.php \
tests/Feature/Accounting/DocumentGlPreflightTest.php \
tests/Feature/Accounting/InvoiceGLIntegrationTest.php \
tests/Feature/Accounting/SeedChartsCommandTest.php \
tests/Feature/Document/CancelRefusedOnNonOpenVatPeriodTest.php \
tests/Feature/Document/InvoiceDeliveryNoteConfirmationTest.php \
tests/Feature/Document/Types/CreditNoteDocumentTest.php \
tests/Feature/Document/Types/InvoiceDocumentTest.php \
tests/Feature/Expense/ExpenseCategorySeederTest.php \
tests/Feature/Seeders/DemoSeedersTaxTest.php \
tests/Feature/Seeders/SeededProductsHaveTaxRateTest.php \
tests/Feature/Taxation/CompanyTaxProvisioningServiceTest.php \
tests/Feature/Taxation/EndToEndTaxResolutionTest.php \
tests/Feature/Tenant/OnboardingTaxStepTest.php \
tests/Feature/Treasury/InstrumentAccountResolverTest.php \
tests/Feature/Treasury/PayableInstrumentAccountsTest.php \
tests/Unit/Taxation/CountryTaxConfigurationRegistryTest.php"

# 2 — SQLite baseline (default phpunit.xml)
php artisan test $PHASE_A_TESTS

# 1 — real PostgreSQL (authoritative; recipe shape from the C/G/H/I lane,
#     docs/sessions/codex-accounting-gaps-cghi-report.md)
DB_HOST="$PGHOST" DB_PORT="$PGPORT" \
DB_DATABASE="$PHASE_A_DB" DB_CENTRAL_DATABASE="$PHASE_A_DB" \
DB_USERNAME="$PGUSER" DB_PASSWORD="$PGPASSWORD" \
php artisan test -c phpunit-pgsql.xml $PHASE_A_TESTS

# 4a — backend static analysis over the FULL set of files M1–M6 modify
PHASE_A_PHP_SCOPE="\
app/Modules/CountryDefaults \
app/Shared/Contracts/CountryDefaults \
app/Http/Middleware/EnsureCentralAdmin.php \
app/Models/Enums/SuperAdminRole.php \
app/Modules/Taxation/Application/Registries/CountryTaxConfigurationRegistry.php \
app/Modules/Taxation/Application/Services/CompanyTaxProvisioningService.php \
app/Modules/Tenant/Application/Services/TenantInitializationService.php \
app/Modules/Accounting/Application/Services/ChartOfAccountsService.php \
app/Modules/Accounting/Application/Services/LegacyExistingChartRepairPreviewer.php \
app/Modules/Expense/Application/Services/ExpenseCategoryProvisioningService.php \
app/Modules/Company/Presentation/Controllers/CompanyController.php \
app/Http/Controllers/Api/Admin/SuperAdminAuthController.php \
app/Console/Commands/SeedChartsCommand.php \
app/Console/Commands/BackfillChartPurposesCommand.php \
bootstrap/app.php \
bootstrap/providers.php \
routes/api.php \
config/country_defaults.php \
lang/en/country_defaults.php \
lang/fr/country_defaults.php \
database/seeders/DatabaseSeeder.php \
database/seeders/DemoTenantSeeder.php \
database/seeders/CoffeeShopSeeder.php \
database/seeders/TunisianParapharmacySeeder.php \
database/seeders/ParapharmacySeeder.php \
database/seeders/DemoPharmacySeeder.php \
database/seeders/ExpenseCategorySeeder.php \
database/seeders/Contracts/ChartOfAccountsSeederContract.php \
database/seeders/CountryDefaultsChartOfAccountsSeeder.php \
database/seeders/TunisiaChartOfAccountsSeeder.php \
database/seeders/FranceChartOfAccountsSeeder.php \
database/seeders/GenericChartOfAccountsSeeder.php \
database/migrations/2026_08_11_100000_create_admin_templates_table.php \
database/migrations/2026_08_11_100100_create_admin_template_accounts_table.php \
database/migrations/2026_08_11_100200_create_country_template_assignments_table.php \
database/migrations/2026_08_11_100300_import_legacy_coa_templates_as_drafts.php"

./vendor/bin/phpstan analyse $PHASE_A_PHP_SCOPE
./vendor/bin/pint --test $PHASE_A_PHP_SCOPE

# 5 — migrations: prove the four files are DISCOVERED by the default (production) path and
#     apply cleanly from empty on a scratch central database. The committed runner rejects URL
#     precedence and cached config, pins every default/central field, confirms both effective
#     Laravel connections, and passes --database=central to the destructive command.
PGHOST="$PGHOST" PGPORT="$PGPORT" PGUSER="$PGUSER" PGPASSWORD="$PGPASSWORD" \
PHASE_A_MIGRATE_DB="$PHASE_A_MIGRATE_DB" \
PHASE_A_FIXTURE_CONFIRM=I_UNDERSTAND_THIS_REBUILDS_A_DISPOSABLE_DATABASE \
PHASE_A_FIXTURE_PORT=8196 \
  ../../scripts/phase-a-authenticated-verifier-fixture.sh --migrate-only \
  | tee /tmp/phase-a-migrate-status.txt
for m in 2026_08_11_100000_create_admin_templates_table \
         2026_08_11_100100_create_admin_template_accounts_table \
         2026_08_11_100200_create_country_template_assignments_table \
         2026_08_11_100300_import_legacy_coa_templates_as_drafts; do
  grep -F "$m" /tmp/phase-a-migrate-status.txt || { echo "MISSING FROM MIGRATE:STATUS: $m"; exit 1; }
done
# NOTE (honesty): a second `php artisan migrate` here would be a migration-REPOSITORY no-op and
# proves nothing about import idempotency. Behavioral retry/idempotency coverage — key collision,
# altered content, partial row set, concurrent import, retry after failure — is carried solely by
# tests/Feature/CountryDefaults/BootstrapKeyAssertionImportTest.php (run in items 1 and 2).

# 6 — authenticated HTTP certification fixture + verify command. The committed runner fails closed
#     unless the target has an explicit disposable Country Defaults test/scratch database name,
#     confirms the actual PostgreSQL database identity, rebuilds only that target, publishes and
#     assigns through authenticated HTTP, and leaves staging/production human certification open.
PHASE_A_FIXTURE_CONFIRM=I_UNDERSTAND_THIS_REBUILDS_A_DISPOSABLE_DATABASE \
PHASE_A_FIXTURE_PORT=8197 \
PGHOST="$PGHOST" PGPORT="$PGPORT" PGUSER="$PGUSER" PGPASSWORD="$PGPASSWORD" \
PHASE_A_MIGRATE_DB="$PHASE_A_MIGRATE_DB" \
  ../../scripts/phase-a-authenticated-verifier-fixture.sh

# 7 — route inventory
php artisan route:list --path=admin --json > ../../docs/sessions/phase-a-route-inventory.json
php artisan test tests/Feature/CountryDefaults/CentralAdminRouteInventoryTest.php \
                 tests/Feature/CountryDefaults/AdminAuthRouteBoundaryTest.php

# ── run from apps/web ──────────────────────────────────────────────────────────
cd ../web

# 3 — frontend tests
pnpm exec vitest run \
  src/features/admin/country-defaults/__tests__/TemplateListPage.test.tsx \
  src/features/admin/country-defaults/__tests__/TemplateEditorPage.test.tsx \
  src/features/admin/country-defaults/__tests__/AssignmentsPage.test.tsx \
  src/features/admin/__tests__/adminRoleShell.test.tsx

# 4b — frontend static analysis (pnpm lint also runs the key/design-system/quantity audits)
pnpm typecheck
pnpm lint
pnpm build
```

Remaining evidence items (non-command):

| # | Item | Requirement |
|---|---|---|
| 5b | Bootstrap import evidence | From `tests/Feature/CountryDefaults/BootstrapKeyAssertionImportTest.php` — the **sole** behavioral retry/idempotency evidence (key collision, altered content, partial row set, concurrent import, retry after failure), including the abort-on-mismatch diagnostic output. The item-5 commands prove migrator **discovery** only, not idempotency |
| 8 | Branch-wide static assertions | (1) exactly one timbre predicate **and** one capability-version authority; (2) all 41 purposes partitioned exactly once; (3) no current assignment is stale; (4) no Phase A migration/command/service mutates an existing company's accounts; (5) provisioning has no direct frozen-seeder path when `provisioning_enabled` is true; (6) `external_editors_enabled` **and** `provisioning_enabled` both remain default-off. Each is carried by a named test in §1.1 — cite which |
| 9 | Findings | **Every milestone finding closed**; rerun any specialist reviewer who raised a finding **after** its final fix; attach their final verdicts |
| 10 | Limitation statement | The **S-5** wording present in the report and the deploy checklist |
| 11 | Deploy checklist | Release 1 → authenticated certification → assignments → `country-defaults:verify` on staging **and** production → Release 2 config flip; rollback = restore false. Appended to the launch-program consolidated deploy list |

**PG and SQLite counts must match** (item 1 vs item 2); any divergence is explained in the report.
**Failure of any manifest item blocks promotion.** No exceptions, no "will fix after merge".

**Gate:** adversarial Codex on the **whole branch diff** + rerun of every reviewer who raised a
finding at any milestone.

---

## §5 — House rules (binding)

- **TDD red-first per task.** Write the failing test, show it red, then green. Revert-replay every
  fix commit (prove the covering test goes red without the fix). Exceptions: **M0** (documentation
  only) and **M7** (no new implementation) — both carry the substitute obligations stated above.
- **Tests BY PATH only.** The **full PHPUnit suite is forbidden** — it crashes the machine.
- **A real PostgreSQL run before any green claim** (local PG on 5432; Docker 5433 is currently
  broken). SQLite-only greens are not evidence. Report SQLite + PG counts per milestone.
- **Never `git stash`** — the stash stack is shared across worktrees.
- **Rule 19 money/quantity precision:** never let a float touch money or quantity;
  `CurrencyScale::bcformatStrict` with an injected `CurrencyScaleResolverInterface`; FormRequest
  regex ceilings per column scale. Phase A is mostly non-monetary — do not introduce float paths in
  template/account payloads.
- **Constructor injection only** (`private readonly`); never the `app()` helper. Strict types
  everywhere; no `mixed` in PHP, no `any` in TS; enums for every status/type column.
- **Module boundaries are sacred** — cross-module communication only via `Shared/Contracts/`,
  Events, or a module's public Service class (`.claude/context/architecture.md:45-52`). This is
  what S-1 implements.
- **Migrations idempotent + unattended-safe** — pushing to `origin/dev` auto-deploys
  `tenants:migrate`, and central migrations run at boot with failures logged while boot continues,
  so a migration must never leave a half-imported keyed row.
- **en + fr i18n** for every user-facing string, frontend (`t()`, `apps/web/src/locales/{en,fr}/`)
  and backend (`apps/api/lang/{en,fr}/country_defaults.php`, following the `taxation.*` pattern).
- `pint` + `phpstan` (level 8) clean on touched files; `pnpm lint` + `pnpm typecheck` for FE.
  Preflight by path — do not run the whole preflight blindly at every milestone.
- **Work in a dedicated `git worktree` off `origin/dev`.** Branch: `codex/country-defaults-phase-a`.

## §6 — Reviewer gates (run by the orchestrator after each handback)

| Milestone | Reviewers |
|---|---|
| M0 | Codex adversarial (addendum) + treasury-reviewer |
| M1 | treasury-reviewer + Codex adversarial |
| M2 | tenancy-authz-reviewer + treasury-reviewer + Codex adversarial |
| M3 | tenancy-authz-reviewer (primary) + Codex adversarial |
| M4 | treasury-reviewer + tenancy-authz-reviewer + Codex adversarial |
| M5 | treasury-reviewer + tenancy-authz-reviewer + Codex adversarial |
| M6 | frontend-conventions-reviewer + tenancy-authz-reviewer + Codex adversarial |
| M7 | Codex adversarial (whole branch) + **rerun of every reviewer who raised a finding at any milestone**, after its final fix |

**tenancy-authz-reviewer** owns the central-admin surface (roles, middleware matrix, central-vs-
tenant connection, migration/deploy safety). **treasury-reviewer** owns COA and purpose content
(publish gate, protected codes, absorber/timbre semantics, provisioning charts).
**frontend-conventions-reviewer** owns `apps/web`. Fix rounds ≤5 per gate; re-reviews scoped to the
delta. Reviewers gate — they never auto-merge. No milestone may be reached with an open finding
behind it.

## §7 — VERIFY-AT-DISPATCH (1 item)

**V-1 — Re-pin the base SHA.** This brief's anchors and the `7d85232cc` freeze point were verified
at authoring time, when `HEAD` and `origin/dev` were identical. `dev` is shared and moves. At
dispatch, `git fetch origin dev` and confirm `origin/dev` is still `7d85232cc`; if it has advanced,
re-verify the D-1..D-8 facts and the §1/§M anchors against the new SHA, and restate the D-2 freeze
point as the new base in the M0 addendum before writing code.

*(Former items 1, 2, 3, 4, 6, 7 and 8 are settled in §0.1 as S-1..S-6 and D-1/D-2; former item 5 is
settled by S-4.)*

## §8 — Dispute note (one, recorded per instruction)

**F-2 frontend path — adopted in substance, corrected in form.** The review prescribes
`apps/web/src/features/adminCountryDefaults/`. This brief prescribes
**`apps/web/src/features/admin/country-defaults/`**. Evidence: feature directories in
`apps/web/src/features/` are kebab-case/lowercase (`admin`, `customer-history-audit`,
`document-ingestions`, `opening-balances`), and the admin shell this feature plugs into already
lives under `apps/web/src/features/admin/` (`stores/adminAuthStore.ts`, `pages/AdminLoginPage.tsx`),
so a sibling top-level camelCase directory would split the admin shell across two feature roots.
The **i18n namespace stays `adminCountryDefaults`** exactly as the spec specifies (camelCase
namespaces have precedent — `documentIngestions` in `apps/web/src/lib/i18n.ts:442`). Nothing about
the reviewer's intent — a dedicated, named Phase A frontend location — is dropped.

## §9 — Deliverable

- Branch `codex/country-defaults-phase-a` — **NOT merged, NOT pushed.**
- Report file `docs/sessions/codex-country-defaults-phase-a-report.md`, per milestone: files
  touched, tests + exact commands + output, SQLite and PG counts, red-first proof (or the M0/M7
  substitute obligation), revert-replay records, decisions taken, concerns, and any spec statement
  found unimplementable (quoted).
- The §0 addendum as the **first commit**.
- The orchestrator merges only after every gate passes and the M7 manifest is complete; promotion
  to `origin/dev` follows the batched fast-forward discipline (rule 21) in a separate session.
