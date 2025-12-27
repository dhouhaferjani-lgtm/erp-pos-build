import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'

export interface PaymentMethod {
  id: string
  name: string
  is_physical: boolean
  has_maturity: boolean
  requires_third_party: boolean
  is_push: boolean
  has_deducted_fees: boolean
  is_restricted: boolean
  is_active: boolean
}

interface PaymentMethodsResponse {
  data: PaymentMethod[]
}

/**
 * Hook to fetch all payment methods
 *
 * @returns Query result with payment methods list
 */
export function usePaymentMethods() {
  return useQuery({
    queryKey: ['payment-methods'],
    queryFn: async () => {
      const response = await api.get<PaymentMethodsResponse>('/payment-methods')
      return response.data.data
    },
  })
}

/**
 * Hook to fetch active payment methods only
 *
 * @returns Query result with active payment methods list
 */
export function useActivePaymentMethods() {
  const { data, ...rest } = usePaymentMethods()

  return {
    ...rest,
    data: data?.filter((method) => method.is_active) ?? [],
  }
}
