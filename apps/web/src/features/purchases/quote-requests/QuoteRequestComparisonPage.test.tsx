import { fireEvent, screen, waitFor } from '@testing-library/react'
import { describe, expect, it, vi, beforeEach } from 'vitest'

import { renderWithProviders } from '@/test/renderWithProviders'

import {
  QuoteRequestComparisonPage,
} from './QuoteRequestComparisonPage'
import { correlateGroupLines } from './comparisonLogic'
import type { QuoteRequestGroup } from './types'

const mockGroup = vi.hoisted(() => vi.fn<() => QuoteRequestGroup>())
const awardMutateAsync = vi.hoisted(() => vi.fn())
const reopenMutateAsync = vi.hoisted(() => vi.fn())
const navigate = vi.hoisted(() => vi.fn())
const toastError = vi.hoisted(() => vi.fn())
const refetchGroup = vi.hoisted(() => vi.fn())

vi.mock('./api', () => ({
  useQuoteRequestGroup: () => ({ data: mockGroup(), isLoading: false, refetch: refetchGroup }),
  useAwardQuoteRequest: () => ({ mutateAsync: awardMutateAsync, isPending: false }),
  useReopenQuoteRequestGroup: () => ({ mutateAsync: reopenMutateAsync, isPending: false }),
}))

vi.mock('sonner', () => ({
  toast: { error: toastError },
}))

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    useNavigate: () => navigate,
    useParams: () => ({ groupId: 'group-1' }),
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      if (!opts) return key
      return `${key}:${JSON.stringify(opts)}`
    },
  }),
}))

function group(overrides: Partial<QuoteRequestGroup> = {}): QuoteRequestGroup {
  return {
    group_id: 'group-1',
    has_live_purchase_order: false,
    siblings: [
      {
        id: 'rfq-1',
        type: 'purchase_rfq',
        number: 'DP-1',
        group_id: 'group-1',
        partner: { id: 'supplier-1', name: 'LaboDerm' },
        status: 'confirmed',
        currency: 'EUR',
        validity_date: '2026-07-31',
        lead_time_days: 7,
        responded_at: '2026-07-03T10:00:00Z',
        sent_at: '2026-07-02T10:00:00Z',
        supplier_reference: 'SUP-A',
        closed_reason: null,
        total: '405.000',
        lines: [
          { product_id: 'product-1', variant_id: null, description: 'SPF50', quantity: '50.0000', unit_price: '8.100' },
          { product_id: 'product-2', variant_id: 'variant-1', description: 'Serum', quantity: '30.0000', unit_price: '12.000' },
        ],
      },
      {
        id: 'rfq-2',
        type: 'purchase_rfq',
        number: 'DP-2',
        group_id: 'group-1',
        partner: { id: 'supplier-2', name: 'BioSupply' },
        status: 'confirmed',
        currency: 'EUR',
        validity_date: '2026-08-05',
        lead_time_days: 5,
        responded_at: '2026-07-03T11:00:00Z',
        sent_at: '2026-07-02T11:00:00Z',
        supplier_reference: 'SUP-B',
        closed_reason: null,
        total: '410.000',
        lines: [
          { product_id: 'product-1', variant_id: null, description: 'SPF50', quantity: '40.0000', unit_price: '8.000' },
          { product_id: 'product-3', variant_id: null, description: 'Unmatched', quantity: '10.0000', unit_price: '5.000' },
        ],
      },
    ],
    ...overrides,
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  mockGroup.mockReturnValue(group())
  awardMutateAsync.mockResolvedValue({ id: 'po-1', type: 'purchase_order', status: 'draft' })
  reopenMutateAsync.mockResolvedValue({ reopened: 2 })
  refetchGroup.mockResolvedValue(undefined)
})

describe('correlateGroupLines', () => {
  it('matches rows by product and variant and highlights the best unit price with string decimals', () => {
    const rows = correlateGroupLines(group().siblings)
    const spf50 = rows.find((row) => row.key === 'product-1::')

    expect(spf50?.cells['rfq-1']?.quantity).toBe('50.0000')
    expect(spf50?.cells['rfq-2']?.quantity).toBe('40.0000')
    expect(spf50?.bestSiblingIds).toEqual(['rfq-2'])
  })

  it('leaves unmatched rows unhighlighted', () => {
    const rows = correlateGroupLines(group().siblings)
    const unmatched = rows.find((row) => row.key === 'product-3::')

    expect(unmatched?.cells['rfq-1']).toBeUndefined()
    expect(unmatched?.cells['rfq-2']?.unit_price).toBe('5.000')
    expect(unmatched?.bestSiblingIds).toEqual([])
  })

  it('ignores unanswered siblings when computing best prices', () => {
    const rows = correlateGroupLines(group({
      siblings: [
        group().siblings[0],
        {
          ...group().siblings[1],
          responded_at: null,
          total: '0.000',
          lines: [
            { product_id: 'product-1', variant_id: null, description: 'SPF50', quantity: '50.0000', unit_price: '0.000' },
          ],
        },
      ],
    }).siblings)
    const spf50 = rows.find((row) => row.key === 'product-1::')

    expect(spf50?.bestSiblingIds).toEqual([])
  })

  it('preserves deterministic first-encounter row ordering', () => {
    const rows = correlateGroupLines(group().siblings)

    expect(rows.map((row) => row.key)).toEqual(['product-1::', 'product-2::variant-1', 'product-3::'])
  })

  it('documents duplicate product and variant lines as last cell wins', () => {
    const rows = correlateGroupLines(group({
      siblings: [
        {
          ...group().siblings[0],
          lines: [
            { product_id: 'product-1', variant_id: null, description: 'First SPF50', quantity: '50.0000', unit_price: '8.100' },
            { product_id: 'product-1', variant_id: null, description: 'Second SPF50', quantity: '60.0000', unit_price: '7.900' },
          ],
        },
        group().siblings[1],
      ],
    }).siblings)
    const spf50 = rows.find((row) => row.key === 'product-1::')

    expect(spf50?.label).toBe('First SPF50')
    expect(spf50?.cells['rfq-1']?.quantity).toBe('60.0000')
    expect(spf50?.cells['rfq-1']?.unit_price).toBe('7.900')
  })
})

describe('QuoteRequestComparisonPage', () => {
  it('links each sibling number to its detail page', () => {
    renderWithProviders(<QuoteRequestComparisonPage />)

    const link = screen.getByTestId('open-sibling-rfq-1')
    expect(link).toHaveAttribute('href', '/purchases/quote-requests/rfq-1')
    expect(screen.getByTestId('open-sibling-rfq-2')).toHaveAttribute('href', '/purchases/quote-requests/rfq-2')
  })

  it('shows supplier columns and confirms before awarding a responded column', async () => {
    renderWithProviders(<QuoteRequestComparisonPage />)

    expect(screen.getByText('LaboDerm')).toBeInTheDocument()
    expect(screen.getByText('BioSupply')).toBeInTheDocument()
    expect(screen.getAllByText(/EUR/).length).toBeGreaterThan(0)
    expect(screen.getByTestId('best-price-rfq-2-product-1::')).toBeInTheDocument()

    fireEvent.click(screen.getByTestId('award-rfq-2'))
    expect(screen.getByRole('dialog')).toBeInTheDocument()
    fireEvent.click(screen.getByTestId('confirm-award'))

    await waitFor(() => {
      expect(awardMutateAsync).toHaveBeenCalledWith()
      expect(navigate).toHaveBeenCalledWith('/purchases/orders/po-1')
    })
  })

  it('shows reopen when the group is closed and has no live purchase order', async () => {
    mockGroup.mockReturnValue(group({
      has_live_purchase_order: false,
      siblings: [
        { ...group().siblings[0], status: 'confirmed', closed_reason: null },
        { ...group().siblings[1], status: 'cancelled', closed_reason: 'lost' },
      ],
    }))

    renderWithProviders(<QuoteRequestComparisonPage />)
    fireEvent.click(screen.getByTestId('reopen-group'))

    await waitFor(() => {
      expect(reopenMutateAsync).toHaveBeenCalledWith()
    })
  })

  it('fails closed for award and reopen actions without a live purchase order flag', () => {
    const legacyGroup = group({
      siblings: [
        { ...group().siblings[0], status: 'confirmed', closed_reason: null },
        { ...group().siblings[1], status: 'cancelled', closed_reason: 'lost' },
      ],
    })
    delete legacyGroup.has_live_purchase_order
    mockGroup.mockReturnValue(legacyGroup)

    renderWithProviders(<QuoteRequestComparisonPage />)

    expect(screen.queryByTestId('reopen-group')).not.toBeInTheDocument()
    expect(screen.queryByTestId('award-rfq-1')).not.toBeInTheDocument()
  })

  it('hides award actions after the group has a live purchase order', () => {
    mockGroup.mockReturnValue(group({ has_live_purchase_order: true }))

    renderWithProviders(<QuoteRequestComparisonPage />)

    expect(screen.queryByTestId('award-rfq-1')).not.toBeInTheDocument()
    expect(screen.queryByTestId('award-rfq-2')).not.toBeInTheDocument()
  })

  it('closes the award dialog, refetches state, and surfaces API errors', async () => {
    mockGroup.mockReturnValue(group({ has_live_purchase_order: false }))
    awardMutateAsync.mockRejectedValue({
      response: {
        status: 422,
        data: { error: { message: 'RFQ_GROUP_ALREADY_AWARDED' } },
      },
    })

    renderWithProviders(<QuoteRequestComparisonPage />)

    fireEvent.click(screen.getByTestId('award-rfq-2'))
    fireEvent.click(screen.getByTestId('confirm-award'))

    await waitFor(() => {
      expect(toastError).toHaveBeenCalledWith('RFQ_GROUP_ALREADY_AWARDED')
      expect(refetchGroup).toHaveBeenCalledWith()
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
  })
})
