import { useTranslation } from 'react-i18next'
import { Award, Star } from 'lucide-react'
import { cn } from '@/lib/utils'
import { textColors } from '@/lib/designTokens'
import type { LoyaltyMember, LoyaltyEnrollment } from '../api/loyaltyApi'

export interface LoyaltyMemberBadgeProps {
  member: LoyaltyMember
  enrollment: LoyaltyEnrollment
  programName?: string
  programType?: 'points' | 'stamps'
  className?: string
}

/**
 * Compact loyalty member badge for display in TransactionCart.
 * Shows member name, program name, and current balance.
 */
export function LoyaltyMemberBadge({
  member,
  enrollment,
  programName,
  programType = 'points',
  className,
}: LoyaltyMemberBadgeProps) {
  const { t } = useTranslation(['pos'])
  const displayName = [member.first_name, member.last_name].filter(Boolean).join(' ') || member.phone
  const balance = parseFloat(enrollment.current_balance)

  return (
    <div className={cn('flex items-center gap-3 p-3 bg-amber-50 border border-amber-200 rounded-lg', className)}>
      <div className="flex-shrink-0 w-8 h-8 bg-amber-100 rounded-full flex items-center justify-center">
        <Award className="w-4 h-4 text-amber-600" />
      </div>
      <div className="flex-1 min-w-0">
        <div className={cn('text-sm font-medium truncate', textColors.primary)}>
          {displayName}
        </div>
        {programName && (
          <div className="text-xs text-amber-700 truncate">{programName}</div>
        )}
      </div>
      <div className="flex items-center gap-1 flex-shrink-0">
        <Star className="w-3.5 h-3.5 text-amber-500" />
        <span className="text-sm font-semibold text-amber-700">
          {Math.floor(balance)} {programType === 'stamps'
            ? t('pos:loyalty.memberBadge.stamps')
            : t('pos:loyalty.memberBadge.points')}
        </span>
      </div>
    </div>
  )
}
