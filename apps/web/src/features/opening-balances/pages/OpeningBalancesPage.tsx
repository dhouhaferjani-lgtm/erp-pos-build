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
    colorClass: 'text-blue-600',
    bgClass: 'bg-blue-100',
  },
  {
    type: 'INVENTORY',
    icon: <Package className="h-6 w-6" />,
    colorClass: 'text-green-600',
    bgClass: 'bg-green-100',
  },
  {
    type: 'AR_OPEN_ITEMS',
    icon: <Users className="h-6 w-6" />,
    colorClass: 'text-amber-600',
    bgClass: 'bg-amber-100',
  },
  {
    type: 'AP_OPEN_ITEMS',
    icon: <Truck className="h-6 w-6" />,
    colorClass: 'text-purple-600',
    bgClass: 'bg-purple-100',
  },
]

function StatusBadge({ status }: { status: OpeningBatchStatusInfo }) {
  const { t } = useTranslation()

  if (!status.has_batch) {
    return (
      <span className="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600">
        <Clock className="h-3 w-3" />
        {t('openingBalances.status.notStarted')}
      </span>
    )
  }

  const batchStatus: OpeningBatchStatus | undefined = status.batch?.status

  if (batchStatus === 'LOCKED') {
    return (
      <span className="inline-flex items-center gap-1 rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-700">
        <Lock className="h-3 w-3" />
        {t('openingBalances.status.locked')}
      </span>
    )
  }

  if (batchStatus === 'VALIDATED') {
    return (
      <span className="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-700">
        <CheckCircle className="h-3 w-3" />
        {t('openingBalances.status.validated')}
      </span>
    )
  }

  return (
    <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-700">
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
      className="group block rounded-lg border border-gray-200 bg-white p-6 shadow-sm transition-all hover:border-blue-300 hover:shadow-md"
    >
      <div className="flex items-start justify-between">
        <div className="flex items-start gap-4">
          <div className={`rounded-lg p-3 ${config.bgClass} ${config.colorClass}`}>
            {config.icon}
          </div>
          <div>
            <h3 className="font-semibold text-gray-900 group-hover:text-blue-600">
              {t(`openingBalances.types.${typeKey}.title`)}
            </h3>
            <p className="mt-1 text-sm text-gray-500">
              {t(`openingBalances.types.${typeKey}.description`)}
            </p>
            {status && (
              <div className="mt-3 flex items-center gap-3">
                <StatusBadge status={status} />
                {status.batch?.rows_count != null && status.batch.rows_count > 0 && (
                  <span className="text-xs text-gray-500">
                    {t('openingBalances.rowsCount', { count: status.batch.rows_count })}
                  </span>
                )}
              </div>
            )}
          </div>
        </div>
        <ChevronRight className="h-5 w-5 text-gray-400 transition-transform group-hover:translate-x-1 group-hover:text-blue-500" />
      </div>
    </Link>
  )
}

export function OpeningBalancesPage() {
  const { t } = useTranslation()
  const { data: statusData, isLoading } = useOpeningBatchStatus()

  // Create a map for easy lookup from the types object
  const statusMap = new Map<OpeningBatchType, OpeningBatchStatusInfo>()
  if (statusData?.types) {
    Object.values(statusData.types).forEach((s) => statusMap.set(s.type, s))
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
            className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
          >
            <ArrowLeft className="h-4 w-4" />
            {t('actions.back')}
          </Link>
          <div>
            <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
              <Calculator className="h-6 w-6 text-blue-500" />
              {t('openingBalances.title')}
            </h1>
            <p className="text-gray-500">{t('openingBalances.description')}</p>
          </div>
        </div>
      </div>

      {/* Progress Overview */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold text-gray-900">
            {t('openingBalances.progress.title')}
          </h2>
          <span className="text-sm text-gray-500">
            {t('openingBalances.progress.completed', { locked: lockedCount, total: totalTypes })}
          </span>
        </div>
        <div className="h-3 w-full rounded-full bg-gray-100">
          <div
            className="h-3 rounded-full bg-green-500 transition-all duration-500"
            style={{ width: `${progressPercent}%` }}
          />
        </div>
        {progressPercent === 100 && (
          <div className="mt-3 flex items-center gap-2 text-green-600">
            <CheckCircle className="h-5 w-5" />
            <span className="font-medium">{t('openingBalances.progress.allComplete')}</span>
          </div>
        )}
      </div>

      {/* Batch Types Grid */}
      <div className="space-y-4">
        <h2 className="text-lg font-semibold text-gray-900">
          {t('openingBalances.batchTypes')}
        </h2>
        {isLoading ? (
          <div className="grid gap-4 md:grid-cols-2">
            {BATCH_TYPE_CONFIG.map((config) => (
              <div
                key={config.type}
                className="animate-pulse rounded-lg border border-gray-200 bg-white p-6"
              >
                <div className="flex items-start gap-4">
                  <div className="h-12 w-12 rounded-lg bg-gray-200" />
                  <div className="flex-1 space-y-2">
                    <div className="h-5 w-32 rounded bg-gray-200" />
                    <div className="h-4 w-48 rounded bg-gray-200" />
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
                status={statusMap.get(config.type)}
              />
            ))}
          </div>
        )}
      </div>

      {/* How It Works */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <h2 className="text-lg font-semibold text-gray-900 mb-2">
          {t('openingBalances.howItWorks.title')}
        </h2>
        <p className="text-sm text-gray-600 mb-4">
          {t('openingBalances.howItWorks.description')}
        </p>
        <ol className="list-decimal list-inside space-y-2 text-sm text-gray-600">
          <li>{t('openingBalances.howItWorks.step1')}</li>
          <li>{t('openingBalances.howItWorks.step2')}</li>
          <li>{t('openingBalances.howItWorks.step3')}</li>
          <li>{t('openingBalances.howItWorks.step4')}</li>
          <li>{t('openingBalances.howItWorks.step5')}</li>
        </ol>
      </div>

      {/* Important Notes */}
      <div className="rounded-lg bg-amber-50 border border-amber-200 p-4">
        <h3 className="font-medium text-amber-800 mb-2 flex items-center gap-2">
          <AlertCircle className="h-5 w-5" />
          {t('openingBalances.notes.title')}
        </h3>
        <ul className="list-disc list-inside space-y-1 text-sm text-amber-700">
          <li>{t('openingBalances.notes.note1')}</li>
          <li>{t('openingBalances.notes.note2')}</li>
          <li>{t('openingBalances.notes.note3')}</li>
        </ul>
      </div>
    </div>
  )
}
