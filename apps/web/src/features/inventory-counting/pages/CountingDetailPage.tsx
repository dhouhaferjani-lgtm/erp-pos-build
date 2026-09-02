import { useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { format } from 'date-fns'
import {
  ArrowLeft,
  Play,
  XCircle,
  ClipboardList,
  FileText,
  Clock,
  Users,
  Settings,
} from 'lucide-react'
import { CountingStatusBadge } from '../components/CountingStatusBadge'
import { CancelCountingDialog } from '../components/CancelCountingDialog'
import {
  useCountingDetail,
  useActivateCounting,
  useCancelCounting,
} from '../api/queries'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

export function CountingDetailPage() {
  const { t } = useTranslation('inventory')
  const { id } = useParams<{ id: string }>()
  const countingId = id ?? ''

  const { data: counting, isLoading, error } = useCountingDetail(countingId)
  const activateCounting = useActivateCounting()
  const cancelCounting = useCancelCounting()

  const [showCancelDialog, setShowCancelDialog] = useState(false)

  if (isLoading) {
    return (
      <div className={`p-8 text-center ${colorTokens.text.subtle}`}>
        {t('loading')}...
      </div>
    )
  }

  if (error || !counting) {
    return (
      <div className="p-8 text-center">
        <p className={`${colorTokens.intent.danger.text} mb-4`}>{t('error')}</p>
        <Link
          to="/inventory/counting"
          className={`${colorTokens.intent.primary.text} ${colorTokens.intent.primary.textHoverStrong}`}
        >
          {t('back')}
        </Link>
      </div>
    )
  }

  const handleActivate = () => {
    activateCounting.mutate(countingId)
  }

  const handleCancel = (reason: string) => {
    cancelCounting.mutate(
      { id: countingId, reason },
      {
        onSuccess: () => {
          setShowCancelDialog(false)
        },
      }
    )
  }

  const canActivate = counting.status === 'draft' || counting.status === 'scheduled'
  const canCancel =
    counting.status !== 'finalized' && counting.status !== 'cancelled'
  const canReview =
    counting.status === 'pending_review' ||
    counting.status.includes('completed')

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-start justify-between">
        <div>
          <Link
            to="/inventory/counting"
            className={`inline-flex items-center text-sm ${colorTokens.text.subtle} ${colorTokens.intent.neutral.textHoverStrong} mb-2`}
          >
            <ArrowLeft className="w-4 h-4 me-1" />
            {t('back')}
          </Link>
          <div className="flex items-center gap-3">
            <PageHeaderTitle className="text-2xl font-bold">
              {t(`counting.scopeTypes.${counting.scope_type}`)} {t('counting.count')}{' '}
              <span className={`${colorTokens.text.subtle} font-mono text-lg`}>
                #{counting.id.slice(0, 8)}
              </span>
            </PageHeaderTitle>
            <CountingStatusBadge status={counting.status} />
          </div>
        </div>

        {/* Actions */}
        <div className="flex gap-2">
          {canActivate && (
            <button
              type="button"
              onClick={handleActivate}
              disabled={activateCounting.isPending}
              className={`inline-flex items-center px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.success.bgStrong} rounded-md ${colorTokens.intent.success.bgStrongHover} disabled:opacity-50`}
            >
              <Play className="w-4 h-4 me-2" />
              {t('counting.actions.activate')}
            </button>
          )}
          {canReview && (
            <Link
              to={`/inventory/counting/${String(countingId)}/review`}
              className={`inline-flex items-center px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrong} rounded-md ${colorTokens.intent.primary.bgStrongHover}`}
            >
              <ClipboardList className="w-4 h-4 me-2" />
              {t('counting.actions.review')}
            </Link>
          )}
          <Link
            to={`/inventory/counting/${String(countingId)}/report`}
            className={`inline-flex items-center px-4 py-2 text-sm font-medium border ${colorTokens.border.default} rounded-md ${colorTokens.intent.neutral.bgHover}`}
          >
            <FileText className="w-4 h-4 me-2" />
            {t('counting.actions.viewReport')}
          </Link>
          {canCancel && (
            <button
              type="button"
              onClick={() => { setShowCancelDialog(true); }}
              className={`inline-flex items-center px-4 py-2 text-sm font-medium ${colorTokens.intent.danger.text} border ${colorTokens.intent.danger.borderSubtle} rounded-md ${colorTokens.intent.danger.bgHover}`}
            >
              <XCircle className="w-4 h-4 me-2" />
              {t('counting.actions.cancel')}
            </button>
          )}
        </div>
      </div>

      {/* Content Grid */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Main Info */}
        <div className="lg:col-span-2 space-y-6">
          {/* Progress */}
          <div className={`${colorTokens.surface.base} rounded-lg border p-6`}>
            <h2 className="text-lg font-semibold mb-4 flex items-center gap-2">
              <Clock className="w-5 h-5" />
              {t('counting.detail.progress')}
            </h2>

            {/* Overall Progress */}
            <div className="mb-6">
              <div className="flex justify-between text-sm mb-2">
                <span>{t('counting.overallProgress')}</span>
                <span className="font-medium">{counting.progress.overall}%</span>
              </div>
              <div className={`w-full ${colorTokens.surface.subdued} rounded-full h-3`}>
                <div
                  className={`${colorTokens.intent.primary.bgStrong} h-3 rounded-full transition-all`}
                  style={{ width: `${String(counting.progress.overall)}%` }}
                />
              </div>
            </div>

            {/* Counter Progress */}
            <div className="space-y-4">
              {counting.progress.count_1 && (
                <CounterProgressRow
                  label={t('counting.counter1')}
                  user={counting.count_1_user}
                  progress={counting.progress.count_1}
                />
              )}
              {counting.requires_count_2 && counting.progress.count_2 && (
                <CounterProgressRow
                  label={t('counting.counter2')}
                  user={counting.count_2_user}
                  progress={counting.progress.count_2}
                />
              )}
              {counting.requires_count_3 && counting.progress.count_3 && (
                <CounterProgressRow
                  label={t('counting.counter3')}
                  user={counting.count_3_user}
                  progress={counting.progress.count_3}
                />
              )}
            </div>
          </div>

          {/* Instructions */}
          {counting.instructions && (
            <div className={`${colorTokens.surface.base} rounded-lg border p-6`}>
              <h2 className="text-lg font-semibold mb-4">
                {t('counting.detail.instructions')}
              </h2>
              <p className={`${colorTokens.text.muted} whitespace-pre-wrap`}>
                {counting.instructions}
              </p>
            </div>
          )}

          {/* Assignments */}
          <div className={`${colorTokens.surface.base} rounded-lg border p-6`}>
            <h2 className="text-lg font-semibold mb-4 flex items-center gap-2">
              <Users className="w-5 h-5" />
              {t('counting.detail.assignments')}
            </h2>

            {(counting.assignments ?? []).length === 0 ? (
              <p className={colorTokens.text.subtle}>{t('counting.detail.noAssignments')}</p>
            ) : (
              <div className="space-y-3">
                {(counting.assignments ?? []).map((assignment) => (
                  <div
                    key={assignment.id}
                    className={`flex items-center justify-between p-3 ${colorTokens.surface.page} rounded-lg`}
                  >
                    <div className="flex items-center gap-3">
                      <div className={`w-10 h-10 rounded-full ${colorTokens.surface.disabled} flex items-center justify-center font-medium`}>
                        {assignment.user.name.charAt(0)}
                      </div>
                      <div>
                        <div className="font-medium">{assignment.user.name}</div>
                        <div className={`text-sm ${colorTokens.text.subtle}`}>
                          {t(`counting.counter${String(assignment.count_number)}`)}
                        </div>
                      </div>
                    </div>
                    <div className="text-end">
                      <div className="text-sm font-medium">
                        {assignment.counted_items} / {assignment.total_items}
                      </div>
                      <div className={`text-xs ${colorTokens.text.subtle}`}>
                        {assignment.progress_percentage}%
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>

        {/* Sidebar */}
        <div className="space-y-6">
          {/* Configuration */}
          <div className={`${colorTokens.surface.base} rounded-lg border p-6`}>
            <h2 className="text-lg font-semibold mb-4 flex items-center gap-2">
              <Settings className="w-5 h-5" />
              {t('counting.detail.configuration')}
            </h2>

            <dl className="space-y-3 text-sm">
              <div className="flex justify-between">
                <dt className={colorTokens.text.subtle}>{t('counting.detail.scope')}</dt>
                <dd className="font-medium">
                  {t(`counting.scopeTypes.${counting.scope_type}`)}
                </dd>
              </div>
              <div className="flex justify-between">
                <dt className={colorTokens.text.subtle}>{t('counting.detail.mode')}</dt>
                <dd className="font-medium">
                  {t(`counting.executionModes.${counting.execution_mode}`)}
                </dd>
              </div>
              <div className="flex justify-between">
                <dt className={colorTokens.text.subtle}>{t('counting.detail.countsRequired')}</dt>
                <dd className="font-medium">
                  {1 +
                    (counting.requires_count_2 ? 1 : 0) +
                    (counting.requires_count_3 ? 1 : 0)}
                </dd>
              </div>
              <div className="flex justify-between">
                <dt className={colorTokens.text.subtle}>
                  {t('counting.detail.allowUnexpected')}
                </dt>
                <dd className="font-medium">
                  {counting.allow_unexpected_items
                    ? t('yes')
                    : t('no')}
                </dd>
              </div>
              {/* N-1/A-8: the counting mode decided in the wizard was invisible
                  afterwards — an operator could not tell whether sales are
                  blocked at the counted location or running live under an
                  ambiguity window. */}
              <div className="flex justify-between">
                <dt className={colorTokens.text.subtle}>
                  {t('counting.detail.salesMode')}
                </dt>
                <dd className="font-medium text-right" data-testid="counting-sales-mode">
                  {counting.block_sales
                    ? t('counting.detail.salesModeBlocked')
                    : t('counting.detail.salesModeLive', {
                        minutes: counting.ambiguity_window_minutes,
                      })}
                </dd>
              </div>
            </dl>
          </div>

          {/* Timeline */}
          <div className={`${colorTokens.surface.base} rounded-lg border p-6`}>
            <h2 className="text-lg font-semibold mb-4">
              {t('counting.detail.timeline')}
            </h2>

            <dl className="space-y-3 text-sm">
              <div>
                <dt className={colorTokens.text.subtle}>{t('counting.detail.created')}</dt>
                <dd className="font-medium">
                  {format(new Date(counting.created_at), 'MMM d, yyyy h:mm a')}
                </dd>
              </div>
              {counting.scheduled_start && (
                <div>
                  <dt className={colorTokens.text.subtle}>
                    {t('counting.detail.scheduledStart')}
                  </dt>
                  <dd className="font-medium">
                    {format(
                      new Date(counting.scheduled_start),
                      'MMM d, yyyy h:mm a'
                    )}
                  </dd>
                </div>
              )}
              {counting.scheduled_end && (
                <div>
                  <dt className={colorTokens.text.subtle}>
                    {t('counting.detail.scheduledEnd')}
                  </dt>
                  <dd className="font-medium">
                    {format(
                      new Date(counting.scheduled_end),
                      'MMM d, yyyy h:mm a'
                    )}
                  </dd>
                </div>
              )}
              {counting.activated_at && (
                <div>
                  <dt className={colorTokens.text.subtle}>
                    {t('counting.detail.activated')}
                  </dt>
                  <dd className="font-medium">
                    {format(
                      new Date(counting.activated_at),
                      'MMM d, yyyy h:mm a'
                    )}
                  </dd>
                </div>
              )}
              {counting.finalized_at && (
                <div>
                  <dt className={colorTokens.text.subtle}>
                    {t('counting.detail.finalized')}
                  </dt>
                  <dd className="font-medium">
                    {format(
                      new Date(counting.finalized_at),
                      'MMM d, yyyy h:mm a'
                    )}
                  </dd>
                </div>
              )}
              {counting.cancelled_at && (
                <div>
                  <dt className={colorTokens.text.subtle}>
                    {t('counting.detail.cancelled')}
                  </dt>
                  <dd className={`font-medium ${colorTokens.intent.danger.text}`}>
                    {format(
                      new Date(counting.cancelled_at),
                      'MMM d, yyyy h:mm a'
                    )}
                  </dd>
                  {counting.cancellation_reason && (
                    <dd className={`text-sm ${colorTokens.text.subtle} mt-1`}>
                      {counting.cancellation_reason}
                    </dd>
                  )}
                </div>
              )}
            </dl>
          </div>

          {/* Created By */}
          <div className={`${colorTokens.surface.base} rounded-lg border p-6`}>
            <h2 className="text-lg font-semibold mb-4">
              {t('counting.detail.createdBy')}
            </h2>
            <div className="flex items-center gap-3">
              <div className={`w-10 h-10 rounded-full ${colorTokens.surface.disabled} flex items-center justify-center font-medium`}>
                {counting.created_by.name.charAt(0)}
              </div>
              <div>
                <div className="font-medium">{counting.created_by.name}</div>
                <div className={`text-sm ${colorTokens.text.subtle}`}>
                  {counting.created_by.email}
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Cancel Dialog */}
      <CancelCountingDialog
        open={showCancelDialog}
        onClose={() => { setShowCancelDialog(false); }}
        onConfirm={handleCancel}
        isLoading={cancelCounting.isPending}
      />
    </div>
  )
}

interface CounterProgressRowProps {
  label: string
  user: { name: string; avatar_url?: string } | null
  progress: { counted: number; total: number; percentage: number }
}

function CounterProgressRow({ label, user, progress }: CounterProgressRowProps) {
  const isComplete = progress.percentage === 100

  return (
    <div className="flex items-center gap-4">
      <div className={`w-8 h-8 rounded-full ${colorTokens.surface.subdued} flex items-center justify-center text-sm font-medium`}>
        {user?.name.charAt(0) ?? '?'}
      </div>
      <div className="flex-1">
        <div className="flex items-center justify-between mb-1">
          <span className="text-sm font-medium">{user?.name || label}</span>
          <span
            className={`text-sm font-medium ${
              isComplete ? colorTokens.intent.success.text : colorTokens.text.muted
            }`}
          >
            {progress.counted} / {progress.total} ({progress.percentage}%)
          </span>
        </div>
        <div className={`w-full ${colorTokens.surface.subdued} rounded-full h-2`}>
          <div
            className={`h-2 rounded-full transition-all ${
              isComplete ? colorTokens.intent.success.bg : colorTokens.intent.primary.bg
            }`}
            style={{ width: `${String(progress.percentage)}%` }}
          />
        </div>
      </div>
    </div>
  )
}
