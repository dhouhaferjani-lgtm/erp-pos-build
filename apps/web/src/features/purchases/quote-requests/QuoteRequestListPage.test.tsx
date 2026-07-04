import { screen, waitFor } from '@testing-library/react'
import { describe, expect, it, vi, beforeEach } from 'vitest'

import { renderWithProviders } from '@/test/renderWithProviders'

import { QuoteRequestListPage } from './QuoteRequestListPage'

import type { QuoteRequestListItem } from './types'

const mockUseQuoteRequests = vi.hoisted(() => vi.fn())

vi.mock('./api', () => ({
  useQuoteRequests: mockUseQuoteRequests,
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      if (!opts) return key
      return `${key}:${JSON.stringify(opts)}`
    },
  }),
}))

function item(overrides: Partial<QuoteRequestListItem>): QuoteRequestListItem {
  return {
    id: 'rfq-1',
    number: 'DP-2026-0001',
    partner: { id: 'supplier-1', name: 'LaboDerm' },
    status: 'confirmed',
    currency: 'EUR',
    total: '410.000',
    group_id: 'group-1',
    responded_at: null,
    lines: [],
    ...overrides,
  }
}

beforeEach(() => {
  vi.clearAllMocks()
})

describe('QuoteRequestListPage', () => {
  it('collapses siblings into one group row with supplier and response counts', async () => {
    mockUseQuoteRequests.mockReturnValue({
      data: {
        data: [
          item({ id: 'rfq-1', partner: { id: 'supplier-1', name: 'LaboDerm' }, responded_at: null }),
          item({ id: 'rfq-2', number: 'DP-2026-0002', partner: { id: 'supplier-2', name: 'BioSupply' }, responded_at: '2026-07-03T10:00:00Z' }),
          item({ id: 'rfq-3', number: 'DP-2026-0003', partner: { id: 'supplier-3', name: 'PharmaLine' }, responded_at: '2026-07-03T11:00:00Z' }),
        ],
      },
      isLoading: false,
    })

    renderWithProviders(<QuoteRequestListPage />)

    await waitFor(() => {
      expect(screen.getByTestId('rfq-group-row-group-1')).toBeInTheDocument()
    })
    expect(screen.getByTestId('rfq-group-chip-group-1')).toHaveTextContent(
      'purchases:quoteRequests.list.groupChip',
    )
    expect(screen.getByTestId('rfq-group-chip-group-1')).toHaveTextContent('"count":3')
    expect(screen.getByTestId('rfq-group-chip-group-1')).toHaveTextContent('"responseCount":2')
    expect(screen.getByText('LaboDerm, BioSupply, PharmaLine')).toBeInTheDocument()
    expect(screen.getByText(/EUR/)).toBeInTheDocument()
    expect(screen.getByText('purchases:quoteRequests.status.sent')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'purchases:quoteRequests.actions.open' })).toHaveAttribute(
      'href',
      '/purchases/quote-requests/groups/group-1',
    )
  })

  it('keeps metadata-free legacy list items as single-detail rows', async () => {
    mockUseQuoteRequests.mockReturnValue({
      data: {
        data: [
          item({
            id: 'rfq-legacy',
            group_id: null,
            responded_at: null,
            partner: { id: 'supplier-1', name: 'LaboDerm' },
          }),
        ],
      },
      isLoading: false,
    })

    renderWithProviders(<QuoteRequestListPage />)

    await waitFor(() => {
      expect(screen.getByTestId('rfq-group-row-rfq-legacy')).toBeInTheDocument()
    })
    expect(screen.getByRole('link', { name: 'purchases:quoteRequests.actions.open' })).toHaveAttribute(
      'href',
      '/purchases/quote-requests/rfq-legacy',
    )
  })
})
