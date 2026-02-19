# AutoERP - Master Architecture Document

> **Claude Code: Read this document before starting any task.**

AutoERP is a compliance-ready, event-sourced ERP system for automotive service businesses (Otospex), with a generic retail variant (IziPOS). Multi-tenant, multi-country (France, Tunisia, UK, Italy, North Africa), with two-tier hash chains for fiscal compliance and fraud detection.

---

## Agent Operational Rules

**CRITICAL: Follow these rules for every task. Violations will cause system failures.**

### 1. No Placeholder Code
Write complete implementations. Never leave `// TODO` or `// Add logic here`. Fail the task explicitly if you cannot complete it.

### 2. Test-First Development (TDD)
Write the test first (PHPUnit backend, Vitest frontend). It must fail (red). Write minimum code to pass (green). Refactor. Verify again.

### 3. Strict Typing
PHP: no `mixed` (use DTOs). TypeScript: no `any` (use `unknown` + type guards). JSONB columns must have a corresponding PHP DTO.

### 4. One Task at a Time
Do not modify files outside the current task scope. Note needed changes in other modules and continue.

### 5. Verification is Law
If verification commands fail, do NOT mark the task complete. Debug immediately.

### 6. Module Boundaries are Sacred
Cross-module communication only via `Shared/Contracts/` interfaces, Events, or a module's public Service class. Never import models directly across modules.

### 7. Types Flow from Backend
Never manually edit TypeScript interfaces for domain entities. Run `php artisan typescript:transform` after modifying PHP DTOs. Generated types in `packages/shared/types/` are the source of truth.

### 8. Events are Immutable Forever
Never rename, restructure, or delete an Event class once used. Create versioned replacements: `InvoicePostedV2`.

### 9. Enums for All Status/Type Columns
No magic strings. Every status, type, or code column must use a PHP Enum.

### 10. Pre-Flight Before Commit
Run `./scripts/preflight.sh` (PHPStan, Pint, PHPUnit, TypeScript check, ESLint) before considering any task complete.

### 11. No Hardcoded Strings in Frontend
All user-facing text must use `t()` translation keys via react-i18next. See [i18n reference](.claude/context/i18n.md).

### 12. Module Routes Must Follow Middleware Pattern
All `routes.php` must use `['api', 'auth:sanctum', SetPermissionsTeam::class]`. Missing `'api'` causes 401 errors. Missing `SetPermissionsTeam` causes permission failures. See [docs/conventions/03-AUTHORIZATION.md](docs/conventions/03-AUTHORIZATION.md).

### 13. Constructor Injection Only
All dependencies via constructor with `private readonly`. Never use `app()` helper. See [docs/conventions/07-DEPENDENCY-INJECTION.md](docs/conventions/07-DEPENDENCY-INJECTION.md).

### 14. Frontend API Response Handling
`apiGet`/`apiPost`/etc. already unwrap `response.data.data`. Return them directly — do NOT double-unwrap. See [docs/conventions/01-API-RESPONSES.md](docs/conventions/01-API-RESPONSES.md).

### 15. Session Files Go in `docs/sessions/`
Never create status/plan/implementation markdown files at the repo root. Use `docs/sessions/` for ephemeral session artifacts (gitignored).

---

## Context Files

Read these when working on specific areas:

| File | When to Read |
|------|-------------|
| [`.claude/context/architecture.md`](.claude/context/architecture.md) | Module structure, cross-module rules, transaction boundaries, DB design |
| [`.claude/context/i18n.md`](.claude/context/i18n.md) | Translation setup, RTL properties, key naming, adding translations |
| [`.claude/context/compliance.md`](.claude/context/compliance.md) | Hash chains, fiscal compliance, NF525, e-invoicing |
| [`.claude/context/new-feature-checklist.md`](.claude/context/new-feature-checklist.md) | End-to-end steps for adding a new feature |

## Coding Conventions (REQUIRED)

| Document | Purpose |
|----------|---------|
| [docs/conventions/README.md](docs/conventions/README.md) | Conventions index — start here |
| [docs/conventions/01-API-RESPONSES.md](docs/conventions/01-API-RESPONSES.md) | API response patterns, NO double-unwrap |
| [docs/conventions/02-NAVIGATION-ROUTING.md](docs/conventions/02-NAVIGATION-ROUTING.md) | Adding pages to dashboard |
| [docs/conventions/03-AUTHORIZATION.md](docs/conventions/03-AUTHORIZATION.md) | Permission system, route middleware |
| [docs/conventions/04-FRONTEND-TYPES.md](docs/conventions/04-FRONTEND-TYPES.md) | Type generation from DTOs |
| [docs/conventions/05-REACT-QUERY.md](docs/conventions/05-REACT-QUERY.md) | Data fetching patterns |
| [docs/conventions/06-FORMS.md](docs/conventions/06-FORMS.md) | Form validation with react-hook-form + zod |
| [docs/conventions/07-DEPENDENCY-INJECTION.md](docs/conventions/07-DEPENDENCY-INJECTION.md) | Constructor injection (ONLY pattern) |

## Slash Commands

| Command | Purpose |
|---------|---------|
| `/project:new-module` | Scaffold a complete backend module |
| `/project:new-frontend-feature` | Scaffold a complete frontend feature |
| `/project:add-permissions` | Add permissions to a module |
| `/project:add-i18n-namespace` | Add a new translation namespace |

---

## Tech Stack

| Layer | Technology |
|-------|------------|
| Backend | Laravel 12, PHP 8.2+ (strict types) |
| Database | PostgreSQL 16+ (schema-based multi-tenancy) |
| Cache/Queue | Redis 7+, Laravel Horizon |
| Search | Meilisearch (infrastructure ready, not yet integrated with Scout) |
| Frontend | React 19 / Vite 7 / TypeScript strict / TanStack Query 5 / Zustand 5 |
| Styling | Tailwind CSS 4 (custom design system) |
| Mobile | React Native + Expo (TypeScript) |
| Time-series | TimescaleDB (audit logs) |

---

## Quality Gates

```bash
# Backend
cd apps/api
composer test              # PHPUnit
./vendor/bin/phpstan       # Static analysis (level 8)
./vendor/bin/pint          # Code style

# Frontend
cd apps/web
pnpm test                  # Vitest
pnpm lint                  # ESLint
pnpm typecheck             # TypeScript strict

# All-in-one
./scripts/preflight.sh
```

---

## Domain Documentation

| Document | Purpose |
|----------|---------|
| [`docs/architecture/database.md`](docs/architecture/database.md) | Complete schema documentation |
| [`docs/architecture/frontend.md`](docs/architecture/frontend.md) | React patterns and components |
| [`docs/architecture/design-system.md`](docs/architecture/design-system.md) | Visual design tokens |
| [`docs/modules/treasury.md`](docs/modules/treasury.md) | Payment method configuration |
| [`docs/modules/imports.md`](docs/modules/imports.md) | Data import patterns |
