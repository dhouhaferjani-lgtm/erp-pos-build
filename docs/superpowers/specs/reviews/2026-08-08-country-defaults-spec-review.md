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

## Round 2

Reviewed revision: Rev 2 at commit `a5d129ec2` (repository HEAD `a5d129ec25`).

Review posture: adversarial re-review before implementation planning. The three owner rulings are treated as constraints, not reopened: protected treasury codes in v1 with a later purpose-migration lane; current published assignment for every newly created company; certification metadata on published versions with four-eyes deferred.

### Round-1 closure register

| ID | Status | Round-2 verification |
|---|---|---|
| F-01 | PARTIAL | Rev 2 now acknowledges the literal treasury resolver and selects the owner's protected-code ruling (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:33-38,116-125`), matching the real code/type lookup (`apps/api/app/Modules/Treasury/Application/Services/InstrumentAccountResolver.php:25-30,41-70`). It is not closed because the gate incorrectly requires non-account withholding display codes, does not require protected rows to be tenant-immutable, and makes the publish-time country check vacuous in the normal lifecycle (R2-01, R2-07, R2-08).
| F-02 | PARTIAL | Rev 2 correctly stops treating the 11-value `requiredPurposes()` helper as completeness and proposes a separate versioned contract (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:116-125`; `apps/api/app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php:155-175`). The contract is still not enumerated, its grep/reflection guard does not account for the private `getAccountByPurpose()` wrapper (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4582-4584`) and its many callers (`GeneralLedgerService.php:137-139,1720-1721`), and at least one frozen baseline cannot meet the implied set (R2-02).
| F-03 | CLOSED | The publish gate now expressly enforces purpose/type equality and `system_purpose != null => is_system` (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:118-125`). Those are the actual type contract and tenant mutation boundary (`apps/api/app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php:177-205`; `apps/api/app/Modules/Accounting/Presentation/Controllers/AccountController.php:164-180`). Protected rows without purposes remain a separate new defect (R2-07), not a reopening of this finding.
| F-04 | PARTIAL | The revision preserves `super_admin` and `support_approver`, adds `defaults_editor`, separates active central-admin authentication, keeps existing full-admin groups restricted, and retains the support-access role matrix (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:170-178`). That matches the current enum/middleware topology (`apps/api/app/Models/Enums/SuperAdminRole.php:7-11`; `apps/api/app/Http/Middleware/EnsureSuperAdmin.php:42-59`; `apps/api/app/Http/Middleware/RequireCentralAdminRole.php:17-30`; `apps/api/app/Modules/SupportAccess/Presentation/routes.php:17-46`). It remains ambiguous at the privileged editor-lifecycle endpoints, where the nonexistent `owner` role is named and no nested `super_admin` middleware is specified (R2-06); the UI entry path is also incomplete (R2-12).
| F-05 | PARTIAL | Rev 2 accurately describes the entrypoint and chooses a two-release central-data-migration rollout (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:162-168`). The entrypoint really does run central migrations, continue after migration failure, and conditionally seed only an empty installation (`apps/api/docker/entrypoint.sh:118-127,169-190`). The claimed per-template stable bootstrap key does not exist in the proposed schema (R2-04), and `country-defaults:verify` is not invoked by the auto-deploy entrypoint; the release-2 interlock is therefore a manual checklist condition, not the claimed self-guard (`apps/api/docker/entrypoint.sh:118-207`; `docs/factory/WORKFLOW.md:190-217`).
| F-06 | PARTIAL | The consumer table now covers both services, `DatabaseSeeder`, `DemoTenantSeeder`, `CoffeeShopSeeder`, the contract-driven pharmacy path, and the historical migration (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:135-145`). It still misses a live direct caller, `TunisianParapharmacySeeder` (`apps/api/database/seeders/TunisianParapharmacySeeder.php:68-75`), and a separate literal-code COA consumer used by the pharmacy seeders (`apps/api/database/seeders/ExpenseCategorySeeder.php:37-65,86-93`). See R2-09 and R2-10.
| F-07 | CLOSED | The locked rule is now unambiguous: every newly created company gets the current assignment, while existing company accounts are untouched (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:5-11,51-54,135-145`). The additional-company path creates and seeds inside its own transaction (`apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:70-80,153-166`).
| F-08 | CLOSED | Rev 2 explicitly removes the swallow-and-continue behavior and requires rollback in both provisioning paths (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:146-150`). This is compatible with the existing additional-company transaction (`apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:70-80,153-166`) and the registration compensation path (`apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:211-249`).
| F-09 | CLOSED | A versioned static central ISO provider plus visible, non-removable wildcard is now specified, including a test for an accepted code absent from `CountriesSeeder` (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:97-107`). That addresses both the tenant-only country table (`apps/api/database/migrations/tenant/2025_12_01_192409_create_countries_table.php:12-28`) and the real size-only registration validation (`apps/api/app/Modules/Identity/Presentation/Requests/RegisterRequest.php:69-74`). Case normalization remains a separate new defect (R2-05).
| F-10 | PARTIAL | The revision supplies an ID-independent projection, frozen fixtures, exact hierarchy/order comparison, and a feasible scratch-schema exporter that does not reflect private arrays (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:151-156`). This resolves random UUID/parent-ID comparability. It is not fully executable as written because `is_active` is part of the canonical projection/hash but absent from the template-row schema (R2-11).
| F-11 | CLOSED | Provisioning resolution is now explicitly uncached (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:127-133`), so it no longer inherits the documented forget/repopulate race (`apps/api/app/Services/CompanyConfigService.php:95-103`). The separate admin-list cache allowance does not affect provisioning correctness.
| F-12 | CLOSED | The revision chooses the exact acceptable mitigation from round 1: defaults-editor login is denied by a default-off feature flag until MFA exists (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:170-178`). That is necessary because current login is password plus active-state only (`apps/api/app/Http/Controllers/Api/Admin/SuperAdminAuthController.php:18-56`) and the central-admin schema has no MFA state (`apps/api/database/migrations/2025_12_01_194614_create_super_admins_table.php:14-24`).
| F-13 | CLOSED | All three new models are now explicitly required to use `CentralConnection`, with a tenancy-initialized regression test (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:60-64`). This matches the working `VerticalConfig` precedent (`apps/api/app/Models/VerticalConfig.php:20-24`) and the named connection that is never swapped (`apps/api/config/database.php:105-115`).
| F-14 | CLOSED | Rev 2 adds a composite `(template_id, domain)` guard backed by a unique `(id, domain)` parent key, central transactions, deterministic row locks, post-lock status checks, assignment-domain equality, reference-aware archive/delete, and an in-transaction wildcard rule (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:97-114`). The composite FK is structurally valid for the configured PostgreSQL central database (`apps/api/config/database.php:124-140`). R2-08 concerns certification/publish context, not the original assignment-domain or mutation-race defect.
| F-15 | CLOSED | The revision now states the real two-pass semantics, including reparenting pre-existing rows, and pins it with a rerun test (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:158-160`). That matches the current first-pass preserve/promote behavior and unconditional second-pass parent update (`apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php:38-58,94-100`).
| F-16 | CLOSED | Audit creation is now service-owned and required inside the same central transaction, with before/after projections or hashes and post-commit cache work (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:198-200`). This deliberately improves on the current separate audit insert (`apps/api/app/Services/AdminAuditService.php:84-114`), while `AdminAuditLog` is already central-pinned (`apps/api/app/Models/AdminAuditLog.php:32-36`).
| F-17 | PARTIAL | Rev 2 adds create/disable/re-enable/reset flows, token revocation, and audit (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:177-178,180-197`). The lifecycle is not safely authorized until the nonexistent `owner` wording is replaced by an explicit nested `central_admin_role:super_admin` rule (R2-06). Today the only provisioning path remains the environment-driven seeder (`apps/api/database/seeders/SuperAdminSeeder.php:19-60`).
| F-18 | PARTIAL | The locked metadata now exists on published versions and the publish action sets all four required fields (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:66-81,116-125`). The bootstrap nevertheless inserts already-published versions before any certifying actor is available, and country scope is not bound to the certification decision (R2-03, R2-08).
| F-19 | CLOSED | The corrected citations and names are now accurate: both match sites (`apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:199-210`; `apps/api/app/Modules/Accounting/Application/Services/ChartOfAccountsService.php:134-148`), `findByPurpose()` rather than its scope predicate (`apps/api/app/Modules/Accounting/Domain/Account.php:221-242`), logical `central` naming (`apps/api/config/database.php:124-135`), and the no-delete account API statement (`apps/api/app/Modules/Accounting/Presentation/routes.php:25-75`; `docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:22-25,33,55,60-64`).

Round-1 closure count: **11 CLOSED / 8 PARTIAL / 0 OPEN**.

### New findings introduced or exposed by Rev 2

| ID | Severity | Finding |
|---|---|---|
| R2-01 | P1 blocker | The new protected-code gate requires withholding display codes that are not COA accounts, so none of the frozen templates can pass it |
| R2-02 | P1 blocker | The frozen France fixture and the new operational-purpose gate are mutually incompatible |
| R2-03 | P1 blocker | The data migration creates “published/certified” baselines before any certifier exists and bypasses the publish gate |
| R2-04 | P1 blocker | Bootstrap idempotency depends on a stable key that the data model does not contain |
| R2-05 | P1 blocker | The resolver does not specify country-code normalization and regresses the existing lowercase-safe company path |
| R2-06 | P1 blocker | Editor-account lifecycle authorization names a nonexistent role and can be implemented as defaults-editor self-administration |
| R2-07 | P2 should-fix | Protected literal-code rows are not required to be tenant-immutable |
| R2-08 | P2 should-fix | Publish-time per-country validation is unreachable in the normal lifecycle and certification has no jurisdiction binding |
| R2-09 | P2 should-fix | `ExpenseCategorySeeder` is an unhandled literal account-code consumer |
| R2-10 | P2 should-fix | The supposedly complete seeder-consumer inventory still omits `TunisianParapharmacySeeder` |
| R2-11 | P2 should-fix | The canonical fixture/hash projection contains a field the template schema cannot store |
| R2-12 | P2 should-fix | A permitted defaults editor is sent to, and can client-navigate through, full-admin pages immediately after login |

#### R2-01 — P1 blocker — Withholding display codes make the publish gate impossible

Rev 2 requires both `42236` and `42237` to exist in every relevant COA template (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:116-125`). Those values are not queried from `accounts`: `WithholdingDirection` returns a base string (`apps/api/app/Modules/Taxation/Domain/Enums/WithholdingDirection.php:26-31`), and `WithholdingCertificate` formats it into a display/PDF value such as `42236.10` (`apps/api/app/Modules/Taxation/Domain/Entities/WithholdingCertificate.php:214-224`). There is no account lookup or type predicate on this path.

Neither value exists in any frozen source array: the complete TN, FR, and Generic definitions occupy `apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php:125-318`, `apps/api/database/seeders/FranceChartOfAccountsSeeder.php:124-339`, and `apps/api/database/seeders/GenericChartOfAccountsSeeder.php:106-235`. Therefore the required golden-identical templates (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:151-156`) cannot pass the proposed publish rule, and release 1 cannot truthfully install three valid published templates.

Required change: keep the locked treasury account-code set, but remove `42236`/`42237` from COA existence/type validation unless a real posting path is first introduced that resolves those values against `accounts`. Treat the current withholding strings as document-format literals in their later domain lane.

#### R2-02 — P1 blocker — Frozen France parity conflicts with operational completeness

The new gate says it is derived from every unconditional purpose lookup (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:116-125`). Ordinary COGS posting unconditionally resolves `CostOfGoodsSold` whenever the computed COGS is positive (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1712-1721`). But the complete France source definition maps Inventory at `apps/api/database/seeders/FranceChartOfAccountsSeeder.php:153-158` and returns at `FranceChartOfAccountsSeeder.php:339` without assigning `CostOfGoodsSold` anywhere (`FranceChartOfAccountsSeeder.php:124-339`).

The spec simultaneously requires exact frozen-seeder purpose parity (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:151-156`) and three published bootstrap templates (`:162-168`). The France template cannot both remain golden-identical and satisfy the advertised operational gate. The proposed grep/reflection guard is also underspecified: many throwing lookups are hidden behind `GeneralLedgerService::getAccountByPurpose()` (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4582-4584`), including dynamic-purpose calls (`GeneralLedgerService.php:1455-1457,2565-2566`). PHP reflection of method metadata does not inventory that call graph.

Required change: enumerate the exact v1 required-purpose manifest in the spec, run it against all three frozen fixtures now, and resolve every failure deliberately. If baseline defects are corrected, keep two independent fixtures: immutable legacy-output goldens and explicitly reviewed/certified v1 template fixtures. Specify a real drift mechanism (for example an explicit consumer manifest plus AST/static-analysis ratchet), not an undefined “grep/reflect” test.

#### R2-03 — P1 blocker — Bootstrap bypasses certification and has no certifying actor

Publishing normally requires `standard_ref` and an acting admin, and sets `content_hash`, `certified_by`, and `published_at` (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:76-79,116-125`). Release 1 instead has a central data migration directly import three templates already marked published (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:162-168`). On a fresh installation, central migrations run before optional seeding (`apps/api/docker/entrypoint.sh:122-127,169-190`), while the first super-admin is only created by `DatabaseSeeder` afterward (`apps/api/database/seeders/DatabaseSeeder.php:36-43`). The migration therefore cannot satisfy its `certified_by` FK with a real reviewer, and direct insertion also bypasses every publish-gate check.

This is not a four-eyes objection. It violates the locked v1 metadata decision by presenting unreviewed legacy extracts as certified published versions.

Required change: bootstrap drafts (or a distinct non-certified legacy status), then require an authenticated certification command/workflow that executes the same publish gate and records the actual reviewer before assignments and release-2 switchover become valid. `country-defaults:verify` must reject published rows with missing certification fields and must execute all current publish invariants, not only compare fixture hashes.

#### R2-04 — P1 blocker — The stable bootstrap key has no storage

Release 1 promises a per-template presence guard keyed by a “stable bootstrap key” (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:162-168`). The proposed `admin_templates` schema contains only UUID id, domain, mutable name/description/status, provenance, certification fields, creator, and timestamps (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:66-81`). There is no bootstrap key or declared deterministic UUID. Name, hash, and domain cannot substitute: names are editable, content hashes change by version, and three templates will eventually share a domain.

Required change: add an immutable nullable unique `bootstrap_key` (or specify fixed deterministic IDs) and define collision behavior. The idempotency test must cover partial state: one baseline present, one conflicting manual draft, and retry after a rolled-back/failed import. Also correct the “self-guarding” claim: the real entrypoint logs migration failure and continues (`apps/api/docker/entrypoint.sh:122-127`), and it never invokes the proposed verifier (`entrypoint.sh:118-207`); the two-release process is safe only if the release-2 promotion gate is explicitly enforced outside that entrypoint.

#### R2-05 — P1 blocker — Country resolution can silently select the wrong template by case

The resolver specifies only exact country then wildcard (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:127-133`); it does not require canonical uppercase input. Registration happens to uppercase before creating the company (`apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:74-75`), but additional-company validation accepts any two-character string (`apps/api/app/Modules/Company/Presentation/Requests/CreateCompanyRequest.php:19-27`) and persists it verbatim (`apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:74-80`). The current COA service preserves correct behavior by calling `strtoupper()` before its match (`apps/api/app/Modules/Accounting/Application/Services/ChartOfAccountsService.php:141-147`).

Without an explicit resolver/assignment normalization invariant, an accepted `tn` additional company receives the wildcard Generic chart rather than TN. This directly violates the locked “every new company gets the current country assignment” ruling.

Required change: normalize and validate country codes at every write boundary and again inside `CountryTemplateResolver` (`trim` + uppercase), normalize assignment route parameters, and add lowercase TN/FR tests for both registration and additional-company creation.

#### R2-06 — P1 blocker — Editor lifecycle can become self-service privilege administration

Rev 2 correctly says there is no `owner` role and preserves the existing enum (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:170-175`; `apps/api/app/Models/Enums/SuperAdminRole.php:7-11`). It then calls editor lifecycle endpoints “owner-role” operations and lists them inside the country-defaults API (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:177-178,180-197`). The enclosing country-defaults group explicitly allows both `super_admin` and `defaults_editor` (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:174-176`). The current role middleware grants any role named in its parameters (`apps/api/app/Http/Middleware/RequireCentralAdminRole.php:15-30`); there is no implicit owner concept.

An implementation following only the stated group middleware lets a defaults editor create, re-enable, or reset credentials for peer privileged accounts. The phrase “owner role only” cannot enforce anything because that role does not exist.

Required change: rename this authorization to `super_admin only`, specify a nested `central_admin_role:super_admin` middleware on every `/editors` route, and extend the route-inventory test to assert a defaults-editor token gets 403 for every lifecycle verb.

#### R2-07 — P2 should-fix — Protected rows can still be renumbered after provisioning

The publish gate requires `is_system=true` only for rows carrying a `system_purpose` (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:118-121`). The protected-code rule requires existence and type but not `is_system` (`:121`). Tenant immutability is solely the boolean check (`apps/api/app/Modules/Accounting/Domain/Account.php:137-143`; `apps/api/app/Modules/Accounting/Presentation/Controllers/AccountController.php:164-180`). Most instrument rows have no `SystemAccountPurpose`; their current safety comes from the legacy seed definitions explicitly setting `is_system`, for example TN `403`/`4035` and `413`/`416` (`apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php:174-175,183-184`).

An editor can therefore publish a protected code with the correct type but `is_system=false`; it is locked in the central editor yet mutable in the tenant, defeating the owner-selected v1 protection.

Required change: every protected account row must also be active and `is_system=true`, enforced server-side at publish and assignment time and covered by negative tests.

#### R2-08 — P2 should-fix — Country-aware publishing is normally context-free

Assignments may reference only published templates (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:97-105`), while the normal lifecycle is draft edit, then publish, then assignment (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:109-123`). Consequently a draft reaching publish normally has no current assignments. “Countries the template … may be assigned to” is not represented anywhere, so the publish-time country-specific protected check at `:121` has no deterministic input. Assignment-time revalidation protects provisioning, but the template has already acquired published/certified metadata.

The same model does not bind certification to a jurisdiction: a template has one `standard_ref` and certifier (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:66-81`) but may be assigned to many exact countries and the wildcard. That cannot demonstrate the stated per-country certification without reopening the locked choice to store metadata on the published version.

Required change: add immutable certified-jurisdiction scope to each published version (for example `certified_country_codes`, with an explicit wildcard/non-TN scope) and validate the protected set for that scope at publish. Assignment must be allowed only within the certified scope and re-run the same validation. A published version may still retain the four locked metadata fields; no four-eyes workflow is required here.

#### R2-09 — P2 should-fix — A pharmacy seeder hardcodes editable account codes

`ExpenseCategorySeeder` maps semantic categories to literal TN codes `613`, `615`, `616`, `624`, and `626`, then queries `accounts.code` (`apps/api/database/seeders/ExpenseCategorySeeder.php:13-44,52-65`). If an accountant validly renumbers one of those non-protected rows in the TN template, the category silently falls back to `GeneralExpense` (`ExpenseCategorySeeder.php:54-69`), changing posting semantics rather than failing. This seeder is called by both pharmacy paths (`ExpenseCategorySeeder.php:86-93`; `apps/api/database/seeders/ParapharmacySeeder.php:649`; `apps/api/database/seeders/TunisianParapharmacySeeder.php:100-103`).

Required change: include this in the COA consumer migration. Replace literal lookup with stable semantic template metadata/purposes, or explicitly protect and certify those mappings; do not silently collapse a missing specialized account to GeneralExpense after a template edit.

#### R2-10 — P2 should-fix — One active direct seeder caller remains outside the inventory

The “complete inventory” (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:135-145`) omits `TunisianParapharmacySeeder`, which creates an additional company and directly instantiates `TunisiaChartOfAccountsSeeder` when no accounts exist (`apps/api/database/seeders/TunisianParapharmacySeeder.php:53-75`). It also has dedicated feature tests that execute the seeder by class (`apps/api/tests/Feature/Seeders/SeededProductsHaveTaxRateTest.php:70-81`; `apps/api/tests/Feature/Seeders/DemoSeedersTaxTest.php:89-99`).

Required change: add this seeder and its tests to the migration table and route it through the template-backed path. Keep direct tests of the three frozen classes only where they deliberately verify historical compatibility; classify those tests explicitly so future cleanup cannot mistake them for active provisioning coverage.

#### R2-11 — P2 should-fix — `is_active` is hashed but cannot be stored

The canonical golden/content-hash projection includes `is_active` (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:151-156`), and tenant accounts really have that persisted flag (`apps/api/database/migrations/tenant/2025_11_30_090000_create_accounts_table.php:13-23`); all three legacy seeders explicitly write it true, for example France at `apps/api/database/seeders/FranceChartOfAccountsSeeder.php:76-90`. But `admin_template_accounts` has no `is_active` column (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:83-95`).

Required change: either add `is_active` to template content and define whether drafts may disable rows, or state that it is a derived constant `true` excluded from stored content and hash only the actual canonical template representation. Specify exact deterministic serialization and row ordering for SHA-256; a tuple name alone is not enough for a certification-grade cross-version hash.

#### R2-12 — P2 should-fix — Defaults-editor login lands on a forbidden UI

The backend design allows a defaults editor to authenticate and access only auth plus country-defaults routes (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:174-177`). The current login success handler unconditionally navigates to `/admin/dashboard` (`apps/web/src/features/admin/pages/AdminLoginPage.tsx:16-31`), the admin index also redirects there (`apps/web/src/routes/index.tsx:385-400`), and `RequireAdminAuth` checks authentication but no role (`apps/web/src/features/admin/components/RequireAdminAuth.tsx:8-16`). The current layout exposes all privileged links to every authenticated admin (`apps/web/src/features/admin/components/AdminLayout.tsx:8-17,50-70`), and the stored role union does not yet admit `defaults_editor` (`apps/web/src/features/admin/stores/adminAuthStore.ts:4-9`).

Rev 2 mentions role-filtered nav and route guards (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:202-208`) but does not specify a role-aware landing route or direct-navigation behavior.

Required change: send defaults editors to `/admin/country-defaults`, make the `/admin` index role-aware, wrap every full-admin client route in a `super_admin` guard (redirect or 403 page), extend the role type, and test direct URL navigation as well as sidebar filtering. Backend authorization remains authoritative.

### Verified Rev-2 additions with no separate finding

- The composite `(template_id, domain)` FK design is a valid database guard when the declared parent unique key is created first (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:97-105`). Published status still correctly remains an in-transaction application invariant because an FK cannot encode it (`:109-114`).
- Central reads during initialized tenancy are safe if all three proposed models actually use `CentralConnection`: the named central connection is never swapped (`apps/api/config/database.php:105-115`) and the existing tenancy-transition test proves the pattern (`apps/api/tests/Feature/Services/TenantConfigCacheTenancyTest.php:177-203`).
- Keeping `RequireCentralAdminRole` unchanged does not leak support-access routes to `defaults_editor`: the middleware checks active state and exact allowed strings (`apps/api/app/Http/Middleware/RequireCentralAdminRole.php:17-30`), and every support route lists only `super_admin` and/or `support_approver` (`apps/api/app/Modules/SupportAccess/Presentation/routes.php:17-46`). If implementation refactors that middleware into a capability-only check, `EnsureCentralAdmin` must be added to the support group in the same change so the active-account check is not lost.
- The no-cache provisioning decision is correct for the stated next-company guarantee (`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:127-133`). `GlobalCache` itself does work across tenancy transitions (`apps/api/tests/Feature/Services/TenantConfigCacheTenancyTest.php:23-36,177-203`); it is simply unnecessary here.
- The historical `6354` migration remains purpose-first and collision-aware, so Rev 2 correctly leaves existing-company changes to tenant backfills rather than template propagation (`apps/api/database/migrations/tenant/2026_08_07_100000_backfill_purchase_stamp_duty_account.php:96-145,187-229`; `docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md:51-54`). Other preferred-code backfills likewise run against existing companies; on fresh tenant creation migrations run before the company exists (`apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:117-140`).

FINAL VERDICT: REJECT
