import { useQuery } from '@tanstack/react-query'
import { api } from '../../../lib/api'

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
  return useQuery({
    queryKey: ['documents', documentId, 'related'],
    queryFn: async () => {
      const response = await api.get<RelatedDocumentsResponse>(
        `/documents/${documentId}/related`
      )
      return response.data.data
    },
    enabled: !!documentId,
  })
}
