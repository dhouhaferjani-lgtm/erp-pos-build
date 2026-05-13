import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import type { CreditNote } from '@/types/creditNote'

import { useCreateCreditNote, useCreditNote, useCreditNotes } from '../useCreditNotes'

const mockGetCreditNotes = vi.hoisted(() => vi.fn())
const mockGetCreditNote = vi.hoisted(() => vi.fn())
const mockCreateCreditNote = vi.hoisted(() => vi.fn())

vi.mock('sonner', () => ({
  toast: {
    error: vi.fn(),
    success: vi.fn(),
  },
}))

vi.mock('../../api/creditNotes', () => ({
  createCreditNote: mockCreateCreditNote,
  getCreditNote: mockGetCreditNote,
  getCreditNotes: mockGetCreditNotes,
}))

const creditNoteParams = { source_invoice_id: 'invoice-1' }

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.test',
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
    companies: [],
    isLoading: false,
  })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function createPersistentQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
  })
}

function makeWrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

function creditNoteFixture(id: string): CreditNote {
  return {
    id,
    document_number: `CN-${id}`,
    document_date: '2026-05-11',
    source_invoice_id: 'invoice-1',
    source_invoice_number: 'INV-1',
    partner: { id: 'partner-1', name: 'Partner' },
    currency: 'TND',
    subtotal: '100.000',
    tax_amount: '0.000',
    total: '100.000',
    reason: 'return',
    reason_label: 'Return',
    notes: null,
    status: 'draft',
    created_at: '2026-05-11T09:00:00Z',
  }
}

function useProbe(queryKey: readonly unknown[], queryFn: () => Promise<unknown>) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(queryKey),
    queryFn,
    enabled: tenantId !== null && companyId !== null,
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockGetCreditNotes.mockResolvedValue([creditNoteFixture('credit-note-1')])
  mockGetCreditNote.mockResolvedValue(creditNoteFixture('credit-note-1'))
  mockCreateCreditNote.mockResolvedValue(creditNoteFixture('credit-note-1'))
})

afterEach(() => {
  resetTenant()
})

describe('credit note hooks tenant scope', () => {
  it('wraps credit note read query keys with the active tenant and company (.186-.187)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)

    const { result } = renderHook(() => ({
      detail: useCreditNote('credit-note-1'),
      list: useCreditNotes(creditNoteParams),
    }), { wrapper })

    await waitFor(() => {
      expect(result.current.detail.isSuccess).toBe(true)
      expect(result.current.list.isSuccess).toBe(true)
    })

    expect(queryClient.getQueryData(['credit-note', 'credit-note-1', 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['credit-notes', creditNoteParams, 'tenant-A', 'company-1'])).toBeDefined()
  })

  it('does not fetch credit note reads without tenant/company scope', () => {
    resetTenant()
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)

    renderHook(() => ({
      detail: useCreditNote('credit-note-1'),
      list: useCreditNotes(creditNoteParams),
    }), { wrapper })

    expect(mockGetCreditNote).not.toHaveBeenCalled()
    expect(mockGetCreditNotes).not.toHaveBeenCalled()
  })

  it('bounds credit note mutation invalidation to the active tenant cache (.188-.190)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)
    let listCalls = 0
    let invoiceCalls = 0
    let documentsCalls = 0

    mockGetCreditNotes.mockImplementation(async () => {
      listCalls += 1
      return [creditNoteFixture(`credit-note-list-${listCalls}`)]
    })
    const invoiceQuery = vi.fn(async () => {
      invoiceCalls += 1
      return { id: `invoice-${invoiceCalls}` }
    })
    const documentsQuery = vi.fn(async () => {
      documentsCalls += 1
      return [{ id: `document-${documentsCalls}` }]
    })

    queryClient.setQueryData(['credit-notes', creditNoteParams, 'tenant-B', 'company-1'], { marker: 'tenant-B-credit-notes' })
    queryClient.setQueryData(['invoice', 'invoice-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-invoice' })
    queryClient.setQueryData(['documents', 'tenant-B', 'company-1'], { marker: 'tenant-B-documents' })

    const { result: reads } = renderHook(() => ({
      documents: useProbe(['documents'], documentsQuery),
      invoice: useProbe(['invoice', 'invoice-1'], invoiceQuery),
      list: useCreditNotes(creditNoteParams),
    }), { wrapper })
    await waitFor(() => {
      expect(reads.current.documents.isSuccess).toBe(true)
      expect(reads.current.invoice.isSuccess).toBe(true)
      expect(reads.current.list.isSuccess).toBe(true)
      expect(listCalls).toBe(1)
      expect(invoiceCalls).toBe(1)
      expect(documentsCalls).toBe(1)
    })

    const { result: mutation } = renderHook(() => useCreateCreditNote(), { wrapper })

    await act(async () => {
      await mutation.current.mutateAsync({
        amount: '100.000',
        reason: 'return',
        source_invoice_id: 'invoice-1',
      })
    })

    await waitFor(() => {
      expect(listCalls).toBe(2)
      expect(invoiceCalls).toBe(2)
      expect(documentsCalls).toBe(2)
    })

    expect(queryClient.getQueryData(['credit-notes', creditNoteParams, 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-credit-notes' })
    expect(queryClient.getQueryData(['invoice', 'invoice-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-invoice' })
    expect(queryClient.getQueryData(['documents', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-documents' })
  })
})
