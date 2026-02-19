# F&B Boss - Implementation Checklist

**Version:** 1.0
**Date:** January 2026
**Status:** Ready to Execute

---

## Quick Start

This checklist provides a step-by-step execution plan for implementing the F&B Boss vertical. Check off each item as you complete it.

**Before Starting:**
- [ ] Read [00-MASTER-IMPLEMENTATION-PLAN.md](./00-MASTER-IMPLEMENTATION-PLAN.md)
- [ ] Review [CLAUDE.md](./CLAUDE.md) project conventions
- [ ] Ensure POS module is at Phase 0.5+ (basic structure exists)
- [ ] Verify development environment is set up
- [ ] Create feature branch: `feature/fnb-boss`

---

## Phase 1: Data Model & Backend

**Timeline:** Weeks 1-2
**Goal:** Complete database schema, domain entities, and API endpoints

### Week 1: Database & Domain Layer

**Day 1-2: Database Migrations**
- [ ] Create migration 1: Add product_type to products
- [ ] Create migration 2: recipes table
- [ ] Create migration 3: recipe_lines table
- [ ] Create migration 4: modifier_groups table
- [ ] Create migration 5: modifier_options table
- [ ] Create migration 6: menu_item_sizes table
- [ ] Create migration 7: menu_item_modifier_groups pivot
- [ ] Run all migrations on local environment
- [ ] Test migration rollback
- [ ] Verify constraints and indexes
- [ ] Seed test data (10 ingredients, 5 menu items)

**Day 3: Domain Entities**
- [ ] Create `Ingredient` entity (extends Product semantics)
- [ ] Create `MenuItem` entity
- [ ] Create `Recipe` entity
- [ ] Create `RecipeLine` entity
- [ ] Create `ModifierGroup` entity
- [ ] Create `ModifierOption` entity
- [ ] Create `MenuItemSize` value object
- [ ] Write unit tests for all entities (60+ tests)

**Day 4: Value Objects & Collections**
- [ ] Create `RecipeLineCollection`
- [ ] Create `MenuItemSizeCollection`
- [ ] Create `ModifierGroupCollection`
- [ ] Create `UnitOfMeasure` value object
- [ ] Create `ConsumptionMode` enum
- [ ] Write unit tests for value objects

**Day 5: Repository Layer**
- [ ] Create `IngredientRepository` interface + implementation
- [ ] Create `MenuItemRepository` interface + implementation
- [ ] Create `RecipeRepository` interface + implementation
- [ ] Create `ModifierGroupRepository` interface + implementation
- [ ] Write integration tests for repositories (20+ tests)

---

### Week 2: Application & Presentation Layer

**Day 6: Application Services**
- [ ] Create `RecipeService`
  - [ ] `calculateTheoreticalCost()` method
  - [ ] `applyCustomizations()` method
  - [ ] `getIngredientRequirements()` method
  - [ ] Write unit tests (15+ tests)
- [ ] Create `ModifierService`
  - [ ] `validateSelection()` method
  - [ ] `calculatePriceAdjustment()` method
  - [ ] `getIngredientEffects()` method
  - [ ] Write unit tests (10+ tests)
- [ ] Create `MenuItemPricingService`
  - [ ] `calculateFinalPrice()` method
  - [ ] `generateDescription()` method
  - [ ] Write unit tests (10+ tests)

**Day 7-8: DTOs & Transformers**
- [ ] Create `IngredientData` DTO
- [ ] Create `MenuItemData` DTO
- [ ] Create `RecipeData` DTO
- [ ] Create `ModifierGroupData` DTO
- [ ] Create `ModifierOptionData` DTO
- [ ] Run `php artisan typescript:transform`
- [ ] Verify generated TypeScript types

**Day 9-10: API Controllers**
- [ ] Create `IngredientController` (CRUD)
  - [ ] `index()`, `show()`, `store()`, `update()`, `destroy()`
  - [ ] Write feature tests (10+ tests)
- [ ] Create `MenuItemController` (CRUD)
  - [ ] `index()`, `show()`, `store()`, `update()`, `destroy()`
  - [ ] Write feature tests (15+ tests)
- [ ] Create `RecipeController` (CRUD)
  - [ ] `index()`, `show()`, `store()`, `update()`
  - [ ] Write feature tests (10+ tests)
- [ ] Create `ModifierGroupController` (CRUD)
  - [ ] `index()`, `show()`, `store()`, `update()`, `destroy()`
  - [ ] Write feature tests (10+ tests)
- [ ] Create `ModifierOptionController` (CRUD)
  - [ ] `index()`, `show()`, `store()`, `update()`, `destroy()`
  - [ ] Write feature tests (5+ tests)

**Phase 1 Quality Gates:**
- [ ] All migrations pass up and down
- [ ] PHPStan level 8 - zero errors
- [ ] 90%+ test coverage on domain layer
- [ ] All feature tests passing
- [ ] No `mixed` or `any` types
- [ ] API documentation generated
- [ ] Code review completed
- [ ] Commit with message: "feat(fnb): Phase 1 - Complete data model and API"

---

## Phase 2: POS Integration

**Timeline:** Weeks 3-4
**Goal:** Build F&B POS UI and integrate with backend

### Week 3: Core POS Components

**Day 11-12: Layout & Structure**
- [ ] Create `FnBPOSLayout` component
- [ ] Create `FnBHeader` component
- [ ] Create `FnBMenuPanel` component
- [ ] Create `FnBCartPanel` component
- [ ] Create `useFnBCart` hook
- [ ] Write component tests (10+ tests)

**Day 13: Menu Display**
- [ ] Create `CategoryTabs` component
- [ ] Create `MenuItemGrid` component
- [ ] Create `MenuItemCard` component
- [ ] Create `QuickSearch` component
- [ ] Create `useMenuItems` hook
- [ ] Integrate with menu items API
- [ ] Write component tests (15+ tests)

**Day 14: Cart Components**
- [ ] Create `CartLineItem` component
- [ ] Create `CartTotals` component
- [ ] Create `CartActions` component
- [ ] Create `ConsumptionModeToggle` component
- [ ] Write component tests (10+ tests)

**Day 15: Modifier System**
- [ ] Create `MenuItemCustomizationModal` component
- [ ] Create `SizeSelector` component
- [ ] Create `ModifierGroupSelector` component
- [ ] Create `ModifierOptionButton` component
- [ ] Create `useModifiers` hook
- [ ] Implement validation logic
- [ ] Write component tests (20+ tests)

---

### Week 4: Integration & Testing

**Day 16-17: API Integration**
- [ ] Create `menuItemsApi.ts` with all endpoints
- [ ] Create `modifiersApi.ts` with all endpoints
- [ ] Create `recipesApi.ts` with all endpoints
- [ ] Implement React Query hooks
- [ ] Test error handling
- [ ] Test loading states

**Day 18: Receipt Integration**
- [ ] Extend `pos_receipt_lines` migration (migration 8)
- [ ] Extend `pos_receipts` migration (migration 9)
- [ ] Update receipt creation service
- [ ] Update receipt template to show modifiers
- [ ] Test receipt generation
- [ ] Write integration tests (10+ tests)

**Day 19-20: E2E Testing**
- [ ] Write E2E test: Complete sale with modifiers
- [ ] Write E2E test: Size selection
- [ ] Write E2E test: Required modifier validation
- [ ] Write E2E test: Consumption mode toggle
- [ ] Write E2E test: Receipt with modifiers
- [ ] Fix any bugs found during E2E testing

**Phase 2 Quality Gates:**
- [ ] All POS components render correctly
- [ ] Modifier validation working
- [ ] Prices calculate correctly
- [ ] Receipts show full modifier details
- [ ] 90%+ component test coverage
- [ ] All E2E tests passing
- [ ] TypeScript strict mode - zero errors
- [ ] Performance: Item selection < 500ms
- [ ] Code review completed
- [ ] Commit with message: "feat(fnb): Phase 2 - Complete POS integration"

---

## Phase 3: Advanced Features

**Timeline:** Weeks 5-6
**Goal:** Implement optional features and compliance

### Week 5: Inventory & Costing

**Day 21-22: Automatic Inventory Deduction**
- [ ] Create `RecipeBasedStockAdjustmentService`
- [ ] Implement `deductForSale()` method
- [ ] Implement `reverseDeduction()` method
- [ ] Add feature flag to company config
- [ ] Integrate with receipt creation
- [ ] Handle insufficient stock errors
- [ ] Write unit tests (15+ tests)
- [ ] Write integration tests (10+ tests)

**Day 23: COGS Calculation**
- [ ] Create `COGSCalculationService`
- [ ] Implement `calculateTheoreticalCOGS()` method
- [ ] Create variance report query
- [ ] Build variance report UI
- [ ] Write unit tests (10+ tests)

**Day 24-25: Consumption Mode VAT**
- [ ] Create `ConsumptionModeTaxCalculator`
- [ ] Implement country-specific tax rules
- [ ] Integrate with receipt tax calculation
- [ ] Add configuration UI for tax rules
- [ ] Test with France VAT rates
- [ ] Write unit tests (15+ tests)

---

### Week 6: Vouchers & Configuration

**Day 26-27: Restaurant Vouchers**
- [ ] Run migration 10: voucher fields on payment_methods
- [ ] Run migration 11: voucher_usage_tracking table
- [ ] Create `VoucherPaymentValidator` service
- [ ] Implement daily limit checking
- [ ] Implement eligible item checking
- [ ] Create `VoucherPaymentForm` component
- [ ] Write unit tests (15+ tests)
- [ ] Write feature tests (10+ tests)

**Day 28: Label Configuration**
- [ ] Run migration 12: label_overrides on tenants
- [ ] Create `useLabels` hook
- [ ] Apply labels to F&B components
- [ ] Create label configuration UI
- [ ] Test label override functionality

**Day 29-30: Performance Optimization**
- [ ] Add query caching for menu items
- [ ] Add eager loading for POS queries
- [ ] Optimize recipe calculation
- [ ] Profile and fix N+1 queries
- [ ] Load test with 100 menu items
- [ ] Load test with 50 concurrent orders

**Phase 3 Quality Gates:**
- [ ] Auto inventory deduction working correctly
- [ ] Consumption mode VAT accurate for France
- [ ] Voucher validation enforces all rules
- [ ] Label configuration applied throughout UI
- [ ] COGS calculation matches expected values
- [ ] 90%+ test coverage on services
- [ ] No N+1 query issues
- [ ] Performance targets met
- [ ] Code review completed
- [ ] Commit with message: "feat(fnb): Phase 3 - Advanced features complete"

---

## Phase 4: Loyalty Integration

**Timeline:** Week 7
**Goal:** Connect F&B to Core Loyalty module

### Week 7: Loyalty

**Day 31-32: Entity Registration**
- [ ] Implement `LoyaltyableContract` in MenuItem
- [ ] Implement `LoyaltyableCategoryContract` in MenuCategory
- [ ] Register entities in `CatalogServiceProvider`
- [ ] Test entity registration
- [ ] Write unit tests (5+ tests)

**Day 33: Program Templates**
- [ ] Create `createCoffeeStampCard()` method
- [ ] Create `createPointsBasedProgram()` method
- [ ] Test template creation
- [ ] Seed test loyalty programs

**Day 34-35: POS Integration**
- [ ] Create `LoyaltyCustomerLookup` component
- [ ] Create `LoyaltyBalanceCard` component
- [ ] Update `FnBCartPanel` with loyalty
- [ ] Implement reward redemption flow
- [ ] Write component tests (15+ tests)

**Day 36: Receipt Integration**
- [ ] Run migration 13: loyalty_transaction_id on pos_receipts
- [ ] Update receipt creation to record loyalty transaction
- [ ] Update receipt template with loyalty info
- [ ] Test loyalty transaction recording
- [ ] Write integration tests (10+ tests)

**Day 37: Final Testing & Documentation**
- [ ] E2E test: Earn points on purchase
- [ ] E2E test: Earn stamp on coffee purchase
- [ ] E2E test: Redeem reward
- [ ] E2E test: Complete stamp card
- [ ] Update API documentation
- [ ] Create user guide for loyalty
- [ ] Code review

**Phase 4 Quality Gates:**
- [ ] MenuItem registered as loyaltyable
- [ ] Stamp card program working
- [ ] Points program working
- [ ] Reward redemption working
- [ ] Receipt shows loyalty details
- [ ] All integration tests passing
- [ ] No regression in POS performance
- [ ] Documentation complete
- [ ] Commit with message: "feat(fnb): Phase 4 - Loyalty integration complete"

---

## Final Review & Deployment

### Pre-Deployment Checklist

**Code Quality:**
- [ ] All tests passing (unit + integration + E2E)
- [ ] PHPStan level 8 - zero errors
- [ ] ESLint - zero errors
- [ ] TypeScript strict mode - zero errors
- [ ] Test coverage > 85%
- [ ] No console errors in browser
- [ ] No SQL warnings in logs

**Documentation:**
- [ ] API documentation complete
- [ ] User guide written
- [ ] Setup instructions documented
- [ ] Configuration guide written
- [ ] Troubleshooting guide written

**Data Migration:**
- [ ] Migration dry-run on staging successful
- [ ] Rollback plan tested
- [ ] Database backup procedure documented
- [ ] Data seeding scripts ready

**Performance:**
- [ ] Load testing completed (100+ concurrent users)
- [ ] Menu load time < 500ms
- [ ] Receipt creation < 1s
- [ ] No memory leaks in long-running sessions
- [ ] Database queries optimized

**Security:**
- [ ] Authorization checks on all endpoints
- [ ] Input validation on all forms
- [ ] SQL injection prevention verified
- [ ] XSS prevention verified
- [ ] CSRF tokens in place

---

### Staging Deployment

**Steps:**
1. [ ] Merge feature branch to `develop`
2. [ ] Deploy to staging environment
3. [ ] Run all migrations
4. [ ] Seed test data
5. [ ] Smoke test all features
6. [ ] Fix any staging-specific issues
7. [ ] Get QA sign-off

**Staging Test Scenarios:**
- [ ] Create ingredient with stock tracking
- [ ] Create menu item with recipe
- [ ] Configure modifiers
- [ ] Complete sale via F&B POS
- [ ] Verify inventory deducted (if enabled)
- [ ] Test consumption mode VAT (France)
- [ ] Test voucher payment
- [ ] Test loyalty transaction
- [ ] Generate variance report
- [ ] Verify receipt accuracy

---

### Pilot Deployment

**Pilot Tenants (2-3 cafés):**
1. [ ] Café A: Stamp card loyalty, no auto-inventory
2. [ ] Café B: Points loyalty, with auto-inventory
3. [ ] Café C: Mixed use case

**Pilot Duration:** 2 weeks

**Pilot Objectives:**
- [ ] Validate real-world usability
- [ ] Identify UX improvements
- [ ] Verify inventory accuracy
- [ ] Test performance under load
- [ ] Gather user feedback

**Daily Monitoring:**
- [ ] Check error logs
- [ ] Monitor transaction volume
- [ ] Review user feedback
- [ ] Track performance metrics
- [ ] Verify loyalty transactions

**Week 1 Review:**
- [ ] Address critical bugs
- [ ] Implement quick UX fixes
- [ ] Optimize slow queries
- [ ] Update documentation based on feedback

**Week 2 Review:**
- [ ] Final bug fixes
- [ ] Performance tuning
- [ ] User training refinements
- [ ] Go/No-Go decision for GA

---

### Production Deployment

**Prerequisites:**
- [ ] Pilot successful
- [ ] All critical bugs fixed
- [ ] Documentation complete
- [ ] Training materials ready
- [ ] Support team briefed

**Deployment Steps:**
1. [ ] Schedule maintenance window
2. [ ] Notify all tenants
3. [ ] Backup production database
4. [ ] Deploy code to production
5. [ ] Run migrations
6. [ ] Smoke test critical paths
7. [ ] Enable feature for pilot tenants
8. [ ] Monitor for 24 hours
9. [ ] Gradual rollout to all F&B tenants

**Post-Deployment:**
- [ ] Monitor error rates
- [ ] Track adoption metrics
- [ ] Collect user feedback
- [ ] Plan iteration 2 features

---

## Success Metrics

**Week 1:**
- [ ] 50% of pilot cafés actively using F&B POS
- [ ] Zero critical bugs reported
- [ ] Average transaction time < 45 seconds

**Week 2:**
- [ ] 100% of pilot cafés using daily
- [ ] Inventory variance < 5% (if enabled)
- [ ] User satisfaction score > 4/5

**Month 1:**
- [ ] 20+ cafés onboarded
- [ ] 1000+ receipts created
- [ ] 500+ loyalty transactions
- [ ] Zero data integrity issues

**Month 3:**
- [ ] 100+ cafés using F&B Boss
- [ ] Feature adoption > 80%
- [ ] Support ticket volume stabilized
- [ ] Positive ROI for pilot customers

---

## Appendix: Useful Commands

### Development

```bash
# Run all tests
composer test
pnpm test

# Static analysis
./vendor/bin/phpstan
pnpm typecheck

# Code formatting
./vendor/bin/pint
pnpm lint:fix

# Generate types
php artisan typescript:transform

# Run specific test
php artisan test --filter MenuItemTest
pnpm test MenuItemCard.test.tsx
```

### Database

```bash
# Run F&B migrations
php artisan migrate --path=database/migrations --from=2026_01_10_100000

# Rollback all F&B migrations
php artisan migrate:rollback --step=13

# Seed F&B test data
php artisan db:seed --class=FnBSeeder

# Check migration status
php artisan migrate:status
```

### Deployment

```bash
# Deploy to staging
./scripts/deploy-staging.sh

# Deploy to production
./scripts/deploy-production.sh

# Verify deployment
./scripts/smoke-test.sh
```

---

**Implementation Start Date:** _______________
**Phase 1 Complete:** _______________
**Phase 2 Complete:** _______________
**Phase 3 Complete:** _______________
**Phase 4 Complete:** _______________
**Production Launch:** _______________

---

*Good luck with the implementation! 🚀*
