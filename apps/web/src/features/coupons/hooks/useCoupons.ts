import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import i18n from '@/lib/i18n'
import { getErrorMessage } from '@/lib/api'
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

const COUPONS_KEY = ['coupons']

export function useCoupons(params: CouponListParams = {}) {
  return useQuery({
    queryKey: [...COUPONS_KEY, params],
    queryFn: () => listCoupons(params),
  })
}

export function useCoupon(id: string) {
  return useQuery({
    queryKey: [...COUPONS_KEY, id],
    queryFn: () => getCoupon(id),
    enabled: !!id,
  })
}

export function useCreateCoupon() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (data: CreateCouponData) => createCoupon(data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: COUPONS_KEY })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useUpdateCoupon() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateCouponData }) => updateCoupon(id, data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: COUPONS_KEY })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useDeleteCoupon() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deleteCoupon(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: COUPONS_KEY })
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
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => revokeCoupon(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: COUPONS_KEY })
      toast.success(i18n.t('coupons:actions.revoked'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useReactivateCoupon() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => reactivateCoupon(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: COUPONS_KEY })
      toast.success(i18n.t('coupons:actions.reactivated'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}
