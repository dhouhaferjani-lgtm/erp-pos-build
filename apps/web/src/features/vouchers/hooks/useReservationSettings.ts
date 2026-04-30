import { useQuery } from '@tanstack/react-query'
import { useCompany } from '@/hooks/useCompany'
import { getVoucherReservationSettings } from '../api/voucherApi'

export function useReservationSettings() {
  const { currentCompany } = useCompany()

  return useQuery({
    queryKey: ['reservation-settings', currentCompany?.id],
    queryFn: () => getVoucherReservationSettings(currentCompany!.id),
    enabled: !!currentCompany?.id,
  })
}
