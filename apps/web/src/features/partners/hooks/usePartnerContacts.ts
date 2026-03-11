import { useQuery } from '@tanstack/react-query'
import { apiGet } from '../../../lib/api'

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
  return useQuery({
    queryKey: ['partner-contacts', partnerId],
    queryFn: () => apiGet<PartnerContact[]>(`/partners/${partnerId}/contacts`),
    enabled: !!partnerId,
  })
}

export type { PartnerContact }
