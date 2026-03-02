import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { Star } from 'lucide-react'
import { cn } from '@/lib/utils'
import { previewEarning, type CartItemForLoyalty } from '../api/loyaltyApi'

export interface EarnPointsPreviewProps {
  enrollmentId: string
  cartTotal: string
  cartItems: CartItemForLoyalty[]
  className?: string
}

/**
 * Shows "You will earn X points" banner.
 * Updates as cart changes via the previewEarning() endpoint.
 */
export function EarnPointsPreview({
  enrollmentId,
  cartTotal,
  cartItems,
  className,
}: EarnPointsPreviewProps) {
  const { t } = useTranslation(['pos'])

  const { data } = useQuery({
    queryKey: ['loyalty', 'preview-earning', enrollmentId, cartTotal, cartItems.length],
    queryFn: () => previewEarning(enrollmentId, cartTotal, cartItems),
    enabled: !!enrollmentId && parseFloat(cartTotal) > 0,
    staleTime: 5000,
  })

  if (!data?.points_to_earn || data.points_to_earn <= 0) {
    return null
  }

  return (
    <div className={cn(
      'flex items-center gap-2 px-3 py-2 bg-green-50 border border-green-200 rounded-lg',
      className,
    )}>
      <Star className="w-4 h-4 text-green-600 flex-shrink-0" />
      <span className="text-sm text-green-700">
        {t('pos:loyalty.earning.preview', { points: data.points_to_earn })}
      </span>
    </div>
  )
}
