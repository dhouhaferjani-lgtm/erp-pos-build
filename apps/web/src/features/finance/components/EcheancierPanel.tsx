import { ArrowRight } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'

import { StatusBadge } from '@/components/atoms'
import { useMaturingInstruments } from '@/features/treasury/hooks/useMaturingInstruments'
import { colors, tokens, textColors } from '@/lib/designTokens'
import { cn } from '@/lib/utils'

interface EcheancierPanelProps {
  from: string
  to: string
  formatMoney: (amount: string) => string
}

export function EcheancierPanel({ from, to, formatMoney }: EcheancierPanelProps) {
  const { t } = useTranslation(['finance', 'treasury'])
  const query = useMaturingInstruments(from, to)
  const rows = query.data?.data.slice(0, 5) ?? []
  const totals = query.data?.meta.grand_total
  const registerUrl = `/treasury/instruments?maturity_from=${from}&maturity_to=${to}`

  return <section className={tokens.card.base}>
    <div className="mb-4 flex flex-wrap items-start justify-between gap-4">
      <div><h2 className={tokens.heading.section}>{t('finance:overview.echeancier.title')}</h2><p className={cn('mt-1 text-sm', textColors.tertiary)}>{t('finance:overview.echeancier.subtitle')}</p></div>
      <Link className={cn('inline-flex items-center gap-1 text-sm font-medium', textColors.brand)} to={registerUrl}>{t('finance:overview.echeancier.openRegister')}<ArrowRight className="h-4 w-4" /></Link>
    </div>
    {query.isLoading ? <p className={textColors.tertiary}>{t('common:status.loading')}</p> : query.error ? <div className={cn(tokens.alert.base, tokens.alert.error)}>{t('common:errors.loadingFailed')}</div> : <>
      <div className="mb-4 grid gap-3 sm:grid-cols-2">
        <div className={cn('rounded-[var(--radius-card)] p-4', colors.neutral[50])}><p className={cn('text-sm', textColors.tertiary)}>{t('finance:overview.echeancier.incoming')}</p><p className={cn('mt-1 text-xl font-semibold tabular-nums', textColors.primary)}>{formatMoney(totals?.total_in ?? '0.000')}</p></div>
        <div className={cn('rounded-[var(--radius-card)] p-4', colors.neutral[50])}><p className={cn('text-sm', textColors.tertiary)}>{t('finance:overview.echeancier.outgoing')}</p><p className={cn('mt-1 text-xl font-semibold tabular-nums', textColors.primary)}>{formatMoney(totals?.total_out ?? '0.000')}</p></div>
      </div>
      {rows.length === 0 ? <p className={cn('py-4 text-center text-sm', textColors.tertiary)}>{t('finance:overview.echeancier.empty')}</p> : <div className="space-y-3">{rows.map((row) => <Link key={row.id} to={`/treasury/instruments/${row.id}`} className="flex items-center justify-between gap-4"><div className="min-w-0"><p className={cn('truncate text-sm font-medium', textColors.primary)}>{row.reference}</p><p className={cn('text-xs', textColors.tertiary)}>{row.maturity_date ? new Date(row.maturity_date).toLocaleDateString() : t('finance:overview.echeancier.atSight')}</p></div><div className="flex items-center gap-3"><StatusBadge tone={row.certainty === 'remitted' ? 'info' : 'warning'}>{t(`treasury:instruments.certainty.${row.certainty}`)}</StatusBadge><span className={cn('text-sm font-semibold tabular-nums', textColors.primary)}>{formatMoney(row.amount)}</span></div></Link>)}</div>}
    </>}
  </section>
}
