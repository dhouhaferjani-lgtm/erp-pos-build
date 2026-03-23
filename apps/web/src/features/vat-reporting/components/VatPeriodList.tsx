import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { VatPeriodStatusBadge } from './VatPeriodStatusBadge'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Button } from '@/components/atoms/Button/Button'
import type { VatPeriod } from '../types'

interface VatPeriodListProps {
  periods: VatPeriod[]
  onClose: (id: string) => void
  onReopen: (id: string) => void
  onFile: (id: string) => void
  onView: (id: string) => void
  isClosing?: boolean
  isFiling?: boolean
  isReopening?: boolean
}

function formatAmount(value: string | null): string {
  if (value === null) return '-'
  return new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(parseFloat(value))
}

export function VatPeriodList({
  periods,
  onClose,
  onReopen,
  onFile,
  onView,
  isClosing = false,
  isFiling = false,
  isReopening = false,
}: VatPeriodListProps) {
  const { t } = useTranslation('finance')
  const [confirmAction, setConfirmAction] = useState<{
    type: 'close' | 'file' | 'reopen'
    periodId: string
    periodLabel: string
  } | null>(null)

  const handleConfirm = () => {
    if (!confirmAction) return
    switch (confirmAction.type) {
      case 'close':
        onClose(confirmAction.periodId)
        break
      case 'file':
        onFile(confirmAction.periodId)
        break
      case 'reopen':
        onReopen(confirmAction.periodId)
        break
    }
    setConfirmAction(null)
  }

  const getConfirmDialogProps = () => {
    if (!confirmAction) return { title: '', message: '', variant: 'warning' as const }
    switch (confirmAction.type) {
      case 'close':
        return {
          title: t('finance:vatReporting.actions.closePeriodTitle'),
          message: t('finance:vatReporting.actions.closePeriodMessage', {
            period: confirmAction.periodLabel,
          }),
          variant: 'warning' as const,
        }
      case 'file':
        return {
          title: t('finance:vatReporting.actions.filePeriodTitle'),
          message: t('finance:vatReporting.actions.filePeriodMessage', {
            period: confirmAction.periodLabel,
          }),
          variant: 'info' as const,
        }
      case 'reopen':
        return {
          title: t('finance:vatReporting.actions.reopenPeriodTitle'),
          message: t('finance:vatReporting.actions.reopenPeriodMessage', {
            period: confirmAction.periodLabel,
          }),
          variant: 'warning' as const,
        }
    }
  }

  const isActionLoading = isClosing || isFiling || isReopening

  return (
    <>
      <div className="overflow-x-auto">
        <table className="min-w-full divide-y divide-gray-200">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('finance:vatReporting.columns.period')}
              </th>
              <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('finance:vatReporting.columns.dateRange')}
              </th>
              <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('finance:vatReporting.columns.outputVat')}
              </th>
              <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('finance:vatReporting.columns.inputVat')}
              </th>
              <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('finance:vatReporting.columns.netDue')}
              </th>
              <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('finance:vatReporting.columns.status')}
              </th>
              <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('finance:vatReporting.columns.actions')}
              </th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200 bg-white">
            {periods.map((period) => {
              const isOpen = period.status === 'OPEN'
              return (
                <tr key={period.id}>
                  <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                    {period.label}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                    {period.period_start} — {period.period_end}
                  </td>
                  <td
                    className={`whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900 ${isOpen ? 'italic' : ''}`}
                  >
                    {formatAmount(period.total_output_vat)}
                  </td>
                  <td
                    className={`whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900 ${isOpen ? 'italic' : ''}`}
                  >
                    {formatAmount(period.total_input_vat)}
                  </td>
                  <td
                    className={`whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900 ${isOpen ? 'italic' : ''}`}
                  >
                    {formatAmount(period.net_vat)}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm">
                    <VatPeriodStatusBadge status={period.status} />
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end text-sm">
                    <div className="flex items-center justify-end gap-2">
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => { onView(period.id); }}
                      >
                        {t('finance:vatReporting.actions.view')}
                      </Button>
                      {period.status === 'OPEN' && (
                        <Button
                          variant="secondary"
                          size="sm"
                          onClick={() => {
                            setConfirmAction({
                              type: 'close',
                              periodId: period.id,
                              periodLabel: period.label,
                            })
                          }}
                        >
                          {t('finance:vatReporting.actions.close')}
                        </Button>
                      )}
                      {period.status === 'CLOSED' && (
                        <>
                          <Button
                            variant="secondary"
                            size="sm"
                            onClick={() => {
                              setConfirmAction({
                                type: 'reopen',
                                periodId: period.id,
                                periodLabel: period.label,
                              })
                            }}
                          >
                            {t('finance:vatReporting.actions.reopen')}
                          </Button>
                          <Button
                            variant="primary"
                            size="sm"
                            onClick={() => {
                              setConfirmAction({
                                type: 'file',
                                periodId: period.id,
                                periodLabel: period.label,
                              })
                            }}
                          >
                            {t('finance:vatReporting.actions.file')}
                          </Button>
                        </>
                      )}
                    </div>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      <ConfirmDialog
        isOpen={confirmAction !== null}
        onClose={() => { setConfirmAction(null); }}
        onConfirm={handleConfirm}
        isLoading={isActionLoading}
        {...getConfirmDialogProps()}
      />
    </>
  )
}
