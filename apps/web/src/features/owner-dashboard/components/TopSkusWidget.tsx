import { useTranslation } from 'react-i18next'
import { EntityLink } from '@/components/molecules/EntityLink'
import { borderColors, colors, textColors } from '@/lib/designTokens'
import { formatQuantity } from '@/lib/decimal'
import { getQuantityDecimals } from '@/lib/quantityScale'
import { OwnerTableFrame } from './OwnerTableFrame'
import type { TopSkuReport } from '../api/ownerReportsApi'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

interface TopSkusWidgetProps {
  data: TopSkuReport[]
  sortBy: 'revenue' | 'quantity'
  onSortByChange: (sortBy: 'revenue' | 'quantity') => void
}

export function TopSkusWidget({ data, sortBy, onSortByChange }: TopSkusWidgetProps) {
  const { t } = useTranslation(['reports'])

  return (
    <OwnerTableFrame title={t('reports:ownerDashboard.topSkus.title')} isEmpty={data.length === 0}>
      <div className={`mb-3 inline-flex rounded-md border ${borderColors.default}`}>
        <button
          type="button"
          onClick={() => {
            onSortByChange('revenue')
          }}
          className={`px-3 py-1 text-sm ${sortBy === 'revenue' ? `${colors.primary[600]} ${textColors.inverse}` : `${colors.white} ${textColors.secondary}`}`}
        >
          {t('reports:ownerDashboard.topSkus.revenue')}
        </button>
        <button
          type="button"
          onClick={() => {
            onSortByChange('quantity')
          }}
          className={`px-3 py-1 text-sm ${sortBy === 'quantity' ? `${colors.primary[600]} ${textColors.inverse}` : `${colors.white} ${textColors.secondary}`}`}
        >
          {t('reports:ownerDashboard.topSkus.quantity')}
        </button>
      </div>
      <DataTable className="w-full text-sm">
        <thead>
          <tr className={`border-b ${borderColors.light} ${textColors.tertiary}`}>
            <th className="py-2 text-start">{t('reports:ownerDashboard.columns.product')}</th>
            <th className="py-2 text-end">{t('reports:ownerDashboard.columns.quantity')}</th>
            <th className="py-2 text-end">{t('reports:ownerDashboard.columns.revenue')}</th>
          </tr>
        </thead>
        <tbody>
          {data.map((row) => (
            <tr key={`${row.product_id ?? row.product_name}-${row.sku ?? ''}`} className={`border-b last:border-0 ${borderColors.light}`}>
              <td className={`py-2 ${textColors.primary}`}>
                <EntityLink
                  type="product"
                  id={row.product_id}
                  label={row.product_name}
                  className="block font-medium"
                />
                {row.sku ? <span className={textColors.tertiary}>{row.sku}</span> : null}
              </td>
              <td className={`py-2 text-end ${textColors.secondary}`}>
                {formatQuantity(row.quantity, getQuantityDecimals(row))}
              </td>
              <td className={`py-2 text-end ${textColors.primary}`}>{row.revenue}</td>
            </tr>
          ))}
        </tbody>
      </DataTable>
    </OwnerTableFrame>
  )
}
