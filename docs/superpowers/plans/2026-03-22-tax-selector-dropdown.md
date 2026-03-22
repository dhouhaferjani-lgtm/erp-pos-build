# Tax Configuration Selector Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace all manual tax rate numeric inputs with a dropdown that picks from configured tax rules, with smart defaults and inline creation.

**Architecture:** Atom + molecule pattern. `TaxConfigurationSelect` atom handles the dropdown (fetching, filtering, "add new" trigger). `TaxConfigurationField` molecule wraps it with `FormField` for label/error. An extracted `TaxConfigFormModal` organism allows inline tax creation from any consumer. TDD throughout.

**Tech Stack:** React 19, TypeScript strict, TanStack Query 5, Vitest, react-i18next, existing design token system.

**Spec:** `docs/superpowers/specs/2026-03-22-tax-selector-dropdown-design.md`

---

### Task 1: Add i18n keys for tax selector

**Files:**
- Modify: `apps/web/src/locales/en/common.json`
- Modify: `apps/web/src/locales/fr/common.json` (if exists)

- [ ] **Step 1: Add English translation keys**

Add these keys under a `"tax"` section in `common.json`:

```json
"tax": {
  "addNew": "+ Add new tax...",
  "selectPlaceholder": "Select tax...",
  "loadingError": "Could not load taxes",
  "staleTaxWarning": "Tax configuration no longer exists",
  "noTaxes": "No taxes configured"
}
```

- [ ] **Step 2: Add French translation keys**

Same structure with French translations:

```json
"tax": {
  "addNew": "+ Ajouter une taxe...",
  "selectPlaceholder": "Sélectionner une taxe...",
  "loadingError": "Impossible de charger les taxes",
  "staleTaxWarning": "La configuration de taxe n'existe plus",
  "noTaxes": "Aucune taxe configurée"
}
```

- [ ] **Step 3: Commit**

```bash
git add apps/web/src/locales/
git commit -m "feat(i18n): add tax selector translation keys"
```

---

### Task 2: Re-export `useTaxConfigurations` hook to shared location

**Files:**
- Create: `apps/web/src/hooks/useTaxConfigurations.ts`

- [ ] **Step 1: Create the re-export file**

```typescript
// Re-export tax configuration hooks for cross-feature access
// Source: features/settings/hooks/useTaxConfigurations.ts
export {
  useTaxConfigurations,
  useCreateTaxConfiguration,
  useUpdateTaxConfiguration,
  useDocumentTypes,
  taxConfigurationKeys,
} from '../features/settings/hooks/useTaxConfigurations'
```

- [ ] **Step 2: Verify import resolves**

Run: `cd apps/web && npx tsc --noEmit --pretty 2>&1 | head -20`
Expected: No new errors related to this file.

- [ ] **Step 3: Commit**

```bash
git add apps/web/src/hooks/useTaxConfigurations.ts
git commit -m "refactor: re-export tax configuration hooks to shared location"
```

---

### Task 3: Extract `TaxConfigFormModal` from `TaxSettingsPage`

**Files:**
- Create: `apps/web/src/components/organisms/TaxConfigFormModal/TaxConfigFormModal.tsx`
- Create: `apps/web/src/components/organisms/TaxConfigFormModal/index.ts`
- Modify: `apps/web/src/features/settings/TaxSettingsPage.tsx` (lines 86-98 state, 192-216 handler, 517-705 modal JSX)
- Modify: `apps/web/src/components/organisms/index.ts` (add export)

- [ ] **Step 1: Create the TaxConfigFormModal component**

Extract the modal from `TaxSettingsPage.tsx` lines 517-705 into a standalone component. The component needs:

**Props:**
```typescript
interface TaxConfigFormModalProps {
  isOpen: boolean
  onClose: () => void
  onSaved: (tax: TaxConfiguration) => void
  editingTax?: TaxConfiguration | null
}
```

The component manages its own `taxFormData` state (currently lines 89-98 of TaxSettingsPage), the `handleSaveTax` logic (lines 192-216), and the full modal JSX (lines 517-705).

Key details to preserve:
- `useCreateTaxConfiguration()` and `useUpdateTaxConfiguration()` mutations
- `useDocumentTypes()` query for document type checkboxes
- The `handleTaxFormChange` helper
- The `getDocumentTypeTranslationKey` helper (import from TaxSettingsPage or inline)
- Toast notifications on success/error
- Form reset on close and after save

The `onSaved` callback receives the created/updated `TaxConfiguration` so the caller can react (e.g., auto-select it).

- [ ] **Step 2: Create index.ts barrel export**

```typescript
export { TaxConfigFormModal } from './TaxConfigFormModal'
export type { TaxConfigFormModalProps } from './TaxConfigFormModal'
```

- [ ] **Step 3: Add to organisms barrel export**

In `apps/web/src/components/organisms/index.ts`, add:

```typescript
export { TaxConfigFormModal } from './TaxConfigFormModal'
```

- [ ] **Step 4: Update TaxSettingsPage to use the extracted modal**

Replace the inline modal JSX (lines 517-705), the `taxFormData` state (lines 89-98), and `handleSaveTax` (lines 192-216) with:

```typescript
import { TaxConfigFormModal } from '../../../components/organisms'

// Keep only: isModalOpen, editingTax state
// Remove: taxFormData state, handleSaveTax, handleTaxFormChange

// In JSX, replace the modal block with:
<TaxConfigFormModal
  isOpen={isModalOpen}
  onClose={() => { setIsModalOpen(false); setEditingTax(null); }}
  onSaved={() => { setIsModalOpen(false); setEditingTax(null); }}
  editingTax={editingTax}
/>
```

- [ ] **Step 5: Verify TaxSettingsPage still works**

Run: `cd apps/web && npx tsc --noEmit --pretty 2>&1 | head -30`
Expected: No type errors.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/components/organisms/TaxConfigFormModal/ apps/web/src/components/organisms/index.ts apps/web/src/features/settings/TaxSettingsPage.tsx
git commit -m "refactor: extract TaxConfigFormModal into reusable organism"
```

---

### Task 4: Build `TaxConfigurationSelect` atom (TDD)

**Files:**
- Create: `apps/web/src/components/atoms/TaxConfigurationSelect/TaxConfigurationSelect.test.tsx`
- Create: `apps/web/src/components/atoms/TaxConfigurationSelect/TaxConfigurationSelect.tsx`
- Create: `apps/web/src/components/atoms/TaxConfigurationSelect/index.ts`

- [ ] **Step 1: Write the failing tests**

```typescript
import { render, screen, fireEvent } from '@testing-library/react'
import { describe, it, expect, vi } from 'vitest'
import { TaxConfigurationSelect } from './TaxConfigurationSelect'

// Mock the shared hook
vi.mock('../../../hooks/useTaxConfigurations', () => ({
  useTaxConfigurations: vi.fn(),
}))

// Mock the TaxConfigFormModal
vi.mock('../../organisms/TaxConfigFormModal', () => ({
  TaxConfigFormModal: () => null,
}))

import { useTaxConfigurations } from '../../../hooks/useTaxConfigurations'

const mockConfigs = [
  {
    id: 'tax-1',
    name: 'TVA 19%',
    tax_type: 'PERCENTAGE' as const,
    percentage_rate: '19.00',
    fixed_amount: null,
    applies_to: 'LINE_ITEMS' as const,
    applicable_document_types: [],
    is_active: true,
    is_default: true,
    country_code: 'TN',
    code: 'TVA19',
    sequence_order: 1,
    stacks_on: 'BASE_AMOUNT' as const,
    is_stamp_duty: false,
    is_recoverable: true,
    created_at: '',
    updated_at: '',
  },
  {
    id: 'tax-2',
    name: 'TVA 7%',
    tax_type: 'PERCENTAGE' as const,
    percentage_rate: '7.00',
    fixed_amount: null,
    applies_to: 'LINE_ITEMS' as const,
    applicable_document_types: ['SALES_INVOICE'],
    is_active: true,
    is_default: false,
    country_code: 'TN',
    code: 'TVA7',
    sequence_order: 2,
    stacks_on: 'BASE_AMOUNT' as const,
    is_stamp_duty: false,
    is_recoverable: true,
    created_at: '',
    updated_at: '',
  },
  {
    id: 'tax-3',
    name: 'Stamp Duty',
    tax_type: 'FIXED_AMOUNT' as const,
    percentage_rate: null,
    fixed_amount: '1.000',
    applies_to: 'DOCUMENT_TOTAL' as const,
    applicable_document_types: ['SALES_INVOICE'],
    is_active: true,
    is_default: false,
    country_code: 'TN',
    code: 'STAMP',
    sequence_order: 3,
    stacks_on: 'BASE_AMOUNT' as const,
    is_stamp_duty: true,
    is_recoverable: false,
    created_at: '',
    updated_at: '',
  },
]

const mockUseTaxConfigurations = useTaxConfigurations as ReturnType<typeof vi.fn>

function setup(props: Partial<React.ComponentProps<typeof TaxConfigurationSelect>> = {}) {
  const defaultProps = {
    value: null,
    onChange: vi.fn(),
    ...props,
  }
  return { ...render(<TaxConfigurationSelect {...defaultProps} />), onChange: defaultProps.onChange }
}

describe('TaxConfigurationSelect', () => {
  beforeEach(() => {
    mockUseTaxConfigurations.mockReturnValue({
      data: mockConfigs,
      isLoading: false,
      isError: false,
    })
  })

  it('renders a select with tax configurations', () => {
    setup()
    const select = screen.getByRole('combobox')
    expect(select).toBeInTheDocument()
    // Should show all active configs as options
    expect(screen.getByText('TVA 19% (19.00%)')).toBeInTheDocument()
    expect(screen.getByText('TVA 7% (7.00%)')).toBeInTheDocument()
    expect(screen.getByText('Stamp Duty (1.000)')).toBeInTheDocument()
  })

  it('filters by documentType when provided', () => {
    setup({ documentType: 'SALES_INVOICE' })
    // TVA 19% has empty applicable_document_types (matches all)
    expect(screen.getByText('TVA 19% (19.00%)')).toBeInTheDocument()
    // TVA 7% and Stamp Duty have SALES_INVOICE in their list
    expect(screen.getByText('TVA 7% (7.00%)')).toBeInTheDocument()
    expect(screen.getByText('Stamp Duty (1.000)')).toBeInTheDocument()
  })

  it('filters OUT configs that do not match documentType', () => {
    setup({ documentType: 'QUOTE' })
    // TVA 19% matches (empty = all)
    expect(screen.getByText('TVA 19% (19.00%)')).toBeInTheDocument()
    // TVA 7% only matches SALES_INVOICE
    expect(screen.queryByText('TVA 7% (7.00%)')).not.toBeInTheDocument()
  })

  it('calls onChange with config ID and tax rate on selection', () => {
    const { onChange } = setup()
    const select = screen.getByRole('combobox')
    fireEvent.change(select, { target: { value: 'tax-1' } })
    expect(onChange).toHaveBeenCalledWith('tax-1', '19.00')
  })

  it('calls onChange with fixed amount for FIXED_AMOUNT type', () => {
    const { onChange } = setup()
    const select = screen.getByRole('combobox')
    fireEvent.change(select, { target: { value: 'tax-3' } })
    expect(onChange).toHaveBeenCalledWith('tax-3', '1.000')
  })

  it('pre-selects the value prop', () => {
    setup({ value: 'tax-2' })
    const select = screen.getByRole('combobox') as HTMLSelectElement
    expect(select.value).toBe('tax-2')
  })

  it('shows placeholder when no value selected', () => {
    setup({ value: null })
    expect(screen.getByText(/select tax/i)).toBeInTheDocument()
  })

  it('shows "Add new tax" option', () => {
    setup()
    expect(screen.getByText(/add new tax/i)).toBeInTheDocument()
  })

  it('shows loading state while fetching', () => {
    mockUseTaxConfigurations.mockReturnValue({
      data: undefined,
      isLoading: true,
      isError: false,
    })
    setup()
    const select = screen.getByRole('combobox') as HTMLSelectElement
    expect(select.disabled).toBe(true)
  })

  it('handles empty configurations list', () => {
    mockUseTaxConfigurations.mockReturnValue({
      data: [],
      isLoading: false,
      isError: false,
    })
    setup()
    // Should still render with "Add new tax" option
    expect(screen.getByText(/add new tax/i)).toBeInTheDocument()
  })

  it('respects size prop for compact rendering', () => {
    setup({ size: 'sm' })
    const select = screen.getByRole('combobox')
    expect(select.className).toContain('py-1')
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/web && pnpm vitest run src/components/atoms/TaxConfigurationSelect/TaxConfigurationSelect.test.tsx 2>&1 | tail -20`
Expected: FAIL — module not found.

- [ ] **Step 3: Implement TaxConfigurationSelect**

```typescript
import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { tokens } from '../../../lib/designTokens'
import { cn } from '../../../lib/utils'
import { useTaxConfigurations } from '../../../hooks/useTaxConfigurations'
import { TaxConfigFormModal } from '../../organisms/TaxConfigFormModal'
import type { TaxConfiguration } from '../../../features/settings/types/tax'

const ADD_NEW_VALUE = '__ADD_NEW__'

export interface TaxConfigurationSelectProps {
  value: string | null
  onChange: (configId: string | null, taxRate: string) => void
  documentType?: string
  disabled?: boolean
  size?: 'sm' | 'md'
  placeholder?: string
  error?: boolean
}

function getConfigDisplayLabel(config: TaxConfiguration): string {
  if (config.tax_type === 'PERCENTAGE' && config.percentage_rate) {
    return `${config.name} (${config.percentage_rate}%)`
  }
  if (config.tax_type === 'FIXED_AMOUNT' && config.fixed_amount) {
    return `${config.name} (${config.fixed_amount})`
  }
  return config.name
}

function getConfigRate(config: TaxConfiguration): string {
  if (config.tax_type === 'PERCENTAGE') return config.percentage_rate ?? '0'
  return config.fixed_amount ?? '0'
}

export function TaxConfigurationSelect({
  value,
  onChange,
  documentType,
  disabled = false,
  size = 'md',
  placeholder,
  error = false,
}: TaxConfigurationSelectProps) {
  const { t } = useTranslation('common')
  const { data: configs, isLoading, isError } = useTaxConfigurations()
  const [isModalOpen, setIsModalOpen] = useState(false)

  const filteredConfigs = useMemo(() => {
    if (!configs) return []
    return configs.filter((config) => {
      if (!config.is_active) return false
      if (!documentType) return true
      // Empty applicable_document_types = applies to all
      if (config.applicable_document_types.length === 0) return true
      return config.applicable_document_types.includes(documentType)
    })
  }, [configs, documentType])

  const sizeClasses = size === 'sm' ? 'py-1 px-2 text-sm' : 'py-2 px-3'

  // Stale value detection: selected config was deleted
  const isStaleValue = !!(value && configs && !configs.find((c) => c.id === value))

  // Error fallback: if query fails, render a plain numeric input
  if (isError) {
    return (
      <input
        type="number"
        min="0"
        step="0.01"
        max="100"
        disabled={disabled}
        className={cn(tokens.select.base, sizeClasses)}
        placeholder={t('common:tax.loadingError')}
        onChange={(e) => { onChange(null, e.target.value || '0'); }}
      />
    )
  }

  const handleChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    const selectedValue = e.target.value

    if (selectedValue === ADD_NEW_VALUE) {
      // Reset select to previous value — modal will handle creation
      e.target.value = value ?? ''
      setIsModalOpen(true)
      return
    }

    if (!selectedValue) {
      onChange(null, '0')
      return
    }

    const config = filteredConfigs.find((c) => c.id === selectedValue)
    if (config) {
      onChange(config.id, getConfigRate(config))
    }
  }

  const handleTaxCreated = (newTax: TaxConfiguration) => {
    setIsModalOpen(false)
    onChange(newTax.id, getConfigRate(newTax))
  }

  return (
    <>
      {isStaleValue && (
        <p className="text-xs text-amber-600">{t('common:tax.staleTaxWarning')}</p>
      )}
      <select
        value={value ?? ''}
        onChange={handleChange}
        disabled={disabled || isLoading}
        className={cn(
          tokens.select.base,
          error && tokens.select.error,
          sizeClasses,
        )}
      >
        <option value="">
          {isLoading
            ? t('common:loading', 'Loading...')
            : (placeholder ?? t('common:tax.selectPlaceholder'))}
        </option>
        {filteredConfigs.map((config) => (
          <option key={config.id} value={config.id}>
            {getConfigDisplayLabel(config)}
          </option>
        ))}
        {!isLoading && (
          <option value={ADD_NEW_VALUE}>
            {t('common:tax.addNew')}
          </option>
        )}
      </select>
      <TaxConfigFormModal
        isOpen={isModalOpen}
        onClose={() => { setIsModalOpen(false); }}
        onSaved={handleTaxCreated}
      />
    </>
  )
}
```

- [ ] **Step 4: Create index.ts barrel export**

```typescript
export { TaxConfigurationSelect } from './TaxConfigurationSelect'
export type { TaxConfigurationSelectProps } from './TaxConfigurationSelect'
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd apps/web && pnpm vitest run src/components/atoms/TaxConfigurationSelect/TaxConfigurationSelect.test.tsx 2>&1 | tail -20`
Expected: All tests PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/components/atoms/TaxConfigurationSelect/
git commit -m "feat: add TaxConfigurationSelect atom with tests"
```

---

### Task 5: Build `TaxConfigurationField` molecule (TDD)

**Files:**
- Create: `apps/web/src/components/molecules/TaxConfigurationField/TaxConfigurationField.test.tsx`
- Create: `apps/web/src/components/molecules/TaxConfigurationField/TaxConfigurationField.tsx`
- Create: `apps/web/src/components/molecules/TaxConfigurationField/index.ts`

- [ ] **Step 1: Write the failing tests**

```typescript
import { render, screen } from '@testing-library/react'
import { describe, it, expect, vi } from 'vitest'
import { TaxConfigurationField } from './TaxConfigurationField'

vi.mock('../../../hooks/useTaxConfigurations', () => ({
  useTaxConfigurations: vi.fn().mockReturnValue({
    data: [
      {
        id: 'tax-1', name: 'TVA 19%', tax_type: 'PERCENTAGE',
        percentage_rate: '19.00', fixed_amount: null, applies_to: 'LINE_ITEMS',
        applicable_document_types: [], is_active: true, is_default: true,
        country_code: 'TN', code: 'TVA19', sequence_order: 1,
        stacks_on: 'BASE_AMOUNT', is_stamp_duty: false, is_recoverable: true,
        created_at: '', updated_at: '',
      },
    ],
    isLoading: false,
    isError: false,
  }),
}))

vi.mock('../../organisms/TaxConfigFormModal', () => ({
  TaxConfigFormModal: () => null,
}))

describe('TaxConfigurationField', () => {
  it('renders with label', () => {
    render(
      <TaxConfigurationField
        label="Tax Rate"
        value={null}
        onChange={vi.fn()}
      />
    )
    expect(screen.getByText('Tax Rate')).toBeInTheDocument()
  })

  it('shows required indicator', () => {
    render(
      <TaxConfigurationField
        label="Tax Rate"
        required
        value={null}
        onChange={vi.fn()}
      />
    )
    expect(screen.getByText('*')).toBeInTheDocument()
  })

  it('shows error message', () => {
    render(
      <TaxConfigurationField
        label="Tax Rate"
        error="Tax is required"
        value={null}
        onChange={vi.fn()}
      />
    )
    expect(screen.getByText('Tax is required')).toBeInTheDocument()
  })

  it('renders the select dropdown', () => {
    render(
      <TaxConfigurationField
        label="Tax Rate"
        value={null}
        onChange={vi.fn()}
      />
    )
    expect(screen.getByRole('combobox')).toBeInTheDocument()
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/web && pnpm vitest run src/components/molecules/TaxConfigurationField/TaxConfigurationField.test.tsx 2>&1 | tail -20`
Expected: FAIL — module not found.

- [ ] **Step 3: Implement TaxConfigurationField**

```typescript
import { FormField } from '../../atoms/FormField'
import { TaxConfigurationSelect } from '../../atoms/TaxConfigurationSelect'
import type { TaxConfigurationSelectProps } from '../../atoms/TaxConfigurationSelect'

export interface TaxConfigurationFieldProps extends TaxConfigurationSelectProps {
  label?: string
  error?: string
  required?: boolean
  htmlFor?: string
  className?: string
}

export function TaxConfigurationField({
  label,
  error,
  required,
  htmlFor,
  className,
  ...selectProps
}: TaxConfigurationFieldProps) {
  return (
    <FormField
      label={label}
      error={error}
      required={required}
      htmlFor={htmlFor}
      className={className}
    >
      <TaxConfigurationSelect {...selectProps} error={!!error} />
    </FormField>
  )
}
```

- [ ] **Step 4: Create index.ts barrel export**

```typescript
export { TaxConfigurationField } from './TaxConfigurationField'
export type { TaxConfigurationFieldProps } from './TaxConfigurationField'
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd apps/web && pnpm vitest run src/components/molecules/TaxConfigurationField/TaxConfigurationField.test.tsx 2>&1 | tail -20`
Expected: All tests PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/components/molecules/TaxConfigurationField/
git commit -m "feat: add TaxConfigurationField molecule with tests"
```

---

### Task 6: Backend — Add `default_tax_configuration_id` to products

**Files:**
- Create: `apps/api/database/migrations/YYYY_MM_DD_HHMMSS_add_default_tax_configuration_id_to_products.php`
- Modify: `apps/api/app/Modules/Product/Domain/Product.php` — add fillable and relationship

**Context:** The `default_tax_configuration_id` FK already exists on `companies` and `categories` tables. Products need it too so the default resolution chain works: product > category > company.

- [ ] **Step 1: Create migration**

```bash
cd apps/api && php artisan make:migration add_default_tax_configuration_id_to_products
```

Migration content:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignUuid('default_tax_configuration_id')
                ->nullable()
                ->after('tax_rate')
                ->constrained('tax_configurations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_tax_configuration_id');
        });
    }
};
```

- [ ] **Step 2: Run migration**

Run: `cd apps/api && php artisan migrate`
Expected: Migration runs successfully.

- [ ] **Step 3: Update Product model**

Edit `apps/api/app/Modules/Product/Domain/Product.php`. Add `'default_tax_configuration_id'` to the `$fillable` array (after `'tax_rate'` at line 73). Add the relationship:

```php
public function defaultTaxConfiguration(): BelongsTo
{
    return $this->belongsTo(
        \App\Modules\Taxation\Domain\Entities\TaxConfiguration::class,
        'default_tax_configuration_id'
    );
}
```

- [ ] **Step 4: Ensure the field is returned in product API responses**

Check the product resource/transformer. The field should be included in the JSON response so the frontend can use it for default resolution. If the product resource manually lists fields, add `default_tax_configuration_id`.

- [ ] **Step 5: Verify with PHPStan**

Run: `cd apps/api && ./vendor/bin/phpstan analyse --memory-limit=512M 2>&1 | tail -20`
Expected: No new errors.

- [ ] **Step 6: Commit**

```bash
git add apps/api/database/migrations/ apps/api/app/Modules/Product/Domain/Product.php
git commit -m "feat(backend): add default_tax_configuration_id to products table"
```

---

### Task 7: Integrate into DocumentLineEditor

**Files:**
- Modify: `apps/web/src/components/documents/DocumentLineEditor.tsx` (lines 402-416 — tax input)
- Modify: `apps/web/src/features/documents/DocumentForm.tsx` (line 482 — pass `documentType`)

**Context:** Replace the numeric `<input type="number">` for tax_rate (lines 406-415) with `TaxConfigurationSelect` (size `sm`). The `DocumentLineEditor` is imported in `DocumentForm.tsx` from `../../components/documents/DocumentLineEditor` (line 9). `DocumentForm` already has an `effectiveType` variable (line 113) derived from `documentType` prop or URL. Need to pass it down.

- [ ] **Step 1: Add `documentType` prop to DocumentLineEditor and pass from DocumentForm**

In `DocumentLineEditor.tsx`, add `documentType?: string` to the component's props interface. In `DocumentForm.tsx` at line 482, change:
```tsx
<DocumentLineEditor lines={lines} onChange={setLines} />
```
to:
```tsx
<DocumentLineEditor lines={lines} onChange={setLines} documentType={effectiveType} />
```

Note: `effectiveType` may be a union type like `DocumentType` — the `TaxConfigurationSelect` expects a `string`, which is compatible. Map to the uppercase API format if needed (e.g., `'invoice'` → `'SALES_INVOICE'`).

- [ ] **Step 2: Replace the tax rate input (lines 402-416)**

Replace the `<td>` content at lines 402-416:

**Before (readonly):**
```tsx
<span className="text-sm text-gray-500">{line.tax_rate}%</span>
```
Keep this for readonly mode, but enhance to show config name if available.

**Before (edit):**
```tsx
<input type="number" min="0" step="0.1" value={line.tax_rate} ... />
```

**After (edit):**
```tsx
<TaxConfigurationSelect
  value={line.tax_configuration_id ?? null}
  onChange={(configId, taxRate) => {
    handleUpdateLine(line.id, {
      tax_configuration_id: configId,
      tax_rate: parseFloat(taxRate) || 0,
    })
  }}
  documentType={documentType}
  size="sm"
/>
```

- [ ] **Step 3: Add `tax_configuration_id` to the DocumentLine interface**

In the file's `DocumentLine` interface (around line 35-50), add:
```typescript
tax_configuration_id?: string | null
```

- [ ] **Step 4: Set default tax config on product selection**

In `handleAddProduct` (around line 135), when creating a `newLine`, set `tax_configuration_id` from the product:

```typescript
tax_configuration_id: product.default_tax_configuration_id ?? null,
```

This requires the `Product` interface (line 10-16) to include `default_tax_configuration_id?: string | null`.

- [ ] **Step 5: Verify TypeScript compiles**

Run: `cd apps/web && npx tsc --noEmit --pretty 2>&1 | head -30`
Expected: No new type errors.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/components/documents/DocumentLineEditor.tsx apps/web/src/features/documents/DocumentForm.tsx
git commit -m "feat: replace tax rate input with TaxConfigurationSelect in DocumentLineEditor"
```

---

### Task 8: Integrate into CompositeItemFormPage

**Files:**
- Modify: `apps/web/src/features/catalog/pages/CompositeItemFormPage.tsx` (lines 250-260 and 457-467 — two tax inputs)

**Context:** Replace both numeric tax_rate inputs with `TaxConfigurationField` molecule. The form state needs `tax_configuration_id` added alongside `tax_rate`. On save, continue sending `tax_rate` (derived from selection). Default from category > company.

- [ ] **Step 1: Add `tax_configuration_id` to form state**

In the form state initialization (around line 60), add:
```typescript
tax_configuration_id: null as string | null,
```

When loading an existing item (around line 84-88), set:
```typescript
tax_configuration_id: item.default_tax_configuration_id ?? null,
```

- [ ] **Step 2: Replace first tax input (lines 250-260)**

Replace the `<input type="number">` with:

```tsx
<TaxConfigurationField
  label={t('catalog:compositeItems.fields.taxRate')}
  value={form.tax_configuration_id}
  onChange={(configId, taxRate) => {
    setForm({ ...form, tax_configuration_id: configId, tax_rate: taxRate })
  }}
/>
```

- [ ] **Step 3: Replace second tax input (lines 457-467)**

Same replacement as step 2.

- [ ] **Step 4: Import TaxConfigurationField**

Add to imports:
```typescript
import { TaxConfigurationField } from '../../../components/molecules/TaxConfigurationField'
```

- [ ] **Step 5: Verify TypeScript compiles**

Run: `cd apps/web && npx tsc --noEmit --pretty 2>&1 | head -30`

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/features/catalog/pages/CompositeItemFormPage.tsx
git commit -m "feat: replace tax rate inputs with TaxConfigurationField in CompositeItemFormPage"
```

---

### Task 9: Integrate into ServiceForm

**Files:**
- Modify: `apps/web/src/features/services/ServiceForm.tsx` (lines 398-413 — tax input)

**Context:** Replace the numeric tax_rate input with `TaxConfigurationField`. Add `tax_configuration_id` to form data. Default from company.

- [ ] **Step 1: Add `tax_configuration_id` to ServiceFormData interface**

In the `ServiceFormData` interface (around line 14-18), add:
```typescript
tax_configuration_id: string | null
```

- [ ] **Step 2: Update form defaults and load logic**

In `defaultValues` (around line 42-45), add:
```typescript
tax_configuration_id: null,
```

When loading existing service data (around line 84-88), set:
```typescript
tax_configuration_id: data.default_tax_configuration_id ?? null,
```

- [ ] **Step 3: Replace tax input (lines 398-413)**

Replace the `<div>` containing the tax_rate label and input with:

```tsx
<TaxConfigurationField
  label={t('services.fields.taxRate', 'Tax Rate')}
  value={form.tax_configuration_id}
  onChange={(configId, taxRate) => {
    setValue('tax_configuration_id', configId)
    setValue('tax_rate', taxRate)
  }}
/>
```

Note: Check whether this form uses `react-hook-form` (with `register`/`setValue`) or local state (`useState`). Adapt the `onChange` accordingly. The current code at line 409 uses `{...register('tax_rate')}`, confirming react-hook-form. Use `setValue` for both fields.

- [ ] **Step 4: Import TaxConfigurationField**

```typescript
import { TaxConfigurationField } from '../../../components/molecules/TaxConfigurationField'
```

- [ ] **Step 5: Verify TypeScript compiles**

Run: `cd apps/web && npx tsc --noEmit --pretty 2>&1 | head -30`

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/features/services/ServiceForm.tsx
git commit -m "feat: replace tax rate input with TaxConfigurationField in ServiceForm"
```

---

### Task 10: Integrate into AddQuickProductModal

**Files:**
- Modify: `apps/web/src/components/organisms/AddQuickProductModal/AddQuickProductModal.tsx` (lines 180-199 — tax input)

**Context:** This modal currently hardcodes a default of "19" for tax_rate. Replace with `TaxConfigurationField` and default from company's `default_tax_configuration_id`.

- [ ] **Step 1: Add `tax_configuration_id` to form state**

In the form's `defaultValues` (around line 82-85), add:
```typescript
tax_configuration_id: null as string | null,
```

Remove the hardcoded `tax_rate: '19'`.

- [ ] **Step 2: Replace tax input (lines 180-199)**

Replace the `<FormField>` containing the numeric tax_rate input with:

```tsx
<TaxConfigurationField
  label={t('common:tax.selectPlaceholder')}
  required
  error={errors.tax_rate?.message}
  value={form.tax_configuration_id}
  onChange={(configId, taxRate) => {
    setValue('tax_configuration_id', configId)
    setValue('tax_rate', taxRate)
  }}
/>
```

Adapt based on whether the form uses `react-hook-form` or `useState`. Currently uses `register('tax_rate', {...})` — so use `setValue`.

- [ ] **Step 3: Import TaxConfigurationField**

```typescript
import { TaxConfigurationField } from '../../molecules/TaxConfigurationField'
```

- [ ] **Step 4: Verify TypeScript compiles**

Run: `cd apps/web && npx tsc --noEmit --pretty 2>&1 | head -30`

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/components/organisms/AddQuickProductModal/AddQuickProductModal.tsx
git commit -m "feat: replace hardcoded tax rate with TaxConfigurationField in AddQuickProductModal"
```

---

### Task 11: Integrate into ProductForm

**Files:**
- Modify: `apps/web/src/features/inventory/ProductForm.tsx` (lines 400-416 — tax input)

**Context:** Replace numeric tax_rate input with `TaxConfigurationField`. Default from category > company.

- [ ] **Step 1: Add `tax_configuration_id` to form state**

Add to form interface and defaults, similar to previous tasks.

- [ ] **Step 2: Replace tax input (lines 400-416)**

Replace with `TaxConfigurationField`, wired to update both `tax_configuration_id` and `tax_rate`.

- [ ] **Step 3: Import TaxConfigurationField**

- [ ] **Step 4: Verify TypeScript compiles**

Run: `cd apps/web && npx tsc --noEmit --pretty 2>&1 | head -30`

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/inventory/ProductForm.tsx
git commit -m "feat: replace tax rate input with TaxConfigurationField in ProductForm"
```

---

### Task 12: Integrate into AddToInventoryModal

**Files:**
- Modify: `apps/web/src/features/parts-catalog/components/organisms/AddToInventoryModal.tsx` (lines 213-230 — tax input)

**Context:** This modal may already have a select with hardcoded options. Replace with `TaxConfigurationField`.

- [ ] **Step 1: Replace tax input with TaxConfigurationField**

Follow the same pattern as previous consumer integrations.

- [ ] **Step 2: Verify TypeScript compiles**

Run: `cd apps/web && npx tsc --noEmit --pretty 2>&1 | head -30`

- [ ] **Step 3: Commit**

```bash
git add apps/web/src/features/parts-catalog/components/organisms/AddToInventoryModal.tsx
git commit -m "feat: replace tax rate input with TaxConfigurationField in AddToInventoryModal"
```

---

### Task 13: Update read-only displays to show tax config name

**Files:**
- Modify: `apps/web/src/features/services/ServiceDetailPage.tsx` (lines 255-262)
- Modify: `apps/web/src/features/documents/components/DocumentLineRow/DocumentLineRow.tsx` (lines 173-187)
- Modify: `apps/web/src/features/pos/organisms/ProductInfoModal/ProductInfoModal.tsx`
- Modify: `apps/web/src/features/inventory/ProductDetailPage.tsx`

**Context:** These pages display `{tax_rate}%` in read-only mode. Enhance to show the tax configuration name (e.g., "TVA 19%") when `tax_configuration_id` is present, by looking up the name from the cached tax configurations list. Fall back to `{tax_rate}%` if no config ID is set.

Create a small utility hook `useTaxConfigName(configId: string | null)` that uses `useTaxConfigurations()` data to resolve the display name. Place it alongside the re-exported hooks at `apps/web/src/hooks/useTaxConfigName.ts`:

```typescript
import { useTaxConfigurations } from './useTaxConfigurations'

export function useTaxConfigName(configId: string | null | undefined): string | null {
  const { data: configs } = useTaxConfigurations()
  if (!configId || !configs) return null
  const config = configs.find((c) => c.id === configId)
  if (!config) return null
  if (config.tax_type === 'PERCENTAGE' && config.percentage_rate) {
    return `${config.name} (${config.percentage_rate}%)`
  }
  if (config.tax_type === 'FIXED_AMOUNT' && config.fixed_amount) {
    return `${config.name} (${config.fixed_amount})`
  }
  return config.name
}
```

- [ ] **Step 1: Create `useTaxConfigName` hook and update ServiceDetailPage**

At line 259, change:
```tsx
{service.tax_rate}%
```
to:
```tsx
{taxConfigName ?? `${service.tax_rate}%`}
```
Where `taxConfigName` comes from `useTaxConfigName(service.default_tax_configuration_id)`.

- [ ] **Step 2: Update DocumentLineRow**

At line 175-176, change the readonly display from `{line.tax_rate}%` to show config name with rate fallback.

- [ ] **Step 3: Update ProductInfoModal and ProductDetailPage**

Same pattern — show config name, fall back to rate%.

- [ ] **Step 4: Verify TypeScript compiles**

Run: `cd apps/web && npx tsc --noEmit --pretty 2>&1 | head -30`

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/services/ServiceDetailPage.tsx apps/web/src/features/documents/components/DocumentLineRow/DocumentLineRow.tsx apps/web/src/features/pos/organisms/ProductInfoModal/ProductInfoModal.tsx apps/web/src/features/inventory/ProductDetailPage.tsx
git commit -m "feat: show tax configuration name in read-only displays"
```

---

### Task 14: Full verification

- [ ] **Step 1: Run TypeScript check**

Run: `cd apps/web && npx tsc --noEmit --pretty`
Expected: No errors.

- [ ] **Step 2: Run all tests**

Run: `cd apps/web && pnpm test`
Expected: All tests pass.

- [ ] **Step 3: Run ESLint**

Run: `cd apps/web && pnpm lint`
Expected: No new errors.

- [ ] **Step 4: Run backend checks**

Run: `cd apps/api && ./vendor/bin/phpstan analyse --memory-limit=512M && ./vendor/bin/pint --test`
Expected: No errors.

- [ ] **Step 5: Run preflight**

Run: `./scripts/preflight.sh`
Expected: All checks pass.
