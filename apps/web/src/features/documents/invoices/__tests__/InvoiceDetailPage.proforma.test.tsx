/**
 * C-F0w gate r1 / W-2 — the LIVE invoice detail page, scanned whole.
 *
 * WHY THIS FILE EXISTS AND WHAT IT REFUSES TO MOCK. The lane's first round pinned
 * the proforma invariant on leaf components only, and the gate found a `Posted`
 * status chip and an `Unpaid` payment chip sitting beside the proforma banner —
 * exactly what the lane's own scanner rejects. It survived because
 * `InvoiceDetailPage.tenantScope.test.tsx:50-65` mocks `DocumentHeader` away, and
 * the chip lives inside it.
 *
 * So: the REAL `DocumentHeader`, the REAL `DocumentTotals`, the REAL `StatusBadge`,
 * and REAL `en` copy (`translateFrom`) — an identity `t` would render
 * `sales:documents.statuses.posted` and hide the very word the scan is looking for.
 * Only the things that would hit the network or the router are mocked.
 *
 * The fixture is deliberately the WORST shape: `status: 'posted'` and
 * `payment_status: 'unpaid'` on a document the fiscal chain never sealed. A status
 * heuristic calls it definitive; `is_proforma` is the only thing that knows better.
 */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { CompanyConfigProvider } from '@/contexts/CompanyConfigContext'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { expectNoForbiddenToken, translateFrom } from '@/test/proformaTokens'
import enSales from '@/locales/en/sales.json'
import enCommon from '@/locales/en/common.json'

import { InvoiceDetailPage } from '../InvoiceDetailPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiGetHelper = vi.hoisted(() => vi.fn())
const mockNavigate = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet },
    apiGet: mockApiGetHelper,
    apiPost: vi.fn(),
    getErrorMessage: () => 'request failed',
  }
})

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    useNavigate: () => mockNavigate,
    useParams: () => ({ id: 'invoice-1' }),
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: translateFrom(
      {
        sales: enSales as Record<string, unknown>,
        common: enCommon as Record<string, unknown>,
      },
      'sales'
    ),
  }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

vi.mock('@/hooks/useCompany', () => ({
  useCompany: () => ({ currentCompany: { id: 'company-1', currency: 'TND' } }),
}))

// Mocked ONLY because they fetch. DocumentHeader, DocumentTotals, StatusBadge and
// ProformaBanner are deliberately REAL — they are what is under test.
vi.mock('../../components/RelatedDocumentsTab', () => ({ RelatedDocumentsTab: () => null }))
vi.mock('../../components/DocumentAttachments', () => ({ DocumentAttachments: () => null }))
vi.mock('../../components/CreditNoteList', () => ({ CreditNoteList: () => null }))
vi.mock('../../components/PaymentHistorySection', () => ({ PaymentHistorySection: () => null }))
vi.mock('../components/CloseWithWriteoffSection', () => ({ CloseWithWriteoffSection: () => null }))
vi.mock('../../components/DocumentActionBar', () => ({ DocumentActionBar: () => null }))
vi.mock('@/components/molecules/EntityLink', () => ({
  EntityLink: ({ label }: { label: ReactNode }) => <span>{label}</span>,
}))

const NET_LINE = { unit_price: '100.000', line_total: '200.000' }
const GROSS_LINE = { unit_price: '119.000', line_total: '238.000' }

function invoiceFixture(overrides: Record<string, unknown> = {}) {
  return {
    id: 'invoice-1',
    type: 'invoice',
    status: 'posted',
    document_number: 'INV-2026-0007',
    document_date: '2026-01-15',
    due_date: '2026-02-15',
    partner_id: 'partner-1',
    partner_name: 'Partner A',
    currency: 'TND',
    subtotal: '200.000',
    tax_amount: '38.000',
    total: '238.000',
    amount_paid: '0.000',
    outstanding_amount: '238.000',
    balance_due: '238.000',
    payment_status: 'unpaid',
    notes: null,
    is_proforma: true,
    proforma: {
      estimated_total: '238.000',
      gross_lines: '238.000',
      stamp_duty: null,
      discount: null,
      adjustment: null,
      lines: [{ line_id: 'line-1', ...GROSS_LINE }],
    },
    lines: [
      {
        id: 'line-1',
        description: 'Widget',
        quantity: '2',
        quantity_decimals: 3,
        ...NET_LINE,
        notes: null,
      },
    ],
    ...overrides,
  }
}

const TAX_BREAKDOWN = {
  subtotal: '200.000',
  discount: '0.000',
  line_tax_amount: '38.000',
  stamp_duty_amount: '0.000',
  total_tax_amount: '38.000',
  total: '238.000',
  tax_details: [
    {
      tax_type: 'percentage',
      tax_name: 'TVA',
      tax_rate: '19.00',
      tax_base: '200.000',
      tax_amount: '38.000',
    },
  ],
}

function mockResponses(invoice: Record<string, unknown>) {
  mockApiGet.mockImplementation((url: string) =>
    Promise.resolve(url === '/invoices/invoice-1' ? { data: { data: invoice } } : { data: { data: [] } })
  )
  mockApiGetHelper.mockImplementation((url: string) =>
    Promise.resolve(url.includes('tax-breakdown') ? TAX_BREAKDOWN : [])
  )
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false } },
  })

  return render(<InvoiceDetailPage />, {
    // The REAL CompanyConfigProvider rather than another mock: it renders its
    // children unconditionally, so nothing on the page is hidden from the scan.
    wrapper: ({ children }: { children: ReactNode }) => (
      <MemoryRouter>
        <QueryClientProvider client={queryClient}>
          <CompanyConfigProvider>{children}</CompanyConfigProvider>
        </QueryClientProvider>
      </MemoryRouter>
    ),
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'User',
      email: 'user@example.test',
      tenant_id: 'tenant-A',
      roles: [],
      email_verified_at: null,
    },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: 'company-1', companies: [], isLoading: false })
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

describe('InvoiceDetailPage — proforma', () => {
  it('scans clean over the WHOLE page, real header included', async () => {
    mockResponses(invoiceFixture())

    const { container } = renderPage()
    await screen.findByText('Proforma — non-fiscal document')

    expectNoForbiddenToken(container.innerHTML, 'the LIVE invoice page WITH its real header')
  })

  it('wears no lifecycle chip and no payment chip', async () => {
    mockResponses(invoiceFixture())

    renderPage()
    await screen.findByText('Proforma — non-fiscal document')

    // `status: 'posted'` + `payment_status: 'unpaid'` — the chips a status
    // heuristic would render, on a document the chain never sealed.
    expect(screen.queryByText('Posted')).not.toBeInTheDocument()
    expect(screen.queryByText('Unpaid')).not.toBeInTheDocument()
    expect(screen.queryByText('Fiscally Sealed')).not.toBeInTheDocument()
  })

  it('prints GROSS line figures and never the net basis', async () => {
    mockResponses(invoiceFixture())

    renderPage()
    await screen.findByText('Proforma — non-fiscal document')

    expect(screen.getByText(/119[.,]000/)).toBeInTheDocument()
    expect(screen.getAllByText(/238[.,]000/).length).toBeGreaterThan(0)
    // 100.000 / 200.000 are the net unit price and line total: printing either one
    // under a 238.000 estimated total is the VAT written as a subtraction.
    expect(screen.queryByText(/100[.,]000/)).not.toBeInTheDocument()
    expect(screen.queryByText(/\b200[.,]000/)).not.toBeInTheDocument()
  })

  it('heads the totals box with the estimated total', async () => {
    mockResponses(invoiceFixture())

    renderPage()

    expect(await screen.findByText('Estimated total')).toBeInTheDocument()
    expect(screen.queryByText('Subtotal')).not.toBeInTheDocument()
  })

  it('still hides everything when the server ships NO proforma projection', async () => {
    mockResponses(invoiceFixture({ proforma: null }))

    const { container } = renderPage()
    await screen.findByText('Proforma — non-fiscal document')

    expectNoForbiddenToken(container.innerHTML, 'a proforma invoice page with no projection')
    expect(screen.queryByText('Subtotal')).not.toBeInTheDocument()
    expect(screen.queryByText(/100[.,]000/)).not.toBeInTheDocument()
  })

  it('leaves a DEFINITIVE posted invoice exactly as it was', async () => {
    mockResponses(invoiceFixture({ is_proforma: false, proforma: null }))

    renderPage()
    await screen.findByText('INV-2026-0007')

    // Every chip and the whole VAT breakdown come back.
    expect(screen.getByText('Posted')).toBeInTheDocument()
    expect(screen.getByText('Unpaid')).toBeInTheDocument()
    expect(screen.getByText('Fiscally Sealed')).toBeInTheDocument()
    expect(await screen.findByText('Subtotal')).toBeInTheDocument()
    expect(screen.getByText(/TVA 19/)).toBeInTheDocument()
    expect(screen.queryByText('Proforma — non-fiscal document')).not.toBeInTheDocument()
    expect(screen.queryByText('Estimated total')).not.toBeInTheDocument()
    // The NET line figures are the ones a definitive invoice prints.
    expect(screen.getAllByText(/100[.,]000/).length).toBeGreaterThan(0)
  })
})
