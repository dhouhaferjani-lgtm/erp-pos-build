import { useMutation } from '@tanstack/react-query'
import { toast } from 'sonner'
import { api, getErrorMessage } from '../../../lib/api'

/**
 * Get the PDF download URL for a document
 */
export function getPdfDownloadUrl(documentId: string): string {
  return `/api/v1/documents/${documentId}/pdf`
}

/**
 * Get the PDF preview URL for a document (inline viewing)
 */
export function getPdfPreviewUrl(documentId: string): string {
  return `/api/v1/documents/${documentId}/pdf/preview`
}

/**
 * Download a document as PDF
 */
export function useDownloadPdf() {
  return useMutation({
    mutationFn: async (documentId: string) => {
      const response = await api.get(`/documents/${documentId}/pdf`, {
        responseType: 'blob',
      })

      // Get filename from Content-Disposition header if available
      const contentDisposition = response.headers['content-disposition']
      let filename = `document-${documentId}.pdf`
      if (contentDisposition) {
        const filenameMatch = contentDisposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/)
        if (filenameMatch?.[1]) {
          filename = filenameMatch[1].replace(/['"]/g, '')
        }
      }

      // Create a download link
      const blob = new Blob([response.data], { type: 'application/pdf' })
      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = filename
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      window.URL.revokeObjectURL(url)

      return { success: true, filename }
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

/**
 * Preview a document PDF in a new tab
 */
export function usePreviewPdf() {
  return useMutation({
    mutationFn: async (documentId: string) => {
      const response = await api.get(`/documents/${documentId}/pdf/preview`, {
        responseType: 'blob',
      })

      // Create blob URL and open in new tab
      const blob = new Blob([response.data], { type: 'application/pdf' })
      const url = window.URL.createObjectURL(blob)
      window.open(url, '_blank')

      // Clean up after a generous delay to ensure the tab has fully loaded the PDF
      setTimeout(() => {
        window.URL.revokeObjectURL(url)
      }, 60_000)

      return { success: true }
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

/**
 * Print a document PDF
 *
 * Uses a hidden iframe positioned off-screen (not display:none, which
 * prevents rendering). Adds a delay before calling print() to give the
 * browser's PDF viewer time to initialise. Cleans up after the print
 * dialog is dismissed.
 */
export function usePrintPdf() {
  return useMutation({
    mutationFn: async (documentId: string) => {
      const response = await api.get(`/documents/${documentId}/pdf/preview`, {
        responseType: 'blob',
      })

      const blob = new Blob([response.data], { type: 'application/pdf' })
      const url = window.URL.createObjectURL(blob)

      const iframe = document.createElement('iframe')
      // Position off-screen instead of display:none so the PDF actually renders
      iframe.style.position = 'fixed'
      iframe.style.width = '1px'
      iframe.style.height = '1px'
      iframe.style.opacity = '0'
      iframe.style.left = '-9999px'
      iframe.style.top = '0'
      iframe.style.border = 'none'
      iframe.src = url
      document.body.appendChild(iframe)

      return new Promise<{ success: boolean }>((resolve) => {
        iframe.onload = () => {
          // Delay to let the PDF viewer plugin initialise inside the iframe
          setTimeout(() => {
            try {
              iframe.contentWindow?.focus()
              iframe.contentWindow?.print()
            } catch {
              // Cross-origin or plugin restriction — fall back to new tab
              window.open(url, '_blank')
            }

            // Clean up after a generous delay (user may still be in the dialog)
            setTimeout(() => {
              try {
                document.body.removeChild(iframe)
              } catch {
                // already removed
              }
              window.URL.revokeObjectURL(url)
            }, 60_000)

            resolve({ success: true })
          }, 500)
        }
      })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}
