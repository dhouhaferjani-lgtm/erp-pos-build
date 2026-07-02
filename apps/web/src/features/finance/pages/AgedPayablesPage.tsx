import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useAgedPayables } from '../hooks/useAgedPayables'
import { PageHeader } from '../../../components/molecules/PageHeader'
import { Button, FormField, Input } from '../../../components/atoms'
import { EntityLink } from '../../../components/molecules/EntityLink'
import { tokens, textColors, borderColors } from '../../../lib/designTokens'
import { cn } from '../../../lib/utils'
import type { AgedPayablesLine } from '../types'

export function AgedPayablesPage() {
  const { t } = useTranslation(['finance'])
  const [asOfDate, setAsOfDate] = useState<string>(
    new Date().toISOString().split('T')[0]
  )

  const { data: payablesData, isLoading } = useAgedPayables({
    as_of_date: asOfDate,
  })

  const lines = payablesData?.lines || []

  const formatCurrency = (amount: string | number) => {
    const num = typeof amount === 'string' ? parseFloat(amount) : amount
    return new Intl.NumberFormat('en-US', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(num)
  }

  const totals = {
    current: parseFloat(payablesData?.total_current || '0'),
    days_30: parseFloat(payablesData?.total_days_30 || '0'),
    days_60: parseFloat(payablesData?.total_days_60 || '0'),
    days_90: parseFloat(payablesData?.total_days_90 || '0'),
    over_90: parseFloat(payablesData?.total_over_90 || '0'),
    total: parseFloat(payablesData?.grand_total || '0'),
  }

  const headCell = 'px-6 py-3 text-xs font-medium uppercase tracking-wider'
  const headCellStart = cn(headCell, 'text-start', textColors.tertiary)
  const headCellNum = cn(headCell, 'text-end tabular-nums', textColors.tertiary)
  const numCell = cn(
    'whitespace-nowrap px-6 py-4 text-end tabular-nums text-sm',
    textColors.primary
  )
  const nameCell = cn(
    'whitespace-nowrap px-6 py-4 text-sm',
    textColors.primary
  )

  return (
    <div className="p-6">
      <PageHeader
        title={t('finance:reports.agedPayablesReport.title')}
        actions={
          <Button variant="secondary">
            {t('finance:reports.common.export')}
          </Button>
        }
      />

      {/* Filters */}
      <div className="mb-6">
        <FormField
          label={t('finance:reports.common.asOfDate')}
          htmlFor="as-of-date"
        >
          <Input
            id="as-of-date"
            type="date"
            value={asOfDate}
            onChange={(e) => { setAsOfDate(e.target.value); }}
          />
        </FormField>
      </div>

      {/* Report */}
      {isLoading ? (
        <div className={textColors.tertiary}>{t('finance:reports.common.loading')}</div>
      ) : (
        <div className="overflow-x-auto">
          <table className={cn('min-w-full divide-y', borderColors.divideDefault)}>
            <thead className={tokens.table.header}>
              <tr>
                <th className={headCellStart}>
                  {t('finance:reports.agedPayablesReport.vendor')}
                </th>
                <th className={headCellNum}>
                  {t('finance:reports.agedPayablesReport.current')}
                </th>
                <th className={headCellNum}>
                  {t('finance:reports.agedPayablesReport.days30')}
                </th>
                <th className={headCellNum}>
                  {t('finance:reports.agedPayablesReport.days60')}
                </th>
                <th className={headCellNum}>
                  {t('finance:reports.agedPayablesReport.days90')}
                </th>
                <th className={headCellNum}>
                  {t('finance:reports.agedPayablesReport.over90')}
                </th>
                <th className={headCellNum}>
                  {t('finance:reports.common.total')}
                </th>
              </tr>
            </thead>
            <tbody className={cn('divide-y bg-white', borderColors.divideDefault)}>
              {lines.map((line: AgedPayablesLine) => (
                <tr key={line.vendor_id}>
                  <td className={nameCell}>
                    <EntityLink
                      type="supplier"
                      id={line.vendor_id}
                      label={line.vendor_name}
                    />
                  </td>
                  <td className={numCell}>
                    {formatCurrency(line.current)}
                  </td>
                  <td className={numCell}>
                    {formatCurrency(line.days_30)}
                  </td>
                  <td className={numCell}>
                    {formatCurrency(line.days_60)}
                  </td>
                  <td className={numCell}>
                    {formatCurrency(line.days_90)}
                  </td>
                  <td className={numCell}>
                    {formatCurrency(line.over_90)}
                  </td>
                  <td className={cn(numCell, 'font-medium')}>
                    {formatCurrency(line.total)}
                  </td>
                </tr>
              ))}
              {/* Totals Row */}
              <tr className={cn(tokens.table.header, 'font-bold')}>
                <td className={nameCell}>{t('finance:reports.common.total')}</td>
                <td className={numCell}>
                  {formatCurrency(totals.current)}
                </td>
                <td className={numCell}>
                  {formatCurrency(totals.days_30)}
                </td>
                <td className={numCell}>
                  {formatCurrency(totals.days_60)}
                </td>
                <td className={numCell}>
                  {formatCurrency(totals.days_90)}
                </td>
                <td className={numCell}>
                  {formatCurrency(totals.over_90)}
                </td>
                <td className={numCell}>
                  {formatCurrency(totals.total)}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
