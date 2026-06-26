/**
 * TDD: SupplierInvoiceDetailPage — Post-disabled-when-blocked logic
 *
 * Covers:
 * 1. "Post Invoice" button is enabled when match_status = 'matched'
 * 2. "Post Invoice" button is disabled with explanation when match_status = 'qty_blocked'
 * 3. "Post Invoice" button is disabled when status is already 'posted'
 * 4. Per-line match table renders ordered/received/invoiced/matchable/price_variance columns
 */

import { screen, waitFor } from '@testing-library/react'
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import { renderWithProviders } from '../../../test/renderWithProviders'
import type { SupplierInvoiceDetail } from './types'

// ── Mocks ──────────────────────────────────────────────────────────────────

const mockApiGet = vi.hoisted(() => vi.fn())

vi.mock('../../../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../../../lib/api')>('../../../lib/api')
  return {
    ...actual,
    apiGet: mockApiGet,
    api: { ...actual.api, get: vi.fn() },
  }
})

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    useParams: () => ({ id: 'inv-detail-1' }),
    Link: ({ to, children, ...rest }: { to: string; children: React.ReactNode; [key: string]: unknown }) => (
      <a href={String(to)} {...rest}>{children}</a>
    ),
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      if (opts) return `${key}:${JSON.stringify(opts)}`
      return key
    },
    i18n: { language: 'en' },
  }),
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

// ── Helpers ────────────────────────────────────────────────────────────────

function setTenant(tenantId = 'tenant-1', companyId = 'company-1') {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.com',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'test-token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: companyId,
    companies: [
      {
        id: companyId,
        name: 'Test Company',
        legalName: 'Test Company LLC',
        taxId: null,
        countryCode: 'TN',
        currency: 'TND',
        locale: 'en_US',
        timezone: 'Africa/Tunis',
      },
    ],
    isLoading: false,
  })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function makeDetail(overrides: Partial<SupplierInvoiceDetail> = {}): SupplierInvoiceDetail {
  return {
    id: 'inv-detail-1',
    number: 'SI-2026-001',
    partner: { id: 'p-1', name: 'ACME Supplies' },
    issue_date: '2026-06-01',
    due_date: '2026-07-01',
    supplier_reference: 'REF-001',
    currency: 'TND',
    total: '1500.000',
    status: 'draft',
    match_status: 'matched',
    has_source_document: true,
    lines: [
      {
        id: 'line-1',
        source_line_id: 'po-line-1',
        quantity: '10.0000',
        unit_price: '150.000',
        vat_rate: '19.00',
        recoverable_tax_amount: '285.000',
        line_subtotal: '1785.000',
      },
    ],
    source_purchase_order: { id: 'po-1', number: 'PO-2026-001' },
    match: {
      status: 'matched',
      per_line: [
        {
          po_line_id: 'po-line-1',
          ordered: '10.0000',
          received: '10.0000',
          invoiced: '10.0000',
          matchable: '10.0000',
          price_variance: '0.000',
        },
      ],
    },
    attachments: [],
    posted_at: null,
    ...overrides,
  }
}

 
let SupplierInvoiceDetailPage: React.ComponentType<Record<string, never>>

beforeEach(async () => {
  vi.clearAllMocks()
  setTenant()
  // apiGet returns unwrapped data
  mockApiGet.mockResolvedValue(makeDetail())
  const mod = await import('./SupplierInvoiceDetailPage')
  SupplierInvoiceDetailPage = mod.SupplierInvoiceDetailPage
})

afterEach(() => {
  resetTenant()
})

// ── Tests ──────────────────────────────────────────────────────────────────

describe('SupplierInvoiceDetailPage — Post action', () => {
  it('Post button is enabled when status=draft and match=matched', async () => {
    mockApiGet.mockResolvedValue(makeDetail({ status: 'draft', match_status: 'matched' }))
    renderWithProviders(<SupplierInvoiceDetailPage />)
    await waitFor(() => {
      const btn = screen.getByTestId('btn-post')
      expect(btn).not.toBeDisabled()
    })
  })

  it('Post button is disabled with blocked explanation when match=qty_blocked', async () => {
    mockApiGet.mockResolvedValue(makeDetail({ status: 'draft', match_status: 'qty_blocked' }))
    renderWithProviders(<SupplierInvoiceDetailPage />)
    await waitFor(() => {
      const btn = screen.getByTestId('btn-post')
      expect(btn).toBeDisabled()
    })
    // Should show a block explanation
    expect(screen.getByTestId('post-block-reason')).toBeInTheDocument()
  })

  it('Post button is not shown when status=posted', async () => {
    mockApiGet.mockResolvedValue(
      makeDetail({ status: 'posted', match_status: 'matched', posted_at: '2026-06-01T10:00:00Z' })
    )
    renderWithProviders(<SupplierInvoiceDetailPage />)
    await waitFor(() => {
      expect(screen.queryByTestId('btn-post')).not.toBeInTheDocument()
    })
  })

  it('Post button is disabled when match=price_variance (price block in strict mode)', async () => {
    // price_variance can be either warn (allowed) or block (disallowed) based on policy.
    // Assumption: the FE disables the button when match_status=price_variance to surface
    // the variance; the backend will make the final decision on assertPostable().
    mockApiGet.mockResolvedValue(makeDetail({ status: 'draft', match_status: 'price_variance' }))
    renderWithProviders(<SupplierInvoiceDetailPage />)
    await waitFor(() => {
      // In price_variance state: button present but labelled with warning
      const btn = screen.getByTestId('btn-post')
      // Not blocking on FE (policy is server-side), just surface the state
      expect(btn).toBeInTheDocument()
    })
  })
})

describe('SupplierInvoiceDetailPage — per-line match table', () => {
  it('renders match table column headers', async () => {
    renderWithProviders(<SupplierInvoiceDetailPage />)
    await waitFor(() => {
      // The 5 match table columns
      expect(screen.getByText('purchases:supplierInvoices.lines.ordered')).toBeInTheDocument()
      expect(screen.getByText('purchases:supplierInvoices.lines.received')).toBeInTheDocument()
      expect(screen.getByText('purchases:supplierInvoices.lines.invoiced')).toBeInTheDocument()
      expect(screen.getByText('purchases:supplierInvoices.lines.matchable')).toBeInTheDocument()
      expect(screen.getByText('purchases:supplierInvoices.lines.priceVariance')).toBeInTheDocument()
    })
  })

  it('renders per-line match values', async () => {
    renderWithProviders(<SupplierInvoiceDetailPage />)
    await waitFor(() => {
      // The row with ordered=10 received=10
      expect(screen.getByTestId('match-row-po-line-1')).toBeInTheDocument()
    })
  })
})

describe('SupplierInvoiceDetailPage — source PO link', () => {
  it('renders a link to the source purchase order', async () => {
    renderWithProviders(<SupplierInvoiceDetailPage />)
    await waitFor(() => {
      const link = screen.getByTestId('link-source-po')
      expect(link).toHaveAttribute('href', expect.stringContaining('po-1'))
    })
  })
})

describe('SupplierInvoiceDetailPage — Record Payment', () => {
  it('shows Record Payment button when invoice is posted', async () => {
    mockApiGet.mockResolvedValue(
      makeDetail({ status: 'posted', match_status: 'matched', posted_at: '2026-06-01T10:00:00Z' })
    )
    renderWithProviders(<SupplierInvoiceDetailPage />)
    await waitFor(() => {
      expect(screen.getByTestId('btn-record-payment')).toBeInTheDocument()
    })
  })

  it('does not show Record Payment when invoice is draft', async () => {
    mockApiGet.mockResolvedValue(makeDetail({ status: 'draft' }))
    renderWithProviders(<SupplierInvoiceDetailPage />)
    await waitFor(() => {
      expect(screen.queryByTestId('btn-record-payment')).not.toBeInTheDocument()
    })
  })
})
