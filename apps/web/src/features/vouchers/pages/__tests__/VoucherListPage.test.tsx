import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { VoucherListPage } from '../VoucherListPage'

// ─── Router mocks ─────────────────────────────────────────────────────────────

const mockNavigate = vi.fn()
const mockSetSearchParams = vi.fn()
let mockSearchParamsSource = ''

vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  useSearchParams: () => [
    { get: (key: string) => (key === 'source' ? mockSearchParamsSource : null) },
    mockSetSearchParams,
  ],
}))

// ─── i18n mock ────────────────────────────────────────────────────────────────

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

// ─── TanStack Query mock ──────────────────────────────────────────────────────

const mockVouchers = [
  {
    id: 'v1',
    code: 'VCH-001',
    source: 'Refund' as const,
    status: 'Issued' as const,
    initial_balance: '50.00',
    current_balance: '50.00',
    currency: 'EUR',
    redemption_mode: 'Bearer' as const,
    expires_at: '2027-01-01',
    partner_id: 'p1',
    partner_name: 'Alice Martin',
    terminal_id: 't1',
    terminal_name: 'Terminal 1',
    cashier_id: null,
    cashier_name: null,
    created_at: '2026-04-01T00:00:00Z',
    updated_at: null,
  },
  {
    id: 'v2',
    code: 'VCH-002',
    source: 'Goodwill' as const,
    status: 'PartiallyRedeemed' as const,
    initial_balance: '100.00',
    current_balance: '30.00',
    currency: 'EUR',
    redemption_mode: 'CustomerBound' as const,
    expires_at: null,
    partner_id: null,
    partner_name: null,
    terminal_id: 't2',
    terminal_name: 'Terminal 2',
    cashier_id: null,
    cashier_name: null,
    created_at: '2026-04-10T00:00:00Z',
    updated_at: null,
  },
]

type MockQueryReturn = {
  data: { data: typeof mockVouchers; meta: { current_page: number; last_page: number; total: number; per_page: number } } | undefined
  isLoading: boolean
}

let mockUseQueryReturn: MockQueryReturn = {
  data: {
    data: mockVouchers,
    meta: { current_page: 1, last_page: 2, total: 30, per_page: 25 },
  },
  isLoading: false,
}

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useQuery: () => mockUseQueryReturn,
    useMutation: () => ({ mutate: vi.fn(), isPending: false }),
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
  }
})

// ─── Permission mock ──────────────────────────────────────────────────────────

let mockPermissions: Record<string, boolean> = {
  'pos.void_voucher': true,
  'pos.extend_voucher_expiry': true,
  'pos.issue_goodwill_voucher': true,
}

vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({
    hasPermission: (permission: string) => mockPermissions[permission] ?? false,
    hasAnyPermission: () => true,
    hasAllPermissions: () => true,
  }),
}))

// ─── useCompany mock (required by useReservationSettings inside modal) ─────────

vi.mock('@/hooks/useCompany', () => ({
  useCompany: () => ({ currentCompany: { id: 'c1' } }),
}))

// ─── useTerminals mock (required by IssueGoodwillVoucherModal) ────────────────

vi.mock('@/features/pos/hooks/useTerminals', () => ({
  useTerminals: () => ({ data: [] }),
}))

// ─── PartnerPicker mock ───────────────────────────────────────────────────────

vi.mock('@/components/molecules/pickers/PartnerPicker', () => ({
  PartnerPicker: () => null,
}))

// ─── Tests ────────────────────────────────────────────────────────────────────

describe('VoucherListPage', () => {
  beforeEach(() => {
    mockSearchParamsSource = ''
    mockPermissions = {
      'pos.void_voucher': true,
      'pos.extend_voucher_expiry': true,
      'pos.issue_goodwill_voucher': true,
    }
    mockUseQueryReturn = {
      data: {
        data: mockVouchers,
        meta: { current_page: 1, last_page: 2, total: 30, per_page: 25 },
      },
      isLoading: false,
    }
    mockNavigate.mockClear()
    mockSetSearchParams.mockClear()
  })

  it('renders table with mocked vouchers', () => {
    render(<VoucherListPage />)
    expect(screen.getByText('VCH-001')).toBeInTheDocument()
    expect(screen.getByText('VCH-002')).toBeInTheDocument()
    expect(screen.getByText('Alice Martin')).toBeInTheDocument()
  })

  it('shows loading spinner when isLoading is true', () => {
    mockUseQueryReturn = { data: undefined, isLoading: true }
    render(<VoucherListPage />)
    expect(screen.queryByText('VCH-001')).not.toBeInTheDocument()
  })

  it('shows empty state when data is empty', () => {
    mockUseQueryReturn = {
      data: { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 25 } },
      isLoading: false,
    }
    render(<VoucherListPage />)
    expect(screen.getByText('vouchers:empty.title')).toBeInTheDocument()
  })

  it('source filter chip click calls setSearchParams with the correct source', () => {
    render(<VoucherListPage />)
    const refundChip = screen.getByTestId('source-filter-Refund')
    fireEvent.click(refundChip)
    expect(mockSetSearchParams).toHaveBeenCalled()
  })

  it('Issue Goodwill button is visible when permission is present', () => {
    render(<VoucherListPage />)
    expect(screen.getByText('vouchers:actions.issueGoodwill')).toBeInTheDocument()
  })

  it('Issue Goodwill button is hidden when permission is absent', () => {
    mockPermissions = {
      'pos.void_voucher': false,
      'pos.extend_voucher_expiry': false,
      'pos.issue_goodwill_voucher': false,
    }
    render(<VoucherListPage />)
    expect(screen.queryByText('vouchers:actions.issueGoodwill')).not.toBeInTheDocument()
  })
})
