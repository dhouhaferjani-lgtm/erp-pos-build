import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { Button } from '@/components/atoms/Button/Button'
import { QueryError } from '@/components/QueryError'
import { useVatPeriods } from '../hooks/useVatPeriods'
import { useVatPeriodActions } from '../hooks/useVatPeriodActions'
import { VatPeriodList } from '../components/VatPeriodList'
import { VatSummaryCards } from '../components/VatSummaryCards'
import { computeYtdTotals } from '../computeYtdTotals'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

export function VatPeriodsPage() {
  const { t } = useTranslation(['finance'])
  const navigate = useNavigate()
  const [year, setYear] = useState(new Date().getFullYear())

  const { data: periods, isLoading, error, refetch } = useVatPeriods({ year })
  const { generateMutation, closeMutation, reopenMutation, fileMutation } = useVatPeriodActions()

  const periodList = periods ?? []
  const ytd = computeYtdTotals(periodList)

  const currentYear = new Date().getFullYear()
  const yearOptions = Array.from({ length: 5 }, (_, i) => currentYear - i)

  const hasOpenPeriods = periodList.some((p) => p.status === 'OPEN')

  return (
    <div className="p-6">
      {/* Header */}
      <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <PageHeaderTitle className="text-2xl font-bold">{t('finance:vatReporting.title')}</PageHeaderTitle>
          <p className={`mt-1 text-sm ${colorTokens.text.subtle}`}>{t('finance:vatReporting.subtitle')}</p>
        </div>
        <div className="flex items-center gap-3">
          <select
            value={year}
            onChange={(e) => { setYear(Number(e.target.value)); }}
            className={`rounded-md border ${colorTokens.border.default} px-3 py-2 text-sm`}
            aria-label={t('finance:vatReporting.columns.period')}
          >
            {yearOptions.map((y) => (
              <option key={y} value={y}>{y}</option>
            ))}
          </select>
          <Button
            variant="primary"
            onClick={() => { generateMutation.mutate(year); }}
            disabled={generateMutation.isPending}
          >
            {t('finance:vatReporting.generatePeriods')}
          </Button>
        </div>
      </div>

      {/* Loading */}
      {isLoading ? (
        <div className={`py-12 text-center ${colorTokens.text.subtle}`}>
          {t('finance:reports.common.loading')}
        </div>
      ) : error ? (
        <QueryError
          error={error}
          onRetry={() => { void refetch(); }}
          title={t('finance:vatReporting.title')}
        />
      ) : (
        <>
          {/* Live data notice */}
          {hasOpenPeriods && (
            <div className={`mb-4 rounded-md ${colorTokens.intent.caution.bgSubtle} border ${colorTokens.intent.caution.borderSubtle} px-4 py-3 text-sm ${colorTokens.intent.caution.textStronger}`}>
              {t('finance:vatReporting.liveData')}
            </div>
          )}

          {/* YTD Summary Cards */}
          {periodList.length > 0 && (
            <div className="mb-6">
              <VatSummaryCards
                outputVat={ytd.outputVat}
                inputVat={ytd.inputVat}
                creditBroughtForward={ytd.creditBroughtForward}
                amountPayable={ytd.amountPayable}
              />
            </div>
          )}

          {/* Period List */}
          {periodList.length > 0 ? (
            <VatPeriodList
              periods={periodList}
              onView={(id) => { void navigate(`/finance/vat-report/${id}`); }}
              onClose={(id) => { closeMutation.mutate({ id }); }}
              onReopen={(id) => { reopenMutation.mutate(id); }}
              onFile={(id) => { fileMutation.mutate({ id }); }}
              isClosing={closeMutation.isPending}
              isReopening={reopenMutation.isPending}
              isFiling={fileMutation.isPending}
            />
          ) : (
            <div className={`rounded-lg border-2 border-dashed ${colorTokens.border.default} py-12 text-center`}>
              <p className={`${colorTokens.text.subtle}`}>{t('finance:vatReporting.subtitle')}</p>
            </div>
          )}
        </>
      )}
    </div>
  )
}
