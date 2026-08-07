import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useAgedPayables } from '../hooks/useAgedPayables'
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
import type { AgedPayablesLine } from '../types'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

export function AgedPayablesPage() {
  const { t } = useTranslation(['finance'])
  const { currentCompany } = useCompany()
  const [asOfDate, setAsOfDate] = useState<string>(() => getTodayDateInputValue())

  const { data: payablesData, isLoading, error, refetch } = useAgedPayables({
    as_of_date: asOfDate,
  })

  const lines = payablesData?.lines ?? []

  const formatMoney = (amount: string) => formatReportCurrency(amount, currentCompany)

  const totals = {
    current: payablesData?.total_current ?? '0',
    days_30: payablesData?.total_days_30 ?? '0',
    days_60: payablesData?.total_days_60 ?? '0',
    days_90: payablesData?.total_days_90 ?? '0',
    over_90: payablesData?.total_over_90 ?? '0',
    total: payablesData?.grand_total ?? '0',
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

      {payablesData?.buckets_by_location ? (
        <section className={cn('mb-6 rounded-lg border p-4', borderColors.light)} aria-label={t('finance:reports.locationBreakdown')}>
          <p className={cn('text-sm', textColors.tertiary)}>{t('finance:reports.defaultAttributedCaveat')}</p>
          <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            {payablesData.buckets_by_location.map((bucket) => (
              <div key={bucket.location_id ?? 'unattributed'} className="flex items-center justify-between gap-3 text-sm">
                <span className={textColors.secondary}>{bucket.location_name}</span>
                <span className={cn('font-medium tabular-nums', textColors.primary)}>{formatMoney(bucket.total)}</span>
              </div>
            ))}
          </div>
        </section>
      ) : null}

      {/* Report */}
      {isLoading ? (
        <div className={textColors.tertiary}>{t('finance:reports.common.loading')}</div>
      ) : error ? (
        <QueryError
          error={error}
          onRetry={() => {
            void refetch()
          }}
          title={t('finance:reports.agedPayablesReport.loadError')}
        />
      ) : (
        <div className="overflow-x-auto">
          <DataTable className={cn('min-w-full divide-y', borderColors.divideDefault)}>
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
