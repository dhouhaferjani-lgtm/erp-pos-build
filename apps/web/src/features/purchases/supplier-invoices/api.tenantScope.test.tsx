import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import {
  useCreateSupplierInvoice,
  useDeleteAttachment,
  usePostSupplierInvoice,
  useRecordSupplierPayment,
  useRematchSupplierInvoice,
  useSupplierInvoiceAttachments,
  useSupplierInvoiceDetail,
  useSupplierInvoiceList,
  useUploadAttachment,
} from './api'

const mockApiGetMethod = vi.hoisted(() => vi.fn())
const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiDelete = vi.hoisted(() => vi.fn())

vi.mock('../../../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../../../lib/api')>('../../../lib/api')
  return {
    ...actual,
    api: {
      ...actual.api,
      get: mockApiGetMethod,
    },
    apiGet: mockApiGet,
    apiPost: mockApiPost,
    apiDelete: mockApiDelete,
  }
})

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
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
  })
}

function wrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockApiGetMethod.mockResolvedValue({
    data: { data: [], meta: { per_page: 20, has_more: false }, links: { next: null, prev: null } },
  })
  mockApiGet.mockImplementation(async (url: string) => {
    if (url.includes('/attachments')) return [{ id: 'attachment-1' }]
    return { id: 'invoice-1', number: 'SI-1' }
  })
  mockApiPost.mockResolvedValue({ id: 'mutation-result' })
  mockApiDelete.mockResolvedValue({ message: 'deleted' })
})

afterEach(() => {
  resetTenant()
})

describe('supplier invoice API hook tenant scope', () => {
  it('wraps read query keys with tenant/company suffixes and gates missing scope', async () => {
    const queryClient = createClient()
    const { result } = renderHook(() => ({
      list: useSupplierInvoiceList({ status: 'draft' }),
      detail: useSupplierInvoiceDetail('invoice-1'),
      attachments: useSupplierInvoiceAttachments('document-1'),
    }), { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(result.current.list.isSuccess).toBe(true)
      expect(result.current.detail.isSuccess).toBe(true)
      expect(result.current.attachments.isSuccess).toBe(true)
    })

    expect(queryClient.getQueryData(['supplier-invoices', 'list', { status: 'draft' }, 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['supplier-invoices', 'detail', 'invoice-1', 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['supplier-invoices', 'attachments', 'document-1', 'tenant-A', 'company-1'])).toBeDefined()

    const listCalls = mockApiGetMethod.mock.calls.length
    const getCalls = mockApiGet.mock.calls.length
    resetTenant()
    renderHook(() => ({
      list: useSupplierInvoiceList({ status: 'posted' }),
      detail: useSupplierInvoiceDetail('invoice-2'),
      attachments: useSupplierInvoiceAttachments('document-2'),
    }), { wrapper: wrapper(createClient()) })

    expect(mockApiGetMethod).toHaveBeenCalledTimes(listCalls)
    expect(mockApiGet).toHaveBeenCalledTimes(getCalls)
  })

  it('mutation invalidations refetch only active tenant supplier-invoice caches', async () => {
    let listCalls = 0
    let detailCalls = 0
    let attachmentCalls = 0
    mockApiGetMethod.mockImplementation(async () => {
      listCalls += 1
      return {
        data: {
          data: [{ id: `invoice-list-${listCalls}` }],
          meta: { per_page: 20, has_more: false },
          links: { next: null, prev: null },
        },
      }
    })
    mockApiGet.mockImplementation(async (url: string) => {
      if (url.includes('/attachments')) {
        attachmentCalls += 1
        return [{ id: `attachment-${attachmentCalls}` }]
      }
      detailCalls += 1
      return { id: `invoice-detail-${detailCalls}` }
    })

    const queryClient = createClient()
    queryClient.setQueryData(['supplier-invoices', 'list', { status: 'draft' }, 'tenant-B', 'company-1'], { marker: 'tenant-B-list' })
    queryClient.setQueryData(['supplier-invoices', 'detail', 'invoice-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-detail' })
    queryClient.setQueryData(['supplier-invoices', 'attachments', 'document-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-attachments' })

    const { result } = renderHook(() => ({
      list: useSupplierInvoiceList({ status: 'draft' }),
      detail: useSupplierInvoiceDetail('invoice-1'),
      attachments: useSupplierInvoiceAttachments('document-1'),
      createInvoice: useCreateSupplierInvoice(),
      rematchInvoice: useRematchSupplierInvoice('invoice-1'),
      postInvoice: usePostSupplierInvoice('invoice-1'),
      uploadAttachment: useUploadAttachment('document-1'),
      deleteAttachment: useDeleteAttachment('document-1'),
      recordPayment: useRecordSupplierPayment('invoice-1'),
    }), { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(result.current.list.isSuccess).toBe(true)
      expect(result.current.detail.isSuccess).toBe(true)
      expect(result.current.attachments.isSuccess).toBe(true)
    })
    expect(listCalls).toBe(1)
    expect(detailCalls).toBe(1)
    expect(attachmentCalls).toBe(1)

    await act(async () => {
      await result.current.createInvoice.mutateAsync({
        partner_id: 'partner-1',
        source_document_id: 'purchase-order-1',
        currency: 'TND',
        issue_date: '2026-07-01',
        lines: [],
      })
    })
    await waitFor(() => expect(listCalls).toBe(2))

    await act(async () => {
      await result.current.rematchInvoice.mutateAsync()
    })
    await waitFor(() => expect(detailCalls).toBe(2))

    await act(async () => {
      await result.current.postInvoice.mutateAsync()
    })
    await waitFor(() => expect(listCalls).toBe(3))

    await act(async () => {
      await result.current.uploadAttachment.mutateAsync(new File(['x'], 'invoice.pdf'))
    })
    await waitFor(() => expect(attachmentCalls).toBe(2))

    await act(async () => {
      await result.current.deleteAttachment.mutateAsync('attachment-1')
    })
    await waitFor(() => expect(attachmentCalls).toBe(3))

    await act(async () => {
      await result.current.recordPayment.mutateAsync({
        document_id: 'invoice-1',
        amount: '10.000',
        currency: 'TND',
        payment_method_id: 'payment-method-1',
        payment_date: '2026-07-01',
      })
    })
    await waitFor(() => expect(detailCalls).toBe(3))

    expect(queryClient.getQueryData(['supplier-invoices', 'list', { status: 'draft' }, 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-list' })
    expect(queryClient.getQueryData(['supplier-invoices', 'detail', 'invoice-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-detail' })
    expect(queryClient.getQueryData(['supplier-invoices', 'attachments', 'document-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-attachments' })
  })
})
