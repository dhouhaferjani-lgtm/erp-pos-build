# F&B Boss - Implementation Documentation

**Food & Beverage Vertical for AutoERP**

This folder contains complete implementation documentation for the F&B Boss vertical, a specialized POS configuration for coffee shops, cafés, quick-service restaurants, and eventually full-service restaurants.

---

## Quick Navigation

### 📋 Start Here
- **[00-MASTER-IMPLEMENTATION-PLAN.md](./00-MASTER-IMPLEMENTATION-PLAN.md)**
  - Executive summary and architecture overview
  - Timeline and milestones
  - Risk mitigation strategies
  - Success metrics

### 📅 Phase-by-Phase Plans

#### Phase 1: Data Model & Backend (Weeks 1-2)
- **[01-PHASE-1-DATA-MODEL.md](./01-PHASE-1-DATA-MODEL.md)**
  - Database design decisions (shared vs separate tables)
  - Domain entity specifications
  - Repository implementations
  - Application services
  - API controllers
  - Testing strategy

**Deliverables:** Complete backend with 80+ tests, 40+ API endpoints

---

#### Phase 2: POS Integration (Weeks 3-4)
- **[02-PHASE-2-POS-INTEGRATION.md](./02-PHASE-2-POS-INTEGRATION.md)**
  - F&B POS UI design
  - Component architecture
  - Modifier selection flow
  - Cart management
  - Receipt generation
  - E2E testing

**Deliverables:** Fully functional F&B POS with 40+ component tests

---

#### Phase 3: Advanced Features (Weeks 5-6)
- **[03-PHASE-3-ADVANCED-FEATURES.md](./03-PHASE-3-ADVANCED-FEATURES.md)**
  - Automatic inventory deduction (optional)
  - Consumption mode VAT calculation
  - Restaurant voucher support
  - Tenant label configuration
  - COGS calculation & variance reporting
  - Performance optimizations

**Deliverables:** Production-ready advanced features with 30+ tests

---

#### Phase 4: Loyalty Integration (Week 7)
- **[04-PHASE-4-LOYALTY-INTEGRATION.md](./04-PHASE-4-LOYALTY-INTEGRATION.md)**
  - MenuItem as loyaltyable entity
  - Coffee stamp card programs
  - Points-based loyalty
  - POS loyalty integration
  - Receipt loyalty details

**Deliverables:** Complete loyalty integration with 20+ tests

---

### 🗄️ Database Specifications
- **[05-DATABASE-MIGRATIONS.md](./05-DATABASE-MIGRATIONS.md)**
  - All 13 migration specifications
  - Execution order and dependencies
  - Rollback procedures
  - Testing queries
  - Performance considerations

---

### ✅ Implementation Guide
- **[99-IMPLEMENTATION-CHECKLIST.md](./99-IMPLEMENTATION-CHECKLIST.md)**
  - Day-by-day implementation checklist
  - Quality gates for each phase
  - Testing requirements
  - Deployment procedures
  - Success metrics

---

## Document Reading Order

### For Product/Business Stakeholders:
1. Start with [00-MASTER-IMPLEMENTATION-PLAN.md](./00-MASTER-IMPLEMENTATION-PLAN.md) (sections 1-3)
2. Review success metrics and timeline
3. Read "Rollout Strategy" section
4. Optional: Skim phase documents for feature details

### For Developers:
1. Read [00-MASTER-IMPLEMENTATION-PLAN.md](./00-MASTER-IMPLEMENTATION-PLAN.md) completely
2. Study [01-PHASE-1-DATA-MODEL.md](./01-PHASE-1-DATA-MODEL.md) thoroughly
3. Review [05-DATABASE-MIGRATIONS.md](./05-DATABASE-MIGRATIONS.md)
4. Follow [99-IMPLEMENTATION-CHECKLIST.md](./99-IMPLEMENTATION-CHECKLIST.md) day-by-day
5. Reference phase documents as you implement each phase

### For QA/Testing:
1. Read [00-MASTER-IMPLEMENTATION-PLAN.md](./00-MASTER-IMPLEMENTATION-PLAN.md) (overview)
2. Focus on "Testing Strategy" sections in each phase document
3. Use [99-IMPLEMENTATION-CHECKLIST.md](./99-IMPLEMENTATION-CHECKLIST.md) as test plan
4. Pay special attention to quality gates

---

## Key Concepts

### Product Type Discriminator
F&B Boss leverages the existing `products` table with a type discriminator:
- `'ingredient'` - Raw materials (coffee beans, milk, cups)
- `'menu_item'` - Sellable items (Cappuccino, Croissant)
- `'standard'` - Regular retail products
- `'service'` - Automotive services

This allows unified inventory, pricing, and tax handling across all product types.

---

### Recipe System
Recipes link menu items to their ingredient composition:
```
Menu Item (Cappuccino)
└── Recipe (v1)
    ├── 14g Espresso Coffee Beans
    ├── 150ml Whole Milk
    ├── 1× Paper Cup (size-appropriate)
    └── 1× Lid
```

**Features:**
- Size multipliers (Large = 1.5× base recipe)
- Modifier effects (Oat Milk substitutes Whole Milk)
- Cost calculation (sum of ingredient costs)
- Versioning (track recipe changes over time)

---

### Modifier System
Modifiers allow item customization with complex rules:

**Modifier Group:** "Milk Type"
- **Selection Type:** SINGLE (radio button)
- **Is Required:** Yes
- **Options:**
  - Whole Milk (default, €0.00)
  - Skim Milk (€0.00)
  - Oat Milk (+€0.50, substitutes ingredient)
  - Almond Milk (+€0.50, substitutes ingredient)

**Modifier Group:** "Extras"
- **Selection Type:** MULTIPLE (checkbox)
- **Max Selections:** 3
- **Options:**
  - Extra Shot (+€0.80, adds ingredient)
  - Vanilla Syrup (+€0.50, adds ingredient)
  - Caramel Syrup (+€0.50, adds ingredient)

---

### Consumption Mode
Tracks whether order is dine-in or takeaway:
- **Sur Place** (🪑 Dine-in)
- **À Emporter** (🥡 Takeaway)

**Impact:**
- VAT rates in France (10% vs 5.5% for beverages)
- Analytics (understand customer behavior)
- Receipt customization

---

## Implementation Timeline

```
Week 1-2:  Phase 1 - Data Model & Backend
Week 3-4:  Phase 2 - POS Integration
Week 5-6:  Phase 3 - Advanced Features
Week 7:    Phase 4 - Loyalty Integration
Week 8-9:  Testing & Bug Fixes
Week 10:   Pilot Deployment (2-3 cafés)
Week 11:   Monitoring & Iteration
Week 12:   General Availability
```

**Total Duration:** 12 weeks from start to GA

---

## Technology Stack

**Backend:**
- Laravel 12.x (PHP 8.3+)
- PostgreSQL 16+
- Redis (caching + queue)
- PHPStan level 8

**Frontend:**
- React 18+ with TypeScript strict mode
- TanStack Query (server state)
- Zustand (client state)
- Tailwind CSS 4+
- Vitest (testing)

**Testing:**
- PHPUnit (backend unit + feature tests)
- Playwright (E2E tests)
- Target: 90%+ coverage on domain layer

---

## Dependencies

### Required Modules (Must Exist):
- ✅ Product module (for `products` table)
- ✅ Inventory module (for stock tracking)
- ✅ Catalog module (for categories)
- ✅ POS module (basic structure at Phase 0.5+)
- ✅ Taxation module (for tax calculations)
- ✅ Treasury module (for payment methods)

### Optional Modules:
- ⏳ Loyalty module (for Phase 4 only)

### External Dependencies:
- None (fully self-contained within AutoERP)

---

## Architecture Principles

### 1. Convention Over Configuration
Follow CLAUDE.md conventions strictly:
- No placeholder code
- Test-first development (TDD)
- Strict typing (no `mixed` or `any`)
- Constructor injection only
- No hardcoded strings in frontend

### 2. Module Boundaries
Cross-module communication via:
- Interfaces in `Shared/Contracts/`
- Events (for async communication)
- Public Service classes

**Forbidden:** Direct model imports across modules

### 3. Event Sourcing (Where Applicable)
- Recipe changes → RecipeVersioned event
- Menu item sold → MenuItemSold event
- Inventory deducted → IngredientStockAdjusted event

### 4. Domain-Driven Design
- Business logic in Domain layer
- Controllers are thin (validate → dispatch → respond)
- Use Value Objects for complex types
- Collections for entity groups

---

## Success Metrics

### Technical Metrics
- **Test Coverage:** > 90% on domain layer
- **Performance:** Menu load < 500ms, Receipt creation < 1s
- **Code Quality:** PHPStan level 8, TypeScript strict mode
- **API Response Time:** p95 < 200ms
- **Database Query Performance:** No N+1 queries

### Business Metrics
- **Pilot Phase (Week 10):**
  - 100% of pilot cafés using daily
  - Average transaction time < 45 seconds
  - User satisfaction > 4/5

- **Month 1:**
  - 20+ cafés onboarded
  - 1000+ receipts created
  - Inventory variance < 5% (if auto-deduction enabled)

- **Month 3:**
  - 100+ cafés using F&B Boss
  - Feature adoption > 80%
  - Positive customer ROI

---

## Related Documents

### Project Root
- [CLAUDE.md](./CLAUDE.md) - Master architecture & conventions
- [TASKS.md](./TASKS.md) - Current sprint tasks

### Specifications
- [../izipos-cafe-mvp-frd.md](../izipos-cafe-mvp-frd.md) - Original functional requirements
- [../pos-database-schema.md](../pos-database-schema.md) - Generic POS schema
- [../core-loyalty-module-frd.md](../core-loyalty-module-frd.md) - Loyalty module spec

### Conventions
- [/docs/conventions/README.md](./docs/conventions/README.md) - Conventions index
- [/docs/conventions/01-API-RESPONSES.md](./docs/conventions/01-API-RESPONSES.md) - API patterns
- [/docs/conventions/07-DEPENDENCY-INJECTION.md](./docs/conventions/07-DEPENDENCY-INJECTION.md) - DI patterns

---

## Frequently Asked Questions

### Q: Why not create a separate `menu_items` table?
**A:** Using the existing `products` table with a type discriminator allows us to reuse existing infrastructure for inventory, pricing, tax, images, and more. This reduces code duplication and keeps the system simpler.

### Q: What if a tenant doesn't want automatic inventory deduction?
**A:** Auto-deduction is an **optional feature flag** (`fnb_auto_inventory`). Tenants can enable/disable it per company. When disabled, recipes are still used for COGS calculation and variance reporting, but inventory isn't automatically adjusted.

### Q: How do we handle menu item pricing with modifiers?
**A:** Final price = Base Price + Size Adjustment + Sum(Modifier Adjustments). This is calculated by `MenuItemPricingService` and validated before adding to cart.

### Q: Can modifiers affect inventory?
**A:** Yes! Modifiers can:
- **ADD** ingredients (Extra Shot adds espresso beans)
- **REMOVE** ingredients (No Sugar removes sugar)
- **SUBSTITUTE** ingredients (Oat Milk replaces Whole Milk)

### Q: Is consumption mode only for France?
**A:** No. While France requires it for VAT compliance, other countries can use it for analytics (understand dine-in vs takeaway trends) even if it doesn't affect tax.

### Q: Can we extend F&B Boss to full-service restaurants?
**A:** Yes! The current MVP focuses on counter-service (cafés, coffee shops). Future phases will add:
- Table management
- Kitchen Display System (KDS)
- Reservations
- Course sequencing

---

## Support & Contributions

**Questions?** Contact the F&B Boss implementation team or refer to the phase-specific documents.

**Found an issue?** Update the relevant document and commit with a clear message.

**Have an improvement?** Follow the project's contribution guidelines in CLAUDE.md.

---

**Last Updated:** January 2026
**Documentation Version:** 1.0
**Status:** Ready for Implementation

---

*Let's build amazing F&B experiences! ☕🥐🍰*
