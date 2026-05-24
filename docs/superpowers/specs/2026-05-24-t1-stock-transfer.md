# T1 — Stock Transfer Module

**Track:** T1 (P0 sprint — Wave 1 server side, Wave 2 POS deltas)
**Date:** 2026-05-24 (v2 after Codex round-1 review)
**Recommended workflow:** Opus for Scenario B (inter-company auto sales/purchase pair) + Codex for Scenario A mechanics + per-location tax_id migration. Adversarial review: Codex headless on Scenario A; Opus headless on Scenario B (larger chunk).
**Estimated effort:** ~12 PD
**Roadmap reference:** [2026-05-24-productization-sprint-roadmap.md](../coordination/2026-05-24-productization-sprint-roadmap.md)
**Constitutional reference:** [2026-05-24-migration-topology-contract.md](../coordination/2026-05-24-migration-topology-contract.md)

---

## 1. Purpose

Stock movement between locations is a core retail operation. Today:

- A basic atomic transfer exists between two locations under the same company (`StockAdjustmentService::transfer`)
- **Batch identity is not preserved** during transfers — verified via Codex review: `stock_movements` has NO `batch_id` column; batch↔movement linkage lives in the `inventory_batch_movements` join table (`batch_id` + `movement_id`). The current `transfer()` does not create `inventory_batch_movements` rows for the paired TransferOut/TransferIn movements.
- **Inter-company transfers** (Scenario B — between separate legal companies under same tenant) do not exist
- **Per-location fiscal/tax IDs** (Tunisian branch numbering `1234567/A/B/001`) are not supported — `locations` table has `receipt_header` and `receipt_footer` but no `tax_id` or `branch_code` field
- **Transfer lifecycle tracking** (in-transit visibility, transfer-cost-impacting WAC, configurable in-transit availability semantics) is absent

This module ships generic across both scenarios for all SaaS tenants.

---

## 2. Architecture grounding (verified file paths)

Read in this order:

1. `apps/erp/apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php` (lines 196–308: `transfer()` method)
2. `apps/erp/apps/api/app/Modules/Inventory/Domain/StockMovement.php` (lines 50–75: fillable confirms NO `batch_id` column)
3. `apps/erp/apps/api/database/migrations/2026_01_05_150002_create_inventory_batch_movements_table.php` (lines 14–33: `batch_id` + `movement_id` join — THIS is the batch↔movement linkage we must populate during transfers)
4. `apps/erp/apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php` (lines 1–289: receipt + WAC + batch pattern — the existing receive path already uses `inventory_batch_movements`)
5. `apps/erp/apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php` (lines 150–206: transfer endpoint)
6. `apps/erp/apps/api/app/Modules/Inventory/Domain/StockReservation.php` (reservation model)
7. `apps/erp/apps/api/app/Modules/Inventory/Domain/Events/StockMovementRecorded.php` (event we emit)
8. `apps/erp/apps/api/app/Modules/Company/Domain/Location.php` (lines 22–88: company-scoped, NO `tenant_id` FK — Location is scoped via its parent Company; per topology contract, intra-tenant FKs OK)
9. `apps/erp/apps/api/database/migrations/2025_11_30_105000_create_locations_table.php` (locations baseline)
10. `apps/erp/apps/api/app/Modules/Company/Domain/Company.php` (multi-company schema — `parent_company_id`, `legal_identifiers`, `is_headquarters`)
11. `apps/erp/apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php` (batch issue/receive pattern to mirror — already uses `inventory_batch_movements`)
12. `apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php` (FEFO logic — must be respected on Scenario A transfers)
13. `apps/erp/apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php` (journal entry auto-posting pattern — needed for Scenario B)
14. `apps/erp/apps/api/app/Modules/Document/Domain/Document.php` (Document model — Scenario B creates Invoice + PO documents)
15. `apps/erp/apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php` (lines 831–883: current POS server-side stock availability check — must integrate `InTransitAvailability` setting here for P1-5 fix)
16. `apps/erp/apps/pos/src/stores/cartStore.ts` (lines 142–191: POS client-side cart state using cached `product.stock_quantity`)

**Patterns to mirror:**

- Atomic operation: `StockAdjustmentService::transfer()` uses `DB::transaction()` — extend to also create `inventory_batch_movements` rows
- Event emission: `DB::afterCommit(fn() => event(new StockMovementRecorded(...)))` — preserve, add new events
- Batch preservation pattern: `BatchStockService::issueBatchStock()` + `receiveBatchStock()` — both already call into `inventory_batch_movements`
- Company hierarchy: `Company.parent_company_id` for chain structures. Scenario A: same `company_id` for from/to. Scenario B: different `company_id` (both validated to belong to same tenant — and per topology contract, both live in the same tenant DB so FK works)

**Constraints from CLAUDE.md:** hexagonal, constructor injection, strict typing, enums, TDD, PHPStan level 8.
**Migration placement:** all T1 migrations → `database/migrations/tenant/` per topology contract.
**Cross-DB FK:** none — intra-tenant only. Scenario B both companies live in same tenant DB.

---

## 3. Domain model

### New entities (all in tenant DB per topology contract)

**`StockTransfer`** (aggregate root)
- `id` (UUID)
- `transfer_number` (string, generated, unique per tenant)
- `transfer_type` enum: `Intracompany` | `Intercompany`
- `from_location_id` (FK Location within tenant DB)
- `to_location_id` (FK Location within tenant DB)
- `from_company_id` (FK Company within tenant DB)
- `to_company_id` (FK Company — same as from for Intracompany)
- `status` enum: `Draft` | `InTransit` | `Completed` | `Cancelled`
- `initiated_by` (FK User within tenant DB)
- `initiated_at` (timestamp)
- `completed_at` (timestamp, nullable)
- `reference` (string, nullable — user note)
- `transfer_cost` (decimal, nullable — additional cost like delivery fees)
- `transfer_cost_distribution` enum: `ProRataValue` | `ProRataQuantity` | `EqualPerLine`
- `idempotency_key` (string, unique per tenant — for retry safety on completion)
- For Scenario B: `sales_document_id`, `purchase_document_id`, `internal_pricing_strategy_id` (FK)
- Timestamps + soft-deletes

**`StockTransferLine`**
- `id`, `transfer_id` (FK), `product_id` (FK Product), `variant_id` (FK ProductVariant, nullable — T2 dep), `batch_id` (FK product_batches, nullable), `quantity` (decimal), `unit_cost_snapshot` (decimal — frozen at initiation), `allocated_transfer_cost` (decimal — computed from distribution)

**`InterCompanyPricingStrategy`** (per-tenant config)
- `id`, `name`, `default_strategy` enum: `AverageWeightedCost` | `FixedMarkup` | `FixedDiscount` | `Custom`, `markup_percent`, `discount_percent`
- Per-tenant default for Scenario B; overridable per transfer

### Schema changes to existing tenant tables

- `locations`: ADD `tax_id` (string, nullable, indexed), `branch_code` (string, nullable — e.g., "001"), `legal_name` (string, nullable — for printed docs when different from company legal name)
- `stock_movements`: ADD `transfer_id` (FK nullable — links movement to its StockTransfer aggregate)
  - **Note:** we do NOT add `batch_id` to `stock_movements`. Batch linkage stays in `inventory_batch_movements` per existing design.
- `pos_receipts`: no schema change; printing layer surfaces `location.tax_id` and `location.branch_code` (Wave 2 POS delta)

### Enums

- `TransferType` (Intracompany, Intercompany)
- `TransferStatus` (Draft, InTransit, Completed, Cancelled)
- `TransferCostDistribution` (ProRataValue, ProRataQuantity, EqualPerLine)
- `InterCompanyPricingMethod` (AverageWeightedCost, FixedMarkup, FixedDiscount, Custom)
- `InTransitAvailability` (per-COMPANY setting: `Available`, `Pending`, `NotAvailable`) — controls whether in-transit stock is sellable. **Storage (resolves round-2 P2-1):** stored as JSONB key on the existing `companies.reservation_settings` column (already exists per `apps/erp/apps/api/app/Modules/Company/Domain/Company.php:506-518` — proven pattern for company-scoped settings). Per-company because each legal entity may want different availability semantics; super-admin can configure per company. Avoids needing a new `tenant_settings` table that would itself be a cross-DB reference post-T6.

---

## 4. Public contracts

### Application services

```php
StockTransferService
  ::initiate(InitiateTransferCommand): StockTransfer
  ::complete(UUID $transferId, User $completedBy): StockTransfer  // idempotent via idempotency_key
  ::cancel(UUID $transferId, User $cancelledBy, string $reason): StockTransfer
  ::calculateTransferCostImpact(StockTransfer): WACImpactDTO

InTransitAvailabilityService  // per-company scope (locked per round-3 P1-2)
  ::current(UUID $companyId): InTransitAvailability
  ::set(UUID $companyId, InTransitAvailability $value, User $user): void

AvailableQuantityService  // consumed by POS ReceiptCreationService + cart logic
  ::availableForSale(UUID $productId, ?UUID $variantId, UUID $locationId): decimal
  // resolves company from location → honors that company's InTransitAvailability setting
```

### Events emitted

- `StockTransferInitiated` (transfer_id, tenant_id, from_location_id, to_location_id, lines, transfer_type)
- `StockTransferCompleted` (transfer_id, tenant_id, completed_at)
- `StockTransferCancelled` (transfer_id, tenant_id, reason)
- `InterCompanyTransferPosted` (transfer_id, sales_document_id, purchase_document_id) — Scenario B only [naming unified per Codex P3-1 finding]
- `StockMovementRecorded` (existing — emit one per leg)

### REST endpoints

- `POST /api/v1/stock-transfers` — initiate
- `POST /api/v1/stock-transfers/{id}/complete` — confirm receipt at destination (idempotent via idempotency_key in body)
- `POST /api/v1/stock-transfers/{id}/cancel`
- `GET /api/v1/stock-transfers` — list with filters (status, location, date range, transfer_type)
- `GET /api/v1/stock-transfers/{id}` — detail with lines + linked documents
- `GET /api/v1/inter-company-pricing-strategies` — list per tenant
- `PUT /api/v1/inter-company-pricing-strategies/{id}` — update
- `GET /api/v1/companies/{companyId}/settings/in-transit-availability` — current setting (per-company per round-3 P1-2)
- `PUT /api/v1/companies/{companyId}/settings/in-transit-availability` — update

---

## 5. User-visible surface

### Admin UI (React, `apps/web/src/features/inventory/transfers/`)

- **TransferListPage** — list with filters, status badges
- **TransferCreatePage** — start transfer; if user has multi-company access, source/destination company pickers (only companies user is permitted on)
- **TransferDetailPage** — lifecycle view; for Scenario B, link to generated sales + purchase docs
- **TransferReceiptPage** — destination user confirms receipt; flag discrepancies

### Super-admin / multi-company dashboard

- **InterCompanyBalanceDashboard** — per-tenant view of inter-company AR/AP, VAT settlements pending, transfer activity

### Settings UI

- **InterCompanyPricingStrategyPage** — per-tenant CRUD
- **LocationSettingsPage** — extend with `tax_id`, `branch_code`, `legal_name` + preview on receipts
- **InTransitAvailabilitySettingsPage** — per-COMPANY toggle: Available / Pending / NotAvailable (scope corrected per round-3 P1-2; super-admin can configure per company; if tenant has multiple companies, each can differ)

### POS (Wave 2 deltas — log to `apps/erp/docs/superpowers/coordination/2026-05-24-pos-coordination-log.md`)

- Receipt template surfaces `location.tax_id` and `location.branch_code` when set
- `InTransitAvailability` rendering on product cards (shows "Available with notice" / "Pending" / "Not available" based on per-company setting + active in-transit transfers)
- Offline mode: cached `available_quantity` includes in-transit adjustment per company setting (snapshot at sync time)
- Server-side enforcement: `ReceiptCreationService` consumes `AvailableQuantityService::availableForSale` (which honors the setting) — if `NotAvailable` and product is in transit, sale blocked with clear error message

---

## 6. Generic-ness checklist

- [ ] Zero client names in code/config
- [ ] Per-location tax_id is generic — works for TN branch numbering, FR SIRET-par-établissement, MA ICE, or any other scheme
- [ ] Inter-company pricing strategies are config, not code
- [ ] In-transit availability is a per-COMPANY setting (Available/Pending/NotAvailable; scope locked per round-3 P1-2)
- [ ] Transfer cost distribution configurable per transfer (default per tenant)
- [ ] All migrations in `database/migrations/tenant/`
- [ ] No cross-DB FKs (Scenario B intercompany is intra-tenant — both companies in same tenant DB)
- [ ] Works across all verticals already supported in `apps/erp/apps/api/config/verticals.php`

---

## 7. Acceptance criteria

### Scenario A (intra-company)

- [ ] Initiate transfer of 10 units of Product P from Location A to B (same company); stock at A decremented, in-transit shows 10
- [ ] Complete the transfer; stock at B incremented, in-transit clears
- [ ] If Product P has batch tracking, batch identity is preserved end-to-end (verify by inspecting `inventory_batch_movements` rows linked to BOTH the TransferOut and TransferIn `stock_movements` records — verify batch_id matches on both rows)
- [ ] If transfer has additional cost of 50 TND distributed pro-rata-by-value, WAC at destination reflects the cost addition
- [ ] No sales/purchase documents created
- [ ] Events emitted: `StockTransferInitiated`, `StockMovementRecorded` (×2), `StockTransferCompleted`
- [ ] When tenant's `InTransitAvailability=Available`: POS sale at Location A during in-transit succeeds with notice
- [ ] When `InTransitAvailability=NotAvailable`: same POS sale is blocked with clear error
- [ ] **Idempotency:** retrying `complete` with the same idempotency_key produces no duplicate movements or events

### Scenario B (inter-company, intra-tenant)

- [ ] Initiate transfer of 10 units of Product P from Company X / Location A to Company Y / Location B (same tenant)
- [ ] Auto-creates sales document at X (`Document.type=Invoice`, internal-transfer flag) and purchase document at Y (`Document.type=PurchaseOrder` confirmed)
- [ ] Pricing applies tenant's default `InterCompanyPricingStrategy` (default: `AverageWeightedCost`)
- [ ] WAC at Y reflects inter-company price + allocated transfer cost
- [ ] Journal entries auto-post on both sides (X: Dr AR / Cr Revenue; Y: Dr Inventory / Cr AP)
- [ ] VAT applied per company's country settings
- [ ] User UX: same "I'm transferring" experience as A; sales/purchase docs generated transparently
- [ ] User with permissions on Company X only sees X as source; destination dropdown shows only permitted companies
- [ ] Super-admin dashboard shows inter-company balance impact
- [ ] **Idempotency:** retrying completion is safe (events not duplicated, journal entries not duplicated)

### Per-location tax ID

- [ ] Locations can be created with `tax_id` + `branch_code`
- [ ] POS receipts print location's `tax_id` + `branch_code` when set (fallback to company-level when null) — Wave 2 delta
- [ ] Invoice PDFs include location's `tax_id` when set

### Batch preservation fix (resolves Codex B-1)

- [ ] `StockAdjustmentService::transfer()` (refactored to delegate to `StockTransferService` or extended in place) creates `inventory_batch_movements` rows for both TransferOut + TransferIn movements when the source line has batch tracking
- [ ] E2E integration test: PO receipt creates batch → transfer between locations → sale at destination → verify `inventory_batch_movements` rows exist for receipt, transfer-out, transfer-in, sale movements; all reference the same batch_id
- [ ] Existing batch tests continue to pass; new tests cover transfer-with-batch + FEFO at destination

### Tests

- [ ] Feature test per scenario (Intracompany + Intercompany happy paths)
- [ ] Feature test per scenario for cancellation
- [ ] Feature test for completion idempotency (retry-safe)
- [ ] Feature test: tenant isolation (transfer between tenants impossible)
- [ ] Feature test: cross-company permission enforcement
- [ ] Unit tests for WAC calculation per `TransferCostDistribution` strategy
- [ ] Unit tests per `InterCompanyPricingMethod`

---

## 8. Adversarial review checklist

**Reviewer instruction (mandatory):** *"Verify findings against actual code at cited paths. Read the files. Do not make assumptions. Pay special attention to: (1) batch preservation — the spec says use `inventory_batch_movements`, NOT a `stock_movements.batch_id` column; verify the implementation matches; (2) idempotency — `complete` and `cancel` operations must be safe to retry with the same idempotency_key; (3) Scenario B journal entries integrate with the existing hash chain via `GeneralLedgerHashService`; (4) Scenario B FKs are intra-tenant only (both companies in same tenant DB per topology contract — no cross-DB FK)."*

- [ ] **Batch preservation pattern:** implementation populates `inventory_batch_movements` rows for transfer movements; does NOT add a `batch_id` column to `stock_movements`
- [ ] **Idempotency:** `complete` and `cancel` operations check `idempotency_key`; retry produces no duplicates (movements, journal entries, events, generated documents)
- [ ] **Tenant isolation:** transfer service rejects requests touching locations/companies in another tenant; trust DB boundary + service-layer validation
- [ ] **Hash chain integration:** Scenario B journal entries integrate with `GeneralLedgerHashService`
- [ ] **Backward compat:** existing `StockAdjustmentService::transfer()` callers continue to work (grep for callsites, list each, verify)
- [ ] **Permission gates:** every new endpoint behind RBAC; user with `transfer.create` on Company X cannot initiate on Company Y
- [ ] **Migration safety:** `tax_id` + `branch_code` are nullable; existing locations work without them; existing receipts/invoices fall back to company-level
- [ ] **WAC correctness:** verify by hand on 3 worst-case examples (single line, multi-line different units, transfer cost distribution math)
- [ ] **FEFO preservation:** Scenario A transfer of batch-tracked product maintains FEFO at destination
- [ ] **Migration placement:** all T1 migrations in `database/migrations/tenant/`; no cross-DB FKs
- [ ] **Spec drift:** for every cited file path, confirm the file still says what the spec claims
- [ ] **POS in-transit enforcement:** `ReceiptCreationService` actually consumes `AvailableQuantityService` (the P1-5 fix); server-side blocks sale when setting=NotAvailable and product is in transit
- [ ] **Event naming:** `InterCompanyTransferPosted` used consistently (no `InterCompanyDocumentsPosted` stragglers per P3-1 finding)

---

## 9. Out of scope

- Multi-step approval workflow (manager pre-approves transfer-out, destination manager pre-approves transfer-in)
- Cross-tenant transfers (would break multi-tenant model — not supported by design; would also violate topology contract)
- Transfer scheduling / recurring transfers
- Pick-list / packing-list workflow with mobile scanner
- Customs/import documentation for cross-border transfers
- Variant-aware transfer UI/data model — **dependency on T2**; spec includes `variant_id` nullable column, UI added as part of T2 integration

---

## 10. Reading order

1. This spec
2. Migration topology contract
3. `apps/erp/CLAUDE.md`, `claude/architecture.md`, `claude/database-topology.md`
4. The 16 file paths in Section 2
5. Memory: `project_monetary_precision.md` (critical for WAC math)
6. Memory: `feedback_sweep_audit_trail_anchoring.md` (relevant for adversarial reviews)
7. Existing tests at `apps/erp/apps/api/tests/Feature/Inventory/` to mirror style

---

## 11. Workflow recommendation

**Phase 1 (Codex, ~2 PD):** Per-location tax_id migration (`tax_id`, `branch_code`, `legal_name` columns) + Location form/UI updates + batch preservation fix. **Batch preservation implementation note (resolves round-2 P1-1):** the existing `BatchStockService::transferBatchStock()` at `apps/erp/apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:130-169` does NOT currently accept movement IDs and cannot create `inventory_batch_movements` rows with the required non-null `movement_id` FK. The implementation MUST extend `BatchStockService::transferBatchStock()` to accept `$sourceMovementId` and `$destMovementId` parameters, and have `StockAdjustmentService::transfer()` (or the new `StockTransferService` in Phase 2) call it AFTER creating both movements. **Do NOT duplicate batch-stock bookkeeping in a second private helper** — there's already one in `StockAdjustmentService::recordBatchMovement` (private) and one in `BatchStockService`; we want a single source of truth. Chunked review by Opus headless.

**Legacy endpoint disposition (resolves round-2 P1-2):** the existing `POST /api/v1/stock-movements/transfer` endpoint at `StockMovementController:180` is PRESERVED AS-IS — no batch_id parameter added. Simple intra-location moves without batch tracking continue to use it unchanged. All batch-preserving transfers route through the new `POST /api/v1/stock-transfers` workflow (Phase 2). This avoids a dual-entry-point ambiguity.

**Phase 2 (Codex, ~3 PD):** Scenario A — `StockTransferService::initiate/complete/cancel` + REST endpoints + admin UI list/detail/receipt pages + `InTransitAvailability` setting service + `AvailableQuantityService` consumed by POS server flow.

**Phase 3 (Opus, ~5 PD):** Scenario B — auto sales/purchase document creation + journal entry posting + inter-company pricing strategies + super-admin dashboard. Architecture-heavy. Chunked review by Codex headless in 2–3 chunks.

**Phase 4 (Codex, ~2 PD):** Tests (idempotency, tenant isolation, permission, WAC correctness, FEFO preservation) + i18n + docs.

**Wave 2 POS deltas** logged to `apps/erp/docs/superpowers/coordination/2026-05-24-pos-coordination-log.md`:
- Receipt template tax_id + branch_code rendering
- InTransitAvailability POS rendering + offline-mode behavior

---

## 12. Coordination notes

- **Depends on:** T6 Phase 0 (migration placement)
- **References:** T2 (`variant_id` column on `StockTransferLine` is T2-owned)
- **Wave 2 POS deltas:** logged for fiscal session coordination — DO NOT modify Tauri code directly
- **Naming consistency:** `InterCompanyTransferPosted` is the canonical event name (replaces inconsistent `InterCompanyDocumentsPosted` per Codex P3-1)
