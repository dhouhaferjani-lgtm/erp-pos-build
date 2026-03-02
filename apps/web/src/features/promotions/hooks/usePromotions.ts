import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import i18n from '@/lib/i18n'
import { getErrorMessage } from '@/lib/api'
import {
  listPromotions,
  getPromotion,
  createPromotion,
  updatePromotion,
  deletePromotion,
  activatePromotion,
  pausePromotion,
  archivePromotion,
} from '../api/promotionApi'
import type { PromotionListParams, CreatePromotionData, UpdatePromotionData } from '../api/promotionApi'

const PROMOTIONS_KEY = ['promotions']

export function usePromotions(params: PromotionListParams = {}) {
  return useQuery({
    queryKey: [...PROMOTIONS_KEY, params],
    queryFn: () => listPromotions(params),
  })
}

export function usePromotion(id: string) {
  return useQuery({
    queryKey: [...PROMOTIONS_KEY, id],
    queryFn: () => getPromotion(id),
    enabled: !!id,
  })
}

export function useCreatePromotion() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (data: CreatePromotionData) => createPromotion(data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: PROMOTIONS_KEY })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useUpdatePromotion() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdatePromotionData }) => updatePromotion(id, data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: PROMOTIONS_KEY })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useDeletePromotion() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deletePromotion(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: PROMOTIONS_KEY })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useActivatePromotion() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => activatePromotion(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: PROMOTIONS_KEY })
      toast.success(i18n.t('promotions:actions.activated'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function usePausePromotion() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => pausePromotion(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: PROMOTIONS_KEY })
      toast.success(i18n.t('promotions:actions.paused'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useArchivePromotion() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => archivePromotion(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: PROMOTIONS_KEY })
      toast.success(i18n.t('promotions:actions.archived'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}
