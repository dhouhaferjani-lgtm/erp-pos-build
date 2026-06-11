import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { toast } from 'sonner'
import { renderWithProviders } from '@/test/renderWithProviders'
import { CreateStockTransferPage } from '../pages/CreateStockTransferPage'
import type { CreateStockTransferInput } from '../types'

const mockCreate = vi.hoisted(() => vi.fn<(input: CreateStockTransferInput) => Promise<{ id: string }>>())
const mockFetchLocations = vi.hoisted(() => vi.fn())
const mockUseProductBatches = vi.hoisted(() => vi.fn())
const mockUseProductVariants = vi.hoisted(() => vi.fn())

const PRODUCT_ID = '11111111-1111-4111-8111-111111111111'
const VARIANT_A = '22222222-2222-4222-8222-222222222222'
const VARIANT_B = '33333333-3333-4333-8333-333333333333'

vi.mock('@/features/location/api', () => ({
  fetchLocations: mockFetchLocations,
}))

vi.mock('../api/queries', () => ({
  useCreateStockTransfer: () => ({
    mutateAsync: mockCreate,
    isPending: false,
  }),
}))

vi.mock('@/features/batches/hooks/useBatches', () => ({
  useProductBatches: mockUseProductBatches,
}))

vi.mock('@/features/catalog/hooks/useProductVariants', () => ({
  useProductVariants: mockUseProductVariants,
}))

vi.mock('@/components/molecules/pickers/ProductPicker', () => ({
  ProductPicker: ({
    value,
    onChange,
  }: {
    value: { name: string } | null
    onChange: (next: {
      id: string
      sku: string
      name: string
      requires_batch_tracking: boolean
    }) => void
  }) => (
    <button
      type="button"
      onClick={() => {
        onChange({
          id: PRODUCT_ID,
          sku: 'TSHIRT',
          name: 'Variant product',
          requires_batch_tracking: false,
        })
      }}
    >
      {value?.name ?? 'Select variant product'}
    </button>
  ),
}))

vi.mock('sonner', () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
  },
}))

function variant(id: string, code: string) {
  return {
    id,
    tenant_id: 't',
    company_id: 'c',
    product_id: PRODUCT_ID,
    variant_code: code,
    sku: `TSHIRT-${code}`,
    barcode: null,
    name_suffix: code,
    is_default: false,
    is_active: true,
    display_order: 0,
    price_override: null,
    cost_override: null,
    image_url: null,
  }
}

describe('CreateStockTransferPage variants', () => {
  beforeEach(() => {
    mockCreate.mockReset()
    vi.mocked(toast.error).mockClear()
    mockFetchLocations.mockResolvedValue([
      { id: 'source-location', name: 'Main Warehouse' },
      { id: 'destination-location', name: 'Downtown Shop' },
    ])
    mockUseProductBatches.mockReturnValue({ data: [], isFetching: false })
    mockUseProductVariants.mockReturnValue({
      data: [variant(VARIANT_A, 'RED'), variant(VARIANT_B, 'BLU')],
      isLoading: false,
    })
    mockCreate.mockResolvedValue({ id: 'transfer-1' })
  })

  it('requires a variant for a variant-bearing product and sends variant_id', async () => {
    const user = userEvent.setup()
    renderWithProviders(<CreateStockTransferPage />)

    await waitFor(() => {
      expect(screen.getAllByRole('option', { name: 'Main Warehouse' }).length).toBeGreaterThan(0)
    })
    await user.selectOptions(await screen.findByLabelText(/source location/i), 'source-location')
    await user.selectOptions(screen.getByLabelText(/destination location/i), 'destination-location')
    await user.click(screen.getByRole('button', { name: /select variant product/i }))

    // The variant select appears for the variant-bearing product.
    const variantSelect = await screen.findByLabelText('Variant')

    // Submitting without choosing a variant is blocked client-side.
    await user.click(screen.getByRole('button', { name: /create transfer/i }))
    expect(mockCreate).not.toHaveBeenCalled()
    expect(toast.error).toHaveBeenCalledWith('Select a variant for this product.')

    // After choosing variant A, the payload carries variant_id.
    await user.selectOptions(variantSelect, VARIANT_A)
    await user.click(screen.getByRole('button', { name: /create transfer/i }))

    await waitFor(() => {
      expect(mockCreate).toHaveBeenCalledWith(expect.objectContaining({
        lines: [
          expect.objectContaining({
            product_id: PRODUCT_ID,
            variant_id: VARIANT_A,
            quantity: '1',
          }),
        ],
      }))
    })
  })
})
