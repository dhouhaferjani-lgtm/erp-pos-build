import { useQuery } from '@tanstack/react-query'
import { apiGet } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { usePosTenantScope } from './usePosTenantScope'

/**
 * Discount permissions response from backend
 */
export interface DiscountPermissions {
  canDiscount: boolean
  canApplyLineDiscounts: boolean
  canApplyTransactionDiscounts: boolean
  maxDiscountPercent: number
  requiresReason: boolean
  effectiveLimit: number
}

/**
 * Hook to fetch discount permissions for current terminal and cashier
 *
 * Fetches the effective discount limits based on:
 * - Terminal configuration (max_discount_percent, allow_line_discounts, allow_transaction_discounts)
 * - Cashier permissions (can_discount, max_discount_percent)
 *
 * The effective limit is the most restrictive (minimum) of terminal and cashier limits.
 *
 * @param terminalCode - The terminal code to fetch permissions for. Query is disabled when absent.
 * @returns Query result with discount permissions
 *
 * @example
 * ```tsx
 * const { permissions, isLoading, canDiscount, effectiveLimit } = useDiscountPermissions('POS01')
 *
 * if (canDiscount) {
 *   // Show discount UI
 * }
 * ```
 */
export function useDiscountPermissions(terminalCode?: string) {
  const { hasTenantScope } = usePosTenantScope()

  const { data: permissions, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['pos', 'discount-permissions', terminalCode]),
    queryFn: () => apiGet<DiscountPermissions>(`/pos/discount-permissions?terminal_code=${encodeURIComponent(terminalCode!)}`),
    enabled: !!terminalCode && hasTenantScope,
    staleTime: 5 * 60 * 1000, // Cache for 5 minutes
    retry: 1, // Only retry once
  })

  return {
    permissions,
    isLoading,
    error,
    canDiscount: permissions?.canDiscount ?? false,
    canApplyLineDiscounts: permissions?.canApplyLineDiscounts ?? false,
    canApplyTransactionDiscounts: permissions?.canApplyTransactionDiscounts ?? false,
    effectiveLimit: permissions?.effectiveLimit ?? 0,
    maxDiscountPercent: permissions?.maxDiscountPercent ?? 0,
    requiresReason: permissions?.requiresReason ?? false,
  }
}
