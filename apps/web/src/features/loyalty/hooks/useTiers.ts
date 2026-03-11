import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import i18n from '@/lib/i18n'
import { getErrorMessage } from '@/lib/api'
import { listTiers, createTier, updateTier, deleteTier } from '../api/tierApi'
import type { CreateTierData, UpdateTierData } from '../types/loyalty'

const tiersKey = (programId: string) => ['loyalty-tiers', programId]

export function useTiers(programId: string) {
  return useQuery({
    queryKey: tiersKey(programId),
    queryFn: () => listTiers(programId),
    enabled: !!programId,
  })
}

export function useCreateTier(programId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (data: CreateTierData) => createTier(programId, data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: tiersKey(programId) })
      toast.success(i18n.t('loyalty:actions.created'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useUpdateTier(programId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateTierData }) => updateTier(id, data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: tiersKey(programId) })
      toast.success(i18n.t('loyalty:actions.updated'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useDeleteTier(programId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deleteTier(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: tiersKey(programId) })
      toast.success(i18n.t('loyalty:actions.deleted'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}
