import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import {
  getAdminSupportAccess,
  approveWriteElevation,
  requestSupportAccess,
  requestWriteElevation,
  revokeAdminGrant,
  secondApproveGrant,
  startSupportSession,
} from '@/features/support-access/api/adminSupportAccessApi'
import type { RequestAccessInput } from '@/features/support-access/types'

export function useAdminSupportAccess(page = 1, perPage = 20) {
  const { t } = useTranslation('admin')
  const queryClient = useQueryClient()
  const query = useQuery({
    queryKey: ['admin', 'support-access', { page, perPage }],
    queryFn: () => getAdminSupportAccess(page, perPage),
  })
  const refresh = async () => queryClient.invalidateQueries({ queryKey: ['admin', 'support-access'] })
  const onError = () => { toast.error(t('supportAccess.errors.mutation')) }
  const requestMutation = useMutation({ mutationFn: requestSupportAccess, onSuccess: refresh, onError })
  const approveMutation = useMutation({ mutationFn: secondApproveGrant, onSuccess: refresh, onError })
  const revokeMutation = useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) => revokeAdminGrant(id, reason),
    onSuccess: refresh,
    onError,
  })
  const startMutation = useMutation({
    mutationFn: ({ grantId, subjectId }: { grantId: string; subjectId: string }) =>
      startSupportSession(grantId, subjectId),
    onSuccess: refresh,
    onError,
  })
  const elevationMutation = useMutation({
    mutationFn: ({ sessionId, reason }: { sessionId: string; reason: string }) =>
      requestWriteElevation(sessionId, reason),
    onSuccess: refresh,
    onError,
  })
  const approveElevationMutation = useMutation({ mutationFn: approveWriteElevation, onSuccess: refresh, onError })

  return {
    overview: query.data?.data,
    meta: query.data?.meta,
    isLoading: query.isLoading,
    error: query.error,
    requestAccess: (input: RequestAccessInput) => requestMutation.mutateAsync(input),
    approveGrant: (id: string) => approveMutation.mutateAsync(id),
    revokeGrant: (id: string, reason: string) => revokeMutation.mutateAsync({ id, reason }),
    startSession: (grantId: string, subjectId: string) =>
      startMutation.mutateAsync({ grantId, subjectId }),
    requestElevation: (sessionId: string, reason: string) =>
      elevationMutation.mutateAsync({ sessionId, reason }),
    approveElevation: (id: string) => approveElevationMutation.mutateAsync(id),
    isMutating: requestMutation.isPending || approveMutation.isPending || revokeMutation.isPending
      || startMutation.isPending || elevationMutation.isPending || approveElevationMutation.isPending,
  }
}
