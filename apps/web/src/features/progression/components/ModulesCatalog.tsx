import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { tokens, textColors, borderColors, colors } from '@/lib/designTokens'
import { Button, Spinner } from '@/components/atoms'
import { useModules } from '../hooks/useModuleReadiness'
import { useCompanyProfile } from '../hooks/useCompanyProgression'
import { ModulesRoadmapView } from './ModulesRoadmapView'
import { ModulesGridView } from './ModulesGridView'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

type ViewMode = 'roadmap' | 'grid'

export function ModulesCatalog() {
  const { t } = useTranslation('progression')
  const [viewMode, setViewMode] = useState<ViewMode>('roadmap')
  const modulesQuery = useModules()
  const profileQuery = useCompanyProfile()

  if (modulesQuery.isLoading || profileQuery.isLoading) {
    return (
      <div className="flex items-center justify-center py-20">
        <Spinner />
      </div>
    )
  }

  if (modulesQuery.isError) {
    return (
      <div className={`${tokens.alert.warning} flex items-center justify-between`}>
        <span>{t('dashboard.unavailable')}</span>
        <Button
          type="button"
          variant="secondary"
          size="sm"
          onClick={() => {
            void modulesQuery.refetch()
            void profileQuery.refetch()
          }}
        >
          {t('dashboard.retry')}
        </Button>
      </div>
    )
  }

  const modules = modulesQuery.data ?? []
  const currentStage = profileQuery.data?.current_stage ?? 'launch'

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <PageHeaderTitle className={`text-2xl font-bold ${textColors.primary}`}>{t('modules.title')}</PageHeaderTitle>
          <p className={`${textColors.tertiary} text-sm mt-1`}>{t('modules.subtitle')}</p>
        </div>

        {/* View toggle */}
        <div className={`flex items-center rounded-lg border ${borderColors.light} overflow-hidden`}>
          <button
            type="button"
            onClick={() => setViewMode('roadmap')}
            className={`px-3 py-1.5 text-sm font-medium transition-colors ${
              viewMode === 'roadmap'
                ? `${tokens.button.primary}`
                : `${textColors.secondary} ${colors.hover.gray50}`
            }`}
          >
            {t('modules.viewRoadmap')}
          </button>
          <button
            type="button"
            onClick={() => setViewMode('grid')}
            className={`px-3 py-1.5 text-sm font-medium transition-colors ${
              viewMode === 'grid'
                ? `${tokens.button.primary}`
                : `${textColors.secondary} ${colors.hover.gray50}`
            }`}
          >
            {t('modules.viewGrid')}
          </button>
        </div>
      </div>

      {/* Content */}
      {viewMode === 'roadmap' ? (
        <ModulesRoadmapView modules={modules} currentStage={currentStage} />
      ) : (
        <ModulesGridView modules={modules} />
      )}
    </div>
  )
}
