import Big from 'big.js'
import { useTranslation } from 'react-i18next'

import { DataTable, type DataTableColumn } from '@/components/molecules/DataTable'
import { borderColors, tokens, textColors } from '@/lib/designTokens'
import { formatCurrency } from '@/lib/format'
import { cn } from '@/lib/utils'

import type { Remittance, RemittanceLine } from '../hooks/useRemittances'
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
  const columns: DataTableColumn<RemittanceLine>[] = [
    { key: 'drawer', header: t('treasury:remittances.drawer'), render: (line) => line.instrument.drawer_name ?? '—' },
    { key: 'bank', header: t('treasury:remittances.draweeBank'), render: (line) => line.instrument.bank_name ?? '—' },
    { key: 'reference', header: t('treasury:remittances.reference'), render: (line) => line.instrument.reference },
    ...(slip.instrument_kind === 'effet' ? [{ key: 'maturity', header: t('treasury:remittances.maturity'), render: (line: RemittanceLine) => line.instrument.maturity_date ? new Date(line.instrument.maturity_date).toLocaleDateString() : '—' }] : []),
    { key: 'amount', header: t('treasury:remittances.amount'), numeric: true, render: (line) => formatCurrency(line.amount, { currency: line.instrument.currency }) },
  ]

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
        <DataTable columns={columns} data={slip.lines} keyExtractor={(line) => line.id} className="min-w-full text-sm" />
      </div>
      <div className={cn('flex items-center justify-between border-t px-4 py-3 font-semibold', borderColors.default)}><span>{slip.lines.length}</span><span className="tabular-nums">{formatCurrency(total, { currency })}</span></div>
    </section>
  )
}
