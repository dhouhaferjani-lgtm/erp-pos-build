import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import type { ReturnNote } from '@/types/returnNote'

import { useCreateReturnNote, useReturnNote, useReturnNotes } from '../useReturnNotes'

const mockGetReturnNotes = vi.hoisted(() => vi.fn())
const mockGetReturnNote = vi.hoisted(() => vi.fn())
const mockCreateReturnNote = vi.hoisted(() => vi.fn())

vi.mock('sonner', () => ({
  toast: {
    error: vi.fn(),
    success: vi.fn(),
  },
}))

vi.mock('../../api/returnNotes', () => ({
  createReturnNote: mockCreateReturnNote,
  getReturnNote: mockGetReturnNote,
  getReturnNotes: mockGetReturnNotes,
}))

const returnNoteParams = { source_invoice_id: 'invoice-1' }

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

function returnNoteFixture(id: string): ReturnNote {
  return {
    id,
    document_number: `RN-${id}`,
    document_date: '2026-05-11',
    partner: { id: 'partner-1', name: 'Partner' },
    currency: 'TND',
    subtotal: '100.000',
    tax_amount: '0.000',
    total: '100.000',
    status: 'draft',
    // Plan CF T8: `metadata` never existed on any response — the endpoint returns
    // `DocumentData::fromModel()`, which has no such key. One `source_document_id`
    // replaces the two invented ones.
    source_document_id: 'invoice-1',
    payload: { return_reason: 'defective' },
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
  mockGetReturnNotes.mockResolvedValue([returnNoteFixture('return-note-1')])
  mockGetReturnNote.mockResolvedValue(returnNoteFixture('return-note-1'))
  mockCreateReturnNote.mockResolvedValue(returnNoteFixture('return-note-1'))
})

afterEach(() => {
  resetTenant()
})

describe('return note hooks tenant scope', () => {
  it('wraps return note read query keys with the active tenant and company (.198-.199)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)

    const { result } = renderHook(() => ({
      detail: useReturnNote('return-note-1'),
      list: useReturnNotes(returnNoteParams),
    }), { wrapper })

    await waitFor(() => {
      expect(result.current.detail.isSuccess).toBe(true)
      expect(result.current.list.isSuccess).toBe(true)
    })

    expect(queryClient.getQueryData(['return-note', 'return-note-1', 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['return-notes', returnNoteParams, 'tenant-A', 'company-1'])).toBeDefined()
  })

  it('does not fetch return note reads without tenant/company scope', () => {
    resetTenant()
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)

    renderHook(() => ({
      detail: useReturnNote('return-note-1'),
      list: useReturnNotes(returnNoteParams),
    }), { wrapper })

    expect(mockGetReturnNote).not.toHaveBeenCalled()
    expect(mockGetReturnNotes).not.toHaveBeenCalled()
  })

  it('bounds return note mutation invalidation to the active tenant cache (.200-.203)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)
    let listCalls = 0
    let invoiceCalls = 0
    let deliveryNoteCalls = 0
    let documentsCalls = 0

    mockGetReturnNotes.mockImplementation(async () => {
      listCalls += 1
      return [returnNoteFixture(`return-note-list-${listCalls}`)]
    })
    const invoiceQuery = vi.fn(async () => {
      invoiceCalls += 1
      return { id: `invoice-${invoiceCalls}` }
    })
    const deliveryNoteQuery = vi.fn(async () => {
      deliveryNoteCalls += 1
      return { id: `delivery-note-${deliveryNoteCalls}` }
    })
    const documentsQuery = vi.fn(async () => {
      documentsCalls += 1
      return [{ id: `document-${documentsCalls}` }]
    })

    queryClient.setQueryData(['return-notes', returnNoteParams, 'tenant-B', 'company-1'], { marker: 'tenant-B-return-notes' })
    queryClient.setQueryData(['invoice', 'invoice-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-invoice' })
    queryClient.setQueryData(['delivery-note', 'delivery-note-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-delivery-note' })
    queryClient.setQueryData(['documents', 'tenant-B', 'company-1'], { marker: 'tenant-B-documents' })

    const { result: reads } = renderHook(() => ({
      // Plan CF T8: a return note has ONE `source_document_id`, which is either an
      // invoice or a delivery note. The hook invalidates BOTH prefixes with that single
      // id, so the active-tenant probe keys on it — the old fixture carried two
      // distinct ids only because the invented `metadata` shape pretended a return note
      // had both at once.
      deliveryNote: useProbe(['delivery-note', 'invoice-1'], deliveryNoteQuery),
      documents: useProbe(['documents'], documentsQuery),
      invoice: useProbe(['invoice', 'invoice-1'], invoiceQuery),
      list: useReturnNotes(returnNoteParams),
    }), { wrapper })
    await waitFor(() => {
      expect(reads.current.deliveryNote.isSuccess).toBe(true)
      expect(reads.current.documents.isSuccess).toBe(true)
      expect(reads.current.invoice.isSuccess).toBe(true)
      expect(reads.current.list.isSuccess).toBe(true)
      expect(listCalls).toBe(1)
      expect(invoiceCalls).toBe(1)
      expect(deliveryNoteCalls).toBe(1)
      expect(documentsCalls).toBe(1)
    })

    const { result: mutation } = renderHook(() => useCreateReturnNote(), { wrapper })

    await act(async () => {
      // The CANONICAL document-create shape (plan CF T8 / CF-D9) — the payload the
      // server has always required and the client never sent.
      await mutation.current.mutateAsync({
        partner_id: 'partner-1',
        document_date: '2026-05-11',
        currency: 'TND',
        source_document_id: 'invoice-1',
        return_reason: 'defective',
        lines: [{
          product_id: 'p1',
          description: 'Product 1',
          quantity: '1',
          unit_price: '100.000',
          tax_rate: '19.00',
        }],
      })
    })

    await waitFor(() => {
      expect(listCalls).toBe(2)
      expect(invoiceCalls).toBe(2)
      expect(deliveryNoteCalls).toBe(2)
      expect(documentsCalls).toBe(2)
    })

    expect(queryClient.getQueryData(['return-notes', returnNoteParams, 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-return-notes' })
    expect(queryClient.getQueryData(['invoice', 'invoice-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-invoice' })
    expect(queryClient.getQueryData(['delivery-note', 'delivery-note-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-delivery-note' })
    expect(queryClient.getQueryData(['documents', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-documents' })
  })
})
