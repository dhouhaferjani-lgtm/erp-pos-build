import { useQuery } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { getVoucherReservationSettings } from '../api/voucherApi'

export function useReservationSettings() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['reservation-settings']),
    queryFn: () => getVoucherReservationSettings(companyId!),
    enabled: !!tenantId && !!companyId,
  })
}
