# AutoERP Fork Readiness Audit

> **For Claude Code (Opus 4.5)**
> **Objective:** Conduct a comprehensive codebase audit to determine readiness for forking into two separate repositories (AutoERP and BossCloud).

---

## Context

We are preparing to fork the current ERP codebase into two separate products:

1. **AutoERP** — Automotive vertical (mechanics, service stations, car glass, detailers, parts retailers, importers, wholesalers)
2. **BossCloud** — Generic retail/hospitality (retail POS, coffee shops, restaurants)

Both products will share the same core architecture but diverge on domain-specific features. Before forking, we need to ensure the common foundation is stable and that automotive-specific code is properly isolated.

---

## Instructions

Execute each section below systematically. For each item:
- Run the provided commands
- Document your findings
- Assess the status (✅ Ready, ⚠️ Needs Work, ❌ Missing)
- Note any blockers or concerns

**Output:** Generate a structured report at the end with your findings.

---

## Part 1: Project Structure Analysis

### 1.1 Map the Directory Structure

```bash
# Get full project structure (2 levels deep)
find . -maxdepth 3 -type d -not -path "*/node_modules/*" -not -path "*/.git/*" -not -path "*/vendor/*" | sort

# List all modules in the backend
ls -la apps/api/app/Modules/

# List all features in the frontend
ls -la apps/web/src/features/

# List shared packages
ls -la packages/
```

**Document:**
- Backend module names and apparent purpose
- Frontend feature directories
- Shared packages between frontend/backend
- Any automotive-specific directories you can identify

### 1.2 Configuration Files Inventory

```bash
# Find all configuration files
find . -name "*.config.*" -o -name "config.php" -o -name "*.env*" | grep -v node_modules | grep -v vendor

# Check for module configuration
find . -name "*module*" -type f | grep -v node_modules | grep -v vendor

# Check for feature flags or toggles
grep -rn "feature.*flag\|toggle\|enabled\|disabled" --include="*.php" --include="*.ts" --include="*.tsx" | head -50
```

**Document:**
- How modules are configured
- Any feature flag system
- Environment-based configuration

---

## Part 2: Core Infrastructure Assessment

### 2.1 Multi-Tenancy Architecture

```bash
# Check for tenant/company scoping
grep -rn "company_id\|tenant_id\|CompanyScope\|TenantScope" apps/api/app --include="*.php" | head -30

# Check for schema-based multi-tenancy
grep -rn "schema\|Schema::" apps/api/app --include="*.php" | grep -v migration | head -20

# Check middleware for tenant resolution
grep -rn "tenant\|company" apps/api/app/Http/Middleware --include="*.php"

# Check database migrations for company_id presence
grep -rn "company_id" apps/api/database/migrations --include="*.php" | head -20
```

**Assess:**
- [ ] Company-scoped architecture implemented?
- [ ] Schema isolation or column-based?
- [ ] Tenant resolution middleware exists?
- [ ] All core tables have company_id?

### 2.2 Authentication & Permissions

```bash
# Find permission/role system
grep -rn "Permission\|Role\|Gate::\|can(\|authorize" apps/api/app --include="*.php" | head -30

# Check for module-based permissions
grep -rn "module.*permission\|permission.*module" apps/api/app --include="*.php"

# List all policies
find apps/api/app -name "*Policy.php" -type f

# Check frontend permission handling
grep -rn "usePermission\|canAccess\|hasRole\|permission" apps/web/src --include="*.ts" --include="*.tsx" | head -30
```

**Assess:**
- [ ] Role-based access control implemented?
- [ ] Permissions can be scoped per module?
- [ ] Frontend respects permissions?
- [ ] Permission structure documented?

### 2.3 Document Engine

```bash
# Find document-related models
find apps/api/app -name "*Document*" -o -name "*Invoice*" -o -name "*Quote*" -o -name "*Order*" | grep -v test

# Check document state machine / status transitions
grep -rn "status\|state\|transition\|workflow" apps/api/app/Modules --include="*.php" | head -40

# Check for document conversion logic
grep -rn "convert\|transform\|createFrom" apps/api/app/Modules --include="*.php" | head -20

# Frontend document features
ls -la apps/web/src/features/documents/ 2>/dev/null || ls -la apps/web/src/features/
```

**Assess:**
- [ ] Document base model exists?
- [ ] Status transitions defined?
- [ ] Quote → Order → Invoice conversion implemented?
- [ ] Document numbering system?

### 2.4 Partner/CRM Module

```bash
# Find partner/customer/supplier models
find apps/api/app -name "*Partner*" -o -name "*Customer*" -o -name "*Supplier*" | grep "\.php$"

# Check partner type handling
grep -rn "partner_type\|PartnerType\|customer\|supplier" apps/api/app/Modules --include="*.php" | head -30

# Frontend partner features
ls -la apps/web/src/features/partners/ 2>/dev/null || grep -rn "partner\|customer\|supplier" apps/web/src/features --include="*.tsx" | head -20
```

**Assess:**
- [ ] Unified Partner model (customer + supplier)?
- [ ] Partner types flexible?
- [ ] Contact management?
- [ ] Address handling?

### 2.5 Treasury Module

```bash
# Find treasury/payment models
find apps/api/app -name "*Payment*" -o -name "*Treasury*" -o -name "*Repository*" | grep "\.php$"

# Check payment method configuration
grep -rn "PaymentMethod\|payment_method" apps/api/app --include="*.php" | head -20

# Check for reconciliation logic
grep -rn "reconcil\|allocat\|match" apps/api/app --include="*.php" | head -20

# Frontend treasury features
ls -la apps/web/src/features/treasury/ 2>/dev/null
```

**Assess:**
- [ ] Payment repositories (bank, cash) implemented?
- [ ] Payment methods configurable?
- [ ] Payment ↔ Document linking?
- [ ] Basic reconciliation logic?

### 2.6 Internationalization (i18n)

```bash
# Check backend localization
ls -la apps/api/lang/ 2>/dev/null || ls -la apps/api/resources/lang/

# Check frontend localization
ls -la apps/web/src/locales/

# Count translation coverage
find apps/web/src/locales -name "*.json" -exec wc -l {} \;

# Check for hardcoded strings in key files
grep -rn "\"[A-Z][a-z].*\"" apps/web/src/components/layout --include="*.tsx" | head -20
```

**Assess:**
- [ ] Backend translations set up?
- [ ] Frontend translations set up?
- [ ] Core UI fully translated?
- [ ] Remaining hardcoded strings?

### 2.7 Compliance & Audit Foundation

```bash
# Check for hash chain / audit trail
grep -rn "hash\|checksum\|audit\|immutable" apps/api/app --include="*.php" | head -30

# Check for event sourcing
grep -rn "event\|Event\|EventStore\|DomainEvent" apps/api/app --include="*.php" | head -30

# Check for document integrity
grep -rn "integrity\|tamper\|seal" apps/api/app --include="*.php"

# Check for audit log table/model
find apps/api/app -name "*Audit*" -o -name "*Event*" | grep "\.php$"
```

**Assess:**
- [ ] Tier-1 hash chain on documents?
- [ ] Event sourcing implemented?
- [ ] Audit log exists?
- [ ] Immutability enforced on posted documents?

---

## Part 3: Module System Assessment

### 3.1 Module Registration & Discovery

```bash
# Check how modules are registered
grep -rn "ServiceProvider\|module\|Module" apps/api/app/Providers --include="*.php"

# Check for module manifest or config
find apps/api -name "*module*" -type f | xargs cat 2>/dev/null | head -100

# Check for dynamic module loading
grep -rn "loadModule\|registerModule\|enableModule" apps/api/app --include="*.php"
```

**Assess:**
- [ ] Modules use Laravel service providers?
- [ ] Module manifest/registry exists?
- [ ] Modules can be enabled/disabled?

### 3.2 Module Boundaries

```bash
# Check for cross-module imports in backend
for module in $(ls apps/api/app/Modules/); do
  echo "=== $module dependencies ==="
  grep -rn "use App\\\\Modules\\\\" apps/api/app/Modules/$module --include="*.php" | grep -v "use App\\\\Modules\\\\$module" | head -10
done

# Check frontend feature dependencies
for feature in $(ls apps/web/src/features/); do
  echo "=== $feature imports ==="
  grep -rn "from.*features/" apps/web/src/features/$feature --include="*.ts" --include="*.tsx" | grep -v "features/$feature" | head -10
done
```

**Assess:**
- [ ] Modules have clear boundaries?
- [ ] Cross-module dependencies minimal?
- [ ] Circular dependencies present?

### 3.3 Module Configuration per Tenant

```bash
# Check for tenant/company module settings
grep -rn "company.*module\|tenant.*module\|enabled_modules\|active_modules" apps/api --include="*.php"

# Check for module feature flags per tenant
grep -rn "feature\|setting" apps/api/app/Modules --include="*.php" | grep -i "company\|tenant" | head -20

# Check database for module settings
grep -rn "module" apps/api/database/migrations --include="*.php" | head -10
```

**Assess:**
- [ ] Modules can be enabled per company?
- [ ] Module settings stored in database?
- [ ] UI reflects module availability?

---

## Part 4: Business Flow Completeness

### 4.1 Quote Flow

```bash
# Find quote-related code
grep -rn "Quote\|quote" apps/api/app/Modules --include="*.php" | head -30

# Check quote creation endpoint
grep -rn "store\|create" apps/api/app/Modules/*/Presentation/Controllers/*Quote* 2>/dev/null

# Check quote frontend
ls -la apps/web/src/features/*/components/*[Qq]uote* 2>/dev/null
ls -la apps/web/src/features/documents/
```

**Test manually or document:**
- [ ] Create quote works?
- [ ] Add line items works?
- [ ] Save without errors?
- [ ] Convert to order works?

### 4.2 Order Flow

```bash
# Find order-related code
grep -rn "SalesOrder\|Order" apps/api/app/Modules --include="*.php" | grep -v "OrderBy" | head -30

# Check order status transitions
grep -rn "confirm\|ship\|deliver\|complete" apps/api/app/Modules --include="*.php" | head -20
```

**Test manually or document:**
- [ ] Order creation from quote?
- [ ] Order confirmation?
- [ ] Order → Delivery conversion?
- [ ] Order → Invoice conversion?

### 4.3 Invoice Flow

```bash
# Find invoice-related code
grep -rn "Invoice\|invoice" apps/api/app/Modules --include="*.php" | head -30

# Check invoice posting/validation
grep -rn "post\|validate\|finalize" apps/api/app/Modules --include="*.php" | head -20
```

**Test manually or document:**
- [ ] Invoice creation from order?
- [ ] Invoice posting/confirmation?
- [ ] Invoice numbering sequential?
- [ ] Posted invoice immutable?

### 4.4 Payment Flow

```bash
# Check payment recording
grep -rn "recordPayment\|createPayment\|Payment" apps/api/app/Modules/Treasury --include="*.php" | head -20

# Check payment-document linking
grep -rn "invoice_id\|document_id\|allocat" apps/api/app/Modules/Treasury --include="*.php" | head -20
```

**Test manually or document:**
- [ ] Record payment works?
- [ ] Payment linked to invoice?
- [ ] Partial payments supported?
- [ ] Invoice balance updates?

---

## Part 5: Automotive-Specific Code Identification

### 5.1 Vehicle-Related Code

```bash
# Search for vehicle/automotive terms
grep -rn "vehicle\|Vehicle\|VIN\|vin\|license.*plate\|immatriculation" apps/api/app --include="*.php"
grep -rn "vehicle\|Vehicle\|VIN\|licensePlate" apps/web/src --include="*.ts" --include="*.tsx"

# Search for make/model/year
grep -rn "make\|model\|year\|brand" apps/api/app --include="*.php" | grep -iv "email\|date" | head -20
```

**Document:**
- Files containing vehicle-specific code
- How deeply integrated is it?

### 5.2 TecDoc / Parts Catalog

```bash
# Search for TecDoc or parts catalog
grep -rn "tecdoc\|TecDoc\|catalog\|Catalog\|OEM\|cross.*reference" apps/api/app --include="*.php"
grep -rn "tecdoc\|TecDoc\|catalog\|compatibility" apps/web/src --include="*.ts" --include="*.tsx"
```

**Document:**
- Any TecDoc integration code?
- Parts compatibility logic?

### 5.3 Job Cards / Work Orders

```bash
# Search for job card / work order / repair order
grep -rn "job.*card\|work.*order\|repair.*order\|intervention\|JobCard\|WorkOrder" apps/api/app --include="*.php"
grep -rn "jobCard\|workOrder\|repair" apps/web/src --include="*.ts" --include="*.tsx"
```

**Document:**
- Job card functionality exists?
- Where is it located?

### 5.4 Industry-Specific Modules

```bash
# List all modules and identify automotive-specific ones
ls -la apps/api/app/Modules/

# Check module names for automotive hints
find apps/api/app/Modules -maxdepth 1 -type d -exec basename {} \;
```

**Document:**
- Which modules are automotive-specific?
- Which modules are generic (usable by BossCloud)?

---

## Part 6: Shared Code & Types

### 6.1 Shared Packages

```bash
# Examine shared packages
ls -la packages/

# Check shared types
cat packages/shared/types/*.ts 2>/dev/null | head -100

# Check if types are generated from backend
grep -rn "typescript:transform\|generate.*types" apps/api --include="*.php"
find apps/api -name "*TypeScript*" -o -name "*typescript*"
```

**Assess:**
- [ ] Shared types package exists?
- [ ] Types auto-generated from backend?
- [ ] Frontend uses shared types?

### 6.2 API Contracts

```bash
# Check for API documentation / OpenAPI
find . -name "openapi*" -o -name "swagger*" -o -name "*.yaml" | grep -v node_modules | grep -v vendor

# Check for API resource/transformer classes
find apps/api/app -name "*Resource.php" -o -name "*Transformer.php" | head -20

# Check API response consistency
grep -rn "JsonResponse\|response()->json" apps/api/app/Modules --include="*.php" | head -20
```

**Assess:**
- [ ] API documented?
- [ ] Response format consistent?
- [ ] Resources/Transformers used?

---

## Part 7: Test Coverage

### 7.1 Backend Tests

```bash
# Count test files
find apps/api/tests -name "*Test.php" | wc -l

# List test directories
ls -la apps/api/tests/

# Check for module-specific tests
find apps/api/tests -type d -name "*Module*" -o -type d -name "Feature"

# Run tests and capture summary (if safe)
cd apps/api && php artisan test --without-tty 2>&1 | tail -20
```

**Assess:**
- [ ] Unit tests exist?
- [ ] Feature/integration tests exist?
- [ ] Tests passing?
- [ ] Critical flows covered?

### 7.2 Frontend Tests

```bash
# Count test files
find apps/web -name "*.test.*" -o -name "*.spec.*" | wc -l

# Check test configuration
cat apps/web/package.json | grep -A5 "test"

# Check for E2E tests
ls -la apps/web/e2e/ 2>/dev/null || ls -la e2e/ 2>/dev/null
```

**Assess:**
- [ ] Unit tests exist?
- [ ] E2E tests exist?
- [ ] Tests passing?

---

## Part 8: Documentation Status

```bash
# List documentation files
find . -name "*.md" -not -path "*/node_modules/*" -not -path "*/vendor/*" | head -30

# Check for architecture documentation
cat docs/ARCHITECTURE*.md 2>/dev/null | head -50
cat CLAUDE.md 2>/dev/null | head -100

# Check for API documentation
ls -la docs/api/ 2>/dev/null
```

**Assess:**
- [ ] Architecture documented?
- [ ] Module documentation exists?
- [ ] API documentation exists?
- [ ] Setup/deployment documented?

---

## Output: Fork Readiness Report

After completing all sections above, compile your findings into this report structure:

```markdown
# Fork Readiness Report
Generated: [DATE]

## Executive Summary
[2-3 sentences on overall readiness]

## Readiness Score: [X/10]

## Critical Blockers
[List any items that MUST be fixed before forking]

1. ...
2. ...

## High Priority Items
[Items that should be addressed soon after fork]

1. ...
2. ...

## Core Infrastructure Status

| Component | Status | Notes |
|-----------|--------|-------|
| Multi-tenancy | ✅/⚠️/❌ | ... |
| Auth/Permissions | ✅/⚠️/❌ | ... |
| Document Engine | ✅/⚠️/❌ | ... |
| Partner/CRM | ✅/⚠️/❌ | ... |
| Treasury | ✅/⚠️/❌ | ... |
| i18n | ✅/⚠️/❌ | ... |
| Compliance/Audit | ✅/⚠️/❌ | ... |

## Module System Status

| Aspect | Status | Notes |
|--------|--------|-------|
| Module boundaries | ✅/⚠️/❌ | ... |
| Enable/disable per tenant | ✅/⚠️/❌ | ... |
| Cross-module dependencies | ✅/⚠️/❌ | ... |

## Business Flow Completeness

| Flow | Status | Notes |
|------|--------|-------|
| Quote creation | ✅/⚠️/❌ | ... |
| Quote → Order | ✅/⚠️/❌ | ... |
| Order → Delivery | ✅/⚠️/❌ | ... |
| Order → Invoice | ✅/⚠️/❌ | ... |
| Invoice posting | ✅/⚠️/❌ | ... |
| Payment recording | ✅/⚠️/❌ | ... |
| Payment allocation | ✅/⚠️/❌ | ... |

## Code Isolation Analysis

### Generic Code (Safe for both forks)
- Module A
- Module B
- ...

### Automotive-Specific Code (AutoERP only)
- [File/Module]: [Description]
- ...

### Code Requiring Refactoring
- [Description of what needs separation]
- ...

## Test Coverage

| Area | Unit | Integration | E2E |
|------|------|-------------|-----|
| Backend | X% | X% | - |
| Frontend | X% | - | X% |

## Recommendations

### Before Fork
1. [Action item]
2. [Action item]

### Immediately After Fork
1. [Action item for AutoERP]
2. [Action item for BossCloud]

### Technical Debt to Address
1. ...
2. ...
```

---

## Final Instructions

1. Execute each section systematically
2. Take notes as you go
3. If you encounter errors or missing directories, document them
4. Be thorough but efficient — skip sections that clearly don't apply
5. Generate the final report in a new file: `docs/FORK-READINESS-REPORT.md`
6. Commit with message: `docs: add fork readiness audit report`
