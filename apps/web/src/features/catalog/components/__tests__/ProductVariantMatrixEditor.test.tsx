import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ProductVariantMatrixEditor } from '../ProductVariantMatrixEditor'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

let mockHasPermission = vi.fn().mockReturnValue(true)
vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: mockHasPermission }),
}))

interface UpdateVariantArg {
  variantId: string
  payload: {
    sku?: string
    barcode?: string | null
    price_override?: string | null
    cost_override?: string | null
    image_url?: string | null
    is_active?: boolean
  }
}

const mockGenerateMutate = vi.fn()
const mockUpdateMutate = vi.fn<(arg: UpdateVariantArg) => Promise<unknown>>()
const mockDeleteMutate = vi.fn()
let mockAttributes: unknown[] = []
let mockVariants: unknown[] = []

vi.mock('../../hooks/useVariants', () => ({
  useAttributes: () => ({ data: mockAttributes, isLoading: false }),
  useVariantsForProduct: () => ({ data: mockVariants, isLoading: false, isError: false }),
  useGenerateMatrix: () => ({ mutateAsync: mockGenerateMutate, isPending: false }),
  useUpdateVariant: () => ({ mutateAsync: mockUpdateMutate, isPending: false }),
  useDeleteVariant: () => ({ mutateAsync: mockDeleteMutate, isPending: false }),
}))

function variant(over: Record<string, unknown>) {
  return {
    id: 'v1',
    tenant_id: 't1',
    company_id: 'c1',
    product_id: 'p1',
    variant_code: 'RED-S',
    sku: 'SKU-RED-S',
    barcode: null,
    name_suffix: 'Red / S',
    is_default: false,
    is_active: true,
    display_order: 0,
    price_override: null,
    cost_override: null,
    image_url: null,
    ...over,
  }
}

describe('ProductVariantMatrixEditor', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockHasPermission = vi.fn().mockReturnValue(true)
    mockAttributes = [
      { id: 'a1', tenant_id: 't1', code: 'color', name: 'Color', data_type: 'color', is_variant_axis: true, display_order: 0, is_active: true },
    ]
    mockVariants = []
  })

  it('prompts to select axes when there are no variants', () => {
    render(<ProductVariantMatrixEditor productId="p1" />)
    expect(screen.getByText('catalog:variants.noVariants')).toBeInTheDocument()
  })

  it('generates a matrix from selected axes', async () => {
    mockGenerateMutate.mockResolvedValue([])
    const user = userEvent.setup()
    render(<ProductVariantMatrixEditor productId="p1" />)

    await user.click(screen.getByLabelText('Color'))
    await user.click(screen.getByRole('button', { name: 'catalog:variants.generate' }))

    await waitFor(() => {
      expect(mockGenerateMutate).toHaveBeenCalledWith(['a1'])
    })
  })

  it('renders an editable row per generated variant', () => {
    mockVariants = [variant({ id: 'v1', sku: 'SKU-RED-S' }), variant({ id: 'v2', sku: 'SKU-RED-M', name_suffix: 'Red / M' })]
    render(<ProductVariantMatrixEditor productId="p1" />)
    expect(screen.getByDisplayValue('SKU-RED-S')).toBeInTheDocument()
    expect(screen.getByDisplayValue('SKU-RED-M')).toBeInTheDocument()
  })

  it('saves an edited SKU + price for a variant', async () => {
    mockVariants = [variant({ id: 'v1', sku: 'SKU-RED-S' })]
    mockUpdateMutate.mockResolvedValue(variant({ id: 'v1' }))
    const user = userEvent.setup()
    render(<ProductVariantMatrixEditor productId="p1" />)

    const skuInput = screen.getByDisplayValue('SKU-RED-S')
    await user.clear(skuInput)
    await user.type(skuInput, 'SKU-NEW')

    await user.click(screen.getByRole('button', { name: 'catalog:variants.save' }))

    await waitFor(() => {
      expect(mockUpdateMutate).toHaveBeenCalled()
    })
    const arg = mockUpdateMutate.mock.calls[0][0]
    expect(arg.variantId).toBe('v1')
    expect(arg.payload.sku).toBe('SKU-NEW')
  })

  it('toggles is_active per variant on save', async () => {
    mockVariants = [variant({ id: 'v1', is_active: true })]
    mockUpdateMutate.mockResolvedValue(variant({ id: 'v1' }))
    const user = userEvent.setup()
    render(<ProductVariantMatrixEditor productId="p1" />)

    await user.click(screen.getByLabelText('catalog:variants.active'))
    await user.click(screen.getByRole('button', { name: 'catalog:variants.save' }))

    await waitFor(() => {
      expect(mockUpdateMutate).toHaveBeenCalled()
    })
    const arg = mockUpdateMutate.mock.calls[0][0]
    expect(arg.variantId).toBe('v1')
    expect(arg.payload.is_active).toBe(false)
  })
})
