import { fireEvent, screen, waitFor } from '@testing-library/react'
import { describe, expect, it, vi, beforeEach } from 'vitest'

import { renderWithProviders } from '@/test/renderWithProviders'

import { QuoteRequestDetailPage } from './QuoteRequestDetailPage'
import type { QuoteRequestDetail } from './types'

const updateMutateAsync = vi.hoisted(() => vi.fn())
const sendMutateAsync = vi.hoisted(() => vi.fn())
const awardMutateAsync = vi.hoisted(() => vi.fn())
const navigate = vi.hoisted(() => vi.fn())
const mockDetail = vi.hoisted(() => vi.fn<() => QuoteRequestDetail>())
const toastError = vi.hoisted(() => vi.fn())

vi.mock('./api', () => ({
  useQuoteRequest: () => ({ data: mockDetail(), isLoading: false }),
  useUpdateQuoteRequest: () => ({ mutateAsync: updateMutateAsync, isPending: false }),
  useSendQuoteRequest: () => ({ mutateAsync: sendMutateAsync, isPending: false }),
  useAwardQuoteRequest: () => ({ mutateAsync: awardMutateAsync, isPending: false }),
}))

vi.mock('sonner', () => ({
  toast: { error: toastError },
}))

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    useNavigate: () => navigate,
    useParams: () => ({ id: 'rfq-1' }),
    Link: ({ to, children, ...rest }: { to: string; children: React.ReactNode; [key: string]: unknown }) => (
      <a href={to} {...rest}>{children}</a>
    ),
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

function detail(overrides: Partial<QuoteRequestDetail> = {}): QuoteRequestDetail {
  return {
    id: 'rfq-1',
    type: 'purchase_rfq',
    number: 'DP-2026-0001',
    group_id: 'group-1',
    partner: { id: 'supplier-1', name: 'LaboDerm' },
    status: 'confirmed',
    currency: 'EUR',
    validity_date: '2026-07-15',
    lead_time_days: 5,
    responded_at: '2026-07-03T10:00:00Z',
    sent_at: '2026-07-02T10:00:00Z',
    supplier_reference: 'SUP-REF-1',
    closed_reason: null,
    total: '410.000',
    lines: [
      {
        product_id: 'product-1',
        variant_id: null,
        description: 'Crème solaire SPF50',
        quantity: '50.0000',
        unit_price: '8.200',
      },
    ],
    ...overrides,
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  mockDetail.mockReturnValue(detail())
  updateMutateAsync.mockResolvedValue(detail())
  sendMutateAsync.mockResolvedValue(detail())
  awardMutateAsync.mockResolvedValue({ id: 'po-1', type: 'purchase_order', status: 'draft' })
})

describe('QuoteRequestDetailPage', () => {
  it('records a supplier response with editable price cells and metadata', async () => {
    renderWithProviders(<QuoteRequestDetailPage />)

    expect(screen.getAllByText(/EUR/).length).toBeGreaterThan(0)
    fireEvent.click(screen.getByTestId('edit-response'))
    fireEvent.change(screen.getByTestId('response-unit-price-0'), { target: { value: '8.100' } })
    fireEvent.change(screen.getByTestId('response-validity-date'), { target: { value: '2026-07-31' } })
    fireEvent.change(screen.getByTestId('response-supplier-reference'), { target: { value: 'SUP-42' } })
    fireEvent.change(screen.getByTestId('response-lead-time'), { target: { value: '7' } })
    fireEvent.click(screen.getByTestId('save-response'))

    await waitFor(() => {
      expect(updateMutateAsync).toHaveBeenCalledWith({
        validity_date: '2026-07-31',
        supplier_reference: 'SUP-42',
        lead_time_days: 7,
        lines: [
          {
            product_id: 'product-1',
            variant_id: null,
            description: 'Crème solaire SPF50',
            quantity: '50.0000',
            unit_price: '8.100',
          },
        ],
      })
    })
  })

  it('sends and converts the RFQ to the created purchase order detail', async () => {
    renderWithProviders(<QuoteRequestDetailPage />)

    fireEvent.click(screen.getByTestId('send-rfq'))
    await waitFor(() => {
      expect(sendMutateAsync).toHaveBeenCalledWith()
    })

    fireEvent.click(screen.getByTestId('convert-rfq'))
    await waitFor(() => {
      expect(awardMutateAsync).toHaveBeenCalledWith()
      expect(navigate).toHaveBeenCalledWith('/purchases/orders/po-1')
    })
  })

  it('shows the lost sibling state for closed non-winning RFQs', () => {
    mockDetail.mockReturnValue(detail({ status: 'cancelled', closed_reason: 'lost' }))
    renderWithProviders(<QuoteRequestDetailPage />)

    expect(screen.getByText('purchases:quoteRequests.status.lost')).toBeInTheDocument()
    expect(screen.queryByTestId('convert-rfq')).not.toBeInTheDocument()
  })

  it('labels sent unanswered RFQs from responded_at presence', () => {
    mockDetail.mockReturnValue(detail({ responded_at: null }))
    renderWithProviders(<QuoteRequestDetailPage />)

    expect(screen.getByText('purchases:quoteRequests.status.sent')).toBeInTheDocument()
    expect(screen.queryByTestId('convert-rfq')).not.toBeInTheDocument()
  })

  it('surfaces save, send, and convert API errors with toasts', async () => {
    updateMutateAsync.mockRejectedValueOnce({
      response: { data: { error: { message: 'VALIDATION_FAILED' } } },
    })
    sendMutateAsync.mockRejectedValueOnce({
      response: { data: { error: { message: 'RFQ_CANCELLED' } } },
    })
    awardMutateAsync.mockRejectedValueOnce({
      response: { data: { error: { message: 'RFQ_GROUP_ALREADY_AWARDED' } } },
    })

    renderWithProviders(<QuoteRequestDetailPage />)

    fireEvent.click(screen.getByTestId('edit-response'))
    fireEvent.click(screen.getByTestId('save-response'))
    fireEvent.click(screen.getByTestId('send-rfq'))
    fireEvent.click(screen.getByTestId('convert-rfq'))

    await waitFor(() => {
      expect(toastError).toHaveBeenCalledWith('VALIDATION_FAILED')
      expect(toastError).toHaveBeenCalledWith('RFQ_CANCELLED')
      expect(toastError).toHaveBeenCalledWith('RFQ_GROUP_ALREADY_AWARDED')
    })
  })
})
