import { useTranslation } from 'react-i18next'
import { X, CheckCircle, AlertCircle, Loader2 } from 'lucide-react'
import {
  useImportProgressStore,
  type ImportProgress,
} from '../../../stores/importProgressStore'

/**
 * Individual import progress card within the global progress panel.
 */
function ImportProgressCard({ progress }: { progress: ImportProgress }) {
  const { t } = useTranslation('import')
  const { removeImport } = useImportProgressStore()

  const isCompleted = progress.status === 'completed' || progress.status === 'failed'
  const isSuccess = progress.isSuccess
  const isFailed = progress.status === 'failed' || (isCompleted && !isSuccess && !progress.isPartialSuccess)
  const isPartial = progress.isPartialSuccess

  const getStatusIcon = () => {
    if (isFailed) {
      return <AlertCircle className="h-5 w-5 text-red-500" />
    }
    if (isSuccess) {
      return <CheckCircle className="h-5 w-5 text-green-500" />
    }
    if (isPartial) {
      return <AlertCircle className="h-5 w-5 text-yellow-500" />
    }
    return <Loader2 className="h-5 w-5 text-primary-500 animate-spin" />
  }

  const getStatusColor = () => {
    if (isFailed) return 'bg-red-500'
    if (isSuccess) return 'bg-green-500'
    if (isPartial) return 'bg-yellow-500'
    return 'bg-primary-500'
  }

  const handleDismiss = () => {
    removeImport(progress.importJobId)
  }

  return (
    <div className="bg-white rounded-lg shadow-lg border border-neutral-200 p-4 w-80">
      <div className="flex items-start justify-between gap-3">
        <div className="flex items-center gap-3 min-w-0">
          {getStatusIcon()}
          <div className="min-w-0 flex-1">
            <p className="text-sm font-medium text-neutral-900 truncate">
              {progress.originalFilename}
            </p>
            <p className="text-xs text-neutral-500">
              {t(`types.${progress.importType}.title`)}
            </p>
          </div>
        </div>
        {isCompleted && (
          <button
            onClick={handleDismiss}
            className="p-1 rounded hover:bg-neutral-100 text-neutral-400 hover:text-neutral-600"
            aria-label={t('common:close')}
          >
            <X className="h-4 w-4" />
          </button>
        )}
      </div>

      {/* Progress bar */}
      <div className="mt-3">
        <div className="flex justify-between text-xs text-neutral-500 mb-1">
          <span>
            {isCompleted
              ? isFailed
                ? t('wizard.execute.failed')
                : t('wizard.execute.completed')
              : t('wizard.execute.importing')}
          </span>
          <span>{progress.progressPercentage}%</span>
        </div>
        <div className="w-full bg-neutral-200 rounded-full h-2">
          <div
            className={`h-2 rounded-full transition-all duration-300 ${getStatusColor()}`}
            style={{ width: `${progress.progressPercentage}%` }}
          />
        </div>
      </div>

      {/* Row counts */}
      <div className="mt-2 flex gap-4 text-xs">
        <span className="text-green-600">
          {progress.successfulRows} {t('wizard.complete.imported')}
        </span>
        {progress.failedRows > 0 && (
          <span className="text-red-600">
            {progress.failedRows} {t('wizard.complete.failed')}
          </span>
        )}
      </div>

      {/* Error message */}
      {progress.errorMessage && (
        <p className="mt-2 text-xs text-red-600 line-clamp-2">
          {progress.errorMessage}
        </p>
      )}
    </div>
  )
}

/**
 * Global import progress indicator.
 *
 * Displays a floating panel in the bottom-right corner showing
 * all active import jobs and their real-time progress.
 *
 * Auto-dismisses completed imports after 5 seconds.
 */
export function GlobalImportProgress() {
  const { activeImports, hasActiveImports } = useImportProgressStore()

  if (!hasActiveImports()) {
    return null
  }

  const imports = Array.from(activeImports.values())

  return (
    <div className="fixed bottom-4 end-4 z-50 flex flex-col gap-3">
      {imports.map((progress) => (
        <ImportProgressCard key={progress.importJobId} progress={progress} />
      ))}
    </div>
  )
}
