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

      // Clean up after a delay to ensure the tab has opened
      setTimeout(() => {
        window.URL.revokeObjectURL(url)
      }, 1000)

      return { success: true }
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

/**
 * Print a document PDF
 */
export function usePrintPdf() {
  return useMutation({
    mutationFn: async (documentId: string) => {
      const response = await api.get(`/documents/${documentId}/pdf/preview`, {
        responseType: 'blob',
      })

      // Create blob URL
      const blob = new Blob([response.data], { type: 'application/pdf' })
      const url = window.URL.createObjectURL(blob)

      // Create an iframe for printing
      const iframe = document.createElement('iframe')
      iframe.style.display = 'none'
      iframe.src = url
      document.body.appendChild(iframe)

      iframe.onload = () => {
        iframe.contentWindow?.print()
        // Clean up after printing
        setTimeout(() => {
          document.body.removeChild(iframe)
          window.URL.revokeObjectURL(url)
        }, 1000)
      }

      return { success: true }
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}
