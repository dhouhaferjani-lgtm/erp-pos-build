import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import {
  Users,
  Package,
  Calculator,
  Image,
  UtensilsCrossed,
  CheckCircle,
  AlertTriangle,
  Lock,
  Download,
  ArrowRight,
} from 'lucide-react'
import { cn } from '@/lib/utils'
import { authenticatedDownload } from '@/lib/api'
import type { ImportType, ImportTypeMetadata, DependencyCheck } from '../types'
import { importApi } from '../api/importApi'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

const typeIcons: Record<ImportType, React.ComponentType<{ className?: string }>> = {
  parties: Users,
  partners: Users,
  products: Package,
  stock_levels: Package,
  opening_balances: Calculator,
  product_images: Image,
  composite_items: UtensilsCrossed,
}

interface ImportTypeCardProps {
  metadata: ImportTypeMetadata
  status: { imported: number; total: number } | null
  dependencies: DependencyCheck | null
  isLoading?: boolean
}

export function ImportTypeCard({
  metadata,
  status,
  dependencies,
  isLoading,
}: ImportTypeCardProps) {
  const { t } = useTranslation('import')
  const Icon = typeIcons[metadata.type]

  const isCompleted = status && status.imported > 0
  const isLocked = dependencies && !dependencies.can_import

  const handleDownloadTemplate = async () => {
    await authenticatedDownload(
      importApi.downloadTemplateUrl(metadata.type),
      `${metadata.type}_template.csv`
    )
  }

  return (
    <div
      className={cn(
        `rounded-lg border ${colorTokens.surface.base} p-6 transition-all`,
        isLocked && 'opacity-60',
        !isLocked && 'hover:shadow-md'
      )}
    >
      <div className="flex items-start justify-between">
        <div className="flex items-center gap-3">
          <div
            className={cn(
              'rounded-lg p-3',
              isCompleted ? `${colorTokens.intent.success.bgSoft}` : `${colorTokens.intent.primary.bgSoft}`
            )}
          >
            <Icon
              className={cn(
                'h-6 w-6',
                isCompleted ? `${colorTokens.intent.success.text}` : `${colorTokens.intent.primary.text}`
              )}
            />
          </div>
          <div>
            <h3 className={`font-semibold ${colorTokens.text.primary}`}>
              {t(`types.${metadata.type}.label`)}
            </h3>
            <p className={`text-sm ${colorTokens.text.subtle}`}>
              {t(`types.${metadata.type}.description`)}
            </p>
          </div>
        </div>

        {isCompleted && (
          <CheckCircle className={`h-5 w-5 ${colorTokens.intent.success.text} flex-shrink-0`} />
        )}
      </div>

      {/* Status */}
      {status && (
        <div className="mt-4 flex items-center gap-2 text-sm">
          <span className={`${colorTokens.text.subtle}`}>{t('status.imported')}:</span>
          <span className={`font-medium ${colorTokens.text.primary}`}>
            {status.imported.toLocaleString()}
          </span>
        </div>
      )}

      {/* Dependency Warning */}
      {isLocked && dependencies?.missing_dependencies.length > 0 && (
        <div className={`mt-4 flex items-start gap-2 rounded-lg ${colorTokens.intent.caution.bgSubtle} p-3`}>
          <Lock className={`h-4 w-4 ${colorTokens.intent.caution.text} flex-shrink-0 mt-0.5`} />
          <p className={`text-sm ${colorTokens.intent.caution.textStrong}`}>
            {t('status.requiresDependencies', { deps: dependencies.missing_dependencies.join(', ') })}
          </p>
        </div>
      )}

      {/* Actions */}
      <div className="mt-4 flex items-center gap-2">
        <button
          type="button"
          onClick={handleDownloadTemplate}
          disabled={isLoading}
          className={`inline-flex items-center gap-1.5 rounded-lg border ${colorTokens.border.default} ${colorTokens.surface.base} px-3 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.intent.neutral.bgHover} disabled:opacity-50`}
        >
          <Download className="h-4 w-4" />
          {t('actions.downloadTemplate')}
        </button>

        {!isLocked && (
          <Link
            to={`/settings/import/wizard/${metadata.type}`}
            className={`inline-flex items-center gap-1.5 rounded-lg ${colorTokens.intent.primary.bgStrong} px-3 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrongHover}`}
          >
            {isCompleted ? t('actions.importMore') : t('actions.startImport')}
            <ArrowRight className="h-4 w-4" />
          </Link>
        )}

        {isLocked && (
          <span className={`inline-flex items-center gap-1.5 rounded-lg ${colorTokens.surface.muted} px-3 py-2 text-sm font-medium ${colorTokens.text.subtle}`}>
            <AlertTriangle className="h-4 w-4" />
            {t('status.dependenciesRequired')}
          </span>
        )}
      </div>
    </div>
  )
}
