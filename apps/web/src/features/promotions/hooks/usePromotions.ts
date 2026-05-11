import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import i18n from '@/lib/i18n'
import { getErrorMessage } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
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

export const PROMOTIONS_KEY = ['promotions'] as const

/**
 * Tenant-scoped predicate matching ANY [promotions, ...] queryKey for the
 * given tenant + company. tenantScopedKey() puts t/c at the SUFFIX, so a
 * fixed wrap like tenantScopedKey([...PROMOTIONS_KEY]) = [promotions, t, c]
 * is NOT a prefix of leaf list/detail keys [promotions, params|id, t, c].
 * Predicate-based invalidation sidesteps the positional mismatch.
 */
export function promotionsInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 3 &&
      k[0] === 'promotions' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function usePromotions(params: PromotionListParams = {}) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...PROMOTIONS_KEY, params]),
    queryFn: () => listPromotions(params),
    enabled: !!tenantId && !!companyId,
  })
}

export function usePromotion(id: string) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...PROMOTIONS_KEY, id]),
    queryFn: () => getPromotion(id),
    enabled: !!id && !!tenantId && !!companyId,
  })
}

export function useCreatePromotion() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (data: CreatePromotionData) => createPromotion(data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: promotionsInvalidationPredicate(tenantId, companyId),
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useUpdatePromotion() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdatePromotionData }) => updatePromotion(id, data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: promotionsInvalidationPredicate(tenantId, companyId),
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useDeletePromotion() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deletePromotion(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: promotionsInvalidationPredicate(tenantId, companyId),
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useActivatePromotion() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => activatePromotion(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: promotionsInvalidationPredicate(tenantId, companyId),
      })
      toast.success(i18n.t('promotions:actions.activated'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function usePausePromotion() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => pausePromotion(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: promotionsInvalidationPredicate(tenantId, companyId),
      })
      toast.success(i18n.t('promotions:actions.paused'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useArchivePromotion() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => archivePromotion(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: promotionsInvalidationPredicate(tenantId, companyId),
      })
      toast.success(i18n.t('promotions:actions.archived'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}
