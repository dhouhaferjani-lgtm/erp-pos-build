import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import type { DeliveryNote } from '../../api/deliveryNotes'

import {
  useConsolidateDeliveryNotes,
  useDeliveryNote,
  useDeliveryNotes,
  useInvoiceableDeliveryNotes,
} from '../useDeliveryNotes'

const mockGetDeliveryNotes = vi.hoisted(() => vi.fn())
const mockGetInvoiceableDeliveryNotes = vi.hoisted(() => vi.fn())
const mockGetDeliveryNote = vi.hoisted(() => vi.fn())
const mockConsolidateDeliveryNotesToInvoice = vi.hoisted(() => vi.fn())

vi.mock('sonner', () => ({
  toast: {
    error: vi.fn(),
    success: vi.fn(),
  },
}))

vi.mock('../../api/deliveryNotes', () => ({
  consolidateDeliveryNotesToInvoice: mockConsolidateDeliveryNotesToInvoice,
  getDeliveryNote: mockGetDeliveryNote,
  getDeliveryNotes: mockGetDeliveryNotes,
  getInvoiceableDeliveryNotes: mockGetInvoiceableDeliveryNotes,
}))

const deliveryNoteParams = { status: 'confirmed' as const, partner_id: 'partner-1' }

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

function deliveryNoteFixture(id: string): DeliveryNote {
  return {
    id,
    document_number: `DN-${id}`,
    type: 'delivery_note',
    status: 'confirmed',
    partner_id: 'partner-1',
    partner_name: 'Partner',
    document_date: '2026-05-11',
    subtotal: '100.000',
    tax_amount: '0.000',
    total: '100.000',
    currency: 'TND',
    lines: [],
    created_at: '2026-05-11T09:00:00Z',
    updated_at: '2026-05-11T09:00:00Z',
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
  mockGetDeliveryNotes.mockResolvedValue([deliveryNoteFixture('delivery-note-1')])
  mockGetInvoiceableDeliveryNotes.mockResolvedValue([deliveryNoteFixture('delivery-note-1')])
  mockGetDeliveryNote.mockResolvedValue(deliveryNoteFixture('delivery-note-1'))
  mockConsolidateDeliveryNotesToInvoice.mockResolvedValue({
    data: {
      id: 'invoice-1',
      document_number: 'INV-1',
      type: 'invoice',
      status: 'draft',
      subtotal: '100.000',
      tax_amount: '0.000',
      total: '100.000',
      lines: [],
      partner: { id: 'partner-1', name: 'Partner' },
    },
    message: 'Created',
    meta: {
      consolidated_delivery_notes: 1,
      source_delivery_note_ids: ['delivery-note-1'],
    },
  })
})

afterEach(async () => {
  await act(async () => {
    resetTenant()
  })
})

describe('delivery note hooks tenant scope', () => {
  it('wraps delivery note read query keys with the active tenant and company (.191-.193)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)

    const { result } = renderHook(() => ({
      detail: useDeliveryNote('delivery-note-1'),
      invoiceable: useInvoiceableDeliveryNotes('partner-1'),
      list: useDeliveryNotes(deliveryNoteParams),
    }), { wrapper })

    await waitFor(() => {
      expect(result.current.detail.isSuccess).toBe(true)
      expect(result.current.invoiceable.isSuccess).toBe(true)
      expect(result.current.list.isSuccess).toBe(true)
    })

    expect(queryClient.getQueryData(['delivery-note', 'delivery-note-1', 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['delivery-notes', 'invoiceable', 'partner-1', 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['delivery-notes', deliveryNoteParams, 'tenant-A', 'company-1'])).toBeDefined()
  })

  it('does not fetch delivery note reads without tenant/company scope', () => {
    resetTenant()
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)

    renderHook(() => ({
      detail: useDeliveryNote('delivery-note-1'),
      invoiceable: useInvoiceableDeliveryNotes('partner-1'),
      list: useDeliveryNotes(deliveryNoteParams),
    }), { wrapper })

    expect(mockGetDeliveryNote).not.toHaveBeenCalled()
    expect(mockGetDeliveryNotes).not.toHaveBeenCalled()
    expect(mockGetInvoiceableDeliveryNotes).not.toHaveBeenCalled()
  })

  it('bounds delivery consolidation invalidation to the active tenant cache (.194-.196)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)
    let deliveryNotesCalls = 0
    let invoiceableCalls = 0
    let documentsCalls = 0
    let invoicesCalls = 0

    mockGetDeliveryNotes.mockImplementation(async () => {
      deliveryNotesCalls += 1
      return [deliveryNoteFixture(`delivery-note-list-${deliveryNotesCalls}`)]
    })
    mockGetInvoiceableDeliveryNotes.mockImplementation(async () => {
      invoiceableCalls += 1
      return [deliveryNoteFixture(`delivery-note-invoiceable-${invoiceableCalls}`)]
    })
    const documentsQuery = vi.fn(async () => {
      documentsCalls += 1
      return [{ id: `document-${documentsCalls}` }]
    })
    const invoicesQuery = vi.fn(async () => {
      invoicesCalls += 1
      return [{ id: `invoice-${invoicesCalls}` }]
    })

    queryClient.setQueryData(['delivery-notes', deliveryNoteParams, 'tenant-B', 'company-1'], { marker: 'tenant-B-delivery-notes' })
    queryClient.setQueryData(['delivery-notes', 'invoiceable', 'partner-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-invoiceable' })
    queryClient.setQueryData(['documents', 'tenant-B', 'company-1'], { marker: 'tenant-B-documents' })
    queryClient.setQueryData(['invoices', 'tenant-B', 'company-1'], { marker: 'tenant-B-invoices' })

    const { result: reads } = renderHook(() => ({
      documents: useProbe(['documents'], documentsQuery),
      invoiceable: useInvoiceableDeliveryNotes('partner-1'),
      invoices: useProbe(['invoices'], invoicesQuery),
      list: useDeliveryNotes(deliveryNoteParams),
    }), { wrapper })
    await waitFor(() => {
      expect(reads.current.documents.isSuccess).toBe(true)
      expect(reads.current.invoiceable.isSuccess).toBe(true)
      expect(reads.current.invoices.isSuccess).toBe(true)
      expect(reads.current.list.isSuccess).toBe(true)
      expect(deliveryNotesCalls).toBe(1)
      expect(invoiceableCalls).toBe(1)
      expect(documentsCalls).toBe(1)
      expect(invoicesCalls).toBe(1)
    })

    const { result: mutation } = renderHook(() => useConsolidateDeliveryNotes(), { wrapper })

    await act(async () => {
      await mutation.current.mutateAsync(['delivery-note-1'])
    })

    await waitFor(() => {
      expect(deliveryNotesCalls).toBe(2)
      expect(invoiceableCalls).toBe(2)
      expect(documentsCalls).toBe(2)
      expect(invoicesCalls).toBe(2)
    })

    expect(queryClient.getQueryData(['delivery-notes', deliveryNoteParams, 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-delivery-notes' })
    expect(queryClient.getQueryData(['delivery-notes', 'invoiceable', 'partner-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-invoiceable' })
    expect(queryClient.getQueryData(['documents', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-documents' })
    expect(queryClient.getQueryData(['invoices', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-invoices' })
  })
})
