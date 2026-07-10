import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import type { ArticleCriteria } from '../../types/catalog'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

interface SpecificationsTableProps {
  criteria: ArticleCriteria[]
  className?: string
}

export function SpecificationsTable({ criteria, className }: SpecificationsTableProps) {
  const { t } = useTranslation(['parts-catalog'])

  if (criteria.length === 0) return null

  return (
    <div className={cn('', className)}>
      <h3 className={`text-sm font-semibold ${colorTokens.text.primary} mb-3`}>
        {t('parts-catalog:article.specifications')}
      </h3>
      <div className={`rounded-lg border ${colorTokens.border.subtle} overflow-hidden`}>
        <DataTable className="w-full text-sm">
          <tbody className={`divide-y ${colorTokens.border.dividerSubtle}`}>
            {criteria.map((c, i) => (
              <tr key={c.criteria_id} className={i % 2 === 0 ? `${colorTokens.surface.base}` : '${colorTokens.surface.pageAlpha}'}>
                <td className={`px-4 py-2.5 ${colorTokens.text.subtle} font-medium whitespace-nowrap w-2/5`}>
                  {c.label}
                </td>
                <td className={`px-4 py-2.5 ${colorTokens.text.primary}`}>
                  {c.value}
                  {c.unit && <span className={`ms-1 ${colorTokens.text.disabled}`}>{c.unit}</span>}
                </td>
              </tr>
            ))}
          </tbody>
        </DataTable>
      </div>
    </div>
  )
}
