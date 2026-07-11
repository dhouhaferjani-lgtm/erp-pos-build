import { fireEvent, render, screen } from '@testing-library/react'
import { useForm } from 'react-hook-form'
import { describe, expect, it, vi } from 'vitest'

import { makeProductSectionProduct } from './__fixtures__/productSectionProduct'
import { ProductGeneralSection } from './ProductGeneralSection'
import type { ProductSectionFormData } from './types'
import { colors } from '@/lib/designTokens'

const mockClearPrefilledField = vi.hoisted(() => vi.fn())

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key === 'inventory:products.category' ? 'Category' : key,
  }),
}))

vi.mock('@/components/catalog/CategorySelect', () => ({
  CategorySelect: ({ value }: { value: number | null }) => (
    <div data-testid="category-select">{value}</div>
  ),
}))

vi.mock('@/features/uom/components/UnitDropdown', () => ({
  UnitDropdown: ({ value }: { value?: string }) => (
    <div data-testid="unit-dropdown">{value}</div>
  ),
}))

function EditHarness() {
  const {
    control,
    formState: { errors },
    register,
    setValue,
    watch,
  } = useForm<ProductSectionFormData>({
    defaultValues: {
      name: 'Brake Pad',
      sku: 'BP-001',
      unit_id: 'unit-1',
      category_id: 7,
      description: 'Low-dust front brake pad',
      is_active: true,
      is_active_for_ecommerce: true,
    },
  })

  return (
    <ProductGeneralSection
      adapter={{
        mode: 'edit',
        form: { control, errors, register, setValue, watch },
        general: {
          prefilledFields: new Set(['name']),
          clearPrefilledField: mockClearPrefilledField,
        },
      }}
    />
  )
}

describe('ProductGeneralSection', () => {
  it('renders the canonical general card with editable controls in edit mode', () => {
    const { container } = render(<EditHarness />)

    expect(container.querySelectorAll('#section-general')).toHaveLength(1)
    expect(screen.getByLabelText('inventory:products.name *')).toHaveValue('Brake Pad')
    expect(screen.getByLabelText('inventory:products.sku *')).toHaveValue('BP-001')
    expect(screen.getByTestId('unit-dropdown')).toHaveTextContent('unit-1')
    expect(screen.getByTestId('category-select')).toHaveTextContent('7')
    expect(screen.getByText('Category')).toBeInTheDocument()

    const nameInput = screen.getByLabelText('inventory:products.name *')
    expect(nameInput).toHaveClass(colors.success[50])
    fireEvent.change(nameInput, { target: { value: 'Updated Pad' } })
    expect(mockClearPrefilledField).toHaveBeenCalledWith('name')
  })

  it('renders the same labelled values read-only in view mode', () => {
    const { container } = render(
      <ProductGeneralSection
        adapter={{
          mode: 'view',
          product: makeProductSectionProduct(),
        }}
      />,
    )

    expect(container.querySelectorAll('#section-general')).toHaveLength(1)
    expect(screen.getByText('Brake Pad')).toBeInTheDocument()
    expect(screen.getByText('BP-001')).toBeInTheDocument()
    expect(screen.getByText('pcs')).toBeInTheDocument()
    expect(screen.getByText('Braking')).toBeInTheDocument()
    expect(screen.getByText('Category')).toBeInTheDocument()
    expect(screen.getByText('Low-dust front brake pad')).toBeInTheDocument()
    expect(screen.getByText('common:status.active')).toBeInTheDocument()
    expect(screen.getByText('inventory:products.isActiveForEcommerce')).toBeInTheDocument()
  })
})
