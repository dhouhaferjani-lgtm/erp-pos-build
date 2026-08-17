import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { calendarDateInTimeZone } from '@/lib/format'
import { renderWithProviders } from '@/test/renderWithProviders'
import { ReceiptListPage } from './ReceiptListPage'
import { fetchReceiptFilterOptions, fetchReceipts } from '../../api/receiptApi'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
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

vi.mock('../../hooks/usePosTenantScope', () => ({
  usePosTenantScope: () => ({ tenantId: 'tenant-1', companyId: 'company-1', hasTenantScope: true }),
}))

vi.mock('@/hooks/useLocation', () => ({
  useLocation: () => ({ hasMultipleLocations: false }),
}))

vi.mock('@/stores/companyStore', () => ({
  useCompanyStore: Object.assign(
    (selector: (state: { currentCompanyId: string; companies: Array<{ id: string; timezone: string }> }) => unknown) => selector({
      currentCompanyId: 'company-1',
      companies: [{ id: 'company-1', timezone: 'Africa/Tunis' }],
    }),
    { getState: () => ({ currentCompanyId: 'company-1' }) },
  ),
}))

const row = {
  id: 'receipt-1',
  receipt_number: 'TN-POS-0001',
  posted_at: '2026-08-17T10:30:00.000000Z',
  invoice_type_code: 'TRAINING',
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
  beforeEach(() => {
    vi.clearAllMocks()
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
    renderWithProviders(<ReceiptListPage />, { route: '/pos/receipts' })

    expect(await screen.findByRole('link', { name: 'TN-POS-0001' })).toHaveAttribute('href', '/pos/receipts/receipt-1')
    expect(screen.getByText('12,345 TND')).toBeInTheDocument()
    expect(screen.getByText('pos:receipts.types.TRAINING')).toBeInTheDocument()
    expect(screen.queryByText('Tunis')).not.toBeInTheDocument()
    expect(screen.queryByText('REFUND')).not.toBeInTheDocument()
    expect(screen.queryByText('VOID')).not.toBeInTheDocument()
  })

  it('sends SALE only until training is explicitly enabled', async () => {
    renderWithProviders(<ReceiptListPage />, { route: '/pos/receipts' })

    await waitFor(() => {
      expect(fetchReceipts).toHaveBeenCalledWith(expect.objectContaining({
        invoice_type_codes: ['SALE'],
        include_training: false,
        location_ids: ['loc-1'],
      }))
    })

    fireEvent.click(screen.getByRole('checkbox', { name: 'pos:receipts.includeTraining' }))

    await waitFor(() => {
      expect(fetchReceipts).toHaveBeenLastCalledWith(expect.objectContaining({
        invoice_type_codes: ['SALE', 'TRAINING'],
        include_training: true,
      }))
    })
  })
})
