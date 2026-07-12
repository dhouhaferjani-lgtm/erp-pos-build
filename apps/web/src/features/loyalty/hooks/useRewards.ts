import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import i18n from '@/lib/i18n'
import { getErrorMessage } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import {
  listRewards,
  createReward,
  updateReward,
  deleteReward,
  activateReward,
  deactivateReward,
} from '../api/rewardApi'
import type { CreateRewardData, UpdateRewardData } from '../types/loyalty'

export const rewardsKey = (programId: string) => ['loyalty-rewards', programId] as const

export function useRewards(programId: string) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey([...rewardsKey(programId)]),
    queryFn: () => listRewards(programId),
    enabled: !!programId && !!tenantId && !!companyId,
  })
}

export function useCreateReward(programId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (data: CreateRewardData) => createReward(programId, data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: [...rewardsKey(programId)] })
      toast.success(i18n.t('loyalty:actions.created'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useUpdateReward(programId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateRewardData }) => updateReward(id, data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: [...rewardsKey(programId)] })
      toast.success(i18n.t('loyalty:actions.updated'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useDeleteReward(programId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deleteReward(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: [...rewardsKey(programId)] })
      toast.success(i18n.t('loyalty:actions.deleted'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useActivateReward(programId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => activateReward(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: [...rewardsKey(programId)] })
      toast.success(i18n.t('loyalty:actions.activated'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useDeactivateReward(programId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deactivateReward(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: [...rewardsKey(programId)] })
      toast.success(i18n.t('loyalty:actions.deactivated'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}
