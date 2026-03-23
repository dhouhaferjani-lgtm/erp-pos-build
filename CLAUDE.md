# AutoERP - Master Architecture Document

> **Claude Code: Read this document before starting any task.**

AutoERP is a compliance-ready, event-sourced ERP system for automotive service businesses (Otospex), with a generic retail variant (IziPOS). Multi-tenant, multi-country (France, Tunisia, UK, Italy, North Africa), with two-tier hash chains for fiscal compliance and fraud detection.

**Multi-app monorepo** (React + Tauri 2 + Laravel): IziPOS (copper theme) and Otospex (pink theme) are separate verticals with different product scoping. They share the same localhost but have separate CSS variable sets, configs, and vertical-scoped products. Never assume one app's config applies to the other. `localStorage`/auth is shared on localhost — be aware of conflicts.

---

## Agent Operational Rules

**CRITICAL: Follow these rules for every task. Violations will cause system failures.**

### 1. No Placeholder Code
Write complete implementations. Never leave `// TODO` or `// Add logic here`. Fail the task explicitly if you cannot complete it.

### 2. Test-First Development (TDD)
Write the test first (PHPUnit backend, Vitest frontend). It must fail (red). Write minimum code to pass (green). Refactor. Verify again.

### 3. Strict Typing
PHP: no `mixed` (use DTOs). TypeScript: no `any` (use `unknown` + type guards). JSONB columns must have a corresponding PHP DTO.

### 4. One Task at a Time — No Scope Creep
Do not modify files outside the current task scope. Note needed changes in other modules and continue. When auditing or fixing, scope work to exactly what the user specifies — do not audit extra areas, include deferred features, or expand scope without explicit confirmation.

### 5. Verification is Law — End-to-End
If verification commands fail, do NOT mark the task complete. Debug immediately. Always verify the end-to-end flow works before considering a task complete — test the critical path (e.g., complete a sale, submit a form) rather than just confirming compilation.

### 6. Module Boundaries are Sacred
Cross-module communication only via `Shared/Contracts/` interfaces, Events, or a module's public Service class. Never import models directly across modules.

### 7. Types Flow from Backend
Never manually edit TypeScript interfaces for domain entities. Run `php artisan typescript:transform` after modifying PHP DTOs. Generated types in `packages/shared/types/` are the source of truth.

### 8. Events are Immutable Forever
Never rename, restructure, or delete an Event class once used. Create versioned replacements: `InvoicePostedV2`.

### 9. Enums for All Status/Type Columns
No magic strings. Every status, type, or code column must use a PHP Enum.

### 10. Pre-Flight Before Commit
Run `./scripts/preflight.sh` (PHPStan, Pint, PHPUnit, TypeScript check, ESLint) before considering any task complete. When pushing to GitHub: check for build artifacts before committing, verify submodule state, confirm branch name before push. Run `git status` once and act on it — don't spend multiple calls figuring out git state.

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

### 16. Plan Then Execute
After plan mode, immediately proceed to implementation unless explicitly told to wait for feedback. Do not get stuck trying to exit plan mode.

### 17. Testing Pitfalls
Always use valid UUIDs for FK columns in tests/seeders. Check actual DB schema for required fields before writing seeders/tests. Test rendered HTML output rather than CSS class names.

### 18. Design Tokens for Tailwind Colors
When editing `.tsx` files in `apps/web/src/`, migrate hardcoded Tailwind color classes (e.g. `bg-blue-600`, `text-gray-700`, `border-red-500`) to design tokens from `lib/designTokens.ts`. Import `tokens`, `textColors`, `borderColors` from `@/lib/designTokens`. Only migrate classes in code you are already touching — do not refactor untouched lines. New feature directories must use tokens exclusively (enforced as ESLint error). A PostToolUse hook will remind you when editing files with hardcoded colors.

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
| Desktop | Tauri 2 (IziPOS) |
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
