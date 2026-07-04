import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api, apiGet, apiPost, apiPut } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import type {
  AwardQuoteRequestResponse,
  CreateQuoteRequestGroupPayload,
  QuoteRequestDetail,
  QuoteRequestGroup,
  QuoteRequestListItem,
  QuoteRequestListParams,
  ReopenQuoteRequestGroupResponse,
  UpdateQuoteRequestPayload,
} from './types'

export const quoteRequestKeys = {
  list: (params: QuoteRequestListParams) => ['quote-requests', 'list', params] as const,
  detail: (id: string) => ['quote-requests', 'detail', id] as const,
  group: (groupId: string) => ['quote-requests', 'group', groupId] as const,
} as const

function quoteRequestListInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const key = q.queryKey
    return (
      Array.isArray(key) &&
      key[0] === 'quote-requests' &&
      key[1] === 'list' &&
      key[key.length - 2] === tenantId &&
      key[key.length - 1] === companyId
    )
  }
}

function quoteRequestDetailInvalidationPredicate(
  id: string,
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const key = q.queryKey
    return (
      Array.isArray(key) &&
      key[0] === 'quote-requests' &&
      key[1] === 'detail' &&
      key[2] === id &&
      key[key.length - 2] === tenantId &&
      key[key.length - 1] === companyId
    )
  }
}

function quoteRequestGroupInvalidationPredicate(
  groupId: string,
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const key = q.queryKey
    return (
      Array.isArray(key) &&
      key[0] === 'quote-requests' &&
      key[1] === 'group' &&
      key[2] === groupId &&
      key[key.length - 2] === tenantId &&
      key[key.length - 1] === companyId
    )
  }
}

export function useQuoteRequests(params: QuoteRequestListParams = {}) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const enabled = tenantId !== null && companyId !== null

  return useQuery({
    queryKey: tenantScopedKey([...quoteRequestKeys.list(params)]),
    queryFn: async () => {
      const cleanParams: Record<string, string> = {}
      if (params.status) cleanParams['status'] = params.status
      if (params.search) cleanParams['search'] = params.search

      const response = await api.get<{ data: QuoteRequestListItem[] }>('/purchase-quote-requests', {
        params: cleanParams,
      })
      return response.data
    },
    enabled,
  })
}

export function useQuoteRequest(id: string) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const enabled = tenantId !== null && companyId !== null && id !== ''

  return useQuery({
    queryKey: tenantScopedKey([...quoteRequestKeys.detail(id)]),
    queryFn: () => apiGet<QuoteRequestDetail>(`/purchase-quote-requests/${id}`),
    enabled,
  })
}

export function useQuoteRequestGroup(groupId: string) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const enabled = tenantId !== null && companyId !== null && groupId !== ''

  return useQuery({
    queryKey: tenantScopedKey([...quoteRequestKeys.group(groupId)]),
    queryFn: () => apiGet<QuoteRequestGroup>(`/purchase-quote-requests/groups/${groupId}`),
    enabled,
  })
}

export function useCreateQuoteRequestGroup() {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (payload: CreateQuoteRequestGroupPayload) =>
      apiPost<QuoteRequestGroup>('/purchase-quote-requests', payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        predicate: quoteRequestListInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}

export function useUpdateQuoteRequest(id: string) {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (payload: UpdateQuoteRequestPayload) =>
      apiPut<QuoteRequestDetail>(`/purchase-quote-requests/${id}`, payload),
    onSuccess: (updated) => {
      queryClient.setQueryData(tenantScopedKey([...quoteRequestKeys.detail(id)]), updated)
      void queryClient.invalidateQueries({
        predicate: quoteRequestListInvalidationPredicate(tenantId, companyId),
      })
      if (updated.group_id) {
        void queryClient.invalidateQueries({
          predicate: quoteRequestGroupInvalidationPredicate(updated.group_id, tenantId, companyId),
        })
      }
    },
  })
}

export function useSendQuoteRequest(id: string) {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: () => apiPost<QuoteRequestDetail>(`/purchase-quote-requests/${id}/send`, {}),
    onSuccess: (updated) => {
      queryClient.setQueryData(tenantScopedKey([...quoteRequestKeys.detail(id)]), updated)
      void queryClient.invalidateQueries({
        predicate: quoteRequestListInvalidationPredicate(tenantId, companyId),
      })
      if (updated.group_id) {
        void queryClient.invalidateQueries({
          predicate: quoteRequestGroupInvalidationPredicate(updated.group_id, tenantId, companyId),
        })
      }
    },
  })
}

export function useAwardQuoteRequest(id: string, groupId?: string) {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: () => apiPost<AwardQuoteRequestResponse>(`/purchase-quote-requests/${id}/convert-to-po`, {}),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        predicate: quoteRequestListInvalidationPredicate(tenantId, companyId),
      })
      void queryClient.invalidateQueries({
        predicate: quoteRequestDetailInvalidationPredicate(id, tenantId, companyId),
      })
      if (groupId !== undefined && groupId !== '') {
        void queryClient.invalidateQueries({
          predicate: quoteRequestGroupInvalidationPredicate(groupId, tenantId, companyId),
        })
      }
    },
  })
}

export function useReopenQuoteRequestGroup(groupId: string) {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: () =>
      apiPost<ReopenQuoteRequestGroupResponse>(`/purchase-quote-requests/groups/${groupId}/reopen`, {}),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        predicate: quoteRequestListInvalidationPredicate(tenantId, companyId),
      })
      void queryClient.invalidateQueries({
        predicate: quoteRequestGroupInvalidationPredicate(groupId, tenantId, companyId),
      })
    },
  })
}
