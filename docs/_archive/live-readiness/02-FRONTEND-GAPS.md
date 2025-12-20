# Frontend Gaps Analysis

> Missing features and settings for tenants

---

## Executive Summary

**Overall Score: 72/100** - Production-ready for core workflows but needs i18n fixes and enhanced settings.

| Category | Score | Key Issue |
|----------|-------|-----------|
| Settings Pages | 7/10 | Missing role editor, subscription |
| Feature Coverage | 7/10 | Many features read-only |
| Form Validation | 7/10 | Missing async validation |
| Route Coverage | 9/10 | Comprehensive |
| i18n Compliance | 4/10 | 50+ hardcoded strings |

---

## 1. Settings Pages Status

### Currently Implemented

#### CompanyPage.tsx ✅ COMPLETE
- Company name, legal name, tax ID, registration number
- Full address management
- Contact info (phone, email, website)
- Logo upload/delete with 2MB validation
- Primary color picker
- Regional settings (currency, timezone, date format, language)

#### UsersPage.tsx ✅ COMPLETE
- User listing with search and status filtering
- Create user modal with role assignment
- Action menu: activate, deactivate, reset password, delete
- Last login tracking

#### RolesPage.tsx ⚠️ READ-ONLY
- List roles with permission count
- Permission matrix display
- **Missing**: Role creation/editing functionality

#### LocationsPage.tsx ✅ COMPLETE
- Create/edit/delete locations
- Location types: shop, warehouse, office, mobile
- Set default location
- POS enablement toggle

### Missing Settings Pages

| Page | Priority | Description |
|------|----------|-------------|
| **Subscription/Billing** | High | `api/subscription.ts` exists but unused |
| **User Profile** | High | Change own password, timezone, avatar |
| **Role Editor** | High | Create/edit roles with permissions |
| **Audit Log Export** | Medium | Download audit trails as CSV |
| **Document Templates** | Medium | Invoice/quote customization |
| **Integration Settings** | Low | API keys, webhooks |

---

## 2. Feature Pages Completeness

### Full CRUD Implementation

| Feature | List | Create | Read | Update | Delete |
|---------|:----:|:------:|:----:|:------:|:------:|
| Partners | ✓ | ✓ | ✓ | ✓ | ✓ |
| Products | ✓ | ✓ | ✓ | ✓ | ✗ |
| Documents | ✓ | ✓ | ✓ | ✓* | ✗ |
| Payments | ✓ | ✓ | ✓ | ✗ | ✗ |
| Vehicles | ✓ | ✓ | ✓ | ✓ | ✗ |
| Services | ✓ | ✓ | ✓ | ✓ | ✗ |
| Price Lists | ✓ | ✓ | ✓ | ✓ | ✗ |
| Chart of Accounts | ✓ | ✓ | ✓ | ✓ | ✗ |
| Journal Entries | ✓ | ✓ | ✓ | ✗ | ✗ |

*Documents: Edit only for drafts

### Read-Only/Limited Features

| Feature | Limitation | Impact |
|---------|------------|--------|
| Instruments | No create/edit/delete | Can't manage checks |
| Repositories | No create/edit/delete | Can't add bank accounts |
| Stock Movements | Audit view only | No manual entry |
| Goods Receipts | List view only | No detail page |
| Service Categories | List only | Can't organize services |

### Reports (All Read-Only)

- Trial Balance
- Profit/Loss Statement
- Balance Sheet
- Aged Receivables
- Aged Payables
- Partner Statements

**Missing**: Export to PDF/Excel, scheduling, custom date ranges

---

## 3. Form Validation Issues

### Good Implementation
- ProductForm: Full validation, OEM arrays
- PartnerForm: Email validation, phone formatting
- PaymentForm: Amount validation, smart allocation
- JournalEntryForm: Debit/credit balance validation

### Missing Validation

| Form | Issue |
|------|-------|
| All forms | No async validation (duplicate SKU, tax ID) |
| All forms | Generic error messages without field context |
| Company form | No server-side validation display |

---

## 4. Navigation & Routes

### Registered Routes (65 total)

```
/ (protected)
├── /dashboard
├── /sales/* (11 routes)
├── /purchases/* (6 routes)
├── /inventory/* (15 routes)
├── /vehicles/* (4 routes)
├── /services/* (5 routes)
├── /treasury/* (7 routes)
├── /reports/* (1 route)
├── /finance/* (8 routes)
├── /pricing/* (4 routes)
├── /settings/* (10 routes)
└── /admin/* (3 routes)
```

### Issues

- No 404 page (redirects to dashboard)
- Reports page has no submenu items in sidebar
- Legacy redirects: `/partners` → `/sales/customers`

---

## 5. API Integration Status

### Connected APIs ✓
- Documents: Full integration
- Treasury: Smart payment allocation
- Import: File upload + validation
- Opening Balances: Complete wizard
- Partners: Full CRUD
- Inventory: Stock levels + movements

### Unused APIs
- `apps/web/src/features/settings/api/subscription.ts`
  - Fully implemented but no UI component uses it

---

## 6. High Priority Fixes

### Phase 1 - Critical (Before Launch)

| Task | Files | Effort |
|------|-------|--------|
| Add delete operations | Multiple pages | 8h |
| Fix hardcoded English in settings | SettingsPage.tsx, UsersPage.tsx | 4h |
| Add role creation UI | RolesPage.tsx | 8h |
| Implement user profile page | New page | 6h |

### Phase 2 - Important

| Task | Files | Effort |
|------|-------|--------|
| Build subscription/billing page | New page | 12h |
| Add document export (PDF) | DocumentDetailPage | 8h |
| Improve error messages | All forms | 6h |
| Add async field validation | Forms | 8h |

### Phase 3 - Enhancement

| Task | Files | Effort |
|------|-------|--------|
| Bulk operations UI | List pages | 12h |
| Report scheduling | ReportsPage | 8h |
| Document templates | New feature | 16h |
| Dashboard customization | DashboardPage | 12h |

---

## 7. Tenant Settings Completeness

### What Tenants CAN Configure

| Setting | Location | Status |
|---------|----------|--------|
| Company info | /settings/company | ✅ |
| Logo & branding | /settings/company | ✅ |
| Users | /settings/users | ✅ |
| Locations | /settings/locations | ✅ |
| Currency | /settings/company | ✅ |
| Timezone | /settings/company | ✅ |
| Date format | /settings/company | ✅ |
| Language | /settings/company | ✅ |

### What Tenants CANNOT Configure

| Setting | Priority | Workaround |
|---------|----------|-----------|
| Payment methods | High | Database seed only |
| Tax rates | High | Database seed only |
| Document sequences | Medium | Auto-generated |
| Invoice templates | Medium | Default only |
| Chart of accounts | Medium | Country seeder only |
| User roles | High | View only |
| Integrations | Low | None available |
| Subscription | High | Not implemented |

---

## 8. Component Patterns

### Positive Patterns ✅
- Modal dialog pattern reused consistently
- FilterTabs component across pages
- SearchInput component standardized
- useMutation from React Query everywhere
- usePermissions hook on protected routes

### Anti-Patterns Found ❌
- Inline form data models (each form defines own interface)
- Duplicate validation logic (email, phone patterns)
- No error boundary component
- Direct API calls in some components

---

## 9. Data Fetching Patterns

### Current Implementation
- React Query with proper cache invalidation
- Dependent queries with `enabled` flag
- Basic offset/limit pagination
- Loading states with spinners

### Missing
- No optimistic updates
- No polling/real-time updates
- Default cache timeout (no custom staleTime)
- Refetch on window focus enabled (can cause unexpected reloads)

---

## 10. Accessibility Status

### Implemented
- Semantic HTML structure
- Form labels associated with inputs
- Focus management in modals

### Missing
- ARIA labels on interactive elements
- Keyboard navigation in data tables
- Screen reader announcements for dynamic content
- Color contrast verification

---

**File**: `docs/live-readiness/02-FRONTEND-GAPS.md`
**Generated**: 2025-12-13
