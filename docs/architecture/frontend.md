# Frontend Architecture

> Complete frontend documentation for the React application at `apps/web/`.

---

## Overview

- **Framework**: React 19.2 + TypeScript 5.9
- **Build Tool**: Vite 7.2
- **State**: TanStack Query 5.90 + Zustand 5.0
- **Styling**: Tailwind CSS 4.1
- **Total Features**: 22
- **Total Pages**: 30+

---

## Project Structure

```
apps/web/src/
├── components/
│   ├── ui/                    # Base UI components
│   ├── layout/                # Layout components
│   ├── atoms/                 # Atomic design atoms
│   ├── molecules/             # Atomic design molecules
│   ├── organisms/             # Atomic design organisms
│   └── templates/             # Page templates
├── features/                  # Feature modules (22 total)
│   ├── admin/                 # Admin dashboard
│   ├── auth/                  # Authentication
│   ├── documents/             # Documents (quotes, invoices, etc.)
│   ├── finance/               # Accounting module
│   ├── inventory/             # Stock management
│   ├── inventory-counting/    # Physical counting
│   ├── treasury/              # Payments
│   └── ...
├── hooks/                     # Global hooks
├── stores/                    # Zustand stores
├── lib/                       # Utilities
├── locales/                   # i18n translations
├── routes/                    # Routing config
└── types/                     # TypeScript types
```

---

## Feature Module Structure

Each feature follows this pattern:

```
feature/
├── pages/                     # Page components (lazy loaded)
├── components/                # Feature-specific components
├── hooks/                     # Feature-specific hooks
├── api/                       # API client functions
├── types.ts                   # Type definitions
└── index.ts                   # Public exports
```

---

## Feature Inventory

### Admin Feature (8 pages)
**Purpose**: Platform administration, multi-tenant management

**Pages**:
- `AdminLoginPage` - Super admin authentication
- `AdminDashboardPage` - Overview metrics
- `TenantsPage` - Tenant management
- `AuditLogsPage` - Admin audit trail
- `BillingDashboardPage` - Billing overview
- `SubscriptionsPage` - Subscription management
- `InvoicesPage` - Platform invoices
- `PaymentsPage` - Payment tracking
- `MonitoringPage` - System health

**API Hooks**:
- `useAdminDashboard()` - Dashboard data
- `useTenants()` - Tenant list
- `useAdminAuditLogs()` - Audit entries
- `useBilling()` - Billing operations
- `useMonitoring()` - Health metrics

---

### Documents Feature (selected pages)
**Purpose**: Unified document management (the feature holds 12 page components across its
type-specific subdirectories; the load-bearing ones for this doc are listed)

**Pages**:
- `DocumentListPage` - List with type filter
- `DocumentForm` - Create/edit
- `DeliveryNoteDetailPage` - Delivery note view with billing attribution
- `ToBillPage` - Un-invoiced delivery notes grouped by customer (`/sales/to-bill`)

> `DeliveryNoteConsolidationPage` was retired: the standalone
> `/inventory/delivery-notes/consolidate` page, its component and its barrel
> export were deleted. Billing a customer's delivery notes now happens from
> `ToBillPage` or from the partner "Delivery notes" tab.

**Props Pattern**:
```tsx
// Same component, different document types
<DocumentListPage documentType="quote" />
<DocumentListPage documentType="invoice" />
<DocumentListPage documentType="credit_note" />
```

**Hooks**:
- `useDocumentEmail()` - Email dispatch
- `useDeliveryNotes()` - DN operations
- `useCreditNotes()` - Credit note creation
- `useRelatedDocuments()` - Linked documents
- `useAttachments()` - File management
- `useAdditionalCosts()` - Landed cost
- `useDocumentPdf()` - PDF generation

---

### Finance Feature (10 pages)
**Purpose**: Accounting and financial reports

**Pages**:
- `ChartOfAccountsPage` - COA management
- `GeneralLedgerPage` - GL view
- `TrialBalancePage` - Trial balance report
- `ProfitLossPage` - P&L statement
- `BalanceSheetPage` - Balance sheet
- `AgedReceivablesPage` - AR aging
- `AgedPayablesPage` - AP aging
- `JournalEntryListPage` - Journal entries
- `JournalEntryForm` - Create entry
- `JournalEntryDetailPage` - Entry view

**Hooks**:
- `useAccounts()` - Account list
- `useLedger()` - Ledger entries
- `useTrialBalance()` - Trial balance
- `useProfitLoss()` - P&L data
- `useBalanceSheet()` - Balance sheet
- `useAgedReceivables()` - AR aging
- `useAgedPayables()` - AP aging
- `useJournalEntries()` - Journal list
- `useJournalEntryMutations()` - Create/post
- `useFinanceSummary()` - Dashboard stats

---

### Inventory Feature (5 pages)
**Purpose**: Product and stock management

**Pages**:
- `ProductListPage` - Product catalog
- `ProductDetailPage` - Product view
- `ProductForm` - Create/edit product
- `StockLevelsPage` - Stock by location
- `StockMovementsPage` - Movement history

---

### Inventory Counting Feature (6 pages)
**Purpose**: Physical inventory counting

**Pages**:
- `CountingDashboardPage` - Counting overview
- `CountingListPage` - Session list
- `CreateCountingPage` - New count
- `CountingDetailPage` - Session view
- `CountingReviewPage` - Review counts
- `DiscrepancyReportPage` - Variance report

---

### Treasury Feature (8 pages)
**Purpose**: Payment management

**Pages**:
- `PaymentListPage` - Payment list
- `PaymentDetailPage` - Payment view
- `PaymentForm` - Record payment
- `InstrumentListPage` - Check/voucher list
- `InstrumentDetailPage` - Instrument view
- `RepositoryListPage` - Safes, registers
- `RepositoryDetailPage` - Repository view
- `BankReconciliationPage` - Bank matching

**Advanced Hooks**:
- `useSmartPayment()` - Smart allocation
- `useReconciliation()` - Bank reconciliation

---

## Routing Architecture

**File**: `src/routes/index.tsx` (1,210 lines)

### Route Structure

```tsx
<Routes>
  {/* Public */}
  <Route path="/login" element={<LoginPage />} />
  <Route path="/admin/login" element={<AdminLoginPage />} />

  {/* Admin (RequireAdminAuth) */}
  <Route path="/admin" element={<AdminLayout />}>
    <Route path="dashboard" element={<AdminDashboardPage />} />
    <Route path="tenants" element={<TenantsPage />} />
    <Route path="billing/*" element={...} />
    {/* ... */}
  </Route>

  {/* Protected (RequireAuth) */}
  <Route path="/" element={<Layout />}>
    <Route path="dashboard" element={<Dashboard />} />

    {/* Sales Module */}
    <Route path="sales/customers" element={<PartnerListPage partnerType="customer" />} />
    <Route path="sales/quotes" element={<DocumentListPage documentType="quote" />} />
    <Route path="sales/orders" element={<DocumentListPage documentType="sales_order" />} />
    <Route path="sales/invoices" element={<DocumentListPage documentType="invoice" />} />
    <Route path="sales/credit-notes" element={<DocumentListPage documentType="credit_note" />} />

    {/* Purchases Module */}
    <Route path="purchases/suppliers" element={<PartnerListPage partnerType="supplier" />} />
    <Route path="purchases/orders" element={<DocumentListPage documentType="purchase_order" />} />

    {/* Inventory Module */}
    <Route path="inventory/products" element={<ProductListPage />} />
    <Route path="inventory/stock" element={<StockLevelsPage />} />
    <Route path="inventory/counting/*" element={...} />

    {/* Treasury Module */}
    <Route path="treasury/payments" element={<PaymentListPage />} />
    <Route path="treasury/instruments" element={<InstrumentListPage />} />
    <Route path="treasury/repositories" element={<RepositoryListPage />} />

    {/* Finance Module */}
    <Route path="finance/chart-of-accounts" element={<ChartOfAccountsPage />} />
    <Route path="finance/ledger" element={<GeneralLedgerPage />} />
    <Route path="finance/trial-balance" element={<TrialBalancePage />} />
    {/* ... */}

    {/* Settings */}
    <Route path="settings/*" element={...} />
  </Route>
</Routes>
```

### Permission Guards

```tsx
// Module-level permission
<RequirePermission moduleKey="sales">
  <DocumentListPage documentType="invoice" />
</RequirePermission>

// Granular permission
<RequirePermission permission="sales.create">
  <DocumentForm documentType="invoice" />
</RequirePermission>
```

---

## State Management

### Server State (TanStack Query)

**Pattern**: Query hooks per feature

```typescript
// Query keys factory
export const documentKeys = {
  all: ['documents'] as const,
  lists: () => [...documentKeys.all, 'list'] as const,
  list: (filters: DocumentFilters) => [...documentKeys.lists(), filters] as const,
  details: () => [...documentKeys.all, 'detail'] as const,
  detail: (id: string) => [...documentKeys.details(), id] as const,
};

// Query hook
export function useDocuments(filters: DocumentFilters) {
  return useQuery({
    queryKey: documentKeys.list(filters),
    queryFn: () => api.get<{ data: Document[] }>('/api/v1/documents', { params: filters }),
    select: (response) => response.data,
  });
}

// Mutation hook
export function useCreateDocument() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (data: CreateDocumentDTO) =>
      api.post<{ data: Document }>('/api/v1/documents', data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: documentKeys.lists() });
    },
  });
}
```

### Client State (Zustand)

**Only for UI state, not server data**

```typescript
// stores/authStore.ts
interface AuthState {
  user: User | null;
  token: string | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  setAuth: (user: User, token: string) => void;
  logout: () => void;
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set) => ({
      user: null,
      token: null,
      isAuthenticated: false,
      isLoading: true,
      setAuth: (user, token) => set({ user, token, isAuthenticated: true }),
      logout: () => set({ user: null, token: null, isAuthenticated: false }),
    }),
    { name: 'autoerp-auth' }
  )
);

// stores/companyStore.ts
interface CompanyState {
  currentCompanyId: string | null;
  companies: Company[];
  setCurrentCompany: (id: string) => void;
}

// stores/locationStore.ts
interface LocationState {
  currentLocationId: string | null;
  locations: Location[];
  setCurrentLocation: (id: string) => void;
}
```

---

## API Layer

### Configuration (`lib/api.ts`)

```typescript
const apiClient = axios.create({
  baseURL: '/api/v1',
  timeout: 30000,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
  withCredentials: true, // Sanctum cookies
});

// Request interceptor - add auth token and company context
apiClient.interceptors.request.use((config) => {
  const token = useAuthStore.getState().token;
  const companyId = useCompanyStore.getState().currentCompanyId;

  if (token) config.headers.Authorization = `Bearer ${token}`;
  if (companyId) config.headers['X-Company-Id'] = companyId;

  return config;
});

// Response interceptor - handle errors
apiClient.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      // Handle unauthorized
    }
    return Promise.reject(error);
  }
);
```

### API Response Format

```typescript
interface ApiResponse<T> {
  data: T;
  meta: {
    timestamp: string;
    request_id: string;
  };
}

interface PaginatedResponse<T> {
  data: T[];
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
}

interface ApiError {
  error: {
    code: string;
    message: string;
    details?: Record<string, unknown>;
  };
  meta: { timestamp: string; request_id: string };
}
```

---

## Component Library

### Atoms (`components/atoms/`)
- `Button` - Primary, secondary, ghost, destructive variants
- `Input` - Text input with error state
- `Textarea` - Multi-line input
- `Select` - Dropdown select
- `FormField` - Field wrapper with label/error
- `Spinner` - Loading indicator

### Molecules (`components/molecules/`)
- `Breadcrumb` - Navigation breadcrumb
- `EmptyState` - No data placeholder
- `FilterTabs` - Tab-based filters
- `MarginIndicator` - Profit margin display
- `PriceInputWithMargin` - Price with margin calc
- `SearchInput` - Search with debounce
- `Tabs` - Tab navigation

### Organisms (`components/organisms/`)
- `Sidebar` - Main navigation
- `TopBar` - Header with user menu
- `Modal` - Dialog modal
- `LocationSelector` - Location picker
- `CompanySelector` - Company picker
- `AddPartnerModal` - Quick add partner
- `RecordPaymentModal` - Payment recording
- `SplitPaymentModal` - Split payment
- `LandedCostBreakdown` - Cost breakdown
- `ProductPricingCard` - Product pricing

### Layout (`components/layout/`)
- `Layout` - Main app layout
- `AdminLayout` - Admin dashboard layout
- `PageLayout` - Page wrapper

---

## Internationalization (i18n)

### Supported Languages
```
en - English (LTR, fallback)
fr - Français (LTR, primary)
ar - العربية (RTL, English fallback)
```

### Translation Namespaces
| Namespace | Purpose | Size |
|-----------|---------|------|
| `common.json` | Shared UI strings | 27.5 KB |
| `sales.json` | Partners, documents | 9 KB |
| `inventory.json` | Products, stock | 16.1 KB |
| `treasury.json` | Payments | 12.9 KB |
| `finance.json` | Accounting | - |
| `validation.json` | Form validation | - |

### Usage Pattern
```tsx
import { useTranslation } from 'react-i18next';

function Component() {
  const { t } = useTranslation(['common', 'sales']);

  return (
    <div>
      <h1>{t('common:appName')}</h1>
      <button>{t('sales:documents.create')}</button>
    </div>
  );
}
```

### RTL Support
Use logical Tailwind properties:
- `ms-*` / `me-*` instead of `ml-*` / `mr-*`
- `ps-*` / `pe-*` instead of `pl-*` / `pr-*`
- `text-start` / `text-end` instead of `text-left` / `text-right`

---

## Form Handling

### Pattern: React Hook Form + Zod

```tsx
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';

const partnerSchema = z.object({
  name: z.string().min(1, 'Name is required'),
  email: z.string().email().optional().or(z.literal('')),
  vatNumber: z.string().optional(),
  isCustomer: z.boolean().default(true),
  isSupplier: z.boolean().default(false),
});

type PartnerFormData = z.infer<typeof partnerSchema>;

function PartnerForm({ onSubmit }: Props) {
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<PartnerFormData>({
    resolver: zodResolver(partnerSchema),
  });

  return (
    <form onSubmit={handleSubmit(onSubmit)}>
      <FormField label="Name" error={errors.name?.message}>
        <Input {...register('name')} />
      </FormField>
      {/* ... */}
    </form>
  );
}
```

---

## Update Patterns

### Optimistic Updates (Low Risk)
```typescript
// For partner updates, product info changes
export function useUpdatePartner(id: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (data) => api.patch(`/partners/${id}`, data),
    onMutate: async (newData) => {
      await queryClient.cancelQueries({ queryKey: ['partner', id] });
      const previous = queryClient.getQueryData(['partner', id]);
      queryClient.setQueryData(['partner', id], (old) => ({ ...old, ...newData }));
      return { previous };
    },
    onError: (err, newData, context) => {
      queryClient.setQueryData(['partner', id], context?.previous);
    },
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: ['partner', id] });
    },
  });
}
```

### Pessimistic Updates (Critical Operations)
```typescript
// For invoice posting, payments - wait for server
export function usePostInvoice(id: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => api.post(`/documents/${id}/post`),
    // NO onMutate - wait for server confirmation
    onSuccess: (response) => {
      queryClient.setQueryData(['document', id], response.data);
      queryClient.invalidateQueries({ queryKey: ['documents'] });
      toast.success('Invoice posted successfully');
    },
    onError: (error) => {
      toast.error(error.message);
    },
  });
}
```

---

## Performance Optimizations

1. **Lazy Route Loading** - All pages loaded on-demand
2. **Suspense Boundaries** - Loading states during lazy load
3. **Query Deduplication** - TanStack Query handles duplicates
4. **Stale-While-Revalidate** - Background refetch
5. **Code Splitting** - Vite automatic chunking

---

## Testing

### Unit Tests (Vitest)
```bash
pnpm test          # Run once
pnpm test:watch    # Watch mode
```

### E2E Tests (Playwright)
```bash
pnpm test:e2e      # Headless
pnpm test:e2e:ui   # With UI
```

### Test Files
```
e2e/
├── auth.spec.ts
├── documents.spec.ts
├── company.spec.ts
├── payments.spec.ts
├── smart-payment.spec.ts
└── ...
```

---

## Build Configuration

### Vite Config
```typescript
export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': './src',
      '@autoerp/shared': '../../packages/shared',
    },
  },
  server: {
    port: 5173,
    proxy: {
      '/api': 'http://localhost:8002',
      '/sanctum': 'http://localhost:8002',
    },
  },
});
```

### Scripts
```json
{
  "dev": "vite",
  "build": "tsc && vite build",
  "preview": "vite preview",
  "lint": "eslint .",
  "typecheck": "tsc --noEmit",
  "test": "vitest run",
  "test:watch": "vitest",
  "test:e2e": "playwright test",
  "test:e2e:ui": "playwright test --ui"
}
```

---

## Related Documentation

- [Architecture Overview](./overview.md)
- [Backend Architecture](./backend.md)
- [Component Library](../frontend/components.md)
- [React Hooks](../frontend/hooks.md)
