# Architecture Overview

> High-level system architecture for AutoERP - a compliance-ready ERP for automotive businesses.

---

## System Purpose

AutoERP is a multi-tenant ERP system designed for:
1. **Automotive service businesses** - mechanics, body shops, glass specialists
2. **Auto parts retailers** - with NF525-ready POS capabilities
3. **Multi-country compliance** - France, Tunisia, UK, Italy, North Africa

---

## Technology Stack

### Backend
| Technology | Version | Purpose |
|------------|---------|---------|
| Laravel | 12.x | PHP framework (PHP 8.2+) |
| PostgreSQL | 16+ | Primary database |
| Redis | 7+ | Cache, queues, sessions |
| Spatie Event Sourcing | 7.12 | Event store |
| Stancl Tenancy | 3.9 | Multi-tenancy |
| Laravel Sanctum | 4.2 | API authentication |
| Laravel Horizon | 5.40 | Queue management |
| Stripe PHP | 19.0 | Payment processing |

### Frontend
| Technology | Version | Purpose |
|------------|---------|---------|
| React | 19.2 | UI library |
| TypeScript | 5.9 | Type safety |
| Vite | 7.2 | Build tool |
| TanStack Query | 5.90 | Server state |
| Zustand | 5.0 | Client state |
| Tailwind CSS | 4.1 | Styling |
| React Router | 7.9 | Routing |
| React Hook Form | 7.67 | Form handling |
| Zod | 4.1 | Validation |
| i18next | 25.6 | Internationalization |

### Dev Tools
| Tool | Purpose |
|------|---------|
| PHPStan (Level 8) | Static analysis |
| Laravel Pint | Code formatting |
| PHPUnit 11.5 | Backend testing |
| Vitest 3.2 | Frontend unit testing |
| Playwright 1.57 | E2E testing |
| ESLint 9.39 | JavaScript linting |

---

## Architectural Patterns

### 1. Hexagonal Architecture (Ports & Adapters)

```
┌─────────────────────────────────────────────────────────────┐
│                    PRESENTATION LAYER                        │
│  Controllers, Form Requests, API Resources, Middleware       │
├─────────────────────────────────────────────────────────────┤
│                    APPLICATION LAYER                         │
│  Application Services, DTOs, Commands, Queries               │
├─────────────────────────────────────────────────────────────┤
│                      DOMAIN LAYER                            │
│  Entities, Value Objects, Domain Events, Domain Services     │
│  Aggregates, Repository Interfaces                           │
├─────────────────────────────────────────────────────────────┤
│                   INFRASTRUCTURE LAYER                       │
│  Eloquent Repositories, External APIs, File Storage          │
│  Queue Workers, Payment Providers, Email Services            │
└─────────────────────────────────────────────────────────────┘
```

**Rules:**
- Domain layer has ZERO dependencies on infrastructure
- All external dependencies are injected via interfaces
- Business logic lives in Domain Services, not Controllers
- Controllers are thin: validate → dispatch → respond

### 2. Event Sourcing with Hash Chains

**Two-Tier Approach:**

| Tier | Purpose | Documents | Storage |
|------|---------|-----------|---------|
| **Fiscal Chain** | Compliance | Invoices, Credit Notes, Payments | SHA-256 hash chain |
| **Audit Log** | Fraud Detection | All domain events | Individual event hashes |

**Hash Chain Formula:**
```
Genesis Hash = SHA256(company_seed | document_data)
Chained Hash = SHA256(previous_hash | document_data)
```

Each company has a unique 256-bit `fiscal_chain_seed` for genesis document entropy.

### 3. Multi-Tenancy (Schema-Based)

```
public              → Shared lookup data, tenant registry
tenant_{slug}       → Per-tenant schema with identical tables
```

**Benefits:**
- Data isolation at database level
- Easy backup/restore per tenant
- Migration path to dedicated databases

### 4. CQRS Light

- **Commands** modify state through domain layer
- **Queries** read directly from optimized read models (PostgreSQL views)
- No separate read database (yet)

---

## Module Architecture

The system is organized into 22 independent modules:

### Core Modules
| Module | Purpose | Files |
|--------|---------|-------|
| Identity | Users, auth, devices, RBAC | 18 |
| Tenant | Multi-tenancy, domains | 12 |
| Company | Companies, locations, fiscal years | 27 |

### Business Modules
| Module | Purpose | Files |
|--------|---------|-------|
| Document | Quotes, orders, invoices, delivery notes | 34 |
| Accounting | Chart of accounts, journal entries | 30 |
| Inventory | Stock levels, movements, counting | 36 |
| Treasury | Payments, instruments, reconciliation | 32 |
| Product | Product catalog | 9 |
| Partner | Customers, suppliers | 8 |
| Pricing | Price lists | 7 |
| Service | Service catalog | 14 |
| Vehicle | Vehicle management | 7 |

### Infrastructure Modules
| Module | Purpose | Files |
|--------|---------|-------|
| Admin | Health monitoring, system metrics | 4 |
| Billing | SaaS subscriptions, Stripe | 28 |
| Compliance | Fiscal compliance, audit | 10 |
| Import | Data import workflows | 11 |
| Dashboard | Dashboard aggregation | 3 |
| Media | File attachments | 6 |
| Communication | Email dispatch | 4 |

---

## Data Flow Patterns

### Document Lifecycle
```
┌─────────┐     ┌───────────┐     ┌────────┐     ┌──────────┐
│  Draft  │ ──► │ Confirmed │ ──► │ Posted │ ──► │   Paid   │
└─────────┘     └───────────┘     └────────┘     └──────────┘
     │                                  │
     ▼                                  ▼
┌───────────┐                    ┌───────────┐
│ Cancelled │                    │ Cancelled │
└───────────┘                    └───────────┘
```

### Document Conversion Workflow
```
Quote ──► Sales Order ──► Invoice ──► Payment
                │
                ▼
         Delivery Note ──► Consolidated Invoice
```

### Payment Recording Flow
```
┌─────────────────────────────────────────────────────────────┐
│                    Payment Received                          │
├─────────────────────────────────────────────────────────────┤
│ 1. Create Payment record                                     │
│ 2. Create PaymentAllocation(s) to invoice(s)                 │
│ 3. Update invoice.balance_due                                │
│ 4. Update invoice.status if fully paid                       │
│ 5. Create JournalEntry:                                      │
│    - Dr. Bank/Cash (repository account)                      │
│    - Dr. Bank Fees (if any)                                  │
│    - Cr. Customer Receivable                                 │
│ 6. Update hash chain                                         │
└─────────────────────────────────────────────────────────────┘
```

---

## Transaction Boundaries

### Operations Requiring Pessimistic Locking

| Operation | Lock Target | Reason |
|-----------|-------------|--------|
| Stock adjustment | `stock_levels` row | Prevent overselling |
| Invoice posting | `documents` + `sequences` | Sequential numbering |
| Payment recording | `payment_instruments` + invoices | Accurate balances |
| Period closing | `fiscal_periods` | Prevent backdating |

### Atomic Transaction Pattern

```php
DB::transaction(function () {
    // 1. Acquire locks
    $stock = StockLevel::lockForUpdate()->find($id);

    // 2. Validate
    if ($stock->quantity < $requested) {
        throw new InsufficientStockException();
    }

    // 3. Create event (with hash if fiscal)
    $event = new StockReserved(...);

    // 4. Update state
    $stock->decrement('quantity', $requested);
});
```

---

## Security Architecture

### Authentication
- **API**: Laravel Sanctum with Bearer tokens
- **Web**: Cookie-based session with CSRF protection
- **Admin**: Separate super_admins table with rate limiting

### Authorization
- **RBAC**: Spatie Laravel Permission with team support
- **Route Guards**: RequirePermission component in frontend
- **API Middleware**: Permission checks in controllers

### Data Protection
- **Tenant Isolation**: Schema-based separation
- **Hash Chains**: Tamper detection for fiscal documents
- **Audit Trail**: All domain events logged with hashes
- **Encryption**: Sensitive data encrypted at rest

---

## Scalability Considerations

### Horizontal Scaling
- Stateless API servers behind load balancer
- Redis for session and cache clustering
- PostgreSQL read replicas for reporting

### Vertical Scaling
- Table partitioning by date (documents, events)
- Materialized views for partner balances
- Partial indexes for active records only

### Performance Optimizations
- Lazy route loading in frontend
- TanStack Query request deduplication
- Stale-while-revalidate caching
- Queue-based heavy operations

---

## Related Documentation

- [Backend Architecture](./backend.md)
- [Frontend Architecture](./frontend.md)
- [Database Schema](./database.md)
- [API Reference](../api/README.md)
