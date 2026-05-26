# Sprint Planning Round-3 Adversarial Review — Codex
**Date:** 2026-05-24
**Reviewer:** Codex headless

## Verdict
REJECT

Weakest link: T6 Phase 0 + migration topology contract. v3 fixed several round-2 items, but the core topology gate is still not implementable as written because it misses non-`constrained('tenants')` cross-database FK declarations in tables it now classifies as tenant-scoped.

## Summary

v3 is materially better than v2 in several places. The topology contract now correctly classifies `users` as tenant-scoped, the T2 table-name typo is gone, the T2 nullable-variant partial-index strategy now preserves the old `stock_levels` key shape, the roadmap has a server-side fiscal handshake section, and T11 moved channel override to the first resolver position.

The risk is that the v3 rework is still text-level, not fully code-grounded. The biggest unresolved problem is Phase 0's FK rewrite scope: the spec says to rewrite the 39 `->constrained('tenants')` migrations, but actual tenant-classed migrations also use `foreign(...)->references('id')->on('tenants')`. Those include `users` and `companies`, which v3 explicitly says must move to tenant DBs. If implementation follows v3 literally, the Stancl DB-per-tenant flip will still leave cross-database FKs in tenant migrations.

T6 Phase 0 is also internally inconsistent on effort and unresolved decisions. The same T6 spec says Phase 0 is `~3 PD` and `5–6 PD`; the roadmap still publishes `~2-3 days` / `~3 PD`; the status doc explicitly says effort confirmation is a human decision. I do not think T6 Phase 0 can start right now from these docs without another revision.

## Round-2 BLOCKER resolution check

- **T6/topology B-1 — 39 cross-DB FKs need rewriting: PARTIALLY FIXED**
  - Evidence: v3 added the work item at `docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:70` and repeats it in the topology contract at `docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:134`.
  - Still missing: the work item only targets `->constrained('tenants')`. Actual tenant-classed migrations still declare tenant FKs through the other syntax, for example `users` at `apps/api/database/migrations/2025_11_30_000003_create_users_table.php:33-36` and `companies` at `apps/api/database/migrations/2025_11_30_104000_create_companies_table.php:104-105`. v3 itself classifies `users` and `companies` as tenant-scoped at topology lines 35 and 46.
  - Result: not fully fixed.

- **T6/topology B-2 — `users` misclassified central: FIXED**
  - Evidence: topology contract says `users` is tenant-scoped at `docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:35`; T6 repeats it at `docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:67`.
  - Minor citation defect: topology line 35 cites the wrong migration line numbers. Actual `tenant_id` is at `apps/api/database/migrations/2025_11_30_000003_create_users_table.php:18`; the unique `(tenant_id,email)` is at line 39.

- **T6/topology B-3 — `central` connection missing: FIXED AS SPEC WORK**
  - Evidence: topology contract explicitly says `central` does not exist and must be added at `docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:132`; T6 Phase 0 includes it at `docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:71`.
  - Verified current code: `apps/api/config/database.php:87-103` has `pgsql` only, no `central`.

- **T6/topology B-4 — phpunit pinned to SQLite: PARTIALLY FIXED**
  - Evidence: T6 acknowledges `phpunit.xml` pins SQLite at `docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:76-80`; current code confirms `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:` at `apps/api/phpunit.xml:40-41`.
  - Still missing: the status doc lists this as a human decision at `docs/superpowers/coordination/2026-05-24-autonomous-rework-status.md:130-134`, but v3 does not mark it as `decision required from human`; it gives Option A/B and leaves the implementer to choose.

- **T6/topology B-5 — fiscal Phase 1 migration handover: FIXED**
  - Evidence: T6 Phase 0 requires moving the three committed fiscal migrations and pausing fiscal migration work at `docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:82-85`; roadmap repeats the pause at `docs/superpowers/coordination/2026-05-24-productization-sprint-roadmap.md:144`.
  - Verified current code: `apps/api/database/migrations/2026_05_14_100001_create_fiscal_events_table.php`, `...100002_create_fiscal_events_immutability.php`, and `...100003_create_fiscal_event_projections_table.php` exist in the central migrations directory today.

- **T2 B-1 — wrong table name `receipt_line_batch_allocations`: FIXED**
  - Evidence: `rg -P "(?<!pos_)receipt_line_batch_allocations"` across T2/topology/T6/roadmap/POS log returned no hits. Correct `pos_receipt_line_batch_allocations` appears in T2 at `docs/superpowers/specs/2026-05-24-t2-variants.md:95` and topology at `docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:63`.
  - Verified current code: actual table is created at `apps/api/database/migrations/2026_02_19_000001_create_pos_receipt_line_batch_allocations_table.php:13`.

- **T2 B-2 — nullable `company_id` weakens stock-level partial unique: FIXED**
  - Evidence: T2 now says the null-variant index is `(tenant_id, product_id, location_id)` and explicitly says not to add `company_id` at `docs/superpowers/specs/2026-05-24-t2-variants.md:80-86`.
  - Verified current code: old unique is exactly `(tenant_id, product_id, location_id)` at `apps/api/database/migrations/2025_11_30_110000_create_inventory_tables.php:27`; `company_id` was later added nullable at `apps/api/database/migrations/2025_11_30_131000_add_company_id_to_stock_tables.php:31-35`.

- **Roadmap R2-B1 — Wave 1 backend-POS collision not surfaced: PARTIALLY FIXED**
  - Evidence: roadmap now has a server-side fiscal handshake section at `docs/superpowers/coordination/2026-05-24-productization-sprint-roadmap.md:120-146`; POS log has T1-S1 and T2-S1 at `docs/superpowers/coordination/2026-05-24-pos-coordination-log.md:7-9`.
  - Still missing: roadmap still says Wave 1 has `ZERO POS touch` and `No collision with fiscal Phase 1` at `docs/superpowers/coordination/2026-05-24-productization-sprint-roadmap.md:54-56`, contradicting the later section.

- **Roadmap R2-P1-1 — fiscal session ownership unresolved: FIXED AS DEFERRED DECISION**
  - Evidence: roadmap explicitly flags fiscal ownership as an open question and decision required from Houssam at `docs/superpowers/coordination/2026-05-24-productization-sprint-roadmap.md:146` and again at lines 223-225.

- **Roadmap R2-P1-2 — T6 Phase 0 vs fiscal migration writes: FIXED**
  - Evidence: T6 line 84 requires fiscal to pause new migration work during the Phase 0 PR; roadmap line 144 repeats the same handover.

- **T11 P1-A — channel override unreachable: FIXED**
  - Evidence: T11 Section 4.2 now puts channel-specific override first at `docs/superpowers/specs/2026-05-24-t11-b2b-b2c-separation.md:76-92`. Existing `PricingService::getPrice()` still resolves partner → default → base at `apps/api/app/Modules/Pricing/Domain/Services/PricingService.php:41-77`, and v3 correctly says POS falls through step 1 when `channel_id` is null.

## Findings introduced or surfaced in round-3 review

### BLOCKER

- **[B-1] Phase 0 still misses cross-DB FKs that are not written as `->constrained('tenants')`**
  - Where: `docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:70`; `docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:134`
  - Claim under review: Phase 0 rewrites the 39 tenant migrations that declare `->constrained('tenants')` cross-DB FKs.
  - What I found: exact grep for `constrained('tenants')|constrained("tenants")` returns 39 files, matching the spec. But a broader grep for tenant FK declarations returns 55 lines. Examples that v3 itself says are tenant-scoped:
    - `users` has `foreign('tenant_id')->references('id')->on('tenants')` at `apps/api/database/migrations/2025_11_30_000003_create_users_table.php:33-36`.
    - `companies` has `foreign('tenant_id')->references('id')->on('tenants')` at `apps/api/database/migrations/2025_11_30_104000_create_companies_table.php:104-105`.
    - `product_images` has the same pattern at `apps/api/database/migrations/2025_12_29_155412_create_product_images_table.php:33-34`.
  - Why it matters: these migrations would be moved into `database/migrations/tenant/` with FKs pointing back to central `tenants`, so the DB-per-tenant migration still fails after the supposed B-1 fix.
  - Suggested fix: replace the 39-file wording with a syntax-independent FK rewrite audit. Acceptance should grep for `constrained('tenants')`, `constrained("tenants")`, `references('id')->on('tenants')`, and `references("id")->on("tenants")`; every tenant migration must replace the FK with a plain indexed UUID.

- **[B-2] Topology contract parks shared reference-data classification even though Phase 0 needs it now**
  - Where: `docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:195-197`
  - Claim under review: shared central reference data is an open question, “parked, not a blocker.”
  - What I found: Phase 0 requires classifying every existing migration before the Stancl flip (`docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:97-108`). Existing tables include `countries`, `country_tax_rates`, `country_payment_settings`, and `tax_configurations`; they are not classified in Section 2. They also have intra-reference FKs, e.g. `country_tax_rates.country_code -> countries.code` at `apps/api/database/migrations/2025_12_01_192545_create_country_tax_rates_table.php:24-27` and `tax_configurations.country_code -> countries.code` at `apps/api/database/migrations/2025_12_30_100000_create_tax_configurations_table.php:13-17`.
  - Why it matters: if `countries` stays central while dependent tax/payment tables move tenant-side, Phase 0 creates cross-DB FKs. If all reference tables move tenant-side, tenant DB creation needs a reference-data seeding contract. The contract currently says “everything else” is tenant-scoped at line 42, but then parks this exact decision at line 197.
  - Suggested fix: classify shared reference tables before Phase 0 starts. Either move `countries` and all dependent reference tables into every tenant DB with an explicit seed step, or keep them central and rewrite dependent tenant migrations to Pattern A with no FK. Do not leave it as a parked question.

- **[B-3] T6 Phase 0 effort decision is silently and inconsistently filled in**
  - Where: `docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:6`, `:53`, `:57`, `:268`; roadmap at `docs/superpowers/coordination/2026-05-24-productization-sprint-roadmap.md:39` and `:95`
  - Claim under review: status doc says T6 Phase 0 effort acknowledgement is one of four human decisions (`docs/superpowers/coordination/2026-05-24-autonomous-rework-status.md:130-136`).
  - What I found: v3 both silently fills in `5–6 PD` at T6 line 57 and still publishes `~3 PD` at T6 lines 6, 53, 268 and roadmap line 95. The roadmap top diagram still says `~2-3 days` at line 39.
  - Why it matters: Phase 0 is the global gate. Underestimating or contradicting the gate estimate changes sequencing and staffing for every track.
  - Suggested fix: mark “T6 Phase 0 effort confirmation: decision required from human” until accepted, then update every estimate location consistently.

### P1

- **[P1-1] Roadmap and POS log tell T2 to add `variant_id` to `payments`, but T2 spec does not own that and it is conceptually wrong**
  - Where: `docs/superpowers/coordination/2026-05-24-productization-sprint-roadmap.md:128`, `:142`; `docs/superpowers/coordination/2026-05-24-pos-coordination-log.md:5`, `:9`
  - Claim under review: T2 Wave 1 adds `variant_id` to `payments`.
  - What I found: T2 owns `variant_id` on `stock_levels`, `stock_movements`, `stock_reservations`, `product_batches`, `document_lines`, `pos_receipt_lines`, and `pos_receipt_line_batch_allocations` at `docs/superpowers/specs/2026-05-24-t2-variants.md:289`; no `payments` column is in the T2 spec. Fiscal Task 12 owns `payments.origin` and `payments.fiscal_event_id` at `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:901-936`.
  - Why it matters: implementers following the roadmap/log could add a nonsensical `payments.variant_id` migration or coordinate a fake collision.
  - Suggested fix: remove `payments` from T2/Roadmap/POS coordination variant lists. Keep `payments` only as a fiscal Phase 1 surface.

- **[P1-2] T1 contradicts itself on whether `InTransitAvailability` is per-company or per-tenant**
  - Where: `docs/superpowers/specs/2026-05-24-t1-stock-transfer.md:103`, `:118-124`, `:166`, `:182`
  - Claim under review: storage was revised to use `companies.reservation_settings`.
  - What I found: line 103 says `InTransitAvailability` is per-COMPANY and stored under `companies.reservation_settings`. But the public service API is `current(UUID $tenantId)` / `set(UUID $tenantId, ...)` at lines 118-120; the settings UI is a tenant toggle at line 166; the generic checklist says per-tenant at line 182. Existing `Company::getReservationSettings()` does exist at `apps/api/app/Modules/Company/Domain/Company.php:506-518`, so the per-company storage pattern is real.
  - Why it matters: this changes schema shape, API shape, permission model, and POS availability semantics. It is not a wording nit.
  - Suggested fix: choose one scope. If per-company, change service signatures to take `companyId` or infer from `locationId`, update UI text to company-scoped, and update POS/log wording. If per-tenant, do not store in `companies.reservation_settings`.

- **[P1-3] T6 still overclaims what `TenantInitializationService` seeds at claim time**
  - Where: `docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:16`, `:40`, `:179-180`, `:237`; topology at `docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:162-165`
  - Claim under review: pre-warmed DBs are empty, then claim-time `TenantInitializationService` seeds country-specific CoA + tax + payment methods, including Tunisia stamp duty.
  - What I found: `TenantInitializationService` imports and calls `TunisiaChartOfAccountsSeeder`, `FranceChartOfAccountsSeeder`, `GenericChartOfAccountsSeeder`, `PaymentMethodSeeder`, `PaymentRepositorySeeder`, and `TunisiaTaxConfigurationSeeder` at `apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:14-19`, `:53-69`, `:138-148`, `:205-223`. It does not import or call `TunisiaStampDutySeeder`, `TunisianParapharmacySeeder`, or `TunisiaWithholdingRulesSeeder`. Tax config also no-ops unless `countries` already contains the company country at lines 207-214.
  - Why it matters: a DB-per-tenant claim flow can produce a tenant missing stamp duty/tax configuration while the spec acceptance says Tunisia signup seeds TN CoA + TVA + stamp duty.
  - Suggested fix: either add the missing claim-time seeding work to T6 Phase 1 scope, or weaken the acceptance criteria to exactly what the current service does. Also resolve the `countries` seed dependency explicitly.

- **[P1-4] phpunit/PG strategy and Spatie classification are not marked as human decisions**
  - Where: status doc `docs/superpowers/coordination/2026-05-24-autonomous-rework-status.md:130-134`; T6 `docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:76-80`; topology `docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:36`
  - Claim under review: four decisions are deferred to human.
  - What I found: fiscal ownership is explicitly decision-required, but phpunit/PG is left as Option A/B for implementer choice, and Spatie is left as “Phase 0 must verify.” Code shows Spatie teams are enabled and use `tenant_id` as team key at `apps/api/config/permission.php:95-134`; the migration uses tenant_id in roles and model pivots at `apps/api/database/migrations/2025_11_29_231806_create_permission_tables.php:36-44`, `:61-66`, `:85-90`.
  - Why it matters: Phase 0 implementer will make topology decisions the status doc said were human-owned.
  - Suggested fix: add a visible “Decision required from human” subsection for both, or record the chosen decision with rationale and code citations.

- **[P1-5] Roadmap still claims Wave 1 is collision-free before later contradicting itself**
  - Where: `docs/superpowers/coordination/2026-05-24-productization-sprint-roadmap.md:54-56` vs `:126-146`
  - Claim under review: Wave 1 has “ZERO POS touch” and “No collision with fiscal Phase 1.”
  - What I found: later lines correctly say Wave 1 has backend-POS collisions and require fiscal handshake.
  - Why it matters: readers schedule from the top diagram. The top diagram is still the old false mental model.
  - Suggested fix: change the top diagram to “ZERO Tauri touch; backend-POS handshaked” and remove “No collision.”

### P2

- **[P2-1] Topology contract uses nonexistent/incorrect table name `subscriptions`**
  - Where: `docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:32-33`
  - Claim under review: central tables include `plans` and `subscriptions`.
  - What I found: the actual table is `tenant_subscriptions`, created at `apps/api/database/migrations/2025_12_01_193759_create_tenant_subscriptions_table.php:14`; the model hardcodes `$table = 'tenant_subscriptions'` at `apps/api/app/Modules/Billing/Domain/TenantSubscription.php:47`.
  - Why it matters: Phase 0 migration classification should use exact table names.
  - Suggested fix: replace `subscriptions` with `tenant_subscriptions` and state whether it remains central with plain UUID tenant refs, or tenant-side with Pattern A plan refs.

- **[P2-2] POS coordination log still contains old “Tauri delta” language after broadened scope**
  - Where: `docs/superpowers/coordination/2026-05-24-pos-coordination-log.md:28-30`, `:42`
  - Claim under review: unified log covers backend-POS and Tauri changes.
  - What I found: the same file says no entry below applies to Wave 1 at line 30, while it has Wave 1 backend items at lines 7-9. T1-D3 still ends with “Server-only — not actually a Tauri delta” at line 42.
  - Why it matters: small but confusing in the handoff doc that is supposed to remove coordination ambiguity.
  - Suggested fix: rename “deltas” wording to “coordination items,” and mark T1-D3 as a backend-POS coordination item, not a Tauri exception.

- **[P2-3] Topology contract line citation for `users` is inaccurate**
  - Where: `docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:35`
  - Claim under review: users migration line 13 declares `tenant_id`, line 24 declares unique `(tenant_id,email)`.
  - What I found: line 18 declares `tenant_id`; line 39 declares the unique constraint.
  - Suggested fix: correct the line numbers.

- **[P2-4] T4 still cites `Document.php` for `additional_costs` even though the field lives in `DocumentAdditionalCost`**
  - Where: `docs/superpowers/specs/2026-05-24-t4-order-routing.md:33`
  - What I found: `DocumentAdditionalCost` is the actual model at `apps/api/app/Modules/Document/Domain/DocumentAdditionalCost.php`; the `Document.php` citation is imprecise. This was already raised in round 2 and remains open.
  - Suggested fix: cite the actual model and table.

### SUGGESTION

- **[S-1] T11 still has one stale basename for the old Tauri deltas log**
  - Where: `docs/superpowers/specs/2026-05-24-t11-b2b-b2c-separation.md:151`
  - Suggested fix: replace `2026-05-24-tauri-pos-deltas.md` with `2026-05-24-pos-coordination-log.md`.

- **[S-2] POS coordination log has a broken relative link to the fiscal plan**
  - Where: `docs/superpowers/coordination/2026-05-24-pos-coordination-log.md:3`
  - What I found: from `docs/superpowers/coordination`, `../superpowers/plans/...` resolves to `docs/superpowers/superpowers/plans/...`; the real relative path is `../plans/2026-05-14-pos-phase1-fiscal-event-engine.md`.

## Four-decision audit

- **Fiscal session ownership + vacation-week escalation**
  - Silent fill? No.
  - Explicit decision-required? Pass. Roadmap says the ownership question blocks Wave 2/backend-POS Wave 1 and requires Houssam's decision at `docs/superpowers/coordination/2026-05-24-productization-sprint-roadmap.md:146`.
  - Options clear? Mostly. Options are named at roadmap lines 146 and 225, but canonical channel/SLA/escalation still need final values.

- **phpunit/PG test strategy for Phase 0**
  - Silent fill? No single answer chosen.
  - Explicit decision-required? Fail. T6 gives Option A/B at `docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:76-80`, but does not say this is a human decision despite status lines 130-134.
  - Options clear? Yes, but owner is not.

- **Spatie permissions classification**
  - Silent fill? No single final answer, but T6 nudges implementer verification.
  - Explicit decision-required? Fail. Topology line 36 calls it an open question; T6 line 68 says verify each. The status doc says human decision.
  - Options clear? Partly. Actual code gives useful evidence: teams enabled with `tenant_id` at `apps/api/config/permission.php:95-134`; roles and model pivots carry `tenant_id` in the migration.

- **T6 Phase 0 effort confirmation**
  - Silent fill? Yes. T6 says 5–6 PD at line 57.
  - Explicit decision-required? Fail. The same docs still say ~3 PD in multiple places.
  - Options clear? No. v3 needs one accepted number or a visible decision gate.

## T6 Phase 0 effort reality check

v1 said 3 PD, v2 says both 3 PD and 5–6 PD, and the round-2 context says the reviewer estimated much higher. My estimate for the actual v3 Phase 0 work is **10–12 PD** before review time.

Justification: this is not only “move files and flip Stancl.” It includes classifying roughly 354 existing migrations, creating the tenant migrations directory, moving every tenant migration while preserving order, rewriting all cross-DB FKs by syntax-independent scan, resolving shared reference-data topology, resolving Spatie placement, adding `central`, fixing duplicate `pgsql`, adding or choosing PG CI strategy, writing a real DB-creation integration test, moving fiscal migrations, and coordinating the fiscal pause. That is at least two focused engineering weeks if done without damaging existing tests.

The current 5–6 PD number is optimistic; the still-published 3 PD number is not credible.

## Genericity audit (quick)

- Client names: no `Nénupharma`, `ParaFendri`, or `Finderi` hits in production code/migrations from my grep. `Nénupharma` appears in docs as the forcing function, which is acceptable context.
- Hardcoded Tunisian geography: production code already has Tunisia/Tunis/Africa-Tunis references outside seeders, for example country lists and default company UI. These appear pre-existing and often regulatory, not newly introduced by the sprint specs. The sprint docs should not claim “zero outside seeders” globally unless scoped to new T4 `OrderRouting/` code.
- Concrete adapter strings: T3 still contains `WooCommerce`, `Shopify`, `PrestaShop`, and `Paradeals` in future/deferred sections, not as production implementation. That is acceptable. No `automattic/woocommerce` production dependency was found.

## What's solid in v3

1. T2's round-2 blockers are genuinely fixed: correct POS batch allocation table name and correct null-variant stock-level unique key shape.
2. T11's channel override resolver order is fixed and now explains why channel override must be first.
3. The roadmap now acknowledges backend-POS fiscal collisions and creates a unified coordination log with T1-S1 and T2-S1 entries.
4. T6 correctly recognizes that `users` is tenant-scoped and that `central` must be added before Pattern A can compile.
5. The fiscal migration pause/handover is now explicit in both T6 and the roadmap.

## Ready-to-start verdict

T6 Phase 0 should **not** start from this v3. A v4 is needed first.

Highest-priority v4 fixes:

1. Replace the 39-FK rewrite scope with a syntax-independent cross-DB FK audit and acceptance check.
2. Classify shared reference data and actual billing table names (`tenant_subscriptions`) in the topology contract.
3. Resolve or explicitly human-gate phpunit/PG strategy, Spatie placement, and Phase 0 effort.
4. Remove the false `payments.variant_id` claim from roadmap/POS coordination.
5. Make T6 Phase 0 effort consistent across roadmap, spec header, section title, workflow, and status docs.
