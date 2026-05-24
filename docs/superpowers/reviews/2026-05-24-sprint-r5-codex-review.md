# Sprint Planning Round-5 Adversarial Review — Codex
**Date:** 2026-05-24
**Reviewer:** Codex headless

## Verdict
NEEDS-REVISION

Weakest link: T6 Phase 0 / topology Section 9.

## Summary

v5 fixed several real v4 problems. The Stancl defaults are now described accurately, the subdomain path now uses full `domains.domain` rows, T6 Phase 0 is mostly raised to 10 PD, the POS coordination log no longer points T2 at `payments`, T11 now references the renamed POS coordination log, and the T4 `DocumentAdditionalCost.php` citation is fixed.

The remaining risk is not the core DB topology anymore; it is the auth and identity surface created by that topology. v5 added `AuthController::login` and `AuthController::register` to Phase 0, but the current controller has additional pre-auth flows that explicitly assume a global `users` table: email availability, email verification, forgot password, and reset password. Moving `users` into tenant databases without a strategy for those flows will break registration/account recovery paths immediately after the gate lands.

I would not start T6 Phase 0 implementation from v5 as-is. The necessary v6 edit is much smaller than earlier rounds, but it must happen before code starts because it changes migration classification, route/middleware wiring, and user-facing auth contracts.

## Round-4 finding resolution check (evidence-based)

| Round-4 finding | Status | Evidence |
|---|---|---|
| Codex B-1 / Claude Section 9 Stancl semantics | PARTIALLY FIXED | v5 correctly states Stancl defaults: topology `2026-05-24-migration-topology-contract.md:237-247`; vendor confirms `InitializeTenancyByRequestData::$header = 'X-Tenant'` and query `tenant` at `apps/api/vendor/stancl/tenancy/src/Middleware/InitializeTenancyByRequestData.php:15-20`, and PK lookup at `apps/api/vendor/stancl/tenancy/src/Resolvers/RequestDataTenantResolver.php:21-29`. Still underspecified: the middleware constructor is typed to `RequestDataTenantResolver` at `InitializeTenancyByRequestData.php:30`, so "register SlugTenantResolver as Stancl's tenant resolver" is not enough unless it subclasses/binds that type or replaces the middleware. |
| Codex P1-1 / Claude P1-3 `tenants.database_name` | FIXED | Topology now says database name is computed by `Tenant::getDatabaseName()` and not a column at `migration-topology-contract.md:226,263-270`. Code confirms no column in `2025_11_30_000001_create_tenants_table.php:16-35`; `Tenant::getDatabaseName()` returns `tenant_{$slug}` at `apps/api/app/Modules/Tenant/Domain/Tenant.php:248-250`. |
| Codex P1-2 / Claude B-2 domains rows for subdomains | FIXED | Topology says `InitializeTenancyByDomain` resolves via `domains` and signup must create a full-domain row at `migration-topology-contract.md:220-225,249-253`. T6 work item 8 repeats this at `t6-tenant-provisioning.md:85-89`. Current code only does this in CLI `CreateTenantCommand.php:86-94`, so the spec now correctly owns the gap. |
| Codex P1-3 reference-data phase contradiction | PARTIALLY FIXED | Topology locks seeding to Phase 0 at `migration-topology-contract.md:200-214`, and T6 says the same at `t6-tenant-provisioning.md:125-127`. But the implementation subsection still says "Phase 1A extends `TenantInitializationService`" at `t6-tenant-provisioning.md:133`, inside the Phase 1 OPS section starting at `:121`. |
| Codex P2-1 / Claude P1-2 POS log payments references | FIXED | POS log now says T2 touches only `pos_receipt_lines` and `pos_receipt_line_batch_allocations`, explicitly NOT `payments`, at `pos-coordination-log.md:5,9`. |
| Codex P2-2 tenant-existence failure mode leak | NOT FIXED | Topology still prescribes unknown `tenant_id` as 404 "tenant not found" and bad credentials as 401 at `migration-topology-contract.md:272-276`, preserving tenant slug enumeration. |
| Claude B-1 AuthController login/register underspecified | PARTIALLY FIXED | T6 now adds login/register rewrite and Tauri/frontend work at `t6-tenant-provisioning.md:85-89`. It still misses other pre-auth identity flows; see B-1 below. |
| Claude B-3 effort inconsistency | PARTIALLY FIXED | Main Phase 0 references now say 10 PD: roadmap `:39,:97,:227`, T6 `:53-57,:293`. Stale totals remain: roadmap total says `~61 PD` at `:106` though the table sums to 68 PD; T6 says Phase 0 10 + Phase 1+ 4 = 12 at `t6-tenant-provisioning.md:6`, while Phase 1 OPS is `~7 PD` at `:121` and `:295-299`. |
| Claude P1-1 duplicate `## 9` heading | FIXED | Topology now has tenant identification as `## 9`, Enforcement as `## 10`, References as `## 11` at `migration-topology-contract.md:216,297,307`. |
| Claude P1-4 `super_admins` conditional | PARTIALLY FIXED | Topology correctly says verified exists at `migration-topology-contract.md:270`, but stale text remains at `:34-35` saying `super_admins (if exists)` and "to be created in Phase 0 if not already present". Code confirms table exists at `apps/api/database/migrations/2025_12_01_194614_create_super_admins_table.php:14-28`. |
| Claude P2-1 `subscriptions` vs `tenant_subscriptions` | FIXED | Topology line 17 now lists `tenant_subscriptions`; line 33 cites the existing migration. |
| Claude P2-2 T1 per-company setting wording | PARTIALLY FIXED | Main T1 storage/service/UI wording is per-company at `t1-stock-transfer.md:103,118-124,166,182`; POS log is per-COMPANY at `pos-coordination-log.md:43-44`. Acceptance still says "tenant's `InTransitAvailability`" at `t1-stock-transfer.md:200-201`. |
| Claude P2-3 T11 stale POS log path | FIXED | T11 now references `2026-05-24-pos-coordination-log.md` at `t11-b2b-b2c-separation.md:151,212,231`. |
| Claude P2-4 T4 `Document.php` additional costs citation | FIXED | T4 cites `DocumentAdditionalCost.php` at `t4-order-routing.md:33`, and no longer claims `additional_costs` is on `Document`. |

## New v5 issues

### BLOCKER

- [B-1] T6 Phase 0 misses pre-auth identity flows that assume a global `users` table
  - **Where:** `docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:85-89`; `docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:35,37`; `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:422-427,568-604`; `apps/api/app/Modules/Identity/Application/Services/EmailVerificationService.php:39-60`
  - **Claim under review:** v5 says Phase 0 rewrites `AuthController::login` and `AuthController::register` for multi-tenant users.
  - **What I found:** The code has more pre-auth identity paths than login/register. `/auth/check-email` is public and explicitly "queries `User::where(email)` globally" at `AuthController.php:422-427`; the React registration step calls it at `apps/web/src/features/auth/components/AccountStep.tsx:23-26`. `/auth/verify-email`, `/auth/forgot-password`, and `/auth/reset-password` are also public routes at `apps/api/app/Modules/Identity/routes.php:21-40`. Email verification looks up `EmailVerificationToken::where('token')` and then `$verificationToken->user` at `EmailVerificationService.php:41-60`; that token table FKs to `users` at `2025_12_21_125019_create_email_verification_tokens_table.php:16-26`. Password reset uses Laravel's `Password` broker at `AuthController.php:568-604`; `config/auth.php:73-77,103-107` points the broker at the `User` provider and central `password_reset_tokens`. Topology says `users` moves tenant-side at `migration-topology-contract.md:35`, while `password_reset_tokens` stays central infra at `:37`.
  - **Why it matters:** After Phase 0, there is no global tenant-agnostic `users` table for these routes to query. Registration UX, email verification, and password reset either query the wrong database, fail before tenancy is initialized, or require a central identity index that v5 never specifies.
  - **Suggested fix:** Expand T6 Phase 0 work item 8 from "login/register" to "all Identity pre-auth flows." Decide one explicit architecture: require tenant slug/domain for check-email and password reset, or add a central identity/account index with tenant_id + email, or remove global email availability and make reset links tenant-qualified. Classify `email_verification_tokens` and `password_reset_tokens` accordingly, update routes/UI, and add PG integration tests for registration, verify-email, forgot/reset password after the Stancl flip.

### P1

- [P1-1] Option A SlugTenantResolver is viable but the spec gives the wrong integration shape
  - **Where:** `migration-topology-contract.md:243-247,287-293`; `apps/api/vendor/stancl/tenancy/src/Middleware/InitializeTenancyByRequestData.php:15-30`; `apps/api/bootstrap/app.php:41-67`; `apps/api/app/Modules/Identity/routes.php:21-43`
  - **Claim under review:** v5 says to write `SlugTenantResolver`, register it as Stancl's tenant resolver for request-data middleware, and configure `X-Tenant-ID`.
  - **What I found:** Stancl's request-data middleware does not accept an arbitrary `TenantResolver` in config; its constructor is explicitly `RequestDataTenantResolver $resolver` at vendor line 30. Option A is implementable, but only if the custom resolver extends `RequestDataTenantResolver` and is container-bound to that type, or if the app defines a custom middleware that injects the slug resolver. Also, topology says to wire middleware in `app/Http/Kernel.php` at `migration-topology-contract.md:293`, but this Laravel app has middleware configured in `apps/api/bootstrap/app.php:41-67`; no `apps/api/app/Http/Kernel.php` exists. Identity auth routes are under a `web` route group at `Identity/routes.php:21-43`, so appending only to the `api` group would miss `/api/v1/auth/*`.
  - **Why it matters:** A literal implementation can produce a resolver that is never used, or middleware attached to the wrong route group. That breaks POS subsequent requests and web auth after the tenant DB flip.
  - **Suggested fix:** Specify the exact implementation: either `SlugTenantResolver extends RequestDataTenantResolver` plus Laravel container binding, or `InitializeTenancyBySlugRequestData` custom middleware. Set `InitializeTenancyByRequestData::$header = 'X-Tenant-ID'` or equivalent in a service provider, wire aliases/groups in `bootstrap/app.php`, and explicitly cover `Identity/routes.php` public/protected auth routes.

- [P1-2] Reference-data seeding is still internally split between Phase 0 and Phase 1A
  - **Where:** `t6-tenant-provisioning.md:121-138`; `migration-topology-contract.md:200-214`; `apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:205-214`
  - **Claim under review:** v5 says the round-3 reference-data contradiction is resolved and locked to Phase 0.
  - **What I found:** T6 says "this work LIVES IN Phase 0" at `:125-127`, then immediately says "Phase 1A extends `TenantInitializationService`" at `:133`. The details sit under `## 4. Phase 1 OPS`, not under Phase 0 deliverables/acceptance. The current service returns early if `countries` is empty at `TenantInitializationService.php:207-214`.
  - **Why it matters:** If implementers defer the `countries` / `country_tax_rates` seeding work to Phase 1A, newly claimed tenant DBs can skip tax configuration immediately after the Phase 0 flip.
  - **Suggested fix:** Move the whole reference-data subsection into Phase 0 deliverables and acceptance criteria. Replace "Phase 1A extends" with "Phase 0 extends" everywhere.

- [P1-3] Roadmap says parallel tracks may start now but only T2 gets the no-migration caveat
  - **Where:** `productization-sprint-roadmap.md:51,226-234`; `t1-stock-transfer.md:55`; `t4-order-routing.md:38`
  - **Claim under review:** Tier A says "Start RIGHT NOW, parallel sessions safe."
  - **What I found:** The roadmap also says no other track writes a migration until Phase 0 merges at `:51`. Tier A repeats the migration caveat only for T2 at `:228`, but T1 and T4 also declare tenant migrations (`t1-stock-transfer.md:55`, `t4-order-routing.md:38`) and are listed as start-right-now at roadmap `:230,:233`.
  - **Why it matters:** Teams can read Tier A as permission to create T1/T4 migrations before the gate, recreating the migration-topology collision v5 is supposed to prevent.
  - **Suggested fix:** Change Tier A to "non-migration work only until Phase 0 merges" for every migration-writing track, or add the same branch-dev caveat to T1/T4/T6 ops as appropriate.

### P2

- [P2-1] T6 effort arithmetic is still inconsistent outside the headline Phase 0 number
  - **Where:** `t6-tenant-provisioning.md:6,121,295-299`; `productization-sprint-roadmap.md:95-106`
  - **What I found:** T6 line 6 says Phase 0 `~10 PD` + Phase 1+ `~4 PD` = `~12 PD total`; the arithmetic is wrong, and the later Phase 1+ breakdown is `~7 PD` at `:121,:295-299`. The roadmap table sums to 68 PD, but `productization-sprint-roadmap.md:106` still says `~61 PD`.
  - **Suggested fix:** Set T6 total to `~17 PD` if Phase 1+ remains 7 PD, and update sprint total to match the table.

- [P2-2] `super_admins` is still contradictory in the topology table
  - **Where:** `migration-topology-contract.md:34-35,270`
  - **What I found:** The table still says `super_admins (if exists)` and "to be created in Phase 0 if not already present"; later Section 9 correctly says verified exists. Code confirms the migration exists at `apps/api/database/migrations/2025_12_01_194614_create_super_admins_table.php:14-28`.
  - **Suggested fix:** Remove "(if exists)" and remove the "create if not present" clause from line 35.

- [P2-3] Reference-data path citations are still wrong
  - **Where:** `migration-topology-contract.md:209-211`
  - **What I found:** The contract says `countries` is `2025_11_30_*_create_countries_table.php` and seed via `CountrySeeder`; actual files are `apps/api/database/migrations/2025_12_01_192409_create_countries_table.php` and `apps/api/database/seeders/CountriesSeeder.php:10`. It also says `country_payment_settings` is `2025_12_02_*`; actual migration is `apps/api/database/migrations/2025_12_10_100000_create_country_payment_settings_table.php`.
  - **Suggested fix:** Correct these filenames and the seeder class name.

- [P2-4] T1 still has one stale per-tenant wording for a per-company setting
  - **Where:** `t1-stock-transfer.md:200-201`
  - **What I found:** Main design is per-company, but acceptance still says "When tenant's `InTransitAvailability=Available/NotAvailable`."
  - **Suggested fix:** Change both to "When the source company's `InTransitAvailability`..."

- [P2-5] Tenant login failure modes still enumerate valid tenant slugs
  - **Where:** `migration-topology-contract.md:272-276`
  - **What I found:** Unknown tenant returns 404 "tenant not found"; bad credentials for valid tenant return 401 "invalid credentials."
  - **Suggested fix:** If slugs are not intended to be public, return the same 401 body for unknown tenant and bad credentials. If slugs are public via subdomain, document that this is accepted.

### SUGGESTION

- [S-1] Document version labels are stale
  - **Where:** `t6-tenant-provisioning.md:4`; `t1-stock-transfer.md:4`; `productization-sprint-roadmap.md:93`; `pos-coordination-log.md:1`
  - **What I found:** Several v5 documents still say v2/v3 in headings or date lines.
  - **Suggested fix:** Either remove version tags from titles or bump them consistently.

- [S-2] Roadmap still uses the old "Tauri deltas log" phrase once
  - **Where:** `productization-sprint-roadmap.md:68`
  - **What I found:** The file is now the POS coordination log; line 68 still says "Tauri deltas log."
  - **Suggested fix:** Rename that phrase to "POS coordination log."

## Stancl middleware reality check

v5 got the Stancl defaults right. In installed `stancl/tenancy` v3.10.0, `InitializeTenancyByRequestData` defaults to header `X-Tenant` and query parameter `tenant` at `apps/api/vendor/stancl/tenancy/src/Middleware/InitializeTenancyByRequestData.php:15-20`; `RequestDataTenantResolver` resolves with `tenancy()->find($payload)` at `apps/api/vendor/stancl/tenancy/src/Resolvers/RequestDataTenantResolver.php:21-29`, which is primary-key lookup, not slug lookup.

Option A is implementable, but not as casually as the spec phrases it. The shipped request-data middleware constructor requires `RequestDataTenantResolver` at `InitializeTenancyByRequestData.php:30`. A slug resolver therefore needs to subclass that resolver and be bound into the container for that type, or the app needs a custom middleware class that injects a slug resolver. Middleware wiring also belongs in `bootstrap/app.php`, not `app/Http/Kernel.php`, in this Laravel app.

## AuthController rewrite reality check

v5 correctly captures the core login/register breakage. Current `LoginRequest` validates only email/password/device/platform fields at `apps/api/app/Modules/Identity/Presentation/Requests/LoginRequest.php:25-35`; current login queries `User::where('email')` before tenant init and uses `Auth::attempt()` at `AuthController.php:171-193`. Current register creates `Tenant`, `User`, `Company`, membership, tenant init, device, and token all in one default-connection transaction at `AuthController.php:264-389`, and does not create a `domains` row.

The rewrite scope is still incomplete. `AuthController::checkEmail`, `verifyEmail`, `forgotPassword`, and `resetPassword` are public pre-auth routes at `Identity/routes.php:21-40`, and their implementation assumes a tenant-agnostic user/token store. Phase 0 must explicitly own those paths before `users` moves tenant-side.

## Effort reality check

v5 says T6 Phase 0 is 10 PD. My independent estimate is 12-15 PD if the auth preflight gap is fixed properly.

The original 10 PD number is plausible for migrations + FK rewrites + central connection + PG tests + fiscal handoff + login/register. It becomes low once you include all pre-auth identity flows, middleware/router surgery in Laravel 12's `bootstrap/app.php`, tenant-qualified password reset/email verification, UI changes for tenant slug on POS login and possibly web auth, and integration tests across fresh tenant signup, verify-email, login, token-authenticated requests, forgot/reset password, and subdomain/domain resolution. If the team narrows scope by removing global email availability and deferring password reset/verification with an explicit human decision, 10-12 PD is possible; otherwise 12-15 PD is the realistic range.

## Genericity audit (quick)

- Client names appear in planning/commercial context (`Nénupharma` in roadmap and T3/POS deferred-adapter rationale), but I did not find them in production-code-relevant requirements that instruct hardcoding.
- Tunisian geography appears in examples and existing named seeders. T1's tax-ID design is generic; POS log T1-D1 references Tunisia as a motivating jurisdiction, not a hardcoded implementation rule.
- Adapter strings (`WooCommerce`, `Shopify`, `PrestaShop`, `Paradeals`) are present in T3 as deferred examples and generic adapter-port tests. T3 explicitly says no concrete adapter or `automattic/woocommerce` dependency in production code at `t3-sync-hub.md:26-27,212,282,289-290`.

## What's solid in v5

1. The topology contract's central/tenant classification is now materially stronger, especially `users` tenant-side and Spatie tenant-side.
2. T2's partial unique index strategy is fixed: the null-variant key keeps `(tenant_id, product_id, location_id)` and does not add nullable `company_id` at `t2-variants.md:80-86`.
3. POS coordination is much cleaner: T1-S1 and T2-S1 are explicit backend-POS handshake items, and `payments` ownership stays with fiscal Phase 1.
4. Domain-based web tenancy is correctly grounded in Stancl's `domains` table and full host lookup.
5. T1's batch-preservation correction is aligned with actual code: `stock_movements` has no `batch_id`, and linkage belongs in `inventory_batch_movements`.

## Ready-to-start verdict

T6 Phase 0 should not start right now from v5. v6 should fix these before implementation:

1. Add a complete Identity pre-auth strategy for check-email, email verification, forgot/reset password, and token table classification.
2. Specify the exact SlugTenantResolver/middleware integration shape and wire it through `bootstrap/app.php` plus the actual Identity route groups.
3. Move reference-data seeding details fully into Phase 0 and remove the Phase 1A contradiction.
4. Fix the remaining effort arithmetic and roadmap migration parallelism wording.
5. Clean the stale `super_admins` and reference-data citation errors.
