import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { api, apiDelete, getErrorMessage } from '../../../lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

export interface DocumentAttachment {
  id: string
  filename: string
  original_filename: string
  mime_type: string
  file_size: number
  formatted_file_size: string
  description: string | null
  is_image: boolean
  is_pdf: boolean
  uploaded_by: {
    id: string
    name: string
  }
  created_at: string
}

export interface AttachmentConfig {
  max_file_size: number
  max_file_size_mb: number
  allowed_extensions: string[]
  allowed_mime_types: string[]
}

interface AttachmentsResponse {
  data: DocumentAttachment[]
}

interface AttachmentResponse {
  data: DocumentAttachment
  message: string
}

interface ConfigResponse {
  data: AttachmentConfig
}

/**
 * Fetch attachments for a document
 */
export function useAttachments(documentId: string | undefined) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['attachments', documentId]),
    queryFn: async () => {
      const response = await api.get<AttachmentsResponse>(
        `/documents/${documentId}/attachments`
      )
      return response.data.data
    },
    enabled: !!documentId && tenantId !== null && companyId !== null,
  })
}

/**
 * Fetch attachment configuration (allowed file types, max size)
 */
export function useAttachmentConfig() {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['attachments-config']),
    queryFn: async () => {
      const response = await api.get<ConfigResponse>('/attachments/config')
      return response.data.data
    },
    enabled: tenantId !== null && companyId !== null,
    staleTime: 1000 * 60 * 60, // 1 hour - config rarely changes
  })
}

/**
 * Upload a new attachment
 * @param documentId - The document to attach the file to
 * @param defaultRole - Optional attachment role sent to the backend (e.g. "SOURCE_DOCUMENT")
 */
export function useUploadAttachment(documentId: string, defaultRole?: string) {
  const queryClient = useQueryClient()
  useAuthStore((state) => state.user?.tenant_id ?? null)
  useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: async ({ file, description }: { file: File; description?: string }) => {
      const formData = new FormData()
      formData.append('file', file)
      if (description) {
        formData.append('description', description)
      }
      if (defaultRole) {
        formData.append('role', defaultRole)
      }

      const response = await api.post<AttachmentResponse>(
        `/documents/${documentId}/attachments`,
        formData,
        {
          headers: {
            'Content-Type': 'multipart/form-data',
          },
        }
      )
      return response.data.data
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: ['attachments', documentId],
      })
      toast.success('Attachment uploaded successfully')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

/**
 * Delete an attachment
 */
export function useDeleteAttachment(documentId: string) {
  const queryClient = useQueryClient()
  useAuthStore((state) => state.user?.tenant_id ?? null)
  useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: async (attachmentId: string) => {
      await apiDelete(`/documents/${documentId}/attachments/${attachmentId}`)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: ['attachments', documentId],
      })
      toast.success('Attachment deleted')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

/**
 * Get the download URL for an attachment
 */
export function getAttachmentDownloadUrl(documentId: string, attachmentId: string): string {
  return `/api/v1/documents/${documentId}/attachments/${attachmentId}/download`
}
