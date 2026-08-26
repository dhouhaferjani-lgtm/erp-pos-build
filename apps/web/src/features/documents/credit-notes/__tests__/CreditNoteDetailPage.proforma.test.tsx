/**
 * C-F0w gate r1 / W-2 — the LIVE credit-note detail page, scanned whole.
 *
 * The sibling of `invoices/__tests__/InvoiceDetailPage.proforma.test.tsx`, and it
 * exists for the same reason: the round-1 coverage was leaf components plus an
 * ORPHAN (`components/CreditNoteDetail.tsx`, rendered by no route), so the surface a
 * user actually opens was unpinned.
 *
 * Nothing that draws a chip, a total or a line is mocked, and the copy is the real
 * `en` bundle — an identity `t` would render `sales:documents.statuses.posted` and
 * the scan would never see the word it is hunting.
 *
 * Fixture shape: `status: 'posted'` on a credit note the fiscal chain never sealed.
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

import { CreditNoteDetailPage } from '../CreditNoteDetailPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiGetHelper = vi.hoisted(() => vi.fn())

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
  return { ...actual, useParams: () => ({ id: 'cn-1' }) }
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

// Mocked ONLY because they fetch. The chips, the banner, the items table and
// DocumentTotals are REAL — they are what is under test.
vi.mock('@/features/documents/components/RelatedDocumentsTab', () => ({
  RelatedDocumentsTab: () => null,
}))
vi.mock('@/features/documents/components/DocumentActionBar', () => ({
  DocumentActionBar: () => null,
}))
vi.mock('@/features/documents/hooks/useDocumentEmail', () => ({
  useSendDocumentEmail: () => ({ isPending: false, mutateAsync: vi.fn() }),
}))
vi.mock('@/features/documents/hooks/useDocumentPdf', () => ({
  useDownloadPdf: () => ({ isPending: false, mutate: vi.fn() }),
  usePreviewPdf: () => ({ isPending: false, mutate: vi.fn() }),
  usePrintPdf: () => ({ isPending: false, mutate: vi.fn() }),
}))
vi.mock('@/components/molecules/EntityLink', () => ({
  EntityLink: ({ label }: { label: ReactNode }) => <span>{label}</span>,
}))

function creditNoteFixture(overrides: Record<string, unknown> = {}) {
  return {
    id: 'cn-1',
    type: 'credit_note',
    status: 'posted',
    document_number: 'CN-2026-0004',
    document_date: '2026-01-15',
    partner_id: 'partner-1',
    partner_name: 'Partner A',
    partner_email: null,
    currency: 'TND',
    subtotal: '200.000',
    tax_amount: '38.000',
    total: '238.000',
    notes: null,
    is_proforma: true,
    proforma: {
      estimated_total: '238.000',
      gross_lines: '238.000',
      stamp_duty: null,
      discount: null,
      adjustment: null,
      lines: [{ line_id: 'line-1', unit_price: '119.000', line_total: '238.000' }],
    },
    lines: [
      {
        id: 'line-1',
        description: 'Returned widget',
        quantity: '2',
        quantity_decimals: 3,
        unit_price: '100.000',
        line_total: '200.000',
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

function mockResponses(creditNote: Record<string, unknown>) {
  mockApiGet.mockImplementation((url: string) =>
    Promise.resolve(url === '/documents/cn-1' ? { data: { data: creditNote } } : { data: { data: [] } })
  )
  mockApiGetHelper.mockImplementation((url: string) =>
    Promise.resolve(url.includes('tax-breakdown') ? TAX_BREAKDOWN : [])
  )
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false } },
  })

  return render(<CreditNoteDetailPage />, {
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

describe('CreditNoteDetailPage — proforma', () => {
  it('scans clean over the WHOLE page', async () => {
    mockResponses(creditNoteFixture())

    const { container } = renderPage()
    await screen.findByText('Proforma — non-fiscal document')

    expectNoForbiddenToken(container.innerHTML, 'the LIVE credit-note page')
  })

  it('wears no lifecycle chip and no sealed chip', async () => {
    mockResponses(creditNoteFixture())

    renderPage()
    await screen.findByText('Proforma — non-fiscal document')

    expect(screen.queryByText('Posted')).not.toBeInTheDocument()
    expect(screen.queryByText('Sealed')).not.toBeInTheDocument()
  })

  it('prints GROSS line figures and never the net basis', async () => {
    mockResponses(creditNoteFixture())

    renderPage()
    await screen.findByText('Proforma — non-fiscal document')

    expect(screen.getByText(/119[.,]000/)).toBeInTheDocument()
    expect(screen.queryByText(/100[.,]000/)).not.toBeInTheDocument()
    expect(screen.queryByText(/\b200[.,]000/)).not.toBeInTheDocument()
  })

  it('heads the totals box with the estimated total', async () => {
    mockResponses(creditNoteFixture())

    renderPage()

    expect(await screen.findByText('Estimated total')).toBeInTheDocument()
    expect(screen.queryByText('Subtotal')).not.toBeInTheDocument()
  })

  it('still hides everything when the server ships NO proforma projection', async () => {
    mockResponses(creditNoteFixture({ proforma: null }))

    const { container } = renderPage()
    await screen.findByText('Proforma — non-fiscal document')

    expectNoForbiddenToken(container.innerHTML, 'a proforma credit-note page with no projection')
    expect(screen.queryByText(/100[.,]000/)).not.toBeInTheDocument()
  })

  it('leaves a DEFINITIVE posted credit note exactly as it was', async () => {
    mockResponses(creditNoteFixture({ is_proforma: false, proforma: null }))

    renderPage()
    await screen.findByText('CN-2026-0004')

    expect(screen.getByText('Posted')).toBeInTheDocument()
    expect(screen.getByText('Sealed')).toBeInTheDocument()
    expect(await screen.findByText('Subtotal')).toBeInTheDocument()
    expect(screen.getByText(/TVA 19/)).toBeInTheDocument()
    expect(screen.queryByText('Proforma — non-fiscal document')).not.toBeInTheDocument()
    expect(screen.getAllByText(/100[.,]000/).length).toBeGreaterThan(0)
  })
})
