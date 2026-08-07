import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { QueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import {
  approveTenantGrant,
  createSupportWindow,
  getTenantSupportAccess,
  rejectTenantGrant,
  revokeTenantGrant,
} from '../api/tenantSupportAccessApi'
import type { CreateSupportWindowInput } from '../types'

export const tenantSupportAccessKeys = {
  overview: () => ['support-access', 'overview'] as const,
}

export async function invalidateTenantSupportAccess(queryClient: QueryClient): Promise<void> {
  await queryClient.invalidateQueries({ queryKey: ['support-access', 'overview'] })
}

export function useTenantSupportAccess(page = 1, perPage = 25) {
  const { t } = useTranslation('support-access')
  const queryClient = useQueryClient()
  const query = useQuery({
    queryKey: tenantScopedKey([...tenantSupportAccessKeys.overview(), page, perPage]),
    queryFn: () => getTenantSupportAccess(page, perPage),
  })
  const refresh = async () => invalidateTenantSupportAccess(queryClient)
  const onError = () => { toast.error(t('errors.mutation')) }
  const createMutation = useMutation({ mutationFn: createSupportWindow, onSuccess: refresh, onError })
  const approveMutation = useMutation({ mutationFn: approveTenantGrant, onSuccess: refresh, onError })
  const rejectMutation = useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) => rejectTenantGrant(id, reason),
    onSuccess: refresh,
    onError,
  })
  const revokeMutation = useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) => revokeTenantGrant(id, reason),
    onSuccess: refresh,
    onError,
  })

  return {
    overview: query.data,
    isLoading: query.isLoading,
    error: query.error,
    createWindow: (input: CreateSupportWindowInput) => createMutation.mutateAsync(input),
    approveGrant: (id: string) => approveMutation.mutateAsync(id),
    rejectGrant: (id: string, reason: string) => rejectMutation.mutateAsync({ id, reason }),
    revokeGrant: (id: string, reason: string) => revokeMutation.mutateAsync({ id, reason }),
    isMutating: createMutation.isPending || approveMutation.isPending
      || rejectMutation.isPending || revokeMutation.isPending,
  }
}
