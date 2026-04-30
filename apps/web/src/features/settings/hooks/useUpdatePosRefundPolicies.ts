import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useCompany } from '@/hooks/useCompany'
import { updatePosRefundPolicies } from '../api/posRefundPoliciesApi'
import type { PosRefundPolicies } from '../types/posRefundPolicies'

export function useUpdatePosRefundPolicies() {
  const { currentCompany } = useCompany()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: Partial<PosRefundPolicies>) => {
      if (!currentCompany?.id) throw new Error('No company selected')
      return updatePosRefundPolicies(currentCompany.id, payload)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: ['pos-refund-policies', currentCompany?.id],
      })
      // Also invalidate the shared reservation-settings key used by voucher feature
      void queryClient.invalidateQueries({
        queryKey: ['reservation-settings', currentCompany?.id],
      })
    },
  })
}
