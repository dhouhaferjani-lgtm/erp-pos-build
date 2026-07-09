import { useQuery } from '@tanstack/react-query'
import { apiGet } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

/**
 * The three "cash" repository types the position covers. Mirrors
 * `CashPositionController::CASH_TYPES` on the backend — `virtual`
 * repositories (netting/suspense) are intentionally excluded there, so they
 * never appear in `groups`.
 */
export type CashPositionRepositoryType = 'cash_register' | 'bank_account' | 'safe'

export interface CashPositionRepository {
  id: string
  code: string
  name: string
  balance: string
}

export interface CashPositionGroup {
  type: CashPositionRepositoryType
  total: string
  repositories: CashPositionRepository[]
}

export interface CashPosition {
  as_of: string
  currency: string
  groups: CashPositionGroup[]
  grand_total: string
}

/**
 * Server-side cash-position aggregation (Treasury spine Task 25/27).
 *
 * Replaces the FE client-side balance summing previously computed in
 * `TreasuryOverviewPage` — `grand_total` and per-type `groups[].total` are now
 * computed server-side from `payment_repositories.balance`, the
 * port-managed/reconcile-guarded authoritative cash figure.
 */
export function useCashPosition() {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['treasury-cash-position']),
    queryFn: () => apiGet<CashPosition>('/treasury/cash-position'),
    enabled: tenantId !== null && companyId !== null,
  })
}

/**
 * Look up a cash-position group's total by repository type. Returns the
 * zero-value fallback ('0.000') when the group is absent (e.g. a tenant with
 * no safes configured never gets a 'safe' group back from the endpoint).
 */
export function cashPositionGroupTotal(
  position: CashPosition | undefined,
  type: CashPositionRepositoryType,
): string {
  return position?.groups.find((group) => group.type === type)?.total ?? '0.000'
}
