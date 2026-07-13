import { describe, it, expect, vi, beforeEach } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useParams } from 'react-router-dom'

import { ExpenseDetailPage } from './ExpenseDetailPage'

// ─── i18n: echo key, or return the string-interpolation arg when present ──────
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

// ─── expenses hooks → tiny fixtures (presentation-only isolation) ─────────────
const mockUseExpense = vi.fn()
vi.mock('../hooks/useExpenses', () => ({
  useExpense: () => mockUseExpense() as unknown,
  useDeleteExpense: () => ({ mutateAsync: vi.fn(), isPending: false }),
  usePostExpense: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))

vi.mock('../components/PayExpenseDialog', () => ({
  PayExpenseDialog: ({ isOpen }: { isOpen: boolean }) =>
    isOpen ? <div data-testid="pay-expense-dialog" /> : null,
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
  partner_id: 'supplier-1',
  partner: { id: 'supplier-1', name: 'Acme Supplies' },
  subtotal: '100.000',
  tax_amount: '19.000',
  total: '119.000',
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
    vat_rate: '19.00',
    vat_deductible_percent: '80.00',
  },
}

const fixtureDraftExpense = {
  ...fixtureExpense,
  status: 'draft' as const,
  document_number: 'EXP-DRAFT-0001',
}

function SupplierDestination() {
  const { supplierId } = useParams<{ supplierId: string }>()

  return <div>Supplier destination: {supplierId}</div>
}

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/expenses/exp-1']}>
      <Routes>
        <Route path="/expenses/:id" element={<ExpenseDetailPage />} />
        <Route path="/purchases/suppliers/:supplierId" element={<SupplierDestination />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  mockUseExpense.mockReset()
  mockHasPermission.mockReset()
  // Default: grant all permissions
  mockHasPermission.mockReturnValue(true)
  mockUseExpense.mockReturnValue({ data: fixtureExpense, isLoading: false })
})

describe('ExpenseDetailPage shell', () => {
  it('renders exactly one <h1> (the document number)', () => {
    renderPage()
    const headings = screen.getAllByRole('heading', { level: 1 })
    expect(headings).toHaveLength(1)
    expect(headings[0]).toHaveTextContent('EXP-0001')
  })

  it('renders the status via a StatusBadge pill', () => {
    renderPage()
    const badge = screen.getByText('expenses:status.posted')
    expect(badge.className).toContain('rounded-full')
  })

  it('renders the attachments organism', () => {
    renderPage()
    expect(screen.getByTestId('document-attachments')).toBeInTheDocument()
  })

  it('routes the supplier link to the canonical purchase supplier detail', () => {
    renderPage()

    fireEvent.click(screen.getByRole('link', { name: 'Acme Supplies' }))

    expect(screen.getByText('Supplier destination: supplier-1')).toBeInTheDocument()
  })

  it('renders receipt-order net, VAT, deductible, and total rows', () => {
    renderPage()

    expect(screen.getByText('100.000 TND')).toBeInTheDocument()
    expect(screen.getByText('19.000 TND')).toBeInTheDocument()
    expect(screen.getByText('19.00%')).toBeInTheDocument()
    expect(screen.getByText('80.00%')).toBeInTheDocument()
    expect(screen.getByText('119.000 TND')).toBeInTheDocument()
  })

  it('renders exact dashes for unavailable legacy VAT values without units', () => {
    mockUseExpense.mockReturnValue({
      data: {
        ...fixtureExpense,
        subtotal: null,
        metadata: {
          ...fixtureExpense.metadata,
          vat_rate: null,
          vat_deductible_percent: null,
        },
      },
      isLoading: false,
    })

    renderPage()

    for (const label of [
      'expenses:form.netAmount',
      'expenses:form.vatRate',
      'expenses:form.vatDeductible',
    ]) {
      const value = screen.getByText(label).parentElement?.querySelector('dd')
      expect(value).toHaveTextContent(/^\s*-\s*$/)
    }
    expect(screen.queryByText('- TND')).not.toBeInTheDocument()
    expect(screen.queryByText('-%')).not.toBeInTheDocument()
  })

  it('falls back to the editable vendor snapshot when no partner is linked', () => {
    mockUseExpense.mockReturnValue({
      data: {
        ...fixtureExpense,
        partner_id: null,
        partner: null,
        metadata: {
          ...fixtureExpense.metadata,
          vendor_name: 'Legacy receipt vendor',
        },
      },
      isLoading: false,
    })

    renderPage()

    const vendorValue = screen.getByText('expenses:vendorName').parentElement?.querySelector('dd')
    expect(vendorValue).toHaveTextContent('Legacy receipt vendor')
    expect(screen.queryByRole('link', { name: 'Legacy receipt vendor' })).not.toBeInTheDocument()
  })
})

describe('ExpenseDetailPage — Post button permission gating', () => {
  it('hides the Post button when user lacks expenses.post on a draft expense', () => {
    mockUseExpense.mockReturnValue({ data: fixtureDraftExpense, isLoading: false })
    // Deny expenses.post only
    mockHasPermission.mockImplementation((p: string) => p !== 'expenses.post')

    renderPage()

    expect(screen.queryByText('expenses:postExpense')).not.toBeInTheDocument()
  })

  it('shows the Post button when user has expenses.post on a draft expense', () => {
    mockUseExpense.mockReturnValue({ data: fixtureDraftExpense, isLoading: false })
    // Grant all permissions (default)
    mockHasPermission.mockReturnValue(true)

    renderPage()

    expect(screen.getByText('expenses:postExpense')).toBeInTheDocument()
  })

  it('never shows the Post button on an already-posted expense regardless of permission', () => {
    // fixtureExpense is posted — Post button must not render even with permission
    mockHasPermission.mockReturnValue(true)

    renderPage()

    expect(screen.queryByText('expenses:postExpense')).not.toBeInTheDocument()
  })
})

describe('ExpenseDetailPage — Edit / Delete button permission gating', () => {
  it('hides Edit button when user lacks expenses.update', () => {
    mockUseExpense.mockReturnValue({ data: fixtureDraftExpense, isLoading: false })
    mockHasPermission.mockImplementation((p: string) => p !== 'expenses.update')

    renderPage()

    expect(screen.queryByText('common:edit')).not.toBeInTheDocument()
  })

  it('shows Edit button when user has expenses.update on a draft expense', () => {
    mockUseExpense.mockReturnValue({ data: fixtureDraftExpense, isLoading: false })
    mockHasPermission.mockReturnValue(true)

    renderPage()

    expect(screen.getByText('common:edit')).toBeInTheDocument()
  })

  it('hides Delete button when user lacks expenses.delete', () => {
    mockUseExpense.mockReturnValue({ data: fixtureDraftExpense, isLoading: false })
    mockHasPermission.mockImplementation((p: string) => p !== 'expenses.delete')

    renderPage()

    expect(screen.queryByText('common:delete')).not.toBeInTheDocument()
  })

  it('shows Delete button when user has expenses.delete on a draft expense', () => {
    mockUseExpense.mockReturnValue({ data: fixtureDraftExpense, isLoading: false })
    mockHasPermission.mockReturnValue(true)

    renderPage()

    expect(screen.getByText('common:delete')).toBeInTheDocument()
  })
})

describe('ExpenseDetailPage — Pay button eligibility and permission gating', () => {
  it('shows Pay for a permitted posted, unpaid, non-linked-cost expense and opens the dialog', () => {
    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'expenses:pay.submit' }))
    expect(screen.getByTestId('pay-expense-dialog')).toBeInTheDocument()
  })

  it.each([
    ['missing permission', fixtureExpense, (p: string) => p !== 'expenses.pay'],
    ['draft status', fixtureDraftExpense, () => true],
    ['already paid', { ...fixtureExpense, metadata: { ...fixtureExpense.metadata, is_paid: true } }, () => true],
    ['linked cost', { ...fixtureExpense, metadata: { ...fixtureExpense.metadata, expense_kind: 'linked_cost' as const } }, () => true],
  ])('hides Pay for %s', (_case, candidate, permission) => {
    mockUseExpense.mockReturnValue({ data: candidate, isLoading: false })
    mockHasPermission.mockImplementation(permission)

    renderPage()
    expect(screen.queryByRole('button', { name: 'expenses:pay.submit' })).not.toBeInTheDocument()
  })
})
