# Translation Audit

> Missing French translations and hardcoded text

---

## Executive Summary

**Overall Translation Coverage: 70%**

- English (en): 100% - All 10 files complete
- French (fr): 99.5% - Missing 3 keys in common.json
- Arabic (ar): 0% - Not started (only .gitkeep exists)

**Hardcoded Text Issues**: 50+ instances across 12+ feature files

---

## 1. Translation File Analysis

### File Structure

```
apps/web/src/locales/
├── en/
│   ├── common.json        ✅ Complete
│   ├── sales.json         ✅ Complete
│   ├── inventory.json     ✅ Complete
│   ├── treasury.json      ✅ Complete
│   ├── finance.json       ✅ Complete
│   ├── auth.json          ✅ Complete
│   ├── validation.json    ✅ Complete
│   ├── vehicles.json      ✅ Complete
│   ├── pricing.json       ✅ Complete
│   └── import.json        ✅ Complete
├── fr/
│   ├── common.json        ⚠️ Missing 3 keys
│   ├── sales.json         ✅ Complete
│   ├── inventory.json     ✅ Complete
│   ├── treasury.json      ✅ Complete
│   ├── finance.json       ✅ Complete
│   ├── auth.json          ✅ Complete
│   ├── validation.json    ✅ Complete
│   ├── vehicles.json      ✅ Complete
│   ├── pricing.json       ✅ Complete
│   └── import.json        ✅ Complete
└── ar/
    └── .gitkeep           ❌ Not implemented
```

---

## 2. Missing French Translation Keys

### In common.json

**Missing `common` section**:
```json
{
  "common": {
    "total": "total",
    "listView": "List view",
    "gridView": "Grid view"
  }
}
```

**French translation needed**:
```json
{
  "common": {
    "total": "total",
    "listView": "Vue en liste",
    "gridView": "Vue en grille"
  }
}
```

**Missing `filters` section**:
```json
{
  "filters": {
    "all": "All",
    "active": "Active",
    "inactive": "Inactive"
  }
}
```

**French translation needed**:
```json
{
  "filters": {
    "all": "Tous",
    "active": "Actif",
    "inactive": "Inactif"
  }
}
```

**Missing `tabs` section**:
```json
{
  "tabs": {
    "overview": "Overview",
    "documents": "Documents",
    "payments": "Payments",
    "vehicles": "Vehicles"
  }
}
```

**French translation needed**:
```json
{
  "tabs": {
    "overview": "Aperçu",
    "documents": "Documents",
    "payments": "Paiements",
    "vehicles": "Véhicules"
  }
}
```

---

## 3. Hardcoded Text by Module

### Settings Module (HIGH PRIORITY)

#### SettingsPage.tsx

| Line | Hardcoded Text |
|------|----------------|
| 62 | "Manage your application settings and configuration" |
| 13-48 | Section array with hardcoded titles/descriptions |

**Sections to translate**:
- "Users" / "Manage user accounts and access"
- "Roles & Permissions" / "Configure roles and their permissions"
- "Company" / "Company information and branding"
- "Data Import" / "Import data from CSV or Excel files"
- "Opening Balances" / "Import initial balances from previous system"

#### Application Info section:
- "Application Info"
- "Version"
- "Environment"
- "API URL"
- "Build Date"

#### UsersPage.tsx

| Line | Hardcoded Text |
|------|----------------|
| 290 | "User Management" |
| 302 | "Add User" |
| 554 | "Add New User" |
| 632 | "An invitation email will be sent..." |

Placeholders (acceptable):
- "John Doe"
- "john@example.com"
- "+33 1 23 45 67 89"

---

### Admin Module (MEDIUM PRIORITY)

#### AuditLogsPage.tsx

| Line | Hardcoded Text |
|------|----------------|
| 35 | "Loading audit logs..." |
| 43 | "Audit Logs" |
| 44 | "Track all administrative actions" |
| 52-65 | Table headers: "Date", "Admin", "Action", "Tenant", "Notes" |
| 73 | "No audit logs yet" |

---

### Documents Module

#### DocumentForm.tsx

| Line | Hardcoded Text |
|------|----------------|
| 77-84 | Select options: "Select type", "Quote", "Sales Order", etc. |

#### Costing Components

**AdditionalCostsForm.tsx**:
- "Loading costs..."
- "Additional Costs"

**LandedCostBreakdown.tsx**:
- "Landed Cost Breakdown"
- "Products Subtotal"
- "Additional Costs"
- "Total Landed Cost"

**DocumentListPage.tsx**:
- "Loading..."

---

### Reports Module

#### ReportsPage.tsx

Labels passed as props (should use t()):
```tsx
label="Quotes"
label="Sales Orders"
label="Invoices"
label="Total Documents"
label="Customers"
label="Suppliers"
label="Total Products"
label="Low Stock"
title="Total Invoiced"
subtitle="invoices"
title="Total Collected"
subtitle="payments"
title="Outstanding"
subtitle="To collect"
title="Quotes Pending"
subtitle="quotes"
```

---

### Inventory Module

#### StockLevelsPage.tsx

Action titles:
- "Receive stock"
- "Issue stock"
- "Adjust stock"
- "Transfer stock"

#### ProductListPage.tsx

- `title="List view"` → Should use `t('common.listView')`
- `title="Grid view"` → Should use `t('common.gridView')`

#### StockMovementsPage.tsx

- `placeholder="Search movements..."`

---

## 4. Translation Coverage by Module

| Module | Files | Status | Issues |
|--------|-------|--------|--------|
| documents | 5+ tsx | 40% | Hardcoded titles, sections |
| partners | 2 tsx | 80% | Minor hardcoded text |
| inventory | 5+ tsx | 50% | Stock action titles |
| treasury | 3+ tsx | 90% | Well translated |
| settings | 4+ tsx | 30% | Major hardcoded UI |
| reports | 1 tsx | 60% | Labels as props |
| admin | 2+ tsx | 20% | Significant hardcoded |
| finance | 3+ tsx | 95% | Well translated |
| components | Many | 85% | Mostly OK |

---

## 5. Action Items

### Priority 1: Add Missing French Keys

Add to `/apps/web/src/locales/fr/common.json`:

```json
{
  "common": {
    "total": "total",
    "listView": "Vue en liste",
    "gridView": "Vue en grille"
  },
  "filters": {
    "all": "Tous",
    "active": "Actif",
    "inactive": "Inactif"
  },
  "tabs": {
    "overview": "Aperçu",
    "documents": "Documents",
    "payments": "Paiements",
    "vehicles": "Véhicules"
  }
}
```

### Priority 2: Refactor Settings Pages

**SettingsPage.tsx**:
1. Create translation keys: `settings.sections.users.title`, etc.
2. Replace hardcoded strings with `t()` calls
3. Translate "Application Info" section

**UsersPage.tsx**:
1. Create `settings.users.*` namespace
2. Replace all hardcoded strings

### Priority 3: Refactor Admin Pages

**AuditLogsPage.tsx**:
1. Create `admin.auditLogs.*` namespace
2. Replace hardcoded strings including table headers

### Priority 4: Refactor Document Components

1. Translate costing component labels
2. Move select options to translation files

### Priority 5: Initialize Arabic

1. Create Arabic translation files (can start with placeholders)
2. Implement RTL support in CSS

---

## 6. Translation Key Conventions

Follow this naming pattern:

```
{namespace}.{feature}.{element}

Examples:
settings.company.title
settings.users.addButton
settings.users.modal.title
admin.auditLogs.noData
documents.costing.breakdown
inventory.stock.actions.receive
```

---

## 7. Pre-Commit Checklist

Before committing frontend code:

- [ ] No hardcoded user-facing text in TSX files
- [ ] All new keys added to en/*.json
- [ ] French translations added to fr/*.json
- [ ] Keys follow naming conventions
- [ ] Tested with French locale

---

## 8. Localization Status

### Dates/Numbers
- Using `Intl.DateTimeFormat` ✅
- Using `Intl.NumberFormat` ✅
- Locale from i18n context ✅

### Missing Localization
- Some error messages hardcoded
- Validation messages not fully translated
- Form placeholders (examples OK, labels not OK)

---

## 9. Files Requiring Changes

| File | Changes Needed | Effort |
|------|----------------|--------|
| `locales/fr/common.json` | Add 3 missing sections | 15min |
| `SettingsPage.tsx` | Replace ~15 hardcoded strings | 1h |
| `UsersPage.tsx` | Replace ~10 hardcoded strings | 45min |
| `AuditLogsPage.tsx` | Replace ~8 hardcoded strings | 30min |
| `ReportsPage.tsx` | Replace ~15 label props | 45min |
| `DocumentForm.tsx` | Move select options to locale | 30min |
| `AdditionalCostsForm.tsx` | Replace 2 hardcoded strings | 15min |
| `LandedCostBreakdown.tsx` | Replace 4 hardcoded strings | 15min |
| `StockLevelsPage.tsx` | Replace 4 action titles | 15min |
| `ProductListPage.tsx` | Use t() for view toggles | 10min |

**Total Estimated Effort**: ~5 hours

---

**File**: `docs/live-readiness/04-TRANSLATION-AUDIT.md`
**Generated**: 2025-12-13
