import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { getAdminUsers, verifyUserEmail } from '../api'
import { getErrorMessage } from '@/lib/api'

export function useAdminUsers(params?: {
  search?: string
  tenant_id?: string
  email_verified?: boolean
  status?: string
}) {
  return useQuery({
    queryKey: ['admin', 'users', params],
    queryFn: () => getAdminUsers(params),
  })
}

export function useVerifyUserEmail() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ userId, notes }: { userId: string; notes?: string }) =>
      verifyUserEmail(userId, notes),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'users'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'tenants'] })
      toast.success('Email verified successfully')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}
