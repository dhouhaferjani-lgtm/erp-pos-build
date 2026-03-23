import { useMutation } from '@tanstack/react-query'
import { toast } from 'sonner'
import { authenticatedDownload, getErrorMessage } from '@/lib/api'

export function useVatExport() {
  return useMutation({
    mutationFn: ({ periodId, format }: { periodId: string; format: string }) =>
      authenticatedDownload(
        `/vat/reports/${periodId}/export/${format}`,
        `vat-report-${periodId}.${format}`
      ),
    onSuccess: () => {
      toast.success('Export downloaded successfully')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}
