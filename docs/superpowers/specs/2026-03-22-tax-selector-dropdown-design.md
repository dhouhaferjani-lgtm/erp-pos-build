# Tax Configuration Selector — Design Spec

> Replace manual tax rate numeric inputs with a dropdown that picks from configured tax rules, with smart defaults and inline creation.

## Problem

Tax rate fields across the app (document lines, product forms, service forms, composite items) are plain numeric inputs. Users manually type percentages every time despite having a rich `TaxConfiguration` system in settings. This is error-prone and ignores existing defaults.

## Solution

An atom + molecule component pair (`TaxConfigurationSelect` + `TaxConfigurationField`) that replaces all manual tax rate inputs with a dropdown pre-populated from configured tax rules.

## Design Decisions

- **No backend changes.** Existing endpoints, models, and FKs (`default_tax_configuration_id` on company/category/product) already support this.
- **Forms submit `tax_rate` as before.** The rate is derived from the selected configuration — backward compatible with all existing calculation logic.
- **Approach B (atom + molecule):** Atom for compact table rows, molecule with `FormField` wrapper for standalone forms. Follows atomic design principles.
- **Default resolution via FK chain:** `product.default_tax_configuration_id` > `category.default_tax_configuration_id` > `company.default_tax_configuration_id`. Direct FK lookup, no rate-matching ambiguity.
- **Filtered by document type:** When used on documents, only shows configs where `applicable_document_types` includes the current document type (or is empty = all types).
- **TDD:** Tests written first for each component and consumer integration.

## Component Architecture

### Atom: `TaxConfigurationSelect`

**Location:** `apps/web/src/components/atoms/TaxConfigurationSelect/`

**Props:**
| Prop | Type | Description |
|------|------|-------------|
| `value` | `string \| null` | Selected `tax_configuration_id` |
| `onChange` | `(configId: string \| null, taxRate: string) => void` | Returns both ID and resolved rate |
| `documentType?` | `string` | Filters configs by `applicable_document_types` |
| `disabled?` | `boolean` | Disable the select |
| `size?` | `'sm' \| 'md'` | `sm` for table rows, `md` for forms |
| `placeholder?` | `string` | Placeholder text |

**Behavior:**
- Fetches active tax configs via `useTaxConfigurations()`
- Filters by `documentType` when provided (configs with empty `applicable_document_types` match all)
- Renders dropdown: `"{name} ({rate}%)"` for percentage, `"{name} ({amount})"` for fixed
- Last option: "Add new tax..." opens the existing tax config creation modal from `TaxSettingsPage`
- After creating a new tax config, auto-selects it and calls `onChange`

### Molecule: `TaxConfigurationField`

**Location:** `apps/web/src/components/molecules/TaxConfigurationField/`

**Props:** All of `TaxConfigurationSelect` plus:
| Prop | Type | Description |
|------|------|-------------|
| `label?` | `string` | Field label |
| `error?` | `string` | Validation error message |
| `required?` | `boolean` | Shows required indicator |

Wraps `TaxConfigurationSelect` inside a `FormField` component with label and error display.

### Shared Hook

`useTaxConfigurations()` already exists at `features/settings/hooks/useTaxConfigurations.ts`. Re-export from a shared hooks location so it's accessible outside the settings feature.

## Consumer Integration

### DocumentLineEditor.tsx (table rows)
- Replace numeric `tax_rate` input with `TaxConfigurationSelect` (size `sm`)
- `documentType` prop passed from parent document form
- On product selection: auto-set default from product > category > company FK chain
- `onChange` updates both `tax_configuration_id` and `tax_rate` on the line

### CompositeItemFormPage.tsx (two form sections)
- Replace both numeric inputs with `TaxConfigurationField`
- No `documentType` filter (product context, not document)
- Default from `category.default_tax_configuration_id` > `company.default_tax_configuration_id`

### ServiceForm.tsx
- Replace numeric input with `TaxConfigurationField`
- No `documentType` filter
- Default from `company.default_tax_configuration_id`

### AddQuickProductModal.tsx
- Replace numeric input with `TaxConfigurationField`
- Default from `company.default_tax_configuration_id` — removes hardcoded "19"

### ProductForm.tsx
- Replace numeric input with `TaxConfigurationField`
- Default from `category.default_tax_configuration_id` > `company.default_tax_configuration_id`

### Read-Only Displays
- `ServiceDetailPage.tsx`, `DocumentLineRow.tsx`, `ProductInfoModal.tsx`
- Show tax config name (e.g., "TVA 19%") instead of just "19%"
- Fallback to rate display if config name unavailable

## Testing Strategy (TDD)

### Atom tests (`TaxConfigurationSelect.test.tsx`)
1. Renders dropdown with tax configurations
2. Filters by `documentType` when provided
3. Calls `onChange` with config ID and tax rate on selection
4. Shows "Add new tax" option
5. Handles empty configurations list
6. Pre-selects `value` prop

### Molecule tests (`TaxConfigurationField.test.tsx`)
1. Renders with label and error
2. Passes props through to atom
3. Shows required indicator

### Consumer integration tests
Each consumer gets a test verifying:
1. Dropdown renders (not a numeric input)
2. Default is pre-selected from FK chain
3. Changing selection updates the tax rate value

## Files to Create
- `apps/web/src/components/atoms/TaxConfigurationSelect/TaxConfigurationSelect.tsx`
- `apps/web/src/components/atoms/TaxConfigurationSelect/TaxConfigurationSelect.test.tsx`
- `apps/web/src/components/atoms/TaxConfigurationSelect/index.ts`
- `apps/web/src/components/molecules/TaxConfigurationField/TaxConfigurationField.tsx`
- `apps/web/src/components/molecules/TaxConfigurationField/TaxConfigurationField.test.tsx`
- `apps/web/src/components/molecules/TaxConfigurationField/index.ts`

## Files to Modify
- `apps/web/src/features/documents/components/DocumentLineEditor.tsx`
- `apps/web/src/features/catalog/pages/CompositeItemFormPage.tsx`
- `apps/web/src/features/services/ServiceForm.tsx`
- `apps/web/src/components/organisms/AddQuickProductModal/AddQuickProductModal.tsx`
- `apps/web/src/features/inventory/ProductForm.tsx`
- `apps/web/src/features/services/ServiceDetailPage.tsx` (read-only)
- `apps/web/src/features/documents/components/DocumentLineRow/DocumentLineRow.tsx` (read-only)
- `apps/web/src/features/pos/organisms/ProductInfoModal/ProductInfoModal.tsx` (read-only)
