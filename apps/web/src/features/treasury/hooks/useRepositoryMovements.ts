import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

/**
 * Mirrors `App\Modules\Treasury\Domain\Enums\MovementDirection`.
 */
export type MovementDirection = 'in' | 'out'

/**
 * Mirrors `App\Modules\Treasury\Domain\Enums\MovementSourceType`.
 */
export type MovementSourceType =
  | 'payment'
  | 'expense'
  | 'income'
  | 'refund'
  | 'fiscal_event'
  | 'transfer'
  | 'adjustment'
  | 'opening_balance'
  | 'instrument'

/**
 * Mirrors `App\Modules\Treasury\Domain\Enums\MovementReasonCode`.
 */
export type MovementReasonCode = 'count_variance' | 'correction' | 'theft_loss' | 'other'

/**
 * One row of the append-only `repository_movements` ledger, as returned by
 * `RepositoryMovementController::index`. This is a controller-shaped array,
 * not a transformed PHP DTO, so the interface is defined locally here rather
 * than generated via `typescript:transform`.
 */
export interface RepositoryMovement {
  id: string
  direction: MovementDirection
  /** Positive numeric-string magnitude — sign comes from `direction`, never the string itself. */
  amount: string
  currency: string
  balance_after: string
  ordinal: number
  source_type: MovementSourceType
  source_id: string | null
  journal_entry_id: string | null
  reason_code: MovementReasonCode | null
  occurred_at: string
  recorded_while_frozen: boolean
}

export interface RepositoryMovementsMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface RepositoryMovementsResponse {
  data: RepositoryMovement[]
  meta: RepositoryMovementsMeta
}

export interface RepositoryMovementsFilters {
  date_from?: string
  date_to?: string
  source_type?: MovementSourceType
  direction?: MovementDirection
  page?: number
}

/**
 * Paginated drill-down over one repository's `repository_movements` ledger
 * (Treasury spine Task 26/27).
 *
 * CRITICAL (repo rule 14): this is a paginated `{data, meta}` endpoint — uses
 * `api.get` and returns `response.data` directly. `apiGet` would strip `meta`,
 * silently breaking pagination.
 */
export function useRepositoryMovements(
  repositoryId: string | undefined,
  filters: RepositoryMovementsFilters = {},
) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['repository-movements', repositoryId, filters]),
    queryFn: async () => {
      const response = await api.get<RepositoryMovementsResponse>(
        `/payment-repositories/${repositoryId ?? ''}/movements`,
        { params: filters },
      )
      return response.data
    },
    enabled: !!repositoryId && tenantId !== null && companyId !== null,
  })
}
