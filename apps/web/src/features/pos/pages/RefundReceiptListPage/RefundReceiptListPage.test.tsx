import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import i18n from '@/lib/i18n'
import { renderWithProviders } from '@/test/renderWithProviders'
import { RefundReceiptListPage } from './RefundReceiptListPage'
import { fetchReceiptFilterOptions, fetchRefundReceipts } from '../../api/receiptApi'

vi.mock('../../api/receiptApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../api/receiptApi')>()
  return {
    ...actual,
    fetchReceiptFilterOptions: vi.fn(),
    fetchRefundReceipts: vi.fn(),
  }
})

vi.mock('@/features/locations/hooks/useViewScope', () => ({
  useViewScope: () => ({ scope: ['loc-1'], effectiveLocationIds: ['loc-1'], isAll: false, setScope: vi.fn() }),
}))

vi.mock('../../hooks/usePosTenantScope', () => ({
  usePosTenantScope: () => ({ tenantId: 'tenant-1', companyId: 'company-1', hasTenantScope: true }),
}))

vi.mock('@/stores/companyStore', () => ({
  useCompanyStore: Object.assign(
    (selector: (state: { currentCompanyId: string; companies: { id: string; timezone: string }[] }) => unknown) => selector({
      currentCompanyId: 'company-1',
      companies: [{ id: 'company-1', timezone: 'Africa/Tunis' }],
    }),
    { getState: () => ({ currentCompanyId: 'company-1' }) },
  ),
}))

const baseTerminal = {
  id: 'terminal-1',
  code: 'POS-1',
  name: 'Front till',
  is_active: true,
  v4_refund_authoring_enabled: false,
  v4_refund_authoring_acknowledged_at: null,
}

const refundRow = {
  id: 'refund-1',
  receipt_number: 'TN-POS-R-0001',
  posted_at: '2026-08-17T10:30:00.000000Z',
  invoice_type_code: 'REFUND',
  receipt_type: 'return',
  training_flag: false,
  fiscal_status: 'fiscalized',
  location_id: 'loc-1',
  location_name: 'Tunis',
  terminal_id: 'terminal-1',
  terminal_code: 'POS-1',
  cashier_id: 'cashier-1',
  cashier_name: 'Amina',
  total: '12.345',
  currency: 'TND',
  original_receipt_id: 'sale-1',
  original_receipt_number: 'TN-POS-0001',
  refund_reason: 'Customer return',
  refund_reason_source: 'canonical',
  refund_destination: 'cash',
  refund_policy_alerts: [{
    type: 'non_zero_original_transaction_discount',
    original_transaction_discount_amount: '2.000',
    original_fiscal_event_id: 'event-1',
  }],
}

describe('RefundReceiptListPage', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en')
    vi.clearAllMocks()
    vi.mocked(fetchRefundReceipts).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null },
    })
  })

  it.each([
    [{ ...baseTerminal }, 'Refunds are not enabled on POS-1 — the device cannot process one.'],
    [{ ...baseTerminal, v4_refund_authoring_enabled: true }, 'POS-1 is enabled and awaiting device acknowledgement.'],
    [{ ...baseTerminal, v4_refund_authoring_enabled: true, v4_refund_authoring_acknowledged_at: '2026-08-17T10:00:00Z' }, 'POS-1 active since'],
  ])('renders the server-derived capability state without pretending an empty register is evidence', async (terminal, copy) => {
    vi.mocked(fetchReceiptFilterOptions).mockResolvedValue({ terminals: [terminal], cashiers: [] })

    renderWithProviders(<RefundReceiptListPage />, { route: '/pos/receipts/refunds' })

    expect(await screen.findByText(new RegExp(copy))).toBeInTheDocument()
    expect(screen.queryByText('No refunds today')).not.toBeInTheDocument()
  })

  it('uses one server-paginated REFUND and VOID request without the training axis', async () => {
    vi.mocked(fetchReceiptFilterOptions).mockResolvedValue({
      terminals: [{ ...baseTerminal, v4_refund_authoring_enabled: true, v4_refund_authoring_acknowledged_at: '2026-08-17T10:00:00Z' }],
      cashiers: [],
    })

    renderWithProviders(<RefundReceiptListPage />, { route: '/pos/receipts/refunds' })

    await waitFor(() => {
      expect(fetchRefundReceipts).toHaveBeenCalledTimes(1)
      expect(fetchRefundReceipts).toHaveBeenCalledWith(expect.objectContaining({
        invoice_type_codes: ['REFUND', 'VOID'],
      }))
      expect(vi.mocked(fetchRefundReceipts).mock.calls[0]?.[0]).not.toHaveProperty('include_training')
    })
  })

  it('marks only the refunds tab as the current page', async () => {
    vi.mocked(fetchReceiptFilterOptions).mockResolvedValue({
      terminals: [{ ...baseTerminal, v4_refund_authoring_enabled: true, v4_refund_authoring_acknowledged_at: '2026-08-17T10:00:00Z' }],
      cashiers: [],
    })

    renderWithProviders(<RefundReceiptListPage />, { route: '/pos/receipts/refunds' })

    await screen.findByRole('tab', { name: 'Refunds & voids' })
    expect(screen.getByRole('tab', { name: 'Receipts' })).not.toHaveAttribute('aria-current')
    expect(screen.getByRole('tab', { name: 'Refunds & voids' })).toHaveAttribute('aria-current', 'page')
  })

  it('reveals the recorded policy-alert context while null and empty alerts stay quiet', async () => {
    vi.mocked(fetchReceiptFilterOptions).mockResolvedValue({
      terminals: [{ ...baseTerminal, v4_refund_authoring_enabled: true, v4_refund_authoring_acknowledged_at: '2026-08-17T10:00:00Z' }],
      cashiers: [],
    })
    vi.mocked(fetchRefundReceipts).mockResolvedValue({
      data: [refundRow],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: '2026-08-16T23:00:00.000000Z', to: '2026-08-17T23:00:00.000000Z' },
    })

    renderWithProviders(<RefundReceiptListPage />, { route: '/pos/receipts/refunds' })

    const alertBadge = await screen.findByText('1 alert')
    fireEvent.click(alertBadge)
    expect(screen.getByText('Original transaction included a discount')).toBeVisible()
    expect(screen.getByText(/2[.,]000/)).toBeVisible()
    expect(screen.getByText('event-1')).toBeVisible()
  })

  it('keeps historical refunds visible when no terminal can currently author a refund', async () => {
    vi.mocked(fetchReceiptFilterOptions).mockResolvedValue({ terminals: [{ ...baseTerminal }], cashiers: [] })
    vi.mocked(fetchRefundReceipts).mockResolvedValue({
      data: [refundRow],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: null, to: null },
    })

    renderWithProviders(<RefundReceiptListPage />, { route: '/pos/receipts/refunds' })

    expect(await screen.findByRole('link', { name: 'TN-POS-R-0001' })).toBeVisible()
  })

  it('shows whether a refund reason is canonical or only a legacy classification', async () => {
    vi.mocked(fetchReceiptFilterOptions).mockResolvedValue({
      terminals: [{ ...baseTerminal, v4_refund_authoring_enabled: true, v4_refund_authoring_acknowledged_at: '2026-08-17T10:00:00Z' }],
      cashiers: [],
    })
    vi.mocked(fetchRefundReceipts).mockResolvedValue({
      data: [refundRow, {
        ...refundRow,
        id: 'refund-legacy',
        receipt_number: 'TN-POS-R-0002',
        original_receipt_id: null,
        original_receipt_number: null,
        refund_reason: 'Defective product',
        refund_reason_source: 'legacy_enum',
        refund_destination: null,
      }],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 2, from: null, to: null },
    })

    renderWithProviders(<RefundReceiptListPage />, { route: '/pos/receipts/refunds' })

    expect(await screen.findByText('Reason recorded in the fiscal event')).toBeVisible()
    expect(screen.getByText('Legacy classification — not authored text')).toBeVisible()
    expect(screen.getAllByText('—').length).toBeGreaterThanOrEqual(2)
  })
})
