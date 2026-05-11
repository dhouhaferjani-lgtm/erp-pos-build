import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { useAttachmentConfig, useAttachments, useDeleteAttachment, useUploadAttachment } from '../useAttachments'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiDelete = vi.hoisted(() => vi.fn())

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))
vi.mock('@/lib/api', () => ({
  api: { get: mockApiGet, post: mockApiPost },
  apiDelete: mockApiDelete,
  getErrorMessage: (error: unknown) => error instanceof Error ? error.message : 'Unknown error',
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: { id: 'user-1', name: 'User', email: 'u@example.test', tenant_id: tenantId, roles: [], email_verified_at: null },
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
  return new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false } } })
}

function wrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockApiGet.mockResolvedValue({ data: { data: [{ id: 'attachment-1' }] } })
  mockApiPost.mockResolvedValue({ data: { data: { id: 'attachment-new' } } })
  mockApiDelete.mockResolvedValue(undefined)
})

afterEach(() => {
  resetTenant()
})

describe('attachment hooks tenant scope', () => {
  it('wraps attachment read keys and gates missing tenant/company (.182-.183)', async () => {
    const queryClient = createClient()
    const { result } = renderHook(() => ({
      attachments: useAttachments('document-1'),
      config: useAttachmentConfig(),
    }), { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(result.current.attachments.isSuccess).toBe(true)
      expect(result.current.config.isSuccess).toBe(true)
    })

    expect(queryClient.getQueryData(['attachments', 'document-1', 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['attachments-config', 'tenant-A', 'company-1'])).toBeDefined()

    resetTenant()
    const gatedClient = createClient()
    renderHook(() => useAttachments('document-2'), { wrapper: wrapper(gatedClient) })
    expect(mockApiGet).toHaveBeenCalledTimes(2)
  })

  it('invalidates only active-tenant attachment cache (.184-.185)', async () => {
    const queryClient = createClient()
    let attachmentCalls = 0
    mockApiGet.mockImplementation(async () => {
      attachmentCalls += 1
      return { data: { data: [{ id: `attachment-${attachmentCalls}` }] } }
    })
    queryClient.setQueryData(['attachments', 'document-1', 'tenant-B', 'company-1'], { marker: 'tenant-B' })

    const { result: read } = renderHook(() => useAttachments('document-1'), { wrapper: wrapper(queryClient) })
    await waitFor(() => expect(read.current.isSuccess).toBe(true))
    expect(attachmentCalls).toBe(1)

    const { result: mutations } = renderHook(() => ({
      deleteAttachment: useDeleteAttachment('document-1'),
      uploadAttachment: useUploadAttachment('document-1'),
    }), { wrapper: wrapper(queryClient) })

    await act(async () => {
      await mutations.current.uploadAttachment.mutateAsync({ file: new File(['x'], 'a.txt') })
    })
    await waitFor(() => expect(attachmentCalls).toBe(2))

    await act(async () => {
      await mutations.current.deleteAttachment.mutateAsync('attachment-1')
    })
    await waitFor(() => expect(attachmentCalls).toBe(3))

    expect(queryClient.getQueryData(['attachments', 'document-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B' })
  })
})
