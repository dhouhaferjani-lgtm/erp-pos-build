# Inventory Counting Module - Implementation Guide

## Overview

This folder contains all documentation and Claude Code prompts needed to implement the complete Inventory Counting module for AutoERP.

## Document Index

| File | Purpose |
|------|---------|
| `README.md` | This file - execution guide and sequence |
| `01-feature-specification.md` | Complete feature design (database, business logic, API) |
| `02-backend-prompt.md` | Claude Code prompt for Laravel backend |
| `03-web-frontend-prompt.md` | Claude Code prompt for React admin interface |
| `04-mobile-app-prompt.md` | Claude Code prompt for Expo mobile app |

---

## Execution Sequence

### Prerequisites

Before starting, ensure:
- [ ] Laravel 12 backend is set up in `apps/api/`
- [ ] React frontend is set up in `apps/web/`
- [ ] Shared types package exists at `packages/shared/`
- [ ] Database migrations are working
- [ ] Authentication is implemented

---

## Step 1: Backend Implementation

**Location:** `apps/api/`

**Prompt:** `02-backend-prompt.md`

**Command:**
```bash
cd /path/to/autoerp/apps/api
claude "Read /path/to/docs/tasks/inventory-counting/02-backend-prompt.md and implement Phase 1 (Database Migrations). After completing each phase, run the verification commands before moving to the next phase."
```

**Phases (in order):**
1. Database Migrations (5 tables)
2. Enums and Value Objects
3. Eloquent Models
4. Core Service Layer
5. Form Requests
6. API Controllers
7. API Routes
8. Authorization Policies
9. Events and Listeners
10. Tests

**Estimated Time:** 4-6 hours

**Verification (after all phases):**
```bash
php artisan migrate:fresh
php artisan test --filter=Inventory
./vendor/bin/phpstan analyse app/Models/Inventory app/Services/Inventory --level=8

# CRITICAL: Check blind counting is enforced
grep -r "theoretical_qty" app/Http/Controllers/Api/V1/Inventory/ | grep -v "reconciliation\|override\|report\|dashboard"
# Should return EMPTY
```

---

## Step 2: Web Frontend Implementation

**Location:** `apps/web/`

**Prompt:** `03-web-frontend-prompt.md`

**Depends on:** Step 1 (Backend) - API endpoints must exist

**Command:**
```bash
cd /path/to/autoerp/apps/web
claude "Read /path/to/docs/tasks/inventory-counting/03-web-frontend-prompt.md and implement Phase 1 (Shared Types). Continue through all phases sequentially."
```

**Phases (in order):**
1. Shared Types (in `packages/shared/`)
2. API Integration (client + React Query hooks)
3. UI Components
4. Pages
5. Routes

**Estimated Time:** 3-4 hours

**Verification:**
```bash
cd apps/web
pnpm tsc --noEmit  # No TypeScript errors
pnpm lint
pnpm build
pnpm dev  # Manual testing
```

---

## Step 3: Mobile App Implementation

**Location:** `apps/mobile/` (new directory)

**Prompt:** `04-mobile-app-prompt.md`

**Depends on:** Step 1 (Backend) - API endpoints must exist

**Can run in parallel with:** Step 2 (Web Frontend)

**Command:**
```bash
cd /path/to/autoerp/apps
claude "Read /path/to/docs/tasks/inventory-counting/04-mobile-app-prompt.md and implement Phase 1 (Project Initialization). This creates a new Expo project in apps/mobile/."
```

**Phases (in order):**
1. Project Initialization (create Expo app)
2. Core Infrastructure (API client, providers)
3. Counting Feature API
4. Zustand Store (offline support)
5. Expo Router Screens
6. Offline Indicator Component

**Estimated Time:** 4-5 hours

**Verification:**
```bash
cd apps/mobile
npx tsc --noEmit
npx expo start
# Test on simulator/emulator
```

---

## Parallel Execution Option

If you want to speed things up, you can run Steps 2 and 3 in parallel after Step 1 is complete:

```
Step 1: Backend (required first)
    ↓
    ├── Step 2: Web Frontend (parallel)
    └── Step 3: Mobile App (parallel)
```

---

## Critical Security Reminders

These MUST be verified at each step:

### Backend
- `counterView()` endpoint must NEVER include `theoretical_qty`
- `toCount()` endpoint must NEVER include other counters' results
- Run grep check after implementation

### Web Frontend
- Reconciliation view is admin-only (shows all data)
- Counter-facing endpoints don't exist in web (counters use mobile)

### Mobile App
- `CountingItem` type must NOT have `theoretical_qty` field
- Item count screen must NOT display expected quantities
- API responses must be validated in tests

---

## Testing Checklist

### Backend Tests
- [ ] `BlindCountingTest` - counters can't see theoretical qty
- [ ] `ReconciliationTest` - algorithm works correctly
- [ ] `SubmitCountTest` - count submission with validation
- [ ] `FinalizeCountingTest` - creates stock adjustments

### Web Frontend Tests
- [ ] Dashboard renders with data
- [ ] Create counting wizard works
- [ ] Reconciliation table shows all counts (admin view)
- [ ] Manual override dialog works
- [ ] Finalization flow works

### Mobile App Tests
- [ ] Tasks list renders
- [ ] Session screen shows progress
- [ ] Item count screen (NO theoretical qty!)
- [ ] Barcode scanner works
- [ ] Offline queue stores and syncs

---

## Troubleshooting

### Backend: Migration errors
```bash
php artisan migrate:status
php artisan migrate:rollback
php artisan migrate
```

### Web: TypeScript errors
```bash
# Regenerate types from shared package
cd packages/shared && pnpm build
cd apps/web && pnpm install
```

### Mobile: Expo build errors
```bash
# Clear cache
npx expo start --clear
# Rebuild native modules
npx expo prebuild --clean
```

---

## Post-Implementation

After all three parts are complete:

1. **Integration Testing**
   - Create a counting operation in web UI
   - Receive notification in mobile app
   - Submit counts from mobile
   - Review reconciliation in web UI
   - Finalize and verify stock adjustments

2. **Documentation Updates**
   - Update API documentation (Scribe)
   - Update user guides
   - Add to release notes

3. **Deployment**
   - Backend: Standard Laravel deployment
   - Web: Build and deploy static assets
   - Mobile: EAS Build → App Store / Play Store
