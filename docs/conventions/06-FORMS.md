# Form Patterns & Validation Conventions

> **Purpose:** Form state management and validation patterns
> **Last Updated:** 2025-12-30

## Form Library Stack

- **react-hook-form** v7.67.0 - Form state management
- **@hookform/resolvers** v5.2.2 - Validation bridge
- **zod** v4.1.13 - TypeScript-first schema validation
- **TanStack React Query** - Server state mutations
- **sonner** v2.0.7 - Toast notifications

## Simple Validation Pattern

```typescript
const {
  register,
  handleSubmit,
  formState: { errors, isSubmitting },
} = useForm<FormData>({
  defaultValues: {
    name: '',
    description: '',
  },
})

// In JSX
<input
  type="text"
  {...register('name', { required: 'Name is required' })}
  className="mt-1 block w-full rounded-lg border"
/>
{errors.name && (
  <p className="mt-1 text-sm text-red-600">{errors.name.message}</p>
)}
```

## Zod Schema Validation Pattern

```typescript
// Define schema
const schema = z.object({
  amount: z
    .string()
    .min(1, 'sales.creditNotes.form.amountRequired')
    .refine((val) => parseFloat(val) > 0, 'Amount must be positive'),
  reason: z.enum(['return', 'price_adjustment', 'billing_error']),
  notes: z.string().optional(),
})

type FormData = z.infer<typeof schema>

// Use in form
const {
  register,
  handleSubmit,
  formState: { errors },
} = useForm<FormData>({
  resolver: zodResolver(schema),
  defaultValues: {
    amount: '',
    notes: '',
  },
})
```

## Form Integration with Mutations

```typescript
const createMutation = useMutation({
  mutationFn: (data: FormData) => apiPost<Product>('/products', data),
  onSuccess: () => {
    void queryClient.invalidateQueries({ queryKey: ['products'] })
    void navigate('/inventory/products')
  },
})

const onSubmit = (data: FormData) => {
  createMutation.mutate(data)
}

// In JSX
<form onSubmit={handleSubmit(onSubmit)}>
  {/* form fields */}
  <button
    type="submit"
    disabled={createMutation.isPending}
  >
    {createMutation.isPending ? 'Saving...' : 'Save'}
  </button>
</form>
```

## Field Array Pattern (Dynamic Lists)

```typescript
const { fields, append, remove } = useFieldArray({
  control,
  name: 'cross_references',
})

// Render fields
{fields.map((field, index) => (
  <div key={field.id} className="flex gap-2">
    <input
      {...register(`cross_references.${index}.brand` as const)}
      placeholder="Brand"
    />
    <input
      {...register(`cross_references.${index}.reference` as const)}
      placeholder="Reference"
    />
    <button type="button" onClick={() => { remove(index) }}>
      <X className="h-4 w-4" />
    </button>
  </div>
))}

// Add button
<button type="button" onClick={() => { append({ brand: '', reference: '' }) }}>
  Add Cross Reference
</button>
```

## Custom Select with Controller

```typescript
<Controller
  name="partner_id"
  control={control}
  rules={{ required: t('validation.required') }}
  render={({ field }) => (
    <PartnerSearchSelect
      value={field.value ?? ''}
      onChange={field.onChange}
      error={errors.partner_id?.message}
    />
  )}
/>
```

## Error Handling

### API Error Extraction

```typescript
export function getErrorMessage(error: unknown): string {
  if (isApiError(error)) {
    return error.response?.data?.error?.message ?? 'An error occurred'
  }
  if (error instanceof Error) {
    return error.message
  }
  return 'An unexpected error occurred'
}

// In mutation
onError: (error) => {
  toast.error(getErrorMessage(error))
}
```

### Form-Level Error Display

```typescript
{createMutation.isError && (
  <div className="rounded-md bg-red-50 p-4">
    <div className="flex">
      <AlertCircle className="h-5 w-5 text-red-400" />
      <div className="ms-3">
        <p className="text-sm text-red-800">
          {createMutation.error?.message || t('messages.createFailed')}
        </p>
      </div>
    </div>
  </div>
)}
```

## Auto-Save Pattern

```typescript
// apps/web/src/hooks/useDraftAutoSave.ts
export function useDraftAutoSave(
  data: DraftData | null,
  config: AutoSaveConfig = {}
) {
  const { debounceMs = 3000, enabled = true } = config

  useEffect(() => {
    if (!data || !enabled) return

    const hasMinimalData = data.lines && data.lines.length > 0
    if (!hasMinimalData) return

    const timer = setTimeout(() => {
      performSave()
    }, debounceMs)

    return () => clearTimeout(timer)
  }, [data, enabled, debounceMs])

  return { isSaving, lastSavedAt }
}

// Usage
const { isSaving, lastSavedAt } = useDraftAutoSave(draftData, {
  enabled: true,
  debounceMs: 3000,
})

// Display status
{isSaving ? (
  <span>Saving draft...</span>
) : lastSavedAt ? (
  <span>Draft saved {lastSavedAt.toLocaleTimeString()}</span>
) : null}
```

## Key Guidelines

1. **No Hardcoded Strings** - All messages use translation keys
2. **Zod Enums** - Never use magic strings for status/types
3. **Pessimistic UI** - NO optimistic updates for financial forms
4. **Query Invalidation** - Always invalidate on mutation success
5. **Error Extraction** - Use `getErrorMessage()` helper
6. **Toast Notifications** - Provide user feedback for all mutations
7. **Disable on Submit** - Set `disabled={mutation.isPending}`

## Checklist

- [ ] Use react-hook-form for state management
- [ ] Use zod for complex validation schemas
- [ ] Use `Controller` for custom components
- [ ] Integrate with TanStack Query mutations
- [ ] Show loading state while submitting
- [ ] Display errors with toast.error()
- [ ] Invalidate queries after successful mutation
- [ ] Use translation keys for all messages
- [ ] No optimistic updates for financial forms
