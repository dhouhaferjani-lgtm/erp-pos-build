import { describe, it, expect, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/renderWithProviders'
import { StockTransferDetailPage } from '../pages/StockTransferDetailPage'
import type { StockTransfer } from '../types'

const transfer: StockTransfer = {
  id: 'transfer-1',
  transfer_number: 'TR-2024-001',
  transfer_type: 'intracompany',
  status: 'completed',
  source_location_id: 'loc-a',
  source_location_name: 'Main Warehouse',
  destination_location_id: 'loc-b',
  destination_location_name: 'Downtown Store',
  notes: null,
  transfer_cost: '0.000',
  transfer_cost_label: null,
  transfer_cost_distribution: 'pro_rata_value',
  initiated_by_user_id: 'user-1',
  initiated_by_name: 'Alice',
  completed_by_user_id: 'user-1',
  completed_by_name: 'Alice',
  cancelled_by_user_id: null,
  cancelled_by_name: null,
  initiated_at: '2024-01-10',
  completed_at: '2024-01-11',
  cancelled_at: null,
  cancellation_reason: null,
  created_at: '2024-01-10',
  updated_at: '2024-01-11',
  lines: [
    {
      id: 'line-1',
      product_id: 'prod-42',
      product_name: 'Vitamin C Serum',
      product_sku: 'SKU-42',
      variant_id: null,
      variant_sku: null,
      variant_name: null,
      quantity: '5.0000',
      unit_cost_snapshot: '10.000',
      allocated_transfer_cost: '0.000',
      batch_allocations: [],
    },
  ],
}

vi.mock('../api/queries', () => ({
  useStockTransfer: () => ({ data: transfer, isLoading: false }),
  useCompleteStockTransfer: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useCancelStockTransfer: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))

describe('StockTransferDetailPage', () => {
  it('renders line product name as a link to the product detail page', () => {
    renderWithProviders(<StockTransferDetailPage />, { route: '/inventory/stock-transfers/transfer-1' })

    const productLink = screen.getByRole('link', { name: 'Vitamin C Serum' })
    expect(productLink).toHaveAttribute('href', '/inventory/products/prod-42')
  })
})
