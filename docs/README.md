# AutoERP Documentation

> Single source of truth for AutoERP architecture, modules, and features.

---

## Documentation Structure

```
docs/
├── architecture/       # System design and tech decisions
├── modules/            # Backend module documentation
├── features/           # Cross-module feature documentation
├── frontend/           # React/TypeScript web patterns
├── mobile/             # React Native mobile app
├── operations/         # Operational procedures
└── _archive/           # Historical implementation logs
```

---

## Quick Start

### For New Developers

1. [Architecture Overview](./architecture/overview.md) - Understand the system
2. [Tech Stack](./architecture/tech-stack.md) - Technologies used
3. [Module Map](./modules/README.md) - Backend structure
4. [Frontend Guide](./frontend/README.md) - React patterns

### For Feature Work

1. Check [Features](./features/README.md) for existing implementations
2. Read the relevant [Module](./modules/README.md) documentation
3. Review [Data Model](./architecture/data-model.md) for schema

---

## Documentation Index

### Architecture
| Document | Description |
|----------|-------------|
| [Overview](./architecture/overview.md) | High-level architecture |
| [Tech Stack](./architecture/tech-stack.md) | Technologies and versions |
| [Data Model](./architecture/data-model.md) | Database schema |
| [Compliance](./architecture/compliance.md) | Fiscal hash chains |

### Modules
| Module | Description |
|--------|-------------|
| [Overview](./modules/README.md) | Module map and dependencies |
| [Document](./modules/document.md) | Trade documents |
| [Treasury](./modules/treasury.md) | Payments and instruments |
| [Accounting](./modules/accounting.md) | GL and journal entries |
| [Partner](./modules/partner.md) | Customers and suppliers |
| [Inventory](./modules/inventory.md) | Stock management |
| [Identity](./modules/identity.md) | Users and permissions |

### Features
| Feature | Status | Description |
|---------|--------|-------------|
| [Smart Payment](./features/smart-payment.md) | Complete | Payment allocation |
| [Landed Cost](./features/landed-cost.md) | Complete | Cost tracking |
| [Credit Notes](./features/credit-notes.md) | Complete | Invoice corrections |
| [Inventory Counting](./features/inventory-counting.md) | In Progress | Blind counting |

### Frontend (Web)
| Document | Description |
|----------|-------------|
| [Guide](./frontend/README.md) | Quick reference |
| [Architecture](./frontend/architecture.md) | Patterns and structure |
| [Design System](./frontend/design-system.md) | Styling guide |

### Mobile (React Native)
| Document | Description |
|----------|-------------|
| [Mobile App](./mobile/README.md) | React Native app for field operations |

### Operations
| Document | Description |
|----------|-------------|
| [Imports](./operations/imports.md) | Data import procedures |

---

## Related Files

| File | Purpose |
|------|---------|
| `/CLAUDE.md` | AI agent instructions (master) |
| `/AGENTS.md` | AI agent instructions (alternate) |
| `/README.md` | Project readme |

---

## Archive

Historical implementation logs, session summaries, and prompts are in `_archive/`. These are kept for reference but are not maintained.
