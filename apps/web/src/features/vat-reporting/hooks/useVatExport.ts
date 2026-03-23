import { useMutation } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { authenticatedDownload, getErrorMessage } from '@/lib/api'

export function useVatExport() {
  const { t } = useTranslation(['finance'])

  return useMutation({
    mutationFn: ({ periodId, format }: { periodId: string; format: string }) =>
      authenticatedDownload(
        `/vat/reports/${periodId}/export/${format}`,
        `vat-report-${periodId}.${format}`
      ),
    onSuccess: () => {
      toast.success(t('finance:vatReporting.toast.exported'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}
