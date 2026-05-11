import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import i18n from '@/lib/i18n'
import { getErrorMessage } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { listStampCards, createStampCard, updateStampCard, deleteStampCard } from '../api/stampCardApi'
import type { CreateStampCardData, UpdateStampCardData } from '../types/loyalty'

export const stampCardsKey = (programId: string) => ['loyalty-stamp-cards', programId] as const

export function useStampCards(programId: string) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey([...stampCardsKey(programId)]),
    queryFn: () => listStampCards(programId),
    enabled: !!programId && !!tenantId && !!companyId,
  })
}

export function useCreateStampCard(programId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (data: CreateStampCardData) => createStampCard(programId, data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: tenantScopedKey([...stampCardsKey(programId)]) })
      toast.success(i18n.t('loyalty:actions.created'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useUpdateStampCard(programId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateStampCardData }) => updateStampCard(id, data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: tenantScopedKey([...stampCardsKey(programId)]) })
      toast.success(i18n.t('loyalty:actions.updated'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useDeleteStampCard(programId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deleteStampCard(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: tenantScopedKey([...stampCardsKey(programId)]) })
      toast.success(i18n.t('loyalty:actions.deleted'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}
