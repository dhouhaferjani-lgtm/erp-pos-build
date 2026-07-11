import Big from 'big.js'
import { useTranslation } from 'react-i18next'

import { DataTable } from '@/components/molecules/DataTable'
import { borderColors, tokens, textColors } from '@/lib/designTokens'
import { formatCurrency } from '@/lib/format'
import { cn } from '@/lib/utils'

import type { Remittance } from '../hooks/useRemittances'
import './BordereauPrintView.css'

interface BordereauPrintViewProps {
  slip: Remittance
  depositor: string
}

function formatSlipDate(remittedAt: string | null, createdAt: string | null) {
  const value = remittedAt ?? createdAt
  return value ? new Date(value).toLocaleDateString() : '—'
}

export function BordereauPrintView({ slip, depositor }: BordereauPrintViewProps) {
  const { t } = useTranslation(['common', 'treasury'])
  const total = slip.lines.reduce((sum, line) => sum.plus(line.amount), new Big(0)).toFixed(3)
  const currency = slip.lines[0]?.instrument.currency ?? 'TND'
  const repository = slip.bank_repository

  return (
    <section className={cn('bordereau-print space-y-6', tokens.card.base)} aria-label={t('treasury:remittances.bordereau')}>
      <div className="flex items-start justify-between gap-6">
        <div>
          <h2 className={tokens.heading.section}>{t('treasury:remittances.bordereau')}</h2>
          <p className={textColors.tertiary}>{slip.number}</p>
        </div>
        <dl className="grid gap-1 text-sm">
          <div><dt className="inline font-medium">{t('treasury:remittances.depositor')}: </dt><dd className="inline">{depositor}</dd></div>
          <div><dt className="inline font-medium">{t('treasury:remittances.date')}: </dt><dd className="inline">{formatSlipDate(slip.remitted_at, slip.created_at)}</dd></div>
        </dl>
      </div>
      <div className={cn('grid gap-1 text-sm', textColors.primary)}>
        <p className="font-medium">{repository.bank_name ?? repository.name}</p>
        <p>{repository.account_number ?? repository.iban ?? '—'}</p>
      </div>
      <div className="overflow-x-auto">
        <DataTable className="min-w-full border-collapse text-left text-sm">
          <thead className={tokens.table.header}>
            <tr><th className={cn('px-4 py-2 text-xs font-medium uppercase tracking-wide', textColors.tertiary)}>{t('treasury:remittances.drawer')}</th><th className={cn('px-4 py-2 text-xs font-medium uppercase tracking-wide', textColors.tertiary)}>{t('treasury:remittances.draweeBank')}</th><th className={cn('px-4 py-2 text-xs font-medium uppercase tracking-wide', textColors.tertiary)}>{t('treasury:remittances.reference')}</th>{slip.instrument_kind === 'effet' ? <th className={cn('px-4 py-2 text-xs font-medium uppercase tracking-wide', textColors.tertiary)}>{t('treasury:remittances.maturity')}</th> : null}<th className={cn('px-4 py-2 text-right text-xs font-medium uppercase tracking-wide', textColors.tertiary)}>{t('treasury:remittances.amount')}</th></tr>
          </thead>
          <tbody className={cn('divide-y', borderColors.divideDefault)}>{slip.lines.map((line) => <tr key={line.id} className={tokens.table.rowHover}><td className="px-4 py-3">{line.instrument.drawer_name ?? '—'}</td><td className="px-4 py-3">{line.instrument.bank_name ?? '—'}</td><td className="px-4 py-3">{line.instrument.reference}</td>{slip.instrument_kind === 'effet' ? <td className="px-4 py-3">{line.instrument.maturity_date ? new Date(line.instrument.maturity_date).toLocaleDateString() : '—'}</td> : null}<td className="px-4 py-3 text-right tabular-nums">{formatCurrency(line.amount, { currency: line.instrument.currency })}</td></tr>)}</tbody>
          <tfoot><tr><td className="px-4 py-3 font-semibold" colSpan={slip.instrument_kind === 'effet' ? 4 : 3}>{slip.lines.length}</td><td className="px-4 py-3 text-right font-semibold tabular-nums">{formatCurrency(total, { currency })}</td></tr></tfoot>
        </DataTable>
      </div>
    </section>
  )
}
