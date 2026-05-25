# T4 — Order Routing Engine

**Track:** T4 (P0 sprint — Wave 1 for Phases 1-4; Phase 5 integration waits for T3 framework)
**Date:** 2026-05-24 (v2 after Codex round-1 review)
**Recommended workflow:** Opus for ScoringStrategy interface + RuleEvaluator + OrderRoutingService semantics. Codex for zone CRUD + scoring strategy implementations + admin UI.
**Estimated effort:** ~9 PD (revised from v1's 10; Phase 5 may slip to follow-up if T3 lands late)
**Roadmap reference:** [2026-05-24-productization-sprint-roadmap.md](../coordination/2026-05-24-productization-sprint-roadmap.md)
**Constitutional reference:** [2026-05-24-migration-topology-contract.md](../coordination/2026-05-24-migration-topology-contract.md)

---

## 1. Purpose

When an order arrives (from any channel — future T3 adapters, future call-center, future direct entry), the system must decide WHICH location (warehouse or shop) fulfills it. Today, `Document.location_id` is set manually at creation. No automated routing.

This module ships a generic, rule-based routing engine consuming orders from any channel and producing a routing decision. Rules stored in DB per tenant. Scoring strategies pluggable. Engine extensible so future criteria (delivery efficiency feedback, tax optimization, ML-based scoring) plug in without core refactor.

Designed so a 3-location tenant and a 30-location tenant use the same module — only rules differ.

---

## 2. Architecture grounding (verified file paths)

Read before writing code:

1. `apps/erp/apps/api/app/Modules/Document/Domain/Document.php` (lines 1–378+) — Document model with `location_id`; routing engine sets this field
2. `apps/erp/apps/api/app/Modules/Document/Domain/Enums/DocumentType.php` — types the engine routes (typically `SalesOrder`, `Invoice` when paid online)
3. `apps/erp/apps/api/app/Modules/Document/Domain/Enums/DeliveryStatus.php` — lifecycle stages
4. `apps/erp/apps/api/app/Modules/Partner/Domain/Partner.php` (lines 1–378) — Partner with inline address (street, city, state, postal_code, country) — input
5. `apps/erp/apps/api/app/Modules/Company/Domain/Location.php` (lines 22–88: Location is scoped via `company_id`, not `tenant_id`. Per topology contract, intra-tenant FKs are fine for Phase 1; trust DB boundary for tenant isolation)
6. `apps/erp/apps/api/app/Modules/Inventory/Domain/StockLevel.php` — stock per location — routing input
7. `apps/erp/apps/api/app/Modules/Inventory/Domain/StockReservation.php` — routing decision creates reservation at chosen location
8. `apps/erp/apps/api/app/Modules/Document/Domain/DocumentAdditionalCost.php` (line 17 has the `cost_type` enum: `transport`/`shipping`/`insurance`/`customs`/`handling`/`other`). Note: `additional_costs` is a related entity (separate `document_additional_costs` table), NOT a JSONB column on Document — corrected per round-3 P2-4 + round-4 sweep.
9. **No existing Zone/Territory/Governorate entity** — confirmed absent; this spec creates it
10. **No existing carrier/shipping engine** — `additional_costs` is manual entry only; routing scoring uses a stub estimator based on location-pair distance heuristic, externalizable later

**Constraints:** hexagonal, constructor injection, strict typing, enums, TDD.
**Migration placement:** all T4 migrations → `database/migrations/tenant/` per topology contract.
**Cross-DB FK / tenant_id reframe (per Codex P1-3 correction + topology contract):**

The v1 spec proposed adding `tenant_id` columns to `LocationServiceZone` and other routing tables for cross-tenant validation. **This is wrong post-T6 DB-per-tenant flip** — tenant isolation comes from the DB boundary itself, not from FK constraints. Within a tenant DB, normal FKs to `locations` and `zones` are sufficient and correct. We do NOT add `tenant_id` FKs to T4 tables; we trust the Stancl tenant context to keep us in the right DB.

For Phase 0 transitional period (between row-level isolation today and DB-per-tenant post-T6), the existing `Location.company_id` scoping is enforced at service layer; new T4 routing tables follow the same pattern. Pure intra-tenant FKs.

---

## 3. Domain model

### New entities (all in tenant DB)

**`Zone`** (per-tenant taxonomy of geography)
- `id`, `code` (unique per tenant), `name` (localized), `country_code` (ISO 3166-1), `parent_zone_id` (nullable, hierarchical), `display_order`, `is_active`
- NO `tenant_id` column — DB boundary IS the tenant per topology contract

**`ZonePostalRange`** (postal-code lookup)
- `id`, `zone_id` (FK Zone), `country_code`, `postal_code_pattern` (string or regex), `postal_code_start`, `postal_code_end`

**`LocationServiceZone`** (which zones each location services)
- `id`, `location_id` (FK Location — intra-tenant), `zone_id` (FK Zone — intra-tenant), `priority` (int — lower = preferred)
- `delivery_time_hours_estimate` (nullable), `delivery_cost_estimate` (nullable, decimal)
- NO `tenant_id` FK (per topology contract; per Codex P1-3 corrected reasoning)

**`RoutingRuleSet`** (tenant's named ruleset; typically one default per channel)
- `id`, `name`, `is_default`, `channel_id` (FK Channel from T3, nullable — null applies to all channels)
- `scoring_strategy` enum: `ZoneOnly`, `ZoneAndStock`, `ZoneStockAndCapacity`, `Composite`
- `allow_split_orders` (bool, default false)
- `fallback_location_id` (FK Location, nullable — used when no rule matches)

**`RoutingRule`** (composable rules within a ruleset)
- `id`, `rule_set_id` (FK), `display_order`
- `condition_type` enum: `ChannelMatch`, `ZoneMatch`, `ProductCategoryMatch`, `CustomerTagMatch`, `OrderValueRange`, `CustomerTypeMatch`, `Always`
- `condition_payload` JSONB
- `action_type` enum: `AssignLocation`, `BoostLocationScore`, `ExcludeLocation`, `RequireApproval`, `RouteToManualReview`
- `action_payload` JSONB
- `is_active`

**`RoutingDecision`** (audit trail)
- `id`, `document_id` (FK Document), `channel_order_id` (FK ChannelOrder from T3, nullable)
- `rule_set_id`, `decided_at`, `chosen_location_id`, `split_decisions` JSONB
- `evaluated_rules` JSONB (which rules fired, scores)
- `manual_override` (bool), `overridden_by_user_id` (nullable)
- `reasoning` (text, human-readable)

### Enums

- `RoutingScoringStrategy` (ZoneOnly, ZoneAndStock, ZoneStockAndCapacity, Composite)
- `RoutingConditionType` (ChannelMatch, ZoneMatch, ProductCategoryMatch, CustomerTagMatch, OrderValueRange, CustomerTypeMatch, Always)
- `RoutingActionType` (AssignLocation, BoostLocationScore, ExcludeLocation, RequireApproval, RouteToManualReview)

### Module placement

New module: `apps/erp/apps/api/app/Modules/OrderRouting/`

- `Domain/Entities/` — Zone, ZonePostalRange, LocationServiceZone, RoutingRuleSet, RoutingRule, RoutingDecision
- `Domain/Strategies/` — `ZoneOnlyStrategy`, `ZoneAndStockStrategy`, `ZoneStockAndCapacityStrategy`, `CompositeStrategy` (implement `ScoringStrategy`)
- `Application/Services/` — `OrderRoutingService`, `ZoneResolverService`, `RuleEvaluator`
- `Infrastructure/Repositories/`
- `Presentation/Controllers/`

---

## 4. Public contracts

### Port interface (pluggable scoring)

```php
interface ScoringStrategy
{
    public function name(): RoutingScoringStrategy;
    public function score(RoutingContext $context, Location $candidate): LocationScore;
}

// RoutingContext = order + customer + channel + line items
// LocationScore = score (float) + reasoning + capacity_check + stock_check
```

### Application services

```php
OrderRoutingService
  ::route(UUID $documentId): RoutingDecision
  ::routeFromChannelOrder(UUID $channelOrderId): RoutingDecision  // T3 integration (Phase 5)
  ::manualOverride(UUID $decisionId, UUID $locationId, User $user, string $reason): RoutingDecision

ZoneResolverService
  ::resolveZone(string $postalCode, string $countryCode): Collection<Zone>
```

### Events

- `OrderRouted` (decision_id, document_id, chosen_location_id, rule_set_id)
- `OrderRoutingFailed` (document_id, reason)
- `RoutingDecisionOverridden` (decision_id, original_location_id, new_location_id, user_id, reason)

### REST endpoints

- `GET /api/v1/zones`, `POST /api/v1/zones`, `POST /api/v1/zones/{id}/postal-ranges`
- `GET /api/v1/locations/{id}/service-zones`, `POST /api/v1/locations/{id}/service-zones`
- `GET /api/v1/routing-rule-sets`, `POST /api/v1/routing-rule-sets`, `PUT /api/v1/routing-rule-sets/{id}/rules`
- `POST /api/v1/routing/preview` — given draft order, return predicted decision without creating Document/Decision
- `GET /api/v1/routing/decisions/{documentId}`
- `POST /api/v1/routing/decisions/{id}/override`

---

## 5. User-visible surface

### Admin UI (`apps/web/src/features/order-routing/`)

- **ZoneManagerPage** — manage tenant's zone taxonomy + postal-range mapping
- **LocationServiceZonesPage** — per location, show zones + priority
- **RoutingRuleSetEditor** — drag/drop rules, configure conditions + actions, set scoring strategy
- **RoutingPreviewPage** — paste sample order JSON, see decision + why
- **RoutingDecisionsLogPage** — audit log with manual-override capability

### Seed data (tenant-optional)

- Tunisia governorate seed (24 governorates) provided as one-time seeder named `TunisianGovernorateZoneSeeder`
- Postal-range patterns for Tunisia (rough mapping)
- **Seeder is opt-in per tenant** — runs only when tenant chooses to import; not part of `TenantInitializationService` automatic flow

### POS (Tauri 2)

- **No new POS screens.** Routing is server-side; POS sees fulfilled orders in existing Documents list.

---

## 6. Generic-ness checklist

- [ ] Zero hardcoded geography (Tunisia governorates ONLY in seeder, never in code)
- [ ] Zone hierarchy supports country → state/region → city → district nesting (worldwide)
- [ ] Scoring strategies pluggable — new one = new class implementing interface
- [ ] Rules DB-stored and per-tenant
- [ ] Channel-aware (rules per channel)
- [ ] Customer-aware (rules match customer type, tags, location)
- [ ] Product-aware (rules match category, line value)
- [ ] Future-extensible: `DeliveryEfficiencyStrategy` (ML-based) = new ScoringStrategy class
- [ ] All migrations in `database/migrations/tenant/`
- [ ] **No `tenant_id` FK on routing tables** — DB boundary IS the tenant per topology contract

---

## 7. Acceptance criteria

### Zone taxonomy

- [ ] Seed Tunisia 24-governorate zone hierarchy via `TunisianGovernorateZoneSeeder` (opt-in)
- [ ] Add postal ranges for Sousse, Tunis, Sfax (3 major hubs)
- [ ] Assign 4 locations (1 warehouse Sousse + 1 shop Sousse + 1 shop Tunis + 1 shop test) to service zones with priority
- [ ] `ZoneResolverService::resolveZone("4000", "TN")` returns Sousse zone

### Rule evaluation

- [ ] Default ruleset with `scoring_strategy=ZoneAndStock` + `allow_split_orders=false`
- [ ] WC order (future T3) with customer in Tunis postal code 1000, items in stock at Tunis shop → routes to Tunis shop
- [ ] Order with customer in postal 4000, items split between Sousse + Tunis with `allow_split_orders=false` → routes to warehouse (fallback)
- [ ] Order in postal 6000 (no rule match) → routes to `fallback_location_id`
- [ ] Add rule "if channel=Paradeals AND order_value>500 TND → AssignLocation=warehouse" → verify large Paradeals orders go to warehouse regardless of customer location

### Decision audit

- [ ] Every routed order has `RoutingDecision` row with `reasoning`
- [ ] Manual override updates `Document.location_id` + records user + reason

### Preview

- [ ] `POST /api/v1/routing/preview` with sample order JSON returns predicted location WITHOUT creating Document or Decision

### Tests

- [ ] Unit test per `ScoringStrategy` implementation
- [ ] Unit test for `RuleEvaluator` with each condition + action type
- [ ] Feature test: full happy path (mock channel order → routing → `Document.location_id`)
- [ ] Feature test: split-order toggle
- [ ] Feature test: fallback location
- [ ] Feature test: tenant isolation (cannot route across tenant DBs — DB boundary)
- [ ] Test: seeding Tunisia governorates is idempotent
- [ ] Test: routing service rejects when given a location_id not in current tenant DB (service-layer validation per topology contract)

---

## 8. Adversarial review checklist

**Reviewer instruction (mandatory):** *"Verify findings against actual code. Pay special attention to: (1) is the engine truly generic — any hardcoded Tunisian names outside `TunisianGovernorateZoneSeeder`? (2) deterministic rule evaluation — same inputs always same decision? (3) tenant isolation via DB boundary (no tenant_id FKs); does service-layer validation correctly reject cross-tenant location_id values? (4) reservation race — when two concurrent orders need the same insufficient stock, does only one succeed?"*

- [ ] **Genericity:** grep `OrderRouting/` for "Tunis", "Sousse", "Sfax", "Nénupharma" — should appear ONLY in seeder
- [ ] **Determinism:** same RoutingContext + ruleset → same RoutingDecision every time
- [ ] **Tenant isolation:** routing engine cannot match rule from tenant A to order from tenant B (DB boundary)
- [ ] **No `tenant_id` FKs on routing tables** — per topology contract; intra-tenant FKs only
- [ ] **Postgres NULL semantics:** `channel_id` nullable on RuleSet — ruleset with NULL channel_id applies to all channels correctly
- [ ] **Stock availability:** scoring uses `available_quantity` (qty - reserved), not raw qty
- [ ] **Reservation race:** routing decision atomically creates reservation; two concurrent orders for same insufficient stock — only one succeeds, other rejected with clear error
- [ ] **Permission gates:** rule editing requires admin; overriding requires `routing.override` permission
- [ ] **Migration safety:** seeders idempotent; rerun doesn't duplicate zones
- [ ] **Performance:** routing decision for 50-line order across 100 candidate locations < 200ms
- [ ] **Variant-awareness (after T2 merges):** stock checks handle variant-level stock
- [ ] **All migrations in `database/migrations/tenant/`** per topology contract

---

## 9. Out of scope

- ML-based scoring (delivery efficiency feedback) — future strategy plug-in
- Tax-optimization routing — future
- Carrier API integrations for real shipping cost/time — future (stub estimator now)
- Multi-warehouse picking optimization
- Real-time delivery tracking
- Customer choice at checkout ("ship from X or Y")
- Cross-tenant routing (would violate topology contract — Paradeals T7 territory)

---

## 10. Reading order

1. This spec
2. Migration topology contract
3. `apps/erp/CLAUDE.md` + `claude/architecture.md`
4. The file paths in Section 2
5. T3 spec — `ChannelOrder` hand-off contract (Phase 5 dependency)
6. T1 spec — StockReservation model + per-location stock
7. Memory: `project_pos_fiscal_event_engine.md` for document lifecycle context

---

## 11. Workflow recommendation

**Phase 1 (Codex, ~2 PD):** Zone + ZonePostalRange + LocationServiceZone entities + migrations (in `database/migrations/tenant/`) + Tunisia governorate seeder + ZoneResolverService.

**Phase 2 (Opus, ~3 PD):** ScoringStrategy interface + RoutingRuleSet/Rule/Decision entities + RuleEvaluator + OrderRoutingService. Architecture-heavy. Chunked Codex headless review.

**Phase 3 (Codex, ~2 PD):** Implement 4 scoring strategies (ZoneOnly, ZoneAndStock, ZoneStockAndCapacity, Composite).

**Phase 4 (Codex, ~1.5 PD):** Admin UI (ZoneManagerPage, RoutingRuleSetEditor, RoutingPreviewPage, RoutingDecisionsLogPage).

**Phase 5 (Codex, ~0.5 PD) — DEPENDS ON T3 Phase 2:** Integration with T3 `ChannelOrder` → routing → `Document`. If T3 lands late in Wave 1, this phase slips to follow-up sprint.

Adversarial review chunked Codex headless (small phases) or Opus headless (Phase 2 — larger).

---

## 12. Coordination notes

- **Depends on:** T6 Phase 0 (migration placement)
- **Phase 5 depends on:** T3 Phase 2 (`ChannelOrder` model + ingest service must exist)
- **Reads from:** T1 (StockReservation), T2 (variant-aware stock if merged)
- **Tunisia seeder:** explicitly opt-in per tenant; NOT in `TenantInitializationService` automatic flow
- **No Tauri POS changes**
- **No `tenant_id` FKs on routing tables** per topology contract
