import { beforeEach, describe, it, expect, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import { DocumentListPage } from './DocumentListPage'
import type { Document } from '../../types/document'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    // Mirror i18next: a string 2nd arg is a default value; an object 2nd arg is
    // interpolation options (OffsetPagination passes `{ from, to, total }`).
    t: (key: string, second?: unknown) =>
      typeof second === 'string' ? second : key,
  }),
}))

const mockNavigate = vi.fn()
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  useLocation: () => ({ pathname: '/sales/quotes', search: '' }),
  Link: ({ to, children, ...props }: { to: string; children: React.ReactNode; className?: string }) => (
    <a href={to} {...props}>{children}</a>
  ),
}))

vi.mock('../../hooks/usePageTitle', () => ({ usePageTitle: () => {} }))

function makeDoc(overrides: Partial<Document>): Document {
  return {
    id: 'id',
    type: 'quote',
    status: 'draft',
    document_number: 'QUO-0000',
    document_date: '2026-06-14',
    due_date: null,
    valid_until: null,
    currency: 'TND',
    subtotal: '0',
    tax_amount: '0',
    total: '0',
    notes: null,
    internal_notes: null,
    partner_id: null,
    partner_name: null,
    partner_email: null,
    source_document_id: null,
    source_document_number: null,
    source_document_type: null,
    converted_to_order_id: null,
    fully_delivered: null,
    fully_invoiced: null,
    goods_received: null,
    payment_status: null,
    amount_paid: null,
    balance_due: null,
    outstanding_amount: null,
    external_document_number: null,
    external_document_date: null,
    vehicle_context: null,
    created_at: '2026-06-14T00:00:00Z',
    updated_at: '2026-06-14T00:00:00Z',
    ...overrides,
  }
}

interface DocumentsResponse {
  data: Document[]
  meta?: {
    total?: number
    current_page?: number
    last_page?: number
    per_page?: number
    from?: number | null
    to?: number | null
  }
}

const defaultDocumentsResponse: DocumentsResponse = {
  data: [
    makeDoc({ id: '1', document_number: 'QUO-1001', status: 'draft', partner_id: 'p1', partner_name: 'Alice Co', total: '120.500' }),
    makeDoc({ id: '2', document_number: 'QUO-1002', status: 'posted', partner_id: 'p2', partner_name: 'Bob Ltd', total: '90.000' }),
  ],
  meta: { total: 30, current_page: 1, last_page: 2, per_page: 25, from: 1, to: 25 },
}

const mockUseQueryReturn: {
  data: DocumentsResponse | undefined
  isLoading: boolean
  error: unknown
} = {
  data: defaultDocumentsResponse,
  isLoading: false,
  error: null,
}

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return { ...actual, useQuery: () => mockUseQueryReturn }
})

describe('DocumentListPage (canonical list)', () => {
  beforeEach(() => {
    mockUseQueryReturn.data = defaultDocumentsResponse
    mockUseQueryReturn.isLoading = false
    mockUseQueryReturn.error = null
  })

  it('renders a row per document', () => {
    render(<DocumentListPage />)
    expect(screen.getByText('QUO-1001')).toBeInTheDocument()
    expect(screen.getByText('QUO-1002')).toBeInTheDocument()
    expect(screen.getByText('Alice Co')).toBeInTheDocument()
    expect(screen.getByText('Bob Ltd')).toBeInTheDocument()
  })

  it('shows a draft placeholder when the document number is not assigned yet', () => {
    mockUseQueryReturn.data = {
      data: [
        makeDoc({
          id: 'draft-expense',
          type: 'expense',
          status: 'draft',
          document_number: null,
        } as Partial<Document>),
      ],
      meta: { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1 },
    }

    render(<DocumentListPage />)

    expect(screen.getByRole('link', { name: 'sales:documents.draftNumberPlaceholder' })).toHaveAttribute(
      'href',
      '/sales/quotes/draft-expense',
    )
  })

  it('renders the document type as a status badge label', () => {
    render(<DocumentListPage />)
    // `getTypeLabel('quote')` resolves to the 'quote' fallback; it appears once
    // per row in the type badge (and nowhere in the filter tabs / title).
    expect(screen.getAllByText('quote').length).toBeGreaterThanOrEqual(2)
  })

  it('renders pagination controls', () => {
    render(<DocumentListPage />)
    // OffsetPagination renders a per-page <select> (combobox). The original
    // list had no pagination at all — this is the red→green driver.
    expect(screen.getByRole('combobox')).toBeInTheDocument()
  })

  it('exposes the add action as a button', () => {
    render(<DocumentListPage />)
    expect(screen.getByRole('button', { name: /actions\.add/ })).toBeInTheDocument()
  })

  // Regression: the backend collapses a fully-settled invoice's status to the
  // `paid` enum value, which has no `common:status.paid` key. The generic
  // `t('status.paid', 'paid')` fallback leaked the raw lowercase string into the
  // status badge instead of a localized "Paid". The badge must render the
  // localized label (`sales:invoices.paymentStatus.paid`), never the raw value.
  it('localizes the status badge for `paid`-status invoices', () => {
    mockUseQueryReturn.data = {
      data: [
        makeDoc({
          id: 'paid-1',
          type: 'invoice',
          status: 'paid',
          document_number: 'INV-2001',
          partner_id: 'p9',
          partner_name: 'Settled Co',
          total: '46.516',
          balance_due: '0.000',
          payment_status: 'paid',
        }),
      ],
      meta: { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1 },
    }

    render(<DocumentListPage documentType="invoice" />)

    const row = screen.getByRole('link', { name: 'INV-2001' }).closest('tr')
    expect(row).not.toBeNull()
    const statusCell = within(row as HTMLElement)
    // Localized label present in the row's status badge…
    expect(statusCell.getByText('sales:invoices.paymentStatus.paid')).toBeInTheDocument()
    // …and the raw lowercase enum value must NOT leak anywhere in the row.
    expect(statusCell.queryByText('paid')).toBeNull()
  })

  // Regression: the list must render the document date from the list payload's
  // `document_date` field (the same field the detail page uses). A prior report
  // suspected an empty date column; this locks the cell to a formatted value.
  it('renders the document date from the list payload', () => {
    mockUseQueryReturn.data = {
      data: [
        makeDoc({
          id: 'dated-1',
          type: 'invoice',
          status: 'posted',
          document_number: 'INV-3001',
          document_date: '2026-06-09',
          total: '10.000',
        }),
      ],
      meta: { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1 },
    }

    render(<DocumentListPage documentType="invoice" />)

    const row = screen.getByRole('link', { name: 'INV-3001' }).closest('tr') as HTMLElement
    // Columns: number, type, status, partner, date, total, balance, actions.
    const dateCell = row.querySelectorAll('td')[4]
    const dateText = dateCell.textContent?.trim() ?? ''
    // The cell must not be empty and must reflect the payload's year (formatted
    // via `formatDate`, e.g. `06/09/2026` in the en test locale).
    expect(dateText.length).toBeGreaterThan(0)
    expect(dateText).toContain('2026')
  })
})
