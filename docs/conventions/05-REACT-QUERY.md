# React Query Conventions

> **Purpose:** Data fetching and server state management patterns
> **Last Updated:** 2025-12-30

## Query Client Configuration

**File:** `apps/web/src/main.tsx`

```typescript
const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 1000 * 60 * 5, // 5 minutes
      retry: 1,
      refetchOnWindowFocus: false,
    },
    mutations: {
      retry: 0,  // CRITICAL: No retries (prevents duplicate transactions)
    },
  },
})
```

## API Helper Functions

**File:** `apps/web/src/lib/api.ts`

```typescript
export interface ApiResponse<T> {
  data: T
  meta: { timestamp: string; request_id: string }
}

// These AUTOMATICALLY unwrap response.data.data
export async function apiGet<T>(url: string, params?: Record<string, unknown>): Promise<T> {
  const response = await api.get<ApiResponse<T>>(url, { params })
  return response.data.data  // ← Already unwrapped!
}

export async function apiPost<T>(url: string, data?: unknown): Promise<T> {
  const response = await api.post<ApiResponse<T>>(url, data)
  return response.data.data  // ← Already unwrapped!
}
```

**CRITICAL: Correct Usage**

```typescript
// ✅ CORRECT - apiGet returns unwrapped data
export async function fetchItems(): Promise<Item[]> {
  return apiGet<Item[]>('/items')  // Returns Item[] directly
}

// ❌ WRONG - trying to unwrap again
export async function fetchItems(): Promise<Item[]> {
  const response = await apiGet<{ data: Item[] }>('/items')
  return response.data  // ❌ response is already Item[]!
}
```

## Query Key Factory Pattern

```typescript
export const productKeys = {
  all: ['products'] as const,
  lists: () => [...productKeys.all, 'list'] as const,
  list: (params?: GetProductsParams) => [...productKeys.lists(), params] as const,
  details: () => [...productKeys.all, 'detail'] as const,
  detail: (id: string) => [...productKeys.details(), id] as const,
}

// Usage: queryKey: productKeys.list(params) → ['products', 'list', params]
```

## Tenant-Scoped Query Keys

Tenant-owned data must use `tenantScopedKey([...])` from `apps/web/src/lib/tenantScopedKey.ts`:

```typescript
useQuery({
  queryKey: tenantScopedKey(['products', 'list', params]),
  queryFn: () => getProducts(params),
})
```

The architecture gate `apps/web/tools/audit-tanstack-keys.mjs` scans production TS/TSX for unscoped TanStack query keys. It is wired into `pnpm --filter @autoerp/web lint`, `./scripts/preflight.sh`, and the frontend lint CI job; new unscoped tenant-data keys fail those gates.

`tenantScopedKey()` appends tenant/company at the **suffix**, so naive prefix invalidation can miss filtered leaf keys. For list cascades, use a `predicate` that checks the resource prefix and the final two tenant/company entries; see `apps/web/src/features/inventory/_invalidation.ts` for the inventory predicate pattern.

## `placeholderData` on a scoped key MUST be paired with the scope guard

`placeholderData: keepPreviousData` **survives a company switch** and renders the
previous company's rows. TanStack v5 picks the placeholder from the *observer's*
last query that had data, with **no key-lineage check**
(`query-core` `queryObserver.js` `#lastQueryWithDefinedData`), so the
tenant/company suffix `tenantScopedKey` appends does not stop it — and
`CompanySelector` only invalidates the cache, it never unmounts the page. Until
the new fetch settles, the operator reads, links into and acts on another
company's records.

It is still legitimate **within** one scope — paging 1 → 2 must not flash an
empty table — so the rule is pairing, not a ban:

```typescript
const { data, isLoading, isPlaceholderData } = useQuery({
  queryKey: tenantScopedKey(['payments', search, page, perPage]),
  queryFn: fetchPayments,
  placeholderData: keepPreviousData,
})

// Blanks the rows only when the placeholder predates a scope change.
const isStaleScopeData = usePlaceholderScopeGuard(isPlaceholderData, data !== undefined)
const rows = isStaleScopeData ? [] : data?.data ?? []
const meta = isStaleScopeData ? undefined : data?.meta

<DataTable data={rows} isLoading={isLoading || isStaleScopeData} />
```

Gate **every** derived value on `isStaleScopeData`, not just the rows: header
counts, pagination meta and any action target read the same `data`.

If the key carries a dimension beyond tenant/company — `locationScopedKey`
appends a location scope — pass it as the third argument
(`usePlaceholderScopeGuard(isPlaceholderData, data !== undefined, [normalizeViewScope(scope)])`),
or the placeholder crosses that dimension unguarded. Page/offset/sort segments
stay out: keeping the previous slice of the same result set is the whole point.

**When the key varies per input rather than per page** — e.g. a preview keyed on
the whole cart shape — every placeholder is an answer to a different question.
There is no same-scope win to preserve, so **drop `placeholderData`** instead of
guarding it; `apps/web/src/features/pos/hooks/useDiscountPreview.ts` is the
worked example.

Enforced by `apps/web/tools/audit-tanstack-keys.mjs`: `placeholderData` on a
`tenantScopedKey`/`locationScopedKey` read in a file that does not use
`usePlaceholderScopeGuard` fails Gate C.

## Query Hook Pattern

```typescript
export function useProducts(params?: GetProductsParams) {
  return useQuery({
    queryKey: productKeys.list(params),
    queryFn: () => getProducts(params),
    staleTime: 60000, // 1 minute
  })
}

export function useProduct(id: string) {
  return useQuery({
    queryKey: productKeys.detail(id),
    queryFn: () => getProduct(id),
    enabled: Boolean(id), // Don't fetch if id is undefined
  })
}
```

## Mutation Hook Pattern

```typescript
export function useCreateAccount() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: createAccount,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['accounts'] })
      toast.success('Account created successfully')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}
```

## Financial Operations: Pessimistic UI

**CRITICAL: NO optimistic updates for financial operations**

```typescript
export function useCreateCreditNote() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (request: CreateCreditNoteRequest) => createCreditNote(request),
    onSuccess: (creditNote) => {
      // Wait for server response BEFORE invalidating
      void queryClient.invalidateQueries({ queryKey: ['credit-notes'] })
      void queryClient.invalidateQueries({
        queryKey: ['invoice', creditNote.source_invoice_id]
      })
      toast.success('Credit note created')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
    // ← No onMutate/rollback = pessimistic UI
  })
}
```

## Invalidation Strategies

### Selective Invalidation

```typescript
export function useUpdateCategory() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, data }) => updateCategory(id, data),
    onSuccess: (_, variables) => {
      // Invalidate specific item
      queryClient.invalidateQueries({ queryKey: categoryKeys.detail(variables.id) })
      // Invalidate lists
      queryClient.invalidateQueries({ queryKey: categoryKeys.lists() })
      // Invalidate trees
      queryClient.invalidateQueries({ queryKey: categoryKeys.trees() })
    },
  })
}
```

### Broad Invalidation (Delete)

```typescript
export function useDeleteCategory() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: deleteCategory,
    onSuccess: () => {
      // Invalidate everything related to categories
      queryClient.invalidateQueries({ queryKey: categoryKeys.all })
    },
  })
}
```

## Caching Strategy

| Data Type | staleTime | Reason |
|-----------|-----------|---------|
| Products | 60s | Frequently updated (inventory) |
| Locations | 300s | Rarely changes |
| Categories | 60-300s | Stable hierarchy |
| Accounts | 300s | Chart rarely changes |
| Documents | 300s | Default for posted docs |
| Config | 300s+ | Static configuration |

## Component Usage Example

```typescript
function ProductsPage() {
  const { data: products, isLoading } = useProducts()
  const createMutation = useCreateProduct()

  const handleCreate = (formData: CreateProductRequest) => {
    createMutation.mutate(formData, {
      onSuccess: (product) => {
        navigate(`/products/${product.id}`)
      },
    })
  }

  if (isLoading) return <Loading />

  return (
    <div>
      <button
        onClick={() => setCreateOpen(true)}
        disabled={createMutation.isPending}
      >
        {createMutation.isPending ? 'Creating...' : 'Create Product'}
      </button>
      {products?.map(p => <ProductCard key={p.id} product={p} />)}
    </div>
  )
}
```

## Checklist

- [ ] Use `apiGet/apiPost` helpers (auto-unwrapping)
- [ ] Use query key factories for consistency
- [ ] Set `enabled: Boolean(id)` to prevent premature fetches
- [ ] NO `retry` for mutations (prevents duplicates)
- [ ] Invalidate related queries in `onSuccess`
- [ ] Pair any `placeholderData` on a scoped key with `usePlaceholderScopeGuard` (or drop it)
- [ ] Use pessimistic UI for financial operations
- [ ] Show toast notifications for user feedback
