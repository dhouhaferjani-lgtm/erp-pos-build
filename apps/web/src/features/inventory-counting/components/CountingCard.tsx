import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { formatDistanceToNow, isPast, format } from 'date-fns'
import { Clock, AlertTriangle, Eye, Bell } from 'lucide-react'
import { CountingStatusBadge } from './CountingStatusBadge'
import type { InventoryCounting, CountingUser } from '../types'
import { cn } from '@/lib/utils'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface Props {
  counting: InventoryCounting
  onSendReminder?: (id: string) => void
}

interface CounterProgressProps {
  label: string
  user: CountingUser | null
  progress: { counted: number; total: number; percentage: number }
}

function CounterProgress({ label, user, progress }: CounterProgressProps) {
  const isComplete = progress.percentage === 100

  return (
    <div className="flex items-center gap-3">
      <div className={`w-6 h-6 rounded-full ${colorTokens.surface.subdued} flex items-center justify-center text-xs font-medium`}>
        {user?.name.charAt(0) ?? '?'}
      </div>
      <div className="flex-1 min-w-0">
        <div className="flex items-center justify-between text-xs">
          <span className="truncate">{user?.name || label}</span>
          <span
            className={cn('font-medium', isComplete && `${colorTokens.intent.success.text}`)}
          >
            {progress.percentage}%
            {isComplete && ' \u2713'}
          </span>
        </div>
        <div className={`w-full ${colorTokens.surface.subdued} rounded-full h-1.5 mt-1`}>
          <div
            className={cn(
              'h-1.5 rounded-full transition-all',
              isComplete ? `${colorTokens.intent.success.bg}` : `${colorTokens.intent.primary.bg}`
            )}
            style={{ width: `${String(progress.percentage)}%` }}
          />
        </div>
      </div>
    </div>
  )
}

export function CountingCard({ counting, onSendReminder }: Props) {
  const { t } = useTranslation('inventory')
  const isOverdue =
    counting.scheduled_end && isPast(new Date(counting.scheduled_end))

  const getScopeLabel = () => {
    return t(`counting.scopeTypes.${counting.scope_type}`)
  }

  return (
    <div
      className={cn(
        `rounded-lg border ${colorTokens.surface.base} p-4 shadow-sm transition-shadow hover:shadow-md`,
        isOverdue && `${colorTokens.intent.danger.borderSubtle} ${colorTokens.intent.danger.bgSubtleAlpha}`
      )}
    >
      {/* Header */}
      <div className="flex items-start justify-between mb-3">
        <div className="space-y-1">
          <h3 className="text-lg font-semibold flex items-center gap-2">
            {getScopeLabel()} {t('counting.count')}
            {isOverdue && (
              <span className={`inline-flex items-center text-sm font-medium ${colorTokens.intent.danger.text}`}>
                <AlertTriangle className="w-4 h-4 me-1" />
                {t('counting.overdue')}
              </span>
            )}
          </h3>
          <p className={`text-sm ${colorTokens.text.subtle}`}>#{counting.id.slice(0, 8)}</p>
        </div>
        <CountingStatusBadge status={counting.status} />
      </div>

      {/* Overall Progress */}
      <div className="mb-4">
        <div className="flex justify-between text-sm mb-1">
          <span>{t('counting.overallProgress')}</span>
          <span className="font-medium">{counting.progress.overall}%</span>
        </div>
        <div className={`w-full ${colorTokens.surface.subdued} rounded-full h-2`}>
          <div
            className={`${colorTokens.intent.primary.bgStrong} h-2 rounded-full transition-all`}
            style={{ width: `${String(counting.progress.overall)}%` }}
          />
        </div>
      </div>

      {/* Counter Progress */}
      <div className="space-y-2 mb-4">
        {counting.progress.count_1 && (
          <CounterProgress
            label={t('counting.counter1')}
            user={counting.count_1_user}
            progress={counting.progress.count_1}
          />
        )}
        {counting.progress.count_2 && (
          <CounterProgress
            label={t('counting.counter2')}
            user={counting.count_2_user}
            progress={counting.progress.count_2}
          />
        )}
        {counting.progress.count_3 && (
          <CounterProgress
            label={t('counting.counter3')}
            user={counting.count_3_user}
            progress={counting.progress.count_3}
          />
        )}
      </div>

      {/* Deadline */}
      {counting.scheduled_end && (
        <div className={`flex items-center text-sm ${colorTokens.text.subtle} mb-4`}>
          <Clock className="w-4 h-4 me-2" />
          <span>
            {t('counting.deadline')}:{' '}
            {format(new Date(counting.scheduled_end), 'MMM d, yyyy h:mm a')}
            {!isOverdue && (
              <span className="ms-1">
                (
                {formatDistanceToNow(new Date(counting.scheduled_end), {
                  addSuffix: true,
                })}
                )
              </span>
            )}
          </span>
        </div>
      )}

      {/* Actions */}
      <div className="flex gap-2 pt-2 border-t">
        <Link
          to={`/inventory/counting/${String(counting.id)}`}
          className={`flex-1 inline-flex items-center justify-center px-3 py-2 text-sm font-medium rounded-md border ${colorTokens.border.default} ${colorTokens.surface.base} ${colorTokens.intent.neutral.bgHover}`}
        >
          <Eye className="w-4 h-4 me-2" />
          {t('viewDetails')}
        </Link>
        {onSendReminder && (
          <button
            type="button"
            onClick={() => { onSendReminder(counting.id); }}
            className={`inline-flex items-center justify-center px-3 py-2 text-sm font-medium rounded-md border ${colorTokens.border.default} ${colorTokens.surface.base} ${colorTokens.intent.neutral.bgHover}`}
          >
            <Bell className="w-4 h-4" />
          </button>
        )}
      </div>
    </div>
  )
}
