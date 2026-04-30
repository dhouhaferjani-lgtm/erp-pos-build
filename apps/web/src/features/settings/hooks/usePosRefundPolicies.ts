import { useQuery } from '@tanstack/react-query'
import { useCompany } from '@/hooks/useCompany'
import { getPosRefundPolicies } from '../api/posRefundPoliciesApi'

export function usePosRefundPolicies() {
  const { currentCompany } = useCompany()

  return useQuery({
    queryKey: ['pos-refund-policies', currentCompany?.id],
    queryFn: () => getPosRefundPolicies(currentCompany!.id),
    enabled: !!currentCompany?.id,
  })
}
