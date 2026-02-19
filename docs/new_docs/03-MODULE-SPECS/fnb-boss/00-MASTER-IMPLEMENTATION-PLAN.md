# F&B Boss - Master Implementation Plan

**Module:** Food & Beverage Vertical (IziPOS Café)
**Version:** 1.0
**Date:** January 2026
**Status:** Planning Phase
**Dependencies:** POS Module (Phase 0.5), Loyalty Module (In Progress)

---

## Executive Summary

This document outlines the complete implementation plan for the F&B Boss vertical, a specialized configuration of AutoERP for coffee shops, cafés, quick-service restaurants, and eventually full-service restaurants. The implementation builds upon the existing POS foundation while adding F&B-specific features like recipes, modifiers, ingredients, and consumption mode tracking.

### Key Objectives

1. **Menu Management:** Enable creation and management of menu items with recipes linking to ingredients
2. **Ingredient Inventory:** Track raw materials with optional automatic deduction
3. **Modifiers System:** Support complex item customization with price and recipe impacts
4. **F&B POS UI:** Specialized point-of-sale interface optimized for food service
5. **Compliance Ready:** Support consumption mode tracking for VAT compliance (France, etc.)
6. **Multi-Vertical:** Maintain compatibility with existing retail POS while adding F&B specifics

---

## Architecture Overview

### Module Structure

The F&B Boss vertical is **NOT a separate module** but rather an extension of existing modules:

```
app/Modules/
├── Product/                    # Extend for Ingredients
│   ├── Domain/
│   │   ├── Entities/
│   │   │   └── Ingredient.php  # New: Extends/wraps Product with F&B semantics
│   │   └── Services/
│   │       └── RecipeService.php
│   └── Application/
│       └── DTOs/
│           ├── IngredientData.php
│           └── RecipeData.php
│
├── Catalog/                    # Extend for Menu Items
│   ├── Domain/
│   │   ├── Entities/
│   │   │   ├── MenuItem.php    # New: F&B sellable item
│   │   │   ├── MenuCategory.php
│   │   │   ├── ModifierGroup.php
│   │   │   └── ModifierOption.php
│   │   └── ValueObjects/
│   │       ├── MenuItemSize.php
│   │       └── RecipeLine.php
│   └── Application/
│       └── DTOs/
│           ├── MenuItemData.php
│           ├── ModifierGroupData.php
│           └── ModifierOptionData.php
│
├── POS/                        # Extend for F&B UI
│   ├── Domain/
│   │   ├── Entities/
│   │   │   └── ConsumptionMode.php  # New enum
│   │   └── Services/
│   │       └── MenuItemPricingService.php
│   └── Presentation/
│       └── Controllers/
│           └── MenuItemController.php
│
└── Inventory/                  # Extend for recipe-based deduction
    └── Domain/
        └── Services/
            └── RecipeBasedStockAdjustmentService.php
```

### Data Model Philosophy

**Key Design Decision:** We leverage the existing `products` table infrastructure with a **type discriminator** pattern rather than creating entirely separate tables.

| Entity | Database Strategy | Rationale |
|--------|------------------|-----------|
| **Ingredient** | `products` table with `type = 'ingredient'` | Reuses inventory, pricing, UOM infrastructure |
| **MenuItem** | `products` table with `type = 'menu_item'` | Reuses SKU, tax, category, image storage |
| **Recipe** | New `recipes` + `recipe_lines` tables | Domain-specific, links menu items to ingredients |
| **Modifiers** | New `modifier_groups` + `modifier_options` | F&B-specific, no retail equivalent |

**Benefits:**
- Single inventory module handles both ingredients and finished goods
- Pricing rules work uniformly across entity types
- Tax configuration applies consistently
- Less code duplication

**Trade-offs:**
- More complex queries (must filter by type)
- Some columns may be unused for certain types
- Need clear documentation of which fields apply to which types

---

## Implementation Phases

### Phase 1: Data Model & Backend (Weeks 1-2)

**Goal:** Implement core F&B entities, relationships, and business logic

**Deliverables:**
- Database migrations for F&B tables
- Domain entities (Ingredient, MenuItem, Recipe, Modifier)
- Repository implementations
- Business services (RecipeService, ModifierService)
- API controllers with full CRUD
- Unit + integration tests

**See:** [01-PHASE-1-DATA-MODEL.md](./01-PHASE-1-DATA-MODEL.md)

---

### Phase 2: POS Integration (Weeks 3-4)

**Goal:** Build F&B-specific POS UI and integrate with backend

**Deliverables:**
- Menu category grid/tabs UI
- Item customization modal (size + modifiers)
- Recipe-aware cart with pricing
- Consumption mode toggle
- Receipt generation with modifiers
- Integration tests for POS flows

**See:** [02-PHASE-2-POS-INTEGRATION.md](./02-PHASE-2-POS-INTEGRATION.md)

---

### Phase 3: Advanced Features (Weeks 5-6)

**Goal:** Implement optional and compliance features

**Deliverables:**
- Automatic inventory deduction (optional feature flag)
- Consumption mode VAT calculation
- Restaurant voucher payment method
- Tenant label configuration
- COGS calculation and reporting
- Variance tracking (theoretical vs actual)

**See:** [03-PHASE-3-ADVANCED-FEATURES.md](./03-PHASE-3-ADVANCED-FEATURES.md)

---

### Phase 4: Loyalty Integration (Week 7)

**Goal:** Connect F&B POS to Core Loyalty module

**Deliverables:**
- MenuItem registration as loyaltyable entity
- Stamp card configuration for coffee shops
- Points earning on menu item purchases
- Reward redemption in F&B POS
- Loyalty transaction tracking

**See:** [04-PHASE-4-LOYALTY-INTEGRATION.md](./04-PHASE-4-LOYALTY-INTEGRATION.md)

---

## Technical Specifications

### Database Schema

**New Tables:**
1. `recipes` - Links menu items to ingredients
2. `recipe_lines` - Individual ingredient quantities
3. `modifier_groups` - Customization categories
4. `modifier_options` - Individual modifier choices
5. `menu_item_sizes` - Size variants with pricing
6. `menu_item_modifier_groups` - Many-to-many pivot

**Extended Tables:**
1. `products` - Add `type` discriminator column
2. `pos_receipt_lines` - Add `modifiers` JSONB column
3. `pos_receipts` - Add `consumption_mode` column

**See:** [05-DATABASE-SCHEMA.md](./05-DATABASE-SCHEMA.md)

---

## Testing Strategy

### Test Coverage Requirements

| Layer | Coverage Target | Test Types |
|-------|----------------|------------|
| **Domain Logic** | 90%+ | Unit tests for services, value objects |
| **Repositories** | 80%+ | Integration tests with test database |
| **API Endpoints** | 100% happy path | Feature tests for all CRUD operations |
| **POS Flows** | Critical paths | E2E tests with Playwright |

### Critical Test Scenarios

1. **Recipe Calculation:**
   - Size multiplier applies correctly
   - Modifiers add/subtract ingredients
   - COGS calculation matches expected values

2. **Inventory Deduction:**
   - Recipe-based stock reduction
   - Multi-ingredient transactions
   - Insufficient stock handling

3. **Pricing:**
   - Base price + size adjustment + modifiers
   - Tax application by consumption mode
   - Split payment with vouchers

4. **Modifier Validation:**
   - Required modifier enforcement
   - Min/max selection rules
   - Price adjustment calculation

---

## Quality Gates

### Before ANY commit:

```bash
# Backend
cd apps/api
composer test                    # PHPUnit
./vendor/bin/phpstan            # Level 8
./vendor/bin/pint               # Laravel Pint

# Frontend
cd apps/web
pnpm test                       # Vitest
pnpm typecheck                  # TypeScript strict
pnpm lint                       # ESLint
```

### Phase Completion Checklist

Before marking a phase complete:

- [ ] All acceptance criteria met
- [ ] Test coverage meets targets
- [ ] Documentation updated
- [ ] API endpoints documented
- [ ] Frontend types generated (`php artisan typescript:transform`)
- [ ] No PHPStan level 8 errors
- [ ] No TypeScript strict mode errors
- [ ] Migration rollback tested
- [ ] Code review completed

---

## Rollout Strategy

### Feature Flags

F&B-specific features are controlled by company configuration:

```php
// companies table
{
  "vertical": "cafe",  // Enables F&B UI
  "features": {
    "fnb_menu_management": true,
    "fnb_recipe_tracking": true,
    "fnb_auto_inventory": false,  // Optional, tenant choice
    "fnb_consumption_mode": true,
    "fnb_modifiers": true
  }
}
```

### Gradual Activation

1. **Alpha (Internal Testing):** Enable for test tenant only
2. **Beta (Pilot Customers):** 3-5 selected coffee shops
3. **General Availability:** All café vertical tenants
4. **Cross-Vertical:** Make modifiers available to retail if requested

---

## Risk Mitigation

### Technical Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Performance with complex recipes | Medium | High | Add recipe caching, optimize queries |
| Inventory deduction race conditions | High | Critical | Use pessimistic locking, transaction isolation |
| Modifier UI complexity | Medium | Medium | Prototype UI early, user testing |
| Product table bloat | Low | Medium | Add indexes, consider partitioning |

### Business Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Users find modifiers confusing | Medium | High | Comprehensive training, tooltips, defaults |
| Recipe management too complex | Medium | Medium | Provide templates, bulk import |
| Consumption mode not understood | Low | Medium | Clear UI labels, help text |

---

## Dependencies & Prerequisites

### Before Starting Phase 1

- ✅ POS module basic structure exists
- ✅ Product module supports variants
- ✅ Inventory module operational
- ✅ Tax configuration flexible
- ⏳ Loyalty module complete (not blocking for Phase 1-3)

### External Dependencies

- None (fully self-contained within AutoERP)

---

## Success Metrics

### MVP Success Criteria (End of Phase 3)

- [ ] Can create menu items with recipes
- [ ] Can configure modifiers with price adjustments
- [ ] Can sell menu items via F&B POS
- [ ] Inventory deducts correctly (when enabled)
- [ ] Receipts show modifiers clearly
- [ ] Consumption mode affects pricing (France tenants)
- [ ] 90%+ test coverage on domain logic

### Production Readiness Criteria (End of Phase 4)

- [ ] 3 pilot coffee shops using system daily
- [ ] Average transaction time < 30 seconds
- [ ] Zero inventory discrepancies over 2 weeks
- [ ] Loyalty integration working smoothly
- [ ] Positive user feedback from pilots
- [ ] Documentation complete (user guide + API docs)

---

## Timeline

**Estimated Total Duration:** 7 weeks (140 hours)

| Phase | Duration | Start | End | Dependencies |
|-------|----------|-------|-----|--------------|
| Phase 1: Data Model | 2 weeks | Week 1 | Week 2 | None |
| Phase 2: POS Integration | 2 weeks | Week 3 | Week 4 | Phase 1 complete |
| Phase 3: Advanced Features | 2 weeks | Week 5 | Week 6 | Phase 2 complete |
| Phase 4: Loyalty Integration | 1 week | Week 7 | Week 7 | Phase 3 + Loyalty module |

**Note:** Timeline assumes one developer working full-time. Adjust proportionally for part-time or multiple developers.

---

## Open Questions & Decisions Needed

| # | Question | Status | Decision Owner |
|---|----------|--------|----------------|
| 1 | Should ingredients and menu items share `products` table or be separate? | ✅ DECIDED: Shared with type discriminator | Architecture Team |
| 2 | Support multi-company sharing of recipes (chain franchises)? | 🔄 DISCUSS | Product Team |
| 3 | Allow modifiers to affect loyalty points earning? | 🔄 DISCUSS | Product Team |
| 4 | Enable kitchen display system (KDS) in MVP or defer? | 🔄 DISCUSS | Product Team |
| 5 | Support table management in Phase 5 or separate project? | ⏳ DEFER | Product Team |

---

## Document Change Log

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | 2026-01-09 | Claude | Initial master plan created |

---

## Related Documents

- [IziPOS Café - Functional Requirements Document](../izipos-cafe-mvp-frd.md)
- [POS Database Schema](../pos-database-schema.md)
- [Core Loyalty Module FRD](../core-loyalty-module-frd.md)
- [Web POS Technical Architecture](../web-pos-technical-architecture.md)

---

*Next Steps: Review this master plan, then proceed to Phase 1 detailed planning.*
