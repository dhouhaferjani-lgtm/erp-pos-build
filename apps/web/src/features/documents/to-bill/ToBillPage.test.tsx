import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { renderWithProviders } from '@/test/renderWithProviders'
import type { ToBillQueueResponse, ToBillPartnerRowsResponse } from '../api/deliveryNotes'
import { ToBillPage } from './ToBillPage'

const mockUseToBillQueue = vi.hoisted(() => vi.fn())
const mockUseToBillPartnerRows = vi.hoisted(() => vi.fn())
const mockMutateAsync = vi.hoisted(() => vi.fn())
const mockHasPermission = vi.hoisted(() => vi.fn(() => true))

vi.mock('../hooks/useDeliveryNotes', () => ({
  useToBillQueue: mockUseToBillQueue,
  useToBillPartnerRows: mockUseToBillPartnerRows,
  useConsolidateDeliveryNotes: () => ({ mutateAsync: mockMutateAsync, isPending: false }),
}))

vi.mock('@/hooks/useLocation', () => ({
  useLocation: () => ({ currentLocationId: 'location-1', isLoading: false }),
}))

vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: mockHasPermission }),
}))

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({ format: (value: string) => `TND ${value}` }),
  formatAmount: (value: string, currency: string) => `${currency} ${value}`,
}))

const queue: ToBillQueueResponse = {
  data: [
    {
      partner_id: 'partner-old',
      partner_name: 'Atlas Periodic',
      partner_code: 'ATLAS',
      delivery_note_count: 2,
      total: '150.000',
      currency: 'TND',
      oldest_document_date: '2026-05-01',
      aging_bucket: '90_plus',
      is_periodic: true,
    },
    {
      partner_id: 'partner-new',
      partner_name: 'Bizerte Retail',
      partner_code: 'BIZ',
      delivery_note_count: 1,
      total: '25.000',
      currency: 'TND',
      oldest_document_date: '2026-08-09',
      aging_bucket: '0_30',
      is_periodic: false,
    },
  ],
  meta: { current_page: 1, last_page: 2, total: 3, per_page: 2 },
  summary: {
    buckets: [
      { bucket: '0_30', count: 1, total: '25.000' },
      { bucket: '31_60', count: 0, total: '0.000' },
      { bucket: '61_90', count: 0, total: '0.000' },
      { bucket: '90_plus', count: 1, total: '150.000' },
    ],
    grand_total: '175.000',
    grand_count: 2,
    currency: 'TND',
  },
}

const rows: ToBillPartnerRowsResponse = {
  data: [
    {
      id: 'dn-1',
      document_number: 'DN-001',
      document_date: '2026-05-01',
      partner_id: 'partner-old',
      partner_name: 'Atlas Periodic',
      subtotal: '100.000',
      tax_amount: '0.000',
      total: '100.000',
      currency: 'TND',
    },
    {
      id: 'dn-2',
      document_number: 'DN-002',
      document_date: '2026-06-01',
      partner_id: 'partner-old',
      partner_name: 'Atlas Periodic',
      subtotal: '50.000',
      tax_amount: '0.000',
      total: '50.000',
      currency: 'TND',
    },
  ],
  meta: { current_page: 1, last_page: 1, total: 2, per_page: 25 },
  summary: { count: 2, total: '150.000', currency: 'TND' },
}

beforeEach(() => {
  vi.clearAllMocks()
  mockUseToBillQueue.mockReturnValue({ data: queue, isLoading: false, error: null, refetch: vi.fn() })
  mockUseToBillPartnerRows.mockImplementation((_partnerId: string, _params: unknown, enabled: boolean) => ({
    data: enabled ? rows : undefined,
    isLoading: false,
    error: null,
    refetch: vi.fn(),
  }))
  mockMutateAsync.mockResolvedValue({ data: { id: 'invoice-1' } })
  mockHasPermission.mockReturnValue(true)
})

describe('ToBillPage', () => {
  it('renders the aging summary and oldest-first partner groups without a global bill action', () => {
    renderWithProviders(<ToBillPage />)

    expect(screen.getByRole('heading', { name: 'To bill' })).toBeInTheDocument()
    const aging = screen.getByRole('region', { name: 'Un-invoiced delivery note aging' })
    expect(within(aging).getByText('0–30 days')).toBeInTheDocument()
    expect(within(aging).getByText('31–60 days')).toBeInTheDocument()
    expect(within(aging).getByText('61–90 days')).toBeInTheDocument()
    expect(within(aging).getByText('90+ days')).toBeInTheDocument()
    expect(within(aging).getByText('TND 175.000')).toBeInTheDocument()

    const oldGroup = screen.getByRole('article', { name: /Atlas Periodic/ })
    const newGroup = screen.getByRole('article', { name: /Bizerte Retail/ })
    expect(oldGroup.compareDocumentPosition(newGroup) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(within(oldGroup).getByText('Billed periodically')).toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: /Create invoice/ })).toHaveLength(2)
    expect(screen.queryByRole('button', { name: /Bill everyone/i })).not.toBeInTheDocument()
  })

  it('applies search, date, and billed-periodically filters to the group query', async () => {
    const user = userEvent.setup()
    renderWithProviders(<ToBillPage />)

    await user.type(screen.getByRole('searchbox', { name: 'Search partners' }), 'atlas')
    await user.type(screen.getByLabelText('From date'), '2026-07-01')
    await user.type(screen.getByLabelText('To date'), '2026-08-10')
    await user.click(screen.getByRole('button', { name: 'Billed periodically only' }))

    await waitFor(() => {
      expect(mockUseToBillQueue).toHaveBeenLastCalledWith(expect.objectContaining({
        locationId: 'location-1',
        partnerSearch: 'atlas',
        dateFrom: '2026-07-01',
        dateTo: '2026-08-10',
        periodicOnly: true,
        page: 1,
      }))
    })
  })

  it('lazily expands rows and reconciles them with the partner summary', async () => {
    const user = userEvent.setup()
    renderWithProviders(<ToBillPage />)

    expect(mockUseToBillPartnerRows).toHaveBeenCalledWith('partner-old', expect.anything(), false)
    await user.click(screen.getByRole('button', { name: 'Expand Atlas Periodic' }))

    expect(await screen.findByRole('link', { name: 'DN-001' })).toHaveAttribute(
      'href',
      '/inventory/delivery-notes/dn-1',
    )
    expect(screen.getByRole('link', { name: 'DN-002' })).toBeInTheDocument()
    expect(screen.getByText('2 delivery notes · TND 150.000')).toBeInTheDocument()
    expect(mockUseToBillPartnerRows).toHaveBeenLastCalledWith(
      'partner-old',
      expect.objectContaining({ locationId: 'location-1', page: 1 }),
      true,
    )
  })

  it('opens a per-group confirmation with partner, count, and total before submit', async () => {
    const user = userEvent.setup()
    renderWithProviders(<ToBillPage />)

    const group = screen.getByRole('article', { name: /Atlas Periodic/ })
    await user.click(within(group).getByRole('button', { name: 'Create invoice' }))

    const dialog = await screen.findByRole('heading', { name: 'Create invoice for Atlas Periodic?' })
    expect(dialog.parentElement).toHaveTextContent('2 delivery notes')
    expect(dialog.parentElement).toHaveTextContent('TND 150.000')
    expect(mockMutateAsync).not.toHaveBeenCalled()
  })

  it('hides every group action from a deliveries.view-only user', () => {
    mockHasPermission.mockReturnValue(false)
    renderWithProviders(<ToBillPage />)

    expect(screen.queryByRole('button', { name: 'Create invoice' })).not.toBeInTheDocument()
  })

  it('renders the queue empty state and group-level pagination', async () => {
    const user = userEvent.setup()
    const empty = { ...queue, data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 2 } }
    mockUseToBillQueue.mockReturnValueOnce({ data: empty, isLoading: false, error: null, refetch: vi.fn() })
    const first = renderWithProviders(<ToBillPage />)
    expect(screen.getByText('Nothing left to bill')).toBeInTheDocument()
    first.unmount()

    renderWithProviders(<ToBillPage />)
    await user.click(screen.getByRole('button', { name: 'Next' }))
    expect(mockUseToBillQueue).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2, perPage: 25 }))
  })
})
