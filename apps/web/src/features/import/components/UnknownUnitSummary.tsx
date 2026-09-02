import { AlertTriangle } from 'lucide-react'
import { useTranslation } from 'react-i18next'

import type { UnitErrorSummary } from '../types'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface UnknownUnitSummaryProps {
  summary?: UnitErrorSummary | null | undefined
  compact?: boolean
}

export function UnknownUnitSummary({ summary, compact = false }: UnknownUnitSummaryProps) {
  const { t } = useTranslation('import')
  const unknownUnits = summary?.unknown_units ?? []

  if (unknownUnits.length === 0) {
    return null
  }

  return (
    <section
      data-testid="unknown-unit-summary"
      aria-label={t('unitErrors.title')}
      className={`rounded-lg border ${colorTokens.intent.warning.borderSubtle} ${colorTokens.intent.warning.bgSubtle} ${compact ? 'px-3 py-2' : 'p-4'}`}
    >
      <div className="flex items-start gap-3">
        <AlertTriangle aria-hidden="true" className={`mt-0.5 h-5 w-5 shrink-0 ${colorTokens.intent.warning.text}`} />
        <div>
          {!compact && (
            <h3 className={`font-semibold ${colorTokens.intent.warning.textStrongest}`}>
              {t('unitErrors.title')}
            </h3>
          )}
          <ul className={`${compact ? '' : 'mt-1'} space-y-1 text-sm ${colorTokens.intent.warning.textStronger}`}>
            {unknownUnits.map((unknownUnit) => (
              <li key={unknownUnit.text}>
                {t('unitErrors.line', {
                  count: unknownUnit.count,
                  unit: unknownUnit.text,
                })}
              </li>
            ))}
          </ul>
        </div>
      </div>
    </section>
  )
}
