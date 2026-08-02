import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { CreditNoteDetailPage } from '../CreditNoteDetailPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockRouteId = vi.hoisted(() => ({ current: 'cn-1' }))
const mockTranslate = vi.hoisted(() => vi.fn((key: string) => key))

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet },
    apiPost: mockApiPost,
    getErrorMessage: () => 'request failed',
  }
})

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    useParams: () => ({ id: mockRouteId.current }),
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: mockTranslate }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

vi.mock('@/hooks/useCompany', () => ({
  useCompany: () => ({ currentCompany: { id: 'company-1', currency: 'TND' } }),
}))

vi.mock('@/features/documents/hooks/useDocumentEmail', () => ({
  useSendDocumentEmail: () => ({ isPending: false, mutateAsync: vi.fn() }),
}))

vi.mock('@/features/documents/hooks/useDocumentPdf', () => ({
  useDownloadPdf: () => ({ isPending: false, mutate: vi.fn() }),
  usePreviewPdf: () => ({ isPending: false, mutate: vi.fn() }),
  usePrintPdf: () => ({ isPending: false, mutate: vi.fn() }),
}))

vi.mock('@/features/documents/components/RelatedDocumentsTab', () => ({
  RelatedDocumentsTab: () => null,
}))

vi.mock('@/features/documents/components/DocumentTotals', () => ({
  DocumentTotals: () => null,
}))

vi.mock('@/features/documents/components/DocumentActionBar', () => ({
  DocumentActionBar: ({
    onConfirm,
    onPost,
  }: {
    onConfirm?: () => void
    onPost?: () => void
  }) => (
    <div>
      <button type="button" onClick={onConfirm}>confirm-credit-note</button>
      <button type="button" onClick={onPost}>post-credit-note</button>
    </div>
  ),
}))

vi.mock('@/components/ui/ConfirmDialog', () => ({
  ConfirmDialog: ({
    confirmText,
    isOpen,
    onConfirm,
    title,
  }: {
    confirmText?: string
    isOpen: boolean
    onConfirm: () => void
    title: string
  }) => (isOpen ? <button type="button" onClick={onConfirm}>{confirmText ?? title}</button> : null),
}))

vi.mock('@/components/molecules/EntityLink', () => ({
  EntityLink: ({ label }: { label: ReactNode }) => <span>{label}</span>,
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'User',
      email: 'user@example.test',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: companyId, companies: [], isLoading: false })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function createClient() {
  return new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false } },
  })
}

function wrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <MemoryRouter>
        <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
      </MemoryRouter>
    )
  }
}

function creditNoteFixture() {
  return {
    id: 'cn-1',
    type: 'credit_note',
    status: 'draft',
    document_number: 'CN-2026-0001',
    document_date: '2026-08-02',
    partner_id: 'partner-1',
    partner_name: 'Partner A',
    total: '100.000',
    currency: 'TND',
    lines: [],
  }
}

function mockCreditNoteResponses() {
  mockApiGet.mockImplementation(async (url: string) => {
    if (url === '/documents/cn-1') return { data: { data: creditNoteFixture() } }
    return { data: { data: [] } }
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  mockRouteId.current = 'cn-1'
  setTenant('tenant-A', 'company-1')
  mockCreditNoteResponses()
  mockApiPost.mockResolvedValue({ data: { data: creditNoteFixture() } })
})

afterEach(() => {
  resetTenant()
})

describe('CreditNoteDetailPage confirm/post routes', () => {
  it('Confirm posts to /credit-notes/{id}/confirm (not /documents/{id}/confirm)', async () => {
    render(<CreditNoteDetailPage />, { wrapper: wrapper(createClient()) })

    await userEvent.click(await screen.findByRole('button', { name: 'confirm-credit-note' }))
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'common:confirm' }))
    })

    expect(mockApiPost).toHaveBeenCalledWith('/credit-notes/cn-1/confirm')
    expect(mockApiPost).not.toHaveBeenCalledWith('/documents/cn-1/confirm')
  })

  it('Post posts to /credit-notes/{id}/post (not /documents/{id}/post)', async () => {
    render(<CreditNoteDetailPage />, { wrapper: wrapper(createClient()) })

    await userEvent.click(await screen.findByRole('button', { name: 'post-credit-note' }))
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'invoices.post' }))
    })

    expect(mockApiPost).toHaveBeenCalledWith('/credit-notes/cn-1/post')
    expect(mockApiPost).not.toHaveBeenCalledWith('/documents/cn-1/post')
  })
})
