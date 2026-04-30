import { useQuery } from '@tanstack/react-query'
import { listVouchers, getVoucher } from '../api/voucherApi'
import type { VoucherListParams } from '../types/voucher'

export const VOUCHERS_KEY = ['vouchers'] as const

export function useVouchers(params: VoucherListParams = {}) {
  return useQuery({
    queryKey: [...VOUCHERS_KEY, params],
    queryFn: () => listVouchers(params),
  })
}

export function useVoucher(id: string) {
  return useQuery({
    queryKey: [...VOUCHERS_KEY, id],
    queryFn: () => getVoucher(id),
    enabled: !!id,
  })
}
