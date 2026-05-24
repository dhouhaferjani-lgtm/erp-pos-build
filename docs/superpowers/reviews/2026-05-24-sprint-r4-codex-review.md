# Round 4 Review
## Verdict: REJECT

## Summary (3 paragraphs)

v4 fixes most of the original round-3 topology findings at the planning level. The syntax-independent tenant-FK audit is now explicit in both the topology contract and T6; shared reference data is no longer parked; `tenant_subscriptions` and `users` citations are corrected; Spatie is classified tenant-side with code-grounded rationale; and the roadmap now correctly says Wave 1 has zero Tauri POS touch but still has backend-POS handshakes.

The remaining round-3 misses are narrower but still material. T6 Phase 0 effort is still inconsistent in the roadmap and T6 workflow section, POS coordination still says T2 adds `variant_id` to `payments`, and the T1/POS wording still leaks "tenant setting" terminology for a per-company `InTransitAvailability` setting. Those are fixable edits, but they matter because these docs are coordination contracts.

The new v4 tenant-identification architecture is the new blocker. The Stancl middleware class names exist in the installed `stancl/tenancy` v3.10.0, but their default semantics do not match the v4 plan: `InitializeTenancyByRequestData` reads `X-Tenant` and resolves `tenancy()->find($payload)` by tenant primary key, while v4 requires `X-Tenant-ID` carrying a slug. The current POS login and API login accept only email/password and send no tenant header. Starting Phase 0 from this spec would flip database topology without a workable POS/API tenant resolution path.

## Round-3 finding resolution table

| Finding | Status | Evidence |
|---|---|---|
| B-1: Phase 0 misses non-`constrained('tenants')` FKs | FIXED | Topology now requires both `constrained('tenants')` and `references('id')->on('tenants')` greps and names `users`, `companies`, `product_images` at `docs/.../migration-topology-contract.md:134-139`; T6 repeats it at `docs/.../t6-tenant-provisioning.md:70-73`. Actual files confirm the cited patterns: `apps/api/database/migrations/2025_11_30_000003_create_users_table.php:33-36`, `2025_11_30_104000_create_companies_table.php:104-105`, `2025_12_29_155412_create_product_images_table.php:33-34`. |
| B-2: Shared reference-data classification parked | PARTIALLY FIXED | Topology now decides reference data is tenant-side at `migration-topology-contract.md:200-214`, matching actual FKs in `country_tax_rates` and `tax_configurations` (`apps/api/database/migrations/2025_12_01_192545_create_country_tax_rates_table.php:24-27`, `2025_12_30_100000_create_tax_configurations_table.php:13-17`). Remaining inconsistency: topology says Phase 0 extends `TenantInitializationService` to seed countries first, while T6 says not to modify that service in Phase 0 and moves the seeding extension to Phase 1A (`t6-tenant-provisioning.md:36`, `120-129`). |
| B-3: T6 Phase 0 effort silently/inconsistently filled in | PARTIALLY FIXED | T6 header and Phase 0 section now say `~8 PD` (`t6-tenant-provisioning.md:6`, `53-57`) and roadmap Tier A says `~8 PD` (`productization-sprint-roadmap.md:226-228`). But the roadmap top diagram still says `~2-3 days`, the spec table still says `~3 PD`, and T6 workflow still says Phase 0 `~3 PD` (`productization-sprint-roadmap.md:39`, `97`; `t6-tenant-provisioning.md:284`). |
| P1-1: Roadmap/POS log tell T2 to add `variant_id` to `payments` | PARTIALLY FIXED | Roadmap is fixed and explicitly says NOT `payments` (`productization-sprint-roadmap.md:130`, `141-143`); T2 owns only POS receipt line tables (`t2-variants.md:95`, `289`). POS coordination log still says T2 migrations touch `payments` at `docs/.../pos-coordination-log.md:5` and `:9`. |
| P1-2: T1 contradicts per-company vs per-tenant `InTransitAvailability` | PARTIALLY FIXED | Core T1 contract is now per-company (`t1-stock-transfer.md:103`, `118-124`, `166`, `182`) and actual `Company::getReservationSettings()` exists at `apps/api/app/Modules/Company/Domain/Company.php:506-518`. Stale wording remains in T1 POS/acceptance text and POS log: `tenant setting` / `tenant's InTransitAvailability` at `t1-stock-transfer.md:171-172`, `200-201`, and `pos-coordination-log.md:41`. |
| P1-3: T6 overclaims `TenantInitializationService` seeding | FIXED AS SPEC WORK | T6 now states the current service imports/calls only CoA/payment/tax seeders and does not call `TunisiaStampDutySeeder`, `TunisianParapharmacySeeder`, or `TunisiaWithholdingRulesSeeder`; it adds those plus country/reference seeding to Phase 1A (`t6-tenant-provisioning.md:120-131`). Actual service confirms current calls and the missing country guard at `apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:14-19`, `53-69`, `205-223`. |
| P1-4: phpunit/PG strategy and Spatie classification not resolved/marked | PARTIALLY FIXED | Spatie is now decided tenant-side with correct code evidence (`migration-topology-contract.md:36`; actual config/migration at `apps/api/config/permission.php:95-134`, `apps/api/database/migrations/2025_11_29_231806_create_permission_tables.php:36-44`, `61-66`, `85-90`). phpunit/PG remains Option A/B rather than a chosen implementation (`t6-tenant-provisioning.md:79-83`), and current CI PG tests still run only on PR to main/push main/manual (`.github/workflows/ci.yml:233-241`). |
| P1-5: Roadmap top still claims Wave 1 collision-free | FIXED | Top diagram now says `ZERO Tauri POS touch` and explicitly calls out backend-POS handshakes (`productization-sprint-roadmap.md:54-60`), then repeats the backend collision details at `:126-143`. |

## New v4 issues (BLOCKER / P1 / P2 / SUGGESTION)

### BLOCKER

- **[B-1] Section 9 POS/API tenant resolution cannot work as written with installed Stancl and current auth/POS code.** Installed `stancl/tenancy` is `v3.10.0` in `apps/api/composer.lock:8098-8099`, and the named middleware classes exist in vendor. But `InitializeTenancyByRequestData` defaults to header `X-Tenant` and query parameter `tenant` (`apps/api/vendor/stancl/tenancy/src/Middleware/InitializeTenancyByRequestData.php:15-20`), and its resolver returns `tenancy()->find($payload)` (`RequestDataTenantResolver.php:21-29`), i.e. the tenant primary key, not `tenants.slug`. v4 requires first login body `{tenant_id, email, password}` and subsequent `X-Tenant-ID` carrying slug (`migration-topology-contract.md:230-234`). Current API `LoginRequest` validates only email/password/device fields (`apps/api/app/Modules/Identity/Presentation/Requests/LoginRequest.php:25-35`), `AuthController::login()` looks up `User::where('email', ...)` before tenancy initialization (`AuthController.php:171-189`), POS login collects only email/password (`apps/pos/src/pages/LoginPage.tsx:21-23`, `139-167`), and `api.ts` sends `Authorization`, `X-Company-Id`, and `X-Client-Type` but no tenant header (`apps/pos/src/lib/api.ts:61-80`). T6 Phase 0 must specify and own a real slug-aware resolver/middleware or switch to tenant UUIDs and the Stancl default header.

### P1

- **[P1-1] Section 9 claims a `tenants.database_name` central field that does not exist.** The create migration has `slug`, `status`, timestamps, etc. but no `database_name` (`apps/api/database/migrations/2025_11_30_000001_create_tenants_table.php:16-35`), and no later migration adds it (`rg database_name apps/api/database/migrations apps/api/app` returns no app/migration hit). `Tenant::getDatabaseName()` does return `tenant_{slug}` (`apps/api/app/Modules/Tenant/Domain/Tenant.php:248-250`), so the docs should either remove the field claim or add a central migration and model fillable/custom-column wiring.

- **[P1-2] Web subdomain flow is underspecified against Stancl semantics.** `InitializeTenancyByDomain` exists, but it passes the full request host to `DomainTenantResolver`, which queries `domains.domain` (`InitializeTenancyByDomain.php:35-39`, `DomainTenantResolver.php:30-40`). It does not extract `{tenant_slug}` from `{tenant_slug}.synerivia.tn`. If the intended contract is full-domain rows such as `nenupharma.synerivia.tn`, T6 must state signup/provisioning creates those `domains` rows. If the intended contract is slug extraction, use/configure `InitializeTenancyBySubdomain` or a custom slug resolver.

- **[P1-3] Reference-data seeding phase is internally inconsistent.** The topology contract says Phase 0 extends `TenantInitializationService` to seed `countries` first (`migration-topology-contract.md:214`), but T6 architecture grounding says do not modify that service in Phase 0 (`t6-tenant-provisioning.md:36`) and puts the extension in Phase 1A (`:120-129`). Pick one phase; otherwise Phase 0 can move reference tables tenant-side without the claimed claim-time seed path being ready.

### P2

- **[P2-1] POS coordination log still contains stale round-3 defects.** It still has the broken relative fiscal-plan link (`../superpowers/plans/...`) at `pos-coordination-log.md:3`, still says Wave 1 backend T2 touches `payments` at `:5` and `:9`, and still says no entry below applies to Wave 1 despite listing Wave 1 backend items at `:7-10` and `:30`.

- **[P2-2] Section 9 failure-mode text contradicts its privacy goal.** It says unknown `tenant_id` returns 404 "tenant not found" to avoid revealing whether the tenant exists vs email, while bad credentials against a valid tenant return 401 (`migration-topology-contract.md:252-256`). That distinction reveals tenant existence. Use a generic pre-auth response for unknown tenant and bad credentials if enumeration resistance is the goal.

### SUGGESTION

- Update the roadmap title/version labels. The content is v4-reworked, but it still says "Source of Truth (v2)" and "Why this is v2" (`productization-sprint-roadmap.md:1`, `11`). This is not blocking, but it makes review provenance harder to follow.

## Ready-to-start verdict

T6 Phase 0 is **not ready to start** from v4.

Do the following before starting implementation:

1. Replace Section 9 with a concrete tenant-resolution contract: exact header name, slug-vs-UUID decision, Stancl resolver/middleware implementation, API login changes, POS storage/header changes, and tests.
2. Remove or implement the `tenants.database_name` field claim.
3. Make the T6 Phase 0 estimate consistent everywhere.
4. Clean the POS coordination log stale `payments` and Wave 1 wording.
5. Decide the reference-data seeding phase and align topology + T6.
