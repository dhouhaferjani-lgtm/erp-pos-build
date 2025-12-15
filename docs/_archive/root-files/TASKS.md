# AutoERP - Task Tracker

> **Claude Code: Read this file to know what to work on.**
> Complete tasks in order. Run verification after each task. Never skip verification.

---

## Current Status

**Phase:** 10 - Frontend Application (COMPLETE)
**Task:** All core views implemented
**Last Updated:** 2025-11-30

---

## Verification Protocol

**Test-Driven Development is MANDATORY. Not optional.**

After completing ANY task:

1. ✅ Write the test FIRST (before implementation)
2. ✅ Run test — confirm it FAILS (red)
3. ✅ Write implementation
4. ✅ Run test — confirm it PASSES (green)
5. ✅ Run `./scripts/preflight.sh` (full verification)
6. ✅ Mark task as `[x]` in this file
7. ✅ Commit with conventional commit message
8. ✅ Move to next task

**If verification fails:** Fix → Re-verify → Only proceed when ALL green

**Verification Commands:**
```bash
# Backend (run from apps/api/)
./vendor/bin/phpstan analyse --level=8
./vendor/bin/pint --test
php artisan test --parallel

# Frontend (run from apps/web/)
pnpm typecheck
pnpm lint
pnpm test

# Full preflight (run from root)
./scripts/preflight.sh
```

---

## Phase 1: Infrastructure & Foundation

### 1.1 Repository Setup
- [x] Initialize pnpm monorepo
- [x] Create workspace structure:
  ```
  autoerp/
  ├── apps/
  │   ├── api/          # Laravel 12
  │   └── web/          # React + Vite
  ├── packages/
  │   └── shared/       # Shared TypeScript types
  ├── docs/             # Documentation
  ├── CLAUDE.md
  ├── TASKS.md
  └── pnpm-workspace.yaml
  ```
- [x] Create root `package.json` with workspaces
- [x] Initialize git with `.gitignore`

**Verification:**
```bash
test -f pnpm-workspace.yaml && echo "✓ Workspace config"
test -d apps/api && echo "✓ API directory"
test -d apps/web && echo "✓ Web directory"
pnpm install && echo "✓ Dependencies install"
```

### 1.2 Docker Environment
- [x] Create `docker-compose.yml` with:
  - PostgreSQL 16 with TimescaleDB extension
  - Redis 7
  - Meilisearch
  - MinIO (S3-compatible storage)
- [x] Create `docker-compose.override.yml` for local dev
- [x] Add health checks for all services
- [x] Create `scripts/setup.sh` for first-time setup

**Verification:**
```bash
docker compose up -d
docker compose ps | grep -c "healthy" | grep -q "4" && echo "✓ All services healthy"
docker compose exec postgres pg_isready && echo "✓ PostgreSQL ready"
docker compose exec redis redis-cli ping | grep -q "PONG" && echo "✓ Redis ready"
```

### 1.3 Laravel Foundation
- [x] Install Laravel 12 in `apps/api`
- [x] Configure PostgreSQL connection
- [x] Configure Redis for cache, sessions, queue
- [x] Install and configure packages:
  - `stancl/tenancy` (multi-tenancy)
  - `spatie/laravel-event-sourcing`
  - `spatie/laravel-permission`
  - `laravel/sanctum`
  - `laravel/horizon`
  - `dedoc/scramble` (API docs)
- [x] Configure strict PHP settings (declare strict_types)
- [x] Set up module structure in `app/Modules`

**Verification:**
```bash
cd apps/api
php artisan --version | grep -q "12" && echo "✓ Laravel 12"
php artisan config:cache && echo "✓ Config valid"
php artisan migrate:status && echo "✓ Database connected"
php artisan tinker --execute="Redis::ping()" && echo "✓ Redis connected"
```

### 1.4 React Foundation
- [x] Create React app with Vite in `apps/web`
- [x] Configure TypeScript strict mode
- [x] Install dependencies:
  - `@tanstack/react-query`
  - `zustand`
  - `react-router-dom`
  - `tailwindcss`
  - `lucide-react`
- [x] Set up folder structure
- [x] Create base API client with auth handling

**Verification:**
```bash
cd apps/web
pnpm build && echo "✓ Build succeeds"
pnpm typecheck && echo "✓ TypeScript valid"
pnpm lint && echo "✓ Lint passes"
```

### 1.5 CI/CD Pipeline
- [x] Create `.github/workflows/ci.yml`
- [x] Configure jobs:
  - Backend tests (PHPUnit)
  - Backend static analysis (PHPStan level 8)
  - Frontend tests (Vitest)
  - Frontend type check
  - Frontend lint
- [x] Set up CodeRabbit config (`.coderabbit.yaml`)
- [x] Configure SonarCloud

**Verification:**
```bash
act -j test && echo "✓ CI workflow runs locally"
```

### 1.6 Agent Safety Infrastructure
- [x] Create `scripts/preflight.sh`:
  ```bash
  #!/bin/bash
  set -e
  echo "🔍 Running preflight checks..."

  # Backend
  cd apps/api
  ./vendor/bin/pint --test
  ./vendor/bin/phpstan analyse --level=8
  php artisan test --parallel

  # Type Generation
  php artisan typescript:transform

  # Frontend
  cd ../web
  pnpm typecheck
  pnpm lint
  pnpm test --run

  echo "✅ All preflight checks passed!"
  ```
- [x] Install `spatie/typescript-transformer` in Laravel
- [x] Configure TypeScript transformer for DTOs
- [x] Create `tests/Fixtures/HashChainReference.php` with hardcoded test vectors
- [x] Configure Deptrac for module boundary enforcement
- [x] Create `.deptrac.yaml` with layer definitions

**Verification:**
```bash
chmod +x scripts/preflight.sh
./scripts/preflight.sh
./vendor/bin/deptrac analyse --config-file=.deptrac.yaml
```

### 2.1 Tenant Management
- [x] Create `tenants` table migration
- [x] Configure schema-based tenancy
- [x] Create tenant seeder for development
- [x] Implement tenant creation command
- [x] Add tenant middleware

**Verification:**
```bash
php artisan tenant:create test-tenant
php artisan tinker --execute="Tenant::count()" | grep -q "1"
```

### 2.2 User & Authentication
- [x] Create users table with tenant association
- [x] Implement Sanctum authentication
- [x] Create login/logout endpoints
- [x] Implement device management
- [x] Add biometric auth support structure

**Verification:**
```bash
php artisan test --filter=AuthenticationTest
```

### 2.3 RBAC
- [x] Configure spatie/permission for multi-tenant
- [x] Create default roles: admin, manager, cashier, viewer, technician, accountant
- [x] Create base permissions per module (71 permissions)
- [x] Implement permission middleware
- [x] Create role assignment endpoints

**Verification:**
```bash
php artisan test --filter=RBACTest
```

---

## Phase 3: Core Domain Models

### 3.1 Partners Module
**Step 1 - Write Tests First:**
- [x] Create `tests/Feature/Partner/CreatePartnerTest.php`:
  - Test: name is required
  - Test: email format validation
  - Test: VAT number format by country
  - Test: duplicate detection on VAT number
  - Test: successful creation returns 201
- [x] Create `tests/Feature/Partner/UpdatePartnerTest.php`
- [x] Create `tests/Feature/Partner/ListPartnersTest.php`
- [x] Create `tests/Unit/Partner/PartnerEntityTest.php`

**Step 2 - Run Tests (must fail):**
```bash
php artisan test --filter=Partner
# Expected: All tests should FAIL (classes don't exist yet)
```

**Step 3 - Implement:**
- [x] Create `App\Modules\Partner\Domain\Partner` entity
- [x] Create `App\Modules\Partner\Domain\Enums\PartnerType`
- [x] Create migrations (partners table)
- [x] Create `App\Modules\Partner\Application\DTOs\PartnerData`
- [x] Create CRUD endpoints in `App\Modules\Partner\Presentation\Controllers\`
- [x] Create permission-based route protection

**Step 4 - Verification (must pass):**
```bash
php artisan test --filter=Partner
./vendor/bin/phpstan analyse app/Modules/Partner --level=8
php artisan typescript:transform
./scripts/preflight.sh
```

### 3.2 Products Module
- [x] Create Product entity with ProductType enum
- [x] Create migrations (products table with JSONB for OEM/cross-refs)
- [x] Create ProductData DTO for API responses
- [x] Create CRUD endpoints with permission protection
- [x] Implement automotive-specific fields (OEM numbers, cross-refs)
- [ ] Add product search with Meilisearch (deferred to Phase 10)

**Verification:**
```bash
php artisan test --filter=Product
```

### 3.3 Vehicles Module
- [x] Create Vehicle entity
- [x] Create migrations
- [x] Implement VIN validation (17-char format, excludes I/O/Q)
- [x] Create vehicle CRUD endpoints
- [ ] Link vehicles to work orders (deferred to Phase 4)

**Verification:**
```bash
php artisan test --filter=Vehicle
# ✓ 48 tests passing (151 assertions)
```

---

## Phase 4: Document System

### 4.1 Unified Document Structure
- [x] Create Document aggregate root
- [x] Create migrations (documents, document_lines, document_sequences tables)
- [x] Implement document numbering service
- [x] Create document DTOs and controller
- [x] Implement document status state machine
- [x] Create type-specific routes (quotes, orders, invoices, credit-notes, delivery-notes)

**Verification:**
```bash
php artisan test --filter=Document
# ✓ 63 tests passing (197 assertions)
```

### 4.2 Document Types
- [x] Implement Quote type with metadata
- [x] Implement SalesOrder type
- [x] Implement Invoice type with fiscal fields
- [x] Implement CreditNote type
- [x] Implement DeliveryNote type with DDT

**Verification:**
```bash
php artisan test --filter=Document
# ✓ 31 document type tests passing
```

### 4.3 Document Lifecycle
- [x] Implement quote → order conversion
- [x] Implement order → invoice conversion
- [x] Implement invoice posting (status transition)
- [x] Implement cancellation
- [x] Implement credit note creation from invoice
- [ ] Add document PDF generation (deferred)
- [ ] GL integration (Phase 5)

**Verification:**
```bash
php artisan test --filter=Document
# ✓ All 94 document tests passing (258 assertions)
```

---

## Phase 5: Accounting System (Use Opus 4)

### 5.1 Chart of Accounts
- [x] Create Account entity with AccountType enum
- [x] Create migrations (accounts table with hierarchy support)
- [x] Implement account hierarchy (parent/children relationships)
- [x] Add account CRUD endpoints with tenant isolation
- [x] Add system account protection
- [ ] Create default chart of accounts seeder (deferred)

**Verification:**
```bash
php artisan test --filter=Account
# ✓ 33 tests passing (77 assertions)
```

### 5.2 Journal Entries
- [x] Create JournalEntry aggregate
- [x] Create migrations
- [x] Implement double-entry validation
- [x] Create journal entry service
- [x] Implement hash chain for posted entries

**Verification:**
```bash
php artisan test --filter=JournalEntry
# ✓ 25 tests passing (42 assertions)
```

### 5.3 Document → GL Integration
- [x] Create invoice posting handler
- [x] Generate GL entries from posted invoices
- [x] Handle credit notes with reversals
- [x] Implement payment GL entries
- [ ] Create reconciliation service (deferred)

**Verification:**
```bash
php artisan test --filter=GLIntegration
# ✓ 8 tests passing (25 assertions)
```

---

## Phase 6: Inventory System (Use Opus 4)

### 6.1 Stock Management
- [x] Create StockLevel entity
- [x] Create migrations (stock_levels, locations)
- [x] Implement stock adjustment service with locking
- [x] Create stock movement tracking
- [x] Add low stock alerts

**Verification:**
```bash
php artisan test --filter=StockTest
# ✓ All stock tests passing
```

### 6.2 Stock Movements
- [x] Implement purchase receipt flow
- [x] Implement sales delivery flow
- [x] Implement stock transfers
- [x] Implement stock adjustments
- [x] Create movement history reporting

**Verification:**
```bash
php artisan test --filter=StockMovementTest
# ✓ All stock movement tests passing
```

### 6.3 Delivery Notes (DDT)
- [x] Implement DDT generation
- [x] Link stock movements to delivery notes
- [ ] Create DDT PDF template (deferred)
- [ ] Add signature capture (deferred)

**Verification:**
```bash
php artisan test --filter=DeliveryNoteTest
# ✓ Core delivery note tests passing
```

---

## Phase 7: Treasury System (Use Opus 4)

### 7.1 Payment Methods
- [x] Create PaymentMethod entity with switches
- [x] Create migrations
- [x] Implement country presets
- [x] Create payment method CRUD
- [x] Add fee calculation service

**Verification:**
```bash
php artisan test --filter=PaymentMethodTest
# ✓ All payment method tests passing
```

### 7.2 Payment Repositories
- [x] Create PaymentRepository entity
- [x] Create migrations
- [x] Implement repository balance tracking
- [x] Add repository CRUD endpoints

**Verification:**
```bash
php artisan test --filter=PaymentRepositoryTest
# ✓ All payment repository tests passing
```

### 7.3 Payment Instruments
- [x] Create PaymentInstrument aggregate
- [x] Create migrations
- [x] Implement instrument lifecycle (received → cleared)
- [x] Create custody transfer service
- [x] Add maturity tracking

**Verification:**
```bash
php artisan test --filter=PaymentInstrumentTest
# ✓ All payment instrument tests passing
```

### 7.4 Payments
- [x] Create Payment entity
- [x] Implement payment recording
- [x] Create payment allocation service
- [x] Generate GL entries from payments
- [x] Update invoice paid status

**Verification:**
```bash
php artisan test --filter=PaymentTest
# ✓ All payment tests passing
```

---

## Phase 8: Event Sourcing & Compliance

### 8.1 Event Store
- [x] Configure spatie/laravel-event-sourcing
- [x] Create base event classes
- [x] Implement domain events for all aggregates
- [x] Set up event handlers

**Verification:**
```bash
php artisan test --filter=EventStoreTest
# ✓ All event store tests passing
```

### 8.2 Hash Chains
**CRITICAL: This is compliance-critical code. AI agents cannot "see" hash outputs.**

- [x] Create `tests/Fixtures/HashChainReference.php` with hardcoded test vectors
- [x] Create `tests/Unit/Compliance/FiscalHashServiceTest.php`
- [x] Create `App\Modules\Compliance\Services\FiscalHashService`
- [x] Add hash/previous_hash columns to fiscal documents
- [x] Add hash chains to journal entries
- [x] Create `php artisan fiscal:verify-chains` command

**Verification:**
```bash
php artisan test --filter=FiscalHashServiceTest
php artisan test --filter=HashChainTest
php artisan fiscal:verify-chains
# ✓ All hash chain tests passing
```

### 8.3 Audit Trail
- [x] Create audit_events table (SQLite-compatible)
- [x] Create AuditEvent model with hash generation
- [x] Create AuditService for recording and querying events
- [x] Create AnomalyDetectionService for pattern detection
- [x] Add audit reporting endpoints

**Verification:**
```bash
php artisan test --filter=AuditTrailTest
# ✓ 15 audit trail tests passing
```

---

## Phase 9: Import System

### 9.1 Import Infrastructure
- [x] Create import job tracking (ImportJob model)
- [x] Create staging table (ImportRow model)
- [x] Implement validation engine (ValidationEngine service)
- [x] Create error reporting

**Verification:**
```bash
php artisan test --filter=ImportInfrastructureTest
# ✓ 25 import infrastructure tests passing
```

### 9.2 Import Types
- [x] Implement customer/supplier import
- [x] Implement product import
- [x] Implement stock level import
- [x] Implement opening balance import

**Verification:**
```bash
php artisan test --filter=ImportTypesTest
# ✓ 13 import types tests passing
```

### 9.3 Migration Wizard
- [x] Create wizard API endpoints
- [x] Implement dependency checking
- [x] Add smart column mapping
- [x] Create template generator

**Verification:**
```bash
php artisan test --filter=MigrationWizardTest
# ✓ 13 migration wizard tests passing
```

---

## Phase 10: Frontend Application

### 10.1 Core Layout
- [x] Implement sidebar navigation
- [x] Create top bar with search
- [x] Add authentication flow (LoginPage, AuthProvider, RequireAuth)
- [x] Implement responsive layout (collapsible sidebar on mobile)

**Verification:**
```bash
pnpm test
# ✓ 15 frontend tests passing
```

### 10.2 Partner Management
- [x] Create partner list view
- [x] Create partner detail view
- [x] Add partner form (create/edit)
- [ ] Implement partner search (deferred)

**Verification:**
```bash
pnpm test
# ✓ 30 frontend tests passing (15 partner tests)
```

### 10.3 Document Management
- [x] Create document list view
- [x] Create document detail view
- [x] Implement document form (quote/order/invoice)
- [ ] Add document line editing (deferred)
- [ ] Implement document actions (post, cancel, convert) (deferred)

**Verification:**
```bash
pnpm test
# ✓ 49 frontend tests passing (19 document tests)
```

### 10.4 Treasury
- [x] Create payment recording form
- [x] Implement payment list view
- [x] Create instrument list view (check register)
- [ ] Add instrument lifecycle UI (deferred)
- [ ] Create payment allocation modal (deferred)

**Verification:**
```bash
pnpm test
# ✓ 66 frontend tests passing (17 treasury tests)
```

### 10.5 Dashboard
- [x] Create main dashboard
- [x] Add KPI widgets (Revenue, Invoices, Partners, Payments)
- [x] Implement recent activity (documents, payments)
- [x] Add quick actions (New Quote, New Invoice)

**Verification:**
```bash
pnpm test
# ✓ 72 frontend tests passing (6 dashboard tests)
```

---

## E2E Tests (Playwright)

### Critical Journeys
- [ ] User login/logout
- [ ] Create and post invoice
- [ ] Record payment and allocate
- [ ] Check lifecycle (receive → deposit → clear)
- [ ] Import products from CSV
- [ ] Generate report

**Verification:**
```bash
pnpm playwright test
```

---

## Notes & Decisions

Track any blockers, architectural decisions, or deviations here:

- [x] Decision: Current navigation is simplified scaffolding. Phase 11 will implement proper module-based navigation (Sales/Purchases/Treasury) with type-specific views. The generic `/documents` endpoint is temporary for E2E testing - the type-specific endpoints (`/invoices`, `/quotes`, `/orders`, etc.) are the correct API design.
- [ ] Blocker: [description]
- [ ] Change: [description]

---

## Commit Convention

Use conventional commits:
- `feat(module): description` - New feature
- `fix(module): description` - Bug fix
- `refactor(module): description` - Code change that neither fixes nor adds
- `test(module): description` - Adding tests
- `docs(module): description` - Documentation
- `chore: description` - Maintenance

---

*Last Updated: [timestamp]*
