import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useAgedReceivables } from '../hooks/useAgedReceivables'
import { QueryError } from '@/components/QueryError'
import { PageHeader } from '../../../components/molecules/PageHeader'
import { Button } from '../../../components/atoms/Button/Button'
import { FormField } from '../../../components/atoms/FormField/FormField'
import { Input } from '../../../components/atoms/Input/Input'
import { EntityLink } from '../../../components/molecules/EntityLink'
import { tokens, textColors, borderColors } from '../../../lib/designTokens'
import { cn } from '../../../lib/utils'
import { useCompany } from '../../../hooks/useCompany'
import {
  formatReportCurrency,
  getTodayDateInputValue,
} from './reportPageUtils'
import type { AgedReceivablesLine } from '../types'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

export function AgedReceivablesPage() {
  const { t } = useTranslation(['finance'])
  const { currentCompany } = useCompany()
  const [asOfDate, setAsOfDate] = useState<string>(() => getTodayDateInputValue())

  const { data: receivablesData, isLoading, error, refetch } = useAgedReceivables({
    as_of_date: asOfDate,
  })

  const lines = receivablesData?.lines ?? []

  const formatMoney = (amount: string) => formatReportCurrency(amount, currentCompany)

  const totals = {
    current: receivablesData?.total_current ?? '0',
    days_30: receivablesData?.total_days_30 ?? '0',
    days_60: receivablesData?.total_days_60 ?? '0',
    days_90: receivablesData?.total_days_90 ?? '0',
    over_90: receivablesData?.total_over_90 ?? '0',
    total: receivablesData?.grand_total ?? '0',
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
        title={t('finance:reports.agedReceivablesReport.title')}
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
      ) : error ? (
        <QueryError
          error={error}
          onRetry={() => {
            void refetch()
          }}
          title={t('finance:reports.agedReceivablesReport.loadError')}
        />
      ) : (
        <div className="overflow-x-auto">
          <DataTable className={cn('min-w-full divide-y', borderColors.divideDefault)}>
            <thead className={tokens.table.header}>
              <tr>
                <th className={headCellStart}>
                  {t('finance:reports.agedReceivablesReport.customer')}
                </th>
                <th className={headCellNum}>
                  {t('finance:reports.agedReceivablesReport.current')}
                </th>
                <th className={headCellNum}>
                  {t('finance:reports.agedReceivablesReport.days30')}
                </th>
                <th className={headCellNum}>
                  {t('finance:reports.agedReceivablesReport.days60')}
                </th>
                <th className={headCellNum}>
                  {t('finance:reports.agedReceivablesReport.days90')}
                </th>
                <th className={headCellNum}>
                  {t('finance:reports.agedReceivablesReport.over90')}
                </th>
                <th className={headCellNum}>
                  {t('finance:reports.common.total')}
                </th>
              </tr>
            </thead>
            <tbody className={cn('divide-y bg-white', borderColors.divideDefault)}>
              {lines.map((line: AgedReceivablesLine) => (
                <tr key={line.customer_id}>
                  <td className={nameCell}>
                    <EntityLink
                      type="customer"
                      id={line.customer_id}
                      label={line.customer_name}
                    />
                  </td>
                  <td className={numCell}>
                    {formatMoney(line.current)}
                  </td>
                  <td className={numCell}>
                    {formatMoney(line.days_30)}
                  </td>
                  <td className={numCell}>
                    {formatMoney(line.days_60)}
                  </td>
                  <td className={numCell}>
                    {formatMoney(line.days_90)}
                  </td>
                  <td className={numCell}>
                    {formatMoney(line.over_90)}
                  </td>
                  <td className={cn(numCell, 'font-medium')}>
                    {formatMoney(line.total)}
                  </td>
                </tr>
              ))}
              {/* Totals Row */}
              <tr className={cn(tokens.table.header, 'font-bold')}>
                <td className={nameCell}>{t('finance:reports.common.total')}</td>
                <td className={numCell}>
                  {formatMoney(totals.current)}
                </td>
                <td className={numCell}>
                  {formatMoney(totals.days_30)}
                </td>
                <td className={numCell}>
                  {formatMoney(totals.days_60)}
                </td>
                <td className={numCell}>
                  {formatMoney(totals.days_90)}
                </td>
                <td className={numCell}>
                  {formatMoney(totals.over_90)}
                </td>
                <td className={numCell}>
                  {formatMoney(totals.total)}
                </td>
              </tr>
            </tbody>
          </DataTable>
        </div>
      )}
    </div>
  )
}
