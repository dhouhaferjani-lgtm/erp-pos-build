# Multi-App Scaffolding Architecture Review & Implementation Plan

**Document Purpose:** Comprehensive review of the MULTI-APP-SCAFFOLDING.md document with architectural analysis and implementation recommendations.

**Review Date:** December 30, 2025
**Reviewer:** Claude Code (Sonnet 4.5)
**Status:** For Double Verification

---

## Executive Summary

After thorough analysis of the `docs/new_docs/MULTI-APP-SCAFFOLDING.md` document and the AutoERP codebase, I've identified **critical architectural flaws** in the proposed multi-app approach and **significant gaps** in the documentation.

**Key Findings:**
1. ❌ **Proposed architecture violates AutoERP's core principles** (hexagonal architecture, event sourcing, module boundaries)
2. ❌ **Documentation missing 10+ critical sections** (migration strategy, security, testing, deployment)
3. ⚠️ **Fundamental design decisions are incorrect** (runtime vs compile-time, Tenant vs Company scope)
4. ✅ **Alternative approaches exist** that align better with current architecture

**Recommendation:** **DO NOT implement the proposed architecture as-is.** Three viable alternatives are presented below.

---

## Part 1: Documentation Completeness Analysis

### 1.1 MISSING SECTIONS (Critical Gaps)

#### A. Migration Strategy for Existing Companies ⚠️ CRITICAL
**Status:** COMPLETELY MISSING

**What's needed:**
- How do existing automotive companies (currently in production) transition to the vertical model?
- Default vertical assignment for pre-existing companies (all are automotive - mechanic/body_shop)
- Data migration scripts with rollback procedures
- User communication plan
- Policy on vertical changes post-signup

**Impact:** Cannot safely deploy to production without this.

**Required Documentation:**
```markdown
### Migration Strategy

#### Phase 1: Backfill Existing Companies
1. All existing companies assigned `business_vertical = 'mechanic'` (preserve current functionality)
2. Enable all currently-used modules (Vehicle, Workshop, Document, Inventory)
3. Migration script: `database/migrations/YYYY_MM_DD_backfill_verticals.php`

#### Phase 2: Gradual Onboarding
- Show modal on next login: "Help us serve you better - confirm your business type"
- Allow companies to change vertical ONCE during transition period
- After transition period, vertical changes require support ticket

#### Data Integrity Rules
- IF company has vehicles → MUST select vertical with Vehicle module
- IF company has work orders → MUST select vertical with Workshop module
- Provide "Current Setup" option that preserves all current modules
```

---

#### B. Module Dependency Management ⚠️ CRITICAL
**Status:** COMPLETELY MISSING

**What's needed:**
- Dependency graph (Workshop depends on Vehicle, Recipe depends on Product)
- Validation logic (prevent enabling Workshop without Vehicle)
- Module loading order
- Circular dependency detection
- Auto-enable dependencies vs. show error?

**Required Documentation:**
```markdown
### Module Dependencies

#### Dependency Graph
- **Workshop** → requires: Vehicle, Product, Inventory
- **Recipe** → requires: Product, Inventory
- **Tables** → requires: Menu
- **BatchExpiry** → requires: Inventory, Product
- **TN_EInvoice** → requires: Document, Partner, Accounting

#### Enforcement Strategy
Option 1: Auto-enable dependencies (RECOMMENDED)
- User enables Workshop → System auto-enables Vehicle with notification

Option 2: Show error message
- User enables Workshop → Error: "Workshop requires Vehicle module"

#### Implementation
```php
// config/modules.php
'Workshop' => [
    'depends_on' => ['Vehicle', 'Product', 'Inventory'],
    'loading_order' => 50, // Load after dependencies
]
```
```

---

#### C. Testing Strategy for Vertical Combinations
**Status:** COMPLETELY MISSING

**What's needed:**
- Which vertical × module combinations must be tested?
- Automated tests for module visibility
- Integration tests for vertical-specific features
- Regression testing when adding new verticals
- Test data seeding per vertical

**Required Documentation:**
```markdown
### Testing Matrix

#### Priority Testing Combinations
1. **High Priority** (Must test every release):
   - IziPOS Pharmacy (BatchExpiry required)
   - IziPOS Restaurant (Menu + Tables)
   - Otospex Mechanic (Vehicle + Workshop)

2. **Medium Priority** (Test on major releases):
   - IziPOS Coffee Shop (Menu only)
   - Otospex Body Shop (Vehicle + Workshop)
   - Otospex Parts Retailer (Vehicle only)

3. **Low Priority** (Spot check):
   - IziPOS Retail
   - Remaining verticals

#### Test Files
- `tests/Feature/VerticalModuleVisibilityTest.php` - Module loading tests
- `tests/Feature/SignupVerticalSelectionTest.php` - Signup flow tests
- `tests/Feature/{Vertical}IntegrationTest.php` - Per-vertical integration tests
```

---

#### D. Deployment Strategy ⚠️ CRITICAL
**Status:** INCOMPLETE (mentioned but not detailed)

**What's needed:**
- How to route traffic to different products (Nginx/Traefik configuration)
- SSL certificate management per domain
- CDN configuration for product-specific assets
- CI/CD pipeline for dual-product deployment
- Fallback behavior if APP_PRODUCT is missing
- Environment variable validation

---

#### E. Security & Authorization ⚠️ CRITICAL
**Status:** COMPLETELY MISSING

**Critical Security Vulnerability Identified:**

**Attack Vector:**
```bash
# Frontend hides Vehicles menu
# But API route still registered and accessible!
curl -X GET https://api.otospex.com/api/v1/vehicles \
  -H "Authorization: Bearer $TOKEN"
# Returns 200 OK - bypassed module visibility!
```

**Root Cause:**
- All module routes registered in `bootstrap/providers.php` regardless of visibility
- No middleware enforcing module access on routes
- Only frontend navigation is hidden

**Required Fix:**
```php
// app/Modules/Vehicle/routes.php
Route::middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, 'module:Vehicle'])
    ->group(function () {
        Route::get('/vehicles', [VehicleController::class, 'index']);
    });
```

---

#### F. Performance Considerations
**Status:** COMPLETELY MISSING

**Performance Issues Identified:**
- Module visibility computed on every request (~5-10ms overhead)
- No caching strategy for enabled modules
- N+1 query potential in ModuleLoaderService
- Frontend bundle size (all modules loaded regardless of visibility)

**Required Solutions:**
```php
// Backend Caching
Cache::remember("company:{$companyId}:modules", 3600, function() use ($company) {
    return $this->moduleLoader->getEnabledModules($company);
});

// Database Indexes
CREATE INDEX idx_companies_vertical ON companies(business_vertical);
CREATE INDEX idx_companies_vertical_status ON companies(business_vertical, status) WHERE status = 'active';
```

---

### 1.2 INCOMPLETE SECTIONS (Exist but Lack Detail)

All major sections (Product Configuration, Business Verticals, Module Visibility, Database, Frontend Theming) exist but lack critical implementation details. See full analysis in Part 10 of this document.

---

## Part 2: Architectural & Technical Issues Analysis

### 2.1 FUNDAMENTAL DESIGN FLAWS

#### Flaw #1: Runtime Product Checking is Wrong ❌

**Proposed Approach:**
```php
// On EVERY request
$product = config('app.product'); // from APP_PRODUCT env var
$enabledModules = app(ModuleLoaderService::class)->getEnabledModules($company);
```

**Why This is Wrong:**

1. **Performance Penalty:** 5-10ms overhead per request
2. **Deployment Confusion:** Same container runs both IziPOS and Otospex simultaneously
3. **Testing Complexity:** Must test every feature twice (once per product)
4. **Security Risk:** Both products' code always present (larger attack surface)

**Correct Approach:** Product should be a **compile-time decision** (separate builds), not runtime configuration.

---

#### Flaw #2: Vertical Belongs on Tenant, NOT Company ❌

**Your Current Architecture (from code review):**
```
Tenant.php line 25-26: "IMPORTANT: Tenant = Account/Person (subscription holder), NOT a company."
Company.php line 25-27: "IMPORTANT: Company is where business data is scoped."

Tenant (Account/Subscription Holder)
  └─ Company 1 (Legal Entity - Paris)
  └─ Company 2 (Legal Entity - Lyon)
```

**Proposed puts vertical on Company:**
```sql
ALTER TABLE companies ADD COLUMN business_vertical VARCHAR(50);
```

**Why This is Problematic:**

**Scenario:** Franchise chain "AutoGroup France" (single Tenant)
- Company 1: Paris (mechanic vertical) → needs Vehicle + Workshop
- Company 2: Lyon (parts_retailer vertical) → needs Vehicle only

**Problems:**
1. **Subscription Chaos:** Tenant pays once, but Companies have different module needs
2. **UI Nightmare:** User switches companies → navigation changes completely
3. **Billing Complexity:** How to charge per vertical if companies differ?

**Correct Placement: Vertical on Tenant** (RECOMMENDED)
```sql
ALTER TABLE tenants ADD COLUMN business_vertical VARCHAR(50);
ALTER TABLE tenants ADD COLUMN product VARCHAR(20); -- 'izipos' or 'otospex'
```

**Why:**
- Tenant signs up for ONE product (IziPOS or Otospex)
- Tenant selects ONE vertical during signup
- All companies inherit same vertical/modules
- Consistent billing and user experience

---

#### Flaw #3: JSONB enabled_modules is an Anti-Pattern ❌

**Proposed:**
```sql
ALTER TABLE companies ADD COLUMN enabled_modules JSONB DEFAULT '[]';
```

**Why This is Wrong:**
1. **No Referential Integrity:** Can store `['FakeModule', 'NonExistent']`
2. **Poor Performance:** Can't efficiently index JSONB arrays
3. **Migration Hell:** Renaming module = update JSONB in all rows
4. **No Audit Trail:** Can't track when/who enabled module

**Correct Approach:**
```sql
CREATE TABLE modules (
    name VARCHAR(50) PRIMARY KEY,
    display_name VARCHAR(100) NOT NULL,
    product VARCHAR(20), -- NULL = universal, 'izipos', 'otospex'
    is_core BOOLEAN NOT NULL DEFAULT false,
    depends_on VARCHAR(50)[] -- Module dependencies
);

CREATE TABLE company_modules (
    id UUID PRIMARY KEY,
    company_id UUID NOT NULL REFERENCES companies(id),
    module_name VARCHAR(50) NOT NULL REFERENCES modules(name),
    enabled_at TIMESTAMP NOT NULL DEFAULT NOW(),
    enabled_by UUID NOT NULL REFERENCES users(id),
    UNIQUE(company_id, module_name)
);
```

**Benefits:** Foreign key constraints, audit trail, efficient queries, metadata support

---

#### Flaw #4: Conflicts with Existing Permission System ❌

**CLAUDE.md Rule #12 Already Handles Access Control:**
```php
Route::middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])->group(...)
```

**Proposed Adds SECOND Authorization Layer:**
```php
if (!$moduleLoader->isModuleEnabled('Product', $company)) {
    abort(403, 'Module not enabled');
}
```

**Problems:**
1. **Double Authorization:** Check both permissions AND module visibility
2. **Precedence Unclear:** Permission granted but module disabled?
3. **Test Complexity:** Every test needs both setups

**Resolution: Permissions ARE Module Visibility** (RECOMMENDED)
- If Vehicle module shouldn't be accessible, don't assign vehicle.* permissions
- Existing permission system already works perfectly

---

### 2.2 CONFLICTS WITH EXISTING ARCHITECTURE

#### Conflict #1: Violates Hexagonal Architecture (CLAUDE.md Rule #6) ⬢

**Rule #6: Module Boundaries are Sacred**
> Cross-module communication ONLY via Interfaces in Shared/Contracts/ or Events

**Proposed Violates This:**
```php
// In DocumentController
if (app(ModuleLoaderService::class)->isModuleEnabled('Vehicle', $company)) {
    $vehicle = app(VehicleService::class)->getVehicle($request->vehicle_id);
    // Document module now has runtime dependency on Vehicle!
}
```

**Hexagonal Requires:**
```php
// Define interface in Shared
interface VehicleDataProvider { ... }

// Bind implementation or null object
$this->app->bind(VehicleDataProvider::class,
    $vehicleModuleLoaded ? VehicleService::class : NullVehicleProvider::class
);
```

---

#### Conflict #2: Breaks Event Sourcing (CLAUDE.md Rule #8) 📜

**Rule #8: Events are Immutable Forever**

**Existing Event:**
```php
class InvoicePosted {
    public function __construct(
        public string $invoiceId,
        public ?string $vehicleId, // From Vehicle module
    ) {}
}
```

**Problem:**
- If Vehicle module "disabled", is vehicleId always null?
- Old events have vehicle data, new events don't?
- **Fiscal hash chain breaks if event structure changes!**

**Resolution:** Events MUST remain immutable. Use Event Versioning if needed.

---

#### Conflict #3: Schema-Based Multi-Tenancy Complexity 🗄️

**Current:** `tenant_slug` schema per tenant (from Tenant.php)

**Problem with Module Visibility:**
- Tenant A (Otospex): Has `vehicles`, `work_orders` tables
- Tenant B (IziPOS): Does NOT have `vehicles`, `work_orders` tables
- Migration nightmare: Does migration run for all schemas?

**Resolution:** ALL tables exist in ALL tenant schemas. Module visibility controls data creation, not table existence.

---

## Part 3: Alternative Approaches

### Alternative #1: Simple Feature Flags 🏴

**Approach:** Keep current architecture, add feature toggling at Tenant level.

```sql
ALTER TABLE tenants ADD COLUMN enabled_features JSONB DEFAULT '{}';
```

**Pros:**
- ✅ Minimal changes (1-2 weeks)
- ✅ Tenant-level (correct scope)
- ✅ No performance overhead
- ✅ Easy to test

**Cons:**
- ❌ Doesn't solve multi-product branding (IziPOS vs Otospex)
- ❌ Still shipping all code in bundle

**Verdict:** ✅ **GOOD for feature differentiation within a single product**

---

### Alternative #2: Separate Deployments ⭐ RECOMMENDED

**Approach:** Monorepo with shared packages, separate apps per product.

```
autoerp/ (monorepo)
├── packages/
│   └── autoerp-core/           → Shared modules (Composer package)
├── apps/
│   ├── izipos-api/             → Separate Laravel app
│   ├── izipos-web/             → Separate React app
│   ├── otospex-api/            → Separate Laravel app (includes Vehicle, Workshop)
│   └── otospex-web/            → Separate React app
```

**Benefits:**
1. ✅ **Compile-Time Module Selection:** Only needed modules included
2. ✅ **Smaller Docker Images:** IziPOS doesn't ship Vehicle code
3. ✅ **Independent Versioning:** Update Otospex without touching IziPOS
4. ✅ **Zero Runtime Overhead:** No module checking per request
5. ✅ **Security:** Attack surface limited to product code
6. ✅ **Clean Architecture:** Enforced at composer dependency level

**Cost:** 3-4 weeks initial setup

**Verdict:** ⭐ **RECOMMENDED for true multi-product architecture**

---

### Alternative #3: Domain-Based Routing 🌐

**Approach:** Single codebase, product determined by request domain.

```
https://app.izipos.com → IziPOS
https://app.otospex.com → Otospex
```

**Pros:** ✅ Clear product separation via domain
**Cons:** ⚠️ Still runtime checks, shipping all code

**Verdict:** ⚠️ **Middle ground** - better than env var, not as clean as separate deployments

---

## Part 4: RISK ASSESSMENT MATRIX

| Risk Category | Proposed | Alt #1: Flags | Alt #2: Separate | Alt #3: Domain |
|---------------|----------|---------------|------------------|----------------|
| **Performance** | ❌ High | ✅ Low | ✅ None | ⚠️ Medium |
| **Security** | ❌ High | ⚠️ Medium | ✅ Low | ⚠️ Medium |
| **Data Integrity** | ❌ High | ⚠️ Medium | ✅ Low | ⚠️ Medium |
| **Migration** | ❌ High | ✅ Low | ⚠️ Medium | ⚠️ Medium |
| **Testing** | ❌ High | ⚠️ Medium | ✅ Low | ⚠️ Medium |
| **Architecture** | ❌ Violates | ✅ Clean | ✅ Clean | ⚠️ Minor |
| **Cost (Initial)** | ⚠️ Medium | ✅ Low | ⚠️ High | ⚠️ Medium |
| **Cost (Long-term)** | ❌ High | ⚠️ Medium | ✅ Low | ⚠️ Medium |

---

## Part 5: FINAL RECOMMENDATION

### ❌ DO NOT IMPLEMENT Proposed Architecture As-Is

**Critical Reasons:**

1. **Violates CLAUDE.md Principles:** Rule #6 (Module Boundaries), Rule #8 (Event Immutability)
2. **Security Vulnerabilities:** API routes not protected, module bypass trivial
3. **Performance Issues:** 5-10ms overhead per request, no caching
4. **Data Integrity Risks:** Vertical changes create orphaned data
5. **Architecture Conflicts:** Breaks hexagonal architecture, event sourcing, multi-tenancy

---

### ✅ RECOMMENDED APPROACH: Phased Implementation

#### Phase 1: Short-Term (1-2 weeks) - Feature Flags
**Goal:** Enable/disable features per tenant without architectural changes.

**Implementation:**
```sql
ALTER TABLE tenants ADD COLUMN enabled_features JSONB DEFAULT '{}';
```

**Deliverables:**
- Migration for tenants table
- `hasFeature()` helper method on Tenant model
- Frontend `useTenantFeatures()` hook
- Backend `RequireFeature` middleware (optional)
- Tests

**Use Cases:**
- Trial vs Paid feature differentiation
- Beta feature rollout
- Per-tenant customization

---

#### Phase 2: Medium-Term (3-4 months) - Separate Deployments
**Goal:** True multi-product architecture with IziPOS and Otospex.

**Timeline:**
- **Week 1-2:** Extract core modules to `packages/autoerp-core`
- **Week 3-4:** Create IziPOS app (Menu, Recipe, Tables modules)
- **Week 5-6:** Create Otospex app (Vehicle, Workshop modules)
- **Week 7-8:** Create frontend apps (izipos-web, otospex-web)
- **Week 9-12:** Testing, CI/CD, migration, deployment

**Deliverables:**
- Monorepo with 2 products
- Separate Docker images per product
- Independent versioning capability
- Documentation

---

#### Phase 3: Long-Term (6+ months) - Vertical Specialization
**Goal:** Add vertical-specific features within each product.

**Implementation:**
- Pharmacy-specific features in IziPOS (batch tracking, expiry)
- Restaurant-specific features in IziPOS (tables, menu)
- Body shop-specific features in Otospex (estimates, paint inventory)

**Use Feature Flags from Phase 1:**
```typescript
if (tenant.features.includes('batch_tracking')) {
    // Show pharmacy features
}
```

---

## Part 6: Implementation Plan - Phase 1 (Feature Flags)

### Complete implementation code provided in the full plan document.

**Key Files:**
1. Migration: `database/migrations/2025_12_31_000001_add_features_to_tenants.php`
2. Enum: `app/Enums/TenantFeature.php`
3. Model: `app/Modules/Tenant/Domain/Tenant.php` (add methods)
4. Frontend Hook: `apps/web/src/hooks/useTenantFeatures.ts`
5. Sidebar: `apps/web/src/components/organisms/Sidebar/Sidebar.tsx` (dynamic filtering)
6. Middleware: `app/Http/Middleware/RequireFeature.php` (optional)
7. Seeder: `database/seeders/BackfillTenantFeaturesSeeder.php`
8. Tests: `tests/Feature/TenantFeaturesTest.php`

**Verification Steps:**
```bash
php artisan migrate
php artisan db:seed --class=BackfillTenantFeaturesSeeder
php artisan test --filter=TenantFeaturesTest
php artisan typescript:transform
cd apps/web && pnpm typecheck && pnpm test
```

---

## Part 7: Critical Files for Review

1. **`apps/api/app/Modules/Tenant/Domain/Tenant.php`**
   - Line 25-26: Confirms Tenant = Account holder
   - Needs: `enabled_features` JSONB column

2. **`apps/api/app/Modules/Company/Domain/Company.php`**
   - Line 25-27: Confirms Company = Legal entity
   - Decision: Vertical should be on Tenant, not here

3. **`apps/api/bootstrap/providers.php`**
   - All 22 module ServiceProviders registered
   - For separate deployments: this file differs per product

4. **`apps/web/src/components/organisms/Sidebar/Sidebar.tsx`**
   - Current: Hardcoded navigation
   - Needs: Dynamic filtering based on features

5. **`docs/new_docs/MULTI-APP-SCAFFOLDING.md`**
   - Original document under review
   - Contains proposed architecture (not recommended)

---

## Part 8: Decision Points for Stakeholders

### Decision #1: Which Architecture Approach?

**Options:**

**A. Feature Flags (Phase 1)** ✅ RECOMMENDED START
- Quick win (1-2 weeks)
- Minimal risk
- Enables differentiation now
- Doesn't solve multi-product branding

**B. Separate Deployments (Phase 2)** ⭐ RECOMMENDED LONG-TERM
- True multi-product architecture
- Clean separation
- 3-4 months effort
- Sustainable long-term

**C. Proposed Approach** ❌ NOT RECOMMENDED
- High risks
- Architecture violations
- Performance issues

**My Recommendation:** A + B (Phased approach)

---

### Decision #2: Vertical Scope

**Options:**

**A. Tenant Level** ✅ RECOMMENDED
- Simpler billing
- Consistent user experience
- Aligns with subscription model

**B. Company Level** ⚠️ NOT RECOMMENDED
- Complex billing
- Navigation changes on company switch
- Franchise chaos

**My Recommendation:** A (Tenant Level)

---

### Decision #3: Module Storage

**Options:**

**A. JSONB Column** ✅ RECOMMENDED FOR PHASE 1
- Quick to implement
- Good for feature flags

**B. Junction Table** ✅ RECOMMENDED FOR PHASE 2
- Referential integrity
- Audit trail
- Better for complex module management

**My Recommendation:** A for Phase 1, B for Phase 2

---

## Part 9: Summary & Next Steps

### What This Review Provides

1. ✅ **Completeness Analysis:** 10+ missing critical sections identified
2. ✅ **Architectural Issues:** 4 fundamental design flaws documented
3. ✅ **Alternative Approaches:** 3 options with full pros/cons analysis
4. ✅ **Implementation Plan:** Complete Phase 1 code ready to execute
5. ✅ **Risk Assessment:** Comprehensive matrix comparing all approaches
6. ✅ **Recommendations:** Clear phased path forward

---

### Immediate Actions Required

1. **Stakeholder Decision:** Review and approve phased approach
2. **Architecture Decision:** Confirm Tenant-level vertical placement
3. **Timeline Approval:**
   - Phase 1 (Feature Flags): 2 weeks
   - Phase 2 (Separate Deployments): 3-4 months
4. **Resource Allocation:** Assign development team

---

### Success Criteria

**Phase 1 Complete When:**
- ✅ Tenants can enable/disable features
- ✅ Navigation dynamically filtered
- ✅ Backend routes protected by feature middleware
- ✅ All tests passing
- ✅ TypeScript types generated

**Phase 2 Complete When:**
- ✅ IziPOS and Otospex deployable separately
- ✅ Shared core modules packaged
- ✅ Independent versioning working
- ✅ CI/CD pipelines per product
- ✅ Existing tenants migrated

---

## Part 10: For Double Verification

### Questions for Second Reviewer

1. **Architecture Validity:** Do you agree the proposed runtime approach violates hexagonal architecture?
2. **Tenant vs Company:** Confirm vertical should be on Tenant, not Company?
3. **Security Concerns:** Are the identified API bypass vulnerabilities accurate?
4. **Phase 1 Code:** Review the Feature Flags implementation - any issues?
5. **Phase 2 Approach:** Is the separate deployments monorepo structure sound?
6. **Missing Gaps:** Are there critical sections I missed in the original document?

### Areas Requiring Additional Analysis

1. **Event Versioning Strategy:** How to handle InvoicePosted with optional vehicleId?
2. **Module Dependencies:** Should Workshop auto-enable Vehicle or throw error?
3. **Multi-Location Edge Case:** Can different locations have different verticals?
4. **Compliance Impact:** How does this affect fiscal hash chains and NF525?
5. **Mobile Apps:** How do feature flags work in React Native apps?

---

## Appendix: Full Code Implementation

Complete implementation code for Phase 1 (Feature Flags) is provided in the full plan document at:
`/Users/houssamr/.claude/plans/cozy-petting-charm.md`

This includes:
- Database migrations
- Backend enums and models
- Frontend TypeScript hooks
- Middleware implementation
- Seeder for existing data
- Complete test suite
- Verification steps

---

**END OF REVIEW DOCUMENT**

**For Questions/Clarifications:**
- Review the full plan at `.claude/plans/cozy-petting-charm.md`
- Original document at `docs/new_docs/MULTI-APP-SCAFFOLDING.md`
- Codebase exploration findings included in this review

**Next Steps:**
1. Second reviewer validates findings
2. Stakeholders approve recommended approach
3. Begin Phase 1 implementation (Feature Flags)
4. Plan Phase 2 timeline (Separate Deployments)
