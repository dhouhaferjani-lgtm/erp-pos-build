import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { getAccounts, getAccount, createAccount, updateAccount } from '../api'
import { getErrorMessage } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import type { AccountFilters, UpdateAccountData } from '../types'

function accountsPredicate(tenantId: string | null, companyId: string | null) {
  return (q: { queryKey: readonly unknown[] }) => {
    const k = q.queryKey
    return Array.isArray(k) && k[0] === 'accounts' && k[k.length - 2] === tenantId && k[k.length - 1] === companyId
  }
}

export function useAccounts(filters?: AccountFilters) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['accounts', filters]),
    queryFn: () => getAccounts(filters),
    enabled: tenantId !== null && companyId !== null,
  })
}

export function useAccount(id: string) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['accounts', id]),
    queryFn: () => getAccount(id),
    enabled: !!id && tenantId !== null && companyId !== null,
  })
}

export function useCreateAccount() {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: createAccount,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ predicate: accountsPredicate(tenantId, companyId) })
      toast.success('Account created successfully')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useUpdateAccount() {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateAccountData }) =>
      updateAccount(id, data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ predicate: accountsPredicate(tenantId, companyId) })
      toast.success('Account updated successfully')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}
