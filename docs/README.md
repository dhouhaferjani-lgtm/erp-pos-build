# AutoERP Documentation

> Comprehensive documentation for AI agents and developers working on the AutoERP system.

---

## Quick Navigation

| Section | Description |
|---------|-------------|
| [Architecture Overview](./architecture/overview.md) | System design, tech stack, and principles |
| [Backend Documentation](./architecture/backend.md) | Laravel modules, services, and patterns |
| [Frontend Documentation](./architecture/frontend.md) | React features, components, and state |
| [Database Schema](./architecture/database.md) | Tables, relationships, and indexes |
| [Event-Driven Architecture](./architecture/events.md) | Domain events, audit trail, and event sourcing |
| [Multi-Tenancy Guide](./architecture/multi-tenancy.md) | Tenant & company isolation patterns |
| [Module Architecture](./modules/architecture.md) | Module structure, boundaries, and patterns |
| [Module Reference](./modules/README.md) | Detailed module documentation |
| [API Reference](./api/README.md) | REST API endpoints and contracts |
| [Testing Guide](./api/testing.md) | Test patterns and conventions |
| [AI Agent Guide](./guides/ai-agent-guide.md) | Guidelines for AI agents |

---

## System Overview

**AutoERP** is a compliance-ready, multi-tenant ERP system for automotive service businesses with planned expansion to retail and other verticals.

### Key Capabilities

- **Document Management**: Unified system for quotes, orders, invoices, credit notes, delivery notes
- **Inventory Management**: Stock levels, movements, physical counting with multi-counter support
- **Treasury**: Universal payment methods, instruments, repositories, bank reconciliation
- **Accounting**: Chart of accounts, journal entries, GL posting, financial reports
- **Multi-Tenancy**: Schema-based isolation with company and location hierarchy
- **Fiscal Compliance**: Hash chains, event sourcing, country-specific compliance (NF525, ZATCA ready)
- **Event-Driven**: Complete audit trail for all business operations
- **SaaS Billing**: Subscription management, Stripe integration, manual payments

### Technology Stack

| Layer | Technology | Version |
|-------|------------|---------|
| **Backend** | Laravel | 12.x |
| **Database** | PostgreSQL | 16+ |
| **Frontend** | React + TypeScript | 19.x |
| **State Management** | TanStack Query + Zustand | 5.x / 5.x |
| **Styling** | Tailwind CSS | 4.x |
| **Build Tool** | Vite | 7.x |
| **Queue** | Redis + Horizon | Latest |
| **Event Sourcing** | Spatie | 7.x |
| **Multi-Tenancy** | Stancl | 3.x |

---

## Repository Structure

```
apps/erp/
├── apps/
│   ├── api/                    # Laravel backend
│   │   ├── app/
│   │   │   ├── Modules/        # Domain modules (28 total)
│   │   │   ├── Shared/         # Shared infrastructure
│   │   │   └── Http/           # Controllers, middleware
│   │   ├── database/
│   │   │   ├── migrations/     # Database schema (111 files)
│   │   │   └── seeders/        # Data seeders
│   │   ├── tests/
│   │   │   ├── Unit/           # 44 unit tests
│   │   │   ├── Feature/        # 115 feature tests
│   │   │   └── E2E/            # End-to-end tests
│   │   └── routes/             # API routes
│   │
│   ├── web/                    # React frontend
│   │   ├── src/
│   │   │   ├── features/       # Feature modules (28 total)
│   │   │   ├── components/     # Shared components
│   │   │   ├── hooks/          # Global hooks
│   │   │   ├── stores/         # Zustand stores
│   │   │   ├── lib/            # Utilities
│   │   │   ├── locales/        # i18n translations
│   │   │   └── routes/         # Routing config
│   │   └── e2e/                # Playwright tests
│   │
│   └── mobile/                 # React Native (in development)
│
├── packages/
│   └── shared/                 # Shared TypeScript types
│
└── docs/                       # Documentation (you are here)
    ├── architecture/           # System architecture docs
    │   ├── overview.md
    │   ├── backend.md
    │   ├── frontend.md
    │   ├── database.md
    │   ├── events.md           # NEW: Event-driven architecture
    │   └── multi-tenancy.md    # NEW: Multi-company patterns
    ├── api/                    # API reference
    │   ├── README.md
    │   └── testing.md          # NEW: Testing patterns
    ├── modules/                # Module documentation
    │   ├── README.md
    │   └── architecture.md     # NEW: Module structure guide
    ├── guides/                 # Development guides
    │   ├── ai-agent-guide.md
    │   └── authentication.md
    ├── testing/                # Testing documentation
    ├── planning/               # Future features (not implemented)
    ├── _archive/               # Legacy documentation (v1)
    └── _archive_2/             # Completed tasks and reports
```

---

## Architecture Principles

### 1. Hexagonal Architecture (Ports & Adapters)

```
┌─────────────────────────────────────────────────────────────┐
│                    PRESENTATION LAYER                        │
│              Controllers, Requests, Resources                │
├─────────────────────────────────────────────────────────────┤
│                    APPLICATION LAYER                         │
│              Services, DTOs, Commands, Queries               │
├─────────────────────────────────────────────────────────────┤
│                      DOMAIN LAYER                            │
│          Entities, Value Objects, Domain Services            │
├─────────────────────────────────────────────────────────────┤
│                   INFRASTRUCTURE LAYER                       │
│         Eloquent Repositories, External APIs, Storage        │
└─────────────────────────────────────────────────────────────┘
```

### 2. Module Structure

Each backend module follows this pattern:
```
Module/
├── Domain/
│   ├── EntityName.php        # Eloquent models
│   ├── Events/               # Domain events (immutable)
│   ├── Services/             # Domain logic
│   ├── Enums/                # Type-safe enums
│   └── Observers/            # Model lifecycle hooks
├── Application/
│   ├── DTOs/                 # Data transfer objects
│   └── Services/             # Application orchestration
├── Infrastructure/
│   └── Repositories/         # Eloquent implementations
├── Presentation/
│   ├── Controllers/          # HTTP handlers (thin)
│   ├── Requests/             # Validation
│   └── routes.php            # Module routes
└── Listeners/                # Event listeners
```

### 3. Key Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Database | PostgreSQL | Schema isolation, JSONB, compliance |
| Multi-tenancy | Schema-based | Data isolation, backup/restore |
| Documents | Unified table | Simplified conversions, shared logic |
| Events | Event sourcing | Audit trail, compliance, replay |
| State | TanStack Query | Server state caching, deduplication |
| Types | TypeScript strict | Type safety, IDE support |
| Testing | TDD approach | 160 tests, 100% pass rate |

---

## Critical Rules for Development

### 1. No Placeholder Code
Never leave TODO comments. Write complete implementations or explicitly fail the task.

### 2. Strict Typing
- **PHP**: No `mixed` type. Use DTOs for JSONB columns.
- **TypeScript**: No `any` type. Use `unknown` + type guards.

### 3. Module Boundaries
Cross-module communication ONLY via:
- Interfaces in `Shared/Contracts/`
- Events (for async communication)
- Module's public Service class

### 4. Types Flow from Backend
Never manually edit generated TypeScript types. Run:
```bash
php artisan typescript:transform
```

### 5. Events are Immutable
Once an Event class exists: never rename, change payload, or delete it. Create V2 if requirements change.

### 6. No Hardcoded Strings in Frontend
All user-facing text must use translation keys:
```tsx
const { t } = useTranslation();
<Button>{t('common.save')}</Button>
```

### 7. Test-Driven Development
Write tests FIRST, then implementation. All tests must pass before committing.

---

## Getting Started

### Prerequisites
- PHP 8.2+
- PostgreSQL 16+
- Node.js 20+
- pnpm 9+
- Redis

### Quick Start
```bash
# Clone and install
git clone <repo>
cd apps/erp
pnpm install

# Backend setup
cd apps/api
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed

# Frontend setup
cd ../web
pnpm install

# Start development servers
pnpm dev  # From root - starts all services
```

---

## Documentation Index

### Architecture & Design

- **[System Overview](./architecture/overview.md)** - Technology stack, principles, module inventory
- **[Backend Architecture](./architecture/backend.md)** - Laravel modules, services, patterns, transaction boundaries
- **[Frontend Architecture](./architecture/frontend.md)** - React features, state management, components
- **[Database Schema](./architecture/database.md)** - 85+ tables, relationships, enums, indexes
- **[Event-Driven Architecture](./architecture/events.md)** ⭐ NEW - Domain events, audit trail, fiscal compliance
- **[Multi-Tenancy Guide](./architecture/multi-tenancy.md)** ⭐ NEW - Tenant & company isolation patterns

### Module Development

- **[Module Architecture Guide](./modules/architecture.md)** ⭐ NEW - Module structure, boundaries, cross-module communication
- **[Module Reference](./modules/README.md)** - Detailed documentation for all 28 backend modules

### API & Testing

- **[API Reference](./api/README.md)** - Complete REST API documentation
- **[Testing Guide](./api/testing.md)** ⭐ NEW - Test patterns, conventions, and examples

### Development Guides

- **[AI Agent Guide](./guides/ai-agent-guide.md)** - Guidelines for AI agents working on the codebase
- **[Authentication](./guides/authentication.md)** - Auth flows and security

---

## Recent Architectural Achievements

### Vehicle Module Decoupling ✅
- Core modules have zero vehicle dependencies
- Vehicle data stored as snapshots (immutable)
- Soft references via linking table
- Platform ready for non-automotive verticals

### Multi-Company Support ✅
- Schema-based tenant isolation
- Row-level company scoping
- User can belong to multiple companies
- Company switching support

### Event-Driven Architecture ✅
- 4 new domain events across modules
- Complete audit trail for critical operations
- Fiscal hash chain for compliance
- Foundation for event sourcing

### Fiscal Compliance ✅
- SHA-256 hash chains for invoices/credit notes
- Database immutability triggers
- Sequential numbering per document type
- NF525/ZATCA ready

### Stock Reservation System ✅
- Pessimistic locking for concurrency
- Automatic reservation on order confirmation
- Release on delivery or cancellation
- Multi-location support

### Performance Baselines ✅
- Dashboard: 830ms (target: <1000ms)
- Product list (100 items): 190ms (target: <500ms)
- Invoice creation: 160ms (target: <500ms)
- Partner list (100 items): 160ms (target: <300ms)

---

## Test Coverage

**Current Status (December 2025):**
- **Total Tests:** 160 tests, 19 assertions average
- **Pass Rate:** 100% (160/160 passing)
- **PHPStan:** Level 8 (strict types)
- **Coverage:** 80%+ on domain layer

---

*Documentation Version: 2.0*
*Last Updated: December 2025*
*Prepared for Otospex & IziPOS module development*
