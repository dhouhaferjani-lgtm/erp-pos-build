# Landed Cost & Margin Management - Session 1

> **Task:** TASK-LANDED-COST-MARGIN.md (Phase 3 Supplement)
> **Estimated:** 14-18 hours total
> **Session 1 Date:** December 2, 2025
> **Session 1 Duration:** ~1 hour
> **Branch:** feature/landed-cost-margin

---

## Session 1 Summary

**Progress:** Section 1 Complete (Database Schema)
**Completion:** ~10% of total task

### ✅ Completed This Session

#### Section 1: Database Schema (5/6 migrations)

**1.1 Company Settings** ✅
- Migration: `2025_12_02_064506_add_inventory_costing_settings_to_companies_table.php`
- Added fields:
  - `inventory_costing_method` (VARCHAR 20, default: 'weighted_average')
  - `default_target_margin` (DECIMAL 5,2, default: 30.00)
  - `default_minimum_margin` (DECIMAL 5,2, default: 10.00)
  - `allow_below_cost_sales` (BOOLEAN, default: false)
- Updated Company model with new fields in fillable and casts

**1.2 Category Margin Overrides** ⏭️
- **SKIPPED:** Categories table doesn't exist in current schema
- Will be implemented when product categories are added

**1.3 Product Cost & Margin Fields** ✅
- Migration: `2025_12_02_064541_add_cost_and_margin_fields_to_products_table.php`
- Added fields:
  - `cost_price` (DECIMAL 12,2, default: 0) - Weighted average cost
  - `target_margin_override` (DECIMAL 5,2, nullable)
  - `minimum_margin_override` (DECIMAL 5,2, nullable)
  - `last_purchase_cost` (DECIMAL 12,2, nullable)
  - `cost_updated_at` (TIMESTAMP, nullable)
- Updated Product model with new fields in fillable and casts

**1.4 Document Additional Costs** ✅
- Migration: `2025_12_02_064558_create_document_additional_costs_table.php`
- Created table with fields:
  - `id` (UUID primary key)
  - `document_id` (UUID, references documents)
  - `cost_type` (VARCHAR 50) - transport, shipping, insurance, customs, handling, other
  - `description` (VARCHAR nullable)
  - `amount` (DECIMAL 12,2)
  - `expense_document_id` (UUID nullable, references documents)
  - `created_at`, `updated_at`
- Foreign keys: document_id CASCADE, expense_document_id SET NULL
- Index on document_id

**1.5 Document Line Landed Cost** ✅
- Migration: `2025_12_02_064936_add_landed_cost_fields_to_document_lines_table.php`
- Added fields:
  - `allocated_costs` (DECIMAL 12,2, default: 0)
  - `landed_unit_cost` (DECIMAL 12,2, nullable)

**1.6 Stock Movement Cost Tracking** ✅
- Migration: `2025_12_02_065035_add_cost_tracking_to_stock_movements_table.php`
- Added fields:
  - `unit_cost` (DECIMAL 12,2, nullable)
  - `total_cost` (DECIMAL 12,2, nullable)
  - `avg_cost_before` (DECIMAL 12,2, nullable)
  - `avg_cost_after` (DECIMAL 12,2, nullable)

#### Migration Status
- All 5 migrations ran successfully
- `php artisan migrate` passed
- No errors

---

## Git Commits

**Commit 1:** `fa0dee3`
```
feat(pricing): add database schema for landed cost and margin management

Section 1 Complete (TASK-LANDED-COST-MARGIN.md):
- Add inventory costing settings to companies table
- Add cost and margin fields to products table
- Create document_additional_costs table for PO costs
- Add landed cost fields to document_lines table
- Add cost tracking to stock_movements table
- Update Product and Company models with new fields

Note: Category margin overrides skipped (categories table doesn't exist yet)
```

---

## Remaining Work

### Section 2: Backend Services (Pending)
- [ ] 2.1 LandedCostService
- [ ] 2.2 WeightedAverageCostService
- [ ] 2.3 MarginService

### Section 3: API Endpoints (Pending)
- [ ] 3.1 Purchase Order Additional Costs
- [ ] 3.2 Margin Check Endpoint
- [ ] 3.3 Product Cost Endpoints

### Section 4: Frontend Components (Pending)
- [ ] 4.1 AdditionalCostsForm
- [ ] 4.2 LandedCostBreakdown
- [ ] 4.3 MarginIndicator
- [ ] 4.4 PriceInputWithMargin
- [ ] 4.5 InvoiceLineRow update
- [ ] 4.6 ProductPricingCard
- [ ] 4.7 InventorySettings

### Section 5: Permissions (Pending)
- [ ] 5.1 New permissions added

### Section 6: Integration (Pending)
- [ ] 6.1 PO confirmation flow
- [ ] 6.2 Goods receipt flow
- [ ] 6.3 Invoice creation flow
- [ ] 6.4 Credit note flow

### Section 7: Testing (Pending)
- [ ] 7.1 Unit tests
- [ ] 7.2 Feature tests
- [ ] 7.3 E2E tests

---

## Models Pending Creation

- [ ] DocumentAdditionalCost.php
- [ ] Document model relationship to additionalCosts()

---

## Next Session Tasks

**Priority 1: Complete Model Layer**
1. Create DocumentAdditionalCost model
2. Add additionalCosts() relationship to Document model
3. Add company relationship if needed

**Priority 2: Section 2 - Backend Services**
1. Create LandedCostService with allocateCosts() method
2. Write unit tests for landed cost allocation
3. Create WeightedAverageCostService with recordPurchase() and recordSale()
4. Write unit tests for WAC calculations
5. Create MarginService with margin calculation and permission checks
6. Write unit tests for margin service

**Priority 3: Section 3 - API Endpoints**
1. DocumentAdditionalCostController
2. Add allocateCosts and landedCostBreakdown actions to DocumentController
3. Create PricingController with checkMargin action
4. ProductCostController with history and marginInfo
5. Write feature tests for all endpoints

---

## Technical Notes

### Design Decisions

1. **Weighted Average Cost (WAC)** chosen as costing method (not FIFO/LIFO)
   - Simpler to implement
   - Industry standard for automotive parts
   - Fair cost representation

2. **Margin Inheritance:** Product → Category → Company
   - Category skipped for now (table doesn't exist)
   - Will implement 2-tier: Product → Company
   - Can add category later without breaking changes

3. **Additional Costs Allocation:** Proportional by line value
   - Formula: `allocated = total_additional_costs × (line_total / po_subtotal)`
   - Landed unit cost = (line_total + allocated_costs) / quantity

4. **Permission-Based Pricing:**
   - Green (above target): No permission needed
   - Yellow (below target, above minimum): `sell_below_target_margin`
   - Orange (below minimum, above cost): `sell_below_minimum_margin`
   - Red (below cost): `sell_below_cost` + company setting `allow_below_cost_sales`

### Database Considerations

- All decimal fields use (12,2) for amounts
- Margin percentages use (5,2) to support up to 999.99%
- UUIDs for all primary and foreign keys
- Nullable cost fields to handle products without purchase history
- CASCADE delete for document_additional_costs
- SET NULL for expense_document_id reference

---

## Quality Gates Met

- [x] All migrations created and tested
- [x] Migrations run successfully
- [x] Models updated with new fields
- [ ] Tests not yet written (pending services)
- [ ] PHPStan not yet run (pending services)

---

## Blockers / Issues

**None** - Session 1 completed successfully

---

## Velocity

- **Time:** ~1 hour
- **Progress:** 10% (Section 1 of 7)
- **Estimate Accuracy:** On track (14-18h total, 1.5-2h per section)
- **Next Session Est:** 2-3 hours (Services + Models)

---

**Session 1 Status:** ✅ Complete
**Next Session:** Continue with DocumentAdditionalCost model and Section 2 (Backend Services)
