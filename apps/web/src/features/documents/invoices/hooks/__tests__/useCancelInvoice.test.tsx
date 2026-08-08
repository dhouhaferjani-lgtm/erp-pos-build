import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import { act } from 'react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { useCancelInvoice, useCanCancelInvoice } from '../useCancelInvoice'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    apiGet: mockApiGet,
    apiPost: mockApiPost,
    api: { get: mockApiGet, post: mockApiPost },
  }
})

const INVOICE_ID = 'inv-1'

function createClient(): QueryClient {
  return new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: 0 }, mutations: { retry: false } },
  })
}

function wrapperFor(queryClient: QueryClient) {
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  )
}

/**
 * T9's tests, which the plan enumerates and the first cut omitted entirely (gate CF round
 * 1, MAJOR M4): *"posted body per mode; invalidation set; the `can-cancel` query does not
 * fire for a non-invoice document."*
 *
 * The missing invalidation-set assertion is precisely what would have caught Blocker B1 —
 * the hook invalidated `['invoice', id]` while the invoice detail page stores under
 * `['document', 'invoice', id]`, and `queryKey` is a positional PREFIX matcher.
 */
describe('useCancelInvoice / useCanCancelInvoice', () => {
  beforeEach(() => {
    mockApiGet.mockReset()
    mockApiPost.mockReset()
    useAuthStore.setState({
      user: {
        id: 'user-1',
        name: 'Test User',
        email: 'test@example.com',
        tenant_id: 'tenant-A',
        roles: [],
        email_verified_at: null,
      },
      isAuthenticated: true,
    })
    useCompanyStore.setState({ currentCompanyId: 'company-1' })
  })

  afterEach(() => {
    useAuthStore.setState({ user: null, isAuthenticated: false })
    useCompanyStore.setState({ currentCompanyId: null })
  })

  describe('posted body per mode', () => {
    it.each([
      ['will_return', { mode: 'will_return' as const }],
      ['no_return', { mode: 'no_return' as const }],
      ['no_goods_issued', { mode: 'no_goods_issued' as const }],
      ['not_applicable', { mode: 'not_applicable' as const }],
    ])('posts %s with no returned_on', async (_label, decision) => {
      mockApiPost.mockResolvedValue({ data: { data: {}, return_decision: null, message: 'ok' } })
      const queryClient = createClient()

      const { result } = renderHook(() => useCancelInvoice({ invoiceId: INVOICE_ID }), {
        wrapper: wrapperFor(queryClient),
      })

      await act(async () => {
        await result.current.mutateAsync({ reason: 'Duplicate', return_decision: decision })
      })

      expect(mockApiPost).toHaveBeenCalledWith(`/invoices/${INVOICE_ID}/cancel`, {
        reason: 'Duplicate',
        return_decision: decision,
      })
      // `returned_on` is PROHIBITED by the server for every mode but already_returned.
      expect(mockApiPost.mock.calls[0][1].return_decision).not.toHaveProperty('returned_on')
    })

    it('posts already_returned with its date', async () => {
      mockApiPost.mockResolvedValue({ data: { data: {}, return_decision: null, message: 'ok' } })
      const queryClient = createClient()

      const { result } = renderHook(() => useCancelInvoice({ invoiceId: INVOICE_ID }), {
        wrapper: wrapperFor(queryClient),
      })

      await act(async () => {
        await result.current.mutateAsync({
          reason: 'Already back',
          return_decision: { mode: 'already_returned', returned_on: '2026-08-07' },
        })
      })

      expect(mockApiPost).toHaveBeenCalledWith(`/invoices/${INVOICE_ID}/cancel`, {
        reason: 'Already back',
        return_decision: { mode: 'already_returned', returned_on: '2026-08-07' },
      })
    })

    it('omits return_decision entirely when none is given — today\'s behaviour', async () => {
      mockApiPost.mockResolvedValue({ data: { data: {}, return_decision: null, message: 'ok' } })
      const queryClient = createClient()

      const { result } = renderHook(() => useCancelInvoice({ invoiceId: INVOICE_ID }), {
        wrapper: wrapperFor(queryClient),
      })

      await act(async () => {
        await result.current.mutateAsync({ reason: 'Duplicate' })
      })

      expect(mockApiPost.mock.calls[0][1]).not.toHaveProperty('return_decision')
    })
  })

  describe('invalidation set', () => {
    /**
     * THE assertion that would have caught B1. The invoice detail page stores its
     * document under `tenantScopedKey(['document', 'invoice', id])` — the SINGULAR
     * 'document' head — and the hook previously targeted `['invoice', id]`, which cannot
     * prefix-match it. The `documents` predicate did not rescue it either: it requires
     * `k[0] === 'documents'`.
     */
    it('invalidates the invoice detail key the page actually stores under', async () => {
      mockApiPost.mockResolvedValue({ data: { data: {}, return_decision: null, message: 'ok' } })
      const queryClient = createClient()
      const invalidate = vi.spyOn(queryClient, 'invalidateQueries')

      const { result } = renderHook(() => useCancelInvoice({ invoiceId: INVOICE_ID }), {
        wrapper: wrapperFor(queryClient),
      })

      await act(async () => {
        await result.current.mutateAsync({
          reason: 'Duplicate',
          return_decision: { mode: 'no_return' },
        })
      })

      // Asserted on the CALL, not on cache state: an active query refetches the instant
      // it is invalidated, so `isInvalidated` flips straight back and the flag is a
      // race. The call is the contract.
      const invalidatedKeys = invalidate.mock.calls
        .map((call) => call[0]?.queryKey)
        .filter((key): key is unknown[] => Array.isArray(key))

      expect(invalidatedKeys).toContainEqual(['document', 'invoice', INVOICE_ID])
    })

    it('invalidates every namespace a goods-bearing cancel actually moves', async () => {
      mockApiPost.mockResolvedValue({ data: { data: {}, return_decision: null, message: 'ok' } })
      const queryClient = createClient()
      const invalidate = vi.spyOn(queryClient, 'invalidateQueries')

      const { result } = renderHook(() => useCancelInvoice({ invoiceId: INVOICE_ID }), {
        wrapper: wrapperFor(queryClient),
      })

      await act(async () => {
        await result.current.mutateAsync({
          reason: 'Already back',
          return_decision: { mode: 'already_returned', returned_on: '2026-08-07' },
        })
      })

      const invalidatedKeys = invalidate.mock.calls
        .map((call) => call[0]?.queryKey)
        .filter((key): key is unknown[] => Array.isArray(key))

      expect(invalidatedKeys).toContainEqual(['document', 'invoice', INVOICE_ID])
      // Kept: this is `CreateReturnNotePage`'s key.
      expect(invalidatedKeys).toContainEqual(['invoice', INVOICE_ID])
      expect(invalidatedKeys).toContainEqual(['invoice-can-cancel', INVOICE_ID])

      // The three namespace predicates. Stale stock in particular would show PRE-return
      // quantities immediately after telling the user the goods came back.
      const predicates = invalidate.mock.calls
        .map((call) => call[0]?.predicate)
        .filter((predicate): predicate is (q: { queryKey: readonly unknown[] }) => boolean =>
          typeof predicate === 'function')

      for (const namespace of ['documents', 'return-notes', 'stock']) {
        expect(
          predicates.some((predicate) =>
            predicate({ queryKey: [namespace, 'tenant-A', 'company-1'] })),
        ).toBe(true)
      }
    })

    it('scopes the namespace predicates to the active tenant and company', async () => {
      mockApiPost.mockResolvedValue({ data: { data: {}, return_decision: null, message: 'ok' } })
      const queryClient = createClient()
      const invalidate = vi.spyOn(queryClient, 'invalidateQueries')

      const { result } = renderHook(() => useCancelInvoice({ invoiceId: INVOICE_ID }), {
        wrapper: wrapperFor(queryClient),
      })

      await act(async () => {
        await result.current.mutateAsync({ reason: 'Duplicate', return_decision: { mode: 'no_return' } })
      })

      const predicates = invalidate.mock.calls
        .map((call) => call[0]?.predicate)
        .filter((predicate): predicate is (q: { queryKey: readonly unknown[] }) => boolean =>
          typeof predicate === 'function')

      expect(
        predicates.some((predicate) => predicate({ queryKey: ['documents', 'tenant-B', 'company-2'] })),
      ).toBe(false)
    })
  })

  describe('the can-cancel query', () => {
    it('does not fire when disabled — the six non-invoice pages share DocumentActionBar', async () => {
      const queryClient = createClient()

      renderHook(() => useCanCancelInvoice(INVOICE_ID, false), { wrapper: wrapperFor(queryClient) })

      await waitFor(() => {
        expect(mockApiGet).not.toHaveBeenCalled()
      })
    })

    it('does not fire without a tenant/company scope', async () => {
      useAuthStore.setState({ user: null, isAuthenticated: false })
      useCompanyStore.setState({ currentCompanyId: null })
      const queryClient = createClient()

      renderHook(() => useCanCancelInvoice(INVOICE_ID, true), { wrapper: wrapperFor(queryClient) })

      await waitFor(() => {
        expect(mockApiGet).not.toHaveBeenCalled()
      })
    })

    it('stores under a tenant-scoped key when enabled', async () => {
      mockApiGet.mockResolvedValue({
        can_cancel: true,
        reason_code: null,
        status: 'posted',
        requires_return_decision: true,
        goods_issued: true,
        delivered_quantities: [],
        return_decision: null,
      })
      const queryClient = createClient()

      const { result } = renderHook(() => useCanCancelInvoice(INVOICE_ID, true), {
        wrapper: wrapperFor(queryClient),
      })

      await waitFor(() => {
        expect(result.current.isSuccess).toBe(true)
      })

      expect(mockApiGet).toHaveBeenCalledWith(`/invoices/${INVOICE_ID}/can-cancel`)
      expect(
        queryClient.getQueryData(tenantScopedKey(['invoice-can-cancel', INVOICE_ID])),
      ).toBeDefined()
    })
  })
})
