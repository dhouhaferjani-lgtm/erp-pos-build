import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { CustomerHistoryAuditPage } from '../CustomerHistoryAuditPage'
import type { CustomerHistorySearch } from '../../types/customerHistorySearch'

// ─── Router mocks ─────────────────────────────────────────────────────────────

const mockSetSearchParams = vi.fn()
let mockSearchParamsValues: Record<string, string> = {}

vi.mock('react-router-dom', () => ({
  useSearchParams: () => [
    { get: (key: string) => mockSearchParamsValues[key] ?? null },
    mockSetSearchParams,
  ],
}))

// ─── i18n mock ────────────────────────────────────────────────────────────────

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      if (opts && 'count' in opts) return `${key}:${String(opts['count'])}`
      return key
    },
  }),
}))

// ─── TanStack Query mock ──────────────────────────────────────────────────────

const mockRow1: CustomerHistorySearch = {
  id: 'r1',
  company_id: 'c1',
  cashier_id: 'u1',
  terminal_id: 't1',
  partner_id: 'p1',
  search_hash: 'abc123',
  was_rejected: false,
  rejection_reason: null,
  result_count: 5,
  created_at: '2026-04-01T10:00:00Z',
  cashier_name: 'John Doe',
  terminal_name: 'Terminal A',
  partner_name: 'Alice Martin',
}

const mockRow2: CustomerHistorySearch = {
  id: 'r2',
  company_id: 'c1',
  cashier_id: 'u2',
  terminal_id: 't2',
  partner_id: null,
  search_hash: 'def456',
  was_rejected: true,
  rejection_reason: 'too broad',
  result_count: 0,
  created_at: '2026-04-02T11:00:00Z',
  cashier_name: 'Jane Smith',
  terminal_name: 'Terminal B',
  partner_name: null,
}

type MockQueryReturn = {
  data:
    | {
        data: CustomerHistorySearch[]
        meta: { current_page: number; last_page: number; total: number; per_page: number }
      }
    | undefined
  isLoading: boolean
}

let mockUseQueryReturn: MockQueryReturn = {
  data: {
    data: [mockRow1, mockRow2],
    meta: { current_page: 1, last_page: 1, total: 2, per_page: 25 },
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

let mockCanAccess = true

vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({
    hasPermission: (permission: string) =>
      permission === 'pos.search_customer_full_history' ? mockCanAccess : false,
    hasAnyPermission: () => mockCanAccess,
    hasAllPermissions: () => mockCanAccess,
  }),
}))

// ─── Terminals mock ────────────────────────────────────────────────────────────

vi.mock('@/features/pos/hooks/useTerminals', () => ({
  useTerminals: () => ({
    data: [
      { id: 't1', name: 'Terminal A' },
      { id: 't2', name: 'Terminal B' },
    ],
  }),
}))

// ─── PartnerSearchSelect mock ─────────────────────────────────────────────────

vi.mock('@/components/ui/PartnerSearchSelect', () => ({
  PartnerSearchSelect: ({ placeholder, onChange }: { placeholder: string; onChange: (v: string) => void }) => (
    <button type="button" data-testid={`partner-picker-${placeholder}`} onClick={() => { onChange('partner-x') }}>
      {placeholder}
    </button>
  ),
}))

// ─── Tests ────────────────────────────────────────────────────────────────────

describe('CustomerHistoryAuditPage', () => {
  beforeEach(() => {
    mockSearchParamsValues = {}
    mockCanAccess = true
    mockUseQueryReturn = {
      data: {
        data: [mockRow1, mockRow2],
        meta: { current_page: 1, last_page: 1, total: 2, per_page: 25 },
      },
      isLoading: false,
    }
    mockSetSearchParams.mockClear()
  })

  it('renders rows from mocked data with denormalized names', () => {
    render(<CustomerHistoryAuditPage />)
    expect(screen.getByText('John Doe')).toBeInTheDocument()
    expect(screen.getByText('Alice Martin')).toBeInTheDocument()
    expect(screen.getByText('Jane Smith')).toBeInTheDocument()
  })

  it('shows loading state', () => {
    mockUseQueryReturn = { data: undefined, isLoading: true }
    render(<CustomerHistoryAuditPage />)
    expect(screen.queryByText('John Doe')).not.toBeInTheDocument()
  })

  it('shows empty state when zero results', () => {
    mockUseQueryReturn = {
      data: { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 25 } },
      isLoading: false,
    }
    render(<CustomerHistoryAuditPage />)
    expect(screen.getByText('customer-history-audit:noResults')).toBeInTheDocument()
    expect(screen.getByText('customer-history-audit:empty')).toBeInTheDocument()
  })

  it('rejected row shows RejectedBadge', () => {
    render(<CustomerHistoryAuditPage />)
    // RejectedBadge calls t('rejected') with its own namespace; the mock returns the bare key
    expect(screen.getByText('rejected')).toBeInTheDocument()
  })

  it('rejected row shows rejection_reason text', () => {
    render(<CustomerHistoryAuditPage />)
    expect(screen.getByText('too broad')).toBeInTheDocument()
  })

  it('non-Manager/Admin user sees Access Denied state', () => {
    mockCanAccess = false
    render(<CustomerHistoryAuditPage />)
    expect(screen.getByText('customer-history-audit:accessDenied')).toBeInTheDocument()
    expect(screen.queryByText('John Doe')).not.toBeInTheDocument()
  })

  it('summary chips match row data counts', () => {
    render(<CustomerHistoryAuditPage />)
    // total = 2 (from meta.total), rejectedCount = 1 (row2 was_rejected)
    expect(screen.getByTestId('chip-total')).toHaveTextContent('summary.searches:2')
    expect(screen.getByTestId('chip-rejected')).toHaveTextContent('summary.rejected:1')
  })

  it('filter change for was_rejected updates URL search params', () => {
    render(<CustomerHistoryAuditPage />)
    const select = screen.getByDisplayValue('customer-history-audit:filters.all')
    fireEvent.change(select, { target: { value: 'true' } })
    expect(mockSetSearchParams).toHaveBeenCalled()
    const arg = mockSetSearchParams.mock.calls[0][0] as URLSearchParams
    expect(arg.get('was_rejected')).toBe('true')
  })
})
