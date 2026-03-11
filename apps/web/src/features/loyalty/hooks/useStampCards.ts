import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import i18n from '@/lib/i18n'
import { getErrorMessage } from '@/lib/api'
import { listStampCards, createStampCard, updateStampCard, deleteStampCard } from '../api/stampCardApi'
import type { CreateStampCardData, UpdateStampCardData } from '../types/loyalty'

const stampCardsKey = (programId: string) => ['loyalty-stamp-cards', programId]

export function useStampCards(programId: string) {
  return useQuery({
    queryKey: stampCardsKey(programId),
    queryFn: () => listStampCards(programId),
    enabled: !!programId,
  })
}

export function useCreateStampCard(programId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (data: CreateStampCardData) => createStampCard(programId, data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: stampCardsKey(programId) })
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
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: stampCardsKey(programId) })
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
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: stampCardsKey(programId) })
      toast.success(i18n.t('loyalty:actions.deleted'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}
