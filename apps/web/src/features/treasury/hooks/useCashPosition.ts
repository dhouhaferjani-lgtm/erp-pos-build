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
  flows?: {
    window_days: number
    in: string
    out: string
  }
}

interface CashPositionOptions {
  flowsWindow?: number
}

/**
 * Server-side cash-position aggregation (Treasury spine Task 25/27).
 *
 * Replaces the FE client-side balance summing previously computed in
 * `TreasuryOverviewPage` — `grand_total` and per-type `groups[].total` are now
 * computed server-side from `payment_repositories.balance`, the
 * port-managed/reconcile-guarded authoritative cash figure.
 */
export function useCashPosition(options?: CashPositionOptions) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const queryKey = options?.flowsWindow === undefined
    ? ['treasury-cash-position']
    : ['treasury-cash-position', options.flowsWindow]

  return useQuery({
    queryKey: tenantScopedKey(queryKey),
    queryFn: () => options?.flowsWindow === undefined
      ? apiGet<CashPosition>('/treasury/cash-position')
      : apiGet<CashPosition>('/treasury/cash-position', { flows_window: options.flowsWindow }),
    enabled: tenantId !== null && companyId !== null,
  })
}

/**
 * Look up a cash-position group's total by repository type.
 *
 * Final-review fix wave: the comment here previously claimed the endpoint
 * only emits groups that have repositories, which is WRONG. `CashPositionController`
 * always emits all three groups (cash_register/bank_account/safe) — a tenant
 * with no safes configured still gets a 'safe' group back, with `total: '0.000'`
 * and an empty `repositories` array. `.find()` above can therefore never
 * actually miss; the `?? '0.000'` fallback is defensive-only, guarding against
 * a future contract change rather than today's documented behavior.
 */
export function cashPositionGroupTotal(
  position: CashPosition | undefined,
  type: CashPositionRepositoryType,
): string {
  return position?.groups.find((group) => group.type === type)?.total ?? '0.000'
}
