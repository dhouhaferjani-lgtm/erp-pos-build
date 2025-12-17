import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { api, apiDelete, getErrorMessage } from '../../../lib/api'

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
  return useQuery({
    queryKey: ['attachments', documentId],
    queryFn: async () => {
      const response = await api.get<AttachmentsResponse>(
        `/documents/${documentId}/attachments`
      )
      return response.data.data
    },
    enabled: !!documentId,
  })
}

/**
 * Fetch attachment configuration (allowed file types, max size)
 */
export function useAttachmentConfig() {
  return useQuery({
    queryKey: ['attachments-config'],
    queryFn: async () => {
      const response = await api.get<ConfigResponse>('/attachments/config')
      return response.data.data
    },
    staleTime: 1000 * 60 * 60, // 1 hour - config rarely changes
  })
}

/**
 * Upload a new attachment
 */
export function useUploadAttachment(documentId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({ file, description }: { file: File; description?: string }) => {
      const formData = new FormData()
      formData.append('file', file)
      if (description) {
        formData.append('description', description)
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
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['attachments', documentId] })
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

  return useMutation({
    mutationFn: async (attachmentId: string) => {
      await apiDelete(`/documents/${documentId}/attachments/${attachmentId}`)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['attachments', documentId] })
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
