import { useTranslation } from 'react-i18next'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { Button, ProgressBar, Spinner } from '@/components/atoms'
import { useCompanyProfile, useMilestones } from '../hooks/useCompanyProgression'
import { useRecommendations } from '../hooks/useRecommendations'
import { StageIndicatorBadge } from './StageIndicatorBadge'
import { MilestoneItem } from './MilestoneItem'
import { RecommendationCard } from './RecommendationCard'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

export function GrowthDashboard() {
  const { t } = useTranslation('progression')
  const profileQuery = useCompanyProfile()
  const milestonesQuery = useMilestones()
  const recommendationsQuery = useRecommendations()

  const isLoading = profileQuery.isLoading || milestonesQuery.isLoading || recommendationsQuery.isLoading
  const isError = profileQuery.isError || (milestonesQuery.isError && recommendationsQuery.isError)

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-20">
        <Spinner />
      </div>
    )
  }

  if (isError) {
    return (
      <div className={`${tokens.alert.warning} flex items-center justify-between`}>
        <span>{t('dashboard.unavailable')}</span>
        <Button
          type="button"
          variant="secondary"
          size="sm"
          onClick={() => {
            void profileQuery.refetch()
            void milestonesQuery.refetch()
            void recommendationsQuery.refetch()
          }}
        >
          {t('dashboard.retry')}
        </Button>
      </div>
    )
  }

  const profile = profileQuery.data
  const milestones = milestonesQuery.data ?? []
  const recommendations = recommendationsQuery.data ?? []

  return (
    <div className="space-y-6">
      {/* Header with stage indicator */}
      {profile && (
        <div className="flex items-center justify-between">
          <div>
            <PageHeaderTitle className={`text-2xl font-bold ${textColors.primary}`}>{t('dashboard.title')}</PageHeaderTitle>
            <p className={`${textColors.tertiary} text-sm mt-1`}>{t('dashboard.subtitle')}</p>
          </div>
          <StageIndicatorBadge
            stage={profile.current_stage}
            progressPercent={profile.stage_progress_percent}
          />
        </div>
      )}

      {/* Overall progress */}
      {profile && (
        <div className={tokens.card.base}>
          <div className="flex items-center justify-between mb-2">
            <span className={`${textColors.secondary} text-sm font-medium`}>
              {t('dashboard.milestones')}
            </span>
            <span className={`${textColors.tertiary} text-xs`}>
              {profile.completed_milestones} / {profile.total_milestones}
            </span>
          </div>
          <ProgressBar
            percent={profile.total_milestones > 0
              ? (profile.completed_milestones / profile.total_milestones) * 100
              : 0}
            size="md"
            variant="success"
          />
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Milestones */}
        <div className={tokens.card.base}>
          <h2 className={`${textColors.primary} text-base font-semibold mb-3`}>
            {t('dashboard.milestones')}
          </h2>
          <div className={`divide-y ${borderColors.divideLight}`}>
            {milestones.map((milestone) => (
              <MilestoneItem key={milestone.id} milestone={milestone} />
            ))}
          </div>
        </div>

        {/* Recommendations */}
        <div className="space-y-3">
          <h2 className={`${textColors.primary} text-base font-semibold`}>
            {t('dashboard.recommendations')}
          </h2>
          {recommendations.map((rec) => (
            <RecommendationCard key={rec.id} recommendation={rec} />
          ))}
        </div>
      </div>
    </div>
  )
}
