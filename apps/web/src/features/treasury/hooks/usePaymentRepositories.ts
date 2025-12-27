import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'

export interface PaymentRepository {
  id: string
  code: string
  name: string
  type: 'cash_register' | 'safe' | 'bank_account' | 'virtual'
  is_active: boolean
  is_default: boolean
  current_balance?: string
}

interface PaymentRepositoriesResponse {
  data: PaymentRepository[]
}

/**
 * Hook to fetch all payment repositories
 *
 * @returns Query result with payment repositories list
 */
export function usePaymentRepositories() {
  return useQuery({
    queryKey: ['payment-repositories'],
    queryFn: async () => {
      const response = await api.get<PaymentRepositoriesResponse>('/payment-repositories')
      return response.data.data
    },
  })
}

/**
 * Hook to fetch active payment repositories only
 *
 * @returns Query result with active payment repositories list
 */
export function useActivePaymentRepositories() {
  const { data, ...rest } = usePaymentRepositories()

  return {
    ...rest,
    data: data?.filter((repo) => repo.is_active) ?? [],
  }
}

/**
 * Hook to fetch a single payment repository by ID
 *
 * @param id - Repository ID
 * @returns Query result with payment repository details
 */
export function usePaymentRepository(id: string | undefined) {
  return useQuery({
    queryKey: ['payment-repository', id],
    queryFn: async () => {
      if (!id) return null
      const response = await api.get<{ data: PaymentRepository }>(`/payment-repositories/${id}`)
      return response.data.data
    },
    enabled: !!id,
  })
}
