import { ArrowRight } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'

import { useMaturingInstruments } from '@/features/treasury/hooks/useMaturingInstruments'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { bcadd } from '@/lib/decimal'
import { formatCurrency } from '@/lib/format'

function dateInput(date: Date): string {
  return date.toISOString().slice(0, 10)
}

export function DueThisWeekWidget() {
  const { t } = useTranslation(['reports'])
  const from = dateInput(new Date())
  const toDate = new Date()
  toDate.setDate(toDate.getDate() + 7)
  const to = dateInput(toDate)
  const query = useMaturingInstruments(from, to)
  const buckets = query.data?.meta.buckets
  const count = (buckets?.overdue.count ?? 0) + (buckets?.d0_7.count ?? 0)
  const amount = bcadd(
    bcadd(buckets?.overdue.total_in ?? '0', buckets?.overdue.total_out ?? '0'),
    bcadd(buckets?.d0_7.total_in ?? '0', buckets?.d0_7.total_out ?? '0'),
  )

  return (
    <section className={tokens.card.base} aria-labelledby="due-this-week-title">
      <h3 id="due-this-week-title" className={`text-lg font-medium ${textColors.primary}`}>{t('reports:ownerDashboard.dueThisWeek.title')}</h3>
      <p className={`mt-4 border-b pb-4 text-sm ${borderColors.light} ${textColors.secondary}`}>
        {query.data ? t('reports:ownerDashboard.dueThisWeek.summary', { count, amount: formatCurrency(amount, { currency: 'TND' }) }) : t('reports:ownerDashboard.dueThisWeek.empty')}
      </p>
      <Link to={`/treasury/instruments?maturity_from=${from}&maturity_to=${to}`} className={`mt-4 inline-flex items-center gap-1 text-sm ${textColors.brand}`}>
        {t('reports:ownerDashboard.dueThisWeek.view')} <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
      </Link>
    </section>
  )
}
