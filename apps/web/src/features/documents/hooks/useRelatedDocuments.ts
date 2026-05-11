import { useQuery } from '@tanstack/react-query'
import { api } from '../../../lib/api'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'

export interface RelatedDocument {
  id: string
  type: string
  document_number: string
  document_date: string
  status: string
  total: string
  currency: string
}

export interface DocumentChain {
  ancestors: RelatedDocument[]
  current: RelatedDocument
  descendants: RelatedDocument[]
}

interface RelatedDocumentsResponse {
  data: DocumentChain
  meta: {
    timestamp: string
  }
}

export function useRelatedDocuments(documentId: string | undefined) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['documents', documentId, 'related']),
    queryFn: async () => {
      const response = await api.get<RelatedDocumentsResponse>(
        `/documents/${documentId}/related`
      )
      return response.data.data
    },
    enabled: tenantId !== null && companyId !== null && !!documentId,
  })
}
