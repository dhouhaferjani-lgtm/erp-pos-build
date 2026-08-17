import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
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
})
