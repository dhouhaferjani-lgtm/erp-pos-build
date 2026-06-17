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

beforeEach(() => {
  mockNavigate.mockReset()
  mockUseExpense.mockReset()
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
