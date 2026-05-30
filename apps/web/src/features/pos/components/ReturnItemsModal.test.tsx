import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { seedAuth, resetAuth } from '@/test/seedAuth'
import { ReturnItemsModal } from './ReturnItemsModal'
import type { ProcessReturnRequest } from '../api/receiptApi'

// Mock translation — return the key (or its defaultValue) verbatim.
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) =>
      opts && typeof opts['defaultValue'] === 'string' ? opts['defaultValue'] : key,
  }),
}))

// EUR → currency scale 2. The whole point of F-FRONTEND-RETURN is that the
// RETURNED QUANTITY must NOT be canonicalized at this currency scale.
vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({ currency: 'EUR', decimals: 2 }),
}))

const processReturnMock = vi.fn<(id: string, data: ProcessReturnRequest) => Promise<unknown>>()
const getReceiptDetailMock = vi.fn<(id: string) => Promise<unknown>>()

vi.mock('../api/receiptApi', () => ({
  getReceiptDetail: (id: string): Promise<unknown> => getReceiptDetailMock(id),
  processReturn: (id: string, data: ProcessReturnRequest): Promise<unknown> =>
    processReturnMock(id, data),
}))

function renderModal() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })
  return render(
    <QueryClientProvider client={queryClient}>
      <ReturnItemsModal
        isOpen
        onClose={vi.fn()}
        receiptId="receipt-1"
        terminalId="term-1"
        onSuccess={vi.fn()}
      />
    </QueryClientProvider>,
  )
}

describe('ReturnItemsModal — F-FRONTEND-RETURN quantity precision', () => {
  beforeEach(() => {
    seedAuth()
    processReturnMock.mockReset()
    processReturnMock.mockResolvedValue({})
    getReceiptDetailMock.mockReset()
    getReceiptDetailMock.mockResolvedValue({
      receipt_number: 'R-001',
      total: '30.000',
      currency: 'EUR',
      lines: [
        {
          id: 'line-1',
          line_number: 1,
          product_id: 'p-1',
          composite_item_id: null,
          product_code: 'SKU1',
          product_name: 'Bulk Coffee',
          quantity: '3.0000',
          unit: 'kg',
          unit_price: '10.00',
          line_total: '30.00',
          tax_rate: '0',
          tax_amount: '0',
          discount_amount: '0',
          returned_quantity: '0',
        },
      ],
    })
  })

  afterEach(() => {
    resetAuth()
  })

  it('sends the returned quantity as a 4-decimal STRING (quantity scale, not currency scale)', async () => {
    renderModal()

    // Wait for the receipt line to render.
    await waitFor(() => {
      expect(screen.getByText('Bulk Coffee')).toBeInTheDocument()
    })

    // Select the line (checkbox) — this defaults returnQuantity to maxReturnable.
    const checkbox = screen.getByRole('checkbox')
    fireEvent.click(checkbox)

    // Enter a fractional quantity that is meaningless at currency scale 2
    // but valid at quantity scale 4.
    const qtyInput = screen.getByRole('spinbutton')
    fireEvent.change(qtyInput, { target: { value: '1.2345' } })

    // Submit the return.
    fireEvent.click(screen.getByText('pos:returns.processReturn'))

    await waitFor(() => {
      expect(processReturnMock).toHaveBeenCalledTimes(1)
    })

    const payload = processReturnMock.mock.calls[0][1]
    expect(payload.lines).toHaveLength(1)
    const sentQuantity = payload.lines[0]?.quantity
    // String type (never a JS number) ...
    expect(typeof sentQuantity).toBe('string')
    // ... canonicalized at quantity scale 4, NOT truncated to currency scale 2.
    expect(sentQuantity).toBe('1.2345')
  })

  it('clamps the returned quantity to the maximum returnable (string, scale 4)', async () => {
    renderModal()
    await waitFor(() => {
      expect(screen.getByText('Bulk Coffee')).toBeInTheDocument()
    })

    fireEvent.click(screen.getByRole('checkbox'))
    const qtyInput = screen.getByRole('spinbutton')
    // Over the 3.0000 max — must clamp to maxReturnable.
    fireEvent.change(qtyInput, { target: { value: '99' } })

    fireEvent.click(screen.getByText('pos:returns.processReturn'))

    await waitFor(() => {
      expect(processReturnMock).toHaveBeenCalledTimes(1)
    })

    const payload = processReturnMock.mock.calls[0][1]
    expect(payload.lines[0]?.quantity).toBe('3.0000')
  })
})
