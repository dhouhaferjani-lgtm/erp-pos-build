import { beforeEach, describe, expect, it, vi } from 'vitest'
import { act, fireEvent, screen, waitFor } from '@testing-library/react'
import { calendarDateInTimeZone } from '@/lib/format'
import i18n from '@/lib/i18n'
import { locationScopedKey } from '@/lib/locationScopedKey'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { renderWithProviders } from '@/test/renderWithProviders'
import { ReceiptListPage } from './ReceiptListPage'
import { fetchReceiptFilterOptions, fetchReceipts } from '../../api/receiptApi'

const tenantScopeState = vi.hoisted(() => ({ hasTenantScope: true }))
const companyState = vi.hoisted(() => ({
  currentCompanyId: 'company-1' as string | null,
  companies: [{ id: 'company-1', timezone: 'Africa/Tunis' }],
}))

vi.mock('../../api/receiptApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../api/receiptApi')>()
  return {
    ...actual,
    fetchReceipts: vi.fn(),
    fetchReceiptFilterOptions: vi.fn(),
  }
})

vi.mock('@/features/locations/hooks/useViewScope', () => ({
  useViewScope: () => ({ scope: ['loc-1'], effectiveLocationIds: ['loc-1'], isAll: false, setScope: vi.fn() }),
}))

vi.mock('@/lib/locationScopedKey', () => ({
  locationScopedKey: vi.fn((base: readonly unknown[], scope: readonly string[]) => [...base, { locScope: scope }]),
}))

vi.mock('../../hooks/usePosTenantScope', () => ({
  usePosTenantScope: () => ({
    tenantId: tenantScopeState.hasTenantScope ? 'tenant-1' : null,
    companyId: tenantScopeState.hasTenantScope ? 'company-1' : null,
    hasTenantScope: tenantScopeState.hasTenantScope,
  }),
}))

vi.mock('@/hooks/useLocation', () => ({
  useLocation: () => ({ hasMultipleLocations: false }),
}))

vi.mock('@/stores/companyStore', () => ({
  useCompanyStore: Object.assign(
    (selector: (state: { currentCompanyId: string; companies: { id: string; timezone: string }[] }) => unknown) => selector({
      currentCompanyId: companyState.currentCompanyId ?? '',
      companies: companyState.companies,
    }),
    { getState: () => ({ currentCompanyId: 'company-1' }) },
  ),
}))

const row = {
  id: 'receipt-1',
  receipt_number: 'TN-POS-0001',
  posted_at: '2026-08-17T10:30:00.000000Z',
  invoice_type_code: 'TRAINING',
  receipt_type: 'sale',
  training_flag: true,
  fiscal_status: 'fiscalized',
  location_id: 'loc-1',
  location_name: 'Tunis',
  terminal_id: 'terminal-1',
  terminal_code: 'POS-1',
  cashier_id: 'cashier-1',
  cashier_name: 'Amina',
  total: '12.345',
  currency: 'TND',
  original_receipt_id: null,
} as const

describe('ReceiptListPage', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en')
    vi.clearAllMocks()
    tenantScopeState.hasTenantScope = true
    companyState.currentCompanyId = 'company-1'
    companyState.companies = [{ id: 'company-1', timezone: 'Africa/Tunis' }]
    vi.mocked(fetchReceipts).mockResolvedValue({
      data: [row],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: '2026-08-16T23:00:00.000000Z', to: '2026-08-17T23:00:00.000000Z' },
    })
    vi.mocked(fetchReceiptFilterOptions).mockResolvedValue({ terminals: [], cashiers: [] })
  })

  it('derives the default business day in the company timezone', () => {
    const boundary = new Date('2026-08-16T23:30:00.000Z')

    expect(calendarDateInTimeZone('Africa/Tunis', boundary)).toBe('2026-08-17')
    expect(calendarDateInTimeZone('America/New_York', boundary)).toBe('2026-08-16')
  })

  it('renders a register row with explicit receipt currency and one training signal', async () => {
    renderWithProviders(<ReceiptListPage />, { route: '/pos/receipts?include_training=true' })

    const receiptLink = await screen.findByRole('link', { name: 'TN-POS-0001' })
    expect(receiptLink).toHaveAttribute('href', '/pos/receipts/receipt-1')
    expect(screen.getByText('12,345 TND')).toBeInTheDocument()
    expect(screen.getByText('Training')).toBeInTheDocument()
    expect(receiptLink.closest('tr')).toHaveClass(colorTokens.surface.page)
    expect(screen.queryByText('Tunis')).not.toBeInTheDocument()
    expect(screen.queryByText('REFUND')).not.toBeInTheDocument()
    expect(screen.queryByText('VOID')).not.toBeInTheDocument()
  })

  it('sends SALE only until training is explicitly enabled', async () => {
    renderWithProviders(<ReceiptListPage />, { route: '/pos/receipts' })

    await waitFor(() => {
      expect(fetchReceipts).toHaveBeenCalledWith(expect.objectContaining({
        invoice_type_codes: ['SALE'],
        location_ids: ['loc-1'],
      }))
      expect(vi.mocked(fetchReceipts).mock.calls[0]?.[0]).not.toHaveProperty('include_training')
    })

    fireEvent.click(screen.getByRole('checkbox', { name: 'Include training receipts' }))

    await waitFor(() => {
      expect(fetchReceipts).toHaveBeenLastCalledWith(expect.objectContaining({
        invoice_type_codes: ['SALE', 'TRAINING'],
        include_training: true,
      }))
    })
    expect(screen.getByText('Training receipts included')).toBeInTheDocument()
  })

  it('keys the list query through the location-scoped key helper', async () => {
    renderWithProviders(<ReceiptListPage />, { route: '/pos/receipts' })

    await waitFor(() => {
      const listCall = vi.mocked(locationScopedKey).mock.calls.find(([key]) => key[0] === 'pos' && key[1] === 'receipts' && key.length === 3)
      expect(listCall?.[0][2]).toMatchObject({ location_ids: ['loc-1'], invoice_type_codes: ['SALE'] })
      expect(listCall?.[1]).toEqual(['loc-1'])
    })
  })

  it('offers to clear a filtered empty state', async () => {
    vi.mocked(fetchReceipts).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: '2026-08-16T23:00:00.000000Z', to: '2026-08-17T23:00:00.000000Z' },
    })

    renderWithProviders(<ReceiptListPage />, { route: '/pos/receipts?receipt_number=missing' })

    expect(await screen.findByText('No receipts match these filters')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Clear Filters' }))

    await waitFor(() => {
      expect(vi.mocked(fetchReceipts).mock.lastCall?.[0]).not.toHaveProperty('receipt_number')
    })
  })

  it.each([
    ['/pos/receipts', 'No POS receipts yet'],
    ['/pos/receipts?include_training=true', 'No receipts match these filters'],
  ])('renders the distinct unfiltered empty state for %s', async (route, title) => {
    vi.mocked(fetchReceipts).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null },
    })

    renderWithProviders(<ReceiptListPage />, { route })

    expect(await screen.findByText(title)).toBeInTheDocument()
  })

  it('renders a no-scope state without issuing receipt requests', () => {
    tenantScopeState.hasTenantScope = false

    renderWithProviders(<ReceiptListPage />, { route: '/pos/receipts' })

    expect(screen.getByText('Select a company to view receipts')).toBeInTheDocument()
    expect(fetchReceipts).not.toHaveBeenCalled()
    expect(fetchReceiptFilterOptions).not.toHaveBeenCalled()
  })

  it('waits for the active company before deriving the default business day', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-08-16T23:30:00.000Z'))
    companyState.companies = []

    const view = renderWithProviders(<ReceiptListPage />, { route: '/pos/receipts' })
    expect(fetchReceipts).not.toHaveBeenCalled()

    companyState.companies = [{ id: 'company-1', timezone: 'Africa/Tunis' }]
    await act(async () => {
      view.rerender(<ReceiptListPage />)
      await vi.runAllTimersAsync()
    })

    expect(fetchReceipts).toHaveBeenCalledWith(expect.objectContaining({
      from_date: '2026-08-17',
      to_date: '2026-08-17',
    }))
    vi.useRealTimers()
  })

  it('renders the list shell with French translations', async () => {
    await i18n.changeLanguage('fr')

    renderWithProviders(<ReceiptListPage />, { route: '/pos/receipts?include_training=true' })

    expect(await screen.findByRole('heading', { name: 'Tickets' })).toBeInTheDocument()
    expect(screen.getByText('Tickets de formation inclus')).toBeInTheDocument()
    expect(screen.queryByText('receipts.trainingIncluded')).not.toBeInTheDocument()
  })
})
