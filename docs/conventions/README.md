# AutoERP Coding Conventions

> **Last Updated:** 2025-12-30
> **Purpose:** Single source of truth for how things actually work in the codebase

This directory contains focused, digestible documentation of the ACTUAL patterns used in AutoERP. Each document is under 150 lines and covers a specific aspect of the architecture.

## Quick Navigation

### 1. [API Response Conventions](./01-API-RESPONSES.md)
**When to read:** Creating or modifying backend controllers

Learn about:
- Standard response structure (`{ data, meta }`)
- Paginated responses with cursor pagination
- Error response formats (validation, business logic, 404, 401)
- HTTP status code usage
- The `PaginatesResults` trait

**Key Takeaway:** All responses wrap data in `{ data: ..., meta: { timestamp, request_id } }`

---

### 2. [Navigation & Routing Conventions](./02-NAVIGATION-ROUTING.md)
**When to read:** Adding a new page to the dashboard

Learn about:
- Step-by-step guide to add a new page
- Route configuration patterns
- Sidebar navigation structure
- Permission-based filtering
- Translation key conventions

**Key Takeaway:** Four steps: Create page → Add translations → Add route → Add to sidebar

---

### 3. [Authorization Conventions](./03-AUTHORIZATION.md)
**When to read:** Implementing permission checks or protecting routes

Learn about:
- Backend authorization flow
- Required middleware pattern (`['api', 'auth:sanctum', SetPermissionsTeam::class]`)
- Controller permission checks
- Frontend permission system
- `RequirePermission` component usage
- Multi-company and location-level access

**Key Takeaway:** Missing any of the three required middleware causes 401/403 errors

---

### 4. [Frontend Type Generation](./04-FRONTEND-TYPES.md)
**When to read:** Working with PHP DTOs or TypeScript types

Learn about:
- Type generation flow (PHP → TypeScript)
- DTO definition with `#[TypeScript]` attribute + snake_case convention
- `#[DataCollectionOf]` for collection element typing
- Ambient `App.Modules.*.…` global namespace and re-export pattern
- CI drift guard + preflight enforcement
- Monetary-precision gotcha (string fields, `lib/decimal.ts` helpers)

**Key Takeaway:** PHP DTOs are the source of truth; never manually edit `generated.d.ts`. See the [pipeline ADR](../adr/2026-04-19-typescript-types-pipeline.md) for the full architectural decisions.

---

### 5. [React Query Conventions](./05-REACT-QUERY.md)
**When to read:** Fetching data or creating mutations

Learn about:
- Query Client configuration
- `apiGet/apiPost` helpers (auto-unwrapping!)
- Query key factory pattern
- Query and mutation hook patterns
- **Pessimistic UI for financial operations**
- Invalidation strategies

**Key Takeaway:** The `apiGet` helper already unwraps `response.data.data` — don't unwrap again!

---

### 6. [Form Patterns & Validation](./06-FORMS.md)
**When to read:** Creating forms or handling user input

Learn about:
- react-hook-form + zod validation stack
- Simple vs. schema validation patterns
- Form integration with TanStack Query mutations
- Field arrays for dynamic lists
- Error handling and display
- Auto-save with debouncing

**Key Takeaway:** Use pessimistic UI (no optimistic updates) for financial forms

---

### 7. [Dependency Injection](./07-DEPENDENCY-INJECTION.md)
**When to read:** Creating controllers or services

Learn about:
- Constructor injection (the ONLY acceptable pattern)
- Why `app()` helper is forbidden
- When to inject vs when to skip
- Real examples from codebase
- Common mistakes and how to fix them
- Pre-commit checklist

**Key Takeaway:** NEVER use `app()` helper - always inject dependencies via constructor

---

## Common Workflows

### Adding a New Feature
1. Read [02-NAVIGATION-ROUTING.md](./02-NAVIGATION-ROUTING.md) for page setup
2. Read [03-AUTHORIZATION.md](./03-AUTHORIZATION.md) for permission setup
3. Read [05-REACT-QUERY.md](./05-REACT-QUERY.md) for data fetching
4. Read [06-FORMS.md](./06-FORMS.md) if your feature has forms

### Creating a Backend Endpoint
1. Read [07-DEPENDENCY-INJECTION.md](./07-DEPENDENCY-INJECTION.md) for constructor injection
2. Read [01-API-RESPONSES.md](./01-API-RESPONSES.md) for response format
3. Read [03-AUTHORIZATION.md](./03-AUTHORIZATION.md) for middleware setup
4. Read [04-FRONTEND-TYPES.md](./04-FRONTEND-TYPES.md) to expose DTOs

### Debugging Permission Issues
1. Check [03-AUTHORIZATION.md](./03-AUTHORIZATION.md) for required middleware
2. Verify route has `['api', 'auth:sanctum', SetPermissionsTeam::class]`
3. Check controller has `$user->can('permission')` check
4. Verify frontend route wrapped with `<RequirePermission>`

### Type Errors in Frontend
1. Check [04-FRONTEND-TYPES.md](./04-FRONTEND-TYPES.md) for type generation
2. Run `php artisan typescript:transform` to regenerate
3. Check [05-REACT-QUERY.md](./05-REACT-QUERY.md) for API response handling
4. Verify you're not double-unwrapping `apiGet` responses

---

## Document Maintenance

When you discover the codebase differs from these conventions:

1. **The codebase wins** - These docs describe reality, not ideals
2. Update the relevant convention document
3. Note what changed and why in the commit message
4. Ensure all similar patterns in the codebase are consistent

---

## Critical Rules Summary

### Backend
- ✅ Use constructor injection ALWAYS - NEVER use `app()` helper
- ✅ Wrap responses in `{ data: ..., meta: { timestamp, request_id } }`
- ✅ Use `SetPermissionsTeam` middleware on ALL routes
- ✅ Check `$user->can('permission')` FIRST in controllers
- ✅ Return DTOs, not raw models

### Frontend
- ✅ Import types from `@autoerp/shared/types/generated`
- ✅ Use `apiGet/apiPost` helpers (they auto-unwrap)
- ✅ Wrap routes with `<RequirePermission>`
- ✅ Use translation keys for all user-facing text
- ✅ Use pessimistic UI for financial operations

### Forms
- ✅ Use react-hook-form + zod
- ✅ NO optimistic updates for financial forms
- ✅ Invalidate queries after mutations
- ✅ Show toast notifications for feedback

---

**Need help?** Start with the quick navigation above and jump to the relevant section.
