import { useCallback, useMemo } from 'react'
import { useCompanyConfig } from '@/contexts/CompanyConfigContext'
import { useCartRecommendations } from '../hooks/useCartRecommendations'
import { InlineSmartPrompts } from '../organisms/InlineSmartPrompts'
import { ToastSmartPrompts } from '../organisms/ToastSmartPrompts'
import type { CartItem } from '../../molecules/CartLineItem'

interface SmartPromptsContainerProps {
  cartItems: CartItem[]
  customerId?: string | null
  onAddRecommendation: (productId: string) => void
}

export function SmartPromptsContainer({
  cartItems,
  customerId,
  onAddRecommendation,
}: SmartPromptsContainerProps) {
  const { config } = useCompanyConfig()
  const variant = config?.smart_prompts_variant ?? 'off'

  const productIds = useMemo(
    () => cartItems.map((item) => item.product.id),
    [cartItems],
  )

  const result = useCartRecommendations(productIds, customerId)

  const handleAdd = useCallback(
    (productId: string) => onAddRecommendation(productId),
    [onAddRecommendation],
  )

  if (!result || variant === 'off') {
    return null
  }

  const sharedProps = {
    recommendations: result.recommendations,
    contextFields: result.contextFields,
    skinType: result.skinType,
    onSkinTypeChange: result.setSkinType,
    onAdd: handleAdd,
    isLoading: result.isLoading,
  }

  if (variant === 'toast') {
    return <ToastSmartPrompts {...sharedProps} />
  }

  return <InlineSmartPrompts {...sharedProps} />
}
