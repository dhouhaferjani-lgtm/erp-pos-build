import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  ArrowLeft,
  Calculator,
  Package,
  Users,
  Truck,
  CheckCircle,
  Clock,
  Lock,
  ChevronRight,
  AlertCircle,
} from 'lucide-react'
import { useOpeningBatchStatus } from '../api/queries'
import { openingBatchTypeKey } from '../i18nKeys'
import type { OpeningBatchType, OpeningBatchStatusInfo, OpeningBatchStatus } from '../types'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

interface BatchTypeConfig {
  type: OpeningBatchType
  icon: React.ReactNode
  colorClass: string
  bgClass: string
}

const BATCH_TYPE_CONFIG: BatchTypeConfig[] = [
  {
    type: 'ACCOUNTING',
    icon: <Calculator className="h-6 w-6" />,
    colorClass: colorTokens.intent.primary.text,
    bgClass: colorTokens.intent.primary.bgSoft,
  },
  {
    type: 'INVENTORY',
    icon: <Package className="h-6 w-6" />,
    colorClass: colorTokens.intent.success.text,
    bgClass: colorTokens.intent.success.bgSoft,
  },
  {
    type: 'AR_OPEN_ITEMS',
    icon: <Users className="h-6 w-6" />,
    colorClass: colorTokens.intent.caution.text,
    bgClass: colorTokens.intent.caution.bgSoft,
  },
  {
    type: 'AP_OPEN_ITEMS',
    icon: <Truck className="h-6 w-6" />,
    colorClass: colorTokens.intent.accent.text,
    bgClass: colorTokens.intent.accent.bgSoft,
  },
]

function StatusBadge({ status }: { status: OpeningBatchStatusInfo }) {
  const { t } = useTranslation()

  if (!status.has_batch) {
    return (
      <span className={`inline-flex items-center gap-1 rounded-full ${colorTokens.surface.muted} px-2.5 py-0.5 text-xs font-medium ${colorTokens.text.muted}`}>
        <Clock className="h-3 w-3" />
        {t('openingBalances.status.notStarted')}
      </span>
    )
  }

  const batchStatus: OpeningBatchStatus | undefined = status.batch?.status

  if (batchStatus === 'LOCKED') {
    return (
      <span className={`inline-flex items-center gap-1 rounded-full ${colorTokens.intent.success.bgSoft} px-2.5 py-0.5 text-xs font-medium ${colorTokens.intent.success.textStrong}`}>
        <Lock className="h-3 w-3" />
        {t('openingBalances.status.locked')}
      </span>
    )
  }

  if (batchStatus === 'VALIDATED') {
    return (
      <span className={`inline-flex items-center gap-1 rounded-full ${colorTokens.intent.primary.bgSoft} px-2.5 py-0.5 text-xs font-medium ${colorTokens.intent.primary.textStrong}`}>
        <CheckCircle className="h-3 w-3" />
        {t('openingBalances.status.validated')}
      </span>
    )
  }

  return (
    <span className={`inline-flex items-center gap-1 rounded-full ${colorTokens.intent.caution.bgSoft} px-2.5 py-0.5 text-xs font-medium ${colorTokens.intent.caution.textStrong}`}>
      <AlertCircle className="h-3 w-3" />
      {t('openingBalances.status.draft')}
    </span>
  )
}

function BatchTypeCard({
  config,
  status,
}: {
  config: BatchTypeConfig
  status: OpeningBatchStatusInfo | undefined
}) {
  const { t } = useTranslation()

  const typeKey = openingBatchTypeKey(config.type)

  return (
    <Link
      to={`/settings/opening-balances/${config.type.toLowerCase()}`}
      className={`group block rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6 shadow-sm transition-all ${colorTokens.intent.primary.borderHover} hover:shadow-md`}
    >
      <div className="flex items-start justify-between">
        <div className="flex items-start gap-4">
          <div className={`rounded-lg p-3 ${config.bgClass} ${config.colorClass}`}>
            {config.icon}
          </div>
          <div>
            <h3 className={`font-semibold ${colorTokens.text.primary} ${colorTokens.intent.primary.groupTextHover}`}>
              {t(`openingBalances.types.${typeKey}.title`)}
            </h3>
            <p className={`mt-1 text-sm ${colorTokens.text.subtle}`}>
              {t(`openingBalances.types.${typeKey}.description`)}
            </p>
            {status && (
              <div className="mt-3 flex items-center gap-3">
                <StatusBadge status={status} />
                {status.batch?.rows_count != null && status.batch.rows_count > 0 && (
                  <span className={`text-xs ${colorTokens.text.subtle}`}>
                    {t('openingBalances.rowsCount', { count: status.batch.rows_count })}
                  </span>
                )}
              </div>
            )}
          </div>
        </div>
        <ChevronRight className={`h-5 w-5 ${colorTokens.text.disabled} transition-transform group-hover:translate-x-1 ${colorTokens.intent.primary.groupTextHoverSubtle}`} />
      </div>
    </Link>
  )
}

export function OpeningBalancesPage() {
  const { t } = useTranslation()
  const { data: statusData, isLoading } = useOpeningBatchStatus()

  // Create a lookup for easy access from the types object.
  const batchLookup = new Map<OpeningBatchType, OpeningBatchStatusInfo>()
  if (statusData?.types) {
    Object.values(statusData.types).forEach((s) => batchLookup.set(s.type, s))
  }

  // Calculate overall progress
  const totalTypes = BATCH_TYPE_CONFIG.length
  const statusList = statusData?.types ? Object.values(statusData.types) : []
  const lockedCount = statusList.filter((s) => s.batch?.status === 'LOCKED').length
  const progressPercent = totalTypes > 0 ? Math.round((lockedCount / totalTypes) * 100) : 0

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Link
            to="/settings"
            className={`inline-flex items-center gap-2 text-sm ${colorTokens.text.muted} ${colorTokens.intent.neutral.textHoverStrongest}`}
          >
            <ArrowLeft className="h-4 w-4" />
            {t('actions.back')}
          </Link>
          <div>
            <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary} flex items-center gap-2`}>
              <Calculator className={`h-6 w-6 ${colorTokens.intent.primary.textSubtle}`} />
              {t('openingBalances.title')}
            </PageHeaderTitle>
            <p className={colorTokens.text.subtle}>{t('openingBalances.description')}</p>
          </div>
        </div>
      </div>

      {/* Progress Overview */}
      <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
        <div className="flex items-center justify-between mb-4">
          <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
            {t('openingBalances.progress.title')}
          </h2>
          <span className={`text-sm ${colorTokens.text.subtle}`}>
            {t('openingBalances.progress.completed', { locked: lockedCount, total: totalTypes })}
          </span>
        </div>
        <div className={`h-3 w-full rounded-full ${colorTokens.surface.muted}`}>
          <div
            className={`h-3 rounded-full ${colorTokens.intent.success.bg} transition-all duration-500`}
            style={{ width: `${progressPercent}%` }}
          />
        </div>
        {progressPercent === 100 && (
          <div className={`mt-3 flex items-center gap-2 ${colorTokens.intent.success.text}`}>
            <CheckCircle className="h-5 w-5" />
            <span className="font-medium">{t('openingBalances.progress.allComplete')}</span>
          </div>
        )}
      </div>

      {/* Batch Types Grid */}
      <div className="space-y-4">
        <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
          {t('openingBalances.batchTypes')}
        </h2>
        {isLoading ? (
          <div className="grid gap-4 md:grid-cols-2">
            {BATCH_TYPE_CONFIG.map((config) => (
              <div
                key={config.type}
                className={`animate-pulse rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}
              >
                <div className="flex items-start gap-4">
                  <div className={`h-12 w-12 rounded-lg ${colorTokens.surface.subdued}`} />
                  <div className="flex-1 space-y-2">
                    <div className={`h-5 w-32 rounded ${colorTokens.surface.subdued}`} />
                    <div className={`h-4 w-48 rounded ${colorTokens.surface.subdued}`} />
                  </div>
                </div>
              </div>
            ))}
          </div>
        ) : (
          <div className="grid gap-4 md:grid-cols-2">
            {BATCH_TYPE_CONFIG.map((config) => (
              <BatchTypeCard
                key={config.type}
                config={config}
                status={batchLookup.get(config.type)}
              />
            ))}
          </div>
        )}
      </div>

      {/* How It Works */}
      <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
        <h2 className={`text-lg font-semibold ${colorTokens.text.primary} mb-2`}>
          {t('openingBalances.howItWorks.title')}
        </h2>
        <p className={`text-sm ${colorTokens.text.muted} mb-4`}>
          {t('openingBalances.howItWorks.description')}
        </p>
        <ol className={`list-decimal list-inside space-y-2 text-sm ${colorTokens.text.muted}`}>
          <li>{t('openingBalances.howItWorks.step1')}</li>
          <li>{t('openingBalances.howItWorks.step2')}</li>
          <li>{t('openingBalances.howItWorks.step3')}</li>
          <li>{t('openingBalances.howItWorks.step4')}</li>
          <li>{t('openingBalances.howItWorks.step5')}</li>
        </ol>
      </div>

      {/* Important Notes */}
      <div className={`rounded-lg ${colorTokens.intent.caution.bgSubtle} border ${colorTokens.intent.caution.borderSubtle} p-4`}>
        <h3 className={`font-medium ${colorTokens.intent.caution.textStronger} mb-2 flex items-center gap-2`}>
          <AlertCircle className="h-5 w-5" />
          {t('openingBalances.notes.title')}
        </h3>
        <ul className={`list-disc list-inside space-y-1 text-sm ${colorTokens.intent.caution.textStrong}`}>
          <li>{t('openingBalances.notes.note1')}</li>
          <li>{t('openingBalances.notes.note2')}</li>
          <li>{t('openingBalances.notes.note3')}</li>
        </ul>
      </div>
    </div>
  )
}
