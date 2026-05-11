import { useQuery } from '@tanstack/react-query'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useCompanyConfig } from '@/contexts/CompanyConfigContext'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { usePosTenantScope } from '../../hooks/usePosTenantScope'
import { smartPromptsApi } from '../api/smartPromptsApi'
import { verticalContextFields } from '../config/verticalContextFields'
import type { Recommendation } from '../types/recommendations'
import type { ContextFieldConfig } from '../config/verticalContextFields'

const SUPPORTED_VERTICALS = ['parapharmacy']
const DEBOUNCE_MS = 300

export interface UseCartRecommendationsResult {
  recommendations: Recommendation[]
  isLoading: boolean
  contextFields: ContextFieldConfig[]
  skinType: string | null
  setSkinType: (value: string | null) => void
}

export function useCartRecommendations(
  productIds: string[],
  customerId?: string | null,
): UseCartRecommendationsResult | null {
  const { config } = useCompanyConfig()
  const { hasTenantScope } = usePosTenantScope()
  const [skinType, setSkinType] = useState<string | null>(null)

  const vertical = config?.vertical ?? ''
  const isSupported = SUPPORTED_VERTICALS.includes(vertical)
  const isEnabled = config?.smart_prompts_enabled === true

  // Debounce product IDs
  const [debouncedIds, setDebouncedIds] = useState<string[]>([])
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const sortedIds = useMemo(() => [...productIds].sort().join(','), [productIds])

  useEffect(() => {
    if (timerRef.current) clearTimeout(timerRef.current)
    timerRef.current = setTimeout(() => {
      setDebouncedIds(sortedIds ? sortedIds.split(',') : [])
    }, DEBOUNCE_MS)

    return () => {
      if (timerRef.current) clearTimeout(timerRef.current)
    }
  }, [sortedIds])

  const queryEnabled = isSupported && isEnabled && debouncedIds.length > 0 && hasTenantScope

  const { data, isLoading } = useQuery({
    queryKey: tenantScopedKey(['smart-prompts', 'recommendations', debouncedIds, skinType, customerId]),
    queryFn: () =>
      smartPromptsApi.getRecommendations({
        product_ids: debouncedIds,
        context: 'cart',
        limit: 5,
        skin_type: skinType,
        customer_id: customerId ?? null,
      }),
    enabled: queryEnabled,
    staleTime: 2 * 60 * 1000,
    retry: false,
  })

  if (!isSupported || !isEnabled) {
    return null
  }

  const contextFields = verticalContextFields[vertical] ?? []

  return {
    recommendations: data?.recommendations ?? [],
    isLoading: queryEnabled && isLoading,
    contextFields,
    skinType,
    setSkinType,
  }
}
