import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { generateVatPeriods, closeVatPeriod, reopenVatPeriod, fileVatPeriod } from '../api'
import { getErrorMessage } from '@/lib/api'

export function useVatPeriodActions() {
  const queryClient = useQueryClient()

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['vat-periods'] })
  }

  const generateMutation = useMutation({
    mutationFn: (year: number) => generateVatPeriods(year),
    onSuccess: () => {
      invalidate()
      toast.success('VAT periods generated successfully')
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
      toast.success('VAT period closed successfully')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const reopenMutation = useMutation({
    mutationFn: (id: string) => reopenVatPeriod(id),
    onSuccess: () => {
      invalidate()
      toast.success('VAT period reopened successfully')
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
      toast.success('VAT period marked as filed')
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
