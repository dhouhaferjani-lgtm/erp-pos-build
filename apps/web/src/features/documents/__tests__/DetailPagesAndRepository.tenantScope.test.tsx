import { QueryClient } from '@tanstack/react-query'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { renderWithProviders } from '@/test/renderWithProviders'

import { CreditNoteDetailPage } from '../credit-notes/CreditNoteDetailPage'
import { DeliveryNoteDetailPage } from '../delivery-notes/DeliveryNoteDetailPage'
import { ReturnNoteDetailPage } from '../return-notes/ReturnNoteDetailPage'
import { RepositoryDetailPage } from '../../treasury/RepositoryDetailPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiPatch = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet },
    apiPost: mockApiPost,
    apiPatch: mockApiPatch,
    getErrorMessage: () => 'error',
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

vi.mock('sonner', () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
  },
}))

vi.mock('@/components/ui/ConfirmDialog', () => ({
  ConfirmDialog: ({
    isOpen,
    onConfirm,
    confirmText,
  }: {
    isOpen: boolean
    onConfirm: () => void
    confirmText?: string
  }) => isOpen ? (
    <button type="button" onClick={onConfirm}>{confirmText ?? 'confirm-dialog'}</button>
  ) : null,
}))

vi.mock('../components/DocumentActionBar', () => ({
  DocumentActionBar: ({
    onConfirm,
    onPost,
  }: {
    onConfirm?: () => void
    onPost?: () => void
  }) => (
    <div>
      {onConfirm && <button type="button" onClick={onConfirm}>detail-confirm</button>}
      {onPost && <button type="button" onClick={onPost}>detail-post</button>}
    </div>
  ),
}))

vi.mock('../components/DocumentTotals', () => ({
  DocumentTotals: () => <div>totals</div>,
}))

vi.mock('../components/RelatedDocumentsTab', () => ({
  RelatedDocumentsTab: () => <div>related-documents</div>,
}))

vi.mock('../components/CreateReturnNoteForm', () => ({
  CreateReturnNoteForm: ({ onSuccess }: { onSuccess: () => void }) => (
    <button type="button" onClick={onSuccess}>return-note-success</button>
  ),
}))

vi.mock('@/components/organisms', () => ({
  Modal: ({ children, isOpen }: { children: ReactNode; isOpen: boolean }) => isOpen ? <div>{children}</div> : null,
}))

vi.mock('../hooks', () => ({
  useDownloadPdf: () => ({ mutate: vi.fn(), isPending: false }),
  usePreviewPdf: () => ({ mutate: vi.fn(), isPending: false }),
  usePrintPdf: () => ({ mutate: vi.fn(), isPending: false }),
  useSendDocumentEmail: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))

vi.mock('@/hooks/useCompany', () => ({
  useCompany: () => ({ currentCompany: { currency: 'TND', locale: 'en_US' } }),
}))

vi.mock('../../finance/hooks/useAccounts', () => ({
  useAccounts: () => ({ data: [{ id: 'account-1', code: '101', name: 'Cash' }] }),
}))

function setTenant(tenantId: string, companyId: string) {
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

function createClient() {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
  })
}

const documentRecord = {
  id: 'doc-1',
  document_number: 'DOC-1',
  document_date: '2026-05-11',
  status: 'draft',
  partner_name: 'Partner',
  partner_email: 'partner@example.test',
  source_document_id: null,
  source_document_number: null,
  lines: [{
    id: 'line-1',
    product_id: 'product-1',
    description: 'Precision part',
    quantity: '1',
    quantity_decimals: 3,
    unit_price: '10.000',
    line_total: '10.000',
    discount_percent: null,
    tax_rate: '0.00',
    notes: null,
  }],
  total: '10.000',
}

const repositoryRecord = {
  id: 'repo-1',
  code: 'CASH',
  name: 'Cash',
  type: 'safe',
  bank_name: null,
  account_number: null,
  iban: null,
  bic: null,
  balance: '100.000',
  is_active: true,
  gl_account_id: null,
  gl_account: null,
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockApiGet.mockImplementation(async (url: string) => {
    if (url === '/documents/doc-1') return { data: { data: documentRecord } }
    if (url === '/payment-repositories/repo-1') return { data: { data: repositoryRecord } }
    if (url === '/payment-repositories/repo-1/transactions') {
      return { data: { data: [], meta: { total: 0, repository_id: 'repo-1', repository_name: 'Cash' } } }
    }
    return { data: { data: [] } }
  })
  mockApiPost.mockResolvedValue({ data: { ok: true } })
  mockApiPatch.mockResolvedValue({ data: { ok: true } })
})

afterEach(() => {
  resetTenant()
})

describe('detail pages and repository tenant scope', () => {
  it.each([
    ['credit note', '/sales/credit-notes/:id', '/sales/credit-notes/doc-1', <CreditNoteDetailPage />],
    ['delivery note', '/inventory/delivery-notes/:id', '/inventory/delivery-notes/doc-1', <DeliveryNoteDetailPage />],
    ['return note', '/inventory/return-notes/:id', '/inventory/return-notes/doc-1', <ReturnNoteDetailPage />],
  ])('displays %s quantities at the product unit precision', async (_label, path, route, page) => {
    renderWithProviders(
      <Routes>
        <Route path={path} element={page} />
      </Routes>,
      { queryClient: createClient(), route },
    )

    expect(await screen.findByText('1.000')).toBeInTheDocument()
  })

  it('scopes credit note detail reads and confirm/post invalidations', async () => {
    const user = userEvent.setup()
    const queryClient = createClient()

    renderWithProviders(
      <Routes>
        <Route path="/sales/credit-notes/:id" element={<CreditNoteDetailPage />} />
      </Routes>,
      { queryClient, route: '/sales/credit-notes/doc-1' },
    )

    await waitFor(() => {
      expect(queryClient.getQueryData(['document', 'doc-1', 'tenant-A', 'company-1'])).toEqual(documentRecord)
    })

    await user.click(screen.getByRole('button', { name: 'detail-confirm' }))
    await user.click(screen.getByRole('button', { name: 'common:confirm' }))
    await user.click(screen.getByRole('button', { name: 'detail-post' }))
    await user.click(screen.getByRole('button', { name: 'invoices.post' }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith('/credit-notes/doc-1/confirm')
      expect(mockApiPost).toHaveBeenCalledWith('/credit-notes/doc-1/post')
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/documents/doc-1').length).toBeGreaterThanOrEqual(3)
    })
  })

  it('scopes delivery note detail reads and invalidations', async () => {
    const user = userEvent.setup()
    const queryClient = createClient()

    renderWithProviders(
      <Routes>
        <Route path="/inventory/delivery-notes/:id" element={<DeliveryNoteDetailPage />} />
      </Routes>,
      { queryClient, route: '/inventory/delivery-notes/doc-1' },
    )

    await waitFor(() => {
      expect(queryClient.getQueryData(['document', 'doc-1', 'tenant-A', 'company-1'])).toEqual(documentRecord)
    })

    await user.click(screen.getByRole('button', { name: 'detail-confirm' }))
    await user.click(screen.getByRole('button', { name: 'common:confirm' }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith('/documents/doc-1/confirm')
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/documents/doc-1').length).toBeGreaterThanOrEqual(2)
    })
  })

  it('scopes repository detail reads and GL account invalidation', async () => {
    const user = userEvent.setup()
    const queryClient = createClient()

    renderWithProviders(
      <Routes>
        <Route path="/treasury/repositories/:id" element={<RepositoryDetailPage />} />
      </Routes>,
      { queryClient, route: '/treasury/repositories/repo-1' },
    )

    await waitFor(() => {
      expect(queryClient.getQueryData(['payment-repository', 'repo-1', 'tenant-A', 'company-1'])).toEqual({ data: repositoryRecord })
      expect(queryClient.getQueryData(['payment-repository-transactions', 'repo-1', 'tenant-A', 'company-1'])).toEqual({
        data: [],
        meta: { total: 0, repository_id: 'repo-1', repository_name: 'Cash' },
      })
    })

    await user.click(screen.getByRole('button', { name: 'common:actions.edit' }))
    await user.selectOptions(screen.getByRole('combobox'), 'account-1')
    await user.click(screen.getByRole('button', { name: 'common:actions.save' }))

    await waitFor(() => {
      expect(mockApiPatch).toHaveBeenCalledWith('/payment-repositories/repo-1', { gl_account_id: 'account-1' })
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/payment-repositories/repo-1').length).toBeGreaterThanOrEqual(2)
    })
  })

  it('does not fetch detail page data without tenant/company state', () => {
    resetTenant()

    renderWithProviders(
      <Routes>
        <Route path="/sales/credit-notes/:id" element={<CreditNoteDetailPage />} />
      </Routes>,
      { route: '/sales/credit-notes/doc-1' },
    )

    expect(mockApiGet).not.toHaveBeenCalled()
  })
})
