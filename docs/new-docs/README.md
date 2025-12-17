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
| [Module Reference](./modules/README.md) | Detailed module documentation |
| [API Reference](./api/README.md) | REST API endpoints and contracts |
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
mecanospex/
├── apps/
│   ├── api/                    # Laravel backend
│   │   ├── app/
│   │   │   ├── Modules/        # Domain modules (22 total)
│   │   │   ├── Shared/         # Shared infrastructure
│   │   │   └── Http/           # Controllers, middleware
│   │   ├── database/
│   │   │   ├── migrations/     # Database schema (90 files)
│   │   │   └── seeders/        # Data seeders
│   │   └── routes/             # API routes
│   │
│   ├── web/                    # React frontend
│   │   ├── src/
│   │   │   ├── features/       # Feature modules (22 total)
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
└── docs/                       # Documentation
    ├── new-docs/               # Comprehensive AI documentation (you are here)
    └── ...                     # Legacy documentation
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
│   ├── Entities/           # Core business objects
│   ├── ValueObjects/       # Immutable value types
│   ├── Events/             # Domain events
│   ├── Services/           # Domain logic
│   └── Enums/              # Type-safe enums
├── Application/
│   ├── DTOs/               # Data transfer objects
│   └── Services/           # Application logic
├── Infrastructure/
│   └── Providers/          # Service providers
└── Presentation/
    ├── Controllers/        # HTTP handlers
    ├── Requests/           # Validation
    └── routes.php          # Module routes
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
Once an Event class exists: never rename, change payload, or delete it.

### 6. No Hardcoded Strings in Frontend
All user-facing text must use translation keys:
```tsx
const { t } = useTranslation();
<Button>{t('common.save')}</Button>
```

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
cd mecanospex
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

### Architecture
- [System Overview](./architecture/overview.md)
- [Backend Architecture](./architecture/backend.md)
- [Frontend Architecture](./architecture/frontend.md)
- [Database Schema](./architecture/database.md)

### Backend Modules
- [Module Index](./modules/README.md)
- [Identity & Authentication](./modules/identity.md)
- [Documents](./modules/documents.md)
- [Inventory](./modules/inventory.md)
- [Treasury](./modules/treasury.md)
- [Accounting](./modules/accounting.md)
- [Billing](./modules/billing.md)

### API Reference
- [API Overview](./api/README.md)
- [Authentication](./api/authentication.md)
- [Documents API](./api/documents.md)
- [Inventory API](./api/inventory.md)
- [Treasury API](./api/treasury.md)

### Frontend
- [Feature Modules](./frontend/features.md)
- [Component Library](./frontend/components.md)
- [React Hooks](./frontend/hooks.md)
- [State Management](./frontend/state.md)

### Guides
- [AI Agent Guide](./guides/ai-agent-guide.md)
- [Development Workflow](./guides/development.md)
- [Testing Guide](./guides/testing.md)

---

*Documentation Version: 1.0*
*Last Updated: December 2025*
