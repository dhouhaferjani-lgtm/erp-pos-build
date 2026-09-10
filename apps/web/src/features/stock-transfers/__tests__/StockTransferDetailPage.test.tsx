import { describe, it, expect, vi, beforeEach } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/renderWithProviders'
import { StockTransferDetailPage } from '../pages/StockTransferDetailPage'

import type { StockTransfer } from '../types'

const state = vi.hoisted(() => ({ transfer: null as StockTransfer | null }))

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
  closed_by_user_id: null,
  closed_by_name: null,
  closed_at: null,
  close_disposition: null,
  close_reason: null,
  close_note: null,
  freight_uncapitalized: '0.0000',
  receipts: [],
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
      quantity_received: '5.0000',
      quantity_damaged: '0.0000',
      quantity_written_off: '0.0000',
      quantity_returned: '0.0000',
      quantity_remaining: '0.0000',
      quantity_decimals: 0,
      unit_cost_snapshot: '10.000',
      allocated_transfer_cost: '0.000',
      batch_allocations: [],
    },
  ],
}

vi.mock('../api/queries', () => ({
  useStockTransfer: () => ({ data: state.transfer, isLoading: false }),
  useCompleteStockTransfer: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useCancelStockTransfer: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))

describe('StockTransferDetailPage', () => {
  beforeEach(() => {
    state.transfer = structuredClone(transfer)
  })

  it('renders line product name as a link to the product detail page', () => {
    renderWithProviders(<StockTransferDetailPage />, { route: '/inventory/stock-transfers/transfer-1' })

    const productLink = screen.getByRole('link', { name: 'Vitamin C Serum' })
    expect(productLink).toHaveAttribute('href', '/inventory/products/prod-42')
  })

  it('explains that partial completion receives the entire outstanding remainder', () => {
    const line = transfer.lines?.[0]
    if (!line) throw new Error('Transfer fixture requires a line')
    state.transfer = {
      ...structuredClone(transfer),
      status: 'partially_received',
      lines: [
        { ...line, quantity_received: '2.0000', quantity_remaining: '3.0000' },
        { ...line, id: 'line-2' },
      ],
    }
    renderWithProviders(<StockTransferDetailPage />, { route: '/inventory/stock-transfers/transfer-1' })
    fireEvent.click(screen.getByRole('button', { name: /confirm receipt/i }))
    expect(screen.getByText('1 of 2 lines have not fully arrived. Confirming books the entire outstanding quantity as received at the destination; use Close to write off or return a short shipment.')).toBeInTheDocument()
  })

  it('keeps the existing completion explanation for an in-transit transfer', () => {
    state.transfer = { ...structuredClone(transfer), status: 'in_transit' }
    renderWithProviders(<StockTransferDetailPage />, { route: '/inventory/stock-transfers/transfer-1' })
    fireEvent.click(screen.getByRole('button', { name: /confirm receipt/i }))
    expect(screen.getByText('Destination stock will be incremented and the company-wide weighted average cost will be recomputed if the transfer has additional costs.')).toBeInTheDocument()
  })

  it('formats line quantity with the product unit precision', () => {
    renderWithProviders(<StockTransferDetailPage />, { route: '/inventory/stock-transfers/transfer-1' })

    expect(screen.getByText('5')).toBeInTheDocument()
    expect(screen.queryByText('5.0000')).not.toBeInTheDocument()
  })
})
