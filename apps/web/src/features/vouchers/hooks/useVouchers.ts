import { useQuery } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { listVouchers, getVoucher } from '../api/voucherApi'
import type { VoucherListParams } from '../types/voucher'

export const VOUCHERS_KEY = ['vouchers'] as const

/**
 * Tenant-scoped predicate matching ANY [vouchers, ...] queryKey for the
 * given tenant + company. tenantScopedKey() suffixes t/c on leaf keys,
 * so a fixed wrap tenantScopedKey([...VOUCHERS_KEY]) = [vouchers, t, c]
 * is NOT a prefix of leaf list/detail keys [vouchers, params|id, t, c].
 * Predicate-based invalidation sidesteps the positional mismatch.
 */
export function vouchersInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 3 &&
      k[0] === 'vouchers' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function useVouchers(params: VoucherListParams = {}) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...VOUCHERS_KEY, params]),
    queryFn: () => listVouchers(params),
    enabled: !!tenantId && !!companyId,
  })
}

export function useVoucher(id: string) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...VOUCHERS_KEY, id]),
    queryFn: () => getVoucher(id),
    enabled: !!id && !!tenantId && !!companyId,
  })
}
