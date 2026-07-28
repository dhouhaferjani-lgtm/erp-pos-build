import { describe, it, expect, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/renderWithProviders'
import { formatDate } from '@/lib/format'
import { StockTransferDetailPage } from '../pages/StockTransferDetailPage'
import type { StockTransfer, StockTransferLine } from '../types'

const state = vi.hoisted(() => ({ transfer: null as StockTransfer | null }))

vi.mock('../api/queries', () => ({
  useStockTransfer: () => ({ data: state.transfer, isLoading: false }),
  useCompleteStockTransfer: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useCancelStockTransfer: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))

function makeTransfer(lines: StockTransferLine[]): StockTransfer {
  return {
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
    lines,
  }
}

const batchLine: StockTransferLine = {
  id: 'line-1',
  product_id: 'prod-42',
  product_name: 'Batch Serum',
  product_sku: 'SKU-42',
  variant_id: null,
  variant_sku: null,
  variant_name: null,
  quantity: '4.0000',
  quantity_decimals: 3,
  unit_cost_snapshot: '10.000',
  allocated_transfer_cost: '0.000',
  // As returned by the API: earliest expiry first (FEFO order).
  batch_allocations: [
    {
      id: 'alloc-1',
      batch_id: 1,
      batch_number: 'LOT-EARLY',
      expiry_date: '2026-03-01',
      expiry_status: 'ok',
      can_be_sold: true,
      quantity: '3.0000',
    },
    {
      id: 'alloc-2',
      batch_id: 2,
      batch_number: 'LOT-LATE',
      expiry_date: '2026-09-01',
      expiry_status: 'ok',
      can_be_sold: true,
      quantity: '1.0000',
    },
  ],
}

const plainLine: StockTransferLine = {
  id: 'line-2',
  product_id: 'prod-99',
  product_name: 'Plain Widget',
  product_sku: 'SKU-99',
  variant_id: null,
  variant_sku: null,
  variant_name: null,
  quantity: '2.0000',
  quantity_decimals: 0,
  unit_cost_snapshot: '5.000',
  allocated_transfer_cost: '0.000',
  batch_allocations: [],
}

describe('StockTransferDetailPage batch allocations', () => {
  it('renders each batch allocation with number, formatted expiry and quantity in FEFO order', () => {
    state.transfer = makeTransfer([batchLine])
    renderWithProviders(<StockTransferDetailPage />, {
      route: '/inventory/stock-transfers/transfer-1',
    })

    expect(screen.getByText('LOT-EARLY')).toBeInTheDocument()
    expect(screen.getByText('LOT-LATE')).toBeInTheDocument()
    expect(screen.getByText(formatDate('2026-03-01'))).toBeInTheDocument()
    expect(screen.getByText(formatDate('2026-09-01'))).toBeInTheDocument()
    expect(screen.getByText('3.000')).toBeInTheDocument()
    expect(screen.getByText('1.000')).toBeInTheDocument()

    // Earliest-expiry lot renders before the later one (API/FEFO order preserved).
    const early = screen.getByText('LOT-EARLY')
    const late = screen.getByText('LOT-LATE')
    expect(early.compareDocumentPosition(late) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
  })

  it('does not render a batch allocation section for a line without allocations', () => {
    state.transfer = makeTransfer([plainLine])
    renderWithProviders(<StockTransferDetailPage />, {
      route: '/inventory/stock-transfers/transfer-1',
    })

    expect(screen.getByText('Plain Widget')).toBeInTheDocument()
    expect(screen.queryByText('LOT-EARLY')).not.toBeInTheDocument()
    // The batch sub-row header must not appear when no line has allocations.
    expect(screen.queryByTestId('batch-allocations-line-2')).not.toBeInTheDocument()
  })
})
