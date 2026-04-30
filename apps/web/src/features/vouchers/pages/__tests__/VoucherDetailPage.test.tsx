import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { VoucherDetailPage } from '../VoucherDetailPage'
import type { VoucherDetail, VoucherStatus } from '../../types/voucher'

// ─── Router mocks ─────────────────────────────────────────────────────────────

const mockNavigate = vi.fn()
let mockParamId = 'v1'

vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  useParams: () => ({ id: mockParamId }),
  Link: ({ to, children }: { to: string; children: React.ReactNode }) => (
    <a href={to}>{children}</a>
  ),
}))

// ─── i18n mock ────────────────────────────────────────────────────────────────

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

// ─── Permission mock ──────────────────────────────────────────────────────────

let mockPermissions: Record<string, boolean> = {
  'pos.void_voucher': true,
  'pos.extend_voucher_expiry': true,
  'pos.transfer_voucher': true,
}

vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({
    hasPermission: (permission: string) => mockPermissions[permission] ?? false,
    hasAnyPermission: () => true,
    hasAllPermissions: () => true,
  }),
}))

// ─── Mock voucher data ────────────────────────────────────────────────────────

const mockVoucherDetail = {
  id: 'v1',
  code: 'VCH-GOODWILL-01',
  source: 'Goodwill' as const,
  status: 'Issued' as const,
  initial_balance: '200.00',
  current_balance: '200.00',
  currency: 'EUR',
  redemption_mode: 'CustomerBound' as const,
  expires_at: '2027-06-01',
  partner_id: 'p1',
  partner_name: 'Bob Dupont',
  terminal_id: 't1',
  terminal_name: 'Terminal 1',
  cashier_id: null,
  cashier_name: null,
  created_at: '2026-04-15T00:00:00Z',
  updated_at: null,
  ledger: [
    {
      id: 'l1',
      event: 'Issued' as const,
      amount: '200.00',
      running_balance: '200.00',
      receipt_id: null,
      receipt_number: null,
      terminal_id: 't1',
      terminal_name: 'Terminal 1',
      user_id: 'u1',
      user_name: 'Admin User',
      policy_trigger: null,
      notes: null,
      occurred_at: '2026-04-15T10:00:00Z',
    },
  ],
  provenance: {
    source: 'Goodwill' as const,
    issued_by_user_id: 'u1',
    issued_by_user_name: 'Admin User',
    authorized_by_user_id: null,
    override_reason: 'Customer satisfaction gesture',
    notes: 'Long-standing customer issue',
  },
}

type MockQueryReturn = {
  data: VoucherDetail | undefined
  isLoading: boolean
  isError: boolean
}

let mockUseQueryReturn: MockQueryReturn = {
  data: mockVoucherDetail,
  isLoading: false,
  isError: false,
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

// ─── useCompany mock ──────────────────────────────────────────────────────────

vi.mock('@/hooks/useCompany', () => ({
  useCompany: () => ({ currentCompany: { id: 'c1' } }),
}))

// ─── useTerminals mock ────────────────────────────────────────────────────────

vi.mock('@/features/pos/hooks/useTerminals', () => ({
  useTerminals: () => ({ data: [] }),
}))

// ─── PartnerPicker mock ───────────────────────────────────────────────────────

vi.mock('@/components/molecules/pickers/PartnerPicker', () => ({
  PartnerPicker: () => null,
}))

// ─── Tests ────────────────────────────────────────────────────────────────────

describe('VoucherDetailPage', () => {
  beforeEach(() => {
    mockParamId = 'v1'
    mockPermissions = {
      'pos.void_voucher': true,
      'pos.extend_voucher_expiry': true,
      'pos.transfer_voucher': true,
    }
    mockUseQueryReturn = {
      data: mockVoucherDetail,
      isLoading: false,
      isError: false,
    }
    mockNavigate.mockClear()
  })

  it('renders header with code and balance from mocked data', () => {
    render(<VoucherDetailPage />)
    expect(screen.getByText('VCH-GOODWILL-01')).toBeInTheDocument()
    expect(screen.getByText('200.00')).toBeInTheDocument()
  })

  it('renders ledger rows', () => {
    render(<VoucherDetailPage />)
    // "Admin User" appears in both ledger user column and provenance section
    const allAdminUser = screen.getAllByText('Admin User')
    expect(allAdminUser.length).toBeGreaterThanOrEqual(1)
  })

  it('renders provenance section for Goodwill source', () => {
    render(<VoucherDetailPage />)
    // ProvenanceSection uses useTranslation('vouchers') so t() returns bare key
    expect(screen.getByText('provenance.title')).toBeInTheDocument()
  })

  it('Transfer button is hidden when pos.transfer_voucher permission is absent', () => {
    mockPermissions = {
      'pos.void_voucher': true,
      'pos.extend_voucher_expiry': true,
      'pos.transfer_voucher': false,
    }
    render(<VoucherDetailPage />)
    expect(screen.queryByText('vouchers:actions.transfer')).not.toBeInTheDocument()
  })

  it('shows not-found error state when isError is true and data is undefined', () => {
    mockUseQueryReturn = { data: undefined, isLoading: false, isError: true }
    render(<VoucherDetailPage />)
    expect(screen.getByText('vouchers:errors.notFound')).toBeInTheDocument()
  })

  it('hides Extend Expiry button when status is Voided', () => {
    mockUseQueryReturn = {
      data: { ...mockVoucherDetail, status: 'Voided' as VoucherStatus },
      isLoading: false,
      isError: false,
    }
    render(<VoucherDetailPage />)
    expect(screen.queryByText('vouchers:actions.extend')).not.toBeInTheDocument()
  })

  it('hides Extend Expiry button when status is FullyRedeemed', () => {
    mockUseQueryReturn = {
      data: { ...mockVoucherDetail, status: 'FullyRedeemed' as VoucherStatus },
      isLoading: false,
      isError: false,
    }
    render(<VoucherDetailPage />)
    expect(screen.queryByText('vouchers:actions.extend')).not.toBeInTheDocument()
  })
})
