# AutoERP Frontend Architecture

> React patterns, state management, and component guidelines for AutoERP.

---

## Type Safety Rules

**CRITICAL: Types flow from Backend → Frontend. Never reverse.**

### Generated Types

Domain entity types are AUTO-GENERATED from PHP DTOs using `spatie/typescript-transformer`.

```
apps/api/                          packages/shared/types/
├── App/DTOs/                      ├── generated/
│   ├── PartnerData.php    →→→→   │   ├── PartnerData.ts
│   ├── DocumentData.php   →→→→   │   ├── DocumentData.ts
│   └── PaymentData.php    →→→→   │   └── PaymentData.ts
```

**Generation Command:**
```bash
cd apps/api
php artisan typescript:transform
# Output: packages/shared/types/generated/*.ts
```

**Rules:**
1. ❌ NEVER manually edit files in `packages/shared/types/generated/`
2. ❌ NEVER use `any` type for domain entities
3. ✅ ALWAYS modify the PHP DTO first, then run transform
4. ✅ ALWAYS import domain types from `@autoerp/shared`

**Example:**
```typescript
// WRONG - Manual type definition
interface Partner {
  id: string;
  name: string;
}

// RIGHT - Import generated type
import { PartnerData } from '@autoerp/shared';
```

---

## Tech Stack

| Technology | Purpose | Version |
|------------|---------|---------|
| React | UI Library | 18+ |
| TypeScript | Type Safety | 5+ |
| Vite | Build Tool | 5+ |
| TanStack Query | Server State | 5+ |
| Zustand | Client State | 4+ |
| React Router | Routing | 6+ |
| Tailwind CSS | Styling | 4+ |
| Lucide React | Icons | Latest |

---

## Project Structure

```
apps/web/src/
├── components/
│   ├── ui/                    # Base UI components
│   │   ├── Button.tsx
│   │   ├── Input.tsx
│   │   ├── Select.tsx
│   │   ├── Badge.tsx
│   │   ├── Card.tsx
│   │   ├── Table.tsx
│   │   ├── Modal.tsx
│   │   └── index.ts
│   ├── layout/                # Layout components
│   │   ├── Sidebar.tsx
│   │   ├── TopBar.tsx
│   │   ├── PageLayout.tsx
│   │   └── index.ts
│   └── features/              # Feature-specific components
│       ├── documents/
│       ├── partners/
│       ├── treasury/
│       └── inventory/
├── hooks/
│   ├── useAuth.ts
│   ├── useDebounce.ts
│   └── queries/               # React Query hooks
│       ├── useDocuments.ts
│       ├── usePartners.ts
│       └── usePayments.ts
├── lib/
│   ├── api.ts                 # API client
│   ├── utils.ts               # Utilities
│   └── constants.ts
├── stores/                    # Zustand stores
│   ├── authStore.ts
│   ├── uiStore.ts
│   └── index.ts
├── pages/
│   ├── Dashboard.tsx
│   ├── documents/
│   ├── partners/
│   └── treasury/
├── types/
│   └── index.ts               # TypeScript types
└── App.tsx
```

---

## API Client

```typescript
// lib/api.ts
import { useAuthStore } from '@/stores/authStore';

const API_URL = import.meta.env.VITE_API_URL;

class ApiClient {
  private baseUrl: string;

  constructor(baseUrl: string) {
    this.baseUrl = baseUrl;
  }

  private async request<T>(
    endpoint: string,
    options: RequestInit = {}
  ): Promise<T> {
    const token = useAuthStore.getState().token;
    
    const headers: HeadersInit = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      ...(token && { Authorization: `Bearer ${token}` }),
      ...options.headers,
    };

    const response = await fetch(`${this.baseUrl}${endpoint}`, {
      ...options,
      headers,
    });

    if (!response.ok) {
      const error = await response.json();
      throw new ApiError(response.status, error.error?.message || 'Request failed');
    }

    return response.json();
  }

  get<T>(endpoint: string): Promise<T> {
    return this.request<T>(endpoint, { method: 'GET' });
  }

  post<T>(endpoint: string, data?: unknown): Promise<T> {
    return this.request<T>(endpoint, {
      method: 'POST',
      body: data ? JSON.stringify(data) : undefined,
    });
  }

  patch<T>(endpoint: string, data: unknown): Promise<T> {
    return this.request<T>(endpoint, {
      method: 'PATCH',
      body: JSON.stringify(data),
    });
  }

  delete<T>(endpoint: string): Promise<T> {
    return this.request<T>(endpoint, { method: 'DELETE' });
  }
}

export const api = new ApiClient(API_URL);

class ApiError extends Error {
  constructor(public status: number, message: string) {
    super(message);
    this.name = 'ApiError';
  }
}
```

---

## State Management

### Server State (TanStack Query)

Use React Query for ALL server data. Never store server data in Zustand.

```typescript
// hooks/queries/useDocuments.ts
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Document, DocumentFilters, CreateDocumentDTO } from '@/types';

// Query keys factory
export const documentKeys = {
  all: ['documents'] as const,
  lists: () => [...documentKeys.all, 'list'] as const,
  list: (filters: DocumentFilters) => [...documentKeys.lists(), filters] as const,
  details: () => [...documentKeys.all, 'detail'] as const,
  detail: (id: string) => [...documentKeys.details(), id] as const,
};

// Fetch documents list
export function useDocuments(filters: DocumentFilters) {
  return useQuery({
    queryKey: documentKeys.list(filters),
    queryFn: () => api.get<{ data: Document[] }>('/api/v1/documents', { params: filters }),
    select: (response) => response.data,
  });
}

// Fetch single document
export function useDocument(id: string) {
  return useQuery({
    queryKey: documentKeys.detail(id),
    queryFn: () => api.get<{ data: Document }>(`/api/v1/documents/${id}`),
    select: (response) => response.data,
    enabled: !!id,
  });
}

// Create document
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

// Update document
export function useUpdateDocument(id: string) {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (data: Partial<Document>) =>
      api.patch<{ data: Document }>(`/api/v1/documents/${id}`, data),
    onSuccess: (response) => {
      queryClient.setQueryData(documentKeys.detail(id), response);
      queryClient.invalidateQueries({ queryKey: documentKeys.lists() });
    },
  });
}
```

### Client State (Zustand)

Use Zustand ONLY for UI state that doesn't come from the server:
- User preferences
- UI state (sidebar collapsed, theme)
- Form state that needs to persist across navigation

```typescript
// stores/uiStore.ts
import { create } from 'zustand';
import { persist } from 'zustand/middleware';

interface UIState {
  sidebarCollapsed: boolean;
  viewPreferences: Record<string, 'table' | 'cards'>;
  toggleSidebar: () => void;
  setViewPreference: (key: string, view: 'table' | 'cards') => void;
}

export const useUIStore = create<UIState>()(
  persist(
    (set) => ({
      sidebarCollapsed: false,
      viewPreferences: {},
      toggleSidebar: () => set((state) => ({ sidebarCollapsed: !state.sidebarCollapsed })),
      setViewPreference: (key, view) =>
        set((state) => ({
          viewPreferences: { ...state.viewPreferences, [key]: view },
        })),
    }),
    {
      name: 'autoerp-ui',
    }
  )
);
```

---

## Optimistic vs Pessimistic Updates

### Optimistic Updates (Low-Risk Operations)

For operations that rarely fail and can be retried:
- Customer/partner data updates
- Product information changes
- Quote modifications

```typescript
// Optimistic update example
export function useUpdatePartner(id: string) {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (data: Partial<Partner>) =>
      api.patch<{ data: Partner }>(`/api/v1/partners/${id}`, data),
    
    // Optimistic update
    onMutate: async (newData) => {
      await queryClient.cancelQueries({ queryKey: partnerKeys.detail(id) });
      
      const previousData = queryClient.getQueryData<Partner>(partnerKeys.detail(id));
      
      queryClient.setQueryData(partnerKeys.detail(id), (old: Partner) => ({
        ...old,
        ...newData,
      }));
      
      return { previousData };
    },
    
    onError: (err, newData, context) => {
      // Rollback on error
      queryClient.setQueryData(partnerKeys.detail(id), context?.previousData);
      toast.error('Failed to update. Please try again.');
    },
    
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: partnerKeys.detail(id) });
    },
  });
}
```

### Pessimistic Updates (Critical Operations)

For operations involving money, inventory, or compliance:
- Invoice posting
- Payment recording
- Stock adjustments

```typescript
// Pessimistic update example - Invoice posting
export function usePostInvoice(id: string) {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: () => api.post<{ data: Document }>(`/api/v1/documents/${id}/post`),
    
    // NO onMutate - wait for server confirmation
    
    onSuccess: (response) => {
      queryClient.setQueryData(documentKeys.detail(id), response.data);
      queryClient.invalidateQueries({ queryKey: documentKeys.lists() });
      toast.success('Invoice posted successfully');
    },
    
    onError: (error) => {
      toast.error(error.message || 'Failed to post invoice');
    },
  });
}

// Usage in component
function InvoiceActions({ invoice }: { invoice: Document }) {
  const postInvoice = usePostInvoice(invoice.id);
  
  return (
    <Button
      onClick={() => postInvoice.mutate()}
      disabled={postInvoice.isPending}
    >
      {postInvoice.isPending ? (
        <>
          <Spinner className="mr-2" />
          Posting...
        </>
      ) : (
        'Post Invoice'
      )}
    </Button>
  );
}
```

---

## Component Patterns

### Base Component Structure

```typescript
// components/ui/Button.tsx
import { forwardRef, type ButtonHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'primary' | 'secondary' | 'ghost' | 'destructive';
  size?: 'sm' | 'md' | 'lg';
  loading?: boolean;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
  ({ className, variant = 'primary', size = 'md', loading, children, disabled, ...props }, ref) => {
    return (
      <button
        ref={ref}
        className={cn(
          'inline-flex items-center justify-center font-medium rounded-md transition-colors',
          'focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2',
          'disabled:opacity-50 disabled:cursor-not-allowed',
          {
            'bg-primary-600 text-white hover:bg-primary-700': variant === 'primary',
            'bg-slate-100 text-slate-700 hover:bg-slate-200': variant === 'secondary',
            'bg-transparent hover:bg-slate-100': variant === 'ghost',
            'bg-destructive text-white hover:bg-destructive/90': variant === 'destructive',
            'px-3 py-1.5 text-sm': size === 'sm',
            'px-4 py-2 text-sm': size === 'md',
            'px-5 py-2.5 text-base': size === 'lg',
          },
          className
        )}
        disabled={disabled || loading}
        {...props}
      >
        {loading && <Spinner className="mr-2 h-4 w-4" />}
        {children}
      </button>
    );
  }
);

Button.displayName = 'Button';
```

### Page Component Structure

```typescript
// pages/documents/DocumentList.tsx
import { useState } from 'react';
import { useDocuments } from '@/hooks/queries/useDocuments';
import { PageLayout } from '@/components/layout';
import { DocumentTable, DocumentFilters, CreateDocumentModal } from '@/components/features/documents';

export function DocumentListPage() {
  const [filters, setFilters] = useState<DocumentFilters>({
    type: 'invoice',
    status: 'all',
  });
  const [showCreate, setShowCreate] = useState(false);
  
  const { data: documents, isLoading, error } = useDocuments(filters);
  
  return (
    <PageLayout
      title="Documents"
      action={
        <Button onClick={() => setShowCreate(true)}>
          <Plus className="mr-2 h-4 w-4" />
          New Document
        </Button>
      }
    >
      <DocumentFilters value={filters} onChange={setFilters} />
      
      {error && (
        <Alert variant="destructive">
          Failed to load documents: {error.message}
        </Alert>
      )}
      
      {isLoading ? (
        <TableSkeleton rows={10} />
      ) : (
        <DocumentTable documents={documents ?? []} />
      )}
      
      <CreateDocumentModal
        open={showCreate}
        onClose={() => setShowCreate(false)}
      />
    </PageLayout>
  );
}
```

---

## Form Handling

### Using React Hook Form

```typescript
// components/features/partners/PartnerForm.tsx
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

interface PartnerFormProps {
  defaultValues?: Partial<PartnerFormData>;
  onSubmit: (data: PartnerFormData) => void;
  isSubmitting?: boolean;
}

export function PartnerForm({ defaultValues, onSubmit, isSubmitting }: PartnerFormProps) {
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<PartnerFormData>({
    resolver: zodResolver(partnerSchema),
    defaultValues,
  });
  
  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
      <div>
        <Label htmlFor="name">Name *</Label>
        <Input
          id="name"
          {...register('name')}
          error={errors.name?.message}
        />
      </div>
      
      <div>
        <Label htmlFor="email">Email</Label>
        <Input
          id="email"
          type="email"
          {...register('email')}
          error={errors.email?.message}
        />
      </div>
      
      <div>
        <Label htmlFor="vatNumber">VAT Number</Label>
        <Input
          id="vatNumber"
          {...register('vatNumber')}
        />
      </div>
      
      <div className="flex gap-4">
        <Checkbox {...register('isCustomer')}>Customer</Checkbox>
        <Checkbox {...register('isSupplier')}>Supplier</Checkbox>
      </div>
      
      <Button type="submit" loading={isSubmitting}>
        Save Partner
      </Button>
    </form>
  );
}
```

---

## Error Handling

### Global Error Boundary

```typescript
// components/ErrorBoundary.tsx
import { Component, type ReactNode } from 'react';

interface Props {
  children: ReactNode;
  fallback?: ReactNode;
}

interface State {
  hasError: boolean;
  error?: Error;
}

export class ErrorBoundary extends Component<Props, State> {
  state: State = { hasError: false };

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error };
  }

  render() {
    if (this.state.hasError) {
      return this.props.fallback || (
        <div className="flex flex-col items-center justify-center min-h-screen">
          <h1 className="text-2xl font-semibold text-slate-900">Something went wrong</h1>
          <p className="text-slate-500 mt-2">{this.state.error?.message}</p>
          <Button
            className="mt-4"
            onClick={() => window.location.reload()}
          >
            Reload Page
          </Button>
        </div>
      );
    }

    return this.props.children;
  }
}
```

### Query Error Handling

```typescript
// In App.tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 1000 * 60 * 5, // 5 minutes
      retry: (failureCount, error) => {
        // Don't retry on 4xx errors
        if (error instanceof ApiError && error.status >= 400 && error.status < 500) {
          return false;
        }
        return failureCount < 3;
      },
    },
    mutations: {
      onError: (error) => {
        // Global error handling for mutations
        toast.error(error.message || 'An error occurred');
      },
    },
  },
});
```

---

## Loading States

### Skeleton Components

```typescript
// components/ui/Skeleton.tsx
import { cn } from '@/lib/utils';

export function Skeleton({ className }: { className?: string }) {
  return (
    <div className={cn('animate-pulse bg-slate-200 rounded', className)} />
  );
}

export function TableSkeleton({ rows = 5 }: { rows?: number }) {
  return (
    <div className="space-y-2">
      {Array.from({ length: rows }).map((_, i) => (
        <Skeleton key={i} className="h-12 w-full" />
      ))}
    </div>
  );
}

export function CardSkeleton() {
  return (
    <div className="bg-white rounded-lg border p-4 space-y-3">
      <Skeleton className="h-5 w-1/3" />
      <Skeleton className="h-4 w-2/3" />
      <Skeleton className="h-4 w-1/2" />
    </div>
  );
}
```

---

## Testing

### Component Testing with Vitest

```typescript
// components/ui/Button.test.tsx
import { render, screen, fireEvent } from '@testing-library/react';
import { Button } from './Button';

describe('Button', () => {
  it('renders children', () => {
    render(<Button>Click me</Button>);
    expect(screen.getByText('Click me')).toBeInTheDocument();
  });

  it('shows loading spinner when loading', () => {
    render(<Button loading>Submit</Button>);
    expect(screen.getByRole('button')).toBeDisabled();
  });

  it('calls onClick handler', () => {
    const handleClick = vi.fn();
    render(<Button onClick={handleClick}>Click</Button>);
    fireEvent.click(screen.getByRole('button'));
    expect(handleClick).toHaveBeenCalledTimes(1);
  });
});
```

### Query Hook Testing

```typescript
// hooks/queries/useDocuments.test.tsx
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useDocuments } from './useDocuments';

const createWrapper = () => {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return ({ children }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
};

describe('useDocuments', () => {
  it('fetches documents', async () => {
    const { result } = renderHook(
      () => useDocuments({ type: 'invoice' }),
      { wrapper: createWrapper() }
    );

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toBeDefined();
  });
});
```

---

## Accessibility

### Focus Management

```typescript
// After modal opens, focus first input
useEffect(() => {
  if (isOpen) {
    const firstInput = modalRef.current?.querySelector('input');
    firstInput?.focus();
  }
}, [isOpen]);

// Trap focus in modals
import { useFocusTrap } from '@/hooks/useFocusTrap';
```

### Keyboard Navigation

```typescript
// Table row selection with keyboard
function handleKeyDown(e: KeyboardEvent, row: Row) {
  switch (e.key) {
    case 'Enter':
    case ' ':
      onSelect(row);
      break;
    case 'ArrowDown':
      focusNextRow();
      break;
    case 'ArrowUp':
      focusPreviousRow();
      break;
  }
}
```

### Screen Reader Support

```typescript
// Use semantic HTML and ARIA attributes
<table role="grid" aria-label="Documents list">
  <thead>
    <tr>
      <th scope="col">Number</th>
      <th scope="col">Date</th>
    </tr>
  </thead>
  <tbody>
    {documents.map((doc) => (
      <tr key={doc.id} aria-selected={selectedId === doc.id}>
        <td>{doc.number}</td>
        <td>{formatDate(doc.date)}</td>
      </tr>
    ))}
  </tbody>
</table>

// Announce status changes
<div role="status" aria-live="polite">
  {isLoading && 'Loading documents...'}
</div>
```

---

*Document Version: 1.0*
*Last Updated: November 2025*
