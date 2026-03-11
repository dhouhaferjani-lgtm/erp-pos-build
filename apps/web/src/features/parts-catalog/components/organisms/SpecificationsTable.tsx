import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import type { ArticleCriteria } from '../../types/catalog'

interface SpecificationsTableProps {
  criteria: ArticleCriteria[]
  className?: string
}

export function SpecificationsTable({ criteria, className }: SpecificationsTableProps) {
  const { t } = useTranslation(['parts-catalog'])

  if (criteria.length === 0) return null

  return (
    <div className={cn('', className)}>
      <h3 className="text-sm font-semibold text-gray-900 mb-3">
        {t('parts-catalog:article.specifications')}
      </h3>
      <div className="rounded-lg border border-gray-200 overflow-hidden">
        <table className="w-full text-sm">
          <tbody className="divide-y divide-gray-100">
            {criteria.map((c, i) => (
              <tr key={c.criteria_id} className={i % 2 === 0 ? 'bg-white' : 'bg-gray-50/50'}>
                <td className="px-4 py-2.5 text-gray-500 font-medium whitespace-nowrap w-2/5">
                  {c.label}
                </td>
                <td className="px-4 py-2.5 text-gray-900">
                  {c.value}
                  {c.unit && <span className="ms-1 text-gray-400">{c.unit}</span>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
