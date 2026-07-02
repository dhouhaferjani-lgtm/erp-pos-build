import { beforeEach, describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
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
})
