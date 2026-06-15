import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { Star } from 'lucide-react'
import { cn } from '@/lib/utils'
import { textColors, colors, borderColors } from '@/lib/designTokens'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
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
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const { data } = useQuery({
    queryKey: tenantScopedKey(['loyalty', 'preview-earning', enrollmentId, cartTotal, cartItems.length]),
    queryFn: () => previewEarning(enrollmentId, cartTotal, cartItems),
    enabled: tenantId !== null && companyId !== null && !!enrollmentId && parseFloat(cartTotal) > 0,
    staleTime: 5000,
  })

  if (!data?.points_to_earn || data.points_to_earn <= 0) {
    return null
  }

  return (
    <div className={cn(
      'flex items-center gap-2 px-3 py-2 border rounded-lg',
      colors.success[50],
      borderColors.success,
      className,
    )}>
      <Star className={cn('w-4 h-4 flex-shrink-0', textColors.success)} />
      <span className={cn('text-sm', textColors.success)}>
        {t('pos:loyalty.earning.preview', { points: data.points_to_earn })}
      </span>
    </div>
  )
}
