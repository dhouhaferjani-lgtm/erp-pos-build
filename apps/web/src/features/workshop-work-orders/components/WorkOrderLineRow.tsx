import { useTranslation } from 'react-i18next'
import { borderColors, textColors } from '@/lib/designTokens'
import { LineTypeIcon } from './LineTypeIcon'
import type { WorkOrderLine } from '../types'

interface WorkOrderLineRowProps {
  line: WorkOrderLine
  currency: string
  /** When true, financial columns render as `—` (caller lacks view_financials). */
  redactFinancials: boolean
}

export function WorkOrderLineRow({ line, currency, redactFinancials }: WorkOrderLineRowProps) {
  const { t } = useTranslation('workshop-work-orders')
  const unitPrice = redactFinancials ? t('labels.notSet') : (line.unit_price ?? t('labels.notSet'))
  const totalIncl =
    redactFinancials
      ? t('labels.notSet')
      : (line.line_total_incl_tax ?? t('labels.notSet'))

  return (
    <div
      className={`grid grid-cols-12 items-center gap-3 border-b px-4 py-2 text-sm ${borderColors.light}`}
      data-line-type={line.line_type}
    >
      <div className="col-span-5 flex items-center gap-2">
        <LineTypeIcon type={line.line_type} />
        <div>
          <div className={`font-medium ${textColors.primary}`}>{line.display_name}</div>
          {line.sku_or_code !== null && (
            <div className={`text-xs font-mono ${textColors.tertiary}`}>{line.sku_or_code}</div>
          )}
        </div>
      </div>
      <div className={`col-span-2 text-xs ${textColors.tertiary}`}>
        {line.quantity} {line.unit}
      </div>
      <div className={`col-span-2 text-right text-xs ${textColors.tertiary}`}>{unitPrice}</div>
      <div className="col-span-2 text-right text-sm font-semibold text-slate-900">
        {totalIncl}
        {!redactFinancials && line.line_total_incl_tax !== null && (
          <span className={`ml-1 text-xs ${textColors.tertiary}`}>{currency}</span>
        )}
      </div>
      <div className="col-span-1 text-right">
        {line.is_completed && (
          <span className="inline-flex items-center rounded-md bg-emerald-50 px-1.5 py-0.5 text-[10px] font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">
            {t('labels.done')}
          </span>
        )}
      </div>
    </div>
  )
}
