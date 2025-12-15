import { useMutation } from '@tanstack/react-query'
import { api } from '../../../lib/api'

interface SendEmailParams {
  documentId: string
  recipientEmail?: string | undefined
  subject?: string | undefined
  message?: string | undefined
  ccEmails?: string[] | undefined
}

interface EmailResponse {
  success: boolean
  message: string
}

/**
 * Send a document via email
 */
export function useSendDocumentEmail() {
  return useMutation({
    mutationFn: async ({ documentId, ...data }: SendEmailParams): Promise<EmailResponse> => {
      const response = await api.post(`/documents/${documentId}/email`, {
        recipient_email: data.recipientEmail,
        subject: data.subject,
        message: data.message,
        cc_emails: data.ccEmails,
      })
      return response.data
    },
  })
}

/**
 * Queue a document email for later sending
 */
export function useQueueDocumentEmail() {
  return useMutation({
    mutationFn: async ({ documentId, ...data }: SendEmailParams): Promise<EmailResponse> => {
      const response = await api.post(`/documents/${documentId}/email/queue`, {
        recipient_email: data.recipientEmail,
        subject: data.subject,
        message: data.message,
        cc_emails: data.ccEmails,
      })
      return response.data
    },
  })
}
