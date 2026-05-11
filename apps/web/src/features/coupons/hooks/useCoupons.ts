import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import i18n from '@/lib/i18n'
import { getErrorMessage } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import {
  listCoupons,
  getCoupon,
  createCoupon,
  updateCoupon,
  deleteCoupon,
  validateCoupon,
  revokeCoupon,
  reactivateCoupon,
} from '../api/couponApi'
import type { CouponListParams, CreateCouponData, UpdateCouponData, ValidateCouponRequest } from '../api/couponApi'

export const COUPONS_KEY = ['coupons'] as const

/**
 * Tenant-scoped predicate matching ANY [coupons, ...] queryKey for the
 * given tenant + company. tenantScopedKey() suffixes t/c on leaf keys,
 * so a fixed wrap tenantScopedKey([...COUPONS_KEY]) = [coupons, t, c]
 * is NOT a prefix of leaf list/detail keys [coupons, params|id, t, c].
 * Predicate-based invalidation sidesteps the positional mismatch.
 */
export function couponsInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 3 &&
      k[0] === 'coupons' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function useCoupons(params: CouponListParams = {}) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...COUPONS_KEY, params]),
    queryFn: () => listCoupons(params),
    enabled: !!tenantId && !!companyId,
  })
}

export function useCoupon(id: string) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...COUPONS_KEY, id]),
    queryFn: () => getCoupon(id),
    enabled: !!id && !!tenantId && !!companyId,
  })
}

export function useCreateCoupon() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (data: CreateCouponData) => createCoupon(data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: couponsInvalidationPredicate(tenantId, companyId),
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useUpdateCoupon() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateCouponData }) => updateCoupon(id, data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: couponsInvalidationPredicate(tenantId, companyId),
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useDeleteCoupon() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deleteCoupon(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: couponsInvalidationPredicate(tenantId, companyId),
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useValidateCoupon() {
  return useMutation({
    mutationFn: (data: ValidateCouponRequest) => validateCoupon(data),
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useRevokeCoupon() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => revokeCoupon(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: couponsInvalidationPredicate(tenantId, companyId),
      })
      toast.success(i18n.t('coupons:actions.revoked'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useReactivateCoupon() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => reactivateCoupon(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: couponsInvalidationPredicate(tenantId, companyId),
      })
      toast.success(i18n.t('coupons:actions.reactivated'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}
