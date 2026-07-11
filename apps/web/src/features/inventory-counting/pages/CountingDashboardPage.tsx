import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Plus, Activity, Clock, CheckCircle, AlertTriangle } from 'lucide-react'
import { CountingCard } from '../components/CountingCard'
import { useCountingDashboard, useSendReminder } from '../api/queries'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

interface SummaryCardProps {
  icon: React.ComponentType<{ className?: string }>
  label: string
  value: number
  href: string
  iconClassName?: string
  highlight?: boolean
}

function SummaryCard({
  icon: Icon,
  label,
  value,
  href,
  iconClassName,
  highlight = false,
}: SummaryCardProps) {
  return (
    <div
      className={`rounded-lg border bg-white p-6 ${
        highlight ? `${colorTokens.intent.danger.borderSubtle} ${colorTokens.intent.danger.bgSubtle}` : ''
      }`}
    >
      <Link to={href} className="block">
        <div className="flex items-center justify-between">
          <div>
            <p className={`text-sm ${colorTokens.text.subtle}`}>{label}</p>
            <p className="text-3xl font-bold">{value}</p>
          </div>
          <Icon className={`w-8 h-8 ${iconClassName ?? ''}`} />
        </div>
      </Link>
    </div>
  )
}

export function CountingDashboardPage() {
  const { t } = useTranslation('inventory')
  const { data, isLoading, error } = useCountingDashboard()
  const sendReminder = useSendReminder()

  if (isLoading) {
    return (
      <div className={`p-8 text-center ${colorTokens.text.subtle}`}>
        {t('loading')}...
      </div>
    )
  }

  if (error) {
    return (
      <div className={`p-8 text-center ${colorTokens.intent.danger.text}`}>
        {t('error')}: {error.message}
      </div>
    )
  }

  if (!data) {
    return null
  }

  const { summary, active_counts, pending_review } = data

  const handleSendReminder = (id: string) => {
    sendReminder.mutate(id)
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <PageHeaderTitle className="text-2xl font-bold">{t('counting.title')}</PageHeaderTitle>
          <p className={colorTokens.text.subtle}>{t('counting.description')}</p>
        </div>
        <Link
          to="/inventory/counting/create"
          className={`inline-flex items-center px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrong} rounded-md ${colorTokens.intent.primary.bgStrongHover}`}
        >
          <Plus className="w-4 h-4 me-2" />
          {t('counting.new')}
        </Link>
      </div>

      {/* Summary Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <SummaryCard
          icon={Activity}
          label={t('counting.summary.active')}
          value={summary.active}
          href="/inventory/counting/list?status=active"
          iconClassName={colorTokens.intent.primary.text}
        />
        <SummaryCard
          icon={Clock}
          label={t('counting.summary.pendingReview')}
          value={summary.pending_review}
          href="/inventory/counting/list?status=pending_review"
          iconClassName={colorTokens.intent.caution.text}
        />
        <SummaryCard
          icon={CheckCircle}
          label={t('counting.summary.completedThisMonth')}
          value={summary.completed_this_month}
          href="/inventory/counting/list?status=finalized"
          iconClassName={colorTokens.intent.success.text}
        />
        <SummaryCard
          icon={AlertTriangle}
          label={t('counting.summary.overdue')}
          value={summary.overdue}
          href="/inventory/counting/list?overdue=true"
          iconClassName={colorTokens.intent.danger.text}
          highlight={summary.overdue > 0}
        />
      </div>

      {/* Active Counts */}
      <section>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold">{t('counting.activeCounts')}</h2>
          <Link
            to="/inventory/counting/list?status=active"
            className={`text-sm ${colorTokens.intent.primary.text} ${colorTokens.intent.primary.textHoverStrong}`}
          >
            {t('counting.viewAll')}
          </Link>
        </div>

        {active_counts.length === 0 ? (
          <div className={`rounded-lg border ${colorTokens.surface.base} py-8 text-center ${colorTokens.text.subtle}`}>
            {t('counting.noActiveCounts')}
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {active_counts.map((counting) => (
              <CountingCard
                key={counting.id}
                counting={counting}
                onSendReminder={handleSendReminder}
              />
            ))}
          </div>
        )}
      </section>

      {/* Pending Review */}
      <section>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold">{t('counting.pendingReview')}</h2>
          <Link
            to="/inventory/counting/list?status=pending_review"
            className={`text-sm ${colorTokens.intent.primary.text} ${colorTokens.intent.primary.textHoverStrong}`}
          >
            {t('counting.viewAll')}
          </Link>
        </div>

        {pending_review.length === 0 ? (
          <div className={`rounded-lg border ${colorTokens.surface.base} py-8 text-center ${colorTokens.text.subtle}`}>
            {t('counting.noPendingReview')}
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {pending_review.map((counting) => (
              <CountingCard key={counting.id} counting={counting} />
            ))}
          </div>
        )}
      </section>
    </div>
  )
}
