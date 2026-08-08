# Adversarial review: Country Defaults in the Super Admin Panel

Reviewed spec: `docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md`

Review posture: pre-planning adversarial gate. The owner's locked product decisions are treated as constraints: framework + COA first, template library + country assignment, scoped central-admin role, and propagation to new tenants only.

## Findings register

| ID | Severity | Finding |
|---|---|---|
| F-01 | P1 blocker | Runtime treasury code resolves nine GL accounts by hardcoded literal code, disproving the spec's central safety premise |
| F-02 | P1 blocker | `requiredPurposes()` is not an operational-completeness gate; a published template can pass and still break core GL flows |
| F-03 | P1 blocker | The publish gate does not enforce purpose/type compatibility or system-account immutability |
| F-04 | P1 blocker | The proposed scoped role is incompatible with the current role schema and `super_admin` middleware |
| F-05 | P1 blocker | The stated bootstrap/deploy ordering does not exist in the actual container startup path |
| F-06 | P1 blocker | Rewiring only the two match sites leaves production/demo/migration consumers on the old seeder classes |
| F-07 | P1 blocker | “New tenants only” conflicts with rewiring `ChartOfAccountsService`, which is also used when an existing tenant creates another company |
| F-08 | P1 blocker | Existing-company creation swallows COA resolution failures and is outside the tenant-provisioning compensation flow |
| F-09 | P1 blocker | The central assignments UI has no authoritative central country catalog, and signup accepts codes absent from `CountriesSeeder` |
| F-10 | P1 blocker | The golden-parity test is not executable as specified and cannot compare raw parent IDs meaningfully |
| F-11 | P1 blocker | Forget-based `GlobalCache` invalidation cannot guarantee “the next tenant” sees a repointed assignment |
| F-12 | P1 blocker | The spec promises MFA-equivalent protection for an external editor, but current admin authentication is password-only |
| F-13 | P2 should-fix | Central-table safety depends on connection-pinned models, which the design does not make an explicit invariant |
| F-14 | P2 should-fix | Published/assignment/archive invariants are application-only and race-prone; assignment domain compatibility is missing |
| F-15 | P2 should-fix | The claimed idempotent seeder semantics are factually incomplete: reruns reparent existing rows |
| F-16 | P2 should-fix | The vertical-config “audit precedent” is not atomic and is insufficient for the stated certification/audit goal |
| F-17 | P2 should-fix | The design has no lifecycle for creating, disabling, or revoking the external accountant account |
| F-18 | P2 should-fix | Certification is not represented as domain data or an approval decision |
| F-19 | P3 nit | Several factual citations/names are stale or inaccurate |

### F-01 — P1 blocker — Runtime treasury code resolves nine GL accounts by hardcoded literal code

The spec's statement that “no business code resolves accounts by literal code” is false. `InstrumentAccountResolver::resolve()` queries `accounts.code`, and `accountCode()` maps nine operational purposes to literals such as `5312`, `5112`, `4035`, `403`, `6275`, `44566`, and `416` (`apps/api/app/Modules/Treasury/Application/Services/InstrumentAccountResolver.php:15-30`, `apps/api/app/Modules/Treasury/Application/Services/InstrumentAccountResolver.php:41-55`). This is not dead compatibility code: payment instruments, outbound instruments, lifecycle transitions, remittance, projections, and reconciliation inject this resolver (`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentInstrumentController.php:39`, `apps/api/app/Modules/Treasury/Application/Services/OutboundInstrumentService.php:43`, `apps/api/app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php:60`, `apps/api/app/Modules/Treasury/Application/Services/InstrumentRemittanceService.php:36`).

An accountant can therefore publish a structurally valid template that renumbers any of those accounts and immediately break cheque/effect posting and reconciliation for every new tenant receiving it. This invalidates the safety premise at spec line 27 and the claim that purpose validation alone makes arbitrary renumbering safe.

Required change: either extend the template/purpose model to cover all nine `InstrumentAccountPurpose` mappings and rewire `InstrumentAccountResolver`, or explicitly make those codes immutable certified invariants and validate them at publish time. The first option is consistent with the template-library goal; the second sharply limits what accountants may edit and must be disclosed in the editor.

There is a second literal-code surface in withholding certificates: `WithholdingDirection::glAccountBase()` returns `42236`/`42237`, and `WithholdingCertificate::getGLAccountCode()` emits a derived code such as `42236.10` (`apps/api/app/Modules/Taxation/Domain/Enums/WithholdingDirection.php:26-31`, `apps/api/app/Modules/Taxation/Domain/Entities/WithholdingCertificate.php:214-224`). It is a follow-up-domain concern, but it further disproves the global “no business code” assertion.

### F-02 — P1 blocker — `requiredPurposes()` is not an operational-completeness gate

`SystemAccountPurpose` has 41 cases, but `requiredPurposes()` returns only eleven (`apps/api/app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php:14-103`, `apps/api/app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php:155-175`). Core code unconditionally resolves purposes outside that list: inventory and GR-IR in goods receipt, plus purchase stamp duty, inventory, both purchase-price-variance accounts, and supplier payable in supplier-invoice posting (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1828-1829`, `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1982-1988`). Other operational paths use `PurchaseExpenses`, refund, voucher, tolerance, and rounding purposes as features activate.

Consequently, the proposed publish gate can approve a template that provisions successfully and later hard-fails ordinary procurement or inventory operations. `ChartOfAccountsService::validateCompanyAccounts()` merely loops the same eleven-value list (`apps/api/app/Modules/Accounting/Application/Services/ChartOfAccountsService.php:47-62`); it is not proof that the full seeded chart is operationally complete.

Required change: define an explicit versioned “provisioning-required purpose set” for the product/module set, with tests proving every unconditional `findByPurposeOrFail()` consumer is covered. Optional/module-gated purposes need declared prerequisites and publish validation tied to the modules a new tenant can receive. Do not equate today's `requiredPurposes()` helper with complete readiness.

### F-03 — P1 blocker — Purpose/type and `is_system` invariants are absent from the publish gate

The enum already defines the expected `AccountType` for every system purpose (`apps/api/app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php:177-205`), but the spec validates only that `type` is a valid enum value, not that it matches the assigned purpose. A template could publish `cash` as revenue, `vat_collected` as an asset, or `opening_balance_equity` as an expense and still pass.

The gate also does not require a purpose-bearing row to have `is_system=true`. Tenant account immutability is enforced solely by `is_system`; non-system rows can be updated (`apps/api/app/Modules/Accounting/Presentation/Controllers/AccountController.php:147-180`). Purpose assignment itself does not automatically promote the flag (`apps/api/app/Modules/Accounting/Application/Services/ChartOfAccountsService.php:102-122`). A newly provisioned purpose row with `is_system=false` is therefore mutable in the tenant UI despite being required for GL routing.

Required change: publish must enforce `row.type === purpose.expectedAccountType()` and `system_purpose !== null => is_system === true`, plus the complete operational-purpose rule from F-02. Include negative tests for every mismatch.

### F-04 — P1 blocker — The scoped-role design conflicts with current auth

`super_admins` already has a `role` column; it is not a new column (`apps/api/database/migrations/2025_12_01_194614_create_super_admins_table.php:14-24`). The current enum has `super_admin` and `support_approver`, not `owner` (`apps/api/app/Models/Enums/SuperAdminRole.php:7-11`). `EnsureSuperAdmin` is explicitly role-aware and rejects every role other than `super_admin` (`apps/api/app/Http/Middleware/EnsureSuperAdmin.php:27-49`). Therefore a `defaults_editor` route cannot remain layered on `['auth:sanctum-admin', 'super_admin', ...]` as the spec states: it will be rejected before the new capability middleware runs.

The proposed two-role model also omits the existing four-eyes `support_approver` role, which is actively routed through `central_admin_role` (`apps/api/app/Modules/SupportAccess/Presentation/routes.php:17-45`) and seeded as a distinct account (`apps/api/database/seeders/SuperAdminSeeder.php:40-60`). Backfilling every existing row to `owner` would erase that distinction.

Required change: preserve the existing roles and semantics. Split “authenticated active central admin” from capability authorization, add `defaults_editor` to the existing enum, and put explicit capabilities on every central-admin route group. Existing fleet/billing/monitoring/vertical routes must remain full-admin only (`apps/api/routes/api.php:56-124`), while support-access routes must retain their current `super_admin`/`support_approver` matrix. Add a route-inventory test proving `defaults_editor` receives only auth profile/logout and country-defaults routes.

### F-05 — P1 blocker — The bootstrap does not run in the migration batch

The actual API entrypoint runs central migrations and rolling tenant migrations (`apps/api/docker/entrypoint.sh:118-145`). It runs the default database seeder only when `AUTO_SEED=true` **and** the central tenant count is zero; otherwise it skips seeding (`apps/api/docker/entrypoint.sh:169-190`). There is no unconditional central bootstrap-seeder hook. `ProductionSeeder` is not invoked by startup and does not currently contain this proposed bootstrap (`apps/api/database/seeders/ProductionSeeder.php:19-81`).

Thus, on an existing staging/production central DB, a single release will create empty template tables, skip the bootstrap, deploy resolver-reading code, and make new provisioning fail. The assertion that the bootstrap runs “in the same migration batch” is false: seeders are not migrations, and the deployed process has no such ordering guarantee. Startup also logs a central migration failure and continues (`apps/api/docker/entrypoint.sh:122-127`), so merely placing a step before runtime cache-building is not a hard release gate.

Required change: use a self-contained central data migration, an explicit fail-closed deploy command, or a two-release rollout (schema + verified bootstrap, then reader switchover). The release gate must verify three published templates, exact TN/FR/* assignments, row counts/hashes, and wildcard availability before reader code is enabled. “Skip if any templates exist” is not sufficient because one manually created draft would suppress the remaining bootstrap data.

### F-06 — P1 blocker — Seeder retirement misses direct and historical consumers

The two match sites are real (`apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:199-210`, `apps/api/app/Modules/Accounting/Application/Services/ChartOfAccountsService.php:134-148`), but they are not the only callers.

- `DatabaseSeeder` directly creates France and Tunisia seeders (`apps/api/database/seeders/DatabaseSeeder.php:83-89`, `apps/api/database/seeders/DatabaseSeeder.php:117-123`).
- `DemoTenantSeeder` directly creates the Tunisia seeder (`apps/api/database/seeders/DemoTenantSeeder.php:215-220`).
- `CoffeeShopSeeder` directly creates the Tunisia seeder (`apps/api/database/seeders/CoffeeShopSeeder.php:365-373`).
- `ParapharmacySeeder` dynamically instantiates a `ChartOfAccountsSeederContract` class (`apps/api/database/seeders/ParapharmacySeeder.php:627-638`); its default is France and `DemoPharmacySeeder` overrides it to Tunisia (`apps/api/database/seeders/ParapharmacySeeder.php:154-162`, `apps/api/database/seeders/DemoPharmacySeeder.php:97-113`).
- A historical tenant migration imports and instantiates `TunisiaChartOfAccountsSeeder` when rebuilding a tenant from scratch (`apps/api/database/migrations/tenant/2026_03_23_300000_fix_existing_tunisian_companies_tax_setup.php:5-8`, `apps/api/database/migrations/tenant/2026_03_23_300000_fix_existing_tunisian_companies_tax_setup.php:44-53`).

Deleting the classes in a “cleanup commit” would make fresh tenant migration replay fail on the historical migration and break multiple development/demo seeders. Merely leaving them in place means those paths continue bypassing the template library.

Required change: inventory and classify every direct caller. Adapt active seeders and `ChartOfAccountsSeederContract` to the new path; retain a permanent compatibility class for historical migration replay or make the historical migration independent of a deletable class without mutating already-shipped migration behavior. Update direct-seeder tests deliberately rather than assuming the two service rewires cover them.

### F-07 — P1 blocker — “New tenants only” conflicts with existing-tenant company creation

The spec says templates apply at tenant creation only, yet it also rewires `ChartOfAccountsService`. That service is called when an authenticated existing tenant creates another company (`apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:153-163`). Therefore, after an assignment is repointed, a second company created inside an old tenant would receive the current central template, even though that tenant is supposed to retain its own local copy and be insulated from future template changes.

Required change consistent with the owner's ruling: define and persist the template version selected at **tenant creation** (for example, a central immutable assignment snapshot on the tenant directory row) and use that version for later companies in the same tenant, or explicitly state that “new tenants only” actually means “new companies” and obtain an owner correction. The current spec cannot simultaneously make both promises.

### F-08 — P1 blocker — Existing-company creation can succeed without a chart

Initial database-per-tenant registration does have the claimed compensation: tenancy is initialized before full tenant setup, and any exception ends tenancy, drops the tenant DB best-effort, and removes central rows (`apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:117-125`, `apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:187-215`, `apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:218-249`).

But the other rewired caller is materially different. `CompanyController` catches `RuntimeException` from COA seeding, logs a warning, continues tax provisioning, and returns the company (`apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:153-165`). A missing central assignment/template can therefore create an existing tenant's new company with no COA. That contradicts the spec's “fails loudly” model and leaves later GL paths broken.

Required change: specify failure semantics for both callers. Company creation must roll back if required defaults cannot resolve/seed; do not rely on `TenantProvisioningService` compensation outside registration. Add tests for resolver failure in both initial tenant registration and additional-company creation.

### F-09 — P1 blocker — There is no central country catalog for the assignments matrix

`countries` is created by a **tenant** migration (`apps/api/database/migrations/tenant/2025_12_01_192409_create_countries_table.php:12-28`). `TenantProvisioningService` explicitly documents that the countries table is not central (`apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:74-80`), and `CountriesSeeder` writes through the unpinned `Country` model (`apps/api/database/seeders/CountriesSeeder.php:630-635`; `apps/api/app/Models/Country.php:12-20`). A central admin endpoint cannot safely treat that tenant table as its catalog.

Moreover, registration validates only “required string of size 2”; it does not require membership in `CountriesSeeder` (`apps/api/app/Modules/Identity/Presentation/Requests/RegisterRequest.php:69-74`). Such a country will correctly need the wildcard at provisioning, but it cannot appear in the proposed “CountriesSeeder list” assignment UI, so an exact assignment cannot be administered there.

Required change: define an authoritative central/static country-code provider for the admin matrix, define whether inactive/unknown ISO codes can receive exact assignments, and test an accepted two-letter code absent from `CountriesSeeder`. The wildcard must be visible and non-removable independently of the country list.

### F-10 — P1 blocker — Golden parity is under-specified and raw-row equality is impossible

All three seeders generate random UUIDs and then store those IDs in `parent_id`; the Tunisia seeder's second pass demonstrates the shape (`apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php:74-99`). Seeding old and template paths into separate fresh companies/databases necessarily produces different `parent_id` values. If “except ids” excludes `parent_id`, the test fails to verify hierarchy parity; if it includes `parent_id`, equality always fails.

The source arrays are also private (`apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php:120-126`, `apps/api/database/seeders/FranceChartOfAccountsSeeder.php:117-123`, `apps/api/database/seeders/GenericChartOfAccountsSeeder.php:101-107`), so a central bootstrap seeder cannot simply “import the arrays” without first refactoring the source, using reflection, or materializing rows by running tenant seeders. If both paths are changed to consume one extracted definition, the parity test can become tautological unless an independent frozen golden is retained.

Required change: define a canonical comparison projection such as `(code, name, type, parent_code, system_purpose, is_active, is_system, normalized balance, ordinal)`, resolving `parent_id` back to parent code. Produce immutable golden fixtures from the pre-refactor seeders before sharing any data source. Test exact row count, order, hierarchy, and all 41 purpose assignments, plus behavior for an arbitrary non-TN/FR code.

### F-11 — P1 blocker — Cache invalidation has an acknowledged stale re-cache race

The precedent uses `GlobalCache::remember()` and a plain `forget()` (`apps/api/app/Services/VerticalConfigService.php:64-70`, `apps/api/app/Services/VerticalConfigService.php:82-104`). `GlobalCache` is the correct tenancy-neutral cache manager, and the test suite proves it survives tenancy transitions (`apps/api/tests/Feature/Services/TenantConfigCacheTenancyTest.php:23-36`, `apps/api/tests/Feature/Services/TenantConfigCacheTenancyTest.php:177-203`). However, the codebase explicitly documents that forget-based invalidation has an in-flight read race: a read started before the forget can repopulate stale data after the forget and retain it for the TTL (`apps/api/app/Services/CompanyConfigService.php:95-103`).

For vertical modules that race self-heals after 24 hours. For country defaults it violates the stronger promise that assignment changes affect “the next tenant”: multiple later tenants can receive the old certified template for the entire cache TTL.

Required change: either do not cache provisioning resolution (tenant creation is low-frequency), or use versioned keys/generation tokens whose value changes atomically with the assignment transaction. Add a concurrency regression test where an old read overlaps a repoint and must not make the old assignment visible again.

### F-12 — P1 blocker — Current central-admin auth has no MFA gate

The current login validates email/password, checks `is_active`, and immediately issues a Sanctum token (`apps/api/app/Http/Controllers/Api/Admin/SuperAdminAuthController.php:18-56`). The `super_admins` schema has no MFA secret, challenge state, recovery codes, or verified-at field (`apps/api/database/migrations/2025_12_01_194614_create_super_admins_table.php:14-24`). Therefore “defaults_editor accounts are subject to the same policy” currently means password-only authentication, regardless of an owner-confirmed future requirement.

Required change: either include and test the actual MFA gate before external `defaults_editor` accounts can authenticate, or explicitly make external-account enablement blocked until the separate MFA requirement lands. The spec cannot rely on an unimplemented policy as a security mitigation.

### F-13 — P2 should-fix — Central connection pinning must be explicit

During registration the default connection is switched to the tenant DB before initialization (`apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:117-125`). The named `central` connection is intentionally never swapped (`apps/api/config/database.php:105-115`). The working precedent is safe because `VerticalConfig` uses Stancl's `CentralConnection` concern (`apps/api/app/Models/VerticalConfig.php:20-24`), and `AdminAuditLog` does the same (`apps/api/app/Models/AdminAuditLog.php:32-36`).

The design says the resolver “reads from the central connection,” but does not make connection pinning a model-level invariant for all three new models. An ordinary Eloquent model would query the tenant DB during provisioning and fail with a missing table.

Required change: state that `AdminTemplate`, `AdminTemplateAccount`, and `CountryTemplateAssignment` all use `CentralConnection` (or that every query is explicitly `DB::connection('central')`), and add a real tenancy-initialized test proving central resolution works while the default connection points at a tenant database.

### F-14 — P2 should-fix — Lifecycle invariants need atomic enforcement

The data model has independent `domain` columns on templates and assignments but specifies only “template must be published”; it does not state that `assignment.domain === template.domain`. This becomes a real corruption path as soon as the second domain ships. Likewise, “cannot archive/delete while assigned” and “assign only published” are status checks that no FK can enforce by itself.

Application-level check-then-write logic races: one request can verify that a template is unassigned while another assigns it, or verify `published` while another archives it. The existing vertical controller is not a transaction/locking precedent: it performs an upsert, invalidation, fanout, and audit as separate operations (`apps/api/app/Http/Controllers/Api/Admin/VerticalConfigController.php:102-139`).

Required change: specify central DB transactions and row locks for publish, archive, delete, and assignment repoint; validate same-domain assignment; lock both old/new assignment targets in deterministic order; and define whether archived-but-still-referenced rows remain resolvable. The wildcard invariant must also be enforced by mutation rules (not merely a health check) so the only fallback cannot be removed or pointed at a non-published/wrong-domain template.

### F-15 — P2 should-fix — Seeder reruns do rewrite existing rows

The spec says the exact existing semantics are “never rewrite existing rows; only promote `is_system`.” The first pass does preserve existing name/type/purpose and may promote `is_system` (`apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php:38-58`). But the second pass updates `parent_id` for every definition present in the ID map, including pre-existing accounts (`apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php:94-100`). The productized seed command explicitly reports this behavior as “reparented” and says seeders “re-issue parent links” (`apps/api/app/Console/Commands/SeedChartsCommand.php:47-50`, `apps/api/app/Console/Commands/SeedChartsCommand.php:110-123`).

Required change: decide whether `TemplateChartOfAccountsSeeder` preserves this reparenting behavior or adopts genuinely non-rewriting semantics. Document it precisely and test a rerun after a tenant manually changes an existing account's parent. Do not call the current behavior “only promote `is_system`.”

### F-16 — P2 should-fix — Audit writes are not atomic or certification-grade

The vertical-config precedent commits the config update and cache invalidations before calling `AdminAuditService` (`apps/api/app/Http/Controllers/Api/Admin/VerticalConfigController.php:107-139`). `AdminAuditService::log()` then performs a separate `AdminAuditLog::create()` (`apps/api/app/Services/AdminAuditService.php:84-114`). If audit insertion fails, the mutation has already happened; if a controller forgets to call the service, the model/observer does not enforce an audit row.

For a bulk rows endpoint, this is not a “full audit trail” unless the design defines an exact before/after representation and commits content mutation, lifecycle transition, assignment change, and audit record in one central transaction. `CrossTenantRoute` is only an architectural annotation; it carries a reason for scanners and is not itself an audit write (`apps/api/app/Shared/Architecture/CrossTenantRoute.php:10-17`, `apps/api/app/Shared/Architecture/CrossTenantRoute.php:30-40`).

Required change: make audit creation part of each mutation transaction, define stable action/entity identifiers and row-level diffs or content hashes, and test rollback when audit creation fails. Cache invalidation should occur after commit.

### F-17 — P2 should-fix — No external-account administration lifecycle is designed

The API surface contains template/assignment operations but no central-admin account provisioning, disable, credential reset, role change, or revocation endpoints. Today the only central-admin creation path is environment-driven `SuperAdminSeeder`; it creates the main admin and optional support approver from configuration (`apps/api/database/seeders/SuperAdminSeeder.php:19-38`, `apps/api/database/seeders/SuperAdminSeeder.php:40-60`).

Required change: specify how the chartered accountant account is created, activated, disabled, and recovered, and how all of its tokens are revoked on disable/role change. Manual SQL is not an adequate lifecycle for an external privileged actor.

### F-18 — P2 should-fix — “Certification” has no model or approval semantics

The problem statement invokes chartered-accountant review and per-country certification, but the proposed template header contains only status, provenance, timestamps, and `created_by` (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:50-62`). It has no jurisdictional standard/version, effective date, certified/reviewed-by identity, review decision, qualification/evidence reference, expiry/supersession, or content hash. The same `defaults_editor` can edit, publish, and repoint assignments under the proposed API (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:125-140`), so “published” is not demonstrably a separate certification decision.

`AdminAuditLog` records generic action/entity/old/new JSON and request metadata (`apps/api/app/Models/AdminAuditLog.php:13-30`, `apps/api/app/Models/AdminAuditLog.php:40-57`); that does not by itself say what regulatory plan was certified or preserve a signed review artifact.

Required change: either narrow the goal to “reviewable/auditable configuration” and stop claiming certification, or add explicit certification metadata/workflow and define whether four-eyes approval is required. At minimum a published version needs a stable content hash, reviewer identity, reviewed standard/version, decision timestamp, and supersession link.

### F-19 — P3 nit — Citation and naming corrections

- `Account::findByPurpose()` is at `apps/api/app/Modules/Accounting/Domain/Account.php:238-242`; line 229 is the underlying `scopeWithPurpose()` predicate (`apps/api/app/Modules/Accounting/Domain/Account.php:221-242`).
- The match-site citations are accurate (`apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:199-210`; `apps/api/app/Modules/Accounting/Application/Services/ChartOfAccountsService.php:139-148`).
- The physical central database must not be specified as `synerivia_central`; current configuration deliberately uses a deployment-specific product database and explicitly warns not to name ERP resources after Synerivia (`apps/api/config/database.php:124-135`). Refer to the logical `central` connection instead.
- The tenant account API is not CRUD: it lists, shows, creates, and updates accounts, but has no account-delete route (`apps/api/app/Modules/Accounting/Presentation/routes.php:25-41`). Purpose mappings do have a delete/unassign route (`apps/api/app/Modules/Accounting/Presentation/routes.php:60-75`).

## Verified claims and non-findings

- The two duplicated TN/FR/default match sites cited by the spec exist exactly where claimed (`apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:199-210`; `apps/api/app/Modules/Accounting/Application/Services/ChartOfAccountsService.php:134-148`).
- `SystemAccountPurpose` has 41 enum cases, and tenant accounts have a unique `(company_id, system_purpose)` constraint plus a unique `(company_id, code)` constraint (`apps/api/app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php:14-103`; `apps/api/database/migrations/tenant/2025_12_06_002513_add_company_id_and_system_purpose_to_accounts.php:53-58`, `apps/api/database/migrations/tenant/2025_12_06_002513_add_company_id_and_system_purpose_to_accounts.php:84-87`).
- `GlobalCache` is the correct tenancy-neutral cache mechanism; the defect is the stronger instant-next-tenant guarantee under forget races, not the selection of `GlobalCache` (`apps/api/app/Services/VerticalConfigService.php:22-30`; `apps/api/tests/Feature/Services/TenantConfigCacheTenancyTest.php:23-36`).
- Central reads during initialized tenancy are possible and already proven by the `VerticalConfig` + `CentralConnection` precedent (`apps/api/app/Models/VerticalConfig.php:20-24`; `apps/api/tests/Feature/Services/TenantConfigCacheTenancyTest.php:177-203`).
- Initial registration failures do enter the existing compensation path and remove tenant DB/central directory rows best-effort (`apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:211-249`).
- The cited `6354` backfill is purpose-first and collision-aware: it skips an already mapped purpose, claims a compatible preferred-code row, or chooses a nearby free code (`apps/api/database/migrations/tenant/2026_08_07_100000_backfill_purchase_stamp_duty_account.php:96-145`, `apps/api/database/migrations/tenant/2026_08_07_100000_backfill_purchase_stamp_duty_account.php:193-214`). It is not itself evidence that arbitrary template renumbering is unsafe; the runtime treasury resolver in F-01 is.

## Gate conditions before implementation planning

At minimum, revise the spec to close F-01 through F-12, then re-review it before producing an implementation plan. The revision needs a complete consumer migration inventory, operational-purpose contract, current-role-compatible authorization matrix, real bootstrap rollout, tenant-version snapshot semantics, exact parity normalization, authoritative country source, atomic lifecycle/audit rules, and a cache strategy that cannot reintroduce stale assignments.

FINAL VERDICT: REJECT
