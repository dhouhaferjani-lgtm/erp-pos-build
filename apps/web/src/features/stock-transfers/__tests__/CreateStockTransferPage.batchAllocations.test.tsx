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
          id: '11111111-1111-4111-8111-111111111111',
          sku: 'PARA-LOT',
          name: 'Batch tracked product',
          requires_batch_tracking: true,
        })
      }}
    >
      {value?.name ?? 'Select batch tracked product'}
    </button>
  ),
}))

vi.mock('sonner', () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
  },
}))

describe('CreateStockTransferPage batch allocations', () => {
  beforeEach(() => {
    mockCreate.mockReset()
    vi.mocked(toast.error).mockClear()
    mockFetchLocations.mockResolvedValue([
      { id: 'source-location', name: 'Main Warehouse' },
      { id: 'backup-source', name: 'Backup Warehouse' },
      { id: 'destination-location', name: 'Downtown Shop' },
    ])
    mockUseProductBatches.mockReturnValue({
      data: [
        {
          id: 101,
          uuid: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
          product_id: '11111111-1111-4111-8111-111111111111',
          batch_number: 'LOT-2026-A',
          expiry_date: '2026-12-31',
          expiry_status: 'OK',
          is_active: true,
          is_recalled: false,
          is_expired: false,
          can_be_sold: true,
          days_until_expiry: 209,
          available_quantity: 6,
          total_quantity: 6,
          batch_stock: [
            {
              location_id: 'source-location',
              quantity: '6.0000',
              reserved_quantity: '0.0000',
              available_quantity: '6.0000',
            },
          ],
        },
      ],
      isFetching: false,
    })
    mockCreate.mockResolvedValue({ id: 'transfer-1' })
  })

  it('defaults batch detail allocations by FEFO and submits them with the transfer line', async () => {
    const user = userEvent.setup()
    renderWithProviders(<CreateStockTransferPage />)

    await waitFor(() => {
      expect(screen.getAllByRole('option', { name: 'Main Warehouse' }).length).toBeGreaterThan(0)
    })
    await user.selectOptions(await screen.findByLabelText(/source location/i), 'source-location')
    await user.selectOptions(screen.getByLabelText(/destination location/i), 'destination-location')
    await user.click(screen.getByRole('button', { name: /select batch tracked product/i }))
    await user.click(await screen.findByRole('button', { name: /lots and expiry/i }))
    await screen.findByText('LOT-2026-A')
    await user.click(screen.getByRole('button', { name: /create transfer/i }))

    await waitFor(() => {
      expect(mockCreate).toHaveBeenCalledWith(expect.objectContaining({
        lines: [
          expect.objectContaining({
            product_id: '11111111-1111-4111-8111-111111111111',
            quantity: '1',
            batch_allocations: [
              {
                batch_id: 101,
                quantity: '1.0000',
              },
            ],
          }),
        ],
      }))
    })
  })

  it('clears selected batch allocations when the source location changes', async () => {
    const user = userEvent.setup()
    renderWithProviders(<CreateStockTransferPage />)

    await waitFor(() => {
      expect(screen.getAllByRole('option', { name: 'Main Warehouse' }).length).toBeGreaterThan(0)
    })
    await user.selectOptions(await screen.findByLabelText(/source location/i), 'source-location')
    await user.selectOptions(screen.getByLabelText(/destination location/i), 'destination-location')
    await user.click(screen.getByRole('button', { name: /select batch tracked product/i }))
    await user.click(await screen.findByRole('button', { name: /lots and expiry/i }))
    await screen.findByRole('button', { name: /1 lot/i })

    await user.selectOptions(screen.getByLabelText(/source location/i), 'backup-source')
    await user.click(screen.getByRole('button', { name: /create transfer/i }))

    expect(mockCreate).not.toHaveBeenCalled()
    expect(toast.error).toHaveBeenCalledWith('FEFO could not cover every batch-tracked line. Allocate lots manually.')
  })
})
