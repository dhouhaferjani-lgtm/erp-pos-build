import { useQuery } from '@tanstack/react-query'
import { useMemo, useRef, useState, useEffect } from 'react'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import {
  previewDiscounts,
  type PreviewDiscountsRequest,
  type DiscountBreakdownData,
  type DiscountLineData,
} from '../api/discountApi'
import type { CartItem } from '../molecules/CartLineItem'
import { useCurrency } from '@/hooks/useCurrency'
import { usePosTenantScope } from './usePosTenantScope'

export interface DiscountPreviewInput {
  cartItems: CartItem[]
  subtotal: string
  manualDiscountAmount?: string | undefined
  couponCode?: string | undefined
  customerId?: string | undefined
  loyaltyDiscountAmount?: string | undefined
  loyaltyRewardId?: string | undefined
}

export interface DiscountPreviewResult {
  breakdown: DiscountBreakdownData | null
  autoPromotions: DiscountLineData[]
  couponDiscount: DiscountLineData | null
  loyaltyDiscount: DiscountLineData | null
  totalSavings: string
  isLoading: boolean
}

const DEBOUNCE_MS = 500

export function useDiscountPreview(input: DiscountPreviewInput): DiscountPreviewResult {
  const { decimals } = useCurrency()
  const { hasTenantScope } = usePosTenantScope()
  const {
    cartItems,
    subtotal,
    manualDiscountAmount,
    couponCode,
    customerId,
    loyaltyDiscountAmount,
    loyaltyRewardId,
  } = input

  // Debounce the request: only update the query params after DEBOUNCE_MS of inactivity
  const [debouncedRequest, setDebouncedRequest] = useState<PreviewDiscountsRequest | null>(null)
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const hasItems = cartItems.length > 0

  const request: PreviewDiscountsRequest | null = useMemo(() => {
    if (!hasItems) return null
    return {
      items: cartItems.map((item) => ({
        product_id: item.product.id,
        quantity: item.quantity,
        unit_price: item.unit_price,
        line_total: item.line_total,
      })),
      subtotal,
      ...(manualDiscountAmount ? { manual_discount_amount: manualDiscountAmount } : {}),
      ...(couponCode ? { coupon_code: couponCode } : {}),
      ...(customerId ? { customer_id: customerId } : {}),
      ...(loyaltyDiscountAmount ? { loyalty_discount_amount: loyaltyDiscountAmount } : {}),
      ...(loyaltyRewardId ? { loyalty_reward_id: loyaltyRewardId } : {}),
    }
  }, [hasItems, cartItems, subtotal, manualDiscountAmount, couponCode, customerId, loyaltyDiscountAmount, loyaltyRewardId])

  // Debounce updates
  useEffect(() => {
    if (timerRef.current) clearTimeout(timerRef.current)
    timerRef.current = setTimeout(() => {
      setDebouncedRequest(request)
    }, DEBOUNCE_MS)
    return () => {
      if (timerRef.current) clearTimeout(timerRef.current)
    }
  }, [request])

  const { data, isLoading } = useQuery({
    queryKey: tenantScopedKey(['pos', 'discount-preview', debouncedRequest]),
    queryFn: () => previewDiscounts(debouncedRequest!),
    enabled: !!debouncedRequest && hasTenantScope,
    // NO `placeholderData: keepPreviousData` here, deliberately. TanStack v5
    // picks the placeholder from the observer's last query that had data with NO
    // key-lineage check (`queryObserver.js` #lastQueryWithDefinedData), so it
    // hands the result back across the tenant/company suffix `tenantScopedKey`
    // appends — a promotion/coupon/loyalty amount priced under ANOTHER company's
    // rules would be shown as this cart's savings and carried into the total the
    // cashier reads. Unlike a paginated list there is no same-scope win to trade
    // for that: the key also changes on every cart edit, so a placeholder is a
    // discount computed for a DIFFERENT cart even within one company. The 500ms
    // debounce plus `staleTime` already keep the request rate down; a repeated
    // cart shape is served from cache without a flash.
    staleTime: 10_000,
  })

  const autoPromotions = useMemo(
    () => data?.lines.filter((l) => l.source === 'promotion') ?? [],
    [data],
  )

  const couponDiscount = useMemo(
    () => data?.lines.find((l) => l.source === 'coupon') ?? null,
    [data],
  )

  const loyaltyDiscount = useMemo(
    () => data?.lines.find((l) => l.source === 'loyalty') ?? null,
    [data],
  )

  const totalSavings = useMemo(() => {
    if (!data) return (0).toFixed(decimals)
    return data.lines
      .filter((l) => l.source !== 'manual')
      .reduce((sum, l) => sum + parseFloat(l.discount_amount), 0)
      .toFixed(decimals)
  }, [data, decimals])

  return {
    breakdown: data ?? null,
    autoPromotions,
    couponDiscount,
    loyaltyDiscount,
    totalSavings,
    isLoading,
  }
}
