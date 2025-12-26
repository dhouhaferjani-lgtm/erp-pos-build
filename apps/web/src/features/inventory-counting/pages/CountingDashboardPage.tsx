import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Plus, Activity, Clock, CheckCircle, AlertTriangle } from 'lucide-react'
import { CountingCard } from '../components/CountingCard'
import { useCountingDashboard, useSendReminder } from '../api/queries'

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
        highlight ? 'border-red-300 bg-red-50' : ''
      }`}
    >
      <Link to={href} className="block">
        <div className="flex items-center justify-between">
          <div>
            <p className="text-sm text-gray-500">{label}</p>
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
      <div className="p-8 text-center text-gray-500">
        {t('loading')}...
      </div>
    )
  }

  if (error) {
    return (
      <div className="p-8 text-center text-red-600">
        {t('error')}: {error.message}
      </div>
    )
  }

  if (!data) {
    return null
  }

  const { summary, active_counts, pending_review } = data

  const handleSendReminder = (id: number) => {
    sendReminder.mutate(id)
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">{t('counting.title')}</h1>
          <p className="text-gray-500">{t('counting.description')}</p>
        </div>
        <Link
          to="/inventory/counting/create"
          className="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700"
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
          iconClassName="text-blue-600"
        />
        <SummaryCard
          icon={Clock}
          label={t('counting.summary.pendingReview')}
          value={summary.pending_review}
          href="/inventory/counting/list?status=pending_review"
          iconClassName="text-amber-600"
        />
        <SummaryCard
          icon={CheckCircle}
          label={t('counting.summary.completedThisMonth')}
          value={summary.completed_this_month}
          href="/inventory/counting/list?status=finalized"
          iconClassName="text-green-600"
        />
        <SummaryCard
          icon={AlertTriangle}
          label={t('counting.summary.overdue')}
          value={summary.overdue}
          href="/inventory/counting/list?overdue=true"
          iconClassName="text-red-600"
          highlight={summary.overdue > 0}
        />
      </div>

      {/* Active Counts */}
      <section>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold">{t('counting.activeCounts')}</h2>
          <Link
            to="/inventory/counting/list?status=active"
            className="text-sm text-blue-600 hover:text-blue-700"
          >
            {t('viewAll')}
          </Link>
        </div>

        {active_counts.length === 0 ? (
          <div className="rounded-lg border bg-white py-8 text-center text-gray-500">
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
            className="text-sm text-blue-600 hover:text-blue-700"
          >
            {t('viewAll')}
          </Link>
        </div>

        {pending_review.length === 0 ? (
          <div className="rounded-lg border bg-white py-8 text-center text-gray-500">
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
