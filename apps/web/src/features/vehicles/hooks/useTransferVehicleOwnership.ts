import { useMutation, useQueryClient } from '@tanstack/react-query'
import { transferOwnership, type TransferOwnershipPayload } from '../api/vehicleOwnershipApi'

export function useTransferVehicleOwnership(vehicleId: string | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: TransferOwnershipPayload) => {
      if (vehicleId === undefined) {
        throw new Error('vehicleId is required')
      }
      return transferOwnership(vehicleId, payload)
    },
    onSuccess: (_data, variables) => {
      void queryClient.invalidateQueries({ queryKey: ['vehicle', vehicleId] })
      void queryClient.invalidateQueries({ queryKey: ['vehicle', vehicleId, 'ownerships'] })
      void queryClient.invalidateQueries({
        queryKey: ['partner-vehicles', variables.new_owner_partner_id],
      })
    },
  })
}
