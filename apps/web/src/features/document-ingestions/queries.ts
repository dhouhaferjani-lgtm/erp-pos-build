import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import {
  commitDocumentIngestion,
  getDocumentIngestion,
  listDocumentIngestions,
  rejectDocumentIngestion,
  reExtractDocumentIngestion,
  uploadDocumentIngestion,
  type ListDocumentIngestionsParams,
} from './api'
import type {
  DocumentIngestionListResponse,
  IngestionStatus,
  LocationOption,
  ReviewedPayload,
  UploadDocumentIngestionInput,
} from './types'

interface LocationEnvelope {
  data: LocationOption[]
}

function hasTenantScope(): boolean {
  return (
    useAuthStore.getState().user?.tenant_id !== undefined
    && useAuthStore.getState().user?.tenant_id !== null
    && useCompanyStore.getState().currentCompanyId !== null
  )
}

function shouldPoll(data: DocumentIngestionListResponse | undefined): boolean {
  return (data?.data ?? []).some((row) => row.status === 'uploaded' || row.status === 'extracting')
}

function shouldPollDetail(status: IngestionStatus | undefined): boolean {
  return status === 'uploaded' || status === 'extracting' || status === 'committing'
}

export function useDocumentIngestions(params: ListDocumentIngestionsParams) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['document-ingestions', 'list', params]),
    queryFn: () => listDocumentIngestions(params),
    enabled: tenantId !== null && companyId !== null,
    refetchInterval: (query) => (shouldPoll(query.state.data) ? 5000 : false),
  })
}

export function useDocumentIngestion(id: string | undefined) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['document-ingestions', 'detail', id]),
    queryFn: () => getDocumentIngestion(id ?? ''),
    enabled: id !== undefined && id !== '' && tenantId !== null && companyId !== null,
    refetchInterval: (query) => (shouldPollDetail(query.state.data?.status) ? 4000 : false),
  })
}

export function useLocationsForIngestion(enabled: boolean) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['locations']),
    queryFn: async () => {
      const response = await api.get<LocationEnvelope>('/locations')
      return response.data.data
    },
    enabled: enabled && tenantId !== null && companyId !== null,
  })
}

export function useUploadDocumentIngestion() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: UploadDocumentIngestionInput) => uploadDocumentIngestion(input),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: ['document-ingestions'],
      })
    },
  })
}

export function useReExtractDocumentIngestion(id: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => reExtractDocumentIngestion(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: ['document-ingestions'],
      })
    },
  })
}

export function useRejectDocumentIngestion(id: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => rejectDocumentIngestion(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: ['document-ingestions'],
      })
    },
  })
}

export function useCommitDocumentIngestion(id: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: ReviewedPayload) => commitDocumentIngestion(id, payload),
    onSuccess: async () => {
      if (hasTenantScope()) {
        await queryClient.invalidateQueries({
          queryKey: ['document-ingestions'],
        })
      }
    },
  })
}

export const documentIngestionStatuses: readonly IngestionStatus[] = [
  'uploaded',
  'extracting',
  'needs_review',
  'committing',
  'committed',
  'rejected',
  'failed',
]
