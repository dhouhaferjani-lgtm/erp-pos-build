# Sprint Planning Round-4 Adversarial Review — Claude general-purpose
**Date:** 2026-05-24
**Reviewer:** Claude general-purpose (Codex headless failed twice on the superpowers skill ritual)

## Verdict
NEEDS-REVISION

Weakest link: the new Section 9 ("Tenant identification architecture") in the topology contract, plus a still-incomplete sweep of round-3 nits. The high-level direction in v4 is sound and the core constitutional rules are now genuinely implementable, but Section 9 adds an under-specified surface (subdomain → Stancl resolution, AuthController rewrite, super_admins gap, central-DB `domains` rows) and the round-3 sweep missed three documented findings (S-1, S-2, half of P1-1, half of P2-1, all of P2-2, all of P2-4). Effort is also still inconsistent across docs.

## Summary

v4 lands several real wins. Round-3 B-1 (syntax-independent FK rewrite) is genuinely fixed — the spec and contract now name BOTH `constrained('tenants')` and `references('id')->on('tenants')`, and Phase 0 acceptance now requires the second grep. Round-3 B-2 (shared reference-data classification) is resolved: the contract's new Section 8 picks per-tenant seeding and names the four affected tables. Round-3 B-3 (effort) is partially resolved by raising T6 spec header to 8 PD and matching it in roadmap Tier A, but two stale citations remain (T6 spec §10 "~3 PD" and roadmap table column "~3 PD" and roadmap top diagram "~2-3 days") so the inconsistency persists. Round-3 P1-2 (InTransitAvailability scope) is locked in the public service signatures + REST endpoints + storage decision, but Wave 2 POS delta wording and one acceptance test still say "tenant setting" — needs a global rename.

Section 9 of the topology contract is the new BLOCKER surface. It correctly cites real Stancl middleware classes (verified present in `vendor/stancl/tenancy/src/Middleware/`), correctly cites `Tenant::getDatabaseName()` returning `tenant_{slug}` (verified at line 250), and correctly notes the `super_admins` table — except it says "(if exists)" while a migration for it has existed since 2025-12-01. But Section 9 silently introduces three implementation cliffs that Phase 0 cannot survive: (a) Stancl's `InitializeTenancyByDomain` resolves through the `domains` table, not via `tenants.slug` directly — so subdomain-based resolution needs a `domains` row per tenant, and registration today does NOT create one (only `php artisan tenant:create` does, per `CreateTenantCommand:89`); (b) current `AuthController::login` uses `Auth::attempt()` and `User::where('email', ...)` against the default connection, which BREAKS once `users` lives in tenant DBs — that's a multi-day refactor not in Phase 0 scope; (c) the proposed `tenant_id + email + password` Tauri login flow is incompatible with the current single-field POS LoginPage and authStore.login signature, but no work item lists the Tauri-side change.

There's also a structural defect in the topology contract: there are TWO `## 9.` headings (lines 216 and 277). One must be renumbered.

Smaller round-3 misses worth fixing in v5: T11 line 151 still cites `2026-05-24-tauri-pos-deltas.md`, POS coordination log line 5 and 9 still list `payments` as a T2-S1 column, T4 line 33 still cites `Document.php` for `additional_costs`, POS coordination log line 30 says "no entry below applies to Wave 1" while Wave 1 entries exist at lines 7-9, and topology line 17 lists `subscriptions` while line 33 calls it `tenant_subscriptions`.

## Round-3 finding resolution check (detailed, evidence-based)

### BLOCKER

**B-1 — Phase 0 still misses cross-DB FKs not written as `->constrained('tenants')`: FIXED**
- T6 §3 deliverable 3 at `docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:70-73` explicitly enumerates BOTH grep patterns and gives the combined ~50 file count.
- Topology contract Pattern A at `docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:134-139` matches.
- Verified independently: `grep -rln "constrained('tenants')..."` returns 39 files; `grep -rln "references('id')->on('tenants')..."` returns 15 files; combined unique = 54 (close to the spec's ~50 estimate). `users` (line 33), `companies` (line 104), `product_images` (line 33) are all in the second grep set as the contract notes.

**B-2 — Topology parks shared reference-data classification: FIXED**
- Topology contract §8 at lines 200-214 RESOLVES the question: all reference tables move per-tenant; lists `countries`, `country_tax_rates`, `country_payment_settings`, `tax_configurations` with their current migration paths and Phase 0 action.
- Verified all four migration files exist: `2025_12_01_192409_create_countries_table.php`, `2025_12_01_192545_create_country_tax_rates_table.php`, `2025_12_10_100000_create_country_payment_settings_table.php`, `2025_12_30_100000_create_tax_configurations_table.php`.
- Verified `database/seeders/CountriesSeeder.php` exists so the "seed countries FIRST" claim is implementable.

**B-3 — T6 Phase 0 effort silently/inconsistently filled: PARTIALLY FIXED**
- T6 spec header (line 6) and §3 title (line 53) now agree on 8 PD with explicit rationale.
- Roadmap Tier A (line 228) agrees: 8 PD.
- BUT three locations still publish the old number:
  - T6 spec §10 line 284: `Phase 0 (Codex with Opus review, ~3 PD)`
  - Roadmap §"7 specs" table line 97: `~3 PD`
  - Roadmap top diagram line 39: `~2-3 days`
- Implementer who reads the workflow recommendation or the table-of-specs gets the old number.

### P1

**P1-1 — `payments.variant_id` claim removed: PARTIALLY FIXED**
- Roadmap line 130 and line 143 correctly say T2 does NOT touch `payments` and explicitly cites round-3 P1-1.
- T2 spec is clean — no `payments` column ownership claimed.
- BUT POS coordination log STILL has the bad claim in TWO places:
  - Line 5: `T2 migrations on pos_receipt_lines / pos_receipt_line_batch_allocations / payments) also requires fiscal coordination` (in the "File rename" paragraph)
  - Line 9: `T2-S1 ... adding variant_id columns to pos_receipt_lines, pos_receipt_line_batch_allocations, payments via migrations`
- Implementer reading the coordination log (the single source of truth for cross-track collisions per roadmap line 132) gets the wrong column list.

**P1-2 — `InTransitAvailability` per-company vs per-tenant: PARTIALLY FIXED**
- T1 spec §3 line 103: storage is per-COMPANY via `companies.reservation_settings` (verified pattern exists at `Company.php:508-518`).
- T1 spec §4 lines 118-120: service signatures take `UUID $companyId`.
- T1 spec §4 lines 144-145: REST endpoints are `/api/v1/companies/{companyId}/...`.
- T1 spec §5 line 166: Settings UI is per-company.
- BUT some lines still say "tenant setting":
  - Line 171 (Wave 2 POS delta): `based on tenant setting`
  - Line 200 (acceptance test): `When tenant's InTransitAvailability=Available`
  - Line 201 (acceptance test): `When InTransitAvailability=NotAvailable` (this one is fine, no scope qualifier)
- POS coordination log T1-D2 (line 41): `based on tenant setting`
- Not a contradiction in the public contract, but a doc drift that will mislead the Wave 2 implementer + the POS adversarial reviewer.

**P1-3 — `TenantInitializationService` claims overstate seeding: FIXED AS DEFERRED**
- T6 §4 "Expand TenantInitializationService seeding" at lines 120-131 explicitly carves out the seeding gap as Phase 1A work with a concrete extension list (countries FIRST, then country_tax_rates + country_payment_settings, then existing per-country seeders, then `TunisiaStampDutySeeder` + `TunisianParapharmacySeeder` + `TunisiaWithholdingRulesSeeder`).
- Verified `TunisiaStampDutySeeder`, `TunisianParapharmacySeeder`, `TunisiaWithholdingRulesSeeder` exist in `database/seeders/`.
- Verified `CountriesSeeder` exists.
- Phase 0 acceptance is no longer making the false claim; Phase 1A owns the fix. Acceptable.

**P1-4 — phpunit/PG strategy + Spatie not marked as human decisions: PARTIALLY FIXED**
- Spatie is now RESOLVED, not deferred: topology contract line 36 explicitly says "DECISION ... All Spatie permission tables move to tenant DB" with code evidence (`config/permission.php:95-134` teams enabled with `tenant_id`). Good — this matches the code.
- phpunit/PG is still Option A/B for the implementer to pick (T6 §3 deliverable 6 at lines 79-83). No "decision required" marker. The implementer of Phase 0 will pick this, and it's a CI-scope topology choice that should not be silently delegated.
- The autonomous-rework-status doc was not in the review package so I cannot confirm whether it was updated to remove this from the human-decision list.

**P1-5 — Roadmap Wave 1 collision-free contradiction: FIXED**
- Roadmap line 54-58 now reads "Wave 1 — Server-side work, ZERO Tauri POS touch ... Backend POS module changes require fiscal handshake (T1-S1 + T2-S1 in the coordination log)". Diagram and detail section consistent.
- POS coordination log still has a stale "Wave 1 is zero-Tauri-touch by design — no entry below applies to Wave 1" at line 30, see P2-2 below.

### P2

**P2-1 — Topology uses nonexistent `subscriptions` name: PARTIALLY FIXED**
- Line 33 of the contract correctly lists `tenant_subscriptions` with the migration citation and round-3 P2-1 reference.
- BUT line 17 (the rule statement) still says `Tenant tables do not declare FKs to tenants, domains, plans, subscriptions, central users`. The reader sees `subscriptions` here first and thinks it's a real central table name.

**P2-2 — POS coordination log Tauri-delta language: NOT FIXED**
- Line 30 still says: `Wave 1 is zero-Tauri-touch by design — no entry below applies to Wave 1.` This contradicts lines 7-9 which are explicit Wave 1 backend-POS entries.
- Line 42 T1-D3 still ends: `Server-only — not actually a Tauri delta; flagged here for visibility`. The doc was renamed to a unified coordination log; this entry should be a normal coordination item, not a "flagged exception."

**P2-3 — Topology line citation for `users`: FIXED**
- Topology line 35 now says line 18 declares `tenant_id` and line 39 declares the unique. Verified: line 18 has `$table->uuid('tenant_id');` and line 39 has `$table->unique(['tenant_id', 'email']);`. Correct.

**P2-4 — T4 cites `Document.php` for `additional_costs`: NOT FIXED**
- T4 spec at `docs/superpowers/specs/2026-05-24-t4-order-routing.md:33` still says: `apps/erp/apps/api/app/Modules/Document/Domain/Document.php — additional_costs includes transport/shipping/insurance/customs/handling/other`. The actual model lives at `Document/Domain/DocumentAdditionalCost.php`. Identical wording to v3.

### SUGGESTION

**S-1 — T11 stale `2026-05-24-tauri-pos-deltas.md` basename: NOT FIXED**
- T11 spec line 151 still references `2026-05-24-tauri-pos-deltas.md`. File renamed to `2026-05-24-pos-coordination-log.md` per topology v3 (roadmap line 132 acknowledges the rename).

**S-2 — POS coordination log broken relative link to fiscal plan: NOT FIXED**
- Line 3 still has `../superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md`. From `docs/superpowers/coordination/`, this resolves to `docs/superpowers/superpowers/plans/...` (extra `superpowers`). Correct relative path is `../plans/2026-05-14-pos-phase1-fiscal-event-engine.md`.

## New v4 surface — Tenant Identification Architecture (Section 9 of topology contract)

### Stancl middleware accuracy: ACCURATE BUT INCOMPLETE
- `Stancl\Tenancy\Middleware\InitializeTenancyByDomain` exists at `apps/api/vendor/stancl/tenancy/src/Middleware/InitializeTenancyByDomain.php`. Confirmed.
- `Stancl\Tenancy\Middleware\InitializeTenancyByRequestData` exists at the same directory. Confirmed.
- `Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain` exists too. Confirmed.
- Stancl version is `v3.10.0` per `composer.lock`. The contract doesn't pin a version; the cited classes match this version.
- INCOMPLETE: `InitializeTenancyByDomain` resolves the tenant by querying the `domains` table for a row whose `domain` column matches the request host (verified in `DomainTenantResolver.php` lines 32-43). It does NOT resolve via `tenants.slug` directly. So subdomain-based resolution for `{slug}.synerivia.tn` requires a `domains` row with `domain = "{slug}.synerivia.tn"` for every tenant. The `domains` table exists (`2025_11_30_000002_create_domains_table.php`) but `AuthController::register()` does NOT create a row in it — verified by grep; only `CreateTenantCommand:89` creates one (and only when `--domain=...` is passed). Section 9 calls this out only implicitly ("Stancl's InitializeTenancyByDomain middleware resolves tenant from subdomain") and provides no Phase 0 or Phase 1 work item for "create a domain row at tenant registration."

### Central `tenants` table fields claimed: PARTIALLY ACCURATE
- `slug` (unique, indexed): EXISTS at `2025_11_30_000001_create_tenants_table.php:19, 31`. Correct.
- `database_name` (default `tenant_{slug}`): NO SUCH COLUMN. The naming is computed by `Tenant::getDatabaseName(): return 'tenant_'.$this->slug;` at `Tenant.php:250`. Section 9's table at line 247 lists `database_name (default tenant_{slug} per existing convention)` as if it were a stored field — it's a method return value with no persisted column. This will confuse anyone wiring `DatabaseConfig` against the `tenants` table.
- `status`: EXISTS (string default 'pending') at migration line 20. T6 spec says ADD `PreProvisioned` case to the enum — consistent.
- `subscription_state`: NOT a column on `tenants`. Section 9 says "via tenant_subscriptions table" — accurate but the wording reads as if it's a tenant field.
- `created_at`, `updated_at`: EXIST. Correct.

### Tauri login flow vs current code: NOT REALISTIC AS WRITTEN
- Current `PosLoginPage.tsx` (line 17-22) calls `useAuthStore().login(email, password, opts)`. Two fields plus an AbortSignal. There is no `tenant_id` input field.
- Current `authStore.login` (per type signature at line 51-55) takes `email`, `password`, `opts`. There is no `tenantId` parameter.
- Current `AuthController::login()` (lines 171-240) calls `User::where('email', $validated['email'])->first()` directly against the default DB connection and uses `Auth::attempt(['email' => ..., 'password' => ...])`. Both queries assume a single shared `users` table. Once `users` lives per-tenant DB, this code path FAILS until rewritten:
  1. Login must first resolve `tenant_id` from request body → bind tenancy via `tenancy()->initialize($tenant)` → then query `users` and `Auth::attempt`.
  2. The `LoginRequest` (lines 25-35) currently does not accept `tenant_id`; it must be added as a required field.
  3. `Auth::attempt` against a tenant-scoped guard requires careful interaction with Sanctum sessions; Section 9 hand-waves "validate email/password against users in that tenant's DB" but does not call out the Sanctum + tenant-aware guard surface.
- The proposed `X-Tenant-ID` header for subsequent requests is reasonable, but Section 9 doesn't note that `tenancy/v3` ships `InitializeTenancyByRequestData` with a configurable header name (defaults to `X-Tenant`) — implementer needs to know the config knob.
- Bottom line: Section 9's Tauri flow is the right shape, but the work to get from current state to that flow is multi-PD across server + client + tests + Sanctum config + middleware wiring, and zero of that effort is itemised in Phase 0 or Phase 1.

### `super_admins` table existence: TABLE EXISTS — SECTION 9 IS WRONG
- Topology line 34 says: `super_admins (if exists)` with note "(separate super_admins table to be created in Phase 0 if not already present)" at line 35.
- Verified: `apps/api/database/migrations/2025_12_01_194614_create_super_admins_table.php` exists with `id`, `name`, `email`, `password`, `role`, `is_active`, `last_login_at`, `last_login_ip`, `notes`, `timestamps`.
- v4 should drop the conditional and just say "central — already exists per migration X". No Phase 0 creation needed. (There IS still work to migrate any super-admin auth flow off the shared `users` table, but that's a different work item.)

### Duplicate `## 9.` heading
- The topology contract has TWO sections numbered `## 9.`:
  - Line 216: `## 9. Tenant identification architecture (NEW per user direction)`
  - Line 277: `## 9. Enforcement`
- Renumber the second to `## 10. Enforcement` (and References to `## 11.`).

## Findings introduced in round 4

### BLOCKER

**[B-1] Section 9 underspecifies the AuthController/login refactor required by DB-per-tenant**
- Where: `docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:228-235`, `:252-256`
- Claim under review: "API resolves tenant_id against central tenants.slug → bind tenant context → validate email/password against users in that tenant's DB"
- What I found: current `AuthController::login()` (lines 171-240) uses `User::where('email', $validated['email'])->first()` against the default connection and `Auth::attempt(['email' => ..., 'password' => ...])`. Both calls implicitly target the central/default DB. The `users` table is being moved to tenant DBs per topology line 35. Without a Phase 0 or Phase 1 work item to rewrite the login flow (resolve tenant by slug from `tenants` table → `tenancy()->initialize($tenant)` → THEN query `users`), the moment Phase 0 merges every login attempt will 500 or return a misleading "credentials incorrect" because `users` is empty in the default DB.
- Why it matters: this is the global gate path. Login broken = whole product broken.
- Suggested fix: add an explicit Phase 0 (or Phase 1A) work item: "rewrite `AuthController::login` and `AuthController::register` to be tenant-aware. Login takes `tenant_id` field; register creates the tenant + initialises tenancy context + creates the User inside the tenant DB." Estimate at least 2 PD including tests.

**[B-2] Section 9 misses the `domains` row that subdomain resolution requires**
- Where: `docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:222-225`
- Claim: "Stancl's `InitializeTenancyByDomain` middleware resolves tenant from subdomain"
- What I found: Stancl's `InitializeTenancyByDomain` calls `DomainTenantResolver` which queries the `domains` table (`vendor/stancl/tenancy/src/Resolvers/DomainTenantResolver.php:32-43`). It does NOT resolve via `tenants.slug` directly. Current `AuthController::register()` does NOT create a `domains` row (grep shows only `CreateTenantCommand:89` creates one, and only when an explicit `--domain` option is passed).
- Why it matters: subdomain-based web ERP login will return "tenant not found" 404 from Stancl even though `tenants.slug` exists, because no matching `domains.domain` row exists.
- Suggested fix: add to Phase 0 or Phase 1: "Tenant registration creates `domains` row with `domain = {slug}.{APP_DOMAIN env}`. Configure APP_DOMAIN (e.g. `synerivia.tn`). Add a backfill migration for existing tenants. Add an integration test that resolves a subdomain through Stancl."

**[B-3] T6 Phase 0 effort is still inconsistent across docs**
- Where: T6 spec header (line 6, 8 PD), §3 title (line 53, 8 PD), §10 workflow (line 284, ~3 PD); roadmap top diagram (line 39, ~2-3 days), table column (line 97, ~3 PD), Tier A (line 228, 8 PD)
- Claim: 8 PD per round-3 reality check.
- What I found: three locations have the old ~3 PD / ~2-3 days numbers. v3 had the same kind of inconsistency for which round-3 raised B-3; only partially swept.
- Suggested fix: global replace in roadmap and T6 spec so all six locations agree on 8 PD. Drop the ~2-3 days phrasing entirely; use PD.

### P1

**[P1-1] Duplicate `## 9.` heading in topology contract**
- Where: lines 216 and 277 of `docs/superpowers/coordination/2026-05-24-migration-topology-contract.md`
- Why it matters: markdown TOC + anchor links break; readers searching for §9 may land on the wrong section. The two-section confusion makes future references like "see §9" ambiguous.
- Suggested fix: renumber the second `## 9. Enforcement` → `## 10. Enforcement`; renumber `## 10. References` → `## 11. References`.

**[P1-2] POS coordination log still references `payments` as a T2-S1 column in two places**
- Where: lines 5 and 9 of `docs/superpowers/coordination/2026-05-24-pos-coordination-log.md`
- Roadmap was correctly swept for this (lines 130, 143). The single source-of-truth coordination log was not.
- Why it matters: implementers consulting the log directly will add `payments.variant_id`, a nonsense column that overlaps with fiscal Task 12's ownership of `payments.origin` + `payments.fiscal_event_id`.
- Suggested fix: remove `payments` from both lines; explicitly reference round-3 P1-1.

**[P1-3] Section 9's claim about `database_name` field is misleading**
- Where: `docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:247` (`database_name (default tenant_{slug} per existing convention)`)
- What I found: there is no `database_name` COLUMN in `tenants` migration. The name is computed by `Tenant::getDatabaseName()` at line 250 of the Tenant model.
- Why it matters: Phase 0 implementer wiring `DatabaseConfig` to look up DB name will look for the column and not find it.
- Suggested fix: rewrite the line as "database name derived from `slug` via `Tenant::getDatabaseName()`; no column persisted. If we want a stored override (e.g., for legacy tenants), add a nullable `database_name` column."

**[P1-4] `super_admins` table existence wrongly conditional**
- Where: topology contract line 34 (`super_admins (if exists)`) and line 35 (`if not already present`)
- What I found: table migration `2025_12_01_194614_create_super_admins_table.php` already exists.
- Why it matters: Phase 0 implementer reads "create if not present" and may create a duplicate migration with a conflicting timestamp or column set.
- Suggested fix: drop conditional, cite the existing migration, and add a Phase 1A work item to migrate any auth flow that currently uses `users` for super-admin login (none found in this review pass, but worth confirming) to use `super_admins`.

**[P1-5] T1 spec + POS coordination log still call InTransitAvailability a "tenant setting" in Wave-2-facing copy**
- Where: T1 spec lines 171, 200; POS coordination log line 41
- The public service signatures, REST endpoints, settings UI, storage decision, and acceptance test (line 182) are all per-COMPANY. The drift is in Wave 2 POS delta language + one acceptance test.
- Why it matters: when the POS adversarial reviewer reads "based on tenant setting" they will flag a contradiction; when the Wave 2 implementer wires the POS render they may pass `tenantId` instead of `companyId` and silently break the per-company semantics.
- Suggested fix: global rename in T1 spec lines 171, 200 and POS coordination log line 41 from "tenant setting" → "company setting" (or "each company's setting").

### P2

**[P2-1] Topology rule statement at line 17 still says `subscriptions`**
- Where: line 17 of `docs/superpowers/coordination/2026-05-24-migration-topology-contract.md`
- Inconsistent with line 33 which correctly uses `tenant_subscriptions`.
- Suggested fix: replace `subscriptions` → `tenant_subscriptions` at line 17.

**[P2-2] T11 line 151 still references the old filename**
- Where: `docs/superpowers/specs/2026-05-24-t11-b2b-b2c-separation.md:151`
- The file is renamed to `2026-05-24-pos-coordination-log.md` per roadmap line 132.
- Suggested fix: update the basename.

**[P2-3] POS coordination log claims "no entry below applies to Wave 1" then has Wave 1 entries**
- Where: `docs/superpowers/coordination/2026-05-24-pos-coordination-log.md:30` (contradicted by lines 7-9)
- Suggested fix: rewrite line 30 to "Wave 1 entries are in the section above; the per-track delta tables below are Wave 2 unless otherwise marked." Also drop the T1-D3 "Server-only — not actually a Tauri delta" tag at line 42 — the log is unified now.

**[P2-4] T4 still cites `Document.php` for `additional_costs`**
- Where: `docs/superpowers/specs/2026-05-24-t4-order-routing.md:33`
- Same finding as round-3 P2-4. Not swept.
- Suggested fix: cite `apps/api/app/Modules/Document/Domain/DocumentAdditionalCost.php` and the actual table that stores those rows.

**[P2-5] POS coordination log broken relative link to fiscal plan**
- Where: `docs/superpowers/coordination/2026-05-24-pos-coordination-log.md:3` (`../superpowers/plans/2026-05-14-...`)
- Same as round-3 S-2; not swept. Should be `../plans/2026-05-14-...`.

### SUGGESTION

**[S-1] Phase 0 acceptance grep could be tightened**
- The Phase 0 acceptance at T6 spec line 110 says: "Grep all tenant migrations for `->constrained('tenants')`, `->constrained('plans')`, `->constrained('domains')` — should return zero hits". This is necessary but not sufficient — it still misses the `references('id')->on(...)` syntax. Add the second pattern to the acceptance grep to match the §3 deliverable language.

**[S-2] Section 9's tenant_id header default**
- Stancl's `InitializeTenancyByRequestData` uses a configurable header — Section 9 picks `X-Tenant-ID` but should explicitly call out the `tenancy.identification.middleware.request_data.header` config key (or wherever Stancl v3.10 keeps it) so the Phase 0 wiring is unambiguous.

**[S-3] Section 9 missing slug-uniqueness validation rule**
- Section 9 claims slug rules (lowercase a-z, 0-9, hyphens, 3-32 chars, globally unique). The current `tenants` migration enforces uniqueness via DB index but not the format constraints. Spec should either add a check constraint or document that format enforcement lives at the application layer (`Str::slug()` is used today per `AuthController:272` — that produces compatible output but allows uppercase + underscores via input).

## Effort reality check

v4 T6 spec says Phase 0 = 8 PD. Round-3 Codex estimated 10-12 PD. My independent estimate given v4's scope: **10-14 PD** before adversarial-review iterations.

Justification for the upward revision: v4 Section 9 silently expands Phase 0 to include tenant-identification middleware wiring (web + Tauri + future mobile), but the explicit Phase 0 work list (§3 deliverables 1-10) doesn't reflect the AuthController rewrite, the `domains` row creation at registration, or the LoginRequest field addition. Each of those is a separate sub-task:

- AuthController login refactor (resolve tenant first, then init tenancy, then `Auth::attempt`): 1.5-2 PD including tests + Sanctum/SPA cookie interaction
- AuthController register refactor (initialize tenancy mid-transaction, create User inside tenant DB, handle tenant init in the right connection context): 1.5-2 PD
- `domains` row creation at registration + APP_DOMAIN env wiring + Stancl middleware wiring in `app/Http/Kernel.php`: 0.5 PD
- Tauri LoginPage refactor (add tenant_id field, refactor authStore.login signature, update LoginPage.test.tsx): 1 PD (frontend-only but cross-cutting)
- POS storage + header propagation in `apps/pos/src/lib/api.ts` for `X-Tenant-ID`: 0.5 PD
- Integration tests for subdomain → web login + tenant_id header → Tauri login + bad tenant 404: 1 PD

That's ~6 PD on TOP of the 8 PD already estimated for the migration moves + FK rewrites + central connection + Spatie + phpunit/PG + flip test + fiscal coordination. If Section 9 is INTENDED to be Phase 0 scope, the budget is 13-14 PD. If Section 9 work is a separate Phase 1 sub-track (e.g., "Phase 1D Tenant Identification"), Phase 0 stays at 8 PD but Phase 1D needs its own explicit budget and gating relationship to other Phase 1 tracks.

The current docs are silent on which option Section 9 represents. That ambiguity is the root cause of the effort guess being off; resolve it and the PD math becomes mechanical.

## Genericity audit (quick)

- `Nénupharma` / `ParaFendri` / `Finderi` / hardcoded client names in NEW v4 code references: NONE in the v4 high-change docs. `Nénupharma` appears as "forcing function" context in the roadmap, which is acceptable.
- Hardcoded Tunisian geography in NEW v4 references: Section 9 uses `nenupharma.synerivia.tn` as the EXAMPLE subdomain (line 222). That's fine as an illustrative example, but consider using a generic `{client-slug}.{your-domain}.tld` form instead to make the doc reusable across deployments.
- Concrete adapter strings: T3 keeps WC/Shopify/PrestaShop/Paradeals in deferred sections, not production. Acceptable.

## What's solid in v4

1. **B-1 syntax-independent FK audit** is now genuinely implementable: both grep patterns named, combined file count documented, ~50 file estimate aligns with my independent count (54). Acceptance test wording is concrete.
2. **B-2 reference-data classification** is RESOLVED with a clean per-tenant decision, a list of the four affected tables, and an explicit ordering rule (seed `countries` FIRST). The clean-slate framing makes this realistic.
3. **Spatie classification** moved from "open question" to "DECISION: tenant DB" with concrete code evidence (`config/permission.php` teams enabled with `tenant_id` team key). Solid call.
4. **T1 storage decision for InTransitAvailability** reuses an existing `companies.reservation_settings` JSONB column. Verified the pattern (`Company.php:508-518`). Avoids a new table that would itself be a cross-DB problem. Clean.
5. **Roadmap tier prioritization** (Tier A / B / C) is a real improvement over the wave-only structure for kickoff readability; the dependency arrows are correct.

## Ready-to-start verdict

T6 Phase 0 CANNOT start RIGHT NOW with v4. A v5 is needed.

Top priorities for v5:

1. **Resolve B-1 (login refactor) AND B-2 (`domains` row) BEFORE Phase 0 starts.** Either fold them into Phase 0 (rebudget to ~13-14 PD) or create an explicit Phase 1D track with a gating relationship documented. Without this, the first tenant login after Phase 0 merges will fail.
2. **Renumber the duplicate `## 9.` heading and fix the `database_name` and `super_admins` factual errors in Section 9.** Five-minute fix; high-payoff.
3. **Sweep the 8 round-3 misses identified above (P1-1 partial, P1-2 partial, P2-1 partial, P2-2, P2-4, S-1, S-2, plus B-3 effort inconsistencies).** These are mostly find-and-replace; doing them in one pass prevents v6 review iteration.
4. **Add explicit "decision required from human" markers for phpunit/PG strategy** (or pick one and document why). The current Option A/B framing delegates a CI-scope topology choice to an implementer mid-Phase 0.
5. **Decide whether `domains` is central or tenant.** Topology line 31 lists `domains` as central. That's correct (Stancl needs it central to resolve which DB to open). But Phase 0 also needs to make sure no tenant-side code FKs to it. Verify and document.

If those five items are addressed in v5, my expectation is APPROVE-WITH-MINOR-EDITS on the next round. The core architecture is sound; the remaining work is doc hygiene + filling the Section 9 implementation gaps.
