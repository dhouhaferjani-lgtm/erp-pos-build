import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { generateVatPeriods, closeVatPeriod, reopenVatPeriod, fileVatPeriod } from '../api'
import { getErrorMessage } from '@/lib/api'

export function useVatPeriodActions() {
  const { t } = useTranslation(['finance'])
  const queryClient = useQueryClient()

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['vat-periods'] })
  }

  const generateMutation = useMutation({
    mutationFn: (year: number) => generateVatPeriods(year),
    onSuccess: () => {
      invalidate()
      toast.success(t('finance:vatReporting.toast.generated'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const closeMutation = useMutation({
    mutationFn: ({ id, notes }: { id: string; notes?: string }) => closeVatPeriod(id, notes),
    onSuccess: () => {
      invalidate()
      void queryClient.invalidateQueries({ queryKey: ['vat-report'] })
      toast.success(t('finance:vatReporting.toast.closed'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const reopenMutation = useMutation({
    mutationFn: (id: string) => reopenVatPeriod(id),
    onSuccess: () => {
      invalidate()
      toast.success(t('finance:vatReporting.toast.reopened'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const fileMutation = useMutation({
    mutationFn: ({ id, filingReference }: { id: string; filingReference?: string }) =>
      fileVatPeriod(id, filingReference),
    onSuccess: () => {
      invalidate()
      toast.success(t('finance:vatReporting.toast.filed'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  return {
    generateMutation,
    closeMutation,
    reopenMutation,
    fileMutation,
  }
}
