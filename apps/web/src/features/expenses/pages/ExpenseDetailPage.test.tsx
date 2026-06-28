import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'

import { ExpenseDetailPage } from './ExpenseDetailPage'

// ─── i18n: echo key, or return the string-interpolation arg when present ──────
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

// ─── react-router-dom: id param, capture navigate, Link as plain anchor ───────
const mockNavigate = vi.fn()
vi.mock('react-router-dom', () => ({
  useParams: () => ({ id: 'exp-1' }),
  useNavigate: () => mockNavigate,
  Link: ({ to, children }: { to: string; children: React.ReactNode }) => (
    <a href={to}>{children}</a>
  ),
}))

// ─── expenses hooks → tiny fixtures (presentation-only isolation) ─────────────
const mockUseExpense = vi.fn()
vi.mock('../hooks/useExpenses', () => ({
  useExpense: () => mockUseExpense() as unknown,
  useDeleteExpense: () => ({ mutateAsync: vi.fn(), isPending: false }),
  usePostExpense: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))

// ─── child organism → stub so the test isolates the page shell ────────────────
vi.mock('../../documents/components/DocumentAttachments', () => ({
  DocumentAttachments: () => <div data-testid="document-attachments" />,
}))

// ─── usePermissions: configurable mock — default grants all ──────────────────
const mockHasPermission = vi.fn((_p: string): boolean => true)
vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({
    hasPermission: (p: string) => mockHasPermission(p),
    hasAnyPermission: vi.fn(() => true),
    hasAllPermissions: vi.fn(() => true),
    canAccessModule: vi.fn(() => true),
  }),
}))

const fixtureExpense = {
  id: 'exp-1',
  type: 'expense' as const,
  status: 'posted' as const,
  document_number: 'EXP-0001',
  document_date: '2026-06-01',
  total: '120.000',
  currency: 'TND',
  notes: null,
  internal_notes: null,
  created_at: '2026-06-01T00:00:00Z',
  updated_at: '2026-06-01T00:00:00Z',
  metadata: {
    vendor_name: 'Acme Supplies',
    receipt_number: 'R-99',
    payment_date: null,
    is_paid: false,
    expense_category_id: null,
    payment_method_id: null,
    payment_repository_id: null,
  },
}

const fixtureDraftExpense = {
  ...fixtureExpense,
  status: 'draft' as const,
  document_number: 'EXP-DRAFT-0001',
}

beforeEach(() => {
  mockNavigate.mockReset()
  mockUseExpense.mockReset()
  mockHasPermission.mockReset()
  // Default: grant all permissions
  mockHasPermission.mockReturnValue(true)
  mockUseExpense.mockReturnValue({ data: fixtureExpense, isLoading: false })
})

describe('ExpenseDetailPage shell', () => {
  it('renders exactly one <h1> (the document number)', () => {
    render(<ExpenseDetailPage />)
    const headings = screen.getAllByRole('heading', { level: 1 })
    expect(headings).toHaveLength(1)
    expect(headings[0]).toHaveTextContent('EXP-0001')
  })

  it('renders the status via a StatusBadge pill', () => {
    render(<ExpenseDetailPage />)
    const badge = screen.getByText('expenses:status.posted')
    expect(badge.className).toContain('rounded-full')
  })

  it('renders the attachments organism', () => {
    render(<ExpenseDetailPage />)
    expect(screen.getByTestId('document-attachments')).toBeInTheDocument()
  })
})

describe('ExpenseDetailPage — Post button permission gating', () => {
  it('hides the Post button when user lacks expenses.post on a draft expense', () => {
    mockUseExpense.mockReturnValue({ data: fixtureDraftExpense, isLoading: false })
    // Deny expenses.post only
    mockHasPermission.mockImplementation((p: string) => p !== 'expenses.post')

    render(<ExpenseDetailPage />)

    expect(screen.queryByText('expenses:postExpense')).not.toBeInTheDocument()
  })

  it('shows the Post button when user has expenses.post on a draft expense', () => {
    mockUseExpense.mockReturnValue({ data: fixtureDraftExpense, isLoading: false })
    // Grant all permissions (default)
    mockHasPermission.mockReturnValue(true)

    render(<ExpenseDetailPage />)

    expect(screen.getByText('expenses:postExpense')).toBeInTheDocument()
  })

  it('never shows the Post button on an already-posted expense regardless of permission', () => {
    // fixtureExpense is posted — Post button must not render even with permission
    mockHasPermission.mockReturnValue(true)

    render(<ExpenseDetailPage />)

    expect(screen.queryByText('expenses:postExpense')).not.toBeInTheDocument()
  })
})

describe('ExpenseDetailPage — Edit / Delete button permission gating', () => {
  it('hides Edit button when user lacks expenses.update', () => {
    mockUseExpense.mockReturnValue({ data: fixtureDraftExpense, isLoading: false })
    mockHasPermission.mockImplementation((p: string) => p !== 'expenses.update')

    render(<ExpenseDetailPage />)

    expect(screen.queryByText('common:edit')).not.toBeInTheDocument()
  })

  it('shows Edit button when user has expenses.update on a draft expense', () => {
    mockUseExpense.mockReturnValue({ data: fixtureDraftExpense, isLoading: false })
    mockHasPermission.mockReturnValue(true)

    render(<ExpenseDetailPage />)

    expect(screen.getByText('common:edit')).toBeInTheDocument()
  })

  it('hides Delete button when user lacks expenses.delete', () => {
    mockUseExpense.mockReturnValue({ data: fixtureDraftExpense, isLoading: false })
    mockHasPermission.mockImplementation((p: string) => p !== 'expenses.delete')

    render(<ExpenseDetailPage />)

    expect(screen.queryByText('common:delete')).not.toBeInTheDocument()
  })

  it('shows Delete button when user has expenses.delete on a draft expense', () => {
    mockUseExpense.mockReturnValue({ data: fixtureDraftExpense, isLoading: false })
    mockHasPermission.mockReturnValue(true)

    render(<ExpenseDetailPage />)

    expect(screen.getByText('common:delete')).toBeInTheDocument()
  })
})
