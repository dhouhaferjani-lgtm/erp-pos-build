import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import type { Recommendation, RecommendationPriority } from '../api/types'
import { useAcceptRecommendation, useDismissRecommendation } from '../hooks/useRecommendations'

const buttonTokens = tokens.button


interface RecommendationCardProps {
  recommendation: Recommendation
}

const priorityBorderMap: Record<RecommendationPriority, string> = {
  high: `border-l-4 ${borderColors.leftError}`,
  medium: `border-l-4 ${borderColors.leftWarning}`,
  low: `border-l-4 ${borderColors.leftPrimary}`,
}

export function RecommendationCard({ recommendation }: RecommendationCardProps) {
  const { t } = useTranslation('progression')
  const navigate = useNavigate()
  const acceptMutation = useAcceptRecommendation()
  const dismissMutation = useDismissRecommendation()

  const isActioned = recommendation.status === 'accepted' || recommendation.status === 'dismissed'

  function handleAccept() {
    acceptMutation.mutate(recommendation.id, {
      onSuccess: () => {
        if (recommendation.action_route) {
          void navigate(recommendation.action_route)
        }
      },
    })
  }

  function handleDismiss() {
    dismissMutation.mutate(recommendation.id)
  }

  if (isActioned) {
    return (
      <div className={`${tokens.card.base} opacity-60 ${priorityBorderMap[recommendation.priority]}`}>
        <p className={`${textColors.secondary} text-sm font-medium`}>{recommendation.title}</p>
        <p className={`${textColors.disabled} text-xs mt-1`}>
          {recommendation.status === 'accepted' ? t('recommendation.accepted') : t('recommendation.dismissed')}
        </p>
      </div>
    )
  }

  return (
    <div className={`${tokens.card.base} ${priorityBorderMap[recommendation.priority]}`}>
      <h4 className={`${textColors.primary} text-sm font-semibold`}>{recommendation.title}</h4>
      <p className={`${textColors.tertiary} text-sm mt-1`}>{recommendation.description}</p>
      <div className="mt-3 flex items-center gap-2">
        <button
          type="button"
          onClick={handleAccept}
          disabled={acceptMutation.isPending}
          className={`${buttonTokens.base} ${buttonTokens.primary} ${buttonTokens.sizes.sm}`}
        >
          {recommendation.action_label || t('recommendation.showMeHow')}
        </button>
        <button
          type="button"
          onClick={handleDismiss}
          disabled={dismissMutation.isPending}
          className={`${buttonTokens.base} ${buttonTokens.ghost} ${buttonTokens.sizes.sm}`}
        >
          {t('recommendation.dismiss')}
        </button>
      </div>
    </div>
  )
}
