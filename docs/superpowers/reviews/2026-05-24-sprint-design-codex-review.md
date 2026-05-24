# Sprint Design Adversarial Review — Codex
**Date:** 2026-05-24
**Reviewer:** Codex
**Verdict:** NEEDS-REWORK

## Executive summary
The sprint package is not ready for implementation. Several “verified” architecture claims are false against the current codebase, and the roadmap’s parallelization model hides real dependencies and POS fiscal collisions. The largest risks are T6’s database-per-tenant migration plan, T1/T2/T11 POS interactions with the in-flight fiscal rebuild, and T3/T5 grounding errors that would send implementers toward non-existent or wrong patterns. Fix the BLOCKERs and P1s before opening implementation sessions.

## Findings by severity
### BLOCKER
- [B-1] T1 acceptance criteria assert `StockMovement.batch_id`, but the code has no such field (spec: T1, severity: BLOCKER)
  **Claim:** T1 says batch preservation should be verified via `StockMovement.batch_id` on both transfer legs and later says `batch_id` should be present on receipt, transfer-out, transfer-in, and sale movements (`docs/superpowers/specs/2026-05-24-t1-stock-transfer.md:180`, `:206-207`).
  **Reality:** `StockMovement` fillable/properties contain movement metadata but no `batch_id` (`apps/api/app/Modules/Inventory/Domain/StockMovement.php:20-48`, `:55-75`). Batch linkage lives in `inventory_batch_movements.batch_id` plus `movement_id` (`apps/api/database/migrations/2026_01_05_150002_create_inventory_batch_movements_table.php:14-23`).
  **Impact:** Tests and implementation will target a column that does not exist, or add a duplicate batch pointer that conflicts with the existing batch movement model.
  **Recommended fix:** Rewrite T1 acceptance/tests to verify `inventory_batch_movements` rows linked to each `stock_movements.id`, or explicitly add a `stock_movements.batch_id` schema change and justify why it duplicates the existing join table.

- [B-2] T3 Section 2 cites non-existent “verified” paths (spec: T3, severity: BLOCKER)
  **Claim:** T3 says `VerifySynerivaWebhookSignature.php` is under `PlatformIntegration/Middleware` and `ProcessEnrichmentWebhookJob.php` is under `PlatformIntegration/Jobs` (`docs/superpowers/specs/2026-05-24-t3-sync-hub.md:27-29`).
  **Reality:** The actual middleware is `apps/api/app/Modules/PlatformIntegration/Infrastructure/Middleware/VerifySynerivaWebhookSignature.php`, and the job is `apps/api/app/Modules/PlatformIntegration/Application/Jobs/ProcessEnrichmentWebhookJob.php`. The cited paths do not exist.
  **Impact:** Agents following the spec will fail the first reading pass or create duplicate files in the wrong layer.
  **Recommended fix:** Correct both paths and update the layer guidance: middleware is Infrastructure, async job is Application.

- [B-3] T6’s DB-per-tenant migration plan collides with every tenant-scoped migration in the sprint (spec: T6, severity: BLOCKER)
  **Claim:** T6 says Phase 1 moves tenant-scoped migrations into `database/migrations/tenant/` and flips to `PostgreSQLDatabaseManager` in ~2 PD (`docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:138-143`, `:225`).
  **Reality:** `apps/api/database/migrations/tenant/` is currently missing (`docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:39-40`). Existing tenant tables such as `stock_levels` and `stock_movements` are in central migrations today (`apps/api/database/migrations/2025_11_30_110000_create_inventory_tables.php:16-50`), and other sprint tracks propose tenant-scoped migrations.
  **Impact:** Parallel work will put new migrations in the wrong place, or T6 will move files underneath active branches. This is not “invisible to application code” and cannot be safely treated as non-blocking.
  **Recommended fix:** Make T6 Phase 1 a pre-sprint migration-topology gate. Define central vs tenant migration ownership first, then require T1/T2/T3/T4/T5 migrations to target the new directory after the gate lands.

- [B-4] Roadmap says there are no POS fiscal conflicts, but the sprint changes the exact surfaces fiscal Phase 1 is rebuilding (spec: roadmap/T1/T2, severity: BLOCKER)
  **Claim:** Roadmap says there are “No conflicts” with POS fiscal Phase 1 (`docs/superpowers/coordination/2026-05-24-productization-sprint-roadmap.md:77-79`).
  **Reality:** The fiscal plan explicitly reworks `apps/pos/src/lib/offline/receiptService.ts`, `offlineCheckoutService.ts`, `receiptApi.ts`, `syncService.ts`, and the device SQLite migration set (`docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:75-77`). T2 adds POS `variant_id` receipt-line/cache migrations (`docs/sessions/2026-05-24-tauri-pos-deltas.md:24-27`), and T1 changes receipt printing for location tax fields (`docs/sessions/2026-05-24-tauri-pos-deltas.md:18`).
  **Impact:** Fiscal event payloads, offline receipt storage, sync, and printing can diverge if implemented independently.
  **Recommended fix:** Replace “No conflicts” with a POS fiscal integration gate: T1/T2/T11 POS deltas must be sequenced into the fiscal session, with explicit ownership of SQLite migrations and fiscal payload shape.

- [B-5] Roadmap dependency model is false for T4 (spec: roadmap/T4, severity: BLOCKER)
  **Claim:** Roadmap says all seven tracks can start in parallel and that T3’s WC variant mapping is the only dependency (`docs/superpowers/coordination/2026-05-24-productization-sprint-roadmap.md:70-74`).
  **Reality:** T4 itself says it depends on T3 because it consumes `ChannelOrder` and routes it into `Document.location_id` (`docs/superpowers/specs/2026-05-24-t4-order-routing.md:272`, `:280`). `ChannelOrder` is new in T3, not existing code.
  **Impact:** T4 Phase 5 cannot be implemented or tested until T3’s `ChannelOrder` model/contract exists. Starting the integration in parallel creates either stubs or later rewrites.
  **Recommended fix:** Change roadmap sequencing: T4 Phase 1/2 can start independently, but T4 channel-order integration is blocked on T3 Phase 1 and order-ingest contract.

### P1
- [P1-1] T5 is not an “extension on existing ECharts” in the cited pages (spec: T5, severity: P1)
  **Claim:** T5 says `Dashboard.tsx` and `ReportsPage.tsx` are production-wired with ECharts v6 (`docs/superpowers/specs/2026-05-24-t5-reporting.md:13`, `:25-29`).
  **Reality:** Both pages use TanStack Query and `tenantScopedKey`, but neither imports ECharts. `Dashboard.tsx` imports query, stores, formatting, and lucide icons (`apps/web/src/features/dashboard/Dashboard.tsx:1-21`); `ReportsPage.tsx` does the same (`apps/web/src/features/reports/ReportsPage.tsx:1-17`). ECharts is only present as a package dependency (`apps/web/package.json:49-50`).
  **Impact:** The UI estimate and implementation pattern are wrong. This is not extending existing chart components; it is adding the first owner dashboard chart surface.
  **Recommended fix:** Reword T5 to say ECharts is installed but not used by the cited pages. Add a real chart component reference if one exists, or specify the first chart wrapper to create.

- [P1-2] T3 assumes the WooCommerce SDK is available, but it is not installed (spec: T3, severity: P1)
  **Claim:** T3 says to verify `automattic/woocommerce` in `composer.json` and uses it in Phase 2 (`docs/superpowers/specs/2026-05-24-t3-sync-hub.md:37`, `:277`).
  **Reality:** `rg "woocommerce|automattic" apps/api/composer.json apps/api/composer.lock` returns no matches.
  **Impact:** Phase 2 cannot compile without adding dependency management, version choice, config, and test mocking.
  **Recommended fix:** Add an explicit Phase 1 dependency task: choose SDK version, update composer, wire config, and define adapter tests against mocked SDK interfaces.

- [P1-3] T4’s routing tables do not carry enough tenant/company anchors to enforce isolation by schema alone (spec: T4, severity: P1)
  **Claim:** T4 asserts tenant isolation and defines per-tenant zones/rules (`docs/superpowers/specs/2026-05-24-t4-order-routing.md:44-80`).
  **Reality:** `LocationServiceZone` has only `location_id` and `zone_id`, and `RoutingRuleSet` has `fallback_location_id` with no tenant/company key (`docs/superpowers/specs/2026-05-24-t4-order-routing.md:55-64`). `Location` itself is scoped by `company_id`, not `tenant_id` (`apps/api/app/Modules/Company/Domain/Location.php:22-23`, `:70-88`).
  **Impact:** A malformed rule/service-zone row can connect a location from tenant/company A to a zone/ruleset from tenant B unless every write path performs cross-entity validation.
  **Recommended fix:** Add `tenant_id` to `LocationServiceZone` and `company_id` or tenant-validated constraints where location FKs appear; specify service-layer validation and tests for cross-tenant/cross-company FK attempts.

- [P1-4] T11 PricingStrategyResolver omits an existing pricing path (spec: T11, severity: P1)
  **Claim:** T11 says its resolution order is complete and deterministic (`docs/superpowers/specs/2026-05-24-t11-b2b-b2c-separation.md:86-93`, `:187`).
  **Reality:** Current `PricingService::getPrice()` resolves partner-specific price lists, then the company default price list, then product base price (`apps/api/app/Modules/Pricing/Domain/Services/PricingService.php:41-77`). T11 omits the existing default price list path and introduces a “Business customer group” path that I did not find in the current pricing code.
  **Impact:** The new resolver would regress non-partner default price list behavior or force an unplanned customer-group model into T11.
  **Recommended fix:** Make the resolver order: explicit partner price list, existing company default price list/quantity break, channel override if intentionally higher/lower priority, then product/variant sale price. Add customer groups only if a concrete current model or new migration is specified.

- [P1-5] T1 says no Tauri POS code changes while requiring POS behavior changes (spec: T1, severity: P1)
  **Claim:** T1 says “No new screens” and “No Tauri POS code changes from this track,” while also requiring `InTransitAvailability` to change whether POS sales succeed or fail (`docs/superpowers/specs/2026-05-24-t1-stock-transfer.md:156-158`, `:184`, `:275`).
  **Reality:** Current POS product display is based on cached `product.stock_quantity` and local cart state (`apps/pos/src/stores/cartStore.ts:142-191`), and backend receipt creation checks raw `StockLevel::getAvailableQuantity()` without an in-transit setting (`apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:831-883`). The deltas log only records T1 receipt tax fields (`docs/sessions/2026-05-24-tauri-pos-deltas.md:14-18`).
  **Impact:** The acceptance criterion cannot pass through server work alone, especially offline. The POS will not show “available with notice” or block based on the new tenant setting unless a delta is added.
  **Recommended fix:** Add a T1 POS delta for in-transit availability semantics, including offline behavior and server-side receipt enforcement.

- [P1-6] T6 Section 2 has more non-existent “verified” paths (spec: T6, severity: P1)
  **Claim:** T6 cites country seeders under `app/Modules/Country/Seeders` and monitoring under `app/Http/Controllers/Monitoring/MonitoringController.php` (`docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:33-37`).
  **Reality:** The seeders are under `apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php` and `apps/api/database/seeders/TunisiaTaxConfigurationSeeder.php`; monitoring is under `apps/api/app/Modules/Admin/Presentation/Controllers/MonitoringController.php`.
  **Impact:** Implementers will wire or extend the wrong namespace/layer for seeders and monitoring.
  **Recommended fix:** Correct the paths and state whether backup/pool monitoring belongs in the Admin module or a Tenant/Ops module.

- [P1-7] T2’s “mirror CompositeItemVariant lifecycle” pattern is not real enough for product variants (spec: T2, severity: P1)
  **Claim:** T2 says `CompositeItemVariant.php` lines 29-102 are an existing variant lifecycle pattern to mirror (`docs/superpowers/specs/2026-05-24-t2-variants.md:25`).
  **Reality:** Those lines define fillable fields, casts, one relation, and `calculatePrice()` (`apps/api/app/Modules/Catalog/Domain/Entities/CompositeItemVariant.php:29-102`). There is no attribute matrix generation, SKU/barcode uniqueness, stock movement integration, or lifecycle service.
  **Impact:** Mirroring this pattern will under-design product variants, which need tenant-level attributes, matrix generation, stock/batch/document/POS integration, and channel mapping.
  **Recommended fix:** Treat `CompositeItemVariant` only as a small Eloquent style reference. Add concrete patterns for product service/controller/repository structure and a separate variant lifecycle design.

### P2
- [P2-1] T3 misstates PlatformHttpClient timeout semantics (spec: T3, severity: P2)
  **Claim:** T3 says PlatformHttpClient has “timeout 10s connect / 5s read” (`docs/superpowers/specs/2026-05-24-t3-sync-hub.md:41`).
  **Reality:** Code uses `timeout(10)` and `connectTimeout(5)` (`apps/api/app/Modules/PlatformIntegration/Infrastructure/Http/PlatformHttpClient.php:215-221`).
  **Impact:** Minor, but it matters for adapter parity and test assertions.
  **Recommended fix:** Correct the spec to “5s connect, 10s total/request timeout” unless changing the client.

- [P2-2] T2 stock-level migration reference points at the wrong migration family (spec: T2, severity: P2)
  **Claim:** T2 says `2025_11_30_131000_*` is the stock-level unique constraint to extend (`docs/superpowers/specs/2026-05-24-t2-variants.md:30`).
  **Reality:** `2025_11_30_131000_add_company_id_to_stock_tables.php` adds company columns/indexes (`apps/api/database/migrations/2025_11_30_131000_add_company_id_to_stock_tables.php:31-45`). The actual `stock_levels` unique constraint is in `2025_11_30_110000_create_inventory_tables.php:16-30`.
  **Impact:** The migration author may drop/alter the wrong index set and miss the old unique constraint that blocks variant rows.
  **Recommended fix:** Replace the reference with `2025_11_30_110000_create_inventory_tables.php:16-30` plus the later company-id migrations.

- [P2-3] T4 workflow classification underplays the core design work (spec: T4, severity: P2)
  **Claim:** Roadmap recommends Codex for “rule engine + CRUD” with Opus review (`docs/superpowers/coordination/2026-05-24-productization-sprint-roadmap.md:26`).
  **Reality:** T4 itself assigns ScoringStrategy interface, RuleEvaluator, and OrderRoutingService semantics to Opus (`docs/superpowers/specs/2026-05-24-t4-order-routing.md:264-274`).
  **Impact:** The roadmap undersells the design-heavy part and may dispatch the wrong tool first.
  **Recommended fix:** Align roadmap with T4: Opus owns rule evaluation semantics and strategy architecture before Codex implements CRUD/strategies.

- [P2-4] T6 effort estimate is materially under-scoped (spec: T6, severity: P2)
  **Claim:** T6 estimates ~7 PD for DB-per-tenant flip, pre-warm pool, backup automation, restore drill, dashboard widgets, tests, and runbooks (`docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:225-231`).
  **Reality:** The work includes migration reclassification of the existing app, Stancl manager change, PgBouncer compatibility, pool race locking, pg_dump/S3 retention, restore safety, monitoring, UI, and runbooks.
  **Impact:** The estimate hides the riskiest infrastructure work in the sprint and encourages unsafe parallelization.
  **Recommended fix:** Split T6 into a prerequisite migration-topology track and a separate ops/backup track; do not treat it as a 7 PD side quest.

### P3
- [P3-1] Naming inconsistency in T1 inter-company event (spec: T1, severity: P3)
  **Claim:** Section 2 says add `InterCompanyTransferPosted`; Section 4 lists `InterCompanyDocumentsPosted` (`docs/superpowers/specs/2026-05-24-t1-stock-transfer.md:44`, `:123`).
  **Reality:** No existing event resolves the mismatch.
  **Impact:** Small, but event names will drift across tests/listeners.
  **Recommended fix:** Pick one event name. I would use `InterCompanyTransferPosted` if it represents the whole transaction, or `InterCompanyDocumentsPosted` only if it is strictly document creation.

### NOTE
- Domain-name collision sweep found no existing concrete classes for the proposed `StockTransfer`, `ProductVariant`, `ChannelOrder`, `RoutingDecision`, `TenantPreWarmService`, or `DocumentEmissionPolicy` names. The only near-collision is `VariantService` vs existing image-variant services (`ImageVariantService`), which is not a direct class-name conflict but argues for `ProductVariantService`.
- T1/T2 ownership of `variant_id` is internally consistent: T1 references it and T2 owns the column additions.

## Productization audit
T1: Mostly generic in model intent, but the POS in-transit behavior is not productized yet because no setting storage/API/POS delta is specified. The tax fields are generic, but receipt/invoice fallback behavior needs exact source-of-truth rules.

T2: Generic attribute/value/variant design is directionally sound. The UI examples and deltas are size/color-heavy, but acceptable if the matrix editor truly supports 1-3 axes. Current pattern references are too weak.

T3: Adapter genericness is good on paper, but Paradeals is first-class in the sprint while T7 is deferred. Keep Paradeals code isolated and make credential/idempotency abstractions channel-neutral.

T4: Zone/rule model is generic, but the Tunisia seeder must stay optional and tenant-scoped. Add tenant/company anchors to service-zone and fallback-location links.

T5: KPI set is generic retail. The design-token/no-hardcoded-colors claim needs enforcement because current Dashboard/Reports pages already use hardcoded Tailwind color families.

T6: Generic ops design is under-specified for environment variability. Pool sizing, backup region, restore destination, and PgBouncer bypass rules must be config/runbook-driven.

T11: Generic direction is sound, but PricingStrategyResolver is incomplete against current pricing paths. “Business customer group” should not appear as a required step unless the customer-group model is added.

## Gaps identified
- No explicit migration topology contract for the whole sprint after T6. Every build track needs to know central vs tenant migration placement before writing migrations.
- No fiscal payload/schema plan for variant-aware POS receipts, location tax metadata on printed receipts, or B2B draft hand-off.
- No explicit offline behavior for variants or in-transit stock beyond a low-urgency SQLite delta.
- No security model for WooCommerce webhook tenant resolution beyond “channel_id in URL”; the spec should require channel lookup to bind tenant context before any order mutation.
- No idempotency model for T1 transfer completion creating movements/documents, especially retry after partial failure.
- No data backfill plan for existing `stock_levels` uniqueness when adding nullable `variant_id`.

## Recommendation
Do not start implementation as-is. Fix all BLOCKERs and P1s, then re-review the roadmap and the individual specs. The minimum edits are: correct verified paths, rewrite T6 sequencing, add POS/fiscal integration gates, fix T1 batch verification against the real batch movement model, and align the dependency graph with T3/T4/T6 reality.
