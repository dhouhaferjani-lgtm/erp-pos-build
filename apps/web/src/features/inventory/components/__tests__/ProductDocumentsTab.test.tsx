/* eslint-disable @typescript-eslint/unbound-method */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { ProductDocumentsTab } from '../ProductDocumentsTab'
import {
  makeProductDocument,
  makeProductDocumentLine,
} from '../../__fixtures__/productDocuments'

// Mock the api module
vi.mock('../../../../lib/api', () => ({
  api: {
    get: vi.fn(),
  },
}))

vi.mock('../../../../stores/authStore', () => {
  const authState = { user: { tenant_id: 'tenant-A' } }
  const useAuthStore = Object.assign(
    (selector: (state: typeof authState) => unknown) => selector(authState),
    { getState: () => authState },
  )
  return { useAuthStore }
})

// Mock the company store with selector support
vi.mock('../../../../stores/companyStore', () => {
  const company = { id: 'company-1', currency: 'EUR', locale: 'en_US' }
  const companyState = {
    currentCompanyId: 'company-1',
    companies: [company],
    getCurrentCompany: () => company,
  }
  const useCompanyStore = Object.assign(
    (selector: (state: typeof companyState) => unknown) => selector(companyState),
    { getState: () => companyState },
  )
  return { useCompanyStore }
})

// Mock formatCurrency
vi.mock('../../../../lib/format', () => ({
  formatCurrency: (amount: number, options: { currency: string }) =>
    `${options.currency} ${amount.toFixed(2)}`,
}))

import { api } from '../../../../lib/api'

const mockDocuments = [
  makeProductDocument({
    id: 'doc-1',
    type: 'invoice',
    status: 'posted',
    document_number: 'INV-2024-001',
    document_date: '2024-01-15',
    partner_id: 'partner-1',
    partner_name: 'Acme Corp',
    total: '1500.00',
    currency: 'EUR',
    lines: [
      makeProductDocumentLine({
        id: 'line-1',
        product_id: 'prod-1',
        description: 'Test Product',
        quantity: '5',
        unit_price: '100.00',
        line_total: '500.00',
      }),
      makeProductDocumentLine({
        id: 'line-2',
        product_id: 'prod-2',
        description: 'Other Product',
        quantity: '10',
        unit_price: '100.00',
        line_total: '1000.00',
      }),
    ],
  }),
  makeProductDocument({
    id: 'doc-2',
    type: 'quote',
    status: 'draft',
    document_number: 'QT-2024-001',
    document_date: '2024-01-10',
    partner_id: 'partner-2',
    partner_name: 'Tech Solutions',
    total: '2000.00',
    currency: 'EUR',
    lines: [
      makeProductDocumentLine({
        id: 'line-3',
        product_id: 'prod-1',
        description: 'Test Product',
        quantity: '10',
        unit_price: '150.00',
        line_total: '1500.00',
      }),
    ],
  }),
  makeProductDocument({
    id: 'doc-3',
    type: 'purchase_order',
    status: 'confirmed',
    document_number: 'PO-2024-001',
    document_date: '2024-01-12',
    partner_id: 'partner-3',
    partner_name: 'Supplier Inc',
    total: '800.00',
    currency: 'EUR',
    lines: [
      makeProductDocumentLine({
        id: 'line-4',
        product_id: 'prod-1',
        description: 'Test Product',
        quantity: '8',
        unit_price: '100.00',
        line_total: '800.00',
      }),
    ],
  }),
]

describe('ProductDocumentsTab', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders loading state while fetching', () => {
    vi.mocked(api.get).mockReturnValue(new Promise(() => {}) as never) // Never resolves

    renderWithProviders(<ProductDocumentsTab productId="prod-1" />)

    expect(screen.getByText(/loading/i)).toBeInTheDocument()
  })

  it('renders empty state when no documents', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: [] } })

    renderWithProviders(<ProductDocumentsTab productId="prod-1" />)

    await waitFor(() => {
      expect(screen.getByText(/no documents/i)).toBeInTheDocument()
    })
  })

  it('renders document list with correct data', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: mockDocuments } })

    renderWithProviders(<ProductDocumentsTab productId="prod-1" />)

    await waitFor(() => {
      // Check document numbers are displayed
      expect(screen.getByText('INV-2024-001')).toBeInTheDocument()
      expect(screen.getByText('QT-2024-001')).toBeInTheDocument()
      expect(screen.getByText('PO-2024-001')).toBeInTheDocument()
    })

    // Check partner names are displayed
    expect(screen.getByText('Acme Corp')).toBeInTheDocument()
    expect(screen.getByText('Tech Solutions')).toBeInTheDocument()
    expect(screen.getByText('Supplier Inc')).toBeInTheDocument()
  })

  it('renders document type badges correctly', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: mockDocuments } })

    renderWithProviders(<ProductDocumentsTab productId="prod-1" />)

    await waitFor(() => {
      expect(screen.getByText('Invoice')).toBeInTheDocument()
      expect(screen.getByText('Quote')).toBeInTheDocument()
      expect(screen.getByText('Purchase Order')).toBeInTheDocument()
    })
  })

  it('renders status badges correctly', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: mockDocuments } })

    renderWithProviders(<ProductDocumentsTab productId="prod-1" />)

    await waitFor(() => {
      expect(screen.getByText('Posted')).toBeInTheDocument()
      expect(screen.getByText('Draft')).toBeInTheDocument()
      expect(screen.getByText('Confirmed')).toBeInTheDocument()
    })
  })

  it('renders document number as link to detail page', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: mockDocuments } })

    renderWithProviders(<ProductDocumentsTab productId="prod-1" />)

    await waitFor(() => {
      const invoiceLink = screen.getByRole('link', { name: 'INV-2024-001' })
      expect(invoiceLink).toHaveAttribute('href', '/sales/invoices/doc-1')

      const quoteLink = screen.getByRole('link', { name: 'QT-2024-001' })
      expect(quoteLink).toHaveAttribute('href', '/sales/quotes/doc-2')

      const poLink = screen.getByRole('link', { name: 'PO-2024-001' })
      expect(poLink).toHaveAttribute('href', '/purchases/orders/doc-3')
    })
  })

  it('renders a draft placeholder link when document_number is null', async () => {
    vi.mocked(api.get).mockResolvedValue({
      data: {
        data: [
          makeProductDocument({
            id: 'draft-expense',
            type: 'expense',
            status: 'draft',
            document_number: null,
            document_date: '2024-01-12',
            partner_id: null,
            partner_name: undefined,
            total: '0.00',
            currency: 'EUR',
            lines: [
              makeProductDocumentLine({
                id: 'line-draft',
                product_id: 'prod-1',
                quantity: '1',
                line_total: '0.00',
              }),
            ],
          }),
        ],
      },
    })

    renderWithProviders(<ProductDocumentsTab productId="prod-1" />)

    await waitFor(() => {
      expect(
        screen.getByRole('link', { name: 'Draft (not numbered)' })
      ).toHaveAttribute('href', '/documents/draft-expense')
    })
  })

  it('renders partner name as type-aware link to partner detail page', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: mockDocuments } })

    renderWithProviders(<ProductDocumentsTab productId="prod-1" />)

    await waitFor(() => {
      // Sales document partner → customer detail
      const customerLink = screen.getByRole('link', { name: 'Acme Corp' })
      expect(customerLink).toHaveAttribute('href', '/sales/customers/partner-1')

      const quotePartnerLink = screen.getByRole('link', { name: 'Tech Solutions' })
      expect(quotePartnerLink).toHaveAttribute('href', '/sales/customers/partner-2')

      // Purchase order partner → supplier detail
      const supplierLink = screen.getByRole('link', { name: 'Supplier Inc' })
      expect(supplierLink).toHaveAttribute('href', '/purchases/suppliers/partner-3')
    })
  })

  it('calculates product-specific quantity correctly', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: mockDocuments } })

    renderWithProviders(<ProductDocumentsTab productId="prod-1" />)

    await waitFor(() => {
      // Check that quantities are displayed
      expect(document.body.textContent).toContain('5.00')
      expect(document.body.textContent).toContain('10.00')
      expect(document.body.textContent).toContain('8.00')
    })
  })

  it('calculates product-specific line total correctly', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: mockDocuments } })

    renderWithProviders(<ProductDocumentsTab productId="prod-1" />)

    await waitFor(() => {
      // Check that line totals are displayed
      expect(document.body.textContent).toContain('EUR 500.00')
      expect(document.body.textContent).toContain('EUR 1500.00')
      expect(document.body.textContent).toContain('EUR 800.00')
    })
  })

  it('renders error state on API failure', async () => {
    vi.mocked(api.get).mockRejectedValue(new Error('API Error'))

    renderWithProviders(<ProductDocumentsTab productId="prod-1" />)

    await waitFor(() => {
      // Match the actual error text from translation
      expect(screen.getByText(/failed to load data/i)).toBeInTheDocument()
    })
  })

  it('displays correct document count in subtitle', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: mockDocuments } })

    renderWithProviders(<ProductDocumentsTab productId="prod-1" />)

    await waitFor(() => {
      // Should show count of 3 documents in subtitle
      expect(
        screen.getByText((content) => content.includes('3') && content.toLowerCase().includes('document'))
      ).toBeInTheDocument()
    })
  })

  it('passes correct product_id to API', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: [] } })

    renderWithProviders(<ProductDocumentsTab productId="test-product-456" />)

    await waitFor(() => {
      expect(api.get).toHaveBeenCalledWith(
        expect.stringContaining('product_id=test-product-456')
      )
    })
  })

  it('requests the selected server page when pagination is used', async () => {
    vi.mocked(api.get)
      .mockResolvedValueOnce({
        data: {
          data: mockDocuments.slice(0, 1),
          meta: { current_page: 1, last_page: 2, per_page: 1, total: 2, from: 1, to: 1 },
        },
      })
      .mockResolvedValueOnce({
        data: {
          data: mockDocuments.slice(1, 2),
          meta: { current_page: 2, last_page: 2, per_page: 1, total: 2, from: 2, to: 2 },
        },
      })
    const user = userEvent.setup()

    renderWithProviders(<ProductDocumentsTab productId="prod-1" />)

    await waitFor(() => {
      expect(screen.getByText('INV-2024-001')).toBeInTheDocument()
    })

    await user.click(screen.getByRole('button', { name: /next/i }))

    await waitFor(() => {
      expect(api.get).toHaveBeenLastCalledWith(expect.stringContaining('page=2'))
    })
  })

  it('shows dash for partner when not available', async () => {
    const docsWithoutPartner = [
      makeProductDocument({
        ...mockDocuments[0],
        partner_id: null,
        partner_name: undefined,
      }),
    ]
    vi.mocked(api.get).mockResolvedValue({ data: { data: docsWithoutPartner } })

    renderWithProviders(<ProductDocumentsTab productId="prod-1" />)

    await waitFor(() => {
      // Multiple `-` placeholders appear in the row (partner, landed cost,
      // etc. depending on which fields are missing). Assert at least one.
      expect(screen.getAllByText('-').length).toBeGreaterThanOrEqual(1)
    })
  })
})
