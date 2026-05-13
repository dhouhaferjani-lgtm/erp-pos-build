import { useMutation, useQueryClient } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { transferOwnership, type TransferOwnershipPayload } from '../api/vehicleOwnershipApi'

function partnerVehiclesPredicate(
  partnerId: string,
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 4 &&
      k[0] === 'partner-vehicles' &&
      k[1] === partnerId &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function useTransferVehicleOwnership(vehicleId: string | undefined) {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (payload: TransferOwnershipPayload) => {
      if (vehicleId === undefined) {
        throw new Error('vehicleId is required')
      }
      return transferOwnership(vehicleId, payload)
    },
    onSuccess: async (_data, variables) => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: tenantScopedKey(['vehicle', vehicleId]),
        }),
        queryClient.invalidateQueries({
          queryKey: tenantScopedKey(['vehicle', vehicleId, 'ownerships']),
        }),
        queryClient.invalidateQueries({
          predicate: partnerVehiclesPredicate(
            variables.new_owner_partner_id,
            tenantId,
            companyId,
          ),
        }),
      ])
    },
  })
}
