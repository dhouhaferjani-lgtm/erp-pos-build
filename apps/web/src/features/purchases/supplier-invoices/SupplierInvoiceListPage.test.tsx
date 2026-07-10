/**
 * TDD: SupplierInvoiceListPage
 *
 * Covers:
 * 1. Match-status badge rendering for all 4 values
 * 2. Filter state changes (status, match_status, date)
 * 3. Empty state
 * 4. Tenant-scope gate (no fetch when unauthenticated)
 */

import { screen, waitFor, fireEvent } from '@testing-library/react'
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import { renderWithProviders } from '../../../test/renderWithProviders'
import type { SupplierInvoiceListItem } from './types'

// ── Mock api ───────────────────────────────────────────────────────────────

const mockApiGet = vi.hoisted(() => vi.fn())

vi.mock('../../../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../../../lib/api')>('../../../lib/api')
  return {
    ...actual,
    api: {
      ...actual.api,
      get: mockApiGet,
    },
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      if (opts) {
        return `${key}:${JSON.stringify(opts)}`
      }
      return key
    },
    i18n: { language: 'en' },
  }),
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

// ── Helpers ────────────────────────────────────────────────────────────────

function setTenant(tenantId = 'tenant-1', companyId = 'company-1', roles: string[] = []) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.com',
      tenant_id: tenantId,
      roles,
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

function makeInvoice(overrides: Partial<SupplierInvoiceListItem> = {}): SupplierInvoiceListItem {
  return {
    id: 'inv-1',
    number: 'SI-2026-001',
    partner: { id: 'p-1', name: 'ACME Supplies' },
    issue_date: '2026-06-01',
    currency: 'TND',
    total: '1500.000',
    status: 'draft',
    match_status: 'matched',
    has_source_document: true,
    ...overrides,
  }
}

function makeListResponse(items: SupplierInvoiceListItem[] = [makeInvoice()]) {
  return {
    // api.get returns axios response; response.data is the raw backend body.
    // Backend uses cursor pagination: { data, meta: { per_page, has_more }, links: { next, prev } }.
    data: {
      data: items,
      meta: { per_page: 20, has_more: false },
      links: { next: null, prev: null },
    },
  }
}

// ── Import component (lazy — after mocks are hoisted) ──────────────────────
 
let SupplierInvoiceListPage: React.ComponentType<Record<string, never>>

beforeEach(async () => {
  vi.clearAllMocks()
  setTenant()
  mockApiGet.mockResolvedValue(makeListResponse())
  const mod = await import('./SupplierInvoiceListPage')
  SupplierInvoiceListPage = mod.SupplierInvoiceListPage
})

afterEach(() => {
  resetTenant()
})

// ── Tests ──────────────────────────────────────────────────────────────────

describe('SupplierInvoiceListPage — match-status badge', () => {
  it('renders "matched" badge for matched status', async () => {
    mockApiGet.mockResolvedValue(makeListResponse([makeInvoice({ match_status: 'matched' })]))
    renderWithProviders(<SupplierInvoiceListPage />)
    await waitFor(() => {
      expect(screen.getByTestId('match-badge-inv-1')).toHaveTextContent('purchases:supplierInvoices.matchStatus.matched')
    })
  })

  it('renders "price_variance" badge for price variance status', async () => {
    mockApiGet.mockResolvedValue(
      makeListResponse([makeInvoice({ id: 'inv-2', match_status: 'price_variance' })])
    )
    renderWithProviders(<SupplierInvoiceListPage />)
    await waitFor(() => {
      expect(screen.getByTestId('match-badge-inv-2')).toHaveTextContent(
        'purchases:supplierInvoices.matchStatus.price_variance'
      )
    })
  })

  it('renders "qty_blocked" badge for qty blocked status', async () => {
    mockApiGet.mockResolvedValue(
      makeListResponse([makeInvoice({ id: 'inv-3', match_status: 'qty_blocked' })])
    )
    renderWithProviders(<SupplierInvoiceListPage />)
    await waitFor(() => {
      expect(screen.getByTestId('match-badge-inv-3')).toHaveTextContent(
        'purchases:supplierInvoices.matchStatus.qty_blocked'
      )
    })
  })

  it('renders "unmatched" badge for unmatched status', async () => {
    mockApiGet.mockResolvedValue(
      makeListResponse([makeInvoice({ id: 'inv-4', match_status: 'unmatched' })])
    )
    renderWithProviders(<SupplierInvoiceListPage />)
    await waitFor(() => {
      expect(screen.getByTestId('match-badge-inv-4')).toHaveTextContent(
        'purchases:supplierInvoices.matchStatus.unmatched'
      )
    })
  })
})

describe('SupplierInvoiceListPage — filtering', () => {
  it('passes status filter to API', async () => {
    renderWithProviders(<SupplierInvoiceListPage />)

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledWith(
        '/supplier-invoices',
        expect.objectContaining({ params: expect.not.objectContaining({ status: expect.anything() }) })
      )
    })

    // Select status = 'posted'
    const statusSelect = screen.getByTestId('filter-status')
    fireEvent.change(statusSelect, { target: { value: 'posted' } })

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledWith(
        '/supplier-invoices',
        expect.objectContaining({ params: expect.objectContaining({ status: 'posted' }) })
      )
    })
  })

  it('passes match_status filter to API', async () => {
    renderWithProviders(<SupplierInvoiceListPage />)

    const matchSelect = screen.getByTestId('filter-match-status')
    fireEvent.change(matchSelect, { target: { value: 'qty_blocked' } })

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledWith(
        '/supplier-invoices',
        expect.objectContaining({ params: expect.objectContaining({ match_status: 'qty_blocked' }) })
      )
    })
  })

  it('passes date_from filter to API', async () => {
    renderWithProviders(<SupplierInvoiceListPage />)

    const dateFromInput = screen.getByTestId('filter-date-from')
    fireEvent.change(dateFromInput, { target: { value: '2026-06-01' } })

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledWith(
        '/supplier-invoices',
        expect.objectContaining({ params: expect.objectContaining({ date_from: '2026-06-01' }) })
      )
    })
  })

  it('passes pending receipt filter to API', async () => {
    renderWithProviders(<SupplierInvoiceListPage />)

    const pendingSelect = screen.getByTestId('filter-pending-receipt')
    fireEvent.change(pendingSelect, { target: { value: '1' } })

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledWith(
        '/supplier-invoices',
        expect.objectContaining({ params: expect.objectContaining({ pending_receipt: '1' }) })
      )
    })
  })
})

describe('SupplierInvoiceListPage — pending receipts', () => {
  it('renders a pending receipt badge on invoice-first drafts', async () => {
    mockApiGet.mockResolvedValue(makeListResponse([makeInvoice({ pending_receipt: true })]))
    renderWithProviders(<SupplierInvoiceListPage />)

    await waitFor(() => {
      expect(screen.getByTestId('pending-receipt-badge-inv-1')).toHaveTextContent(
        'purchases:supplierInvoices.pendingReceipt.badge'
      )
    })
  })
})

describe('SupplierInvoiceListPage — empty state', () => {
  it('shows empty message when no invoices', async () => {
    mockApiGet.mockResolvedValue(makeListResponse([]))
    renderWithProviders(<SupplierInvoiceListPage />)
    await waitFor(() => {
      expect(screen.getByText('purchases:supplierInvoices.empty')).toBeInTheDocument()
    })
  })
})

describe('SupplierInvoiceListPage — tenant scope', () => {
  it('does not fetch when unauthenticated', () => {
    resetTenant()
    renderWithProviders(<SupplierInvoiceListPage />)
    expect(mockApiGet).not.toHaveBeenCalled()
  })
})

describe('SupplierInvoiceListPage — scan entry point', () => {
  it('renders a scan-invoice link with the locked kind when the user has document-ingestions.view', async () => {
    setTenant('tenant-1', 'company-1', ['purchases'])
    renderWithProviders(<SupplierInvoiceListPage />)

    await waitFor(() => {
      expect(screen.getByRole('link', { name: 'documentIngestions:actions.scanInvoice' })).toHaveAttribute(
        'href',
        '/purchases/scans/new?kind=supplier_invoice',
      )
    })
  })

  it('hides the scan-invoice link when the user lacks document-ingestions.view', async () => {
    setTenant('tenant-1', 'company-1', [])
    renderWithProviders(<SupplierInvoiceListPage />)

    await waitFor(() => {
      expect(screen.queryByRole('link', { name: 'documentIngestions:actions.scanInvoice' })).not.toBeInTheDocument()
    })
  })
})
