import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useCompany } from '@/hooks/useCompany'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { updatePosRefundPolicies } from '../api/posRefundPoliciesApi'
import type { PosRefundPolicies } from '../types/posRefundPolicies'

export function useUpdatePosRefundPolicies() {
  const { currentCompany } = useCompany()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const hasTenantScope = tenantId !== null && companyId !== null
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: Partial<PosRefundPolicies>) => {
      if (!currentCompany?.id || !hasTenantScope) throw new Error('No company selected')
      return updatePosRefundPolicies(currentCompany.id, payload)
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: ['pos-refund-policies', currentCompany?.id],
        }),
        // Also invalidate the shared reservation-settings key used by voucher feature
        queryClient.invalidateQueries({
          queryKey: ['reservation-settings', currentCompany?.id],
        }),
      ])
    },
  })
}
