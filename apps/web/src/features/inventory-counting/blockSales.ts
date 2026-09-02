import type { CountingScopeType } from './types'

/**
 * The ONLY scopes for which "block sales during this count" is actually
 * enforced (gate r1 FE IMPORTANT-1).
 *
 * `CountingBlockService::activeBlockFor` queries
 * `whereIn('scope_type', [location, full_inventory, product_location])` and its
 * `scopeCoversLocation()` returns false by default, so for any other scope no
 * terminal ever receives `active_counting_block`: the till keeps selling the
 * counted items AND no late sale is flagged (flagging is gated on an active
 * block). Labelling such a count "Blocked" would state a guarantee the system
 * does not keep, so both the wizard and the detail page read this list.
 *
 * The backend refuses `block_sales:true` for `product`/`category`/`zone` at
 * creation (`CreateCountingRequest::validateScopeFilters`); this list also
 * covers rows persisted before that rule existed.
 */
export const BLOCK_SALES_ENFORCED_SCOPES: readonly CountingScopeType[] = [
  'location',
  'full_inventory',
  'product_location',
]

export function isBlockSalesEnforced(scopeType: CountingScopeType | undefined): boolean {
  return scopeType !== undefined && BLOCK_SALES_ENFORCED_SCOPES.includes(scopeType)
}
