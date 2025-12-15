# AutoERP - Audit & Completion Instructions

> **Claude Code: READ THIS ENTIRE FILE before doing anything.**
> This file takes priority over TASKS-PHASE-2.md until audit is complete.

---

## Context

Phase 2 tasks may be incomplete or have bugs. Before continuing, we need to:
1. Audit what actually works
2. Fix critical bugs
3. Complete remaining tasks
4. Refactor to atomic design

---

## Step 1: Verify Current State (DO THIS FIRST)

Go through TASKS-PHASE-2.md and TEST each feature manually or with automated tests.
For each task, verify it ACTUALLY works, not just that code exists.

### Critical Flows to Test

| # | Flow | How to Test | Status |
|---|------|-------------|--------|
| 1 | Create quote with line items | Save quote → Refresh → Data persists | |
| 2 | Create sales order | Save → Verify in list | |
| 3 | Create invoice | Save → Post → Verify status changes | |
| 4 | Download invoice PDF | Click download → PDF opens | |
| 5 | Create customer | Save → View detail page | |
| 6 | Create supplier | Save → View detail page | |
| 7 | Create product | Save → Search for it | |
| 8 | Product search (Meilisearch) | Type in search → Results appear | |
| 9 | View stock levels | Page loads with data | |
| 10 | Stock adjustment | Adjust qty → Movement recorded | |
| 11 | Create vehicle | Save with valid VIN | |
| 12 | Record payment | Save → Verify in list | |
| 13 | Allocate payment to invoice | Open modal → Allocate → Invoice status updates | |
| 14 | Instrument lifecycle | Create check → Deposit → Clear | |
| 15 | P&L Report | Navigate → Report renders with data | |
| 16 | Aged Receivables Report | Navigate → Report renders | |
| 17 | Sales Report | Navigate → Report renders | |
| 18 | Create user | Send invite / create → User appears in list | |
| 19 | Assign role to user | Edit user → Assign role → Permissions apply | |
| 20 | Company settings | Save settings → Persist on refresh | |

### For Each Flow

- **If it works:** Mark `[x]` in TASKS-PHASE-2.md
- **If it fails:** Mark `[ ]` and add note: `<!-- BUG: description -->`
- **If not implemented:** Mark `[ ]` and add note: `<!-- TODO: not implemented -->`

### Update TASKS-PHASE-2.md

After testing, the file should accurately reflect what's done vs. what's not.

---

## Step 2: Fix Critical Bugs

Before continuing new features, fix these known issues:

### Known Bug: 405 Error on Document Save

**Symptom:** Clicking Save on quote/invoice/order does nothing, console shows 405.

**Debug steps:**
1. Open Network tab in browser DevTools
2. Click Save
3. Check: What URL is being called? What HTTP method?
4. Compare to backend: `php artisan route:list --path=quotes`

**Common causes:**
- Trailing slash mismatch: `POST /api/quotes/` vs `POST /api/quotes`
- Wrong method: `PUT` instead of `POST` for create
- Wrong endpoint: `/api/documents` instead of `/api/quotes`

**Fix:** Update API client calls to match backend routes exactly.

**Verify for ALL document types:**
- `POST /api/quotes`
- `POST /api/sales-orders`
- `POST /api/invoices`
- `POST /api/credit-notes`
- `POST /api/purchase-orders`

### API Convention Reminder

The API client already unwraps responses:

```typescript
// Backend returns:
{ data: Partner[], meta: { total: number } }

// apiGet<T>() returns:
Partner[]  // Already unwrapped!

// Components should expect:
const partners = await apiGet<Partner[]>('/partners');
// NOT: response.data.partners
```

If components expect wrapped responses, they will break. Fix the component, not the API client.

---

## Step 3: Complete Remaining Tasks

After audit and bug fixes, continue with uncompleted tasks in TASKS-PHASE-2.md.

**Rules:**
- Follow task order (Phase 11 → 12 → 13 → etc.)
- TDD: Write test → Fail → Implement → Pass → Commit
- Mark `[x]` only after VERIFIED working
- Run `./scripts/preflight.sh` before marking any phase complete
- Commit after each completed section with conventional commit message

---

## Step 4: Atomic Design Refactor (AFTER All Phases Complete)

Once all functional tasks in TASKS-PHASE-2.md are complete and verified, refactor the component architecture.

### Current Structure (Wrong)

```
src/components/
├── auth/
├── layout/
├── ui/           ← Everything dumped here
└── PlaceholderPage.tsx

src/features/
├── auth/
├── dashboard/
├── documents/
├── partners/
└── treasury/
```

### Target Structure (Atomic Design)

```
src/components/
├── atoms/           # Smallest building blocks, single purpose
│   ├── Button.tsx
│   ├── Input.tsx
│   ├── Label.tsx
│   ├── Badge.tsx
│   ├── Icon.tsx
│   ├── Spinner.tsx
│   ├── Checkbox.tsx
│   ├── Radio.tsx
│   ├── Select.tsx
│   ├── Textarea.tsx
│   └── index.ts     # Re-exports all atoms
│
├── molecules/       # Combinations of atoms
│   ├── SearchInput.tsx       # Input + Icon + Button
│   ├── FormField.tsx         # Label + Input + Error message
│   ├── FilterTabs.tsx        # Multiple Buttons as tabs
│   ├── Breadcrumb.tsx        # Multiple links
│   ├── MenuItem.tsx          # Icon + Label
│   ├── DataCell.tsx          # Text + Badge
│   ├── Pagination.tsx        # Buttons + Text
│   ├── Modal.tsx             # Container for content
│   ├── Dropdown.tsx          # Button + Menu
│   ├── Tabs.tsx              # Tab buttons + panels
│   └── index.ts
│
├── organisms/       # Complex, self-contained sections
│   ├── Sidebar.tsx
│   ├── TopBar.tsx
│   ├── DataTable.tsx
│   ├── DocumentLineEditor.tsx
│   ├── PaymentForm.tsx
│   ├── CustomerForm.tsx
│   ├── ProductForm.tsx
│   ├── ReportChart.tsx
│   ├── KPIWidget.tsx
│   └── index.ts
│
├── templates/       # Page layouts (structure only, no data)
│   ├── DashboardTemplate.tsx
│   ├── ListPageTemplate.tsx
│   ├── DetailPageTemplate.tsx
│   ├── FormPageTemplate.tsx
│   ├── ReportPageTemplate.tsx
│   └── index.ts
│
└── pages/           # Templates + data fetching + business logic
    ├── dashboard/
    │   └── DashboardPage.tsx
    ├── sales/
    │   ├── CustomersPage.tsx
    │   ├── CustomerDetailPage.tsx
    │   ├── CustomerFormPage.tsx
    │   ├── QuotesPage.tsx
    │   ├── QuoteDetailPage.tsx
    │   ├── QuoteFormPage.tsx
    │   ├── SalesOrdersPage.tsx
    │   ├── InvoicesPage.tsx
    │   └── InvoiceDetailPage.tsx
    ├── purchases/
    │   ├── SuppliersPage.tsx
    │   ├── PurchaseOrdersPage.tsx
    │   └── ...
    ├── inventory/
    │   ├── ProductsPage.tsx
    │   ├── StockLevelsPage.tsx
    │   └── ...
    ├── vehicles/
    │   └── VehiclesPage.tsx
    ├── treasury/
    │   ├── PaymentsPage.tsx
    │   ├── InstrumentsPage.tsx
    │   └── ...
    ├── reports/
    │   ├── ProfitLossPage.tsx
    │   ├── AgedReceivablesPage.tsx
    │   └── ...
    └── settings/
        ├── CompanySettingsPage.tsx
        ├── UsersPage.tsx
        └── RolesPage.tsx
```

### Refactor Steps

1. **Create folder structure**
   ```bash
   mkdir -p src/components/{atoms,molecules,organisms,templates,pages}
   mkdir -p src/components/pages/{dashboard,sales,purchases,inventory,vehicles,treasury,reports,settings}
   ```

2. **Categorize existing components**
   
   Review each component and decide:
   - Is it a single UI element? → atom
   - Is it a combination of atoms? → molecule
   - Is it a complex section? → organism
   - Is it a layout structure? → template
   - Does it fetch data and render a full page? → page

3. **Move components one by one**
   
   For each component:
   - Move to correct folder
   - Update its internal imports
   - Update all files that import it
   - Run `pnpm typecheck` to catch broken imports

4. **Create index.ts files for clean exports**
   
   ```typescript
   // src/components/atoms/index.ts
   export { Button } from './Button';
   export { Input } from './Input';
   export { Label } from './Label';
   // ... etc
   ```

5. **Update import style across codebase**
   
   ```typescript
   // Before:
   import { Button } from '../../../components/ui/Button';
   
   // After:
   import { Button, Input, Label } from '@/components/atoms';
   import { SearchInput, FormField } from '@/components/molecules';
   import { DataTable, Sidebar } from '@/components/organisms';
   ```

6. **Verify build**
   ```bash
   pnpm typecheck
   pnpm build
   pnpm test
   ```

7. **Remove old folders**
   - Delete `src/components/ui/` (now empty)
   - Delete `src/components/layout/` (moved to organisms)
   - Delete `src/features/` (pages moved to components/pages)

### Update CLAUDE.md

Add this section to CLAUDE.md:

```markdown
## Component Architecture (Atomic Design)

All components MUST follow atomic design principles:

### Atoms (`src/components/atoms/`)
Single-purpose UI elements with no dependencies on other components.
Examples: Button, Input, Label, Badge, Spinner, Icon, Checkbox

### Molecules (`src/components/molecules/`)
Combinations of atoms that form a functional unit.
Examples: SearchInput (Input + Icon), FormField (Label + Input + Error), Tabs

### Organisms (`src/components/organisms/`)
Complex, self-contained sections composed of molecules and atoms.
Examples: Sidebar, TopBar, DataTable, Forms, KPIWidget

### Templates (`src/components/templates/`)
Page-level layouts that define structure but contain no data or business logic.
Examples: ListPageTemplate, DetailPageTemplate, FormPageTemplate

### Pages (`src/components/pages/`)
Complete pages that combine templates with data fetching and business logic.
Organized by module: sales/, purchases/, inventory/, treasury/, reports/, settings/

### Rules
- NEVER create components outside this structure
- NEVER put business logic in atoms or molecules
- ALWAYS import from index files: `import { Button } from '@/components/atoms'`
- When creating a new component, first decide which level it belongs to
```

---

## Verification Checklist (Before Declaring Done)

Run through this checklist before reporting completion:

### Functional Tests
- [ ] All tasks in TASKS-PHASE-2.md marked `[x]` are actually working
- [ ] No 4xx or 5xx errors in console during normal use
- [ ] Can complete full quote → order → invoice → payment flow
- [ ] Can generate and download PDFs
- [ ] Reports load with data
- [ ] User management works

### Code Quality
- [ ] `./scripts/preflight.sh` passes
- [ ] `pnpm build` succeeds with no errors
- [ ] `pnpm typecheck` passes
- [ ] `pnpm test` all tests pass
- [ ] `php artisan test` all backend tests pass

### Architecture (After Refactor)
- [ ] All components in atomic design folders
- [ ] No components in old `ui/`, `layout/`, `features/` folders
- [ ] All imports updated and working
- [ ] CLAUDE.md updated with component architecture rules

---

## Commit Convention

Use conventional commits:
- `fix(sales): resolve 405 error on quote save`
- `feat(reports): implement P&L report`
- `refactor(components): migrate to atomic design structure`
- `test(treasury): add payment allocation tests`
- `docs(claude): add component architecture guidelines`

---

*Priority: Audit → Fix Bugs → Complete Features → Refactor Architecture*
