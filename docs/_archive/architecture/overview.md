# AutoERP Architecture Overview

> High-level system architecture for AutoERP - a compliance-ready ERP for automotive businesses.

---

## System Purpose

AutoERP is a multi-tenant ERP system designed for:
1. **Automotive service businesses** - mechanics, body shops, glass specialists
2. **Auto parts retailers** - with NF525-ready POS capabilities
3. **Multi-country compliance** - France, Tunisia, UK, Italy, North Africa

---

## Architecture Principles

### 1. Hexagonal Architecture (Ports & Adapters)

```
┌─────────────────────────────────────────────────────────────┐
│                    APPLICATION LAYER                        │
│  Commands, Queries, DTOs, Application Services              │
├─────────────────────────────────────────────────────────────┤
│                      DOMAIN LAYER                           │
│  Entities, Value Objects, Domain Events, Domain Services    │
│  Aggregates, Repositories (interfaces only)                 │
├─────────────────────────────────────────────────────────────┤
│                   INFRASTRUCTURE LAYER                      │
│  Eloquent Repositories, External APIs, File Storage         │
│  Queue Workers, Event Store Implementation                  │
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
| **Fiscal Chain** | Compliance | Invoices, Credit Notes | SHA-256 hash chain per document type |
| **Audit Log** | Fraud Detection | All domain events | Individual event hashes |

**Hash Chain Formula:**
```
Hash = SHA256(genesis_seed | document_data)        # Genesis document
Hash = SHA256(previous_hash | document_data)       # Chained documents
```

Each company has a unique 256-bit `fiscal_chain_seed` for genesis document entropy.

### 3. Multi-Tenancy (Schema-Based)

```
public              → Shared lookup data, tenant registry
tenant_{slug}       → Per-tenant schema with identical tables
```

### 4. CQRS Light

- **Commands** modify state through domain layer
- **Queries** read directly from optimized read models (PostgreSQL views)
- No separate read database (yet)

---

## Module Architecture

Each module follows this structure:

```
Module/
├── Domain/
│   ├── Entities/           # Core business objects
│   ├── ValueObjects/       # Immutable value types
│   ├── Events/             # Domain events
│   ├── Services/           # Domain logic
│   └── Repositories/       # Interfaces only
├── Application/
│   ├── Commands/           # Write operations
│   ├── Queries/            # Read operations
│   ├── DTOs/               # Data transfer objects
│   └── Services/           # Application logic
├── Infrastructure/
│   ├── Repositories/       # Eloquent implementations
│   └── External/           # API clients
└── Presentation/
    ├── Controllers/        # HTTP handlers
    ├── Requests/           # Validation
    └── Resources/          # JSON responses
```

---

## Key Architectural Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Database | PostgreSQL 16+ | Schema isolation, JSONB, TimescaleDB compatibility |
| Multi-tenancy | Schema-based | Data isolation, easy backup/restore per tenant |
| Event storage | Documents table + fiscal fields | Simpler than full event sourcing, sufficient for compliance |
| API style | RESTful | Well-understood, cacheable |
| Frontend state | TanStack Query + Zustand | Server state vs client state separation |

---

## Transaction Boundaries

### Operations Requiring Pessimistic Locking

| Operation | Lock Target | Reason |
|-----------|-------------|--------|
| Stock adjustment | `stock_levels` row | Prevent overselling |
| Invoice posting | `documents` + `sequences` | Sequential numbering |
| Payment recording | `payment_instruments` + `invoices` | Accurate balances |
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

## Related Documentation

- [Tech Stack](./tech-stack.md) - Technologies and versions
- [Data Model](./data-model.md) - Database schema
- [Compliance](./compliance.md) - Fiscal compliance architecture
