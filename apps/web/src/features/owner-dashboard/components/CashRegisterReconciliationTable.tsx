import { useTranslation } from 'react-i18next'
import { borderColors, textColors } from '@/lib/designTokens'
import { OwnerTableFrame } from './OwnerTableFrame'
import type { CashReconciliationReport } from '../api/ownerReportsApi'

interface CashRegisterReconciliationTableProps {
  data: CashReconciliationReport[]
}

export function CashRegisterReconciliationTable({ data }: CashRegisterReconciliationTableProps) {
  const { t } = useTranslation(['reports'])

  return (
    <OwnerTableFrame title={t('reports:ownerDashboard.cashReconciliation.title')} isEmpty={data.length === 0}>
      <table className="w-full text-sm">
        <thead>
          <tr className={`border-b ${borderColors.light} ${textColors.tertiary}`}>
            <th className="py-2 text-start">{t('reports:ownerDashboard.columns.date')}</th>
            <th className="py-2 text-start">{t('reports:ownerDashboard.columns.location')}</th>
            <th className="py-2 text-end">{t('reports:ownerDashboard.columns.expected')}</th>
            <th className="py-2 text-end">{t('reports:ownerDashboard.columns.counted')}</th>
            <th className="py-2 text-end">{t('reports:ownerDashboard.columns.variance')}</th>
          </tr>
        </thead>
        <tbody>
          {data.map((row) => (
            <tr key={row.shift_id} className={`border-b last:border-0 ${borderColors.light}`}>
              <td className={`py-2 ${textColors.secondary}`}>{row.date}</td>
              <td className={`py-2 ${textColors.primary}`}>{row.location_name}</td>
              <td className={`py-2 text-end ${textColors.secondary}`}>{row.expected_cash}</td>
              <td className={`py-2 text-end ${textColors.secondary}`}>{row.counted_cash}</td>
              <td className={`py-2 text-end ${varianceText(row.variance_severity)}`}>{row.variance}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </OwnerTableFrame>
  )
}

function varianceText(severity: string): string {
  if (severity === 'critical') {
    return textColors.error
  }

  if (severity === 'warning') {
    return textColors.warning
  }

  return textColors.success
}
