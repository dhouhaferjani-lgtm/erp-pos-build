import { useQuery } from '@tanstack/react-query'
import { fetchVehiclesForPartner } from '../api/partnerVehiclesApi'

export function usePartnerVehicles(partnerId: string | undefined, perPage = 15) {
  return useQuery({
    queryKey: ['partner-vehicles', partnerId, perPage],
    queryFn: () => {
      if (partnerId === undefined) {
        throw new Error('partnerId is required')
      }
      return fetchVehiclesForPartner(partnerId, perPage)
    },
    enabled: Boolean(partnerId),
  })
}
