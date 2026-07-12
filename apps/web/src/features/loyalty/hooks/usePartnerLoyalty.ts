import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import i18n from '@/lib/i18n'
import { getErrorMessage } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { getPartnerLoyaltySummary, enrollPartner } from '../api/partnerLoyaltyApi'
import type { EnrollPartnerData, PartnerLoyaltySummary } from '../api/partnerLoyaltyApi'

export function usePartnerLoyalty(partnerId: string, enabled: boolean) {
  return useQuery({
    queryKey: tenantScopedKey(['loyalty', 'partner', partnerId]),
    queryFn: () => getPartnerLoyaltySummary(partnerId),
    enabled: enabled && partnerId.length > 0,
  })
}

export function useEnrollPartner(partnerId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: EnrollPartnerData) => enrollPartner(partnerId, data),
    onSuccess: async (data: PartnerLoyaltySummary) => {
      queryClient.setQueryData(tenantScopedKey(['loyalty', 'partner', partnerId]), data)
      await queryClient.invalidateQueries({
        queryKey: ['loyalty', 'partner', partnerId],
      })
      toast.success(i18n.t('loyalty:partnerCard.enrollSuccess'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}
