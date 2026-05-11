import { useQuery } from '@tanstack/react-query'
import { apiGet } from '../../../lib/api'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'

interface PartnerContact {
  id: string
  first_name: string
  last_name: string
  full_name: string
  email: string | null
  phone: string | null
  job_title: string | null
  department: string | null
  is_primary: boolean
  is_invoice_contact?: boolean
  is_delivery_contact?: boolean
}

export function usePartnerContacts(partnerId: string | undefined) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['partner-contacts', partnerId]),
    queryFn: () => apiGet<PartnerContact[]>(`/partners/${partnerId}/contacts`),
    enabled: !!partnerId && tenantId !== null && companyId !== null,
  })
}

export type { PartnerContact }
