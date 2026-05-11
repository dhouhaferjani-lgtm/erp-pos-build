import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { generateVatPeriods, closeVatPeriod, reopenVatPeriod, fileVatPeriod } from '../api'
import { getErrorMessage } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

export function useVatPeriodActions() {
  const { t } = useTranslation(['finance'])
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const invalidate = async () => {
    await queryClient.invalidateQueries({
      predicate: (q) => {
        const k = q.queryKey
        return Array.isArray(k) && k[0] === 'vat-periods' && k[k.length - 2] === tenantId && k[k.length - 1] === companyId
      },
    })
  }

  const generateMutation = useMutation({
    mutationFn: (year: number) => generateVatPeriods(year),
    onSuccess: async () => {
      await invalidate()
      toast.success(t('finance:vatReporting.toast.generated'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const closeMutation = useMutation({
    mutationFn: ({ id, notes }: { id: string; notes?: string }) => closeVatPeriod(id, notes),
    onSuccess: async (_data, { id }) => {
      await Promise.all([
        invalidate(),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['vat-report', id]) }),
      ])
      toast.success(t('finance:vatReporting.toast.closed'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const reopenMutation = useMutation({
    mutationFn: (id: string) => reopenVatPeriod(id),
    onSuccess: async () => {
      await invalidate()
      toast.success(t('finance:vatReporting.toast.reopened'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const fileMutation = useMutation({
    mutationFn: ({ id, filingReference }: { id: string; filingReference?: string }) =>
      fileVatPeriod(id, filingReference),
    onSuccess: async () => {
      await invalidate()
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
