import { useTranslation } from 'react-i18next'
import { AlertTriangle } from 'lucide-react'
import { EntityLink } from '@/components/molecules/EntityLink'
import { borderColors, colors, textColors } from '@/lib/designTokens'
import { OwnerTableFrame } from './OwnerTableFrame'
import type { StockAlertReport } from '../api/ownerReportsApi'

interface LowStockAlertsListProps {
  data: StockAlertReport[]
}

export function LowStockAlertsList({ data }: LowStockAlertsListProps) {
  const { t } = useTranslation(['reports'])

  return (
    <OwnerTableFrame title={t('reports:ownerDashboard.lowStock.title')} isEmpty={data.length === 0}>
      <div className="space-y-3">
        {data.map((row) => (
          <div key={`${row.product_id}-${row.location_id}`} className={`flex items-center justify-between rounded-md border ${borderColors.light} ${colors.neutral[50]} p-3`}>
            <div className="flex items-center gap-3">
              <AlertTriangle className={`h-4 w-4 ${severityText(row.severity)}`} />
              <div>
                <p className={`text-sm font-medium ${textColors.primary}`}>
                  <EntityLink
                    type="product"
                    id={row.product_id}
                    label={row.product_name}
                  />
                </p>
                <p className={`text-xs ${textColors.tertiary}`}>{row.location_name}</p>
              </div>
            </div>
            <p className={`text-sm font-medium ${severityText(row.severity)}`}>
              {row.quantity}/{row.min_quantity}
            </p>
          </div>
        ))}
      </div>
    </OwnerTableFrame>
  )
}

function severityText(severity: string): string {
  if (severity === 'out_of_stock') {
    return textColors.error
  }

  if (severity === 'critical') {
    return textColors.warning
  }

  return textColors.warningDark
}
